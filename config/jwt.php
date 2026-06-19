<?php

declare(strict_types=1);

/**
 * AlphaForge JWT Configuration
 *
 * Configuration for JSON Web Token (JWT) authentication using
 * the firebase/php-jwt library. Supports HS256 and RS256 algorithms.
 *
 * For RS256, generate keys with:
 *   openssl genrsa -out private.pem 4096
 *   openssl rsa -in private.pem -pubout -out public.pem
 */

return [

    // -------------------------------------------------------------------------
    // Algorithm
    // -------------------------------------------------------------------------
    // Supported symmetric:  HS256, HS384, HS512
    // Supported asymmetric: RS256, RS384, RS512, ES256, ES384, ES512

    'algorithm' => (string) ($_ENV['JWT_ALGORITHM'] ?? 'HS256'),

    // -------------------------------------------------------------------------
    // HMAC Secret (HS256/HS384/HS512)
    // -------------------------------------------------------------------------
    // Must be at least 64 bytes (128 hex chars) for HS256.
    // Generate: php -r "echo bin2hex(random_bytes(64));"

    'secret' => (string) ($_ENV['JWT_SECRET'] ?? ''),

    // -------------------------------------------------------------------------
    // RSA Keys (RS256/RS384/RS512) — leave empty when using HMAC
    // -------------------------------------------------------------------------

    'private_key' => (string) ($_ENV['JWT_PRIVATE_KEY'] ?? ''),
    'public_key'  => (string) ($_ENV['JWT_PUBLIC_KEY'] ?? ''),

    // Private key passphrase (if key is encrypted)
    'private_key_passphrase' => (string) ($_ENV['JWT_PRIVATE_KEY_PASSPHRASE'] ?? ''),

    // -------------------------------------------------------------------------
    // Access Token Settings
    // -------------------------------------------------------------------------

    'expiry' => (int) ($_ENV['JWT_EXPIRY'] ?? 3600),  // seconds (default: 1 hour)

    // Token issuer (iss claim)
    'issuer' => (string) ($_ENV['JWT_ISSUER'] ?? 'alphaforge'),

    // Intended audience (aud claim)
    'audience' => (string) ($_ENV['JWT_AUDIENCE'] ?? 'alphaforge-api'),

    // Number of seconds to tolerate clock skew between servers
    'leeway' => 30,

    // -------------------------------------------------------------------------
    // Refresh Token Settings
    // -------------------------------------------------------------------------

    'refresh' => [
        // Refresh token TTL in seconds (default: 7 days)
        'expiry' => (int) ($_ENV['JWT_REFRESH_EXPIRY'] ?? 604800),

        // Store refresh tokens in database for revocation support
        'store_in_db' => true,

        // Allow refresh token rotation (each use issues a new refresh token)
        'rotate' => true,

        // Maximum number of active refresh tokens per user
        'max_per_user' => 5,

        // Grace period in seconds during which old refresh token remains valid
        // after rotation (handles race conditions with parallel requests)
        'rotation_grace_period' => 10,
    ],

    // -------------------------------------------------------------------------
    // Claims
    // -------------------------------------------------------------------------

    'claims' => [
        // Include user role in token payload
        'include_role' => true,

        // Include subscription tier in token payload
        'include_tier' => true,

        // Include permissions array in token payload
        'include_permissions' => false,

        // Custom claim namespace (to avoid collisions)
        'namespace' => 'https://alphaforge.io',
    ],

    // -------------------------------------------------------------------------
    // Security
    // -------------------------------------------------------------------------

    // Reject tokens without an explicit expiry claim
    'require_expiry' => true,

    // Require the 'iat' (issued at) claim
    'require_issued_at' => true,

    // Token blacklist / revocation support via Redis
    'blacklist' => [
        'enabled'    => true,
        'driver'     => 'redis',
        'prefix'     => 'alphaforge:jwt:blacklist:',
        // How long to keep blacklisted token JTIs in Redis.
        // Should be >= access token expiry to prevent reuse.
        'ttl'        => (int) ($_ENV['JWT_EXPIRY'] ?? 3600) + 60,
    ],

    // -------------------------------------------------------------------------
    // Cookie Settings (when tokens are delivered via HTTP-only cookies)
    // -------------------------------------------------------------------------

    'cookie' => [
        'enabled'   => false,
        'name'      => 'alphaforge_token',
        'secure'    => true,
        'http_only' => true,
        'same_site' => 'Strict',
        'path'      => '/',
        'domain'    => (string) ($_ENV['COOKIE_DOMAIN'] ?? ''),
    ],

    // -------------------------------------------------------------------------
    // Header Settings (when tokens are delivered via Authorization header)
    // -------------------------------------------------------------------------

    'header' => [
        'name'   => 'Authorization',
        'prefix' => 'Bearer',
    ],

];
