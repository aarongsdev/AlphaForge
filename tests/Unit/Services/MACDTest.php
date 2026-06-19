<?php

declare(strict_types=1);

namespace AlphaForge\Tests\Unit\Services;

use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\MACD;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the MACD (Moving Average Convergence Divergence) indicator.
 *
 * Tests cover: output structure, histogram arithmetic identity, edge cases
 * (empty input), and verification that sufficient data produces non-null values.
 */
class MACDTest extends TestCase
{
    private MACD $macd;

    protected function setUp(): void
    {
        $this->macd = new MACD();
    }

    // ── Output structure ─────────────────────────────────────────────────────

    /**
     * calculate() must return an array of the same length as the input.
     * Every element must be an associative array with exactly the keys
     * 'macd', 'signal', and 'histogram', each holding a float or null.
     */
    public function testCalculateReturnsCorrectStructure(): void
    {
        $closes = $this->makeCloses(50);
        $result = $this->macd->calculate($closes);

        $this->assertCount(50, $result, 'Output length must equal input length');

        foreach ($result as $i => $entry) {
            $this->assertIsArray($entry, "Entry at index {$i} must be an array");
            $this->assertArrayHasKey('macd',      $entry, "Entry at {$i} missing key 'macd'");
            $this->assertArrayHasKey('signal',    $entry, "Entry at {$i} missing key 'signal'");
            $this->assertArrayHasKey('histogram', $entry, "Entry at {$i} missing key 'histogram'");

            // Each key must be float or null.
            foreach (['macd', 'signal', 'histogram'] as $key) {
                $this->assertTrue(
                    $entry[$key] === null || is_float($entry[$key]),
                    "Entry[{$i}]['{$key}'] must be float or null, got " . gettype($entry[$key])
                );
            }
        }
    }

    // ── Histogram arithmetic identity ────────────────────────────────────────

    /**
     * The histogram is defined as MACD line minus Signal line.
     * For every entry where both macd and signal are non-null, the identity
     *   histogram === macd - signal
     * must hold within a floating-point tolerance of 0.0001.
     */
    public function testHistogramIsMACSMinusSignal(): void
    {
        $closes = $this->makeCloses(60);
        $result = $this->macd->calculate($closes);

        $checkedCount = 0;

        foreach ($result as $i => $entry) {
            if ($entry['macd'] === null || $entry['signal'] === null) {
                // Histogram must also be null when either component is null.
                $this->assertNull(
                    $entry['histogram'],
                    "Histogram at {$i} must be null when macd or signal is null"
                );
                continue;
            }

            $this->assertNotNull(
                $entry['histogram'],
                "Histogram at {$i} must be non-null when macd and signal are both non-null"
            );

            $expected = $entry['macd'] - $entry['signal'];
            $this->assertEqualsWithDelta(
                $expected,
                $entry['histogram'],
                0.0001,
                "Histogram at {$i} does not equal macd - signal"
            );

            $checkedCount++;
        }

        // Sanity: at least one fully-computed entry must have been verified.
        $this->assertGreaterThan(
            0,
            $checkedCount,
            'No fully-computed MACD entries found to verify the histogram identity'
        );
    }

    // ── Edge: empty array ────────────────────────────────────────────────────

    /**
     * An empty input must produce an empty output array without throwing.
     */
    public function testEmptyArrayReturnsEmpty(): void
    {
        $result = $this->macd->calculate([]);
        $this->assertSame([], $result);
    }

    // ── Sufficient data ───────────────────────────────────────────────────────

    /**
     * With 50 closing prices the default MACD (12/26/9) requires at least
     * 26 + 9 - 1 = 34 data points to produce a fully computed entry. With 50
     * bars, at least some entries toward the end must be non-null for all three
     * components.
     */
    public function testSufficientDataProducesValues(): void
    {
        $closes = $this->makeCloses(50);
        $result = $this->macd->calculate($closes);

        $fullyComputedCount = 0;

        foreach ($result as $entry) {
            if (
                $entry['macd']      !== null
                && $entry['signal']    !== null
                && $entry['histogram'] !== null
            ) {
                $fullyComputedCount++;
            }
        }

        $this->assertGreaterThan(
            0,
            $fullyComputedCount,
            'Expected at least one fully-computed MACD/Signal/Histogram entry with 50 closes'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Generate a realistic-looking price series using a random walk seeded
     * deterministically so tests are reproducible.
     *
     * @return float[]
     */
    private function makeCloses(int $count): array
    {
        // Use a fixed deterministic sequence to keep tests repeatable.
        $prices   = [];
        $price    = 100.0;
        // A simple LCG for determinism (no mt_srand needed).
        $seed     = 42;

        for ($i = 0; $i < $count; $i++) {
            $prices[] = $price;
            // LCG: X_{n+1} = (a * X_n + c) mod m
            $seed  = (1664525 * $seed + 1013904223) & 0x7FFFFFFF;
            // Map to a small move in [-2, +2].
            $move  = ($seed / 0x7FFFFFFF) * 4.0 - 2.0;
            $price = max(1.0, $price + $move);
        }

        return $prices;
    }
}
