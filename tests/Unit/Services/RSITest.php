<?php

declare(strict_types=1);

namespace AlphaForge\Tests\Unit\Services;

use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\RSI;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RSI (Relative Strength Index) indicator.
 *
 * Tests cover: output count/shape, value range, signal interpretation,
 * edge cases (empty / single value), default period, and custom period.
 */
class RSITest extends TestCase
{
    private RSI $rsi;

    protected function setUp(): void
    {
        $this->rsi = new RSI();
    }

    // ── Output count / null positions ────────────────────────────────────────

    /**
     * With 30 closing prices and the default period of 14, calculate() must
     * return exactly 30 values, and the first 13 (indices 0-12) must be null
     * because there is insufficient data to seed Wilder's average.
     * The value at index 14 (the $period-th close) is the first non-null RSI.
     */
    public function testCalculateReturnsCorrectCount(): void
    {
        $closes = $this->makeLinearCloses(30, 100.0, 1.0);
        $result = $this->rsi->calculate($closes);

        $this->assertCount(30, $result, 'Output array length must equal input length');

        for ($i = 0; $i < 13; $i++) {
            $this->assertNull($result[$i], "Index {$i} should be null (insufficient data)");
        }

        // Index 14 onward must be non-null (the seeded RSI and Wilder-smoothed values).
        for ($i = 14; $i < 30; $i++) {
            $this->assertNotNull($result[$i], "Index {$i} should be a computed RSI value");
        }
    }

    // ── Value range ──────────────────────────────────────────────────────────

    /**
     * Every non-null RSI value must lie in the closed interval [0, 100].
     * This invariant holds regardless of price direction.
     */
    public function testRSIValueIsInValidRange(): void
    {
        // Use a volatile (zigzag) series to exercise both gains and losses.
        $closes = $this->makeZigzagCloses(50, 100.0, 5.0);
        $result = $this->rsi->calculate($closes);

        foreach ($result as $i => $value) {
            if ($value === null) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                0.0,
                $value,
                "RSI at index {$i} ({$value}) is below 0"
            );
            $this->assertLessThanOrEqual(
                100.0,
                $value,
                "RSI at index {$i} ({$value}) is above 100"
            );
        }
    }

    // ── Oversold detection ───────────────────────────────────────────────────

    /**
     * A series of 30 consecutive declining prices (no gains) causes avgLoss to
     * dominate, pushing RSI toward 0. The interpretation must return 'oversold'.
     */
    public function testOversoldDetection(): void
    {
        // 30 strictly decreasing prices: each bar loses $2.
        $closes = $this->makeLinearCloses(30, 160.0, -2.0);
        $result = $this->rsi->calculate($closes);

        // Pick the last non-null RSI value.
        $lastRsi = $this->lastNonNull($result);

        $this->assertNotNull($lastRsi, 'Expected at least one non-null RSI value');
        $this->assertLessThan(30.0, $lastRsi, "RSI {$lastRsi} should be below 30 for a declining series");

        $interpretation = $this->rsi->interpret($lastRsi);
        $this->assertSame('oversold', $interpretation['signal']);
    }

    // ── Overbought detection ─────────────────────────────────────────────────

    /**
     * A series of 30 consecutive advancing prices (no losses) causes avgGain to
     * dominate, pushing RSI toward 100. The interpretation must return 'overbought'.
     */
    public function testOverboughtDetection(): void
    {
        // 30 strictly increasing prices: each bar gains $2.
        $closes = $this->makeLinearCloses(30, 100.0, 2.0);
        $result = $this->rsi->calculate($closes);

        $lastRsi = $this->lastNonNull($result);

        $this->assertNotNull($lastRsi, 'Expected at least one non-null RSI value');
        $this->assertGreaterThan(70.0, $lastRsi, "RSI {$lastRsi} should be above 70 for an advancing series");

        $interpretation = $this->rsi->interpret($lastRsi);
        $this->assertSame('overbought', $interpretation['signal']);
    }

    // ── Neutral detection ────────────────────────────────────────────────────

    /**
     * A zigzag series with equal-magnitude gains and losses produces an RSI near
     * 50 (neutral zone between 30 and 70).
     */
    public function testNeutralDetection(): void
    {
        // Alternating +1/-1 moves: gains == losses in every period seed.
        $closes = [];
        $price  = 100.0;
        for ($i = 0; $i < 50; $i++) {
            $closes[] = $price;
            $price   += ($i % 2 === 0) ? 1.0 : -1.0;
        }

        $result  = $this->rsi->calculate($closes);
        $lastRsi = $this->lastNonNull($result);

        $this->assertNotNull($lastRsi);

        $interpretation = $this->rsi->interpret($lastRsi);
        $this->assertSame(
            'neutral',
            $interpretation['signal'],
            "RSI {$lastRsi} should be neutral (between 30 and 70)"
        );
    }

    // ── Edge: empty array ────────────────────────────────────────────────────

    /**
     * An empty input array must produce an empty output array, not an error.
     */
    public function testEmptyArrayReturnsEmpty(): void
    {
        $result = $this->rsi->calculate([]);
        $this->assertSame([], $result);
    }

    // ── Edge: single value ───────────────────────────────────────────────────

    /**
     * A single closing price cannot form any change, so the only output element
     * must be null.
     */
    public function testSingleValueReturnsNull(): void
    {
        $result = $this->rsi->calculate([150.0]);
        $this->assertCount(1, $result);
        $this->assertNull($result[0]);
    }

    // ── Default period ───────────────────────────────────────────────────────

    /**
     * When calculate() is called without a second argument the default period of
     * 14 is used. Therefore indices 0-12 are null and index 14 is the first
     * computed value (13 nulls = period - 1 = 14 - 1).
     */
    public function testDefaultPeriodIs14(): void
    {
        $closes = $this->makeLinearCloses(20, 100.0, 0.5);

        // Explicit default call (no period argument).
        $result = $this->rsi->calculate($closes);

        // Indices 0 through 12 (13 values) must be null.
        for ($i = 0; $i <= 12; $i++) {
            $this->assertNull($result[$i], "Index {$i} must be null with default period 14");
        }

        // Index 14 is the seeded RSI (first non-null).
        $this->assertNotNull($result[14], 'Index 14 must be the first non-null RSI value');
    }

    // ── Custom period ────────────────────────────────────────────────────────

    /**
     * When period = 5 is passed, indices 0-3 (4 values = period - 1) are null
     * and index 5 is the first non-null value.
     */
    public function testCustomPeriod(): void
    {
        $closes = $this->makeLinearCloses(15, 100.0, 1.0);
        $result = $this->rsi->calculate($closes, 5);

        // First 4 indices must be null.
        for ($i = 0; $i <= 3; $i++) {
            $this->assertNull($result[$i], "Index {$i} must be null with period 5");
        }

        // Index 5 is the seeded value.
        $this->assertNotNull($result[5], 'Index 5 must be the first non-null RSI value with period 5');

        // All subsequent indices must also be non-null.
        for ($i = 6; $i < 15; $i++) {
            $this->assertNotNull($result[$i], "Index {$i} must be non-null with period 5");
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build a strictly linear price series.
     *
     * @return float[]
     */
    private function makeLinearCloses(int $count, float $start, float $step): array
    {
        $closes = [];
        $price  = $start;

        for ($i = 0; $i < $count; $i++) {
            $closes[] = $price;
            $price   += $step;
        }

        return $closes;
    }

    /**
     * Build a zigzag price series alternating between +$amplitude and -$amplitude.
     *
     * @return float[]
     */
    private function makeZigzagCloses(int $count, float $start, float $amplitude): array
    {
        $closes = [];
        $price  = $start;

        for ($i = 0; $i < $count; $i++) {
            $closes[] = $price;
            $price   += ($i % 2 === 0) ? $amplitude : -$amplitude;
        }

        return $closes;
    }

    /**
     * Return the last non-null element from an array, or null if all are null.
     *
     * @param array<int, float|null> $values
     */
    private function lastNonNull(array $values): ?float
    {
        foreach (array_reverse($values) as $v) {
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }
}
