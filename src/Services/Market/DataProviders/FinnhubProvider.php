<?php

declare(strict_types=1);

namespace AlphaForge\Services\Market\DataProviders;

use AlphaForge\Core\Config;
use AlphaForge\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Finnhub API data provider.
 *
 * Wraps the Finnhub REST API for real-time quotes, candles, company profiles,
 * news, insider transactions, earnings calendars, and sentiment data.
 * The Authorization header carries the API token for every request.
 * 429 rate-limit responses are handled with a one-second sleep and single retry.
 */
class FinnhubProvider
{
    private string $apiKey;
    private string $baseUrl = 'https://finnhub.io/api/v1';
    private Client $client;

    private Logger $logger;

    public function __construct()
    {
        $config       = Config::getInstance();
        $this->logger = Logger::getInstance();
        $this->apiKey = (string) ($config->get('app.api_keys.finnhub') ?? '');

        $this->client = new Client([
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
            'headers'         => [
                'Accept'        => 'application/json',
                'User-Agent'    => 'AlphaForge/1.0',
                'Authorization' => 'token ' . $this->apiKey,
            ],
        ]);
    }

    /**
     * Fetch a real-time quote for an equity symbol.
     *
     * @param string $symbol Exchange ticker, e.g. "AAPL"
     * @return array{symbol:string,price:float,open:float,high:float,low:float,previous_close:float,change:float,change_percent:float,timestamp:int}
     * @throws \RuntimeException On API error
     */
    public function getQuote(string $symbol): array
    {
        $data = $this->request('/quote', ['symbol' => $symbol]);

        return [
            'symbol'         => $symbol,
            'price'          => (float) ($data['c']  ?? 0),
            'open'           => (float) ($data['o']  ?? 0),
            'high'           => (float) ($data['h']  ?? 0),
            'low'            => (float) ($data['l']  ?? 0),
            'previous_close' => (float) ($data['pc'] ?? 0),
            'change'         => (float) ($data['d']  ?? 0),
            'change_percent' => (float) ($data['dp'] ?? 0),
            'timestamp'      => (int)   ($data['t']  ?? 0),
        ];
    }

    /**
     * Fetch OHLCV candles for an equity symbol.
     *
     * @param string $symbol     Exchange ticker
     * @param string $resolution Candle resolution: 1, 5, 15, 30, 60, D, W, M
     * @param int    $from       Unix timestamp for period start (0 = 1 year ago)
     * @param int    $to         Unix timestamp for period end   (0 = now)
     * @return list<array{timestamp:int,open:float,high:float,low:float,close:float,volume:float}>
     * @throws \RuntimeException On API error or unexpected response shape
     */
    public function getCandles(
        string $symbol,
        string $resolution = 'D',
        int $from = 0,
        int $to = 0,
    ): array {
        $now  = time();
        $from = $from > 0 ? $from : $now - 365 * 24 * 3600;
        $to   = $to   > 0 ? $to   : $now;

        $data = $this->request('/stock/candle', [
            'symbol'     => $symbol,
            'resolution' => $resolution,
            'from'       => $from,
            'to'         => $to,
        ]);

        if (($data['s'] ?? '') === 'no_data') {
            return [];
        }

        if (($data['s'] ?? '') !== 'ok') {
            throw new \RuntimeException(
                "Finnhub candles returned unexpected status '{$data['s']}' for symbol '{$symbol}'."
            );
        }

        $timestamps = (array) ($data['t'] ?? []);
        $opens      = (array) ($data['o'] ?? []);
        $highs      = (array) ($data['h'] ?? []);
        $lows       = (array) ($data['l'] ?? []);
        $closes     = (array) ($data['c'] ?? []);
        $volumes    = (array) ($data['v'] ?? []);

        $rows = [];
        $count = count($timestamps);

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'timestamp' => (int)   ($timestamps[$i] ?? 0),
                'open'      => (float) ($opens[$i]      ?? 0),
                'high'      => (float) ($highs[$i]      ?? 0),
                'low'       => (float) ($lows[$i]       ?? 0),
                'close'     => (float) ($closes[$i]     ?? 0),
                'volume'    => (float) ($volumes[$i]    ?? 0),
            ];
        }

        // Ensure ascending order by timestamp.
        usort($rows, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        return $rows;
    }

    /**
     * Fetch recent company news articles.
     *
     * @param string $symbol Exchange ticker
     * @param string $from   Start date Y-m-d (empty = 7 days ago)
     * @param string $to     End date   Y-m-d (empty = today)
     * @return list<array{id:int,category:string,datetime:int,headline:string,source:string,summary:string,url:string,image:string,sentiment_score:null}>
     * @throws \RuntimeException On API error
     */
    public function getNews(string $symbol, string $from = '', string $to = ''): array
    {
        $today   = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        $from = $from !== '' ? $from : $weekAgo;
        $to   = $to   !== '' ? $to   : $today;

        $data = $this->request('/company-news', [
            'symbol' => $symbol,
            'from'   => $from,
            'to'     => $to,
        ]);

        $articles = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $articles[] = [
                'id'              => (int)    ($item['id']       ?? 0),
                'category'        => (string) ($item['category'] ?? ''),
                'datetime'        => (int)    ($item['datetime'] ?? 0),
                'headline'        => (string) ($item['headline'] ?? ''),
                'source'          => (string) ($item['source']   ?? ''),
                'summary'         => (string) ($item['summary']  ?? ''),
                'url'             => (string) ($item['url']      ?? ''),
                'image'           => (string) ($item['image']    ?? ''),
                'sentiment_score' => null, // Computed downstream
            ];
        }

        return $articles;
    }

    /**
     * Fetch a company's basic profile.
     *
     * @param string $symbol Exchange ticker
     * @return array{name:string,ticker:string,exchange:string,finnhub_industry:string,ipo_date:string,logo:string,market_cap:float,shares_outstanding:float,weburl:string,country:string,currency:string,phone:string}
     * @throws \RuntimeException On API error
     */
    public function getCompanyProfile(string $symbol): array
    {
        $data = $this->request('/stock/profile2', ['symbol' => $symbol]);

        return [
            'name'                => (string) ($data['name']                 ?? ''),
            'ticker'              => (string) ($data['ticker']               ?? $symbol),
            'exchange'            => (string) ($data['exchange']             ?? ''),
            'finnhub_industry'    => (string) ($data['finnhubIndustry']      ?? ''),
            'ipo_date'            => (string) ($data['ipo']                  ?? ''),
            'logo'                => (string) ($data['logo']                 ?? ''),
            'market_cap'          => (float)  ($data['marketCapitalization'] ?? 0),
            'shares_outstanding'  => (float)  ($data['shareOutstanding']     ?? 0),
            'weburl'              => (string) ($data['weburl']               ?? ''),
            'country'             => (string) ($data['country']              ?? ''),
            'currency'            => (string) ($data['currency']             ?? ''),
            'phone'               => (string) ($data['phone']                ?? ''),
        ];
    }

    /**
     * Fetch insider transactions for an equity.
     *
     * @param string $symbol Exchange ticker
     * @return list<array{name:string,share:float,change:float,transaction_date:string,transaction_price:float,transaction_code:string}>
     * @throws \RuntimeException On API error
     */
    public function getInsiderTransactions(string $symbol): array
    {
        $data = $this->request('/stock/insider-transactions', ['symbol' => $symbol]);

        $transactions = [];

        foreach ((array) ($data['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $transactions[] = [
                'name'              => (string) ($item['name']                   ?? ''),
                'share'             => (float)  ($item['share']                  ?? 0),
                'change'            => (float)  ($item['change']                 ?? 0),
                'transaction_date'  => (string) ($item['transactionDate']        ?? ''),
                'transaction_price' => (float)  ($item['transactionPrice']       ?? 0),
                'transaction_code'  => (string) ($item['transactionCode']        ?? ''),
            ];
        }

        return $transactions;
    }

    /**
     * Fetch the earnings calendar for a date range.
     *
     * @param string $from Start date Y-m-d (empty = today)
     * @param string $to   End date   Y-m-d (empty = 30 days from now)
     * @return list<array{symbol:string,date:string,eps_estimate:float|null,eps_actual:float|null,revenue_estimate:float|null,revenue_actual:float|null,quarter:int,year:int}>
     * @throws \RuntimeException On API error
     */
    public function getEarningsCalendar(string $from = '', string $to = ''): array
    {
        $from = $from !== '' ? $from : date('Y-m-d');
        $to   = $to   !== '' ? $to   : date('Y-m-d', strtotime('+30 days'));

        $data = $this->request('/calendar/earnings', [
            'from' => $from,
            'to'   => $to,
        ]);

        $events = [];

        foreach ((array) ($data['earningsCalendar'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $nullableFloat = static function (mixed $v): ?float {
                return ($v !== null && $v !== '' && $v !== 'null') ? (float) $v : null;
            };

            $events[] = [
                'symbol'            => (string) ($item['symbol']           ?? ''),
                'date'              => (string) ($item['date']             ?? ''),
                'eps_estimate'      => $nullableFloat($item['epsEstimate'] ?? null),
                'eps_actual'        => $nullableFloat($item['epsActual']   ?? null),
                'revenue_estimate'  => $nullableFloat($item['revenueEstimate'] ?? null),
                'revenue_actual'    => $nullableFloat($item['revenueActual']   ?? null),
                'quarter'           => (int) ($item['quarter'] ?? 0),
                'year'              => (int) ($item['year']    ?? 0),
            ];
        }

        return $events;
    }

    /**
     * Fetch news sentiment metrics for a symbol.
     *
     * @param string $symbol Exchange ticker
     * @return array{symbol:string,buzz_articles_in_last_week:int,buzz_weekly_average:float,buzz_score:float,company_news_score:float,sector_average_bullish_percent:float,sector_average_news_score:float,sentiment_bullish_percent:float,sentiment_bearish_neutral_percent:float}
     * @throws \RuntimeException On API error
     */
    public function getSentiment(string $symbol): array
    {
        $data = $this->request('/news-sentiment', ['symbol' => $symbol]);

        $buzz      = (array) ($data['buzz']      ?? []);
        $sentiment = (array) ($data['sentiment'] ?? []);
        $compScore = (array) ($data['companyNewsScore'] ?? []);

        return [
            'symbol'                         => (string) ($data['symbol']                                  ?? $symbol),
            'buzz_articles_in_last_week'     => (int)    ($buzz['articlesInLastWeek']                      ?? 0),
            'buzz_weekly_average'            => (float)  ($buzz['weeklyAverage']                           ?? 0),
            'buzz_score'                     => (float)  ($buzz['buzz']                                    ?? 0),
            'company_news_score'             => (float)  ($data['companyNewsScore']                        ?? 0),
            'sector_average_bullish_percent' => (float)  ($data['sectorAverageBullishPercent']             ?? 0),
            'sector_average_news_score'      => (float)  ($data['sectorAverageNewsScore']                  ?? 0),
            'sentiment_bullish_percent'      => (float)  ($sentiment['bullishPercent']                     ?? 0),
            'sentiment_bearish_neutral_percent' => (float) (1.0 - (float) ($sentiment['bullishPercent']    ?? 0)),
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Execute a GET request to a Finnhub API endpoint.
     *
     * Passes the API token as a query parameter in addition to the
     * Authorization header. Handles 429 rate-limiting with a single retry
     * after a 1-second sleep. Raises on 403 and other non-200 responses.
     *
     * @param string               $endpoint URL path, e.g. '/quote'
     * @param array<string, mixed> $params   Additional query parameters
     * @return array<string|int, mixed> Decoded JSON response body
     * @throws \RuntimeException On HTTP or API errors
     */
    private function request(string $endpoint, array $params = []): array
    {
        $params['token'] = $this->apiKey;
        $url             = $this->baseUrl . $endpoint;
        $maxAttempts     = 2;

        $this->logger->debug('Finnhub API request', [
            'endpoint' => $endpoint,
            'params'   => array_filter($params, static fn($k) => $k !== 'token', ARRAY_FILTER_USE_KEY),
        ]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response   = $this->client->get($url, ['query' => $params]);
                $statusCode = $response->getStatusCode();
                $body       = (string) $response->getBody();

                $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);

                if (!is_array($decoded)) {
                    throw new \RuntimeException('Finnhub returned a non-array JSON response.');
                }

                return $decoded;
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()?->getStatusCode() ?? 0;

                if ($statusCode === 429 && $attempt < $maxAttempts) {
                    $this->logger->warning('Finnhub rate limit hit — retrying after 1s', [
                        'endpoint' => $endpoint,
                        'attempt'  => $attempt,
                    ]);
                    sleep(1);
                    continue;
                }

                if ($statusCode === 403) {
                    $this->logger->error('Finnhub 403 Forbidden — check API key permissions', [
                        'endpoint' => $endpoint,
                    ]);
                    throw new \RuntimeException(
                        "Finnhub API returned 403 Forbidden for endpoint '{$endpoint}'. Check your API key.",
                        403,
                        $e,
                    );
                }

                throw new \RuntimeException(
                    "Finnhub HTTP request failed for '{$endpoint}': " . $e->getMessage(),
                    $statusCode,
                    $e,
                );
            } catch (\JsonException $e) {
                throw new \RuntimeException(
                    'Finnhub returned invalid JSON: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        throw new \RuntimeException("Finnhub request to '{$endpoint}' failed after {$maxAttempts} attempts.");
    }
}
