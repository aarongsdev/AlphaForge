<?php

declare(strict_types=1);

namespace AlphaForge\Models;

use AlphaForge\Core\Database;
use AlphaForge\Core\Cache;

/**
 * Signal model — BUY/SELL/HOLD predictions from the AI engine.
 */
class Signal
{
    private Database $db;
    private Cache $cache;

    public function __construct()
    {
        $this->db    = Database::getInstance();
        $this->cache = Cache::getInstance();
    }

    public function findById(string $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT s.*, a.symbol, a.name, a.asset_type
             FROM signals s
             JOIN assets a ON a.id = s.asset_id
             WHERE s.id = :id',
            [':id' => $id]
        );
        if ($row) {
            $row['key_factors']  = json_decode((string) ($row['key_factors']  ?? '[]'), true);
            $row['risk_factors'] = json_decode((string) ($row['risk_factors'] ?? '[]'), true);
        }
        return $row;
    }

    public function getLatestForAsset(string $assetId, int $limit = 5): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM signals
             WHERE asset_id = :id AND is_active = true
             ORDER BY generated_at DESC
             LIMIT :limit',
            [':id' => $assetId, ':limit' => $limit]
        );
        return array_map([$this, 'decodeJson'], $rows);
    }

    public function getActive(
        string $signalType = '',
        string $timeframe  = '',
        string $assetType  = '',
        string $sortBy     = 'confidence_score',
        int    $limit      = 50,
        int    $offset     = 0
    ): array {
        $sql    = "SELECT s.*, a.symbol, a.name, a.asset_type, a.sector
                   FROM signals s
                   JOIN assets a ON a.id = s.asset_id
                   WHERE s.is_active = true
                     AND (s.expires_at IS NULL OR s.expires_at > NOW())";
        $params = [];

        if ($signalType !== '') {
            $sql                    .= ' AND s.signal_type = :signal_type';
            $params[':signal_type'] = $signalType;
        }
        if ($timeframe !== '') {
            $sql                 .= ' AND s.timeframe = :timeframe';
            $params[':timeframe'] = $timeframe;
        }
        if ($assetType !== '') {
            $sql                  .= ' AND a.asset_type = :asset_type';
            $params[':asset_type'] = $assetType;
        }

        $allowedSorts = ['confidence_score', 'composite_score', 'generated_at', 'risk_reward_ratio'];
        $sort         = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'confidence_score';
        $sql          .= " ORDER BY s.{$sort} DESC LIMIT :limit OFFSET :offset";
        $params[':limit']  = $limit;
        $params[':offset'] = $offset;

        return array_map([$this, 'decodeJson'], $this->db->fetchAll($sql, $params));
    }

    public function countActive(string $signalType = '', string $date = ''): int
    {
        $sql    = "SELECT COUNT(*) as cnt FROM signals WHERE is_active = true";
        $params = [];
        if ($signalType !== '') {
            $sql .= ' AND signal_type = :st';
            $params[':st'] = $signalType;
        }
        if ($date !== '') {
            $sql .= ' AND DATE(generated_at) = :dt';
            $params[':dt'] = $date;
        }
        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['cnt'] ?? 0);
    }

    public function create(array $data): string
    {
        $id = Database::uuid();
        $this->db->insert('signals', array_merge($data, [
            'id'           => $id,
            'is_active'    => true,
            'status'       => 'active',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'key_factors'  => isset($data['key_factors'])  ? json_encode($data['key_factors'])  : '[]',
            'risk_factors' => isset($data['risk_factors']) ? json_encode($data['risk_factors']) : '[]',
        ]));
        $this->cache->flush("signals:{$data['asset_id']}");
        return $id;
    }

    public function getPerformanceStats(int $days = 90): array
    {
        return $this->db->fetchOne(
            "SELECT
                COUNT(sp.id) as total_signals,
                SUM(CASE WHEN sp.outcome = 'win' THEN 1 ELSE 0 END) as wins,
                ROUND(AVG(sp.actual_return_percent), 4) as avg_return,
                ROUND(MAX(sp.actual_return_percent), 4) as best_return,
                ROUND(MIN(sp.actual_return_percent), 4) as worst_return,
                ROUND(AVG(sp.days_held), 1) as avg_days_held,
                ROUND(AVG(CASE WHEN sp.outcome = 'win' THEN sp.actual_return_percent END), 4) as avg_win,
                ROUND(AVG(CASE WHEN sp.outcome = 'loss' THEN sp.actual_return_percent END), 4) as avg_loss
             FROM signal_performance sp
             JOIN signals s ON s.id = sp.signal_id
             WHERE sp.closed_at >= NOW() - INTERVAL ':days days'",
            [':days' => $days]
        ) ?? [];
    }

    public function deactivateExpired(): int
    {
        return $this->db->update(
            'signals',
            ['is_active' => false, 'status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')],
            [] // WHERE clause handled inline
        );
    }

    private function decodeJson(array $row): array
    {
        $row['key_factors']  = json_decode((string) ($row['key_factors']  ?? '[]'), true);
        $row['risk_factors'] = json_decode((string) ($row['risk_factors'] ?? '[]'), true);
        return $row;
    }
}
