<?php

declare(strict_types=1);

namespace AlphaForge\Api\Controllers;

use AlphaForge\Core\Request;
use AlphaForge\Core\Response;
use AlphaForge\Core\Validator;
use AlphaForge\Exceptions\ValidationException;
use AlphaForge\Services\Auth\AuthService;

/**
 * Authentication REST controller.
 *
 * All endpoints are mounted under /api/v1/auth by the Router. Each action
 * validates its own input before delegating to AuthService. Rate limits are
 * applied as route-level middleware (configured in the router bootstrap).
 *
 * Endpoints:
 *   POST   /api/v1/auth/register             – create account
 *   POST   /api/v1/auth/login                – obtain tokens
 *   POST   /api/v1/auth/logout               – revoke session [auth required]
 *   POST   /api/v1/auth/refresh              – exchange refresh token
 *   POST   /api/v1/auth/forgot-password      – initiate reset flow
 *   POST   /api/v1/auth/reset-password       – complete reset flow
 *   GET    /api/v1/auth/verify-email/{token} – verify email address
 *   GET    /api/v1/auth/me                   – current user profile [auth required]
 *   PUT    /api/v1/auth/profile              – update profile [auth required]
 *   POST   /api/v1/auth/change-password      – change password [auth required]
 */
final class AuthController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    // ─── POST /api/v1/auth/register ───────────────────────────────────────────

    /**
     * Register a new user account.
     *
     * Required body fields: email, password, password_confirm.
     * Optional: username, first_name, last_name, phone.
     *
     * @param Request $request
     * @return never
     */
    public function register(Request $request): never
    {
        try {
            $user = $this->authService->register($request->all());

            Response::created([
                'user' => $user,
            ], 'Account created. Please check your email to verify your address.');
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors(), $e->getMessage());
        } catch (\Throwable $e) {
            Response::serverError('Registration failed: ' . $e->getMessage());
        }
    }

    // ─── POST /api/v1/auth/login ──────────────────────────────────────────────

    /**
     * Authenticate with email + password and receive access/refresh tokens.
     *
     * Required body fields: email, password.
     * Rate limited to 5 attempts per 15 minutes per IP by route-level middleware.
     *
     * @param Request $request
     * @return never
     */
    public function login(Request $request): never
    {
        try {
            $validated = (new Validator())
                ->field('email')->required()->email()->max(255)
                ->field('password')->required()->min(1)->max(255)
                ->validate($request->all());
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors());
        }

        try {
            $result = $this->authService->login(
                email:     (string) $validated['email'],
                password:  (string) $validated['password'],
                ip:        $request->ip(),
                userAgent: $request->userAgent(),
            );

            Response::success($result, 'Login successful.');
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors(), $e->getMessage());
        } catch (\RuntimeException $e) {
            // Generic 401 — never hint at whether email or password was wrong.
            Response::unauthorized($e->getMessage());
        } catch (\Throwable $e) {
            Response::serverError('Login failed. Please try again.');
        }
    }

    // ─── POST /api/v1/auth/logout [auth required] ─────────────────────────────

    /**
     * Log out the authenticated user and revoke their tokens.
     *
     * Optionally accepts a refresh_token body field to deactivate the
     * session immediately (improves security; not strictly required).
     *
     * @param Request $request
     * @return never
     */
    public function logout(Request $request): never
    {
        $user         = $request->user();
        $jti          = (string) ($user['jti'] ?? '');
        $userId       = (string) ($user['id']  ?? '');
        $refreshToken = (string) ($request->get('refresh_token') ?? '');

        try {
            $this->authService->logout($userId, $jti, $refreshToken);
            Response::noContent();
        } catch (\Throwable $e) {
            Response::serverError('Logout failed.');
        }
    }

    // ─── POST /api/v1/auth/refresh ────────────────────────────────────────────

    /**
     * Exchange a refresh token for a new access token.
     *
     * Required body field: refresh_token.
     *
     * @param Request $request
     * @return never
     */
    public function refresh(Request $request): never
    {
        try {
            $validated = (new Validator())
                ->field('refresh_token')->required()->min(10)
                ->validate($request->all());
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors());
        }

        try {
            $result = $this->authService->refreshToken((string) $validated['refresh_token']);
            Response::success($result, 'Token refreshed.');
        } catch (\RuntimeException $e) {
            Response::unauthorized($e->getMessage());
        } catch (\Throwable $e) {
            Response::serverError('Token refresh failed.');
        }
    }

    // ─── POST /api/v1/auth/forgot-password ───────────────────────────────────

    /**
     * Initiate a password reset flow.
     *
     * Always returns 200 regardless of whether the email exists,
     * to prevent user enumeration.
     *
     * Required body field: email.
     *
     * @param Request $request
     * @return never
     */
    public function forgotPassword(Request $request): never
    {
        try {
            $validated = (new Validator())
                ->field('email')->required()->email()->max(255)
                ->validate($request->all());
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors());
        }

        try {
            $this->authService->forgotPassword((string) $validated['email']);
        } catch (\Throwable) {
            // Swallow all errors — never reveal existence of the email.
        }

        Response::success(
            null,
            'If an account with that email exists, a reset link has been sent.'
        );
    }

    // ─── POST /api/v1/auth/reset-password ────────────────────────────────────

    /**
     * Complete a password reset using the token from the email link.
     *
     * Required body fields: token, password, password_confirm.
     *
     * @param Request $request
     * @return never
     */
    public function resetPassword(Request $request): never
    {
        try {
            $validated = (new Validator())
                ->field('token')->required()->min(10)
                ->field('password')->required()->min(12)->max(255)
                ->field('password_confirm')->required()
                ->validate($request->all());
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors());
        }

        if ((string) $validated['password'] !== (string) $validated['password_confirm']) {
            Response::validationError(['password_confirm' => 'Passwords do not match.']);
        }

        try {
            $this->authService->resetPassword(
                token:       (string) $validated['token'],
                newPassword: (string) $validated['password'],
            );

            Response::success(null, 'Password has been reset. Please log in with your new password.');
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors(), $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::serverError('Password reset failed.');
        }
    }

    // ─── GET /api/v1/auth/verify-email/{token} ───────────────────────────────

    /**
     * Verify a user's email address using the link token.
     *
     * @param Request $request (token extracted from path parameter by Router)
     * @return never
     */
    public function verifyEmail(Request $request): never
    {
        $token = $request->param('token');

        if ($token === '') {
            Response::error('Verification token is required.', 400);
        }

        try {
            $this->authService->verifyEmail($token);
            Response::success(null, 'Email address verified successfully.');
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::serverError('Email verification failed.');
        }
    }

    // ─── GET /api/v1/auth/me [auth required] ─────────────────────────────────

    /**
     * Return the authenticated user's profile data.
     *
     * @param Request $request
     * @return never
     */
    public function me(Request $request): never
    {
        $user = $request->user();

        // Remove internal JWT metadata before returning to client.
        unset($user['jti']);

        Response::success(['user' => $user]);
    }

    // ─── PUT /api/v1/auth/profile [auth required] ────────────────────────────

    /**
     * Update the authenticated user's profile.
     *
     * Accepted body fields: username, first_name, last_name, phone, avatar_url.
     *
     * @param Request $request
     * @return never
     */
    public function updateProfile(Request $request): never
    {
        $userId = (string) ($request->user()['id'] ?? '');

        try {
            $user = $this->authService->updateProfile($userId, $request->all());

            Response::success(['user' => $user], 'Profile updated.');
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors(), $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::serverError('Profile update failed.');
        }
    }

    // ─── POST /api/v1/auth/change-password [auth required] ───────────────────

    /**
     * Change the authenticated user's password.
     *
     * Required body fields: current_password, new_password, new_password_confirm.
     *
     * @param Request $request
     * @return never
     */
    public function changePassword(Request $request): never
    {
        try {
            $validated = (new Validator())
                ->field('current_password')->required()->min(1)->max(255)
                ->field('new_password')->required()->min(12)->max(255)
                ->field('new_password_confirm')->required()
                ->validate($request->all());
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors());
        }

        if ((string) $validated['new_password'] !== (string) $validated['new_password_confirm']) {
            Response::validationError(['new_password_confirm' => 'New passwords do not match.']);
        }

        $userId = (string) ($request->user()['id'] ?? '');

        try {
            $this->authService->changePassword(
                userId:      $userId,
                oldPassword: (string) $validated['current_password'],
                newPassword: (string) $validated['new_password'],
            );

            Response::success(null, 'Password changed. Please log in again with your new password.');
        } catch (ValidationException $e) {
            Response::validationError($e->getFieldErrors(), $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::serverError('Password change failed.');
        }
    }
}
