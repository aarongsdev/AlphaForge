<?php

declare(strict_types=1);

namespace AlphaForge\Api\Controllers;

use AlphaForge\Api\Request;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;

/**
 * Handles all portfolio management endpoints:
 *
 *   GET    /api/v1/portfolio
 *   POST   /api/v1/portfolio
 *   GET    /api/v1/portfolio/{id}
 *   PUT    /api/v1/portfolio/{id}
 *   DELETE /api/v1/portfolio/{id}
 *   GET    /api/v1/portfolio/{id}/positions
 *   GET    /api/v1/portfolio/{id}/performance
 *   GET    /api/v1/portfolio/{id}/risk
 *   POST   /api/v1/portfolio/{id}/trade
 *   GET    /api/v1/portfolio/{id}/trades
 *
 * All operations are scoped to the authenticated user.
 */
final class PortfolioController
{
    private readonly Database $db;
    private readonly Logger   $logger;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    // ─── GET /api/v1/portfolio ────────────────────────────────────────────────

    public function list(Request $req): void
    {
        $userId = $this->requireUserId($req);

        try {
            $portfolios = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        p.id,
                        p.name,
                        p.initial_capital,
                        p.current_value,
                        p.is_paper_trading,
                        p.benchmark_symbol,
                        p.created_at,
                        COUNT(pp.id) AS position_count
                    FROM portfolios p
                    LEFT JOIN portfolio_positions pp ON pp.portfolio_id = p.id
                    WHERE p.user_id = :user_id
                    GROUP BY p.id
                    ORDER BY p.created_at DESC
                SQL,
                ['user_id' => $userId],
            );

            $this->success($portfolios, 'Portfolios retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['user_id' => $userId]);
            $this->error('Failed to retrieve portfolios: ' . $e->getMessage(), 500);
        }
    }

    // ─── POST /api/v1/portfolio ───────────────────────────────────────────────

    public function create(Request $req): void
    {
        $userId = $this->requireUserId($req);
        $body   = $req->getBody();

        // Validation.
        $errors = [];

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Portfolio name is required.';
        }

        $initialCapital = isset($body['initial_capital']) ? (float) $body['initial_capital'] : null;
        if ($initialCapital === null || $initialCapital <= 0) {
            $errors['initial_capital'] = 'initial_capital must be a positive number.';
        }

        if (!empty($errors)) {
            $this->error('Validation failed.', 422, $errors);
        }

        $isPaperTrading  = (bool) ($body['is_paper_trading'] ?? true);
        $benchmarkSymbol = trim((string) ($body['benchmark_symbol'] ?? 'SPY'));
        $description     = trim((string) ($body['description'] ?? ''));

        try {
            $portfolioId = $this->db->insert('portfolios', [
                'user_id'          => $userId,
                'name'             => $name,
                'initial_capital'  => $initialCapital,
                'current_value'    => $initialCapital,
                'is_paper_trading' => $isPaperTrading,
                'benchmark_symbol' => $benchmarkSymbol !== '' ? $benchmarkSymbol : 'SPY',
                'description'      => $description !== '' ? $description : null,
                'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $portfolio = $this->db->fetchOne(
                'SELECT * FROM portfolios WHERE id = :id',
                ['id' => $portfolioId],
            );

            $this->success($portfolio, 'Portfolio created.', 201);
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['user_id' => $userId]);
            $this->error('Failed to create portfolio: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/portfolio/{id} ───────────────────────────────────────────

    public function get(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);

        try {
            $positions = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        pp.id,
                        pp.quantity,
                        pp.avg_cost_basis,
                        pp.current_price,
                        pp.unrealized_pnl,
                        pp.sector,
                        a.id          AS asset_id,
                        a.symbol,
                        a.name,
                        a.exchange,
                        a.asset_type
                    FROM portfolio_positions pp
                    JOIN assets a ON a.id = pp.asset_id
                    WHERE pp.portfolio_id = :portfolio_id
                    ORDER BY a.symbol
                SQL,
                ['portfolio_id' => $portfolio['id']],
            );

            $portfolio['positions'] = $positions;

            $this->success($portfolio, 'Portfolio retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Failed to retrieve portfolio: ' . $e->getMessage(), 500);
        }
    }

    // ─── PUT /api/v1/portfolio/{id} ───────────────────────────────────────────

    public function update(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);
        $body      = $req->getBody();

        $allowedFields = ['name', 'benchmark_symbol', 'description'];
        $data          = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = is_string($body[$field]) ? trim($body[$field]) : $body[$field];
            }
        }

        if (empty($data)) {
            $this->error('No updatable fields provided. Allowed: ' . implode(', ', $allowedFields) . '.', 400);
        }

        if (isset($data['name']) && $data['name'] === '') {
            $this->error('Portfolio name cannot be empty.', 422);
        }

        try {
            $this->db->update('portfolios', $data, ['id' => $portfolio['id']]);

            $updated = $this->db->fetchOne(
                'SELECT * FROM portfolios WHERE id = :id',
                ['id' => $portfolio['id']],
            );

            $this->success($updated, 'Portfolio updated.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Failed to update portfolio: ' . $e->getMessage(), 500);
        }
    }

    // ─── DELETE /api/v1/portfolio/{id} ────────────────────────────────────────

    public function delete(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);

        try {
            $this->db->delete('portfolios', ['id' => $portfolio['id']]);
            http_response_code(204);
            exit;
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Failed to delete portfolio: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/portfolio/{id}/positions ─────────────────────────────────

    public function getPositions(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);

        try {
            $positions = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        pp.id,
                        a.id                                                      AS asset_id,
                        a.symbol,
                        a.name,
                        a.exchange,
                        a.asset_type,
                        pp.quantity,
                        pp.avg_cost_basis,
                        COALESCE(mdd.close_price, pp.current_price)               AS current_price,
                        pp.unrealized_pnl,
                        CASE
                            WHEN pp.avg_cost_basis <> 0
                            THEN ROUND(
                                ((COALESCE(mdd.close_price, pp.current_price) - pp.avg_cost_basis)
                                / pp.avg_cost_basis) * 100,
                                4
                            )
                            ELSE 0
                        END                                                       AS unrealized_pnl_percent,
                        pp.sector
                    FROM portfolio_positions pp
                    JOIN assets a ON a.id = pp.asset_id
                    LEFT JOIN LATERAL (
                        SELECT close_price
                        FROM   market_data_daily d
                        WHERE  d.asset_id   = pp.asset_id
                        ORDER  BY d.trade_date DESC
                        LIMIT  1
                    ) mdd ON true
                    WHERE pp.portfolio_id = :portfolio_id
                    ORDER BY a.symbol
                SQL,
                ['portfolio_id' => $portfolio['id']],
            );

            $this->success($positions, 'Positions retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Failed to retrieve positions: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/portfolio/{id}/performance ────────────────────────────────

    public function getPerformance(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);

        try {
            $snapshots = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        snapshot_date,
                        total_value,
                        daily_pnl,
                        daily_return_percent,
                        total_return_percent
                    FROM portfolio_snapshots
                    WHERE portfolio_id = :portfolio_id
                    ORDER BY snapshot_date ASC
                SQL,
                ['portfolio_id' => $portfolio['id']],
            );

            if (empty($snapshots)) {
                $this->success([
                    'total_return_percent' => 0.0,
                    'annualized_return'    => 0.0,
                    'sharpe_ratio'         => null,
                    'max_drawdown_percent' => 0.0,
                    'daily_returns'        => [],
                    'equity_curve'         => [],
                    'vs_benchmark'         => null,
                ], 'No performance data yet.');
            }

            $dailyReturns = array_column($snapshots, 'daily_return_percent');
            $equityCurve  = array_map(
                static fn($s) => ['date' => $s['snapshot_date'], 'value' => (float) $s['total_value']],
                $snapshots,
            );

            $totalReturnPercent = (float) (end($snapshots)['total_return_percent'] ?? 0);

            // Annualised return from total return and number of trading days.
            $tradingDays       = count($snapshots);
            $annualizedReturn  = $tradingDays > 0
                ? (((1 + $totalReturnPercent / 100) ** (252 / $tradingDays)) - 1) * 100
                : 0.0;

            // Sharpe ratio (risk-free rate ≈ 0 for simplicity; annualised).
            $sharpeRatio = $this->calculateSharpeRatio($dailyReturns);

            // Max drawdown from equity curve.
            $maxDrawdown = $this->calculateMaxDrawdown(array_column($equityCurve, 'value'));

            // Benchmark comparison if set.
            $vsBenchmark = null;
            $benchmarkSymbol = (string) ($portfolio['benchmark_symbol'] ?? '');

            if ($benchmarkSymbol !== '' && count($snapshots) >= 2) {
                $firstDate = $snapshots[0]['snapshot_date'];
                $lastDate  = end($snapshots)['snapshot_date'];

                $benchAsset = $this->db->fetchOne(
                    'SELECT id FROM assets WHERE symbol = :symbol LIMIT 1',
                    ['symbol' => $benchmarkSymbol],
                );

                if ($benchAsset !== null) {
                    $benchSnapshots = $this->db->fetchAll(
                        <<<'SQL'
                            SELECT trade_date, close_price
                            FROM   market_data_daily
                            WHERE  asset_id   = :asset_id
                              AND  trade_date BETWEEN :start AND :end
                            ORDER  BY trade_date ASC
                        SQL,
                        [
                            'asset_id' => $benchAsset['id'],
                            'start'    => $firstDate,
                            'end'      => $lastDate,
                        ],
                    );

                    if (count($benchSnapshots) >= 2) {
                        $benchStart      = (float) $benchSnapshots[0]['close_price'];
                        $benchEnd        = (float) end($benchSnapshots)['close_price'];
                        $benchReturn     = $benchStart > 0
                            ? (($benchEnd - $benchStart) / $benchStart) * 100
                            : 0.0;

                        $vsBenchmark = [
                            'symbol'         => $benchmarkSymbol,
                            'return_percent' => round($benchReturn, 4),
                            'alpha'          => round($totalReturnPercent - $benchReturn, 4),
                        ];
                    }
                }
            }

            $this->success([
                'total_return_percent' => round($totalReturnPercent, 4),
                'annualized_return'    => round($annualizedReturn, 4),
                'sharpe_ratio'         => $sharpeRatio !== null ? round($sharpeRatio, 4) : null,
                'max_drawdown_percent' => round($maxDrawdown, 4),
                'daily_returns'        => $dailyReturns,
                'equity_curve'         => $equityCurve,
                'vs_benchmark'         => $vsBenchmark,
            ], 'Performance retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Failed to retrieve performance: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/portfolio/{id}/risk ──────────────────────────────────────

    public function getRisk(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);

        try {
            // Check for a recent risk_metrics entry (< 24 h old).
            $riskRow = $this->db->fetchOne(
                <<<'SQL'
                    SELECT *
                    FROM   risk_metrics
                    WHERE  portfolio_id = :portfolio_id
                      AND  calculated_at >= NOW() - INTERVAL '24 hours'
                    ORDER  BY calculated_at DESC
                    LIMIT  1
                SQL,
                ['portfolio_id' => $portfolio['id']],
            );

            if ($riskRow !== null) {
                $this->success($riskRow, 'Risk metrics retrieved.');
            }

            // No recent entry — compute from daily returns and positions.
            $snapshots = $this->db->fetchAll(
                <<<'SQL'
                    SELECT daily_return_percent
                    FROM   portfolio_snapshots
                    WHERE  portfolio_id = :portfolio_id
                    ORDER  BY snapshot_date ASC
                SQL,
                ['portfolio_id' => $portfolio['id']],
            );

            $returns = array_map(
                static fn($r) => (float) $r['daily_return_percent'],
                $snapshots,
            );

            $var95  = $this->calculateVaR($returns, 0.05);
            $var99  = $this->calculateVaR($returns, 0.01);
            $cvar95 = $this->calculateCVaR($returns, 0.05);

            // Concentration risk: largest single position as % of portfolio.
            $positions = $this->db->fetchAll(
                <<<'SQL'
                    SELECT pp.quantity * COALESCE(mdd.close_price, pp.current_price) AS position_value
                    FROM   portfolio_positions pp
                    LEFT JOIN LATERAL (
                        SELECT close_price
                        FROM   market_data_daily d
                        WHERE  d.asset_id = pp.asset_id
                        ORDER  BY d.trade_date DESC
                        LIMIT  1
                    ) mdd ON true
                    WHERE  pp.portfolio_id = :portfolio_id
                SQL,
                ['portfolio_id' => $portfolio['id']],
            );

            $positionValues       = array_column($positions, 'position_value');
            $totalPositionValue   = array_sum($positionValues);
            $concentrationRisk    = $totalPositionValue > 0
                ? (max($positionValues) / $totalPositionValue) * 100
                : 0.0;

            // Annualised volatility.
            $volatility = $this->calculateVolatility($returns);

            $metrics = [
                'portfolio_id'       => $portfolio['id'],
                'var_95'             => $var95 !== null  ? round($var95,  4) : null,
                'var_99'             => $var99 !== null  ? round($var99,  4) : null,
                'cvar_95'            => $cvar95 !== null ? round($cvar95, 4) : null,
                'beta'               => null, // Requires benchmark time-series correlation
                'volatility'         => $volatility !== null ? round($volatility, 4) : null,
                'concentration_risk' => round($concentrationRisk, 2),
                'calculated_at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];

            $this->success($metrics, 'Risk metrics calculated.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Failed to retrieve risk metrics: ' . $e->getMessage(), 500);
        }
    }

    // ─── POST /api/v1/portfolio/{id}/trade ────────────────────────────────────

    public function executeTrade(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);
        $body      = $req->getBody();

        // --- Validation ---
        $errors = [];

        $symbol    = strtoupper(trim((string) ($body['symbol'] ?? '')));
        $tradeType = strtolower(trim((string) ($body['trade_type'] ?? '')));
        $quantity  = isset($body['quantity']) ? (float) $body['quantity'] : null;
        $price     = isset($body['price'])    ? (float) $body['price']    : null;
        $signalId  = isset($body['signal_id']) ? (string) $body['signal_id'] : null;
        $notes     = trim((string) ($body['notes'] ?? ''));

        if ($symbol === '') {
            $errors['symbol'] = 'Symbol is required.';
        }

        if (!in_array($tradeType, ['buy', 'sell'], true)) {
            $errors['trade_type'] = 'trade_type must be "buy" or "sell".';
        }

        if ($quantity === null || $quantity <= 0) {
            $errors['quantity'] = 'quantity must be a positive number.';
        }

        if (!empty($errors)) {
            $this->error('Validation failed.', 422, $errors);
        }

        // Resolve asset.
        try {
            $asset = $this->db->fetchOne(
                'SELECT id, symbol, name FROM assets WHERE symbol = :symbol LIMIT 1',
                ['symbol' => $symbol],
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Database error resolving asset.', 500);
        }

        if ($asset === null) {
            $this->error("Asset '{$symbol}' not found.", 404);
        }

        // Use latest market price if not provided.
        if ($price === null || $price <= 0) {
            try {
                $latestPrice = $this->db->fetchOne(
                    <<<'SQL'
                        SELECT close_price
                        FROM   market_data_daily
                        WHERE  asset_id = :asset_id
                        ORDER  BY trade_date DESC
                        LIMIT  1
                    SQL,
                    ['asset_id' => $asset['id']],
                );
                $price = $latestPrice !== null ? (float) $latestPrice['close_price'] : null;
            } catch (\Throwable $e) {
                $this->logger->exception($e);
            }
        }

        if ($price === null || $price <= 0) {
            $this->error('Could not determine price. Please provide a price.', 422);
        }

        // For sell trades, verify sufficient position.
        if ($tradeType === 'sell') {
            try {
                $position = $this->db->fetchOne(
                    'SELECT id, quantity FROM portfolio_positions WHERE portfolio_id = :pid AND asset_id = :aid LIMIT 1',
                    ['pid' => $portfolio['id'], 'aid' => $asset['id']],
                );
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                $this->error('Database error checking position.', 500);
            }

            if ($position === null) {
                $this->error("No position found for '{$symbol}'.", 422);
            }

            if ((float) $position['quantity'] < $quantity) {
                $this->error(
                    "Insufficient quantity. Available: {$position['quantity']}, requested: {$quantity}.",
                    422,
                );
            }
        }

        // --- Execute within a transaction ---
        $this->db->beginTransaction();

        try {
            $commission  = $price * $quantity * 0.001; // 0.1% default commission
            $totalValue  = $price * $quantity;
            $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $tradeId = $this->db->insert('trades', [
                'portfolio_id' => $portfolio['id'],
                'asset_id'     => $asset['id'],
                'signal_id'    => $signalId,
                'trade_type'   => $tradeType,
                'quantity'     => $quantity,
                'price'        => $price,
                'total_value'  => $totalValue,
                'commission'   => $commission,
                'notes'        => $notes !== '' ? $notes : null,
                'created_at'   => $now,
            ]);

            // Upsert portfolio_positions.
            $existingPosition = $this->db->fetchOne(
                'SELECT id, quantity, avg_cost_basis FROM portfolio_positions WHERE portfolio_id = :pid AND asset_id = :aid LIMIT 1',
                ['pid' => $portfolio['id'], 'aid' => $asset['id']],
            );

            if ($tradeType === 'buy') {
                if ($existingPosition !== null) {
                    $existingQty      = (float) $existingPosition['quantity'];
                    $existingAvgCost  = (float) $existingPosition['avg_cost_basis'];
                    $newQty           = $existingQty + $quantity;
                    $newAvgCost       = (($existingQty * $existingAvgCost) + ($quantity * $price)) / $newQty;
                    $unrealizedPnl    = ($price - $newAvgCost) * $newQty;

                    $this->db->update(
                        'portfolio_positions',
                        [
                            'quantity'       => $newQty,
                            'avg_cost_basis' => round($newAvgCost, 6),
                            'current_price'  => $price,
                            'unrealized_pnl' => round($unrealizedPnl, 4),
                        ],
                        ['id' => $existingPosition['id']],
                    );
                } else {
                    $this->db->insert('portfolio_positions', [
                        'portfolio_id'   => $portfolio['id'],
                        'asset_id'       => $asset['id'],
                        'quantity'       => $quantity,
                        'avg_cost_basis' => $price,
                        'current_price'  => $price,
                        'unrealized_pnl' => 0,
                        'sector'         => null,
                    ]);
                }
            } else {
                // Sell — reduce position.
                $remainingQty  = (float) $existingPosition['quantity'] - $quantity;
                $avgCost       = (float) $existingPosition['avg_cost_basis'];
                $unrealizedPnl = ($price - $avgCost) * $remainingQty;

                if ($remainingQty <= 0) {
                    $this->db->delete('portfolio_positions', ['id' => $existingPosition['id']]);
                } else {
                    $this->db->update(
                        'portfolio_positions',
                        [
                            'quantity'       => $remainingQty,
                            'current_price'  => $price,
                            'unrealized_pnl' => round($unrealizedPnl, 4),
                        ],
                        ['id' => $existingPosition['id']],
                    );
                }
            }

            // Recalculate portfolio current_value from all positions.
            $valueRow = $this->db->fetchOne(
                <<<'SQL'
                    SELECT COALESCE(SUM(quantity * current_price), 0) AS total
                    FROM   portfolio_positions
                    WHERE  portfolio_id = :portfolio_id
                SQL,
                ['portfolio_id' => $portfolio['id']],
            );

            $this->db->update(
                'portfolios',
                ['current_value' => (float) ($valueRow['total'] ?? 0)],
                ['id' => $portfolio['id']],
            );

            $this->db->commit();

            $trade = $this->db->fetchOne(
                'SELECT * FROM trades WHERE id = :id',
                ['id' => $tradeId],
            );

            $this->success($trade, 'Trade executed successfully.', 201);
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Trade execution failed: ' . $e->getMessage(), 500);
        }
    }

    // ─── GET /api/v1/portfolio/{id}/trades ────────────────────────────────────

    public function getTrades(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $portfolio = $this->requirePortfolioOwnership($req, $userId);
        $pagination = $req->getPaginationParams();

        $symbol    = strtoupper(trim((string) $req->getQuery('symbol', '')));
        $tradeType = strtolower(trim((string) $req->getQuery('trade_type', '')));
        $dateFrom  = trim((string) $req->getQuery('date_from', ''));
        $dateTo    = trim((string) $req->getQuery('date_to', ''));

        $where  = ['t.portfolio_id = :portfolio_id'];
        $params = ['portfolio_id' => $portfolio['id']];

        if ($symbol !== '') {
            $where[]          = 'a.symbol = :symbol';
            $params['symbol'] = $symbol;
        }

        if (in_array($tradeType, ['buy', 'sell'], true)) {
            $where[]              = 't.trade_type = :trade_type';
            $params['trade_type'] = $tradeType;
        }

        if ($dateFrom !== '') {
            $where[]             = 't.created_at >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo !== '') {
            $where[]           = 't.created_at <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $whereClause = implode(' AND ', $where);

        try {
            $totalRow = $this->db->fetchOne(
                "SELECT COUNT(*) AS total FROM trades t JOIN assets a ON a.id = t.asset_id WHERE {$whereClause}",
                $params,
            );
            $total = (int) ($totalRow['total'] ?? 0);

            $params['limit']  = $pagination['per_page'];
            $params['offset'] = $pagination['offset'];

            $trades = $this->db->fetchAll(
                <<<SQL
                    SELECT
                        t.id,
                        t.trade_type,
                        t.quantity,
                        t.price,
                        t.total_value,
                        t.commission,
                        t.notes,
                        t.created_at,
                        t.signal_id,
                        a.id     AS asset_id,
                        a.symbol,
                        a.name,
                        a.exchange
                    FROM trades t
                    JOIN assets a ON a.id = t.asset_id
                    WHERE {$whereClause}
                    ORDER BY t.created_at DESC
                    LIMIT :limit OFFSET :offset
                SQL,
                $params,
            );

            $this->success([
                'trades'     => $trades,
                'pagination' => [
                    'total'    => $total,
                    'page'     => $pagination['page'],
                    'per_page' => $pagination['per_page'],
                    'pages'    => (int) ceil($total / $pagination['per_page']),
                ],
            ], 'Trades retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['portfolio_id' => $portfolio['id']]);
            $this->error('Failed to retrieve trades: ' . $e->getMessage(), 500);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Return the authenticated user's ID or terminate with 401.
     */
    private function requireUserId(Request $req): string
    {
        $user = $req->getUser();
        if ($user === null || empty($user['id'])) {
            $this->error('Authentication required.', 401);
        }
        return (string) $user['id'];
    }

    /**
     * Fetch a portfolio row and verify it belongs to the given user.
     * Terminates with 404 if not found or 403 if ownership check fails.
     *
     * @return array<string, mixed>
     */
    private function requirePortfolioOwnership(Request $req, string $userId): array
    {
        $portfolioId = trim((string) $req->getParam('id', ''));

        if ($portfolioId === '') {
            $this->error('Portfolio ID is required.', 400);
        }

        try {
            $portfolio = $this->db->fetchOne(
                'SELECT * FROM portfolios WHERE id = :id LIMIT 1',
                ['id' => $portfolioId],
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Database error.', 500);
        }

        if ($portfolio === null) {
            $this->error('Portfolio not found.', 404);
        }

        if ((string) $portfolio['user_id'] !== $userId) {
            $this->error('You do not have access to this portfolio.', 403);
        }

        return $portfolio;
    }

    /**
     * Annualised Sharpe ratio (risk-free rate = 0).
     * Returns null when there are fewer than 2 data points.
     *
     * @param float[] $dailyReturns
     */
    private function calculateSharpeRatio(array $dailyReturns): ?float
    {
        $n = count($dailyReturns);
        if ($n < 2) {
            return null;
        }

        $mean = array_sum($dailyReturns) / $n;
        $variance = array_sum(array_map(static fn($r) => ($r - $mean) ** 2, $dailyReturns)) / ($n - 1);
        $stdDev   = sqrt($variance);

        if ($stdDev == 0) {
            return null;
        }

        return ($mean / $stdDev) * sqrt(252);
    }

    /**
     * Maximum drawdown (as a positive percentage) from an equity curve.
     *
     * @param float[] $values
     */
    private function calculateMaxDrawdown(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $peak        = $values[0];
        $maxDrawdown = 0.0;

        foreach ($values as $v) {
            if ($v > $peak) {
                $peak = $v;
            }
            if ($peak > 0) {
                $drawdown    = (($peak - $v) / $peak) * 100;
                $maxDrawdown = max($maxDrawdown, $drawdown);
            }
        }

        return $maxDrawdown;
    }

    /**
     * Historical VaR at the given confidence level (e.g. 0.05 = 95% VaR).
     *
     * @param float[] $returns
     */
    private function calculateVaR(array $returns, float $alpha): ?float
    {
        if (count($returns) < 10) {
            return null;
        }

        sort($returns);
        $index = (int) floor($alpha * count($returns));
        return $returns[$index] ?? null;
    }

    /**
     * Conditional VaR (Expected Shortfall) at the given confidence level.
     *
     * @param float[] $returns
     */
    private function calculateCVaR(array $returns, float $alpha): ?float
    {
        if (count($returns) < 10) {
            return null;
        }

        sort($returns);
        $cutoff = (int) floor($alpha * count($returns));
        $tail   = array_slice($returns, 0, max(1, $cutoff));

        return array_sum($tail) / count($tail);
    }

    /**
     * Annualised volatility (standard deviation of daily returns × √252).
     *
     * @param float[] $dailyReturns
     */
    private function calculateVolatility(array $dailyReturns): ?float
    {
        $n = count($dailyReturns);
        if ($n < 2) {
            return null;
        }

        $mean     = array_sum($dailyReturns) / $n;
        $variance = array_sum(array_map(static fn($r) => ($r - $mean) ** 2, $dailyReturns)) / ($n - 1);

        return sqrt($variance) * sqrt(252);
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
