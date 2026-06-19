<?php

declare(strict_types=1);

namespace AlphaForge\Services\Auth;

/**
 * Password hashing, verification, reset-token generation, and strength validation.
 *
 * All hashing uses the Argon2id algorithm with hardened cost parameters.
 * Reset tokens are cryptographically random, returned as plaintext for the
 * email link, and stored only as a SHA-256 hash in the database.
 */
final class PasswordService
{
    // Argon2id cost parameters — intentionally high to resist brute-force.
    private const ARGON_MEMORY_COST   = 65536; // 64 MiB
    private const ARGON_TIME_COST     = 4;     // 4 iterations
    private const ARGON_THREADS       = 3;

    // Password reset token: 64 bytes of entropy → 128-char hex string.
    private const RESET_TOKEN_BYTES   = 64;

    // Reset tokens expire after 1 hour.
    private const RESET_TOKEN_TTL     = 3600;

    // Temporary password length (printable ASCII).
    private const TEMP_PASSWORD_LENGTH = 20;

    /**
     * Hash a plaintext password using Argon2id.
     *
     * @param string $password The user's plaintext password
     * @return string Argon2id hash string suitable for storage
     * @throws \RuntimeException On hashing failure (should never occur on supported PHP)
     */
    public function hash(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => self::ARGON_MEMORY_COST,
            'time_cost'   => self::ARGON_TIME_COST,
            'threads'     => self::ARGON_THREADS,
        ]);

        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Verify a plaintext password against a stored Argon2id hash.
     *
     * @param string $password The plaintext candidate password
     * @param string $hash     The stored hash to verify against
     * @return bool True when the password matches
     */
    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Determine whether the stored hash needs to be rehashed with updated parameters.
     *
     * Call this after a successful login and update the hash when true.
     *
     * @param string $hash The hash retrieved from storage
     * @return bool True when a rehash is recommended
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => self::ARGON_MEMORY_COST,
            'time_cost'   => self::ARGON_TIME_COST,
            'threads'     => self::ARGON_THREADS,
        ]);
    }

    /**
     * Generate a cryptographically secure password reset token.
     *
     * Returns:
     *   - 'token'      → The plaintext token to include in the reset link (never stored)
     *   - 'hash'       → SHA-256 hash of the token (stored in password_reset_tokens)
     *   - 'expires_at' → Unix timestamp when the token expires
     *
     * @return array{token: string, hash: string, expires_at: int}
     * @throws \RuntimeException On RNG failure
     */
    public function generateResetToken(): array
    {
        $tokenBytes = random_bytes(self::RESET_TOKEN_BYTES);
        $token      = bin2hex($tokenBytes);
        $hash       = hash('sha256', $token);

        return [
            'token'      => $token,
            'hash'       => $hash,
            'expires_at' => time() + self::RESET_TOKEN_TTL,
        ];
    }

    /**
     * Hash a plaintext reset token for database storage / lookup.
     *
     * @param string $token Plaintext token received from the reset link
     * @return string SHA-256 hex hash
     */
    public function hashResetToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Validate password strength against AlphaForge policy.
     *
     * Policy:
     *   - Minimum 12 characters
     *   - At least one uppercase letter (A-Z)
     *   - At least one lowercase letter (a-z)
     *   - At least one digit (0-9)
     *   - At least one special character (!@#$%^&*()_+-=[]{}|;':",./<>?)
     *
     * @param string $password The plaintext password to evaluate
     * @return array{valid: bool, errors: list<string>} Validation result with descriptive errors
     */
    public function validateStrength(string $password): array
    {
        $errors = [];

        if (mb_strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters long.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit.';
        }

        if (!preg_match('/[!@#$%^&*()\-_=+\[\]{}|;\':",.\\/<>?`~]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }

        // Reject passwords that are purely repetitive sequences.
        if (preg_match('/^(.)\1+$/', $password)) {
            $errors[] = 'Password must not consist of a single repeated character.';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Generate a secure random temporary password that satisfies strength requirements.
     *
     * The generated password includes characters from each required character class.
     *
     * @return string Temporary password string (TEMP_PASSWORD_LENGTH characters)
     * @throws \RuntimeException On RNG failure
     */
    public function generateTemporary(): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $digits    = '23456789';
        $specials  = '!@#$%^&*()-_=+[]{}|;:,.?';

        // Guarantee at least one character from each required class.
        $password  = [];
        $password[] = $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password[] = $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password[] = $digits[random_int(0, strlen($digits) - 1)];
        $password[] = $specials[random_int(0, strlen($specials) - 1)];

        // Fill the remainder from all classes combined.
        $all = $uppercase . $lowercase . $digits . $specials;

        for ($i = count($password); $i < self::TEMP_PASSWORD_LENGTH; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle to avoid predictable position of guaranteed chars.
        shuffle($password);

        return implode('', $password);
    }
}
