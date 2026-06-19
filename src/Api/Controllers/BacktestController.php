<?php

declare(strict_types=1);

namespace AlphaForge\Api\Controllers;

use AlphaForge\Api\Request;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Services\Backtesting\BacktestEngine;

/**
 * Handles all backtesting endpoints:
 *
 *   POST /api/v1/backtest
 *   GET  /api/v1/backtest/{id}
 *   GET  /api/v1/backtest
 *
 * All operations are scoped to the authenticated user.
 */
final class BacktestController
{
    private const MAX_DATE_RANGE_YEARS = 5;
    private const EQUITY_CURVE_MAX_POINTS = 500;

    private readonly Database $db;
    private readonly Logger   $logger;

    public function __construct(
        private readonly BacktestEngine $engine,
    ) {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    // ─── POST /api/v1/backtest ────────────────────────────────────────────────

    /**
     * Configure and run a backtest synchronously, persisting the results.
     */
    public function run(Request $req): void
    {
        $userId = $this->requireUserId($req);
        $body   = $req->getBody();

        // ── Validation ───────────────────────────────────────────────────────
        $errors = [];

        $name      = trim((string) ($body['name']   ?? ''));
        $symbol    = strtoupper(trim((string) ($body['symbol'] ?? '')));
        $startDate = trim((string) ($body['start_date'] ?? ''));
        $endDate   = trim((string) ($body['end_date']   ?? ''));

        if ($symbol === '') {
            $errors['symbol'] = 'Symbol is required.';
        }

        if ($startDate === '') {
            $errors['start_date'] = 'start_date is required (Y-m-d).';
        }

        if ($endDate === '') {
            $errors['end_date'] = 'end_date is required (Y-m-d).';
        }

        if (!empty($errors)) {
            $this->error('Validation failed.', 422, $errors);
        }

        // Parse and validate dates.
        $startDt = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $endDt   = \DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
        $today   = new \DateTimeImmutable('today');

        if ($startDt === false) {
            $errors['start_date'] = 'start_date must be in Y-m-d format.';
        }

        if ($endDt === false) {
            $errors['end_date'] = 'end_date must be in Y-m-d format.';
        }

        if ($startDt !== false && $startDt > $today) {
            $errors['start_date'] = 'start_date cannot be in the future.';
        }

        if ($startDt !== false && $endDt !== false) {
            if ($endDt <= $startDt) {
                $errors['end_date'] = 'end_date must be after start_date.';
            } else {
                $diffYears = (float) $startDt->diff($endDt)->days / 365.25;
                if ($diffYears > self::MAX_DATE_RANGE_YEARS) {
                    $errors['date_range'] = 'Date range cannot exceed ' . self::MAX_DATE_RANGE_YEARS . ' years.';
                }
            }
        }

        if (!empty($errors)) {
            $this->error('Validation failed.', 422, $errors);
        }

        $initialCapital  = max(1.0, (float) ($body['initial_capital']  ?? 10000));
        $commissionRate  = max(0.0, min(0.05, (float) ($body['commission_rate'] ?? 0.001)));
        $slippageRate    = max(0.0, min(0.05, (float) ($body['slippage']        ?? 0.001)));
        $strategy        = is_array($body['strategy'] ?? null) ? $body['strategy'] : [];

        // Resolve asset.
        try {
            $asset = $this->db->fetchOne(
                'SELECT id, symbol FROM assets WHERE symbol = :symbol LIMIT 1',
                ['symbol' => $symbol],
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Database error resolving asset.', 500);
        }

        if ($asset === null) {
            $this->error("Asset '{$symbol}' not found.", 404);
        }

        $config = [
            'symbol'          => $symbol,
            'asset_id'        => $asset['id'],
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'initial_capital' => $initialCapital,
            'commission_rate' => $commissionRate,
            'slippage_rate'   => $slippageRate,
            'strategy'        => $strategy,
        ];

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Insert the backtest record with status='running'.
        try {
            $backtestId = $this->db->insert('backtests', [
                'user_id'          => $userId,
                'name'             => $name !== '' ? $name : "{$symbol} backtest {$startDate} → {$endDate}",
                'strategy_config'  => json_encode($strategy, JSON_UNESCAPED_UNICODE),
                'assets'           => json_encode([$symbol], JSON_UNESCAPED_UNICODE),
                'start_date'       => $startDate,
                'end_date'         => $endDate,
                'initial_capital'  => $initialCapital,
                'commission_rate'  => $commissionRate,
                'slippage_rate'    => $slippageRate,
                'status'           => 'running',
                'created_at'       => $now,
            ]);
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['user_id' => $userId]);
            $this->error('Failed to create backtest record: ' . $e->getMessage(), 500);
        }

        // Run the engine synchronously.
        try {
            $results = $this->engine->run($config);

            // Mark backtest as completed and insert results.
            $this->db->update('backtests', ['status' => 'completed'], ['id' => $backtestId]);

            $resultId = $this->db->insert('backtest_results', [
                'backtest_id'          => $backtestId,
                'total_return_percent' => $results['total_return_percent'] ?? null,
                'sharpe_ratio'         => $results['sharpe_ratio']         ?? null,
                'sortino_ratio'        => $results['sortino_ratio']        ?? null,
                'max_drawdown_percent' => $results['max_drawdown_percent'] ?? null,
                'win_rate'             => $results['win_rate']             ?? null,
                'profit_factor'        => $results['profit_factor']        ?? null,
                'total_trades'         => $results['total_trades']         ?? null,
                'equity_curve'         => isset($results['equity_curve'])
                    ? json_encode($results['equity_curve'], JSON_UNESCAPED_UNICODE)
                    : null,
                'trade_log'            => isset($results['trade_log'])
                    ? json_encode($results['trade_log'], JSON_UNESCAPED_UNICODE)
                    : null,
                'monthly_returns'      => isset($results['monthly_returns'])
                    ? json_encode($results['monthly_returns'], JSON_UNESCAPED_UNICODE)
                    : null,
                'created_at'           => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $summary = [
                'backtest_id'          => $backtestId,
                'result_id'            => $resultId,
                'status'               => 'completed',
                'symbol'               => $symbol,
                'start_date'           => $startDate,
                'end_date'             => $endDate,
                'total_return_percent' => $results['total_return_percent'] ?? null,
                'sharpe_ratio'         => $results['sharpe_ratio']         ?? null,
                'max_drawdown_percent' => $results['max_drawdown_percent'] ?? null,
                'win_rate'             => $results['win_rate']             ?? null,
                'total_trades'         => $results['total_trades']         ?? null,
            ];

            $this->success($summary, 'Backtest completed.', 201);
        } catch (\Throwable $e) {
            // Mark as failed and re-surface the error.
            try {
                $this->db->update('backtests', ['status' => 'failed'], ['id' => $backtestId]);
            } catch (\Throwable) {
                // Ignore secondary failure.
            }

            $this->logger->exception($e, ['backtest_id' => $backtestId]);
            $this->error('Backtest execution failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/backtest/{id} ────────────────────────────────────────────

    /**
     * Return the full result for a single backtest, including equity curve
     * (downsampled to 500 points max), trade log, and monthly returns.
     */
    public function getResult(Request $req): void
    {
        $userId     = $this->requireUserId($req);
        $backtest   = $this->requireBacktestOwnership($req, $userId);

        try {
            $result = $this->db->fetchOne(
                'SELECT * FROM backtest_results WHERE backtest_id = :backtest_id ORDER BY created_at DESC LIMIT 1',
                ['backtest_id' => $backtest['id']],
            );

            // Decode JSON columns.
            $equityCurve     = $this->decodeJsonColumn($result['equity_curve'] ?? null);
            $tradeLog        = $this->decodeJsonColumn($result['trade_log'] ?? null);
            $monthlyReturns  = $this->decodeJsonColumn($result['monthly_returns'] ?? null);
            $strategyConfig  = $this->decodeJsonColumn($backtest['strategy_config'] ?? null);
            $assets          = $this->decodeJsonColumn($backtest['assets'] ?? null);

            // Downsample equity curve to max 500 points.
            if (is_array($equityCurve) && count($equityCurve) > self::EQUITY_CURVE_MAX_POINTS) {
                $equityCurve = $this->downsample($equityCurve, self::EQUITY_CURVE_MAX_POINTS);
            }

            $this->success([
                'backtest' => [
                    'id'              => $backtest['id'],
                    'name'            => $backtest['name'],
                    'status'          => $backtest['status'],
                    'assets'          => $assets,
                    'start_date'      => $backtest['start_date'],
                    'end_date'        => $backtest['end_date'],
                    'initial_capital' => $backtest['initial_capital'],
                    'commission_rate' => $backtest['commission_rate'],
                    'slippage_rate'   => $backtest['slippage_rate'],
                    'strategy_config' => $strategyConfig,
                    'created_at'      => $backtest['created_at'],
                ],
                'result' => $result !== null ? [
                    'id'                   => $result['id'],
                    'total_return_percent' => $result['total_return_percent'],
                    'sharpe_ratio'         => $result['sharpe_ratio'],
                    'sortino_ratio'        => $result['sortino_ratio'],
                    'max_drawdown_percent' => $result['max_drawdown_percent'],
                    'win_rate'             => $result['win_rate'],
                    'profit_factor'        => $result['profit_factor'],
                    'total_trades'         => $result['total_trades'],
                    'equity_curve'         => $equityCurve,
                    'trade_log'            => $tradeLog,
                    'monthly_returns'      => $monthlyReturns,
                    'created_at'           => $result['created_at'],
                ] : null,
            ], 'Backtest result retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['backtest_id' => $backtest['id']]);
            $this->error('Failed to retrieve backtest result: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/backtest ─────────────────────────────────────────────────

    /**
     * Return a paginated list of the user's backtests with summary metrics.
     * Large JSON columns (equity_curve, trade_log) are excluded from the list.
     */
    public function list(Request $req): void
    {
        $userId     = $this->requireUserId($req);
        $pagination = $req->getPaginationParams();

        try {
            $totalRow = $this->db->fetchOne(
                'SELECT COUNT(*) AS total FROM backtests WHERE user_id = :user_id',
                ['user_id' => $userId],
            );
            $total = (int) ($totalRow['total'] ?? 0);

            $backtests = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        b.id,
                        b.name,
                        b.assets,
                        b.start_date,
                        b.end_date,
                        b.initial_capital,
                        b.commission_rate,
                        b.slippage_rate,
                        b.status,
                        b.created_at,
                        br.total_return_percent,
                        br.sharpe_ratio,
                        br.sortino_ratio,
                        br.max_drawdown_percent,
                        br.win_rate,
                        br.profit_factor,
                        br.total_trades,
                        br.created_at AS result_at
                    FROM backtests b
                    LEFT JOIN backtest_results br ON br.backtest_id = b.id
                    WHERE b.user_id = :user_id
                    ORDER BY b.created_at DESC
                    LIMIT :limit OFFSET :offset
                SQL,
                [
                    'user_id' => $userId,
                    'limit'   => $pagination['per_page'],
                    'offset'  => $pagination['offset'],
                ],
            );

            // Decode assets JSON column for each row.
            $backtests = array_map(function (array $row): array {
                $row['assets'] = $this->decodeJsonColumn($row['assets'] ?? null);
                return $row;
            }, $backtests);

            $this->success([
                'backtests'  => $backtests,
                'pagination' => [
                    'total'    => $total,
                    'page'     => $pagination['page'],
                    'per_page' => $pagination['per_page'],
                    'pages'    => (int) ceil($total / $pagination['per_page']),
                ],
            ], 'Backtests retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['user_id' => $userId]);
            $this->error('Failed to retrieve backtests: ' . $e->getMessage(), 500);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function requireUserId(Request $req): string
    {
        $user = $req->getUser();
        if ($user === null || empty($user['id'])) {
            $this->error('Authentication required.', 401);
        }
        return (string) $user['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireBacktestOwnership(Request $req, string $userId): array
    {
        $backtestId = trim((string) $req->getParam('id', ''));

        if ($backtestId === '') {
            $this->error('Backtest ID is required.', 400);
        }

        try {
            $backtest = $this->db->fetchOne(
                'SELECT * FROM backtests WHERE id = :id LIMIT 1',
                ['id' => $backtestId],
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Database error.', 500);
        }

        if ($backtest === null) {
            $this->error('Backtest not found.', 404);
        }

        if ((string) $backtest['user_id'] !== $userId) {
            $this->error('You do not have access to this backtest.', 403);
        }

        return $backtest;
    }

    /**
     * Decode a JSON column that may be a string (from the DB) or already an array.
     *
     * @return mixed
     */
    private function decodeJsonColumn(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        return null;
    }

    /**
     * Downsample an array to at most $maxPoints entries using uniform stride.
     * Always includes the first and last elements.
     *
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function downsample(array $data, int $maxPoints): array
    {
        $count = count($data);
        if ($count <= $maxPoints) {
            return $data;
        }

        $result = [];
        $step   = ($count - 1) / ($maxPoints - 1);

        for ($i = 0; $i < $maxPoints; $i++) {
            $index    = (int) round($i * $step);
            $result[] = $data[$index];
        }

        return $result;
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
