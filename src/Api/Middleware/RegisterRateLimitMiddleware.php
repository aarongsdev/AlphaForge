<?php

declare(strict_types=1);

namespace AlphaForge\Api\Middleware;

/**
 * Specialised rate limiter for the registration endpoint.
 *
 * 10 registrations per hour per IP to prevent bulk account creation.
 */
final class RegisterRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct()
    {
        parent::__construct(limit: 10, windowSeconds: 3600, keyPrefix: 'rl:register');
    }
}
