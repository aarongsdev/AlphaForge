<?php

declare(strict_types=1);

namespace AlphaForge\Core;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\WebProcessor;

/**
 * Singleton application logger backed by Monolog.
 *
 * Writes daily-rotated JSON log files in production and human-readable
 * line-formatted logs in development. All log records are automatically
 * enriched with the current request ID, user ID, and client IP address
 * when those values are available on the Request object.
 */
final class Logger
{
    private static ?self $instance = null;

    private MonologLogger $logger;

    /** Context carried across all log calls during a request. */
    private array $globalContext = [];

    private function __construct()
    {
        $config   = Config::getInstance();
        $env      = (string) ($config->get('app.env') ?? $_ENV['APP_ENV'] ?? 'production');
        $logLevel = $this->resolveLevel((string) ($config->get('app.logging.level') ?? $_ENV['LOG_LEVEL'] ?? 'warning'));
        $maxFiles = (int) ($config->get('app.logging.max_files') ?? $_ENV['LOG_MAX_FILES'] ?? 30);
        $logDir   = dirname(__DIR__, 2) . '/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $this->logger = new MonologLogger('alphaforge');

        // ── Application log (daily rotation) ─────────────────────────────────
        $appHandler = new RotatingFileHandler(
            filename:   $logDir . '/app.log',
            maxFiles:   $maxFiles,
            level:      $logLevel,
            bubble:     true,
            filePermission: 0644,
        );

        // ── Error/critical log (dedicated file for fast alerting) ─────────────
        $errorHandler = new RotatingFileHandler(
            filename:   $logDir . '/error.log',
            maxFiles:   $maxFiles,
            level:      Level::Error,
            bubble:     false,
            filePermission: 0644,
        );

        if ($env === 'production') {
            $formatter = new JsonFormatter(
                batchMode:      JsonFormatter::BATCH_MODE_NEWLINES,
                appendNewline:  true,
                ignoreEmptyContextAndExtra: false,
                includeStacktraces: true,
            );
            $appHandler->setFormatter($formatter);
            $errorHandler->setFormatter($formatter);
        } else {
            $formatter = new LineFormatter(
                format:    "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                dateFormat: 'Y-m-d H:i:s.u',
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true,
            );
            $formatter->includeStacktraces();
            $appHandler->setFormatter($formatter);
            $errorHandler->setFormatter($formatter);
        }

        $this->logger->pushHandler($appHandler);
        $this->logger->pushHandler($errorHandler);

        // ── Processors: add class/method introspection + memory usage ─────────
        $this->logger->pushProcessor(new MemoryUsageProcessor(realUsage: true, useFormatting: false));
        $this->logger->pushProcessor(new IntrospectionProcessor(level: Level::Warning, skipClassesPartials: ['AlphaForge\\Core\\Logger']));

        // ── Inline processor: inject global context ───────────────────────────
        $this->logger->pushProcessor(function (array $record): array {
            $record['context'] = array_merge($this->globalContext, $record['context']);
            return $record;
        });
    }

    /**
     * Returns the singleton Logger instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Set context fields that will be merged into every subsequent log record
     * for the lifetime of the current request.
     *
     * Common keys: request_id, user_id, ip, user_agent.
     *
     * @param array<string, mixed> $context
     */
    public function setGlobalContext(array $context): void
    {
        $this->globalContext = array_merge($this->globalContext, $context);
    }

    /**
     * Log a DEBUG-level message.
     *
     * @param string               $message Human-readable log message
     * @param array<string, mixed> $context Additional structured context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    /**
     * Log an INFO-level message.
     *
     * @param string               $message Human-readable log message
     * @param array<string, mixed> $context Additional structured context
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * Log a WARNING-level message.
     *
     * @param string               $message Human-readable log message
     * @param array<string, mixed> $context Additional structured context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * Log an ERROR-level message.
     *
     * @param string               $message Human-readable log message
     * @param array<string, mixed> $context Additional structured context
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * Log a CRITICAL-level message.
     *
     * @param string               $message Human-readable log message
     * @param array<string, mixed> $context Additional structured context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    /**
     * Log a slow-query warning with query details.
     *
     * @param string $sql      The SQL statement (may be truncated)
     * @param float  $duration Duration in milliseconds
     * @param array<string, mixed> $extra Additional context
     */
    public function slowQuery(string $sql, float $duration, array $extra = []): void
    {
        $this->warning('Slow query detected', array_merge([
            'sql'          => mb_substr($sql, 0, 1000),
            'duration_ms'  => round($duration, 3),
        ], $extra));
    }

    /**
     * Log an unhandled exception with full stack trace.
     *
     * @param \Throwable $throwable The caught exception or error
     * @param array<string, mixed>  $context  Additional context
     */
    public function exception(\Throwable $throwable, array $context = []): void
    {
        $this->error($throwable->getMessage(), array_merge([
            'exception' => [
                'class'   => get_class($throwable),
                'code'    => $throwable->getCode(),
                'file'    => $throwable->getFile(),
                'line'    => $throwable->getLine(),
                'trace'   => $throwable->getTraceAsString(),
            ],
        ], $context));
    }

    /**
     * Access the underlying Monolog instance for advanced use cases.
     */
    public function getMonolog(): MonologLogger
    {
        return $this->logger;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function resolveLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug'    => Level::Debug,
            'info'     => Level::Info,
            'notice'   => Level::Notice,
            'warning'  => Level::Warning,
            'error'    => Level::Error,
            'critical' => Level::Critical,
            'alert'    => Level::Alert,
            'emergency'=> Level::Emergency,
            default    => Level::Warning,
        };
    }

    private function __clone(): void {}

    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize Logger singleton.');
    }
}
