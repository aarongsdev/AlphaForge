#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * AlphaForge Database Seeder
 *
 * Seeds the database with:
 *   - Reference data from database/migrations/013_seed_initial_data.sql
 *   - A default admin user (idempotent — skips creation if the user already exists)
 *
 * Usage:
 *   php database/seed.php
 *
 * Default admin credentials:
 *   Email   : admin@alphaforge.local
 *   Password: AlphaF0rge!Admin2025
 *
 * Exit codes:
 *   0  Success
 *   1  Failure
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

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        if (
            strlen($value) >= 2
            && (
                (str_starts_with($value, '"')  && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            )
        ) {
            $value = substr($value, 1, -1);
        }

        if (str_contains($value, ' #')) {
            $value = trim(explode(' #', $value, 2)[0]);
        }

        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function env(string $key, string $default = ''): string
{
    $val = getenv($key);
    return ($val !== false && $val !== '') ? $val : ($_ENV[$key] ?? $default);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);

loadEnv($projectRoot . '/.env');

out('');
out(clr('bold', 'AlphaForge Database Seeder'));
out(clr('bold', '=========================='));
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

    $pdo->exec(sprintf("SET search_path TO %s, public", $pdo->quote($schema)));

    out(clr('green', '[OK]') . ' Connected to PostgreSQL at ' . $host . ':' . $port . '/' . $dbname);
    out('');
} catch (PDOException $e) {
    err(clr('red', '[ERROR]') . ' Cannot connect to database: ' . $e->getMessage());
    exit(1);
}

// ── Run seed SQL file ─────────────────────────────────────────────────────────

$seedFile = $projectRoot . '/database/migrations/013_seed_initial_data.sql';

if (file_exists($seedFile)) {
    $sql = file_get_contents($seedFile);

    if ($sql !== false && trim($sql) !== '') {
        out('Executing: ' . basename($seedFile));

        try {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            $pdo->commit();

            out(clr('green', '[OK]') . ' Reference data seeded from ' . basename($seedFile));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            err(clr('red', '[ERROR]') . ' Failed to run seed SQL: ' . $e->getMessage());
            exit(1);
        }
    } else {
        out(clr('yellow', '[SKIP]') . ' Seed file is empty: ' . basename($seedFile));
    }

    out('');
} else {
    out(clr('yellow', '[WARN]') . ' Seed file not found: ' . $seedFile);
    out('         Skipping reference data — proceeding to admin user creation.');
    out('');
}

// ── Default admin user ────────────────────────────────────────────────────────

const ADMIN_EMAIL    = 'admin@alphaforge.local';
const ADMIN_PASSWORD = 'AlphaF0rge!Admin2025';
const ADMIN_USERNAME = 'admin';
const ADMIN_ROLE     = 'admin';

/**
 * Hash a password using Argon2id with hardened cost parameters matching
 * the settings defined in PasswordService and .env.example.
 */
function hashPassword(string $password): string
{
    $hash = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, // 64 MiB
        'time_cost'   => 4,
        'threads'     => 3,
    ]);

    if ($hash === false) {
        throw new \RuntimeException('password_hash() returned false — check PHP Argon2id support.');
    }

    return $hash;
}

out('Checking for existing admin user...');

try {
    // Check whether the admin user already exists.
    $check = $pdo->prepare("SELECT id, email FROM users WHERE email = :email LIMIT 1");
    $check->execute([':email' => ADMIN_EMAIL]);
    $existing = $check->fetch();

    if ($existing !== false) {
        out(clr('yellow', '[SKIP]') . ' Admin user already exists:');
        out('         ID    : ' . $existing['id']);
        out('         Email : ' . $existing['email']);
        out('');
        out('         No changes were made to the existing account.');
        out('');

        exit(0);
    }

    // Generate a UUID for the new user (uses gen_random_uuid() from pgcrypto
    // or uuid_generate_v4() from uuid-ossp, whichever is available).
    $uuidRow = $pdo->query("SELECT gen_random_uuid()::text AS id")->fetch();
    $userId  = $uuidRow['id'] ?? null;

    if ($userId === null) {
        // Fallback: generate UUID in PHP.
        $userId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    $hash = hashPassword(ADMIN_PASSWORD);

    $insert = $pdo->prepare(
        <<<'SQL'
        INSERT INTO users (
            id,
            email,
            username,
            password_hash,
            role,
            is_active,
            email_verified_at,
            created_at,
            updated_at
        ) VALUES (
            :id,
            :email,
            :username,
            :password_hash,
            :role,
            TRUE,
            NOW(),
            NOW(),
            NOW()
        )
        SQL
    );

    $insert->execute([
        ':id'            => $userId,
        ':email'         => ADMIN_EMAIL,
        ':username'      => ADMIN_USERNAME,
        ':password_hash' => $hash,
        ':role'          => ADMIN_ROLE,
    ]);

    out(clr('green', '[OK]') . ' Default admin user created successfully.');
    out('');
    out('  +-------------------------------------------------+');
    out('  |           DEFAULT ADMIN CREDENTIALS             |');
    out('  +-------------------------------------------------+');
    out('  |  Email    : ' . ADMIN_EMAIL);
    out('  |  Password : ' . ADMIN_PASSWORD);
    out('  |  Role     : ' . ADMIN_ROLE);
    out('  |  UUID     : ' . $userId);
    out('  +-------------------------------------------------+');
    out('');
    out(clr('yellow', '[IMPORTANT]') . ' Change the admin password immediately after first login.');
    out('');
} catch (PDOException $e) {
    $msg = $e->getMessage();

    // If the users table does not yet exist the migrations have not been run.
    if (str_contains($msg, 'relation "users" does not exist')
        || str_contains($msg, 'table "users" does not exist')
    ) {
        err(clr('red', '[ERROR]') . ' The users table does not exist.');
        err('         Run migrations first: php database/migrate.php');
    } else {
        err(clr('red', '[ERROR]') . ' Failed to create admin user: ' . $msg);
    }

    exit(1);
}

exit(0);
