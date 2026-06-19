<?php

declare(strict_types=1);

namespace AlphaForge\Api\Controllers;

use AlphaForge\Api\Request;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;

/**
 * Handles all watchlist endpoints:
 *
 *   GET    /api/v1/watchlists
 *   POST   /api/v1/watchlists
 *   GET    /api/v1/watchlists/{id}
 *   PUT    /api/v1/watchlists/{id}
 *   DELETE /api/v1/watchlists/{id}
 *   POST   /api/v1/watchlists/{id}/items
 *   DELETE /api/v1/watchlists/{id}/items/{itemId}
 *   PUT    /api/v1/watchlists/{id}/items/{itemId}
 *
 * All operations are scoped to the authenticated user.
 */
final class WatchlistController
{
    private readonly Database $db;
    private readonly Logger   $logger;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    // ─── GET /api/v1/watchlists ───────────────────────────────────────────────

    public function list(Request $req): void
    {
        $userId = $this->requireUserId($req);

        try {
            $watchlists = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        w.id,
                        w.name,
                        w.is_default,
                        w.created_at,
                        COUNT(wi.id) AS item_count
                    FROM watchlists w
                    LEFT JOIN watchlist_items wi ON wi.watchlist_id = w.id
                    WHERE w.user_id = :user_id
                    GROUP BY w.id
                    ORDER BY w.is_default DESC, w.created_at ASC
                SQL,
                ['user_id' => $userId],
            );

            $this->success($watchlists, 'Watchlists retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['user_id' => $userId]);
            $this->error('Failed to retrieve watchlists', 500);
        }
    }

    // ─── POST /api/v1/watchlists ──────────────────────────────────────────────

    public function create(Request $req): void
    {
        $userId = $this->requireUserId($req);
        $body   = $req->getBody();

        $name      = trim((string) ($body['name'] ?? ''));
        $isDefault = (bool) ($body['is_default'] ?? false);

        if ($name === '') {
            $this->error('Watchlist name is required.', 422);
        }

        try {
            // If this watchlist should be the default, unset all others first.
            if ($isDefault) {
                $this->db->update(
                    'watchlists',
                    ['is_default' => false],
                    ['user_id' => $userId],
                );
            }

            $watchlistId = $this->db->insert('watchlists', [
                'user_id'    => $userId,
                'name'       => $name,
                'is_default' => $isDefault,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $watchlist = $this->db->fetchOne(
                'SELECT * FROM watchlists WHERE id = :id',
                ['id' => $watchlistId],
            );

            $this->success($watchlist, 'Watchlist created.', 201);
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['user_id' => $userId]);
            $this->error('Failed to create watchlist', 500);
        }
    }

    // ─── GET /api/v1/watchlists/{id} ─────────────────────────────────────────

    public function get(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $watchlist = $this->requireWatchlistOwnership($req, $userId);

        try {
            // Fetch items enriched with latest price and latest signal.
            $items = $this->db->fetchAll(
                <<<'SQL'
                    SELECT
                        wi.id            AS item_id,
                        wi.price_alert_high,
                        wi.price_alert_low,
                        wi.notes,
                        wi.added_at,
                        a.id             AS asset_id,
                        a.symbol,
                        a.name,
                        a.exchange,
                        a.asset_type,
                        a.sector,
                        mdd.close_price  AS latest_price,
                        mdd.trade_date   AS price_date,
                        ls.id            AS latest_signal_id,
                        ls.signal_type   AS latest_signal_type,
                        ls.confidence_score AS latest_signal_confidence,
                        ls.created_at    AS latest_signal_at
                    FROM watchlist_items wi
                    JOIN assets a ON a.id = wi.asset_id
                    LEFT JOIN LATERAL (
                        SELECT close_price, trade_date
                        FROM   market_data_daily d
                        WHERE  d.asset_id = wi.asset_id
                        ORDER  BY d.trade_date DESC
                        LIMIT  1
                    ) mdd ON true
                    LEFT JOIN LATERAL (
                        SELECT id, signal_type, confidence_score, created_at
                        FROM   signals s
                        WHERE  s.asset_id = wi.asset_id
                        ORDER  BY s.created_at DESC
                        LIMIT  1
                    ) ls ON true
                    WHERE wi.watchlist_id = :watchlist_id
                    ORDER BY wi.added_at DESC
                SQL,
                ['watchlist_id' => $watchlist['id']],
            );

            $watchlist['items'] = $items;

            $this->success($watchlist, 'Watchlist retrieved.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['watchlist_id' => $watchlist['id']]);
            $this->error('Failed to retrieve watchlist', 500);
        }
    }

    // ─── PUT /api/v1/watchlists/{id} ─────────────────────────────────────────

    public function update(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $watchlist = $this->requireWatchlistOwnership($req, $userId);
        $body      = $req->getBody();

        $data = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                $this->error('Watchlist name cannot be empty.', 422);
            }
            $data['name'] = $name;
        }

        if (array_key_exists('is_default', $body)) {
            $data['is_default'] = (bool) $body['is_default'];
        }

        if (empty($data)) {
            $this->error('No updatable fields provided. Allowed: name, is_default.', 400);
        }

        try {
            // If setting as default, clear existing default first.
            if (!empty($data['is_default'])) {
                $this->db->update(
                    'watchlists',
                    ['is_default' => false],
                    ['user_id' => $userId],
                );
            }

            $this->db->update('watchlists', $data, ['id' => $watchlist['id']]);

            $updated = $this->db->fetchOne(
                'SELECT * FROM watchlists WHERE id = :id',
                ['id' => $watchlist['id']],
            );

            $this->success($updated, 'Watchlist updated.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['watchlist_id' => $watchlist['id']]);
            $this->error('Failed to update watchlist', 500);
        }
    }

    // ─── DELETE /api/v1/watchlists/{id} ──────────────────────────────────────

    public function delete(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $watchlist = $this->requireWatchlistOwnership($req, $userId);

        try {
            // Prevent deleting the user's only watchlist.
            $countRow = $this->db->fetchOne(
                'SELECT COUNT(*) AS total FROM watchlists WHERE user_id = :user_id',
                ['user_id' => $userId],
            );

            if ((int) ($countRow['total'] ?? 1) <= 1) {
                $this->error('Cannot delete the only watchlist. Create another one first.', 409);
            }

            $this->db->delete('watchlists', ['id' => $watchlist['id']]);
            http_response_code(204);
            exit;
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['watchlist_id' => $watchlist['id']]);
            $this->error('Failed to delete watchlist', 500);
        }
    }

    // ─── POST /api/v1/watchlists/{id}/items ──────────────────────────────────

    public function addItem(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $watchlist = $this->requireWatchlistOwnership($req, $userId);
        $body      = $req->getBody();

        $symbol         = strtoupper(trim((string) ($body['symbol'] ?? '')));
        $priceAlertHigh = isset($body['price_alert_high']) ? (float) $body['price_alert_high'] : null;
        $priceAlertLow  = isset($body['price_alert_low'])  ? (float) $body['price_alert_low']  : null;
        $notes          = trim((string) ($body['notes'] ?? ''));

        if ($symbol === '') {
            $this->error('Symbol is required.', 422);
        }

        try {
            // Resolve or create the asset record.
            $asset = $this->db->fetchOne(
                'SELECT id, symbol, name, exchange, asset_type FROM assets WHERE symbol = :symbol LIMIT 1',
                ['symbol' => $symbol],
            );

            if ($asset === null) {
                // Create a minimal placeholder asset record.
                $assetId = $this->db->insert('assets', [
                    'symbol'     => $symbol,
                    'name'       => $symbol,
                    'exchange'   => null,
                    'asset_type' => 'equity',
                    'sector'     => null,
                ]);

                $asset = $this->db->fetchOne(
                    'SELECT id, symbol, name, exchange, asset_type FROM assets WHERE id = :id LIMIT 1',
                    ['id' => $assetId],
                );
            }

            // Check for duplicate item in this watchlist.
            $existing = $this->db->fetchOne(
                'SELECT id FROM watchlist_items WHERE watchlist_id = :wid AND asset_id = :aid LIMIT 1',
                ['wid' => $watchlist['id'], 'aid' => $asset['id']],
            );

            if ($existing !== null) {
                $this->error("'{$symbol}' is already in this watchlist.", 409);
            }

            $itemId = $this->db->insert('watchlist_items', [
                'watchlist_id'    => $watchlist['id'],
                'asset_id'        => $asset['id'],
                'price_alert_high' => $priceAlertHigh,
                'price_alert_low'  => $priceAlertLow,
                'notes'           => $notes !== '' ? $notes : null,
                'added_at'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $item = $this->db->fetchOne(
                <<<'SQL'
                    SELECT
                        wi.*,
                        a.symbol,
                        a.name,
                        a.exchange,
                        a.asset_type
                    FROM watchlist_items wi
                    JOIN assets a ON a.id = wi.asset_id
                    WHERE wi.id = :id
                SQL,
                ['id' => $itemId],
            );

            $this->success($item, 'Item added to watchlist.', 201);
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['watchlist_id' => $watchlist['id'], 'symbol' => $symbol]);
            $this->error('Failed to add item', 500);
        }
    }

    // ─── DELETE /api/v1/watchlists/{id}/items/{itemId} ────────────────────────

    public function removeItem(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $watchlist = $this->requireWatchlistOwnership($req, $userId);
        $itemId    = trim((string) $req->getParam('itemId', ''));

        if ($itemId === '') {
            $this->error('Item ID is required.', 400);
        }

        try {
            $item = $this->db->fetchOne(
                'SELECT id FROM watchlist_items WHERE id = :id AND watchlist_id = :wid LIMIT 1',
                ['id' => $itemId, 'wid' => $watchlist['id']],
            );

            if ($item === null) {
                $this->error('Watchlist item not found.', 404);
            }

            $this->db->delete('watchlist_items', ['id' => $itemId]);
            http_response_code(204);
            exit;
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['watchlist_id' => $watchlist['id'], 'item_id' => $itemId]);
            $this->error('Failed to remove item', 500);
        }
    }

    // ─── PUT /api/v1/watchlists/{id}/items/{itemId} ───────────────────────────

    public function updateItem(Request $req): void
    {
        $userId    = $this->requireUserId($req);
        $watchlist = $this->requireWatchlistOwnership($req, $userId);
        $itemId    = trim((string) $req->getParam('itemId', ''));
        $body      = $req->getBody();

        if ($itemId === '') {
            $this->error('Item ID is required.', 400);
        }

        try {
            $item = $this->db->fetchOne(
                'SELECT id FROM watchlist_items WHERE id = :id AND watchlist_id = :wid LIMIT 1',
                ['id' => $itemId, 'wid' => $watchlist['id']],
            );

            if ($item === null) {
                $this->error('Watchlist item not found.', 404);
            }

            $data = [];

            if (array_key_exists('price_alert_high', $body)) {
                $data['price_alert_high'] = $body['price_alert_high'] !== null
                    ? (float) $body['price_alert_high']
                    : null;
            }

            if (array_key_exists('price_alert_low', $body)) {
                $data['price_alert_low'] = $body['price_alert_low'] !== null
                    ? (float) $body['price_alert_low']
                    : null;
            }

            if (array_key_exists('notes', $body)) {
                $notes          = trim((string) $body['notes']);
                $data['notes']  = $notes !== '' ? $notes : null;
            }

            if (empty($data)) {
                $this->error('No updatable fields provided. Allowed: price_alert_high, price_alert_low, notes.', 400);
            }

            $this->db->update('watchlist_items', $data, ['id' => $itemId]);

            $updated = $this->db->fetchOne(
                <<<'SQL'
                    SELECT
                        wi.*,
                        a.symbol,
                        a.name,
                        a.exchange,
                        a.asset_type
                    FROM watchlist_items wi
                    JOIN assets a ON a.id = wi.asset_id
                    WHERE wi.id = :id
                SQL,
                ['id' => $itemId],
            );

            $this->success($updated, 'Watchlist item updated.');
        } catch (\Throwable $e) {
            $this->logger->exception($e, ['watchlist_id' => $watchlist['id'], 'item_id' => $itemId]);
            $this->error('Failed to update item', 500);
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
    private function requireWatchlistOwnership(Request $req, string $userId): array
    {
        $watchlistId = trim((string) $req->getParam('id', ''));

        if ($watchlistId === '') {
            $this->error('Watchlist ID is required.', 400);
        }

        try {
            $watchlist = $this->db->fetchOne(
                'SELECT * FROM watchlists WHERE id = :id LIMIT 1',
                ['id' => $watchlistId],
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $this->error('Database error.', 500);
        }

        if ($watchlist === null) {
            $this->error('Watchlist not found.', 404);
        }

        if ((string) $watchlist['user_id'] !== $userId) {
            $this->error('You do not have access to this watchlist.', 403);
        }

        return $watchlist;
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
