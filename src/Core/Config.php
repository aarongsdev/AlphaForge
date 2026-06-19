<?php

declare(strict_types=1);

namespace AlphaForge\Core;

/**
 * Singleton configuration manager for the AlphaForge platform.
 *
 * Loads PHP config files from the /config/ directory and provides
 * dot-notation access to nested configuration values. All loaded
 * configs are cached in memory for the duration of the request.
 */
final class Config
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, bool> */
    private array $loaded = [];

    private string $configPath;

    private function __construct()
    {
        $this->configPath = dirname(__DIR__, 2) . '/config';
    }

    /**
     * Returns the singleton Config instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Retrieve a configuration value using dot-notation.
     *
     * The first segment before the first dot is treated as the config file
     * name (and auto-loaded if not already loaded). Subsequent segments
     * traverse nested array keys.
     *
     * @param string $key     Dot-notation key, e.g. 'database.connections.pgsql.host'
     * @param mixed  $default Value returned when the key does not exist
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file     = $segments[0];

        // Auto-load the config file if not already loaded.
        if (!isset($this->loaded[$file])) {
            $this->load($file);
        }

        $current = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a configuration value at runtime using dot-notation.
     *
     * This does NOT persist to disk; the change is scoped to the current
     * request lifecycle only.
     *
     * @param string $key   Dot-notation key
     * @param mixed  $value The value to store
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current  = &$this->data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * Load a single PHP config file by name (without the .php extension).
     *
     * The file must return an array. Loaded values are namespaced under the
     * file name key, e.g. 'database.php' is stored under $this->data['database'].
     *
     * @param string $file Config file name without extension (e.g. 'database')
     */
    public function load(string $file): void
    {
        if (isset($this->loaded[$file])) {
            return;
        }

        $path = $this->configPath . '/' . $file . '.php';

        if (!file_exists($path)) {
            // Mark as attempted so we don't retry on every get() call.
            $this->loaded[$file] = false;
            return;
        }

        /** @var mixed $values */
        $values = require $path;

        if (is_array($values)) {
            $this->data[$file] = array_merge(
                $this->data[$file] ?? [],
                $values
            );
        }

        $this->loaded[$file] = true;
    }

    /**
     * Load every .php file present in the config directory.
     */
    public function loadAll(): void
    {
        $files = glob($this->configPath . '/*.php');

        if ($files === false) {
            return;
        }

        foreach ($files as $filePath) {
            $name = basename($filePath, '.php');
            $this->load($name);
        }
    }

    /**
     * Determine whether a given configuration key exists.
     *
     * @param string $key Dot-notation key
     */
    public function has(string $key): bool
    {
        return $this->get($key, '__ALPHAFORGE_MISSING__') !== '__ALPHAFORGE_MISSING__';
    }

    /**
     * Return all loaded configuration data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Flush the in-memory configuration cache.
     * Useful in test environments where configs must be reset between tests.
     */
    public function flush(): void
    {
        $this->data   = [];
        $this->loaded = [];
    }

    // Prevent cloning and unserialization of the singleton.
    private function __clone(): void {}

    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize Config singleton.');
    }
}
