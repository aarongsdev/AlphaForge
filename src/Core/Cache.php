<?php

declare(strict_types=1);

namespace AlphaForge\Core;

use Predis\Client as RedisClient;

/**
 * Redis-backed cache manager with graceful degradation.
 *
 * All keys are automatically prefixed with the application name to prevent
 * collisions in shared Redis instances. When Redis is unavailable, all
 * operations degrade gracefully: reads return null, writes return false,
 * and a single warning is logged to avoid flooding the log.
 */
final class Cache
{
    private static ?self $instance = null;

    private ?RedisClient $redis = null;
    private bool $available = false;
    private bool $degradedLogged = false;

    private string $prefix;

    private Config $config;
    private Logger $logger;

    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance();
        $this->prefix = (string) ($this->config->get('app.cache.prefix')
            ?? (strtolower((string) ($_ENV['APP_NAME'] ?? 'alphaforge')) . ':'));

        $this->connect();
    }

    /**
     * Returns the singleton Cache instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Retrieve a cached value by key.
     *
     * @param string $key Cache key (without prefix)
     * @return mixed Decoded value, or null if not found / Redis unavailable
     */
    public function get(string $key): mixed
    {
        if (!$this->available) {
            $this->logDegraded();
            return null;
        }

        try {
            $raw = $this->redis->get($this->prefixKey($key));

            if ($raw === null) {
                return null;
            }

            return json_decode((string) $raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->warning('Cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Store a value in the cache.
     *
     * @param string $key   Cache key (without prefix)
     * @param mixed  $value Any JSON-serialisable value
     * @param int    $ttl   Time-to-live in seconds (default 3600)
     * @return bool True on success, false on failure or Redis unavailable
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->available) {
            $this->logDegraded();
            return false;
        }

        try {
            $encoded = json_encode($value, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $result  = $this->redis->setex($this->prefixKey($key), $ttl, $encoded);

            return $result !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Cache set failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Remove a single key from the cache.
     *
     * @param string $key Cache key (without prefix)
     * @return bool True if the key was deleted, false otherwise
     */
    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            $deleted = $this->redis->del([$this->prefixKey($key)]);
            return $deleted > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('Cache delete failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Retrieve a value or compute and cache it if absent (cache-aside pattern).
     *
     * @param string   $key      Cache key (without prefix)
     * @param int      $ttl      Time-to-live in seconds
     * @param callable $callback Invoked with no arguments; return value is cached
     * @return mixed The cached or freshly computed value
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Delete all keys matching the given prefix, or all application keys when empty.
     *
     * Uses a SCAN-based approach to avoid blocking Redis with KEYS on large datasets.
     *
     * @param string $prefix Additional key prefix to narrow the flush scope
     */
    public function flush(string $prefix = ''): void
    {
        if (!$this->available) {
            return;
        }

        try {
            $pattern = $this->prefixKey($prefix) . '*';
            $cursor  = '0';

            do {
                /** @var array{0: string, 1: list<string>} $result */
                $result = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                $cursor = $result[0];
                $keys   = $result[1];

                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } while ($cursor !== '0');
        } catch (\Throwable $e) {
            $this->logger->warning('Cache flush failed', ['prefix' => $prefix, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Atomically increment an integer counter in the cache.
     *
     * If the key does not exist, it is created with value 0 before incrementing.
     * TTL is not applied automatically—use set() with a TTL to initialise a
     * counter with an expiry, then increment.
     *
     * @param string $key Cache key (without prefix)
     * @param int    $by  Amount to increment by (default 1)
     * @return int The new value after incrementing
     */
    public function increment(string $key, int $by = 1): int
    {
        if (!$this->available) {
            $this->logDegraded();
            return 0;
        }

        try {
            if ($by === 1) {
                return (int) $this->redis->incr($this->prefixKey($key));
            }

            return (int) $this->redis->incrby($this->prefixKey($key), $by);
        } catch (\Throwable $e) {
            $this->logger->warning('Cache increment failed', ['key' => $key, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Set a TTL on an existing key.
     *
     * @param string $key Cache key (without prefix)
     * @param int    $ttl Seconds until expiry
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            return (bool) $this->redis->expire($this->prefixKey($key), $ttl);
        } catch (\Throwable $e) {
            $this->logger->warning('Cache expire failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Return whether the Redis connection is active.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Expose the underlying Predis client for advanced operations.
     */
    public function getRedis(): ?RedisClient
    {
        return $this->available ? $this->redis : null;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function connect(): void
    {
        try {
            $host     = (string) ($_ENV['REDIS_HOST']     ?? '127.0.0.1');
            $port     = (int)    ($_ENV['REDIS_PORT']     ?? 6379);
            $password = (string) ($_ENV['REDIS_PASSWORD'] ?? '');
            $database = (int)    ($_ENV['REDIS_DATABASE'] ?? 0);

            $params = [
                'scheme'   => 'tcp',
                'host'     => $host,
                'port'     => $port,
                'database' => $database,
            ];

            if ($password !== '') {
                $params['password'] = $password;
            }

            $this->redis = new RedisClient($params, [
                'connections' => [
                    'tcp' => [
                        'read_write_timeout' => 2.0,
                        'timeout'            => 2.0,
                    ],
                ],
            ]);

            // Validate the connection is live.
            $this->redis->ping();
            $this->available = true;

            $this->logger->debug('Redis connection established', [
                'host'     => $host,
                'port'     => $port,
                'database' => $database,
            ]);
        } catch (\Throwable $e) {
            $this->available = false;
            $this->logger->warning('Redis connection failed — cache disabled', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    private function logDegraded(): void
    {
        if (!$this->degradedLogged) {
            $this->logger->warning('Cache is unavailable; operating without cache.');
            $this->degradedLogged = true;
        }
    }

    private function __clone(): void {}

    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize Cache singleton.');
    }
}
