<?php

declare(strict_types=1);

/**
 * AlphaForge Front Controller
 *
 * Single entry point for all HTTP requests. Responsibilities:
 *   - Bootstrap autoloader and environment variables
 *   - Configure PHP error handling (environment-aware)
 *   - Emit security headers (CSP, HSTS, X-Frame-Options, etc.)
 *   - Generate a unique request ID and attach it to Logger and Response
 *   - Handle CORS preflight (OPTIONS) via CorsMiddleware
 *   - Extract and validate JWT from Authorization header when present
 *   - Instantiate the Router, register all routes, and dispatch
 *   - Catch all uncaught exceptions and emit structured JSON error responses
 */

// ── Autoloader ────────────────────────────────────────────────────────────────

$autoloader = dirname(__DIR__) . '/vendor/autoload.php';

if (!file_exists($autoloader)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'success'   => false,
        'message'   => 'Application not installed. Run `composer install`.',
        'data'      => null,
        'timestamp' => date('c'),
        'request_id'=> '',
    ]);
    exit(1);
}

require_once $autoloader;

// ── Environment variables ─────────────────────────────────────────────────────

use AlphaForge\Api\Controllers\AuthController;
use AlphaForge\Api\Middleware\AuthMiddleware;
use AlphaForge\Api\Middleware\CorsMiddleware;
use AlphaForge\Api\Middleware\ForgotPasswordRateLimitMiddleware;
use AlphaForge\Api\Middleware\LoginRateLimitMiddleware;
use AlphaForge\Api\Middleware\RateLimitMiddleware;
use AlphaForge\Api\Middleware\RegisterRateLimitMiddleware;
use AlphaForge\Core\Config;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Request;
use AlphaForge\Core\Response;
use AlphaForge\Core\Router;
use AlphaForge\Exceptions\ValidationException;
use Dotenv\Dotenv;
use Ramsey\Uuid\Uuid;

// Load .env from project root (silently skip if already populated by the environment).
$envFile = dirname(__DIR__) . '/.env';

if (file_exists($envFile)) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

// ── Error handling ────────────────────────────────────────────────────────────

$env   = $_ENV['APP_ENV'] ?? 'production';
$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($env === 'production' || !$debug) {
    // Production: suppress all output, log errors to file.
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');

    $logFile = dirname(__DIR__) . '/logs/php_errors.log';
    ini_set('error_log', $logFile);
    error_reporting(E_ALL);
} else {
    // Development: display errors for rapid iteration.
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// ── Request ID ────────────────────────────────────────────────────────────────

$requestId = Uuid::uuid4()->toString();
Response::setRequestId($requestId);

// ── Security headers ──────────────────────────────────────────────────────────

// These headers are set early so they appear on every response, including
// error responses generated before the Router runs.
if (!headers_sent()) {
    // Prevent clickjacking.
    header('X-Frame-Options: DENY');

    // Prevent MIME-type sniffing.
    header('X-Content-Type-Options: nosniff');

    // Minimal Content Security Policy (API-only — no HTML served here).
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    // HSTS: force HTTPS for 1 year (only safe in production with TLS).
    if ($env === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // Control referrer information.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Disable browser feature APIs that the API does not need.
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // Unique request identifier (useful for log correlation).
    header('X-Request-ID: ' . $requestId);

    // Remove server fingerprinting headers.
    header_remove('X-Powered-By');
    header_remove('Server');
}

// ── Bootstrap core services ───────────────────────────────────────────────────

$config = Config::getInstance();
$config->loadAll();

$logger = Logger::getInstance();
$logger->setGlobalContext([
    'request_id' => $requestId,
    'env'        => $env,
]);

// ── Parse request ─────────────────────────────────────────────────────────────

$request = new Request();

$logger->debug('Request received', [
    'method' => $request->method(),
    'path'   => $request->path(),
    'ip'     => $request->ip(),
]);

$logger->setGlobalContext(['ip' => $request->ip()]);

// ── CORS (preflight terminates here) ─────────────────────────────────────────

(new CorsMiddleware())->handle($request, static function (): void {
    // No-op: if OPTIONS, CorsMiddleware exits before calling this.
});

// ── Eager JWT extraction (optional) ──────────────────────────────────────────
// Pre-validate the JWT when present so the Router and middleware can see the
// user context early. Errors here are NOT fatal — unauthenticated routes will
// simply proceed without a user; AuthMiddleware enforces auth on protected routes.

$token = $request->bearerToken();

if ($token !== null) {
    try {
        $jwtService = new \AlphaForge\Services\Auth\JwtService();
        $payload    = $jwtService->verify($token);

        $userModel = new \AlphaForge\Models\User();
        $user      = $userModel->findById((string) ($payload['user_id'] ?? ''));

        if ($user !== null) {
            $user['role'] = (string) ($payload['role'] ?? 'viewer');
            $user['jti']  = (string) ($payload['jti']  ?? '');
            $request->setUser($user);

            $logger->setGlobalContext(['user_id' => $user['id']]);
        }
    } catch (\Throwable) {
        // Invalid or expired token — silently ignore here; let AuthMiddleware reject it on
        // protected routes. Public routes that received a bad token simply proceed without user.
    }
}

// ── Router ────────────────────────────────────────────────────────────────────

$router = new Router();

// ── Auth routes ───────────────────────────────────────────────────────────────

$router->group('/api/v1/auth', static function (Router $r): void {

    // Public routes — each has its own tailored rate limiter.
    $r->post('/register',         AuthController::class, 'register',       [RegisterRateLimitMiddleware::class]);
    $r->post('/login',            AuthController::class, 'login',          [LoginRateLimitMiddleware::class]);
    $r->post('/refresh',          AuthController::class, 'refresh',        [RateLimitMiddleware::class]);
    $r->post('/forgot-password',  AuthController::class, 'forgotPassword', [ForgotPasswordRateLimitMiddleware::class]);
    $r->post('/reset-password',   AuthController::class, 'resetPassword',  [RateLimitMiddleware::class]);
    $r->get('/verify-email/{token}', AuthController::class, 'verifyEmail');

    // Authenticated-only routes.
    $r->post('/logout',           AuthController::class, 'logout',         [AuthMiddleware::class]);
    $r->get('/me',                AuthController::class, 'me',             [AuthMiddleware::class]);
    $r->put('/profile',           AuthController::class, 'updateProfile',  [AuthMiddleware::class]);
    $r->post('/change-password',  AuthController::class, 'changePassword', [AuthMiddleware::class]);

}, []);

// ── Dispatch ──────────────────────────────────────────────────────────────────

// Wrap dispatch in the global exception handler.
try {
    $router->dispatch($request);
} catch (ValidationException $e) {
    $logger->info('Validation exception', [
        'message' => $e->getMessage(),
        'errors'  => $e->getFieldErrors(),
    ]);

    Response::validationError($e->getFieldErrors(), $e->getMessage());
} catch (\PDOException $e) {
    $logger->error('Database error', [
        'message' => $e->getMessage(),
        'code'    => $e->getCode(),
    ]);

    // Never expose raw DB error messages externally.
    Response::serverError('A database error occurred. Please try again later.');
} catch (\Throwable $e) {
    $logger->exception($e, [
        'method' => $request->method(),
        'path'   => $request->path(),
    ]);

    if ($debug) {
        Response::serverError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    } else {
        Response::serverError('An unexpected error occurred. Please try again later.');
    }
}
