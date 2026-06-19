<?php

declare(strict_types=1);

namespace AlphaForge\Models;

use AlphaForge\Core\Database;

/**
 * Portfolio model — paper and live trading portfolios.
 */
class Portfolio
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM portfolios WHERE id = :id',
            [':id' => $id]
        );
    }

    public function findByUser(string $userId): array
    {
        return $this->db->fetchAll(
            'SELECT p.*,
                    (SELECT SUM(pp.market_value) FROM portfolio_positions pp WHERE pp.portfolio_id = p.id) as positions_value,
                    (SELECT SUM(pp.unrealized_pnl) FROM portfolio_positions pp WHERE pp.portfolio_id = p.id) as unrealized_pnl
             FROM portfolios p
             WHERE p.user_id = :uid
             ORDER BY p.is_default DESC, p.created_at ASC',
            [':uid' => $userId]
        );
    }

    public function getDefaultForUser(string $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM portfolios WHERE user_id = :uid AND is_default = true LIMIT 1',
            [':uid' => $userId]
        );
    }

    public function create(array $data): string
    {
        $id = Database::uuid();
        // If this is the first portfolio, make it default
        $existing = $this->findByUser($data['user_id']);
        $isDefault = empty($existing);

        $this->db->insert('portfolios', array_merge($data, [
            'id'               => $id,
            'is_default'       => $isDefault,
            'is_paper_trading' => $data['is_paper_trading'] ?? true,
            'currency'         => $data['currency'] ?? 'USD',
            'benchmark_symbol' => $data['benchmark_symbol'] ?? 'SPY',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]));
        return $id;
    }

    public function update(string $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('portfolios', $data, ['id' => $id]);
    }

    public function delete(string $id, string $userId): int
    {
        // Prevent deleting if it's the only portfolio
        $all = $this->db->fetchAll(
            'SELECT id FROM portfolios WHERE user_id = :uid',
            [':uid' => $userId]
        );
        if (count($all) <= 1) {
            throw new \RuntimeException('Cannot delete the only portfolio.');
        }
        return $this->db->delete('portfolios', ['id' => $id, 'user_id' => $userId]);
    }

    public function getPositions(string $portfolioId): array
    {
        return $this->db->fetchAll(
            'SELECT pp.*, a.symbol, a.name, a.asset_type, a.sector
             FROM portfolio_positions pp
             JOIN assets a ON a.id = pp.asset_id
             WHERE pp.portfolio_id = :pid
             ORDER BY pp.market_value DESC',
            [':pid' => $portfolioId]
        );
    }

    public function upsertPosition(string $portfolioId, string $assetId, array $data): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM portfolio_positions WHERE portfolio_id = :pid AND asset_id = :aid',
            [':pid' => $portfolioId, ':aid' => $assetId]
        );

        if ($existing) {
            $this->db->update('portfolio_positions', array_merge($data, ['last_updated' => date('Y-m-d H:i:s')]),
                ['portfolio_id' => $portfolioId, 'asset_id' => $assetId]);
        } else {
            $this->db->insert('portfolio_positions', array_merge($data, [
                'id'                  => Database::uuid(),
                'portfolio_id'        => $portfolioId,
                'asset_id'            => $assetId,
                'first_purchase_date' => date('Y-m-d'),
                'last_updated'        => date('Y-m-d H:i:s'),
                'created_at'          => date('Y-m-d H:i:s'),
            ]));
        }
    }

    public function getTrades(string $portfolioId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT t.*, a.symbol, a.name, a.asset_type
             FROM trades t
             JOIN assets a ON a.id = t.asset_id
             WHERE t.portfolio_id = :pid
             ORDER BY t.executed_at DESC
             LIMIT :limit OFFSET :offset',
            [':pid' => $portfolioId, ':limit' => $limit, ':offset' => $offset]
        );
    }

    public function recordTrade(string $portfolioId, array $data): string
    {
        $id = Database::uuid();
        $this->db->insert('trades', array_merge($data, [
            'id'           => $id,
            'portfolio_id' => $portfolioId,
            'executed_at'  => $data['executed_at'] ?? date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
        ]));
        return $id;
    }

    public function getSnapshots(string $portfolioId, int $days = 90): array
    {
        // PostgreSQL does not accept a bound parameter inside an INTERVAL literal.
        // $days is typed int, so interpolation is safe against SQL injection.
        return $this->db->fetchAll(
            "SELECT * FROM portfolio_snapshots
             WHERE portfolio_id = :pid
               AND snapshot_date >= NOW() - INTERVAL '{$days} days'
             ORDER BY snapshot_date ASC",
            [':pid' => $portfolioId]
        );
    }

    public function saveSnapshot(string $portfolioId, array $data): void
    {
        $this->db->query(
            'INSERT INTO portfolio_snapshots
             (id, portfolio_id, snapshot_date, total_value, cash_balance, positions_value,
              daily_pnl, daily_pnl_percent, total_return, total_return_percent, created_at)
             VALUES (:id, :pid, :date, :tv, :cash, :pv, :dpnl, :dpct, :tr, :trpct, NOW())
             ON CONFLICT (portfolio_id, snapshot_date) DO UPDATE
             SET total_value = EXCLUDED.total_value,
                 daily_pnl   = EXCLUDED.daily_pnl,
                 total_return = EXCLUDED.total_return',
            array_merge([':id' => Database::uuid(), ':pid' => $portfolioId], $data)
        );
    }

    public function getPerformanceMetrics(string $portfolioId): array
    {
        $snapshots = $this->getSnapshots($portfolioId, 365);
        if (empty($snapshots)) {
            return ['total_return' => 0, 'sharpe_ratio' => 0, 'max_drawdown' => 0];
        }

        $returns   = [];
        $peak      = 0;
        $maxDD     = 0;
        $prevValue = null;

        foreach ($snapshots as $snap) {
            $val = (float) $snap['total_value'];
            if ($prevValue !== null && $prevValue > 0) {
                $returns[] = ($val - $prevValue) / $prevValue;
            }
            $peak  = max($peak, $val);
            $dd    = $peak > 0 ? ($peak - $val) / $peak : 0;
            $maxDD = max($maxDD, $dd);
            $prevValue = $val;
        }

        $avgReturn = !empty($returns) ? array_sum($returns) / count($returns) : 0;
        $variance  = 0;
        foreach ($returns as $r) {
            $variance += ($r - $avgReturn) ** 2;
        }
        $stdDev = count($returns) > 1 ? sqrt($variance / (count($returns) - 1)) : 0;
        $sharpe = $stdDev > 0 ? (($avgReturn * 252) - 0.05) / ($stdDev * sqrt(252)) : 0;

        $first = (float) $snapshots[0]['total_value'];
        $last  = (float) end($snapshots)['total_value'];
        $totalReturn = $first > 0 ? ($last - $first) / $first : 0;

        return [
            'total_return'    => round($totalReturn * 100, 2),
            'sharpe_ratio'    => round($sharpe, 3),
            'max_drawdown'    => round($maxDD * 100, 2),
            'volatility'      => round($stdDev * sqrt(252) * 100, 2),
            'total_snapshots' => count($snapshots),
        ];
    }
}
