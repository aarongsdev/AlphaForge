<?php

declare(strict_types=1);

namespace AlphaForge\Models;

use AlphaForge\Core\Database;
use AlphaForge\Core\Cache;

/**
 * Asset model — stocks, ETFs, crypto, commodities, forex, futures, options, indices.
 */
class Asset
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
        return $this->cache->remember("asset:{$id}", 3600, function () use ($id) {
            return $this->db->fetchOne(
                'SELECT * FROM assets WHERE id = :id AND is_active = true',
                [':id' => $id]
            );
        });
    }

    public function findBySymbol(string $symbol, string $exchange = '', string $type = ''): ?array
    {
        $key = "asset:sym:{$symbol}:{$exchange}:{$type}";
        return $this->cache->remember($key, 3600, function () use ($symbol, $exchange, $type) {
            $sql    = 'SELECT * FROM assets WHERE UPPER(symbol) = UPPER(:symbol) AND is_active = true';
            $params = [':symbol' => $symbol];
            if ($exchange !== '') {
                $sql              .= ' AND exchange = :exchange';
                $params[':exchange'] = $exchange;
            }
            if ($type !== '') {
                $sql            .= ' AND asset_type = :type';
                $params[':type'] = $type;
            }
            $sql .= ' LIMIT 1';
            return $this->db->fetchOne($sql, $params);
        });
    }

    public function search(string $query, string $type = '', int $limit = 20): array
    {
        $sql = "SELECT id, symbol, name, exchange, asset_type, sector, currency
                FROM assets
                WHERE is_active = true
                  AND (UPPER(symbol) LIKE UPPER(:q1) OR name ILIKE :q2)";
        $params = [':q1' => strtoupper($query) . '%', ':q2' => '%' . $query . '%'];

        if ($type !== '') {
            $sql              .= ' AND asset_type = :type';
            $params[':type'] = $type;
        }

        $sql .= ' ORDER BY CASE WHEN UPPER(symbol) = UPPER(:q3) THEN 0
                                WHEN UPPER(symbol) LIKE UPPER(:q4) THEN 1 ELSE 2 END,
                           market_cap DESC NULLS LAST
                  LIMIT :limit';
        $params[':q3']    = $query;
        $params[':q4']    = $query . '%';
        $params[':limit'] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    public function getLatestQuote(string $assetId): ?array
    {
        return $this->db->fetchOne(
            'SELECT date, open, high, low, close, volume, adj_close, vwap
             FROM market_data_daily
             WHERE asset_id = :id
             ORDER BY date DESC
             LIMIT 1',
            [':id' => $assetId]
        );
    }

    public function getHistoricalData(string $assetId, string $interval = 'daily', int $periods = 200): array
    {
        $table = $interval === 'intraday' ? 'market_data_intraday' : 'market_data_daily';
        $dateCol = $interval === 'intraday' ? 'timestamp' : 'date';

        return $this->db->fetchAll(
            "SELECT {$dateCol} as ts, open, high, low, close, volume
             FROM {$table}
             WHERE asset_id = :id
             ORDER BY {$dateCol} DESC
             LIMIT :periods",
            [':id' => $assetId, ':periods' => $periods]
        );
    }

    public function getTopMovers(string $direction = 'gainers', int $limit = 10): array
    {
        $order = $direction === 'gainers' ? 'DESC' : 'ASC';
        return $this->db->fetchAll(
            "SELECT a.symbol, a.name, a.exchange, a.asset_type,
                    d.close as current_price,
                    ((d.close - d.open) / d.open * 100) as change_percent
             FROM assets a
             JOIN market_data_daily d ON d.asset_id = a.id
             WHERE a.is_active = true
               AND d.date = (SELECT MAX(date) FROM market_data_daily WHERE asset_id = a.id)
               AND d.open > 0
             ORDER BY change_percent {$order}
             LIMIT :limit",
            [':limit' => $limit]
        );
    }

    public function getMostActive(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT a.symbol, a.name, a.asset_type,
                    d.close, d.volume,
                    ((d.close - d.open) / d.open * 100) as change_percent
             FROM assets a
             JOIN market_data_daily d ON d.asset_id = a.id
             WHERE a.is_active = true
               AND d.date = (SELECT MAX(date) FROM market_data_daily WHERE asset_id = a.id)
             ORDER BY d.volume DESC
             LIMIT :limit",
            [':limit' => $limit]
        );
    }

    public function create(array $data): string
    {
        $id = $this->db->insert('assets', array_merge($data, [
            'id'         => \AlphaForge\Core\Database::uuid(),
            'is_active'  => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
        $this->cache->flush('asset:');
        return $id;
    }

    public function update(string $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $rows = $this->db->update('assets', $data, ['id' => $id]);
        $this->cache->delete("asset:{$id}");
        return $rows;
    }
}
