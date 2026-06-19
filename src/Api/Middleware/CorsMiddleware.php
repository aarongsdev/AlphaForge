<?php

declare(strict_types=1);

namespace AlphaForge\Api\Middleware;

use AlphaForge\Core\Config;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Request;

/**
 * CORS (Cross-Origin Resource Sharing) middleware.
 *
 * Sets the appropriate Access-Control-* response headers based on the incoming
 * Origin header and the configured allowed-origins list. In production mode,
 * origins are strictly validated against the whitelist; in development, all
 * origins are permitted (controlled by the 'app.env' config value).
 *
 * Preflight OPTIONS requests are handled and terminated immediately with a
 * 204 No Content response so they never reach the controller layer.
 *
 * Configure allowed origins in config/app.php under the 'cors' key:
 *
 *   'cors' => [
 *       'allowed_origins' => ['https://app.alphaforge.io', 'https://alphaforge.io'],
 *       'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
 *       'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
 *       'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],
 *       'max_age'         => 86400,
 *       'credentials'     => true,
 *   ],
 */
final class CorsMiddleware
{
    /** @var list<string> */
    private array $allowedOrigins;

    /** @var list<string> */
    private array $allowedMethods;

    /** @var list<string> */
    private array $allowedHeaders;

    /** @var list<string> */
    private array $exposedHeaders;

    private int  $maxAge;
    private bool $credentials;
    private bool $isProduction;

    private Config $config;
    private Logger $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance();

        $env                = (string) ($this->config->get('app.env') ?? $_ENV['APP_ENV'] ?? 'production');
        $this->isProduction = ($env === 'production');

        $this->allowedOrigins = (array) ($this->config->get('app.cors.allowed_origins') ?? [
            (string) ($_ENV['APP_URL'] ?? 'http://localhost'),
        ]);

        $this->allowedMethods = (array) ($this->config->get('app.cors.allowed_methods') ?? [
            'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS',
        ]);

        $this->allowedHeaders = (array) ($this->config->get('app.cors.allowed_headers') ?? [
            'Content-Type', 'Authorization', 'X-Requested-With', 'X-Request-ID', 'Accept',
        ]);

        $this->exposedHeaders = (array) ($this->config->get('app.cors.exposed_headers') ?? [
            'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'X-Request-ID',
        ]);

        $this->maxAge      = (int)  ($this->config->get('app.cors.max_age')     ?? 86400);
        $this->credentials = (bool) ($this->config->get('app.cors.credentials') ?? true);
    }

    /**
     * Execute the CORS middleware.
     *
     * Sets CORS headers on every request and terminates OPTIONS preflight
     * requests with 204 before calling the next handler.
     *
     * @param Request  $request The current HTTP request
     * @param callable $next    The next middleware or controller handler
     */
    public function handle(Request $request, callable $next): void
    {
        $origin = $request->header('origin');

        if ($origin !== null) {
            $resolvedOrigin = $this->resolveOrigin($origin);

            if ($resolvedOrigin !== null) {
                $this->setHeaders($resolvedOrigin);
            } elseif ($this->isProduction) {
                // Log rejected CORS origins in production for monitoring.
                $this->logger->info('CORS origin rejected', [
                    'origin' => $origin,
                    'path'   => $request->path(),
                ]);
            }
        }

        // Terminate OPTIONS preflight immediately.
        if ($request->method() === 'OPTIONS') {
            if (!headers_sent()) {
                http_response_code(204);
                header('Content-Length: 0');
            }
            exit;
        }

        $next();
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Determine the value to use in Access-Control-Allow-Origin.
     *
     * In development: returns '*' (or the origin if credentials are enabled).
     * In production: only returns the origin if it matches the whitelist.
     *
     * @param string $requestOrigin The Origin header value from the request
     * @return string|null The origin to reflect, or null when rejected
     */
    private function resolveOrigin(string $requestOrigin): ?string
    {
        if (!$this->isProduction) {
            // In development, reflect any origin (but never use '*' with credentials).
            return $this->credentials ? $requestOrigin : '*';
        }

        foreach ($this->allowedOrigins as $allowed) {
            if ($this->originMatches($requestOrigin, (string) $allowed)) {
                return $requestOrigin;
            }
        }

        return null;
    }

    /**
     * Determine whether the request origin matches an allowed origin pattern.
     *
     * Supports exact matches and wildcard subdomain patterns (e.g. '*.alphaforge.io').
     *
     * @param string $requestOrigin The incoming Origin header value
     * @param string $allowed       Configured allowed origin (may start with '*.')
     * @return bool
     */
    private function originMatches(string $requestOrigin, string $allowed): bool
    {
        // Exact match.
        if ($requestOrigin === $allowed) {
            return true;
        }

        // Wildcard subdomain: *.alphaforge.io matches app.alphaforge.io
        if (str_starts_with($allowed, '*.')) {
            $domain  = substr($allowed, 2); // e.g. 'alphaforge.io'
            $pattern = '/^https?:\/\/[a-z0-9]([a-z0-9\-]*[a-z0-9])?\.'. preg_quote($domain, '/') . '$/i';

            if (preg_match($pattern, $requestOrigin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emit all Access-Control-* headers.
     *
     * @param string $origin The resolved origin value to reflect
     */
    private function setHeaders(string $origin): void
    {
        if (headers_sent()) {
            return;
        }

        header('Access-Control-Allow-Origin: '    . $origin);
        header('Access-Control-Allow-Methods: '   . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: '   . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: '         . $this->maxAge);

        if (!empty($this->exposedHeaders)) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->exposedHeaders));
        }

        if ($this->credentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        // Vary header tells caches that the response varies by Origin.
        header('Vary: Origin');
    }
}
