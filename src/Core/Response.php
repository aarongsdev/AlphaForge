<?php

declare(strict_types=1);

namespace AlphaForge\Core;

/**
 * HTTP response builder with JSON output.
 *
 * All public methods are static and terminate the script with `exit` after
 * emitting the response (return type `never`). Every response body includes
 * a consistent envelope with `success`, `message`, `data`, `timestamp`,
 * and `request_id` fields.
 */
final class Response
{
    /** Request-scoped unique identifier set by the front controller. */
    private static string $requestId = '';

    /**
     * Set the request ID used in all response envelopes for this request.
     *
     * Called once by the front controller after generating the ID.
     *
     * @param string $id UUID or other unique identifier string
     */
    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    /**
     * Emit a raw JSON response and terminate.
     *
     * @param mixed $data   Any JSON-serialisable value
     * @param int   $status HTTP status code
     * @return never
     */
    public static function json(mixed $data, int $status = 200): never
    {
        self::sendHeaders($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Emit a successful JSON response.
     *
     * @param mixed  $data    Payload to embed in the `data` field
     * @param string $message Human-readable success message
     * @param int    $status  HTTP status code (default 200)
     * @return never
     */
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): never
    {
        self::json(self::envelope(true, $message, $data), $status);
    }

    /**
     * Emit an error JSON response.
     *
     * @param string               $message Human-readable error description
     * @param int                  $status  HTTP status code (default 400)
     * @param array<string, mixed> $errors  Field-level validation errors
     * @return never
     */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = self::envelope(false, $message, null);

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $status);
    }

    /**
     * Emit a paginated list response.
     *
     * @param array<int, mixed> $items   Items for the current page
     * @param int               $total   Total number of items across all pages
     * @param int               $page    Current page number (1-based)
     * @param int               $perPage Items per page
     * @return never
     */
    public static function paginated(array $items, int $total, int $page, int $perPage): never
    {
        $lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        $data = [
            'items'       => $items,
            'pagination'  => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
                'from'         => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
                'to'           => min($page * $perPage, $total),
                'has_more'     => $page < $lastPage,
            ],
        ];

        self::success($data, 'OK');
    }

    /**
     * Emit a 201 Created response.
     *
     * @param mixed  $data    The newly created resource representation
     * @param string $message Human-readable creation message
     * @return never
     */
    public static function created(mixed $data = null, string $message = 'Created'): never
    {
        self::success($data, $message, 201);
    }

    /**
     * Emit a 204 No Content response (no body).
     *
     * @return never
     */
    public static function noContent(): never
    {
        self::sendHeaders(204);
        exit;
    }

    /**
     * Emit a 401 Unauthorized response.
     *
     * @param string $message
     * @return never
     */
    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error($message, 401);
    }

    /**
     * Emit a 403 Forbidden response.
     *
     * @param string $message
     * @return never
     */
    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    /**
     * Emit a 404 Not Found response.
     *
     * @param string $message
     * @return never
     */
    public static function notFound(string $message = 'Not Found'): never
    {
        self::error($message, 404);
    }

    /**
     * Emit a 405 Method Not Allowed response.
     *
     * @param list<string> $allowedMethods
     * @return never
     */
    public static function methodNotAllowed(array $allowedMethods = []): never
    {
        if (!empty($allowedMethods)) {
            header('Allow: ' . implode(', ', $allowedMethods));
        }

        self::error('Method Not Allowed', 405);
    }

    /**
     * Emit a 422 Unprocessable Entity (validation failure) response.
     *
     * @param array<string, mixed> $errors Field-level errors
     * @param string               $message
     * @return never
     */
    public static function validationError(array $errors = [], string $message = 'Validation failed'): never
    {
        self::error($message, 422, $errors);
    }

    /**
     * Emit a 429 Too Many Requests response.
     *
     * @param int    $retryAfter Seconds until the client may retry
     * @param string $message
     * @return never
     */
    public static function tooManyRequests(int $retryAfter = 60, string $message = 'Too Many Requests'): never
    {
        header('Retry-After: ' . $retryAfter);
        self::error($message, 429);
    }

    /**
     * Emit a 500 Internal Server Error response.
     *
     * @param string $message
     * @return never
     */
    public static function serverError(string $message = 'Internal Server Error'): never
    {
        self::error($message, 500);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build the standard response envelope.
     *
     * @param bool   $success
     * @param string $message
     * @param mixed  $data
     * @return array<string, mixed>
     */
    private static function envelope(bool $success, string $message, mixed $data): array
    {
        return [
            'success'    => $success,
            'message'    => $message,
            'data'       => $data,
            'timestamp'  => date('c'),
            'request_id' => self::$requestId,
        ];
    }

    /**
     * Set HTTP status code and JSON content-type header.
     */
    private static function sendHeaders(int $status): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
    }
}
