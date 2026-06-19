<?php

declare(strict_types=1);

namespace AlphaForge\Services\Market;

use AlphaForge\Core\Config;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Services\Market\DataProviders\AlphaVantageProvider;
use AlphaForge\Services\Market\DataProviders\FinnhubProvider;
use Predis\Client as RedisClient;
use Ramsey\Uuid\Uuid;

/**
 * Market data service.
 *
 * Orchestrates AlphaVantage and Finnhub data providers to supply real-time
 * quotes, historical OHLCV data, and asset metadata. Results are stored in the
 * alphaforge PostgreSQL schema and optionally cached in Redis. Falls back
 * gracefully when Redis is unavailable.
 */
class MarketDataService
{
    private AlphaVantageProvider $alphaVantage;
    private FinnhubProvider      $finnhub;
    private Database             $db;
    private Logger               $logger;
    private ?RedisClient         $cache;

    /** Cache TTL for quote data in seconds. */
    private int $quoteTtl;

    /** Cache TTL for historical data in seconds. */
    private int $historicalTtl;

    public function __construct()
    {
        $this->alphaVantage  = new AlphaVantageProvider();
        $this->finnhub       = new FinnhubProvider();
        $this->db            = Database::getInstance();
        $this->logger        = Logger::getInstance();

        $config              = Config::getInstance();
        $this->quoteTtl      = (int) $config->get('cache.ttl.market_data', 60);
        $this->historicalTtl = 300;

        $this->cache = $this->initRedis();
    }

    /**
     * Return a real-time quote for the given symbol.
     *
     * Checks Redis first (60-second TTL). Falls back sequentially to
     * AlphaVantage then Finnhub on a cache miss. The asset row is created
     * automatically when it does not exist in the database.
     *
     * @param string $symbol Exchange ticker
     * @return array{symbol:string,name:string,price:float,open:float,high:float,low:float,previous_close:float,change:float,change_percent:float,volume:float,market_cap:float,asset_type:string,exchange:string,timestamp:string}
     * @throws \RuntimeException When neither provider can supply data
     */
    public function getQuote(string $symbol): array
    {
        $symbol   = strtoupper(trim($symbol));
        $cacheKey = "market:quote:{$symbol}";

        // Try cache first.
        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $asset = $this->getOrCreateAsset($symbol);

        $quote = null;

        // Attempt AlphaVantage.
        try {
            $avQuote = $this->alphaVantage->getQuote($symbol);
            $quote   = [
                'symbol'         => $symbol,
                'name'           => (string) ($asset['name']       ?? $symbol),
                'price'          => $avQuote['price'],
                'open'           => $avQuote['open'],
                'high'           => $avQuote['high'],
                'low'            => $avQuote['low'],
                'previous_close' => $avQuote['previous_close'],
                'change'         => $avQuote['change'],
                'change_percent' => $avQuote['change_percent'],
                'volume'         => $avQuote['volume'],
                'market_cap'     => 0.0, // Overview has market cap; omit here for speed
                'asset_type'     => (string) ($asset['asset_type'] ?? 'stock'),
                'exchange'       => (string) ($asset['exchange']   ?? ''),
                'timestamp'      => $avQuote['latest_trading_day'],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('AlphaVantage quote failed — trying Finnhub', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
        }

        // Fallback to Finnhub.
        if ($quote === null) {
            try {
                $fhQuote = $this->finnhub->getQuote($symbol);
                $quote   = [
                    'symbol'         => $symbol,
                    'name'           => (string) ($asset['name']       ?? $symbol),
                    'price'          => $fhQuote['price'],
                    'open'           => $fhQuote['open'],
                    'high'           => $fhQuote['high'],
                    'low'            => $fhQuote['low'],
                    'previous_close' => $fhQuote['previous_close'],
                    'change'         => $fhQuote['change'],
                    'change_percent' => $fhQuote['change_percent'],
                    'volume'         => 0.0,
                    'market_cap'     => 0.0,
                    'asset_type'     => (string) ($asset['asset_type'] ?? 'stock'),
                    'exchange'       => (string) ($asset['exchange']   ?? ''),
                    'timestamp'      => date('Y-m-d\TH:i:s\Z', $fhQuote['timestamp']),
                ];
            } catch (\Throwable $e) {
                $this->logger->error('Finnhub quote also failed', [
                    'symbol' => $symbol,
                    'error'  => $e->getMessage(),
                ]);
                throw new \RuntimeException(
                    "Could not fetch quote for '{$symbol}' from any provider.",
                    0,
                    $e,
                );
            }
        }

        $this->cacheSet($cacheKey, $quote, $this->quoteTtl);

        return $quote;
    }

    /**
     * Return OHLCV history for the given symbol and interval.
     *
     * Serves from the database when sufficient records exist; otherwise triggers
     * a historical sync from AlphaVantage before returning. Data is Redis-cached
     * for 300 seconds.
     *
     * @param string $symbol   Exchange ticker
     * @param string $interval 'daily' | 'weekly' | 'monthly'
     * @param int    $periods  Number of bars to return (sorted ascending)
     * @return list<array{date:string,open:float,high:float,low:float,close:float,adj_close:float,volume:float}>
     * @throws \RuntimeException On database or API errors
     */
    public function getHistoricalData(
        string $symbol,
        string $interval = 'daily',
        int $periods = 200,
    ): array {
        $symbol   = strtoupper(trim($symbol));
        $cacheKey = "market:historical:{$symbol}:{$interval}:{$periods}";

        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $asset = $this->getOrCreateAsset($symbol);
        $assetId = (string) $asset['id'];

        $rows = $this->fetchHistoricalFromDb($assetId, $interval, $periods);

        // If the DB has fewer than 50 records, sync first.
        if (count($rows) < 50) {
            $this->logger->info('Insufficient historical data in DB — syncing from API', [
                'symbol'   => $symbol,
                'db_count' => count($rows),
            ]);

            try {
                $this->syncHistoricalData($symbol);
                $rows = $this->fetchHistoricalFromDb($assetId, $interval, $periods);
            } catch (\Throwable $e) {
                $this->logger->warning('Historical sync failed; returning partial DB data', [
                    'symbol' => $symbol,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        // Sort ascending by date before returning.
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

        $this->cacheSet($cacheKey, $rows, $this->historicalTtl);

        return $rows;
    }

    /**
     * Return quotes for multiple symbols.
     *
     * Iterates over each symbol and calls getQuote(), collecting successes.
     * Failed lookups are skipped with a warning rather than aborting.
     *
     * @param list<string>          $symbols
     * @return array<string, array<string, mixed>>  Keyed by uppercase symbol
     */
    public function getMultipleQuotes(array $symbols): array
    {
        $results = [];

        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));

            try {
                $results[$symbol] = $this->getQuote($symbol);
            } catch (\Throwable $e) {
                $this->logger->warning('getMultipleQuotes: failed for symbol', [
                    'symbol' => $symbol,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Search the local asset database for symbols matching $query.
     *
     * @param string $query  Partial symbol or company name
     * @param string $type   Optional asset_type filter (stock, etf, crypto, forex, index, commodity)
     * @return list<array{id:string,symbol:string,name:string,exchange:string,asset_type:string,sector:string}>
     */
    public function searchAssets(string $query, string $type = ''): array
    {
        $likeQuery = '%' . $query . '%';

        if ($type !== '') {
            $sql    = 'SELECT id, symbol, name, exchange, asset_type, sector
                       FROM alphaforge.assets
                       WHERE (symbol ILIKE $1 OR name ILIKE $1)
                         AND asset_type = $2
                         AND is_active = TRUE
                       ORDER BY symbol
                       LIMIT 20';
            $params = [$likeQuery, $type];
        } else {
            $sql    = 'SELECT id, symbol, name, exchange, asset_type, sector
                       FROM alphaforge.assets
                       WHERE (symbol ILIKE $1 OR name ILIKE $1)
                         AND is_active = TRUE
                       ORDER BY symbol
                       LIMIT 20';
            $params = [$likeQuery];
        }

        try {
            return $this->db->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->error('searchAssets DB query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Return a high-level snapshot of major indices and S&P 500 sector ETFs.
     *
     * @return array{indices:array<string,mixed>,sectors:array<string,mixed>,breadth:float,timestamp:string}
     */
    public function getMarketOverview(): array
    {
        $indexSymbols  = ['SPY', 'QQQ', 'DIA', 'IWM', 'VIX'];
        $sectorSymbols = ['XLK', 'XLF', 'XLE', 'XLV', 'XLI', 'XLY', 'XLP', 'XLU', 'XLB', 'XLRE', 'XLC'];

        $indices = $this->getMultipleQuotes($indexSymbols);
        $sectors = $this->getMultipleQuotes($sectorSymbols);

        $breadth = $this->calculateMarketBreadth();

        return [
            'indices'   => $indices,
            'sectors'   => $sectors,
            'breadth'   => $breadth,
            'timestamp' => date('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Force a full data refresh for a symbol from both providers.
     *
     * Fetches the latest daily bar from AlphaVantage and upserts it into
     * market_data_daily. Invalidates all cache entries for the symbol.
     *
     * @param string $symbol Exchange ticker
     * @throws \RuntimeException On API or database error
     */
    public function refreshData(string $symbol): void
    {
        $symbol = strtoupper(trim($symbol));

        $this->logger->info('Refreshing market data', ['symbol' => $symbol]);

        $asset   = $this->getOrCreateAsset($symbol);
        $assetId = (string) $asset['id'];

        try {
            $dailyRows = $this->alphaVantage->getDailyData($symbol, outputSize: 'compact');

            if (!empty($dailyRows)) {
                $latest = end($dailyRows);
                $this->upsertDailyBar($assetId, $latest, 'alpha_vantage');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('refreshData: AlphaVantage daily fetch failed', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
        }

        // Invalidate cache entries for this symbol.
        $this->cacheDelete("market:quote:{$symbol}");
        $this->cacheDeletePattern("market:historical:{$symbol}:");
    }

    /**
     * Sync historical daily OHLCV data from AlphaVantage into market_data_daily.
     *
     * Defaults to the past 5 years when $from/$to are not specified.
     * Uses ON CONFLICT upsert to avoid duplicates on re-runs.
     *
     * @param string         $symbol Exchange ticker
     * @param \DateTime|null $from   Start of range (inclusive)
     * @param \DateTime|null $to     End of range   (inclusive)
     * @return int Count of rows inserted or updated
     * @throws \RuntimeException On database error
     */
    public function syncHistoricalData(
        string $symbol,
        ?\DateTime $from = null,
        ?\DateTime $to = null,
    ): int {
        $symbol = strtoupper(trim($symbol));

        $from ??= new \DateTime('-5 years');
        $to   ??= new \DateTime();

        $fromStr = $from->format('Y-m-d');
        $toStr   = $to->format('Y-m-d');

        $this->logger->info('Syncing historical data', [
            'symbol' => $symbol,
            'from'   => $fromStr,
            'to'     => $toStr,
        ]);

        $asset   = $this->getOrCreateAsset($symbol);
        $assetId = (string) $asset['id'];

        $allRows = $this->alphaVantage->getDailyData($symbol, outputSize: 'full');

        // Filter to requested date range.
        $rows = array_filter(
            $allRows,
            static fn(array $row): bool =>
                $row['date'] >= $fromStr && $row['date'] <= $toStr,
        );

        $count = 0;

        $this->db->beginTransaction();

        try {
            foreach ($rows as $row) {
                $this->upsertDailyBar($assetId, $row, 'alpha_vantage');
                $count++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();

            $this->logger->error('syncHistoricalData transaction rolled back', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "Failed to sync historical data for '{$symbol}': " . $e->getMessage(),
                0,
                $e,
            );
        }

        $this->logger->info('Historical sync complete', [
            'symbol' => $symbol,
            'count'  => $count,
        ]);

        return $count;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Look up or create an asset row in the assets table.
     *
     * When the asset does not exist, its profile is fetched from Finnhub and
     * a new row is inserted with the returned metadata.
     *
     * @param string $symbol Exchange ticker (already uppercased)
     * @return array<string, mixed> Asset row
     * @throws \RuntimeException On insert failure
     */
    private function getOrCreateAsset(string $symbol): array
    {
        try {
            $existing = $this->db->fetchOne(
                'SELECT * FROM alphaforge.assets WHERE symbol = $1 AND is_active = TRUE LIMIT 1',
                [$symbol],
            );

            if ($existing !== null) {
                return $existing;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('getOrCreateAsset: DB lookup failed', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
        }

        // Fetch profile from Finnhub to populate the new row.
        $name       = $symbol;
        $exchange   = '';
        $assetType  = 'stock';
        $sector     = '';
        $industry   = '';
        $marketCap  = 0.0;
        $metadata   = [];

        try {
            $profile  = $this->finnhub->getCompanyProfile($symbol);
            $name     = $profile['name']             !== '' ? $profile['name']          : $symbol;
            $exchange = $profile['exchange']          ?? '';
            $sector   = $profile['finnhub_industry']  ?? '';
            $metadata = [
                'logo'              => $profile['logo']               ?? '',
                'weburl'            => $profile['weburl']             ?? '',
                'country'           => $profile['country']            ?? '',
                'currency'          => $profile['currency']           ?? '',
                'ipo_date'          => $profile['ipo_date']           ?? '',
                'market_cap'        => $profile['market_cap']         ?? 0,
                'shares_outstanding'=> $profile['shares_outstanding'] ?? 0,
            ];
            $marketCap = (float) ($profile['market_cap'] ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('getOrCreateAsset: Finnhub profile fetch failed', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
        }

        $id = Uuid::uuid4()->toString();

        $data = [
            'id'         => $id,
            'symbol'     => $symbol,
            'name'       => $name,
            'exchange'   => $exchange,
            'asset_type' => $assetType,
            'sector'     => $sector,
            'industry'   => $industry,
            'metadata'   => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'is_active'  => true,
        ];

        try {
            $this->db->query(
                'INSERT INTO alphaforge.assets
                    (id, symbol, name, exchange, asset_type, sector, industry, metadata, is_active)
                 VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9)
                 ON CONFLICT (symbol) DO UPDATE
                    SET name      = EXCLUDED.name,
                        exchange  = EXCLUDED.exchange,
                        sector    = EXCLUDED.sector,
                        industry  = EXCLUDED.industry,
                        metadata  = EXCLUDED.metadata,
                        is_active = TRUE',
                [
                    $data['id'],
                    $data['symbol'],
                    $data['name'],
                    $data['exchange'],
                    $data['asset_type'],
                    $data['sector'],
                    $data['industry'],
                    $data['metadata'],
                    $data['is_active'] ? 'true' : 'false',
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error('getOrCreateAsset: insert failed', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "Failed to create asset record for '{$symbol}': " . $e->getMessage(),
                0,
                $e,
            );
        }

        // Re-fetch to get the actual persisted row (handles ON CONFLICT returning existing id).
        $row = $this->db->fetchOne(
            'SELECT * FROM alphaforge.assets WHERE symbol = $1 LIMIT 1',
            [$symbol],
        );

        return $row ?? $data;
    }

    /**
     * Upsert a single daily OHLCV bar into market_data_daily.
     *
     * @param string               $assetId    UUID of the asset
     * @param array<string, mixed> $row        Normalized bar with date/open/high/low/close/adj_close/volume
     * @param string               $dataSource Provider identifier
     */
    private function upsertDailyBar(string $assetId, array $row, string $dataSource): void
    {
        $id = Uuid::uuid4()->toString();

        $this->db->query(
            'INSERT INTO alphaforge.market_data_daily
                (id, asset_id, date, open, high, low, close, adj_close, volume, vwap, data_source)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
             ON CONFLICT (asset_id, date) DO UPDATE
                SET open        = EXCLUDED.open,
                    high        = EXCLUDED.high,
                    low         = EXCLUDED.low,
                    close       = EXCLUDED.close,
                    adj_close   = EXCLUDED.adj_close,
                    volume      = EXCLUDED.volume,
                    data_source = EXCLUDED.data_source',
            [
                $id,
                $assetId,
                (string) ($row['date']      ?? ''),
                (float)  ($row['open']      ?? 0),
                (float)  ($row['high']      ?? 0),
                (float)  ($row['low']       ?? 0),
                (float)  ($row['close']     ?? 0),
                (float)  ($row['adj_close'] ?? $row['close'] ?? 0),
                (float)  ($row['volume']    ?? 0),
                null,       // vwap: not provided by Alpha Vantage daily endpoint
                $dataSource,
            ],
        );
    }

    /**
     * Query market_data_daily from the database, applying interval aggregation.
     *
     * @param string $assetId  UUID of the asset
     * @param string $interval 'daily' | 'weekly' | 'monthly'
     * @param int    $periods  Maximum number of rows to return
     * @return list<array<string, mixed>>
     */
    private function fetchHistoricalFromDb(string $assetId, string $interval, int $periods): array
    {
        try {
            return match ($interval) {
                'weekly'  => $this->db->fetchAll(
                    "SELECT
                         date_trunc('week', date)::date                 AS date,
                         (array_agg(open  ORDER BY date ASC))[1]        AS open,
                         MAX(high)                                       AS high,
                         MIN(low)                                        AS low,
                         (array_agg(close ORDER BY date DESC))[1]       AS close,
                         (array_agg(adj_close ORDER BY date DESC))[1]   AS adj_close,
                         SUM(volume)                                     AS volume
                     FROM alphaforge.market_data_daily
                     WHERE asset_id = \$1
                     GROUP BY date_trunc('week', date)
                     ORDER BY date DESC
                     LIMIT \$2",
                    [$assetId, $periods],
                ),
                'monthly' => $this->db->fetchAll(
                    "SELECT
                         date_trunc('month', date)::date                 AS date,
                         (array_agg(open  ORDER BY date ASC))[1]         AS open,
                         MAX(high)                                        AS high,
                         MIN(low)                                         AS low,
                         (array_agg(close ORDER BY date DESC))[1]        AS close,
                         (array_agg(adj_close ORDER BY date DESC))[1]    AS adj_close,
                         SUM(volume)                                      AS volume
                     FROM alphaforge.market_data_daily
                     WHERE asset_id = \$1
                     GROUP BY date_trunc('month', date)
                     ORDER BY date DESC
                     LIMIT \$2",
                    [$assetId, $periods],
                ),
                default   => $this->db->fetchAll(
                    'SELECT date, open, high, low, close, adj_close, volume
                     FROM alphaforge.market_data_daily
                     WHERE asset_id = $1
                     ORDER BY date DESC
                     LIMIT $2',
                    [$assetId, $periods],
                ),
            };
        } catch (\Throwable $e) {
            $this->logger->error('fetchHistoricalFromDb failed', [
                'asset_id' => $assetId,
                'interval' => $interval,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Calculate approximate market breadth as the percentage of tracked stocks
     * whose most recent close is above their 50-day simple moving average.
     *
     * Returns -1.0 when insufficient data exists.
     *
     * @return float Fraction 0–1 (e.g. 0.63 = 63 % above 50-day MA), or -1.0
     */
    private function calculateMarketBreadth(): float
    {
        try {
            $sql = "
                WITH latest_close AS (
                    SELECT DISTINCT ON (asset_id)
                           asset_id,
                           close,
                           date
                    FROM alphaforge.market_data_daily
                    ORDER BY asset_id, date DESC
                ),
                ma50 AS (
                    SELECT
                        asset_id,
                        AVG(close) AS avg_50
                    FROM (
                        SELECT asset_id, close,
                               ROW_NUMBER() OVER (PARTITION BY asset_id ORDER BY date DESC) AS rn
                        FROM alphaforge.market_data_daily
                    ) sub
                    WHERE rn <= 50
                    GROUP BY asset_id
                    HAVING COUNT(*) >= 50
                )
                SELECT
                    COUNT(*) FILTER (WHERE lc.close > m.avg_50) AS above,
                    COUNT(*) AS total
                FROM latest_close lc
                JOIN ma50 m ON m.asset_id = lc.asset_id
                JOIN alphaforge.assets a ON a.id = lc.asset_id AND a.is_active = TRUE AND a.asset_type = 'stock'
            ";

            $row = $this->db->fetchOne($sql, []);

            if ($row === null || (int) $row['total'] === 0) {
                return -1.0;
            }

            return round((float) $row['above'] / (float) $row['total'], 4);
        } catch (\Throwable $e) {
            $this->logger->warning('calculateMarketBreadth failed', ['error' => $e->getMessage()]);
            return -1.0;
        }
    }

    /**
     * Initialise a Predis client for optional caching.
     *
     * Returns null and logs a warning on connection failure so callers
     * can degrade gracefully without Redis.
     */
    private function initRedis(): ?RedisClient
    {
        try {
            $host     = (string) ($_ENV['REDIS_HOST']     ?? '127.0.0.1');
            $port     = (int)    ($_ENV['REDIS_PORT']     ?? 6379);
            $database = (int)    ($_ENV['REDIS_DATABASE'] ?? 0);
            $password = (string) ($_ENV['REDIS_PASSWORD'] ?? '');

            $params = [
                'scheme'   => 'tcp',
                'host'     => $host,
                'port'     => $port,
                'database' => $database,
            ];

            if ($password !== '') {
                $params['password'] = $password;
            }

            $client = new RedisClient($params, [
                'connections' => [
                    'tcp' => ['read_write_timeout' => 2.0, 'timeout' => 2.0],
                ],
            ]);

            $client->ping();

            return $client;
        } catch (\Throwable $e) {
            $this->logger->warning('MarketDataService: Redis unavailable — caching disabled', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Read a JSON-encoded value from Redis.
     *
     * @return mixed Decoded value, or null on miss / unavailability
     */
    private function cacheGet(string $key): mixed
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $raw = $this->cache->get($key);

            if ($raw === null) {
                return null;
            }

            return json_decode((string) $raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->debug('Cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Write a JSON-encoded value to Redis with a TTL.
     *
     * @param mixed $value Any JSON-serialisable value
     */
    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $this->cache->setex($key, $ttl, $encoded);
        } catch (\Throwable $e) {
            $this->logger->debug('Cache set failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Delete a single key from Redis.
     */
    private function cacheDelete(string $key): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->del([$key]);
        } catch (\Throwable $e) {
            $this->logger->debug('Cache delete failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Delete all Redis keys whose names begin with $prefix.
     *
     * Uses a SCAN loop to avoid blocking Redis on large keyspaces.
     */
    private function cacheDeletePattern(string $prefix): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $cursor = '0';

            do {
                /** @var array{0:string,1:list<string>} $result */
                $result = $this->cache->scan($cursor, ['MATCH' => $prefix . '*', 'COUNT' => 100]);
                $cursor = $result[0];
                $keys   = $result[1];

                if (!empty($keys)) {
                    $this->cache->del($keys);
                }
            } while ($cursor !== '0');
        } catch (\Throwable $e) {
            $this->logger->debug('Cache pattern delete failed', [
                'prefix' => $prefix,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
