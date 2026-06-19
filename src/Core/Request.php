<?php

declare(strict_types=1);

namespace AlphaForge\Core;

/**
 * Immutable-friendly HTTP request wrapper.
 *
 * Parses the current PHP superglobals once on construction and exposes a
 * typed API for accessing body parameters, query-string values, headers,
 * uploaded files, and connection metadata. The authenticated user array is
 * attached after JWT verification by AuthMiddleware.
 */
final class Request
{
    /** @var array<string, mixed> Merged body (JSON > form-urlencoded > multipart) */
    private array $body;

    /** @var array<string, mixed> $_GET */
    private array $queryParams;

    /** @var array<string, string> Normalised HTTP headers */
    private array $headers;

    /** @var array<string, mixed> $_FILES */
    private array $files;

    private string $method;
    private string $path;
    private string $rawBody;

    /** @var array<string, mixed>|null Set by AuthMiddleware after JWT validation */
    private ?array $authenticatedUser = null;

    /** @var array<string, string> Path parameters extracted by the router */
    private array $pathParams = [];

    public function __construct()
    {
        $this->method      = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path        = $this->parsePath();
        $this->headers     = $this->parseHeaders();
        $this->queryParams = $_GET ?? [];
        $this->files       = $_FILES ?? [];
        $this->rawBody     = (string) file_get_contents('php://input');
        $this->body        = $this->parseBody();
    }

    /**
     * Retrieve a value from the request body (JSON / form) or query string.
     * Body parameters take precedence over query parameters.
     *
     * @param string $key     Parameter name
     * @param mixed  $default Value returned when the key is absent
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->queryParams[$key] ?? $default;
    }

    /**
     * Retrieve all body parameters merged with query parameters.
     * Body keys take precedence.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->queryParams, $this->body);
    }

    /**
     * Return only the specified keys from the merged request data.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all    = $this->all();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }

        return $result;
    }

    /**
     * Retrieve a query-string parameter.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Return all query-string parameters.
     *
     * @return array<string, mixed>
     */
    public function queryAll(): array
    {
        return $this->queryParams;
    }

    /**
     * Retrieve a named HTTP header value (case-insensitive).
     *
     * @param string $name Header name, e.g. 'Authorization' or 'content-type'
     * @return string|null The header value, or null if absent
     */
    public function header(string $name): ?string
    {
        $normalised = strtolower(str_replace('_', '-', $name));
        return $this->headers[$normalised] ?? null;
    }

    /**
     * Return all request headers as a normalised (lowercase-key) map.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Determine the real client IP address.
     *
     * Consults X-Forwarded-For and X-Real-IP (in that order) before falling
     * back to REMOTE_ADDR. Only the first IP in a comma-separated X-Forwarded-For
     * list is returned (the originating client).
     *
     * @return string IPv4 or IPv6 address string
     */
    public function ip(): string
    {
        $forwarded = $this->header('x-forwarded-for');

        if ($forwarded !== null) {
            $parts = explode(',', $forwarded);
            $ip    = trim($parts[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        $realIp = $this->header('x-real-ip');

        if ($realIp !== null && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Return the HTTP method in upper-case (GET, POST, PUT, PATCH, DELETE, etc.).
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Return the request URI path without query string (e.g. '/api/v1/auth/login').
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Determine whether the request was sent with a JSON content type or
     * contains a JSON body.
     */
    public function isJson(): bool
    {
        $ct = $this->header('content-type') ?? '';
        return str_contains($ct, 'application/json');
    }

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * @return string|null The raw token string, or null if not present
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');

        if ($auth === null) {
            return null;
        }

        if (str_starts_with($auth, 'Bearer ')) {
            $token = trim(substr($auth, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }

    /**
     * Return the User-Agent string, or an empty string if absent.
     */
    public function userAgent(): string
    {
        return $this->header('user-agent') ?? '';
    }

    /**
     * Return the authenticated user array attached by AuthMiddleware.
     *
     * Returns null when the request is unauthenticated.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->authenticatedUser;
    }

    /**
     * Return the authenticated user ID, or null when unauthenticated.
     */
    public function userId(): ?string
    {
        return isset($this->authenticatedUser['id'])
            ? (string) $this->authenticatedUser['id']
            : null;
    }

    /**
     * Attach an authenticated user payload to this request (called by AuthMiddleware).
     *
     * @param array<string, mixed> $user
     * @internal
     */
    public function setUser(array $user): void
    {
        $this->authenticatedUser = $user;
    }

    /**
     * Set a path parameter extracted by the router (e.g. {symbol}).
     *
     * @internal Called by the Router after route matching.
     */
    public function setPathParam(string $name, string $value): void
    {
        $this->pathParams[$name] = $value;
    }

    /**
     * Set multiple path parameters at once.
     *
     * @param array<string, string> $params
     * @internal
     */
    public function setPathParams(array $params): void
    {
        $this->pathParams = array_merge($this->pathParams, $params);
    }

    /**
     * Retrieve a path parameter (e.g. `symbol` from /api/v1/assets/{symbol}).
     *
     * @param string $name    The parameter name without curly braces
     * @param string $default Value returned when the parameter is absent
     * @return string
     */
    public function param(string $name, string $default = ''): string
    {
        return $this->pathParams[$name] ?? $default;
    }

    /**
     * Return all path parameters.
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->pathParams;
    }

    /**
     * Return the raw (unparsed) request body.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Return uploaded file metadata for a given form field.
     *
     * @param string $field The <input type="file" name="..."> field name
     * @return array<string, mixed>|null
     */
    public function file(string $field): ?array
    {
        return isset($this->files[$field]) ? $this->files[$field] : null;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function parsePath(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return rtrim((string) ($path ?: '/'), '/') ?: '/';
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            /** @var array<string, string> $rawHeaders */
            $rawHeaders = getallheaders();

            foreach ($rawHeaders as $name => $value) {
                $headers[strtolower(str_replace('_', '-', $name))] = $value;
            }

            return $headers;
        }

        // Fallback: parse $_SERVER for HTTP_* entries.
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['content-length'] = $value;
            }
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(): array
    {
        if ($this->isJson() && $this->rawBody !== '') {
            try {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($this->rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                return [];
            }
        }

        return $_POST ?? [];
    }
}
