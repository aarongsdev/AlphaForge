<?php

declare(strict_types=1);

namespace AlphaForge\Api\Middleware;

use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Request;
use AlphaForge\Core\Response;
use AlphaForge\Models\User;
use AlphaForge\Services\Auth\JwtService;

/**
 * JWT authentication middleware.
 *
 * Extracts the Bearer token from the Authorization header, validates it via
 * JwtService, checks the JTI is not revoked, and verifies the corresponding
 * session exists and is active in the database.
 *
 * On success, attaches the full user record to the Request object so downstream
 * controllers can call $request->user().
 *
 * Role-based access control is applied via requireRole() when constructing
 * route-specific middleware instances.
 */
final class AuthMiddleware
{
    /** @var list<string> Roles that are allowed to pass through this middleware instance */
    private array $allowedRoles = [];

    private JwtService $jwt;
    private User       $userModel;
    private Database   $db;
    private Logger     $logger;

    public function __construct()
    {
        $this->jwt       = new JwtService();
        $this->userModel = new User();
        $this->db        = Database::getInstance();
        $this->logger    = Logger::getInstance();
    }

    /**
     * Configure role-based access control for this middleware instance.
     *
     * When called, only users whose role appears in $roles will be allowed
     * through. If the user's role is not in the list, a 403 Forbidden response
     * is emitted.
     *
     * @param string ...$roles One or more allowed role names (e.g. 'admin', 'analyst')
     * @return static Fluent instance for use in route registration
     */
    public function requireRole(string ...$roles): static
    {
        $this->allowedRoles = $roles;
        return $this;
    }

    /**
     * Execute the authentication middleware.
     *
     * @param Request  $request The current HTTP request
     * @param callable $next    The next middleware or controller handler in the pipeline
     */
    public function handle(Request $request, callable $next): void
    {
        $token = $request->bearerToken();

        if ($token === null) {
            Response::unauthorized('Authentication required. Please provide a Bearer token.');
        }

        // ── Verify the JWT signature and expiry ───────────────────────────────
        try {
            $payload = $this->jwt->verify($token);
        } catch (\Throwable $e) {
            $this->logger->info('JWT verification failed', [
                'ip'    => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            Response::unauthorized('Invalid or expired token.');
        }

        $jti    = (string) ($payload['jti']     ?? '');
        $userId = (string) ($payload['user_id'] ?? '');

        // ── Check JTI revocation list ─────────────────────────────────────────
        if ($jti !== '' && $this->jwt->isRevoked($jti)) {
            $this->logger->warning('Revoked JWT used', ['jti' => $jti, 'user_id' => $userId]);
            Response::unauthorized('Token has been revoked.');
        }

        // ── Verify an active session exists ───────────────────────────────────
        // We do NOT store the access-token hash in sessions (only refresh tokens).
        // The session check here is a basic guard that the user hasn't had all
        // sessions invalidated (e.g. via password reset).
        $activeSession = $this->db->fetchOne(
            'SELECT id FROM user_sessions
              WHERE user_id = :uid
                AND is_active = TRUE
                AND expires_at > NOW()
             LIMIT 1',
            ['uid' => $userId],
        );

        if ($activeSession === null) {
            Response::unauthorized('No active session found. Please log in again.');
        }

        // ── Load the user from DB ─────────────────────────────────────────────
        $user = $this->userModel->findById($userId);

        if ($user === null) {
            $this->logger->warning('JWT references non-existent user', ['user_id' => $userId]);
            Response::unauthorized('User account not found.');
        }

        if (!(bool) $user['is_active']) {
            Response::forbidden('Your account has been deactivated.');
        }

        // Attach role from JWT payload (avoids a second DB query for roles in most cases).
        $user['role'] = (string) ($payload['role'] ?? 'viewer');
        $user['jti']  = $jti;

        // ── Role-based access control ─────────────────────────────────────────
        if (!empty($this->allowedRoles) && !in_array($user['role'], $this->allowedRoles, strict: true)) {
            $this->logger->info('Access denied by role', [
                'user_id'       => $userId,
                'user_role'     => $user['role'],
                'required_roles'=> $this->allowedRoles,
                'path'          => $request->path(),
            ]);

            Response::forbidden('You do not have permission to access this resource.');
        }

        // ── Attach user to the request ────────────────────────────────────────
        $request->setUser($user);

        // Enrich logger context with the authenticated user.
        $this->logger->setGlobalContext([
            'user_id' => $userId,
            'role'    => $user['role'],
        ]);

        $next();
    }
}
