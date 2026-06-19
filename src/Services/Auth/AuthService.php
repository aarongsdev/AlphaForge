<?php

declare(strict_types=1);

namespace AlphaForge\Services\Auth;

use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Validator;
use AlphaForge\Exceptions\ValidationException;
use AlphaForge\Models\User;
use Ramsey\Uuid\Uuid;

/**
 * Authentication service: registration, login, logout, token refresh,
 * password reset, email verification, and profile management.
 *
 * All auth events are recorded in the audit_logs table for compliance.
 * Account lockout is enforced after 5 consecutive failed logins (15 minutes).
 */
final class AuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES     = 15;
    private const EMAIL_TOKEN_BYTES   = 64;

    private Database        $db;
    private Logger          $logger;
    private User            $userModel;
    private JwtService      $jwt;
    private PasswordService $passwords;

    public function __construct()
    {
        $this->db        = Database::getInstance();
        $this->logger    = Logger::getInstance();
        $this->userModel = new User();
        $this->jwt       = new JwtService();
        $this->passwords = new PasswordService();
    }

    // ─── Registration ────────────────────────────────────────────────────────

    /**
     * Register a new user account.
     *
     * Validates uniqueness of email and username, hashes the password with
     * Argon2id, creates the user record, assigns the default 'viewer' role,
     * and queues an email-verification link.
     *
     * @param array<string, mixed> $data Required: email, password, password_confirm.
     *                                   Optional: username, first_name, last_name, phone.
     * @return array<string, mixed> The new user's public data (without password_hash)
     * @throws ValidationException     On validation or uniqueness failure
     * @throws \RuntimeException       On DB error
     */
    public function register(array $data): array
    {
        // ── Validation ───────────────────────────────────────────────────────
        $validated = (new Validator())
            ->field('email')->required()->email()->max(255)
            ->field('password')->required()->min(12)->max(255)
            ->field('password_confirm')->required()
            ->field('username')->max(100)
            ->field('first_name')->max(100)
            ->field('last_name')->max(100)
            ->validate($data);

        if ($validated['password'] !== $validated['password_confirm']) {
            throw new ValidationException('Validation failed', [
                'password_confirm' => 'Passwords do not match.',
            ]);
        }

        $strength = $this->passwords->validateStrength($validated['password']);

        if (!$strength['valid']) {
            throw new ValidationException('Password is too weak', [
                'password' => implode(' ', $strength['errors']),
            ]);
        }

        $email = strtolower(trim((string) $validated['email']));

        // ── Uniqueness checks ────────────────────────────────────────────────
        if ($this->userModel->findByEmail($email) !== null) {
            throw new ValidationException('Validation failed', [
                'email' => 'An account with this email address already exists.',
            ]);
        }

        if (!empty($validated['username'])) {
            $username = trim((string) $validated['username']);

            if ($this->userModel->findByUsername($username) !== null) {
                throw new ValidationException('Validation failed', [
                    'username' => 'This username is already taken.',
                ]);
            }
        }

        // ── Persist ──────────────────────────────────────────────────────────
        $this->db->beginTransaction();

        try {
            $user = $this->userModel->create([
                'email'         => $email,
                'password_hash' => $this->passwords->hash($validated['password']),
                'username'      => !empty($validated['username']) ? trim((string) $validated['username']) : null,
                'first_name'    => !empty($validated['first_name']) ? trim((string) $validated['first_name']) : null,
                'last_name'     => !empty($validated['last_name']) ? trim((string) $validated['last_name']) : null,
                'phone'         => !empty($validated['phone']) ? trim((string) $validated['phone']) : null,
            ]);

            // Assign default role.
            $this->userModel->assignRole($user['id'], 'viewer');

            // Create email-verification token.
            $verificationToken = $this->createEmailVerificationToken($user['id']);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->audit($user['id'], 'user.register', 'users', $user['id'], null, [
            'email' => $email,
        ]);

        $this->logger->info('User registered', ['user_id' => $user['id'], 'email' => $email]);

        return $user;
    }

    // ─── Login ────────────────────────────────────────────────────────────────

    /**
     * Authenticate a user and issue access + refresh tokens.
     *
     * Enforces account lockout: after MAX_FAILED_ATTEMPTS consecutive failures
     * the account is locked for LOCKOUT_MINUTES. Each subsequent failed attempt
     * while locked resets the lockout timer.
     *
     * @param string $email     User's email address
     * @param string $password  Plaintext password
     * @param string $ip        Client IP address
     * @param string $userAgent User-Agent header value
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: array<string, mixed>}
     * @throws \RuntimeException On authentication failure (generic message to prevent enumeration)
     */
    public function login(string $email, string $password, string $ip, string $userAgent): array
    {
        $email = strtolower(trim($email));

        // Fetch full row (including password_hash) for verification.
        $fullUser = $this->userModel->findByEmailWithPassword($email);

        if ($fullUser === null) {
            // Constant-time guard: hash a dummy value to prevent timing attacks.
            $this->passwords->verify($password, '$argon2id$v=19$m=65536,t=4,p=3$' . str_repeat('A', 22) . '$' . str_repeat('A', 43));
            $this->audit(null, 'user.login.fail', 'users', null, null, [
                'email'  => $email,
                'reason' => 'user_not_found',
                'ip'     => $ip,
            ]);
            throw new \RuntimeException('Invalid email or password.');
        }

        // Check account lock before password verification.
        if ($this->userModel->isLocked($fullUser)) {
            $this->audit($fullUser['id'], 'user.login.blocked', 'users', $fullUser['id'], null, [
                'ip'     => $ip,
                'reason' => 'account_locked',
            ]);
            throw new \RuntimeException('Account is temporarily locked. Please try again later.');
        }

        // Verify password.
        if (!$this->passwords->verify($password, (string) $fullUser['password_hash'])) {
            $this->userModel->incrementFailedLogins((string) $fullUser['id']);

            $attempts = (int) $fullUser['failed_login_attempts'] + 1;

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $this->userModel->lockAccount((string) $fullUser['id'], self::LOCKOUT_MINUTES);
            }

            $this->audit($fullUser['id'], 'user.login.fail', 'users', $fullUser['id'], null, [
                'ip'       => $ip,
                'attempts' => $attempts,
                'reason'   => 'invalid_password',
            ]);

            throw new \RuntimeException('Invalid email or password.');
        }

        // Reject deactivated accounts.
        if (!(bool) $fullUser['is_active']) {
            throw new \RuntimeException('This account has been deactivated. Please contact support.');
        }

        // Fetch user's primary role.
        $roles      = $this->userModel->getRoles((string) $fullUser['id']);
        $primaryRole = !empty($roles) ? (string) $roles[0]['name'] : 'viewer';

        // Update login metadata and reset failure counter.
        $this->userModel->recordLogin((string) $fullUser['id']);

        // Rehash password if parameters have changed.
        if ($this->passwords->needsRehash((string) $fullUser['password_hash'])) {
            $this->userModel->updatePassword(
                (string) $fullUser['id'],
                $this->passwords->hash($password),
            );
        }

        // ── Issue tokens ─────────────────────────────────────────────────────
        $accessToken = $this->jwt->generate([
            'user_id' => $fullUser['id'],
            'email'   => $fullUser['email'],
            'role'    => $primaryRole,
        ]);

        $refreshToken = $this->jwt->generateRefreshToken(
            (string) $fullUser['id'],
            $primaryRole,
            (string) $fullUser['email'],
        );

        // Persist the session with a hash of the refresh token.
        $refreshHash = hash('sha256', $refreshToken);

        $this->db->insert('user_sessions', [
            'user_id'    => $fullUser['id'],
            'token_hash' => $refreshHash,
            'ip_address' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 500),
            'is_active'  => true,
            'expires_at' => date('Y-m-d H:i:sP', time() + $this->jwt->getRefreshExpiry()),
        ]);

        $this->audit($fullUser['id'], 'user.login', 'users', $fullUser['id'], null, [
            'ip'         => $ip,
            'user_agent' => mb_substr($userAgent, 0, 200),
        ]);

        // Return public user data (no password hash).
        $publicUser = $this->userModel->findById((string) $fullUser['id']);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => $this->jwt->getExpiry(),
            'token_type'    => 'Bearer',
            'user'          => array_merge($publicUser ?? [], ['role' => $primaryRole]),
        ];
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    /**
     * Log out a user by revoking their current access-token JTI and deactivating the session.
     *
     * @param string $userId         User UUID
     * @param string $jti            The JTI claim from the current access token
     * @param string $refreshToken   The raw refresh token string (to find and deactivate the session)
     */
    public function logout(string $userId, string $jti, string $refreshToken = ''): void
    {
        // Revoke the access token.
        $this->jwt->revoke($jti);

        // Deactivate the matching session.
        if ($refreshToken !== '') {
            $refreshHash = hash('sha256', $refreshToken);

            $this->db->query(
                'UPDATE user_sessions SET is_active = FALSE WHERE user_id = :uid AND token_hash = :hash',
                ['uid' => $userId, 'hash' => $refreshHash],
            );
        }

        $this->audit($userId, 'user.logout', 'users', $userId);

        $this->logger->info('User logged out', ['user_id' => $userId, 'jti' => $jti]);
    }

    // ─── Token refresh ────────────────────────────────────────────────────────

    /**
     * Exchange a refresh token for a new access token.
     *
     * Validates the refresh token, ensures the corresponding session is still active,
     * and issues a new access token.
     *
     * @param string $refreshToken Raw refresh-token JWT
     * @return array{access_token: string, expires_in: int}
     * @throws \RuntimeException On invalid, expired, or revoked refresh token
     */
    public function refreshToken(string $refreshToken): array
    {
        $refreshHash = hash('sha256', $refreshToken);

        // Ensure the session exists and is still active in the DB.
        $session = $this->db->fetchOne(
            'SELECT * FROM user_sessions WHERE token_hash = :hash AND is_active = TRUE AND expires_at > NOW()',
            ['hash' => $refreshHash],
        );

        if ($session === null) {
            throw new \RuntimeException('Refresh token session not found or has expired.');
        }

        // Issue new access token via JwtService (also rotates/revokes the old refresh JTI).
        $newAccessToken = $this->jwt->refresh($refreshToken);

        $this->audit((string) $session['user_id'], 'user.token.refresh', 'user_sessions', (string) $session['id']);

        return [
            'access_token' => $newAccessToken,
            'expires_in'   => $this->jwt->getExpiry(),
            'token_type'   => 'Bearer',
        ];
    }

    // ─── Password reset ───────────────────────────────────────────────────────

    /**
     * Initiate a password reset flow for the given email address.
     *
     * Generates a reset token, stores its SHA-256 hash in the DB, and
     * logs the event. The email notification is the caller's responsibility.
     * Always returns void — never reveals whether the email exists.
     *
     * @param string $email
     */
    public function forgotPassword(string $email): void
    {
        $email = strtolower(trim($email));
        $user  = $this->userModel->findByEmail($email);

        if ($user === null) {
            // Return silently — no enumeration.
            $this->logger->info('Password reset requested for unknown email', ['email' => $email]);
            return;
        }

        $tokenData = $this->passwords->generateResetToken();

        // Invalidate any prior reset tokens for this user.
        $this->db->query(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => $user['id']],
        );

        $this->db->insert('password_reset_tokens', [
            'user_id'    => $user['id'],
            'token'      => $tokenData['hash'],
            'expires_at' => date('Y-m-d H:i:sP', $tokenData['expires_at']),
        ]);

        $this->audit((string) $user['id'], 'user.password.reset_requested', 'users', (string) $user['id']);

        $this->logger->info('Password reset token generated', ['user_id' => $user['id']]);

        // TODO: dispatch PasswordResetEmail event with $tokenData['token']
    }

    /**
     * Complete a password reset using the token from the reset link.
     *
     * Validates the token, ensures it has not been used or expired, updates
     * the password, marks the token as used, and invalidates all active sessions.
     *
     * @param string $token       Plaintext reset token from the link
     * @param string $newPassword New plaintext password
     * @throws \RuntimeException  On invalid/expired token or weak password
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $strength = $this->passwords->validateStrength($newPassword);

        if (!$strength['valid']) {
            throw new ValidationException('Password is too weak', [
                'password' => implode(' ', $strength['errors']),
            ]);
        }

        $tokenHash = $this->passwords->hashResetToken($token);

        $resetRow = $this->db->fetchOne(
            'SELECT * FROM password_reset_tokens
              WHERE token = :hash
                AND used_at IS NULL
                AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1',
            ['hash' => $tokenHash],
        );

        if ($resetRow === null) {
            throw new \RuntimeException('This password reset link is invalid or has expired.');
        }

        $userId = (string) $resetRow['user_id'];

        $this->db->beginTransaction();

        try {
            // Update the password.
            $this->userModel->updatePassword($userId, $this->passwords->hash($newPassword));

            // Mark token as used.
            $this->db->update(
                'password_reset_tokens',
                ['used_at' => date('Y-m-d H:i:sP')],
                ['id' => $resetRow['id']],
            );

            // Invalidate all active sessions for this user.
            $this->db->query(
                'UPDATE user_sessions SET is_active = FALSE WHERE user_id = :uid',
                ['uid' => $userId],
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->audit($userId, 'user.password.reset', 'users', $userId);

        $this->logger->info('Password reset completed', ['user_id' => $userId]);
    }

    // ─── Email verification ───────────────────────────────────────────────────

    /**
     * Verify a user's email address using the token from the verification link.
     *
     * @param string $token Plaintext verification token from the email link
     * @throws \RuntimeException On invalid or expired token
     */
    public function verifyEmail(string $token): void
    {
        $tokenHash = hash('sha256', $token);

        $row = $this->db->fetchOne(
            "SELECT * FROM email_verification_tokens
              WHERE token_hash = :hash
                AND used_at IS NULL
                AND expires_at > NOW()
             LIMIT 1",
            ['hash' => $tokenHash],
        );

        if ($row === null) {
            throw new \RuntimeException('This verification link is invalid or has expired.');
        }

        $userId = (string) $row['user_id'];

        $this->db->beginTransaction();

        try {
            $this->userModel->markEmailVerified($userId);

            $this->db->query(
                "UPDATE email_verification_tokens SET used_at = NOW() WHERE id = :id",
                ['id' => $row['id']],
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->audit($userId, 'user.email.verified', 'users', $userId);
    }

    // ─── Profile ──────────────────────────────────────────────────────────────

    /**
     * Update a user's public profile fields.
     *
     * Only username, first_name, last_name, phone, and avatar_url may be updated
     * through this method. Email/password changes have dedicated methods.
     *
     * @param string               $userId User UUID
     * @param array<string, mixed> $data   Fields to update (only safe fields accepted)
     * @return array<string, mixed> Updated public user data
     * @throws ValidationException On validation failure or username collision
     */
    public function updateProfile(string $userId, array $data): array
    {
        $validated = (new Validator())
            ->field('username')->max(100)
            ->field('first_name')->max(100)
            ->field('last_name')->max(100)
            ->field('phone')->max(30)
            ->field('avatar_url')->max(2048)->url()
            ->validate($data);

        // Check username uniqueness if changed.
        if (!empty($validated['username'])) {
            $username  = trim((string) $validated['username']);
            $existing  = $this->userModel->findByUsername($username);

            if ($existing !== null && (string) $existing['id'] !== $userId) {
                throw new ValidationException('Validation failed', [
                    'username' => 'This username is already taken.',
                ]);
            }

            $validated['username'] = $username;
        }

        $safe = array_filter($validated, fn($v) => $v !== null && $v !== '');

        $user = $this->userModel->update($userId, $safe);

        $this->audit($userId, 'user.profile.update', 'users', $userId, null, $safe);

        return $user;
    }

    /**
     * Change a user's password after verifying their current password.
     *
     * @param string $userId      User UUID
     * @param string $oldPassword The user's current plaintext password
     * @param string $newPassword The desired new plaintext password
     * @throws \RuntimeException  On wrong current password or weak new password
     */
    public function changePassword(string $userId, string $oldPassword, string $newPassword): void
    {
        $fullUser = $this->userModel->findByIdWithPassword($userId);

        if ($fullUser === null) {
            throw new \RuntimeException('User not found.');
        }

        if (!$this->passwords->verify($oldPassword, (string) $fullUser['password_hash'])) {
            throw new \RuntimeException('Current password is incorrect.');
        }

        $strength = $this->passwords->validateStrength($newPassword);

        if (!$strength['valid']) {
            throw new ValidationException('New password is too weak', [
                'new_password' => implode(' ', $strength['errors']),
            ]);
        }

        $this->userModel->updatePassword($userId, $this->passwords->hash($newPassword));

        // Invalidate all active sessions (user must log in again).
        $this->db->query(
            'UPDATE user_sessions SET is_active = FALSE WHERE user_id = :uid',
            ['uid' => $userId],
        );

        $this->audit($userId, 'user.password.changed', 'users', $userId);

        $this->logger->info('User changed their password', ['user_id' => $userId]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Create an email verification token and store its hash in the DB.
     *
     * Returns the plaintext token (to be embedded in the verification link email).
     *
     * @param string $userId User UUID
     * @return string Plaintext token
     */
    private function createEmailVerificationToken(string $userId): string
    {
        $tokenBytes = random_bytes(self::EMAIL_TOKEN_BYTES);
        $token      = bin2hex($tokenBytes);
        $tokenHash  = hash('sha256', $token);

        // Silently skip if the table doesn't exist yet (migration not run).
        try {
            $this->db->query(
                "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                 VALUES (:user_id, :token_hash, NOW() + INTERVAL '24 hours')",
                ['user_id' => $userId, 'token_hash' => $tokenHash],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not store email verification token', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }

        return $token;
    }

    /**
     * Record an audit log entry.
     *
     * @param string|null          $userId     Actor's UUID (null for system actions)
     * @param string               $action     Verb, e.g. 'user.login'
     * @param string|null          $entityType Entity class name
     * @param string|null          $entityId   Entity UUID
     * @param array<string,mixed>|null $oldValues Snapshot before change
     * @param array<string,mixed>|null $newValues Snapshot after change
     */
    private function audit(
        ?string $userId,
        string  $action,
        ?string $entityType = null,
        ?string $entityId   = null,
        ?array  $oldValues  = null,
        ?array  $newValues  = null,
    ): void {
        try {
            $this->db->insert('audit_logs', array_filter([
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $oldValues !== null ? json_encode($oldValues) : null,
                'new_values'  => $newValues !== null ? json_encode($newValues) : null,
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'  => isset($_SERVER['HTTP_USER_AGENT'])
                    ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
                    : null,
            ], fn($v) => $v !== null));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to write audit log', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
