<?php

declare(strict_types=1);

namespace AlphaForge\Services\Market\DataProviders;

use AlphaForge\Core\Config;
use AlphaForge\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Alpha Vantage API data provider.
 *
 * Wraps the Alpha Vantage REST API for equity quotes, time series, fundamentals,
 * forex, and cryptocurrency data. Enforces the free-tier rate limit of 5 requests
 * per minute using an in-process sliding-window tracker. Retries on rate-limit
 * responses with exponential back-off (1 s → 2 s → 4 s).
 */
class AlphaVantageProvider
{
    private string $apiKey;
    private string $baseUrl = 'https://www.alphavantage.co/query';
    private Client $client;
    private int $requestsPerMinute = 5;

    /** @var list<float> Unix timestamps of recent requests for rate limiting. */
    private array $requestTimestamps = [];

    private Logger $logger;

    public function __construct()
    {
        $config        = Config::getInstance();
        $this->logger  = Logger::getInstance();
        $this->apiKey  = (string) ($config->get('app.api_keys.alpha_vantage') ?? '');

        $this->client = new Client([
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
            'headers'         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'AlphaForge/1.0',
            ],
        ]);
    }

    /**
     * Fetch a real-time global quote for the given symbol.
     *
     * @param string $symbol Exchange ticker, e.g. "AAPL"
     * @return array{symbol:string,open:float,high:float,low:float,price:float,volume:float,latest_trading_day:string,previous_close:float,change:float,change_percent:float}
     * @throws \RuntimeException On API or mapping error
     */
    public function getQuote(string $symbol): array
    {
        $data = $this->request([
            'function' => 'GLOBAL_QUOTE',
            'symbol'   => $symbol,
        ]);

        if (empty($data['Global Quote'])) {
            throw new \RuntimeException("Alpha Vantage returned no quote data for symbol '{$symbol}'.");
        }

        $q = $data['Global Quote'];

        return [
            'symbol'            => (string) ($q['01. symbol']           ?? $symbol),
            'open'              => (float)  ($q['02. open']             ?? 0),
            'high'              => (float)  ($q['03. high']             ?? 0),
            'low'               => (float)  ($q['04. low']              ?? 0),
            'price'             => (float)  ($q['05. price']            ?? 0),
            'volume'            => (float)  ($q['06. volume']           ?? 0),
            'latest_trading_day'=> (string) ($q['07. latest trading day'] ?? ''),
            'previous_close'    => (float)  ($q['08. previous close']   ?? 0),
            'change'            => (float)  ($q['09. change']           ?? 0),
            'change_percent'    => (float)  rtrim((string) ($q['10. change percent'] ?? '0'), '%'),
        ];
    }

    /**
     * Fetch daily adjusted time-series data for an equity.
     *
     * @param string $symbol     Exchange ticker
     * @param string $outputSize 'compact' (100 bars) or 'full' (20 years)
     * @return list<array{date:string,open:float,high:float,low:float,close:float,adj_close:float,volume:float,dividend:float,split_coeff:float}>
     * @throws \RuntimeException On API or mapping error
     */
    public function getDailyData(string $symbol, string $outputSize = 'compact'): array
    {
        $data = $this->request([
            'function'   => 'TIME_SERIES_DAILY_ADJUSTED',
            'symbol'     => $symbol,
            'outputsize' => $outputSize,
        ]);

        $seriesKey = 'Time Series (Daily)';

        if (empty($data[$seriesKey])) {
            throw new \RuntimeException("Alpha Vantage returned no daily data for symbol '{$symbol}'.");
        }

        $rows = [];

        foreach ($data[$seriesKey] as $date => $bar) {
            $rows[] = [
                'date'        => (string) $date,
                'open'        => (float)  ($bar['1. open']                   ?? 0),
                'high'        => (float)  ($bar['2. high']                   ?? 0),
                'low'         => (float)  ($bar['3. low']                    ?? 0),
                'close'       => (float)  ($bar['4. close']                  ?? 0),
                'adj_close'   => (float)  ($bar['5. adjusted close']         ?? 0),
                'volume'      => (float)  ($bar['6. volume']                 ?? 0),
                'dividend'    => (float)  ($bar['7. dividend amount']        ?? 0),
                'split_coeff' => (float)  ($bar['8. split coefficient']      ?? 1),
            ];
        }

        // Sort ascending by date.
        usort($rows, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * Fetch intraday time-series data for an equity.
     *
     * @param string $symbol   Exchange ticker
     * @param string $interval Supported: '1min','5min','15min','30min','60min'
     * @return list<array{timestamp:string,open:float,high:float,low:float,close:float,volume:float}>
     * @throws \RuntimeException On API or mapping error
     */
    public function getIntradayData(string $symbol, string $interval = '5min'): array
    {
        $data = $this->request([
            'function'   => 'TIME_SERIES_INTRADAY',
            'symbol'     => $symbol,
            'interval'   => $interval,
            'outputsize' => 'full',
        ]);

        $seriesKey = "Time Series ({$interval})";

        if (empty($data[$seriesKey])) {
            throw new \RuntimeException(
                "Alpha Vantage returned no intraday data for symbol '{$symbol}' at interval '{$interval}'."
            );
        }

        $rows = [];

        foreach ($data[$seriesKey] as $timestamp => $bar) {
            $rows[] = [
                'timestamp' => (string) $timestamp,
                'open'      => (float)  ($bar['1. open']   ?? 0),
                'high'      => (float)  ($bar['2. high']   ?? 0),
                'low'       => (float)  ($bar['3. low']    ?? 0),
                'close'     => (float)  ($bar['4. close']  ?? 0),
                'volume'    => (float)  ($bar['5. volume'] ?? 0),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['timestamp'], $b['timestamp']));

        return $rows;
    }

    /**
     * Fetch fundamental company overview data.
     *
     * @param string $symbol Exchange ticker
     * @return array<string, mixed>
     * @throws \RuntimeException On API or mapping error
     */
    public function getCompanyOverview(string $symbol): array
    {
        $data = $this->request([
            'function' => 'OVERVIEW',
            'symbol'   => $symbol,
        ]);

        if (empty($data) || !isset($data['Symbol'])) {
            throw new \RuntimeException("Alpha Vantage returned no overview data for symbol '{$symbol}'.");
        }

        return [
            'symbol'               => (string) ($data['Symbol']                  ?? ''),
            'name'                 => (string) ($data['Name']                    ?? ''),
            'description'          => (string) ($data['Description']             ?? ''),
            'exchange'             => (string) ($data['Exchange']                ?? ''),
            'currency'             => (string) ($data['Currency']                ?? ''),
            'sector'               => (string) ($data['Sector']                  ?? ''),
            'industry'             => (string) ($data['Industry']                ?? ''),
            'market_cap'           => (float)  ($data['MarketCapitalization']    ?? 0),
            'pe_ratio'             => (float)  ($data['PERatio']                 ?? 0),
            'pb_ratio'             => (float)  ($data['PriceToBookRatio']        ?? 0),
            'eps'                  => (float)  ($data['EPS']                     ?? 0),
            'dividend_yield'       => (float)  ($data['DividendYield']           ?? 0),
            '52_week_high'         => (float)  ($data['52WeekHigh']              ?? 0),
            '52_week_low'          => (float)  ($data['52WeekLow']               ?? 0),
            '50_day_ma'            => (float)  ($data['50DayMovingAverage']      ?? 0),
            '200_day_ma'           => (float)  ($data['200DayMovingAverage']     ?? 0),
            'shares_outstanding'   => (float)  ($data['SharesOutstanding']       ?? 0),
            'shares_float'         => (float)  ($data['SharesFloat']             ?? 0),
            'analyst_target_price' => (float)  ($data['AnalystTargetPrice']      ?? 0),
        ];
    }

    /**
     * Fetch annual and quarterly earnings history.
     *
     * @param string $symbol Exchange ticker
     * @return array{annual:list<array<string,mixed>>,quarterly:list<array<string,mixed>>}
     * @throws \RuntimeException On API or mapping error
     */
    public function getEarnings(string $symbol): array
    {
        $data = $this->request([
            'function' => 'EARNINGS',
            'symbol'   => $symbol,
        ]);

        $mapRow = static function (array $row): array {
            return [
                'fiscal_date_ending'      => (string) ($row['fiscalDateEnding']          ?? ''),
                'reported_date'           => (string) ($row['reportedDate']              ?? ''),
                'reported_eps'            => isset($row['reportedEPS'])   && $row['reportedEPS']   !== 'None' ? (float) $row['reportedEPS']   : null,
                'estimated_eps'           => isset($row['estimatedEPS'])  && $row['estimatedEPS']  !== 'None' ? (float) $row['estimatedEPS']  : null,
                'surprise'                => isset($row['surprise'])      && $row['surprise']      !== 'None' ? (float) $row['surprise']      : null,
                'surprise_percentage'     => isset($row['surprisePercentage']) && $row['surprisePercentage'] !== 'None' ? (float) $row['surprisePercentage'] : null,
            ];
        };

        return [
            'annual'    => array_map($mapRow, (array) ($data['annualEarnings']    ?? [])),
            'quarterly' => array_map($mapRow, (array) ($data['quarterlyEarnings'] ?? [])),
        ];
    }

    /**
     * Fetch a real-time forex exchange rate.
     *
     * @param string $fromCurrency ISO currency code, e.g. "EUR"
     * @param string $toCurrency   ISO currency code, e.g. "USD"
     * @return array{from:string,to:string,rate:float,bid:float,ask:float,timestamp:string}
     * @throws \RuntimeException On API or mapping error
     */
    public function getForexRate(string $fromCurrency, string $toCurrency): array
    {
        $data = $this->request([
            'function'      => 'CURRENCY_EXCHANGE_RATE',
            'from_currency' => $fromCurrency,
            'to_currency'   => $toCurrency,
        ]);

        $key = 'Realtime Currency Exchange Rate';

        if (empty($data[$key])) {
            throw new \RuntimeException(
                "Alpha Vantage returned no forex rate for {$fromCurrency}/{$toCurrency}."
            );
        }

        $r = $data[$key];

        return [
            'from'      => (string) ($r['1. From_Currency Code'] ?? $fromCurrency),
            'to'        => (string) ($r['3. To_Currency Code']   ?? $toCurrency),
            'rate'      => (float)  ($r['5. Exchange Rate']      ?? 0),
            'bid'       => (float)  ($r['8. Bid Price']          ?? 0),
            'ask'       => (float)  ($r['9. Ask Price']          ?? 0),
            'timestamp' => (string) ($r['6. Last Refreshed']     ?? ''),
        ];
    }

    /**
     * Fetch daily OHLCV data for a cryptocurrency pair.
     *
     * @param string $symbol Crypto symbol, e.g. "BTC"
     * @param string $market Fiat market currency, e.g. "USD"
     * @return list<array{date:string,open:float,high:float,low:float,close:float,adj_close:float,volume:float,dividend:float,split_coeff:float}>
     * @throws \RuntimeException On API or mapping error
     */
    public function getCryptoDaily(string $symbol, string $market = 'USD'): array
    {
        $data = $this->request([
            'function' => 'DIGITAL_CURRENCY_DAILY',
            'symbol'   => $symbol,
            'market'   => $market,
        ]);

        $seriesKey = 'Time Series (Digital Currency Daily)';

        if (empty($data[$seriesKey])) {
            throw new \RuntimeException(
                "Alpha Vantage returned no crypto daily data for symbol '{$symbol}'."
            );
        }

        $market = strtolower($market);
        $rows   = [];

        foreach ($data[$seriesKey] as $date => $bar) {
            // Alpha Vantage uses keys like "1a. open (USD)" / "1b. open (USD)"
            $open  = (float) ($bar["1a. open ({$market})"]            ?? $bar["1. open"]  ?? 0);
            $high  = (float) ($bar["2a. high ({$market})"]            ?? $bar["2. high"]  ?? 0);
            $low   = (float) ($bar["3a. low ({$market})"]             ?? $bar["3. low"]   ?? 0);
            $close = (float) ($bar["4a. close ({$market})"]           ?? $bar["4. close"] ?? 0);
            $vol   = (float) ($bar['5. volume']                       ?? 0);

            $rows[] = [
                'date'        => (string) $date,
                'open'        => $open,
                'high'        => $high,
                'low'         => $low,
                'close'       => $close,
                'adj_close'   => $close, // Crypto has no split/dividend adjustments
                'volume'      => $vol,
                'dividend'    => 0.0,
                'split_coeff' => 1.0,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $rows;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Execute a rate-limited, retriable GET request to the Alpha Vantage API.
     *
     * @param array<string, string> $params Query parameters (apikey is appended automatically)
     * @return array<string, mixed> Decoded JSON response body
     * @throws \RuntimeException On non-retriable API errors or exhausted retries
     */
    private function request(array $params): array
    {
        $params['apikey'] = $this->apiKey;
        $maxAttempts      = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->enforceRateLimit();

            $this->logger->debug('AlphaVantage API request', [
                'function' => $params['function'] ?? '',
                'symbol'   => $params['symbol']   ?? '',
                'attempt'  => $attempt,
            ]);

            $this->requestTimestamps[] = microtime(true);

            try {
                $response = $this->client->get($this->baseUrl, ['query' => $params]);
                $body     = (string) $response->getBody();
                $decoded  = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);

                if (!is_array($decoded)) {
                    throw new \RuntimeException('Alpha Vantage returned a non-array JSON response.');
                }

                // Hard API error (bad symbol, invalid function, etc.)
                if (isset($decoded['Error Message'])) {
                    throw new \RuntimeException(
                        'Alpha Vantage API error: ' . $decoded['Error Message']
                    );
                }

                // Rate-limit / information note — retry with back-off.
                if (isset($decoded['Note']) || isset($decoded['Information'])) {
                    $message = $decoded['Note'] ?? $decoded['Information'];

                    $this->logger->warning('Alpha Vantage rate limit note', [
                        'note'    => $message,
                        'attempt' => $attempt,
                    ]);

                    if ($attempt < $maxAttempts) {
                        $backoff = (int) (1 << ($attempt - 1)); // 1 s, 2 s, 4 s
                        sleep($backoff);
                        continue;
                    }

                    throw new \RuntimeException(
                        "Alpha Vantage rate limit exceeded after {$maxAttempts} attempts: {$message}"
                    );
                }

                return $decoded;
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode() ?? 0;

                $this->logger->error('Alpha Vantage HTTP error', [
                    'status'  => $status,
                    'error'   => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < $maxAttempts) {
                    $backoff = (int) (1 << ($attempt - 1));
                    sleep($backoff);
                    continue;
                }

                throw new \RuntimeException(
                    "Alpha Vantage HTTP request failed after {$maxAttempts} attempts: " . $e->getMessage(),
                    $status,
                    $e,
                );
            } catch (\JsonException $e) {
                throw new \RuntimeException(
                    'Alpha Vantage returned invalid JSON: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        // Should be unreachable, but satisfies static analysis.
        throw new \RuntimeException('Alpha Vantage request failed: no attempts were made.');
    }

    /**
     * Block until the sliding-window request count drops below the rate limit.
     *
     * Removes timestamps older than 60 seconds from the tracker. If the
     * remaining count is at or above $requestsPerMinute, sleeps until the
     * oldest timestamp in the window is 60+ seconds old.
     */
    private function enforceRateLimit(): void
    {
        $now    = microtime(true);
        $window = 60.0;

        // Evict timestamps outside the rolling 60-second window.
        $this->requestTimestamps = array_values(
            array_filter(
                $this->requestTimestamps,
                static fn(float $ts): bool => ($now - $ts) < $window,
            )
        );

        if (count($this->requestTimestamps) >= $this->requestsPerMinute) {
            $oldest  = $this->requestTimestamps[0];
            $waitSec = $window - ($now - $oldest);

            if ($waitSec > 0) {
                $this->logger->debug('AlphaVantage rate limit: sleeping', [
                    'wait_seconds' => round($waitSec, 3),
                ]);
                usleep((int) ceil($waitSec * 1_000_000));
            }

            // Re-evict after sleeping.
            $now = microtime(true);
            $this->requestTimestamps = array_values(
                array_filter(
                    $this->requestTimestamps,
                    static fn(float $ts): bool => ($now - $ts) < $window,
                )
            );
        }
    }
}
