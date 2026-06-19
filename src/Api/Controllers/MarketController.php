<?php

declare(strict_types=1);

namespace AlphaForge\Api\Controllers;

use AlphaForge\Api\Request;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Services\Market\MarketDataService;

/**
 * Handles all market-data endpoints:
 *
 *   GET /api/v1/market/quote/{symbol}
 *   GET /api/v1/market/historical/{symbol}
 *   GET /api/v1/market/search
 *   GET /api/v1/market/overview
 *   GET /api/v1/market/movers
 *   GET /api/v1/market/calendar
 */
final class MarketController
{
    private readonly Database $db;
    private readonly Logger   $logger;

    public function __construct(
        private readonly MarketDataService $marketData,
    ) {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    // ─── GET /api/v1/market/quote/{symbol} ───────────────────────────────────

    /**
     * Return the latest quote for the given symbol.
     */
    public function getQuote(Request $req): void
    {
        $symbol = strtoupper(trim((string) $req->getParam('symbol', '')));

        if ($symbol === '') {
            $this->error('Symbol is required.', 400);
        }

        try {
            $quote = $this->marketData->getQuote($symbol);
            $this->success($quote, 'Quote retrieved successfully.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['symbol' => $symbol]);
            $this->error('Failed to retrieve quote: ' . $e->getMessage(), 502);
        }
    }

    // ─── GET /api/v1/market/historical/{symbol} ───────────────────────────────

    /**
     * Return OHLCV historical data for the given symbol.
     *
     * Query params:
     *   interval  – daily (default) | weekly | monthly
     *   periods   – number of periods, max 500 (default 200)
     */
    public function getHistorical(Request $req): void
    {
        $symbol = strtoupper(trim((string) $req->getParam('symbol', '')));

        if ($symbol === '') {
            $this->error('Symbol is required.', 400);
        }

        $allowedIntervals = ['daily', 'weekly', 'monthly'];
        $interval         = strtolower(trim((string) $req->getQuery('interval', 'daily')));

        if (!in_array($interval, $allowedIntervals, true)) {
            $this->error(
                'Invalid interval. Allowed values: ' . implode(', ', $allowedIntervals) . '.',
                400,
            );
        }

        $periods = min(500, max(1, (int) $req->getQuery('periods', 200)));

        try {
            $data = $this->marketData->getHistoricalData($symbol, $interval, $periods);

            $this->success([
                'meta' => [
                    'symbol'   => $symbol,
                    'interval' => $interval,
                    'periods'  => $periods,
                ],
                'data' => $data,
            ], 'Historical data retrieved successfully.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['symbol' => $symbol, 'interval' => $interval]);
            $this->error('Failed to retrieve historical data: ' . $e->getMessage(), 502);
        }
    }

    // ─── GET /api/v1/market/search ────────────────────────────────────────────

    /**
     * Search for assets by keyword.
     *
     * Query params:
     *   q    – search query (required, min 2 chars)
     *   type – asset type filter (optional)
     */
    public function search(Request $req): void
    {
        $q    = trim((string) $req->getQuery('q', ''));
        $type = trim((string) $req->getQuery('type', ''));

        if ($q === '') {
            $this->error('Query parameter "q" is required.', 400);
        }

        if (mb_strlen($q) < 2) {
            $this->error('Query parameter "q" must be at least 2 characters.', 400);
        }

        try {
            $results = $this->marketData->searchAssets($q, $type);
            $this->success(['results' => $results, 'count' => count($results)], 'Search completed.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['q' => $q, 'type' => $type]);
            $this->error('Search failed: ' . $e->getMessage(), 502);
        }
    }

    // ─── GET /api/v1/market/overview ──────────────────────────────────────────

    /**
     * Return a high-level market overview (indices, sectors, sentiment).
     */
    public function getOverview(Request $req): void
    {
        try {
            $overview = $this->marketData->getMarketOverview();
            $this->success($overview, 'Market overview retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Failed to retrieve market overview: ' . $e->getMessage(), 502);
        }
    }

    // ─── GET /api/v1/market/movers ────────────────────────────────────────────

    /**
     * Return today's top 10 gainers, losers, and most-active assets.
     *
     * Queries market_data_daily comparing today vs the previous trading day.
     */
    public function getMovers(Request $req): void
    {
        try {
            // Pull today's and yesterday's close for every asset that has today's data.
            $sql = <<<'SQL'
                SELECT
                    a.id,
                    a.symbol,
                    a.name,
                    a.exchange,
                    a.asset_type,
                    today.close_price                                                   AS price,
                    today.volume                                                        AS volume,
                    today.close_price - prev.close_price                               AS change_amount,
                    CASE WHEN prev.close_price <> 0
                         THEN ROUND(
                                ((today.close_price - prev.close_price) / prev.close_price) * 100,
                                2
                              )
                         ELSE 0
                    END                                                                 AS change_percent
                FROM market_data_daily today
                JOIN market_data_daily prev
                    ON  prev.asset_id   = today.asset_id
                    AND prev.trade_date = (
                        SELECT MAX(d2.trade_date)
                        FROM   market_data_daily d2
                        WHERE  d2.asset_id   = today.asset_id
                          AND  d2.trade_date < today.trade_date
                    )
                JOIN assets a ON a.id = today.asset_id
                WHERE today.trade_date = (
                    SELECT MAX(trade_date) FROM market_data_daily
                )
            SQL;

            $rows = $this->db->fetchAll($sql);

            // Sort copies for each category.
            $byGain   = $rows;
            $byLoss   = $rows;
            $byVolume = $rows;

            usort($byGain,   static fn($a, $b) => $b['change_percent'] <=> $a['change_percent']);
            usort($byLoss,   static fn($a, $b) => $a['change_percent'] <=> $b['change_percent']);
            usort($byVolume, static fn($a, $b) => $b['volume']         <=> $a['volume']);

            $this->success([
                'gainers'     => array_slice($byGain,   0, 10),
                'losers'      => array_slice($byLoss,   0, 10),
                'most_active' => array_slice($byVolume, 0, 10),
            ], 'Market movers retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Failed to retrieve market movers: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/market/calendar ─────────────────────────────────────────

    /**
     * Return the economic and earnings calendar for the next 7 days.
     */
    public function getCalendar(Request $req): void
    {
        try {
            $economic = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        id,
                        event_name,
                        event_date,
                        country,
                        impact,
                        forecast,
                        actual,
                        previous
                    FROM economic_calendar
                    WHERE event_date >= CURRENT_DATE
                      AND event_date <  CURRENT_DATE + INTERVAL '7 days'
                    ORDER BY event_date ASC, impact DESC
                SQL,
            );

            $earnings = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        ec.id,
                        ec.report_date,
                        ec.period,
                        ec.eps_estimate,
                        ec.eps_actual,
                        ec.revenue_estimate,
                        ec.revenue_actual,
                        a.symbol,
                        a.name,
                        a.exchange
                    FROM earnings_calendar ec
                    JOIN assets a ON a.id = ec.asset_id
                    WHERE ec.report_date >= CURRENT_DATE
                      AND ec.report_date <  CURRENT_DATE + INTERVAL '7 days'
                    ORDER BY ec.report_date ASC
                SQL,
            );

            $this->success([
                'economic' => $economic,
                'earnings' => $earnings,
            ], 'Calendar retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Failed to retrieve calendar: ' . $e->getMessage(), 500);
        }
    }

    // ─── Response helpers ─────────────────────────────────────────────────────

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function success(mixed $data, string $message = 'Success', int $status = 200): void
    {
        $this->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function error(string $message, int $status = 400, mixed $errors = null): void
    {
        $this->json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }
}
