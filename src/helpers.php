<?php

declare(strict_types=1);

/**
 * AlphaForge Global Helper Functions
 *
 * Loaded automatically via composer.json `autoload.files`. Provides
 * convenience wrappers for the most common framework operations.
 * All functions delegate to the corresponding singleton/service; they
 * do NOT duplicate logic.
 */

use AlphaForge\Core\Cache;
use AlphaForge\Core\Config;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Response;

if (!function_exists('config')) {
    /**
     * Retrieve a configuration value using dot-notation.
     *
     * @param string $key     Dot-notation config key (e.g. 'app.name')
     * @param mixed  $default Default value when the key is absent
     * @return mixed
     */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::getInstance()->get($key, $default);
    }
}

if (!function_exists('cache')) {
    /**
     * Retrieve a value from the cache, or store and return a computed value.
     *
     * When called with only a key, behaves as a simple get (returns null if missing).
     * When called with a callable $value, wraps Cache::remember().
     *
     * @param string        $key     Cache key
     * @param callable|null $value   Optional callback whose return value is cached
     * @param int           $ttl     Seconds to cache when a callback is provided
     * @return mixed
     */
    function cache(string $key, ?callable $value = null, int $ttl = 3600): mixed
    {
        $cache = Cache::getInstance();

        if ($value !== null) {
            return $cache->remember($key, $ttl, $value);
        }

        return $cache->get($key);
    }
}

if (!function_exists('logger')) {
    /**
     * Return the application Logger singleton.
     *
     * When called with a message argument, logs at INFO level for convenience.
     *
     * @param string|null          $message Optional message to log at INFO level
     * @param array<string, mixed> $context Optional log context
     * @return Logger
     */
    function logger(?string $message = null, array $context = []): Logger
    {
        $log = Logger::getInstance();

        if ($message !== null) {
            $log->info($message, $context);
        }

        return $log;
    }
}

if (!function_exists('now')) {
    /**
     * Return the current UTC date-time as a formatted string.
     *
     * @param string $format PHP date() format (default: ISO 8601 with timezone)
     * @return string
     */
    function now(string $format = 'Y-m-d H:i:sP'): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format($format);
    }
}

if (!function_exists('env')) {
    /**
     * Retrieve an environment variable value with an optional default.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true',  '(true)'  => true,
            'false', '(false)' => false,
            'null',  '(null)'  => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('abort')) {
    /**
     * Emit an error JSON response and terminate script execution.
     *
     * @param int    $status  HTTP status code
     * @param string $message Human-readable error message
     * @return never
     */
    function abort(int $status = 400, string $message = 'Bad Request'): never
    {
        Response::error($message, $status);
    }
}

if (!function_exists('uuid4')) {
    /**
     * Generate a random UUID v4 string.
     *
     * @return string e.g. '550e8400-e29b-41d4-a716-446655440000'
     */
    function uuid4(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }
}

if (!function_exists('sanitize_string')) {
    /**
     * Strip HTML tags and trim whitespace from a string.
     *
     * Intended for display-layer sanitisation only. Never use this as a
     * substitute for parameterised queries — always use PDO prepared statements.
     *
     * @param string $value
     * @return string
     */
    function sanitize_string(string $value): string
    {
        return trim(strip_tags($value));
    }
}
