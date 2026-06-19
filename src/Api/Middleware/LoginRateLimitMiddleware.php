<?php

declare(strict_types=1);

namespace AlphaForge\Api\Middleware;

/**
 * Specialised rate limiter for the login endpoint.
 *
 * 5 attempts per 15 minutes per IP. Applied as route-level middleware on
 * POST /api/v1/auth/login to mitigate credential stuffing attacks.
 */
final class LoginRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct()
    {
        parent::__construct(limit: 5, windowSeconds: 900, keyPrefix: 'rl:login');
    }
}
