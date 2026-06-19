<?php

declare(strict_types=1);

namespace AlphaForge\Api;

/**
 * Lightweight HTTP request wrapper for the AlphaForge API layer.
 *
 * Wraps PHP superglobals and provides typed accessors for route params,
 * query-string values, parsed JSON body, headers, the HTTP method, and the
 * authenticated user payload injected by JWT middleware.
 */
final class Request
{
    /** @var array<string, mixed> Route parameters extracted by the router (e.g. {id}, {symbol}) */
    private array $params = [];

    /** @var array<string, mixed>|null Authenticated user payload set by JWT middleware */
    private ?array $user = null;

    /** @var array<string, mixed> Parsed request body (JSON decoded) */
    private array $parsedBody;

    /** @var array<string, string> Normalised (lowercase-key) HTTP headers */
    private array $headers;

    public function __construct()
    {
        $this->headers    = $this->parseHeaders();
        $this->parsedBody = $this->parseBody();
    }

    // ─── Route parameters ─────────────────────────────────────────────────────

    /**
     * Retrieve a route parameter (e.g. `id` from /api/v1/portfolio/{id}).
     *
     * @param string $key     Parameter name (without curly braces)
     * @param mixed  $default Returned when the parameter is absent
     * @return mixed
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Set a single route parameter (called by the router after matching).
     *
     * @internal
     */
    public function setParam(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    /**
     * Set multiple route parameters at once.
     *
     * @param array<string, mixed> $params
     * @internal
     */
    public function setParams(array $params): void
    {
        $this->params = array_merge($this->params, $params);
    }

    // ─── Query string ─────────────────────────────────────────────────────────

    /**
     * Retrieve a query-string ($_GET) parameter.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    // ─── Request body ─────────────────────────────────────────────────────────

    /**
     * Return the decoded JSON request body as an associative array.
     *
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->parsedBody;
    }

    // ─── Headers ──────────────────────────────────────────────────────────────

    /**
     * Retrieve a header value by name (case-insensitive).
     *
     * @param string $key     Header name (e.g. 'Authorization', 'Content-Type')
     * @param mixed  $default Returned when the header is absent
     * @return mixed
     */
    public function getHeader(string $key, mixed $default = null): mixed
    {
        $normalised = strtolower(str_replace(['_', ' '], '-', $key));
        return $this->headers[$normalised] ?? $default;
    }

    // ─── Method ───────────────────────────────────────────────────────────────

    /**
     * Return the HTTP method in upper-case (GET, POST, PUT, PATCH, DELETE …).
     */
    public function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    // ─── Authenticated user ───────────────────────────────────────────────────

    /**
     * Return the authenticated user payload set by JWT middleware, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /**
     * Attach an authenticated user payload (called by JWT/auth middleware).
     *
     * @param array<string, mixed> $user
     * @internal
     */
    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    // ─── Convenience helpers ──────────────────────────────────────────────────

    /**
     * Extract pagination parameters from the query string.
     *
     * Reads `page` and `per_page` from $_GET. Enforces min page=1,
     * min per_page=1, max per_page=100, default per_page=20.
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    public function getPaginationParams(): array
    {
        $page    = max(1, (int) ($this->getQuery('page', 1)));
        $perPage = max(1, min(100, (int) ($this->getQuery('per_page', 20))));
        $offset  = ($page - 1) * $perPage;

        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => $offset,
        ];
    }

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * @return string|null The raw token string, or null if not present / malformed
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization');

        if (!is_string($auth) || $auth === '') {
            return null;
        }

        if (str_starts_with($auth, 'Bearer ')) {
            $token = trim(substr($auth, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function parseHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            /** @var array<string, string> $raw */
            $raw = getallheaders();
            foreach ($raw as $name => $value) {
                $headers[strtolower(str_replace(['_', ' '], '-', $name))] = $value;
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
        $contentType = $this->getHeader('Content-Type', '');

        if (is_string($contentType) && str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            if ($raw !== '') {
                try {
                    /** @var array<string, mixed>|null $decoded */
                    $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
                    return is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    return [];
                }
            }
            return [];
        }

        return $_POST ?? [];
    }
}
