#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * AlphaForge Database Migration Runner
 *
 * Usage:
 *   php database/migrate.php               Run all pending migrations
 *   php database/migrate.php --status      Show migration status (ran / pending)
 *   php database/migrate.php --rollback    Remove the last migration record (no SQL reversal)
 *
 * Exit codes:
 *   0  All requested operations succeeded
 *   1  One or more errors occurred
 */

// ── ANSI colour helpers ───────────────────────────────────────────────────────

function clr(string $colour, string $text): string
{
    $codes = [
        'green'  => "\033[0;32m",
        'red'    => "\033[0;31m",
        'yellow' => "\033[0;33m",
        'cyan'   => "\033[0;36m",
        'bold'   => "\033[1m",
        'reset'  => "\033[0m",
    ];

    // Disable colour codes when not running in a real TTY.
    if (!stream_isatty(STDOUT)) {
        return $text;
    }

    return ($codes[$colour] ?? '') . $text . $codes['reset'];
}

function out(string $message): void
{
    echo $message . PHP_EOL;
}

function err(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

// ── Environment loading ───────────────────────────────────────────────────────

/**
 * Load a .env file by parsing KEY=VALUE lines (supports quoted values and
 * inline comments). Does NOT override variables already set in the environment
 * so that Docker / CI injected values take precedence.
 */
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and blank lines.
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Must contain an equals sign.
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip surrounding quotes (single or double).
        if (
            strlen($value) >= 2
            && (
                (str_starts_with($value, '"')  && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            )
        ) {
            $value = substr($value, 1, -1);
        }

        // Strip inline comments (value part after unquoted '#').
        if (str_contains($value, ' #')) {
            $value = trim(explode(' #', $value, 2)[0]);
        }

        // Do not override environment variables already set by the shell/Docker.
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function env(string $key, string $default = ''): string
{
    $val = getenv($key);
    return ($val !== false && $val !== '') ? $val : ($_ENV[$key] ?? $default);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

// Locate project root (one level above this file's directory).
$projectRoot = dirname(__DIR__);

// Try to load .env from the project root.
loadEnv($projectRoot . '/.env');

// Parse CLI flags.
$flags    = array_slice($argv ?? [], 1);
$doStatus   = in_array('--status',   $flags, true);
$doRollback = in_array('--rollback', $flags, true);

out('');
out(clr('bold', 'AlphaForge Database Migration Runner'));
out(clr('bold', '====================================='));
out('');

// ── Database connection ───────────────────────────────────────────────────────

$host     = env('DB_HOST',     'localhost');
$port     = env('DB_PORT',     '5432');
$dbname   = env('DB_DATABASE', 'alphaforge_db');
$username = env('DB_USERNAME', 'alphaforge');
$password = env('DB_PASSWORD', '');
$schema   = env('DB_SCHEMA',   'public');

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $host,
    $port,
    $dbname,
);

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Set the search_path to the configured schema.
    $pdo->exec(sprintf("SET search_path TO %s, public", $pdo->quote($schema)));

    out(clr('green', '[OK]') . ' Connected to PostgreSQL at ' . $host . ':' . $port . '/' . $dbname);
    out('');
} catch (PDOException $e) {
    err(clr('red', '[ERROR]') . ' Cannot connect to database: ' . $e->getMessage());
    err('');
    err('Check that DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD are set correctly in .env');
    exit(1);
}

// ── Ensure the tracking table exists ─────────────────────────────────────────

try {
    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id           SERIAL      PRIMARY KEY,
            migration    VARCHAR(255) NOT NULL UNIQUE,
            executed_at  TIMESTAMP   NOT NULL DEFAULT NOW()
        )
        SQL
    );
} catch (PDOException $e) {
    err(clr('red', '[ERROR]') . ' Failed to create schema_migrations table: ' . $e->getMessage());
    exit(1);
}

// ── Load already-ran migrations ───────────────────────────────────────────────

/**
 * @return array<string, string>  migration filename => executed_at timestamp
 */
function loadExecuted(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT migration, executed_at FROM schema_migrations ORDER BY id ASC");
    $rows = $stmt->fetchAll();

    $executed = [];
    foreach ($rows as $row) {
        $executed[$row['migration']] = $row['executed_at'];
    }

    return $executed;
}

// ── Discover migration files ──────────────────────────────────────────────────

$migrationsDir = $projectRoot . '/database/migrations';

if (!is_dir($migrationsDir)) {
    err(clr('red', '[ERROR]') . ' Migrations directory not found: ' . $migrationsDir);
    exit(1);
}

$files = glob($migrationsDir . '/*.sql');

if ($files === false || $files === []) {
    out(clr('yellow', '[WARN]') . ' No migration files found in ' . $migrationsDir);
    exit(0);
}

// Sort by filename (alphabetical = chronological given the NNN_ prefix).
sort($files);

// ── --status mode ─────────────────────────────────────────────────────────────

if ($doStatus) {
    $executed = loadExecuted($pdo);

    $ranCount     = 0;
    $pendingCount = 0;

    out(sprintf('%-50s  %-10s  %s', 'Migration', 'Status', 'Executed At'));
    out(str_repeat('-', 80));

    foreach ($files as $file) {
        $name = basename($file);

        if (isset($executed[$name])) {
            out(sprintf(
                '%-50s  %s  %s',
                $name,
                clr('green', 'ran     '),
                $executed[$name],
            ));
            $ranCount++;
        } else {
            out(sprintf(
                '%-50s  %s',
                $name,
                clr('yellow', 'pending '),
            ));
            $pendingCount++;
        }
    }

    out('');
    out(sprintf(
        '%s ran, %s pending.',
        clr('green', (string) $ranCount),
        clr('yellow', (string) $pendingCount),
    ));
    out('');

    exit(0);
}

// ── --rollback mode ───────────────────────────────────────────────────────────

if ($doRollback) {
    $stmt = $pdo->query(
        "SELECT id, migration, executed_at FROM schema_migrations ORDER BY id DESC LIMIT 1"
    );
    $last = $stmt->fetch();

    if ($last === false) {
        out(clr('yellow', '[WARN]') . ' No migrations to roll back.');
        exit(0);
    }

    out(clr('yellow', '[WARN]') . ' Rolling back record only (no SQL reversal):');
    out('       ' . $last['migration'] . ' (executed at ' . $last['executed_at'] . ')');
    out('');
    out('       This removes the tracking record so the migration will re-run next time.');
    out('       It does NOT execute any DOWN SQL. Manual schema cleanup may be required.');
    out('');

    $del = $pdo->prepare("DELETE FROM schema_migrations WHERE id = :id");
    $del->execute([':id' => $last['id']]);

    out(clr('green', '[OK]') . ' Rollback record removed for: ' . $last['migration']);
    out('');

    exit(0);
}

// ── Normal migration run ──────────────────────────────────────────────────────

$executed   = loadExecuted($pdo);
$applied    = 0;
$skipped    = 0;
$failed     = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (isset($executed[$name])) {
        $skipped++;
        continue;
    }

    $sql = file_get_contents($file);

    if ($sql === false || trim($sql) === '') {
        out(clr('yellow', '[SKIP]') . ' ' . $name . ' (empty file)');
        $skipped++;
        continue;
    }

    // Run each migration inside a transaction so partial failures are rolled back.
    try {
        $pdo->beginTransaction();

        $pdo->exec($sql);

        $insert = $pdo->prepare(
            "INSERT INTO schema_migrations (migration, executed_at) VALUES (:migration, NOW())"
        );
        $insert->execute([':migration' => $name]);

        $pdo->commit();

        out(clr('green', '[OK]') . ' ' . $name);
        $applied++;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        err(clr('red', '[FAIL]') . ' ' . $name);
        err('         ' . $e->getMessage());
        $failed++;

        // Abort on first failure — subsequent migrations may depend on this one.
        break;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────

out('');

if ($failed > 0) {
    out(clr('red', '[ERROR]') . sprintf(
        ' Migration aborted. %d applied, %d skipped, %d failed.',
        $applied,
        $skipped,
        $failed,
    ));
    out('');
    exit(1);
}

if ($applied === 0) {
    out(clr('green', '[OK]') . ' All migrations are already up to date (' . $skipped . ' skipped).');
} else {
    out(clr('green', '[OK]') . sprintf(
        ' %d migration%s applied successfully%s.',
        $applied,
        $applied === 1 ? '' : 's',
        $skipped > 0 ? sprintf(' (%d already ran)', $skipped) : '',
    ));
}

out('');
exit(0);
