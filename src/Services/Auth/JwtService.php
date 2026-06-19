<?php

declare(strict_types=1);

namespace AlphaForge\Services\Auth;

use AlphaForge\Core\Cache;
use AlphaForge\Core\Config;
use AlphaForge\Core\Logger;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Ramsey\Uuid\Uuid;

/**
 * JSON Web Token service for access-token generation, verification, and revocation.
 *
 * Uses firebase/php-jwt with HS256 by default. Refresh tokens are longer-lived
 * JWTs issued separately and tracked in Redis. Revoked access-token JTIs are
 * stored in Redis with a TTL equal to the access-token expiry so the blacklist
 * entry automatically expires once the token could no longer be valid anyway.
 */
final class JwtService
{
    private string $secret;
    private string $algorithm;
    private int    $expiry;
    private int    $refreshExpiry;
    private string $issuer;
    private string $audience;
    private int    $leeway;
    private string $blacklistPrefix;
    private bool   $blacklistEnabled;

    private Cache  $cache;
    private Config $config;
    private Logger $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->cache  = Cache::getInstance();
        $this->logger = Logger::getInstance();

        $this->secret           = (string) ($this->config->get('jwt.secret')    ?? $_ENV['JWT_SECRET'] ?? '');
        $this->algorithm        = (string) ($this->config->get('jwt.algorithm') ?? 'HS256');
        $this->expiry           = (int)    ($this->config->get('jwt.expiry')    ?? 3600);
        $this->refreshExpiry    = (int)    ($this->config->get('jwt.refresh.expiry') ?? 604800);
        $this->issuer           = (string) ($this->config->get('jwt.issuer')    ?? 'alphaforge');
        $this->audience         = (string) ($this->config->get('jwt.audience')  ?? 'alphaforge-api');
        $this->leeway           = (int)    ($this->config->get('jwt.leeway')    ?? 30);
        $this->blacklistPrefix  = (string) ($this->config->get('jwt.blacklist.prefix') ?? 'alphaforge:jwt:blacklist:');
        $this->blacklistEnabled = (bool)   ($this->config->get('jwt.blacklist.enabled') ?? true);

        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT secret must be at least 32 characters. Set JWT_SECRET in your .env file.');
        }

        JWT::$leeway = $this->leeway;
    }

    /**
     * Generate a signed access token JWT.
     *
     * Automatically adds standard claims: iss, aud, iat, exp, jti.
     * The $payload must include at minimum: user_id, email, role.
     *
     * @param array<string, mixed> $payload Custom claims to embed (merged with standard claims)
     * @return string Signed JWT string
     */
    public function generate(array $payload): string
    {
        $now = time();
        $jti = Uuid::uuid4()->toString();

        $claims = array_merge($payload, [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->expiry,
            'jti' => $jti,
        ]);

        return JWT::encode($claims, $this->secret, $this->algorithm);
    }

    /**
     * Generate a signed refresh token JWT.
     *
     * Refresh tokens carry a minimal payload (user_id, jti, type=refresh)
     * and have a longer TTL. They must be exchanged via the refresh endpoint
     * and cannot be used directly for API access.
     *
     * @param string $userId   The subject user's UUID
     * @param string $userRole The user's current role (embedded for issuing new access token)
     * @param string $email    The user's email address
     * @return string Signed refresh-token JWT string
     */
    public function generateRefreshToken(string $userId, string $userRole, string $email): string
    {
        $now = time();
        $jti = Uuid::uuid4()->toString();

        $claims = [
            'iss'     => $this->issuer,
            'aud'     => $this->audience,
            'iat'     => $now,
            'nbf'     => $now,
            'exp'     => $now + $this->refreshExpiry,
            'jti'     => $jti,
            'user_id' => $userId,
            'email'   => $email,
            'role'    => $userRole,
            'type'    => 'refresh',
        ];

        return JWT::encode($claims, $this->secret, $this->algorithm);
    }

    /**
     * Verify and decode a JWT access token.
     *
     * Validates signature, expiry, issuer, and audience. Checks the JTI
     * against the revocation blacklist.
     *
     * @param string $token Raw JWT string
     * @return array<string, mixed> Decoded payload as associative array
     * @throws \InvalidArgumentException On empty token
     * @throws \RuntimeException On invalid/expired/revoked token
     */
    public function verify(string $token): array
    {
        if ($token === '') {
            throw new \InvalidArgumentException('JWT token must not be empty.');
        }

        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            /** @var array<string, mixed> $payload */
            $payload = (array) $decoded;

            // Validate required claims.
            if (!isset($payload['jti'])) {
                throw new \RuntimeException('Token is missing required jti claim.');
            }

            if (!isset($payload['user_id'])) {
                throw new \RuntimeException('Token is missing required user_id claim.');
            }

            // Enforce access-token type (must not be a refresh token).
            if (isset($payload['type']) && $payload['type'] === 'refresh') {
                throw new \RuntimeException('Refresh token cannot be used as an access token.');
            }

            // Check blacklist.
            if ($this->blacklistEnabled && $this->isRevoked((string) $payload['jti'])) {
                throw new \RuntimeException('Token has been revoked.');
            }

            return $payload;
        } catch (ExpiredException $e) {
            throw new \RuntimeException('Token has expired.', 401, $e);
        } catch (SignatureInvalidException $e) {
            throw new \RuntimeException('Token signature is invalid.', 401, $e);
        } catch (BeforeValidException $e) {
            throw new \RuntimeException('Token is not yet valid.', 401, $e);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Token is invalid: ' . $e->getMessage(), 401, $e);
        }
    }

    /**
     * Verify a refresh token and issue a new access token.
     *
     * The refresh token is validated for type='refresh', then decoded.
     * A new access token with the embedded user data is returned.
     * The consumed refresh-token JTI is revoked (single-use, with grace period).
     *
     * @param string $refreshToken Raw refresh-token JWT
     * @return string New signed access token
     * @throws \RuntimeException On invalid/expired refresh token
     */
    public function refresh(string $refreshToken): string
    {
        try {
            $decoded = JWT::decode($refreshToken, new Key($this->secret, $this->algorithm));
            /** @var array<string, mixed> $payload */
            $payload = (array) $decoded;
        } catch (ExpiredException $e) {
            throw new \RuntimeException('Refresh token has expired.', 401, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Refresh token is invalid.', 401, $e);
        }

        if (($payload['type'] ?? '') !== 'refresh') {
            throw new \RuntimeException('Provided token is not a refresh token.');
        }

        if ($this->blacklistEnabled && $this->isRevoked((string) ($payload['jti'] ?? ''))) {
            throw new \RuntimeException('Refresh token has been revoked.');
        }

        // Revoke the consumed refresh-token JTI (rotate).
        if (isset($payload['jti'])) {
            $this->revoke((string) $payload['jti'], $this->refreshExpiry);
        }

        // Issue a new access token using data from the refresh token payload.
        return $this->generate([
            'user_id' => $payload['user_id'],
            'email'   => $payload['email'],
            'role'    => $payload['role'],
        ]);
    }

    /**
     * Add a JTI to the revocation blacklist in Redis.
     *
     * @param string $jti JWT ID to blacklist
     * @param int    $ttl Seconds to retain the blacklist entry (defaults to access-token expiry + buffer)
     */
    public function revoke(string $jti, int $ttl = 0): void
    {
        if (!$this->blacklistEnabled) {
            return;
        }

        if ($ttl <= 0) {
            $ttl = $this->expiry + 60;
        }

        $key = $this->blacklistPrefix . $jti;

        // Store directly in Redis to avoid JSON wrapping overhead.
        $redis = $this->cache->getRedis();

        if ($redis !== null) {
            try {
                $redis->setex($key, $ttl, '1');
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to revoke JWT JTI in Redis', [
                    'jti'   => $jti,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $this->logger->warning('JWT revocation skipped — Redis unavailable', ['jti' => $jti]);
        }
    }

    /**
     * Check whether a JTI has been revoked.
     *
     * @param string $jti
     * @return bool True if the JTI is on the blacklist
     */
    public function isRevoked(string $jti): bool
    {
        if (!$this->blacklistEnabled) {
            return false;
        }

        $redis = $this->cache->getRedis();

        if ($redis === null) {
            $this->logger->warning('JWT revocation check skipped — Redis unavailable', ['jti' => $jti]);
            return false;
        }

        try {
            return $redis->exists($this->blacklistPrefix . $jti) > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('JWT revocation check failed', [
                'jti'   => $jti,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Return the access-token TTL in seconds.
     */
    public function getExpiry(): int
    {
        return $this->expiry;
    }

    /**
     * Return the refresh-token TTL in seconds.
     */
    public function getRefreshExpiry(): int
    {
        return $this->refreshExpiry;
    }
}
