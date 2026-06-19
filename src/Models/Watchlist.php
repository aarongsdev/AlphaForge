<?php

declare(strict_types=1);

namespace AlphaForge\Models;

use AlphaForge\Core\Database;

/**
 * Watchlist model.
 */
class Watchlist
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM watchlists WHERE id = :id',
            [':id' => $id]
        );
    }

    public function findByUser(string $userId): array
    {
        return $this->db->fetchAll(
            'SELECT w.*,
                    (SELECT COUNT(*) FROM watchlist_items wi WHERE wi.watchlist_id = w.id) as item_count
             FROM watchlists w
             WHERE w.user_id = :uid
             ORDER BY w.is_default DESC, w.created_at ASC',
            [':uid' => $userId]
        );
    }

    public function getWithItems(string $watchlistId): ?array
    {
        $wl = $this->findById($watchlistId);
        if (!$wl) {
            return null;
        }
        $wl['items'] = $this->db->fetchAll(
            'SELECT wi.*, a.symbol, a.name, a.asset_type, a.sector, a.currency
             FROM watchlist_items wi
             JOIN assets a ON a.id = wi.asset_id
             WHERE wi.watchlist_id = :wid
             ORDER BY wi.added_at DESC',
            [':wid' => $watchlistId]
        );
        return $wl;
    }

    public function create(array $data): string
    {
        $id = Database::uuid();
        $existing = $this->findByUser($data['user_id']);
        $this->db->insert('watchlists', array_merge($data, [
            'id'         => $id,
            'is_default' => empty($existing),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
        return $id;
    }

    public function update(string $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('watchlists', $data, ['id' => $id]);
    }

    public function delete(string $id, string $userId): int
    {
        return $this->db->delete('watchlists', ['id' => $id, 'user_id' => $userId]);
    }

    public function addItem(string $watchlistId, string $assetId, array $extra = []): string
    {
        $id = Database::uuid();
        $this->db->query(
            'INSERT INTO watchlist_items (id, watchlist_id, asset_id, notes, price_alert_high, price_alert_low, added_at)
             VALUES (:id, :wid, :aid, :notes, :pah, :pal, NOW())
             ON CONFLICT (watchlist_id, asset_id) DO NOTHING',
            [
                ':id'    => $id,
                ':wid'   => $watchlistId,
                ':aid'   => $assetId,
                ':notes' => $extra['notes'] ?? null,
                ':pah'   => $extra['price_alert_high'] ?? null,
                ':pal'   => $extra['price_alert_low']  ?? null,
            ]
        );
        return $id;
    }

    public function removeItem(string $watchlistId, string $assetId): int
    {
        return $this->db->delete('watchlist_items', [
            'watchlist_id' => $watchlistId,
            'asset_id'     => $assetId,
        ]);
    }

    public function hasItem(string $watchlistId, string $assetId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM watchlist_items WHERE watchlist_id = :wid AND asset_id = :aid',
            [':wid' => $watchlistId, ':aid' => $assetId]
        );
        return $row !== null;
    }

    public function userOwns(string $watchlistId, string $userId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM watchlists WHERE id = :id AND user_id = :uid',
            [':id' => $watchlistId, ':uid' => $userId]
        );
        return $row !== null;
    }
}
