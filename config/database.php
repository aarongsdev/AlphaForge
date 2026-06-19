<?php

declare(strict_types=1);

/**
 * AlphaForge Database Configuration
 *
 * Supports multiple named connections. The 'default' key determines which
 * connection is used when no explicit connection is specified.
 *
 * Connection pooling values are advisory for application-level poolers
 * (e.g. PgBouncer); PHP-FPM itself does not maintain persistent pools.
 */

return [

    // -------------------------------------------------------------------------
    // Default Connection
    // -------------------------------------------------------------------------

    'default' => (string) ($_ENV['DB_CONNECTION'] ?? 'pgsql'),

    // -------------------------------------------------------------------------
    // Named Connections
    // -------------------------------------------------------------------------

    'connections' => [

        'pgsql' => [
            'driver'         => 'pgsql',
            'host'           => (string) ($_ENV['DB_HOST'] ?? 'postgres'),
            'port'           => (int) ($_ENV['DB_PORT'] ?? 5432),
            'database'       => (string) ($_ENV['DB_DATABASE'] ?? 'alphaforge_db'),
            'username'       => (string) ($_ENV['DB_USERNAME'] ?? 'alphaforge'),
            'password'       => (string) ($_ENV['DB_PASSWORD'] ?? ''),
            'charset'        => 'utf8',
            'schema'         => (string) ($_ENV['DB_SCHEMA'] ?? 'alphaforge'),
            'sslmode'        => (string) ($_ENV['DB_SSLMODE'] ?? 'prefer'),

            // Optional SSL client certificates (leave empty to disable)
            'sslcert'        => (string) ($_ENV['DB_SSLCERT'] ?? ''),
            'sslkey'         => (string) ($_ENV['DB_SSLKEY'] ?? ''),
            'sslrootcert'    => (string) ($_ENV['DB_SSLROOTCERT'] ?? ''),

            // Timeout settings (milliseconds)
            'connect_timeout'   => (int) ($_ENV['DB_CONNECTION_TIMEOUT'] ?? 5000),
            'statement_timeout' => (int) ($_ENV['DB_STATEMENT_TIMEOUT'] ?? 30000),

            // PDO options
            'options' => [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_STRINGIFY_FETCHES  => false,
                \PDO::ATTR_TIMEOUT            => 5,
            ],
        ],

        // Read replica (optional — configure when read-scaling is needed)
        'pgsql_read' => [
            'driver'      => 'pgsql',
            'host'        => (string) ($_ENV['DB_READ_HOST'] ?? $_ENV['DB_HOST'] ?? 'postgres'),
            'port'        => (int) ($_ENV['DB_READ_PORT'] ?? $_ENV['DB_PORT'] ?? 5432),
            'database'    => (string) ($_ENV['DB_DATABASE'] ?? 'alphaforge_db'),
            'username'    => (string) ($_ENV['DB_READ_USERNAME'] ?? $_ENV['DB_USERNAME'] ?? 'alphaforge'),
            'password'    => (string) ($_ENV['DB_READ_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? ''),
            'charset'     => 'utf8',
            'schema'      => (string) ($_ENV['DB_SCHEMA'] ?? 'alphaforge'),
            'sslmode'     => (string) ($_ENV['DB_SSLMODE'] ?? 'prefer'),

            'connect_timeout'   => (int) ($_ENV['DB_CONNECTION_TIMEOUT'] ?? 5000),
            'statement_timeout' => (int) ($_ENV['DB_STATEMENT_TIMEOUT'] ?? 30000),

            'options' => [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_STRINGIFY_FETCHES  => false,
                \PDO::ATTR_TIMEOUT            => 5,
            ],
        ],

    ],

    // -------------------------------------------------------------------------
    // Connection Pool Settings
    // -------------------------------------------------------------------------
    // Used by application-level pool managers or health monitoring.
    // PHP-FPM workers do not share persistent connections, so these values
    // drive PgBouncer or similar sidecar configuration.

    'pool' => [
        'min_connections'     => (int) ($_ENV['DB_POOL_MIN'] ?? 5),
        'max_connections'     => (int) ($_ENV['DB_POOL_MAX'] ?? 20),
        'idle_timeout_ms'     => (int) ($_ENV['DB_POOL_IDLE_TIMEOUT'] ?? 10000),
        'max_lifetime_s'      => 3600,
        'health_check_freq_s' => 30,
    ],

    // -------------------------------------------------------------------------
    // Migration Settings
    // -------------------------------------------------------------------------

    'migrations' => [
        'table'  => 'schema_migrations',
        'schema' => (string) ($_ENV['DB_SCHEMA'] ?? 'alphaforge'),
        'path'   => __DIR__ . '/../database/migrations',
    ],

    // -------------------------------------------------------------------------
    // Query Logging
    // -------------------------------------------------------------------------

    'query_log' => [
        'enabled'           => filter_var($_ENV['DB_QUERY_LOG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'slow_threshold_ms' => (int) ($_ENV['DB_SLOW_QUERY_MS'] ?? 1000),
        'channel'           => 'database',
    ],

];
