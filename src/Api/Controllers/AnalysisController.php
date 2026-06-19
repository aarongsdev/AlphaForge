<?php

declare(strict_types=1);

namespace AlphaForge\Api\Controllers;

use AlphaForge\Api\Request;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Services\Analysis\FundamentalAnalysisService;
use AlphaForge\Services\Analysis\SentimentAnalysisService;
use AlphaForge\Services\Analysis\TechnicalAnalysisService;

/**
 * Handles all analysis endpoints:
 *
 *   GET  /api/v1/analysis/{symbol}/technical
 *   GET  /api/v1/analysis/{symbol}/fundamental
 *   GET  /api/v1/analysis/{symbol}/sentiment
 *   GET  /api/v1/analysis/{symbol}/full
 *   GET  /api/v1/analysis/{symbol}/signals
 *   POST /api/v1/analysis/{symbol}/refresh
 */
final class AnalysisController
{
    private readonly Database $db;
    private readonly Logger   $logger;

    public function __construct(
        private readonly TechnicalAnalysisService   $technical,
        private readonly FundamentalAnalysisService $fundamental,
        private readonly SentimentAnalysisService   $sentiment,
    ) {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    // ─── GET /api/v1/analysis/{symbol}/technical ──────────────────────────────

    public function getTechnical(Request $req): void
    {
        $asset = $this->resolveAsset($req);

        try {
            $result = $this->technical->analyze($asset['id']);

            $this->success([
                'symbol'      => $asset['symbol'],
                'analyzed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'analysis'    => $result,
            ], 'Technical analysis complete.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['asset_id' => $asset['id']]);
            $this->error('Technical analysis failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/analysis/{symbol}/fundamental ────────────────────────────

    public function getFundamental(Request $req): void
    {
        $asset = $this->resolveAsset($req);

        try {
            $result = $this->fundamental->analyze($asset['id']);

            $this->success([
                'symbol'      => $asset['symbol'],
                'analyzed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'analysis'    => $result,
            ], 'Fundamental analysis complete.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['asset_id' => $asset['id']]);
            $this->error('Fundamental analysis failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/analysis/{symbol}/sentiment ──────────────────────────────

    public function getSentiment(Request $req): void
    {
        $asset = $this->resolveAsset($req);

        try {
            $result = $this->sentiment->analyze($asset['id']);

            $this->success([
                'symbol'      => $asset['symbol'],
                'analyzed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'analysis'    => $result,
            ], 'Sentiment analysis complete.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['asset_id' => $asset['id']]);
            $this->error('Sentiment analysis failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/analysis/{symbol}/full ──────────────────────────────────

    /**
     * Run all three analysis services and attach recent signals.
     */
    public function getFullAnalysis(Request $req): void
    {
        $asset = $this->resolveAsset($req);
        $now   = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        try {
            // Run all three services; capture individual failures so a single
            // bad service does not take down the whole response.
            $technicalResult    = null;
            $fundamentalResult  = null;
            $sentimentResult    = null;
            $errors             = [];

            try {
                $technicalResult = $this->technical->analyze($asset['id']);
            } catch (\Throwable $e) {
                $this->logger->exception($e, ['service' => 'technical', 'asset_id' => $asset['id']]);
                $errors['technical'] = $e->getMessage();
            }

            try {
                $fundamentalResult = $this->fundamental->analyze($asset['id']);
            } catch (\Throwable $e) {
                $this->logger->exception($e, ['service' => 'fundamental', 'asset_id' => $asset['id']]);
                $errors['fundamental'] = $e->getMessage();
            }

            try {
                $sentimentResult = $this->sentiment->analyze($asset['id']);
            } catch (\Throwable $e) {
                $this->logger->exception($e, ['service' => 'sentiment', 'asset_id' => $asset['id']]);
                $errors['sentiment'] = $e->getMessage();
            }

            // Fetch recent signals for this asset (last 30 days, max 10).
            $recentSignals = $this->db->fetchAll(
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
                        s.target_price_2,
                        s.stop_loss_price,
                        s.risk_reward_ratio,
                        s.composite_score,
                        s.market_regime,
                        s.status,
                        s.created_at,
                        s.expires_at,
                        sp.outcome,
                        sp.actual_return_percent,
                        sp.max_drawdown,
                        sp.days_to_outcome
                    FROM signals s
                    LEFT JOIN signal_performance sp ON sp.signal_id = s.id
                    WHERE s.asset_id   = :asset_id
                      AND s.created_at >= NOW() - INTERVAL '30 days'
                    ORDER BY s.created_at DESC
                    LIMIT 10
                SQL,
                ['asset_id' => $asset['id']],
            );

            $payload = [
                'symbol'         => $asset['symbol'],
                'technical'      => $technicalResult,
                'fundamental'    => $fundamentalResult,
                'sentiment'      => $sentimentResult,
                'recent_signals' => $recentSignals,
                'analyzed_at'    => $now,
            ];

            if (!empty($errors)) {
                $payload['partial_errors'] = $errors;
            }

            $this->success($payload, 'Full analysis complete.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['asset_id' => $asset['id']]);
            $this->error('Full analysis failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/analysis/{symbol}/signals ───────────────────────────────

    /**
     * Return paginated signals for the given symbol.
     *
     * Query params:
     *   timeframe   – filter by timeframe (optional)
     *   signal_type – BUY | SELL | HOLD filter (optional)
     *   limit       – max results (default 20)
     *   page        – page number (default 1)
     */
    public function getSignals(Request $req): void
    {
        $asset = $this->resolveAsset($req);

        $pagination  = $req->getPaginationParams();
        $timeframe   = trim((string) $req->getQuery('timeframe', ''));
        $signalType  = strtoupper(trim((string) $req->getQuery('signal_type', '')));

        // Build dynamic WHERE clause.
        $where  = ['s.asset_id = :asset_id'];
        $params = ['asset_id' => $asset['id']];

        if ($timeframe !== '') {
            $where[]             = 's.timeframe = :timeframe';
            $params['timeframe'] = $timeframe;
        }

        if (in_array($signalType, ['BUY', 'SELL', 'HOLD'], true)) {
            $where[]              = 's.signal_type = :signal_type';
            $params['signal_type'] = $signalType;
        }

        $whereClause = implode(' AND ', $where);

        try {
            // Total count for pagination meta.
            $totalRow = $this->db->fetchOne(
                "SELECT COUNT(*) AS total FROM signals s WHERE {$whereClause}",
                $params,
            );
            $total = (int) ($totalRow['total'] ?? 0);

            // Paginated result set.
            $params['limit']  = $pagination['per_page'];
            $params['offset'] = $pagination['offset'];

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
                        s.technical_score,
                        s.fundamental_score,
                        s.sentiment_score,
                        s.macro_score,
                        s.composite_score,
                        s.reasoning,
                        s.key_factors,
                        s.market_regime,
                        s.status,
                        s.created_at,
                        s.expires_at,
                        sp.outcome,
                        sp.actual_return_percent,
                        sp.max_drawdown,
                        sp.days_to_outcome
                    FROM signals s
                    LEFT JOIN signal_performance sp ON sp.signal_id = s.id
                    WHERE {$whereClause}
                    ORDER BY s.created_at DESC
                    LIMIT :limit OFFSET :offset
                SQL,
                $params,
            );

            $this->success([
                'signals'    => $signals,
                'pagination' => [
                    'total'    => $total,
                    'page'     => $pagination['page'],
                    'per_page' => $pagination['per_page'],
                    'pages'    => (int) ceil($total / $pagination['per_page']),
                ],
            ], 'Signals retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['asset_id' => $asset['id']]);
            $this->error('Failed to retrieve signals: ' . $e->getMessage(), 500);
        }
    }

    // ─── POST /api/v1/analysis/{symbol}/refresh ───────────────────────────────

    /**
     * Force-refresh all analysis data for the given symbol.
     * Requires the authenticated user to have the 'analyst' or 'admin' role.
     */
    public function refreshAnalysis(Request $req): void
    {
        $user = $req->getUser();

        if ($user === null) {
            $this->error('Authentication required.', 401);
        }

        $roles = (array) ($user['roles'] ?? []);

        if (!array_intersect(['analyst', 'admin'], $roles)) {
            $this->error('Insufficient permissions. Requires analyst or admin role.', 403);
        }

        $asset = $this->resolveAsset($req);

        try {
            $this->technical->analyze($asset['id']);
            $this->fundamental->analyze($asset['id']);
            $this->sentiment->analyze($asset['id']);

            $this->success([
                'refreshed' => true,
                'symbol'    => $asset['symbol'],
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], 'Analysis refreshed successfully.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['asset_id' => $asset['id']]);
            $this->error('Refresh failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Resolve a symbol from route params to an assets row.
     * Terminates with a 404 JSON response if the asset does not exist.
     *
     * @return array<string, mixed>
     */
    private function resolveAsset(Request $req): array
    {
        $symbol = strtoupper(trim((string) $req->getParam('symbol', '')));

        if ($symbol === '') {
            $this->error('Symbol is required.', 400);
        }

        try {
            $asset = $this->db->fetchOne(
                'SELECT id, symbol, name, exchange, asset_type, sector FROM assets WHERE symbol = :symbol LIMIT 1',
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
