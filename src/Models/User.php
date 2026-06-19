<?php

declare(strict_types=1);

namespace AlphaForge\Models;

use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;

/**
 * User model: data-access methods for the `users`, `user_roles`, `roles`,
 * and supporting tables.
 *
 * All public methods strip `password_hash` from returned arrays to prevent
 * accidental exposure. Internal helpers that need the hash are prefixed with
 * `fetchWith` to make this intent explicit.
 *
 * Every write operation runs through PDO prepared statements to eliminate
 * SQL injection risk.
 */
final class User
{
    /** Columns returned to callers — password_hash is always excluded */
    private const PUBLIC_COLUMNS = [
        'id', 'email', 'username', 'first_name', 'last_name',
        'phone', 'avatar_url', 'email_verified_at', 'is_active',
        'failed_login_attempts', 'locked_until', 'last_login_at',
        'created_at', 'updated_at',
    ];

    private Database $db;
    private Logger   $logger;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    // ─── Read operations ──────────────────────────────────────────────────────

    /**
     * Find a user by their UUID, without exposing the password hash.
     *
     * @param string $id UUID
     * @return array<string, mixed>|null User row or null if not found
     */
    public function findById(string $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->publicColumnList() . ' FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row ?? null;
    }

    /**
     * Find a user by email address (case-insensitive), without exposing the password hash.
     *
     * @param string $email
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->publicColumnList() . ' FROM users WHERE LOWER(email) = LOWER(:email)',
            ['email' => $email],
        );

        return $row ?? null;
    }

    /**
     * Find a user by username (case-insensitive), without exposing the password hash.
     *
     * @param string $username
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->publicColumnList() . ' FROM users WHERE LOWER(username) = LOWER(:username)',
            ['username' => $username],
        );

        return $row ?? null;
    }

    /**
     * Retrieve the full user row including the password hash.
     *
     * INTERNAL USE ONLY — must never be returned to API consumers.
     *
     * @param string $email
     * @return array<string, mixed>|null Full row including password_hash
     */
    public function findByEmailWithPassword(string $email): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE LOWER(email) = LOWER(:email)',
            ['email' => $email],
        );
    }

    /**
     * Retrieve the full user row including the password hash by ID.
     *
     * INTERNAL USE ONLY.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function findByIdWithPassword(string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE id = :id',
            ['id' => $id],
        );
    }

    // ─── Write operations ─────────────────────────────────────────────────────

    /**
     * Insert a new user record and return the public user array.
     *
     * @param array<string, mixed> $data Must include: email, password_hash. Optionally: username, first_name, last_name, phone.
     * @return array<string, mixed> The inserted user (without password_hash)
     * @throws \PDOException On unique constraint violations or DB error
     */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:sP');

        $insertData = [
            'email'                 => strtolower((string) $data['email']),
            'password_hash'         => (string) $data['password_hash'],
            'username'              => isset($data['username']) ? (string) $data['username'] : null,
            'first_name'            => isset($data['first_name']) ? (string) $data['first_name'] : null,
            'last_name'             => isset($data['last_name']) ? (string) $data['last_name'] : null,
            'phone'                 => isset($data['phone']) ? (string) $data['phone'] : null,
            'is_active'             => true,
            'failed_login_attempts' => 0,
            'created_at'            => $now,
            'updated_at'            => $now,
        ];

        // Remove null values so the DB defaults apply.
        $insertData = array_filter($insertData, fn($v) => $v !== null);

        $id = $this->db->insert('users', $insertData);

        $user = $this->findById($id);

        if ($user === null) {
            throw new \RuntimeException('Failed to retrieve user after creation.');
        }

        $this->logger->info('User created', ['user_id' => $id]);

        return $user;
    }

    /**
     * Update mutable user fields and return the updated public user array.
     *
     * Only the explicitly provided keys in $data are updated.
     * Sensitive fields (password_hash, id, email, created_at) cannot be changed
     * via this method.
     *
     * @param string               $id   User UUID
     * @param array<string, mixed> $data Fields to update
     * @return array<string, mixed> Updated user (without password_hash)
     * @throws \InvalidArgumentException When no safe fields are provided
     * @throws \RuntimeException When the user is not found
     */
    public function update(string $id, array $data): array
    {
        $disallowed = ['id', 'password_hash', 'email', 'created_at'];

        $safe = array_diff_key($data, array_flip($disallowed));
        $safe['updated_at'] = date('Y-m-d H:i:sP');

        if (count($safe) === 1) {
            // Only updated_at — nothing substantive to update.
            throw new \InvalidArgumentException('No valid fields provided for update.');
        }

        $this->db->update('users', $safe, ['id' => $id]);

        $user = $this->findById($id);

        if ($user === null) {
            throw new \RuntimeException("User '{$id}' not found after update.");
        }

        return $user;
    }

    /**
     * Soft-delete a user by deactivating their account.
     *
     * Hard deletion is intentionally not offered here to preserve audit trail
     * integrity (audit_logs references user_id).
     *
     * @param string $id User UUID
     * @return bool True if the row was affected
     */
    public function delete(string $id): bool
    {
        $affected = $this->db->update(
            'users',
            ['is_active' => false, 'updated_at' => date('Y-m-d H:i:sP')],
            ['id' => $id],
        );

        return $affected > 0;
    }

    /**
     * Update only the password_hash for a user.
     *
     * @param string $id           User UUID
     * @param string $passwordHash New Argon2id hash
     */
    public function updatePassword(string $id, string $passwordHash): void
    {
        $this->db->update('users', [
            'password_hash' => $passwordHash,
            'updated_at'    => date('Y-m-d H:i:sP'),
        ], ['id' => $id]);
    }

    /**
     * Set the email_verified_at timestamp to now for a user.
     *
     * @param string $id User UUID
     */
    public function markEmailVerified(string $id): void
    {
        $this->db->update('users', [
            'email_verified_at' => date('Y-m-d H:i:sP'),
            'updated_at'        => date('Y-m-d H:i:sP'),
        ], ['id' => $id]);
    }

    /**
     * Record a successful login: reset failed attempts and update last_login_at.
     *
     * @param string $id User UUID
     */
    public function recordLogin(string $id): void
    {
        $this->db->update('users', [
            'last_login_at'         => date('Y-m-d H:i:sP'),
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'updated_at'            => date('Y-m-d H:i:sP'),
        ], ['id' => $id]);
    }

    // ─── Account lockout ──────────────────────────────────────────────────────

    /**
     * Increment the failed login counter by 1.
     *
     * @param string $id User UUID
     */
    public function incrementFailedLogins(string $id): void
    {
        $this->db->query(
            'UPDATE users SET failed_login_attempts = failed_login_attempts + 1, updated_at = NOW() WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Reset the failed login counter to zero and clear any lockout.
     *
     * @param string $id User UUID
     */
    public function resetFailedLogins(string $id): void
    {
        $this->db->update('users', [
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'updated_at'            => date('Y-m-d H:i:sP'),
        ], ['id' => $id]);
    }

    /**
     * Lock a user account for the given number of minutes.
     *
     * @param string $id      User UUID
     * @param int    $minutes Lock duration in minutes
     */
    public function lockAccount(string $id, int $minutes): void
    {
        $lockedUntil = date('Y-m-d H:i:sP', time() + ($minutes * 60));

        $this->db->update('users', [
            'locked_until' => $lockedUntil,
            'updated_at'   => date('Y-m-d H:i:sP'),
        ], ['id' => $id]);

        $this->logger->warning('User account locked', [
            'user_id'      => $id,
            'minutes'      => $minutes,
            'locked_until' => $lockedUntil,
        ]);
    }

    /**
     * Determine whether a user account is currently locked.
     *
     * @param array<string, mixed> $user User row (as returned by findById etc.)
     * @return bool True when the account is locked right now
     */
    public function isLocked(array $user): bool
    {
        if (empty($user['locked_until'])) {
            return false;
        }

        $lockedUntil = strtotime((string) $user['locked_until']);

        return $lockedUntil !== false && $lockedUntil > time();
    }

    // ─── Roles & permissions ──────────────────────────────────────────────────

    /**
     * Return all roles assigned to a user.
     *
     * @param string $userId User UUID
     * @return list<array{id: string, name: string, description: string, permissions: mixed}>
     */
    public function getRoles(string $userId): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.name, r.description, r.permissions
               FROM roles r
               JOIN user_roles ur ON ur.role_id = r.id
              WHERE ur.user_id = :user_id',
            ['user_id' => $userId],
        );
    }

    /**
     * Determine whether a user has a specific named role.
     *
     * @param string $userId User UUID
     * @param string $role   Role name (e.g. 'admin', 'analyst', 'trader', 'viewer')
     * @return bool
     */
    public function hasRole(string $userId, string $role): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 FROM roles r
               JOIN user_roles ur ON ur.role_id = r.id
              WHERE ur.user_id = :user_id
                AND r.name = :role
             LIMIT 1',
            ['user_id' => $userId, 'role' => $role],
        );

        return $row !== null;
    }

    /**
     * Determine whether a user has a specific permission string.
     *
     * Permissions are stored as JSONB in the roles table. A user is considered
     * to have a permission when any of their roles grants it.
     *
     * @param string $userId     User UUID
     * @param string $permission Permission string, e.g. 'signals:read'
     * @return bool
     */
    public function hasPermission(string $userId, string $permission): bool
    {
        $row = $this->db->fetchOne(
            "SELECT 1
               FROM roles r
               JOIN user_roles ur ON ur.role_id = r.id
              WHERE ur.user_id = :user_id
                AND r.permissions @> :perm::jsonb
             LIMIT 1",
            [
                'user_id' => $userId,
                'perm'    => json_encode([$permission]),
            ],
        );

        return $row !== null;
    }

    /**
     * Assign a named role to a user.
     *
     * Silently ignores the assignment when the role is already assigned (upsert).
     *
     * @param string      $userId     User UUID receiving the role
     * @param string      $roleName   Role name (must exist in roles table)
     * @param string|null $assignedBy UUID of the admin assigning the role, or null for system
     * @throws \RuntimeException When the named role does not exist
     */
    public function assignRole(string $userId, string $roleName, ?string $assignedBy = null): void
    {
        $role = $this->db->fetchOne(
            'SELECT id FROM roles WHERE name = :name',
            ['name' => $roleName],
        );

        if ($role === null) {
            throw new \RuntimeException("Role '{$roleName}' does not exist.");
        }

        // Upsert — ignore conflict on existing assignment.
        $this->db->query(
            'INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at)
             VALUES (:user_id, :role_id, :assigned_by, NOW())
             ON CONFLICT (user_id, role_id) DO NOTHING',
            [
                'user_id'     => $userId,
                'role_id'     => $role['id'],
                'assigned_by' => $assignedBy,
            ],
        );
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build a SELECT column list for public-facing queries (excludes password_hash).
     */
    private function publicColumnList(): string
    {
        return implode(', ', self::PUBLIC_COLUMNS);
    }
}
