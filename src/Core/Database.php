<?php

declare(strict_types=1);

namespace AlphaForge\Core;

use Ramsey\Uuid\Uuid;

/**
 * PDO database connection manager with connection-retry logic and slow-query logging.
 *
 * Maintains a single PDO connection per named connection per request (simulated
 * connection pool). Retry logic attempts up to 3 connections with exponential
 * back-off before throwing. Slow queries (>100 ms by default, configurable via
 * 'database.query_log.slow_threshold_ms') are logged via the Logger singleton.
 */
final class Database
{
    private static ?self $instance = null;

    /** @var array<string, \PDO> */
    private array $connections = [];

    private Config $config;
    private Logger $logger;

    private int $slowThresholdMs;
    private bool $queryLogEnabled;

    private bool $inTransaction = false;

    private function __construct()
    {
        $this->config          = Config::getInstance();
        $this->logger          = Logger::getInstance();
        $this->slowThresholdMs = (int) ($this->config->get('database.query_log.slow_threshold_ms') ?? 100);
        $this->queryLogEnabled = (bool) ($this->config->get('database.query_log.enabled') ?? false);
    }

    /**
     * Returns the singleton Database instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Returns an active PDO connection for the given named connection.
     *
     * Attempts up to 3 times with exponential back-off (100 ms, 200 ms)
     * before propagating the last PDOException.
     *
     * @param string $name Named connection from config/database.php (default: 'pgsql')
     * @throws \PDOException When all connection attempts fail
     */
    public function getConnection(string $name = ''): \PDO
    {
        if ($name === '') {
            $name = (string) ($this->config->get('database.default') ?? 'pgsql');
        }

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $lastException = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $this->connections[$name] = $this->createConnection($name);
                return $this->connections[$name];
            } catch (\PDOException $e) {
                $lastException = $e;
                $this->logger->warning('Database connection attempt failed', [
                    'connection' => $name,
                    'attempt'    => $attempt,
                    'error'      => $e->getMessage(),
                ]);

                if ($attempt < 3) {
                    // Exponential back-off: 100 ms, 200 ms
                    usleep($attempt * 100_000);
                }
            }
        }

        throw new \PDOException(
            "Failed to connect to database '{$name}' after 3 attempts: " . $lastException?->getMessage(),
            (int) ($lastException?->getCode() ?? 0),
            $lastException,
        );
    }

    /**
     * Prepare and execute a parameterised SQL statement.
     *
     * @param string               $sql    The SQL query (must use ? or :named placeholders)
     * @param array<int|string, mixed> $params Bound parameter values
     * @throws \PDOException On query execution failure
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $pdo  = $this->getConnection();
        $stmt = $pdo->prepare($sql);

        $start = hrtime(true);
        $stmt->execute($params);
        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms

        if ($elapsed >= $this->slowThresholdMs) {
            $this->logger->slowQuery($sql, $elapsed, ['params_count' => count($params)]);
        } elseif ($this->queryLogEnabled) {
            $this->logger->debug('Query executed', [
                'sql'         => mb_substr($sql, 0, 500),
                'duration_ms' => round($elapsed, 3),
            ]);
        }

        return $stmt;
    }

    /**
     * Execute a query and return the first matching row or null.
     *
     * @param string               $sql
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);

        return ($row !== false) ? $row : null;
    }

    /**
     * Execute a query and return all matching rows.
     *
     * @param string               $sql
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Insert a row into the given table.
     *
     * A UUID v4 is generated for the `id` column if none is provided.
     *
     * @param string               $table The target table name (not user-supplied; controlled by code)
     * @param array<string, mixed> $data  Column-to-value map
     * @return string The UUID of the inserted row
     * @throws \PDOException On insert failure
     */
    public function insert(string $table, array $data): string
    {
        $id = isset($data['id']) ? (string) $data['id'] : Uuid::uuid4()->toString();

        $data['id'] = $id;

        $columns      = array_keys($data);
        $placeholders = array_map(static fn(string $col) => ':' . $col, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders),
        );

        $this->query($sql, $data);

        return $id;
    }

    /**
     * Update rows in the given table.
     *
     * @param string               $table The target table name
     * @param array<string, mixed> $data  Columns to update
     * @param array<string, mixed> $where WHERE conditions (ANDed together)
     * @return int Number of affected rows
     * @throws \InvalidArgumentException When $data or $where is empty
     */
    public function update(string $table, array $data, array $where): int
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Update $data must not be empty.');
        }

        if (empty($where)) {
            throw new \InvalidArgumentException('Update $where must not be empty (unsafe full-table update refused).');
        }

        $setClauses   = [];
        $whereClauses = [];
        $params       = [];

        foreach ($data as $col => $val) {
            $paramKey          = 'set_' . $col;
            $setClauses[]      = $this->quoteIdentifier($col) . ' = :' . $paramKey;
            $params[$paramKey] = $val;
        }

        foreach ($where as $col => $val) {
            $paramKey           = 'where_' . $col;
            $whereClauses[]     = $this->quoteIdentifier($col) . ' = :' . $paramKey;
            $params[$paramKey]  = $val;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses),
        );

        $stmt = $this->query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Delete rows from the given table.
     *
     * @param string               $table The target table name
     * @param array<string, mixed> $where WHERE conditions (ANDed together)
     * @return int Number of deleted rows
     * @throws \InvalidArgumentException When $where is empty
     */
    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            throw new \InvalidArgumentException('Delete $where must not be empty (unsafe full-table delete refused).');
        }

        $whereClauses = [];
        $params       = [];

        foreach ($where as $col => $val) {
            $paramKey          = 'w_' . $col;
            $whereClauses[]    = $this->quoteIdentifier($col) . ' = :' . $paramKey;
            $params[$paramKey] = $val;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(' AND ', $whereClauses),
        );

        $stmt = $this->query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Begin a database transaction.
     *
     * @throws \RuntimeException When already inside a transaction
     */
    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new \RuntimeException('A transaction is already active.');
        }

        $this->getConnection()->beginTransaction();
        $this->inTransaction = true;
    }

    /**
     * Commit the active transaction.
     *
     * @throws \RuntimeException When no transaction is active
     */
    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException('No active transaction to commit.');
        }

        $this->getConnection()->commit();
        $this->inTransaction = false;
    }

    /**
     * Roll back the active transaction.
     *
     * @throws \RuntimeException When no transaction is active
     */
    public function rollback(): void
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException('No active transaction to roll back.');
        }

        $this->getConnection()->rollBack();
        $this->inTransaction = false;
    }

    /**
     * Return whether a transaction is currently active.
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build a new PDO connection from the named config block.
     *
     * @throws \PDOException
     * @throws \InvalidArgumentException When the named connection config is missing
     */
    private function createConnection(string $name): \PDO
    {
        /** @var array<string, mixed>|null $cfg */
        $cfg = $this->config->get("database.connections.{$name}");

        if (!is_array($cfg)) {
            throw new \InvalidArgumentException("No database connection config found for '{$name}'.");
        }

        $driver   = (string) ($cfg['driver']   ?? 'pgsql');
        $host     = (string) ($cfg['host']      ?? 'localhost');
        $port     = (int)    ($cfg['port']       ?? 5432);
        $database = (string) ($cfg['database']  ?? '');
        $username = (string) ($cfg['username']  ?? '');
        $password = (string) ($cfg['password']  ?? '');
        $charset  = (string) ($cfg['charset']   ?? 'utf8');
        $schema   = (string) ($cfg['schema']    ?? '');
        $sslmode  = (string) ($cfg['sslmode']   ?? 'prefer');

        $dsn = match ($driver) {
            'pgsql'  => "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}",
            'mysql'  => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            'sqlite' => "sqlite:{$database}",
            default  => throw new \InvalidArgumentException("Unsupported PDO driver '{$driver}'."),
        };

        /** @var array<int, mixed> $options */
        $options = is_array($cfg['options'] ?? null) ? $cfg['options'] : [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        // Ensure critical options are always set.
        $options[\PDO::ATTR_ERRMODE]            = \PDO::ERRMODE_EXCEPTION;
        $options[\PDO::ATTR_DEFAULT_FETCH_MODE] = \PDO::FETCH_ASSOC;
        $options[\PDO::ATTR_EMULATE_PREPARES]   = false;

        $pdo = new \PDO($dsn, $username, $password, $options);

        // Set search_path for PostgreSQL schema scoping.
        if ($driver === 'pgsql' && $schema !== '') {
            $pdo->exec("SET search_path TO " . $pdo->quote($schema) . ", public");
        }

        // Apply statement timeout (pg-specific advisory).
        if ($driver === 'pgsql') {
            $stmtTimeout = (int) ($cfg['statement_timeout'] ?? 30000);
            $pdo->exec("SET statement_timeout = {$stmtTimeout}");
        }

        $this->logger->debug('Database connection established', [
            'connection' => $name,
            'driver'     => $driver,
            'host'       => $host,
            'database'   => $database,
        ]);

        return $pdo;
    }

    /**
     * Quote an identifier (table or column name) to prevent SQL injection
     * from internal identifier construction.
     *
     * Uses double-quotes (ANSI SQL / PostgreSQL convention).
     */
    private function quoteIdentifier(string $identifier): string
    {
        // Strip any existing double-quotes and re-wrap.
        return '"' . str_replace('"', '', $identifier) . '"';
    }

    private function __clone(): void {}

    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize Database singleton.');
    }
}
