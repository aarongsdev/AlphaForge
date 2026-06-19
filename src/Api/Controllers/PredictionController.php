<?php

declare(strict_types=1);

namespace AlphaForge\Api\Controllers;

use AlphaForge\Api\Request;
use AlphaForge\Core\Cache;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Services\Prediction\PredictionEngine;

/**
 * Handles all prediction endpoints:
 *
 *   GET /api/v1/predictions/{symbol}
 *   GET /api/v1/predictions/{symbol}/history
 *   GET /api/v1/predictions/signals/latest
 *   GET /api/v1/predictions/signals/performance
 */
final class PredictionController
{
    private const CACHE_TTL_SECONDS = 900; // 15 minutes

    private readonly Database $db;
    private readonly Cache    $cache;
    private readonly Logger   $logger;

    public function __construct(
        private readonly PredictionEngine $engine,
    ) {
        $this->db     = Database::getInstance();
        $this->cache  = Cache::getInstance();
        $this->logger = Logger::getInstance();
    }

    // ─── GET /api/v1/predictions/{symbol} ────────────────────────────────────

    /**
     * Return a price / direction prediction for the given symbol.
     *
     * Query params:
     *   timeframe     – short_term (default) | medium_term | long_term
     *   force_refresh – 1 | true to bypass cache
     */
    public function getPrediction(Request $req): void
    {
        $symbol       = strtoupper(trim((string) $req->getParam('symbol', '')));
        $timeframe    = strtolower(trim((string) $req->getQuery('timeframe', 'short_term')));
        $forceRefresh = filter_var($req->getQuery('force_refresh', false), FILTER_VALIDATE_BOOLEAN);

        if ($symbol === '') {
            $this->error('Symbol is required.', 400);
        }

        $allowedTimeframes = ['short_term', 'medium_term', 'long_term'];
        if (!in_array($timeframe, $allowedTimeframes, true)) {
            $this->error(
                'Invalid timeframe. Allowed: ' . implode(', ', $allowedTimeframes) . '.',
                400,
            );
        }

        // Resolve the asset record.
        $asset = $this->resolveAsset($symbol);

        $cacheKey = "predictions:{$asset['id']}:{$timeframe}";

        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->success(array_merge($cached, ['cached' => true]), 'Prediction retrieved from cache.');
            }
        }

        try {
            $prediction = $this->engine->predict($asset['id'], $timeframe);

            $payload = array_merge($prediction, [
                'symbol'    => $asset['symbol'],
                'timeframe' => $timeframe,
                'cached'    => false,
                'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]);

            $this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);

            $this->success($payload, 'Prediction generated.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['symbol' => $symbol, 'timeframe' => $timeframe]);
            $this->error('Prediction failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/predictions/{symbol}/history ─────────────────────────────

    /**
     * Return the historical signal/prediction record for a symbol with a
     * win-rate and average-return summary.
     */
    public function getPredictionHistory(Request $req): void
    {
        $symbol = strtoupper(trim((string) $req->getParam('symbol', '')));

        if ($symbol === '') {
            $this->error('Symbol is required.', 400);
        }

        $asset      = $this->resolveAsset($symbol);
        $pagination = $req->getPaginationParams();

        try {
            // Total count for pagination.
            $totalRow = $this->db->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*) AS total
                    FROM signals s
                    JOIN signal_performance sp ON sp.signal_id = s.id
                    WHERE s.asset_id = :asset_id
                SQL,
                ['asset_id' => $asset['id']],
            );
            $total = (int) ($totalRow['total'] ?? 0);

            // Summary metrics.
            $summary = $this->db->fetchOne(
                <<<'SQL'
                    SELECT
                        COUNT(*)                                            AS total_signals,
                        COUNT(*) FILTER (WHERE sp.outcome = 'win')         AS wins,
                        COUNT(*) FILTER (WHERE sp.outcome = 'loss')        AS losses,
                        ROUND(
                            COUNT(*) FILTER (WHERE sp.outcome = 'win')::numeric
                            / NULLIF(COUNT(*), 0) * 100,
                            2
                        )                                                   AS win_rate,
                        ROUND(AVG(sp.actual_return_percent)::numeric, 4)   AS avg_return
                    FROM signals s
                    JOIN signal_performance sp ON sp.signal_id = s.id
                    WHERE s.asset_id = :asset_id
                SQL,
                ['asset_id' => $asset['id']],
            );

            // Paginated signal history.
            $history = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        s.id,
                        s.signal_type,
                        s.strength,
                        s.timeframe,
                        s.confidence_score,
                        s.probability_success,
                        s.entry_price,
                        s.target_price_1,
                        s.stop_loss_price,
                        s.composite_score,
                        s.status,
                        s.created_at,
                        sp.outcome,
                        sp.actual_return_percent,
                        sp.max_drawdown,
                        sp.days_to_outcome
                    FROM signals s
                    JOIN signal_performance sp ON sp.signal_id = s.id
                    WHERE s.asset_id = :asset_id
                    ORDER BY s.created_at DESC
                    LIMIT :limit OFFSET :offset
                SQL,
                [
                    'asset_id' => $asset['id'],
                    'limit'    => $pagination['per_page'],
                    'offset'   => $pagination['offset'],
                ],
            );

            $this->success([
                'symbol'  => $asset['symbol'],
                'summary' => $summary,
                'history' => $history,
                'pagination' => [
                    'total'    => $total,
                    'page'     => $pagination['page'],
                    'per_page' => $pagination['per_page'],
                    'pages'    => (int) ceil($total / $pagination['per_page']),
                ],
            ], 'Prediction history retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['symbol' => $symbol]);
            $this->error('Failed to retrieve prediction history: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/predictions/signals/latest ───────────────────────────────

    /**
     * Return the most-recent signals across all assets from the last 24 hours.
     *
     * Query params:
     *   signal_type – BUY | SELL | HOLD (optional filter)
     *   timeframe   – filter by timeframe (optional)
     *   limit       – max results (default 20, max 100)
     */
    public function getLatestSignals(Request $req): void
    {
        $signalType = strtoupper(trim((string) $req->getQuery('signal_type', '')));
        $timeframe  = trim((string) $req->getQuery('timeframe', ''));
        $limit      = min(100, max(1, (int) $req->getQuery('limit', 20)));

        $where  = ["s.created_at > NOW() - INTERVAL '24 hours'"];
        $params = [];

        if (in_array($signalType, ['BUY', 'SELL', 'HOLD'], true)) {
            $where[]              = 's.signal_type = :signal_type';
            $params['signal_type'] = $signalType;
        }

        if ($timeframe !== '') {
            $where[]             = 's.timeframe = :timeframe';
            $params['timeframe'] = $timeframe;
        }

        $whereClause   = implode(' AND ', $where);
        $params['lim'] = $limit;

        try {
            $signals = $this->db->fetchAll(
                <<<SQL
                    SELECT
                        s.id,
                        s.signal_type,
                        s.strength,
                        s.timeframe,
                        s.confidence_score,
                        s.probability_success,
                        s.entry_price,
                        s.target_price_1,
                        s.target_price_2,
                        s.stop_loss_price,
                        s.risk_reward_ratio,
                        s.composite_score,
                        s.market_regime,
                        s.status,
                        s.created_at,
                        s.expires_at,
                        a.symbol,
                        a.name,
                        a.exchange,
                        a.asset_type
                    FROM signals s
                    JOIN assets a ON a.id = s.asset_id
                    WHERE {$whereClause}
                    ORDER BY s.confidence_score DESC, s.created_at DESC
                    LIMIT :lim
                SQL,
                $params,
            );

            $this->success([
                'signals' => $signals,
                'count'   => count($signals),
            ], 'Latest signals retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Failed to retrieve latest signals: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/predictions/signals/performance ─────────────────────────

    /**
     * Return aggregated signal performance metrics broken down by signal_type
     * and by timeframe.
     */
    public function getSignalPerformance(Request $req): void
    {
        try {
            // Overall summary.
            $overall = $this->db->fetchOne(
                <<<'SQL'
                    SELECT
                        COUNT(*)                                            AS total_signals,
                        COUNT(*) FILTER (WHERE sp.outcome = 'win')         AS win_count,
                        COUNT(*) FILTER (WHERE sp.outcome = 'loss')        AS loss_count,
                        ROUND(
                            COUNT(*) FILTER (WHERE sp.outcome = 'win')::numeric
                            / NULLIF(COUNT(*), 0) * 100,
                            2
                        )                                                   AS win_rate,
                        ROUND(AVG(sp.actual_return_percent)::numeric, 4)   AS avg_return,
                        ROUND(AVG(sp.max_drawdown)::numeric, 4)            AS avg_drawdown
                    FROM signals s
                    JOIN signal_performance sp ON sp.signal_id = s.id
                SQL,
            );

            // Breakdown by signal_type.
            $byType = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        s.signal_type,
                        COUNT(*)                                            AS total,
                        COUNT(*) FILTER (WHERE sp.outcome = 'win')         AS wins,
                        COUNT(*) FILTER (WHERE sp.outcome = 'loss')        AS losses,
                        ROUND(
                            COUNT(*) FILTER (WHERE sp.outcome = 'win')::numeric
                            / NULLIF(COUNT(*), 0) * 100,
                            2
                        )                                                   AS win_rate,
                        ROUND(AVG(sp.actual_return_percent)::numeric, 4)   AS avg_return,
                        ROUND(AVG(sp.max_drawdown)::numeric, 4)            AS avg_drawdown
                    FROM signals s
                    JOIN signal_performance sp ON sp.signal_id = s.id
                    GROUP BY s.signal_type
                    ORDER BY s.signal_type
                SQL,
            );

            // Breakdown by timeframe.
            $byTimeframe = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        s.timeframe,
                        COUNT(*)                                            AS total,
                        COUNT(*) FILTER (WHERE sp.outcome = 'win')         AS wins,
                        COUNT(*) FILTER (WHERE sp.outcome = 'loss')        AS losses,
                        ROUND(
                            COUNT(*) FILTER (WHERE sp.outcome = 'win')::numeric
                            / NULLIF(COUNT(*), 0) * 100,
                            2
                        )                                                   AS win_rate,
                        ROUND(AVG(sp.actual_return_percent)::numeric, 4)   AS avg_return,
                        ROUND(AVG(sp.max_drawdown)::numeric, 4)            AS avg_drawdown
                    FROM signals s
                    JOIN signal_performance sp ON sp.signal_id = s.id
                    GROUP BY s.timeframe
                    ORDER BY s.timeframe
                SQL,
            );

            $this->success([
                'overall'      => $overall,
                'by_type'      => $byType,
                'by_timeframe' => $byTimeframe,
            ], 'Signal performance retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Failed to retrieve signal performance: ' . $e->getMessage(), 500);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Resolve a symbol string to an assets row, terminating with 404 if not found.
     *
     * @return array<string, mixed>
     */
    private function resolveAsset(string $symbol): array
    {
        try {
            $asset = $this->db->fetchOne(
                'SELECT id, symbol, name, exchange, asset_type FROM assets WHERE symbol = :symbol LIMIT 1',
                ['symbol' => $symbol],
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['symbol' => $symbol]);
            $this->error('Database error while resolving asset.', 500);
        }

        if ($asset === null) {
            $this->error("Asset '{$symbol}' not found.", 404);
        }

        return $asset;
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

    private function error(string $message, int $status = 400, mixed $errors = null): never
    {
        $this->json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }
}
