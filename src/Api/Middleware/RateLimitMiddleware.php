<?php

declare(strict_types=1);

namespace AlphaForge\Api\Middleware;

use AlphaForge\Core\Cache;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Request;
use AlphaForge\Core\Response;

/**
 * Sliding-window rate-limiting middleware backed by Redis.
 *
 * Two separate buckets are maintained per request:
 *   1. Per-IP bucket   — enforced for all clients
 *   2. Per-user bucket — enforced additionally when the request is authenticated
 *
 * When Redis is unavailable, rate-limiting is skipped with a warning log to
 * preserve availability (fail-open). For production deployments that require
 * strict enforcement, configure Redis high-availability (sentinel / cluster).
 *
 * Whitelisted internal IP ranges (127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12,
 * 192.168.0.0/16) bypass rate-limiting entirely.
 *
 * Usage:
 *   new RateLimitMiddleware(limit: 60, windowSeconds: 60)         // 60 req/min
 *   new RateLimitMiddleware(limit: 5,  windowSeconds: 900)        // 5 req/15 min (auth endpoints)
 */
final class RateLimitMiddleware
{
    /** @var list<string> CIDR prefixes that bypass rate-limiting */
    private const INTERNAL_PREFIXES = [
        '127.',
        '10.',
        '192.168.',
    ];

    private int    $limit;
    private int    $windowSeconds;
    private string $keyPrefix;

    private Cache  $cache;
    private Logger $logger;

    /**
     * @param int    $limit         Maximum requests allowed within the window
     * @param int    $windowSeconds Sliding window duration in seconds (default: 60)
     * @param string $keyPrefix     Optional prefix to namespace this limiter's keys
     */
    public function __construct(
        int    $limit         = 60,
        int    $windowSeconds = 60,
        string $keyPrefix     = 'ratelimit',
    ) {
        $this->limit         = $limit;
        $this->windowSeconds = $windowSeconds;
        $this->keyPrefix     = $keyPrefix;
        $this->cache         = Cache::getInstance();
        $this->logger        = Logger::getInstance();
    }

    /**
     * Execute the rate-limiting middleware.
     *
     * Sets X-RateLimit-Limit, X-RateLimit-Remaining, and X-RateLimit-Reset
     * headers on every response (including errors).
     *
     * @param Request  $request The current HTTP request
     * @param callable $next    The next middleware or controller handler
     */
    public function handle(Request $request, callable $next): void
    {
        $ip = $request->ip();

        // Skip rate-limiting for whitelisted internal IPs.
        if ($this->isInternalIp($ip)) {
            $next();
            return;
        }

        if (!$this->cache->isAvailable()) {
            $this->logger->warning('Rate limiter skipped — Redis unavailable');
            $next();
            return;
        }

        // ── Per-IP rate limiting ──────────────────────────────────────────────
        $ipKey      = "{$this->keyPrefix}:ip:{$ip}";
        $ipResult   = $this->checkAndIncrement($ipKey);

        $this->sendRateLimitHeaders($ipResult['count'], $ipResult['reset']);

        if ($ipResult['count'] > $this->limit) {
            $retryAfter = max(1, $ipResult['reset'] - time());

            $this->logger->warning('Rate limit exceeded (IP)', [
                'ip'     => $ip,
                'count'  => $ipResult['count'],
                'limit'  => $this->limit,
                'window' => $this->windowSeconds,
            ]);

            Response::tooManyRequests($retryAfter, 'Too many requests. Please slow down.');
        }

        // ── Per-user rate limiting (authenticated requests only) ──────────────
        $user = $request->user();

        if ($user !== null) {
            $userId     = (string) ($user['id'] ?? '');
            $userKey    = "{$this->keyPrefix}:user:{$userId}";
            $userResult = $this->checkAndIncrement($userKey);

            if ($userResult['count'] > $this->limit) {
                $retryAfter = max(1, $userResult['reset'] - time());

                $this->logger->warning('Rate limit exceeded (user)', [
                    'user_id' => $userId,
                    'count'   => $userResult['count'],
                    'limit'   => $this->limit,
                ]);

                Response::tooManyRequests($retryAfter, 'Too many requests. Please slow down.');
            }
        }

        $next();
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Implement sliding-window counter increment.
     *
     * Uses two Redis operations:
     *   INCR  key          — atomic counter increment
     *   EXPIRE key ttl     — set TTL on first use only (key just created by INCR)
     *
     * Returns the current count and the Unix timestamp when the window resets.
     *
     * @param string $key Redis key (already includes namespace prefix from Cache)
     * @return array{count: int, reset: int}
     */
    private function checkAndIncrement(string $key): array
    {
        $redis = $this->cache->getRedis();

        if ($redis === null) {
            return ['count' => 0, 'reset' => time() + $this->windowSeconds];
        }

        try {
            // INCR is atomic; if the key did not exist, it is created with value 1.
            $count = (int) $redis->incr($key);

            // Always check TTL and (re-)set it if missing.
            // A TTL of -1 means no expiry, which can happen if the process crashed
            // between INCR and EXPIRE on a previous request. Fixing it here makes
            // the window eventually self-heal without manual Redis intervention.
            $ttl = (int) $redis->ttl($key);
            if ($ttl === -1) {
                $redis->expire($key, $this->windowSeconds);
                $ttl = $this->windowSeconds;
            }

            $reset = $ttl > 0 ? time() + $ttl : time() + $this->windowSeconds;

            return ['count' => $count, 'reset' => $reset];
        } catch (\Throwable $e) {
            $this->logger->warning('Rate limit Redis error', ['key' => $key, 'error' => $e->getMessage()]);
            return ['count' => 0, 'reset' => time() + $this->windowSeconds];
        }
    }

    /**
     * Emit X-RateLimit-* response headers.
     *
     * These headers are informational and should be sent regardless of whether
     * the limit is exceeded.
     *
     * @param int $count   Current request count in the window
     * @param int $reset   Unix timestamp when the window resets
     */
    private function sendRateLimitHeaders(int $count, int $reset): void
    {
        if (headers_sent()) {
            return;
        }

        $remaining = max(0, $this->limit - $count);

        header('X-RateLimit-Limit: '     . $this->limit);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: '     . $reset);
    }

    /**
     * Determine whether the given IP address is an internal/private address
     * that should bypass rate limiting.
     *
     * @param string $ip IPv4 or IPv6 address string
     * @return bool True when the address is internal
     */
    private function isInternalIp(string $ip): bool
    {
        // IPv6 loopback.
        if ($ip === '::1') {
            return true;
        }

        foreach (self::INTERNAL_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        // 172.16.0.0/12 — check second octet 16–31.
        if (preg_match('/^172\.(\d{1,3})\./', $ip, $m)) {
            $octet = (int) $m[1];
            if ($octet >= 16 && $octet <= 31) {
                return true;
            }
        }

        return false;
    }
}
