<?php

declare(strict_types=1);

namespace AlphaForge\Api\Middleware;

/**
 * Specialised rate limiter for the forgot-password endpoint.
 *
 * 3 requests per hour per IP to prevent email flooding.
 */
final class ForgotPasswordRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct()
    {
        parent::__construct(limit: 3, windowSeconds: 3600, keyPrefix: 'rl:forgot');
    }
}
