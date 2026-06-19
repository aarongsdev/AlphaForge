<?php

declare(strict_types=1);

namespace AlphaForge\Tests\Unit\Services;

use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\BollingerBands;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Bollinger Bands indicator.
 *
 * Tests cover: band ordering (upper > lower), middle band containment,
 * width arithmetic identity, and %B value when price equals the middle band.
 */
class BollingerBandsTest extends TestCase
{
    private BollingerBands $bb;

    protected function setUp(): void
    {
        $this->bb = new BollingerBands();
    }

    // ── Upper always above lower ──────────────────────────────────────────────

    /**
     * For every non-null Bollinger Band entry, the upper band must strictly
     * exceed the lower band. This holds as long as there is any price variance
     * in the window (a constant-price series would produce upper == lower, so
     * we use a volatile series here).
     */
    public function testUpperBandAlwaysAboveLower(): void
    {
        $closes = $this->makeVolatileCloses(50, 100.0);
        $result = $this->bb->calculate($closes);

        $checkedCount = 0;

        foreach ($result as $i => $entry) {
            if ($entry['upper'] === null) {
                continue;
            }

            $this->assertGreaterThan(
                $entry['lower'],
                $entry['upper'],
                "Upper band ({$entry['upper']}) must be > lower band ({$entry['lower']}) at index {$i}"
            );

            $checkedCount++;
        }

        $this->assertGreaterThan(0, $checkedCount, 'No non-null Bollinger Band entries found');
    }

    // ── Middle band between lower and upper ──────────────────────────────────

    /**
     * The middle band is the SMA of the window. It must always lie between
     * the lower and upper bands (inclusive) for every non-null entry.
     */
    public function testMiddleBandIsInRange(): void
    {
        $closes = $this->makeVolatileCloses(50, 100.0);
        $result = $this->bb->calculate($closes);

        foreach ($result as $i => $entry) {
            if ($entry['middle'] === null) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                $entry['lower'],
                $entry['middle'],
                "Middle ({$entry['middle']}) must be >= lower ({$entry['lower']}) at index {$i}"
            );

            $this->assertLessThanOrEqual(
                $entry['upper'],
                $entry['middle'],
                "Middle ({$entry['middle']}) must be <= upper ({$entry['upper']}) at index {$i}"
            );
        }
    }

    // ── Width arithmetic identity ─────────────────────────────────────────────

    /**
     * Width is defined as (upper - lower) / middle.
     * For every non-null entry with a non-zero middle band, the stored width
     * must match this formula within a tolerance of 0.0001.
     */
    public function testWidthCalculation(): void
    {
        $closes = $this->makeVolatileCloses(50, 100.0);
        $result = $this->bb->calculate($closes);

        $checkedCount = 0;

        foreach ($result as $i => $entry) {
            if ($entry['width'] === null || $entry['middle'] === null) {
                continue;
            }

            // Avoid division by zero (middle is always > 0 for positive prices).
            if ($entry['middle'] == 0.0) {
                continue;
            }

            $expected = ($entry['upper'] - $entry['lower']) / $entry['middle'];

            $this->assertEqualsWithDelta(
                $expected,
                $entry['width'],
                0.0001,
                "Width at index {$i} does not equal (upper - lower) / middle"
            );

            $checkedCount++;
        }

        $this->assertGreaterThan(0, $checkedCount, 'No non-null width entries found to verify');
    }

    // ── %B when price == middle band ─────────────────────────────────────────

    /**
     * %B is defined as (Close - Lower) / (Upper - Lower).
     * When the closing price equals the middle band exactly, %B must equal 0.5
     * because the middle band is equidistant from upper and lower by definition
     * (Middle - Lower == Upper - Middle == stdDev * multiplier).
     *
     * Strategy: build a constant price series so that the middle (SMA) equals
     * every price and upper/lower are symmetric around it. Then verify %B == 0.5.
     */
    public function testPercentBCalculation(): void
    {
        // A constant-price series ensures SMA == every close. But with zero std-dev
        // the bands collapse (upper == middle == lower) and %B is forced to 0.5 by
        // the fillBand() guard. That still verifies the formula handles the edge case.
        //
        // For a meaningful test with non-collapsed bands, introduce tiny variance and
        // then manually verify the formula holds for one specific entry.

        // 30 closes with a known pattern: first 20 are 100, last 10 step up.
        $closes = array_merge(
            array_fill(0, 20, 100.0),
            [101.0, 102.0, 103.0, 104.0, 105.0, 106.0, 107.0, 108.0, 109.0, 110.0]
        );

        $result = $this->bb->calculate($closes, 20);

        // At index 19 (the first non-null entry) all 20 values are 100.0.
        // SMA = 100, std-dev = 0 -> bands collapse -> %B = 0.5 (by guard in fillBand).
        $this->assertNotNull($result[19], 'Index 19 must be non-null (first window complete)');
        $this->assertEqualsWithDelta(
            0.5,
            $result[19]['percent_b'],
            0.0001,
            '%B must be 0.5 when price equals middle band (collapsed bands)'
        );

        // Now verify the %B formula for an entry where bands are non-collapsed.
        // Check every non-null entry to make sure %B == (close - lower)/(upper - lower).
        $checkedNonCollapsed = 0;
        foreach ($result as $i => $entry) {
            if ($entry['upper'] === null || $entry['lower'] === null) {
                continue;
            }

            $range = $entry['upper'] - $entry['lower'];

            if ($range < 1e-9) {
                // Collapsed bands: formula is guarded, skip arithmetic check.
                continue;
            }

            $expectedPB = ($closes[$i] - $entry['lower']) / $range;
            $this->assertEqualsWithDelta(
                $expectedPB,
                $entry['percent_b'],
                0.0001,
                "%B at index {$i} does not equal (close - lower) / (upper - lower)"
            );
            $checkedNonCollapsed++;
        }

        // At least the last several entries have variance and non-collapsed bands.
        $this->assertGreaterThan(
            0,
            $checkedNonCollapsed,
            'Expected at least one non-collapsed band entry to verify %B formula'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Generate a volatile (zigzag) closing-price series to ensure the std-dev
     * window always has non-zero variance, keeping bands non-collapsed.
     *
     * @return float[]
     */
    private function makeVolatileCloses(int $count, float $start): array
    {
        $closes    = [];
        $price     = $start;
        $direction = 1.0;

        for ($i = 0; $i < $count; $i++) {
            $closes[] = $price;
            // Use varying step sizes to avoid a strictly periodic series.
            $step     = 2.0 + ($i % 5);
            $price   += $direction * $step;
            $direction *= -1.0;

            // Keep prices positive.
            if ($price < 10.0) {
                $price     = 10.0;
                $direction = 1.0;
            }
        }

        return $closes;
    }
}
