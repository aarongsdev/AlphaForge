<?php

declare(strict_types=1);

namespace AlphaForge\Tests\Unit\Services;

use AlphaForge\Services\Auth\PasswordService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PasswordService.
 *
 * Tests cover: Argon2id hashing, verification, salting, password strength
 * validation, and reset-token generation.
 *
 * Note: Argon2id operations are intentionally expensive. Test cost parameters
 * are the live Argon2id defaults from PasswordService (memory=65536, time=4,
 * threads=3). If tests are too slow in CI, consider lowering the cost via
 * environment variables or a test-specific subclass.
 */
class PasswordServiceTest extends TestCase
{
    private PasswordService $service;

    protected function setUp(): void
    {
        $this->service = new PasswordService();
    }

    // ── Hashing ───────────────────────────────────────────────────────────────

    /**
     * The stored hash must never equal the plaintext password.
     */
    public function testHashIsNotPlaintext(): void
    {
        $plaintext = 'MyP@ssword123';
        $hash      = $this->service->hash($plaintext);

        $this->assertNotSame(
            $plaintext,
            $hash,
            'Stored hash must not be equal to the plaintext password'
        );
    }

    /**
     * The hash must be a non-empty string in Argon2id PHC format.
     */
    public function testHashReturnsArgon2idString(): void
    {
        $hash = $this->service->hash('TestP@ssword99');

        $this->assertStringStartsWith(
            '$argon2id$',
            $hash,
            'Hash must use Argon2id algorithm (PHC format starting with $argon2id$)'
        );
    }

    // ── Verification ─────────────────────────────────────────────────────────

    /**
     * Verifying the correct plaintext against its hash must return true.
     */
    public function testHashVerifiesCorrectly(): void
    {
        $plaintext = 'MyP@ssword123';
        $hash      = $this->service->hash($plaintext);

        $this->assertTrue(
            $this->service->verify($plaintext, $hash),
            'verify() must return true for the correct password'
        );
    }

    /**
     * Verifying an incorrect plaintext must return false.
     */
    public function testWrongPasswordFails(): void
    {
        $hash = $this->service->hash('correct');

        $this->assertFalse(
            $this->service->verify('wrong', $hash),
            'verify() must return false for an incorrect password'
        );
    }

    /**
     * Verifying with an empty string must return false.
     */
    public function testEmptyPasswordFails(): void
    {
        $hash = $this->service->hash('SomeP@ssword1');

        $this->assertFalse(
            $this->service->verify('', $hash),
            'verify() must return false for an empty string'
        );
    }

    // ── Salting ───────────────────────────────────────────────────────────────

    /**
     * Hashing the same password twice must produce two different strings because
     * Argon2id generates a unique random salt for each invocation.
     */
    public function testDifferentHashesForSamePassword(): void
    {
        $plaintext = 'MyP@ssword123';
        $hash1     = $this->service->hash($plaintext);
        $hash2     = $this->service->hash($plaintext);

        $this->assertNotSame(
            $hash1,
            $hash2,
            'Two hashes of the same password must differ due to unique salts'
        );

        // Despite different hashes, both must verify correctly.
        $this->assertTrue($this->service->verify($plaintext, $hash1));
        $this->assertTrue($this->service->verify($plaintext, $hash2));
    }

    // ── Strength validation ───────────────────────────────────────────────────

    /**
     * A password meeting all AlphaForge policy requirements must pass.
     *
     * Policy (from PasswordService::validateStrength):
     *   - Minimum 12 characters
     *   - At least one uppercase letter
     *   - At least one lowercase letter
     *   - At least one digit
     *   - At least one special character
     */
    public function testStrongPasswordPasses(): void
    {
        $result = $this->service->validateStrength('AlphaF0rge!2024');

        $this->assertTrue(
            $result['valid'],
            'AlphaF0rge!2024 should pass strength validation. Errors: '
            . implode(', ', $result['errors'])
        );
        $this->assertEmpty($result['errors'], 'No errors expected for a strong password');
    }

    /**
     * A password consisting only of digits must fail on multiple rules
     * (no uppercase, no lowercase, no special char).
     */
    public function testWeakPasswordFails(): void
    {
        $result = $this->service->validateStrength('12345678');

        $this->assertFalse(
            $result['valid'],
            '12345678 must fail strength validation (too short, missing character classes)'
        );
        $this->assertNotEmpty($result['errors'], 'Errors must be returned for a weak password');
    }

    /**
     * A password that is exactly 11 characters (one short) must fail.
     */
    public function testPasswordTooShortFails(): void
    {
        // 11 characters, otherwise meets all other rules.
        $result = $this->service->validateStrength('AlphaF0rge!');

        $this->assertFalse($result['valid'], '11-character password must fail the minimum-length rule');

        $lengthErrorFound = false;
        foreach ($result['errors'] as $error) {
            if (stripos($error, '12') !== false || stripos($error, 'least') !== false) {
                $lengthErrorFound = true;
                break;
            }
        }

        $this->assertTrue($lengthErrorFound, 'Expected a length-related error message');
    }

    /**
     * A password missing an uppercase letter must fail and produce a relevant error.
     */
    public function testPasswordMissingUppercaseFails(): void
    {
        $result = $this->service->validateStrength('alphaf0rge!2024');

        $this->assertFalse($result['valid']);
        $uppercaseErrorFound = false;
        foreach ($result['errors'] as $error) {
            if (stripos($error, 'uppercase') !== false) {
                $uppercaseErrorFound = true;
                break;
            }
        }
        $this->assertTrue($uppercaseErrorFound, 'Expected an uppercase-related error message');
    }

    /**
     * A password missing a digit must fail.
     */
    public function testPasswordMissingDigitFails(): void
    {
        $result = $this->service->validateStrength('AlphaForge!Strong');

        $this->assertFalse($result['valid']);

        $digitErrorFound = false;
        foreach ($result['errors'] as $error) {
            if (stripos($error, 'digit') !== false) {
                $digitErrorFound = true;
                break;
            }
        }
        $this->assertTrue($digitErrorFound, 'Expected a digit-related error message');
    }

    /**
     * A password missing a special character must fail.
     */
    public function testPasswordMissingSpecialCharFails(): void
    {
        $result = $this->service->validateStrength('AlphaForge12345');

        $this->assertFalse($result['valid']);

        $specialErrorFound = false;
        foreach ($result['errors'] as $error) {
            if (stripos($error, 'special') !== false) {
                $specialErrorFound = true;
                break;
            }
        }
        $this->assertTrue($specialErrorFound, 'Expected a special-character-related error message');
    }

    /**
     * validateStrength() must return an array with 'valid' (bool) and
     * 'errors' (list<string>) keys regardless of input.
     */
    public function testValidateStrengthReturnsExpectedShape(): void
    {
        $result = $this->service->validateStrength('anything');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid',  $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsBool($result['valid']);
        $this->assertIsArray($result['errors']);
    }

    // ── Reset token generation ────────────────────────────────────────────────

    /**
     * generateResetToken() must return an array with keys 'token', 'hash',
     * and 'expires_at'. The expiry must be a Unix timestamp in the future.
     */
    public function testResetTokenHasExpiry(): void
    {
        $data = $this->service->generateResetToken();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('token',      $data);
        $this->assertArrayHasKey('hash',       $data);
        $this->assertArrayHasKey('expires_at', $data);

        $this->assertIsString($data['token']);
        $this->assertIsString($data['hash']);
        $this->assertIsInt($data['expires_at']);

        $this->assertGreaterThan(
            time(),
            $data['expires_at'],
            'expires_at must be a Unix timestamp in the future'
        );
    }

    /**
     * The plaintext token must not equal its hash (SHA-256 of 64 random bytes
     * produces a 64-char hex string; the token itself is a 128-char hex string).
     */
    public function testResetTokenHashDiffers(): void
    {
        $data = $this->service->generateResetToken();

        $this->assertNotSame(
            $data['token'],
            $data['hash'],
            'Plaintext token and its hash must be different strings'
        );
    }

    /**
     * Two calls must produce different tokens (cryptographic randomness).
     */
    public function testResetTokensAreUnique(): void
    {
        $first  = $this->service->generateResetToken();
        $second = $this->service->generateResetToken();

        $this->assertNotSame($first['token'], $second['token'], 'Reset tokens must be unique across calls');
        $this->assertNotSame($first['hash'],  $second['hash'],  'Token hashes must be unique across calls');
    }

    /**
     * hashResetToken() must be deterministic: hashing the same plaintext token
     * twice must produce the same SHA-256 hash.
     */
    public function testHashResetTokenIsDeterministic(): void
    {
        $data   = $this->service->generateResetToken();
        $token  = $data['token'];

        $hash1 = $this->service->hashResetToken($token);
        $hash2 = $this->service->hashResetToken($token);

        $this->assertSame(
            $hash1,
            $hash2,
            'hashResetToken() must produce the same output for the same input'
        );

        // Must match the hash stored in the token data.
        $this->assertSame($data['hash'], $hash1);
    }

    /**
     * The plaintext token must be a long hex string (128 chars for 64 random bytes).
     */
    public function testResetTokenLength(): void
    {
        $data = $this->service->generateResetToken();

        $this->assertSame(
            128,
            strlen($data['token']),
            'Reset token must be 128 hex characters (64 random bytes via bin2hex)'
        );
    }
}
