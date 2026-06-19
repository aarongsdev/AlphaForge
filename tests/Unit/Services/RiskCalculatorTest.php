<?php

declare(strict_types=1);

namespace AlphaForge\Tests\Unit\Services;

use AlphaForge\Services\Prediction\RiskCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RiskCalculator.
 *
 * Tests use small, deterministic data sets so expected values can be verified
 * by hand without external tools.
 */
class RiskCalculatorTest extends TestCase
{
    private RiskCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RiskCalculator();
    }

    // ── Max Drawdown ──────────────────────────────────────────────────────────

    /**
     * Price series: [100, 90, 80, 95, 85, 110]
     *
     * Walk through:
     *   Peak starts at 100 (index 0).
     *   Bar 1: price 90  -> drawdown = (90 - 100) / 100 = -10%
     *   Bar 2: price 80  -> drawdown = (80 - 100) / 100 = -20%  <-- worst so far
     *   Bar 3: price 95  -> new peak? No (95 < 100). drawdown = (95 - 100) / 100 = -5%
     *   Bar 4: price 85  -> drawdown = (85 - 100) / 100 = -15%
     *   Bar 5: price 110 -> new peak = 110. No drawdown from 110.
     *
     * Maximum drawdown = 20% (from 100 to 80).
     * The result is stored as a negative percentage in max_drawdown_percent: -20.0
     */
    public function testMaxDrawdown(): void
    {
        $prices = [100.0, 90.0, 80.0, 95.0, 85.0, 110.0];
        $result = $this->calc->calculateMaxDrawdown($prices);

        $this->assertArrayHasKey('max_drawdown_percent', $result);
        $this->assertArrayHasKey('peak_index',           $result);
        $this->assertArrayHasKey('trough_index',         $result);

        // The max drawdown is -20% (stored as negative by RiskCalculator).
        $this->assertEqualsWithDelta(
            -20.0,
            $result['max_drawdown_percent'],
            0.0001,
            'Max drawdown from 100 to 80 should be -20%'
        );

        // Peak was at index 0 (price 100).
        $this->assertSame(0, $result['peak_index']);

        // Trough was at index 2 (price 80).
        $this->assertSame(2, $result['trough_index']);
    }

    /**
     * Edge case: a strictly ascending price series has no drawdown.
     */
    public function testMaxDrawdownWithAscendingSeries(): void
    {
        $prices = [100.0, 105.0, 110.0, 120.0, 130.0];
        $result = $this->calc->calculateMaxDrawdown($prices);

        $this->assertEqualsWithDelta(
            0.0,
            $result['max_drawdown_percent'],
            0.0001,
            'Ascending prices should produce 0% max drawdown'
        );
    }

    /**
     * Edge case: fewer than 2 prices returns a zeroed result.
     */
    public function testMaxDrawdownWithSinglePrice(): void
    {
        $result = $this->calc->calculateMaxDrawdown([100.0]);

        $this->assertEqualsWithDelta(
            0.0,
            $result['max_drawdown_percent'],
            0.0001
        );
    }

    // ── Sharpe Ratio ──────────────────────────────────────────────────────────

    /**
     * Sharpe ratio with riskFreeRate = 0 and a constant daily return of 0.01:
     *
     *   avgReturn        = 0.01
     *   annualisedReturn = 0.01 * 252 = 2.52
     *   stdDev           = 0 (constant returns)
     *   annualisedVol    = 0
     *
     * When volatility is zero, RiskCalculator returns 0.0 (guard against
     * division by zero).
     */
    public function testSharpeRatioWithZeroVolatility(): void
    {
        $returns = array_fill(0, 252, 0.01);
        $sharpe  = $this->calc->calculateSharpeRatio($returns, 0.0);

        $this->assertEqualsWithDelta(
            0.0,
            $sharpe,
            0.0001,
            'Sharpe must be 0.0 when volatility is zero (guard against division by zero)'
        );
    }

    /**
     * Sharpe ratio with a risk-free rate of 0 and known mean / std-dev.
     *
     * Daily returns: 10 days alternating between +0.02 and -0.01.
     *   Mean = (5 * 0.02 + 5 * (-0.01)) / 10 = (0.10 - 0.05) / 10 = 0.005
     *   Annualised return = 0.005 * 252 = 1.26
     *
     *   Population std-dev (n denominator, matching RiskCalculator):
     *     deviations: 0.015 (five times), -0.015 (five times)
     *     variance = sum((0.015)^2 * 10) / 10 = 0.000225
     *     std-dev  = 0.015
     *   Annualised vol = 0.015 * sqrt(252) ≈ 0.23812
     *
     *   Sharpe = 1.26 / 0.23812 ≈ 5.29
     *
     * We accept a delta of 0.01 to account for the std-dev formula RiskCalculator
     * uses (n denominator vs n-1 denominator).
     */
    public function testSharpeRatioWithZeroRiskFree(): void
    {
        $returns = [];
        for ($i = 0; $i < 10; $i++) {
            $returns[] = ($i % 2 === 0) ? 0.02 : -0.01;
        }

        $sharpe = $this->calc->calculateSharpeRatio($returns, 0.0);

        // The result must be positive (mean return > 0) and reasonably large.
        $this->assertGreaterThan(
            0.0,
            $sharpe,
            'Sharpe must be positive when mean return exceeds risk-free rate'
        );

        // Verify the calculated Sharpe is in the expected ballpark.
        $mean      = array_sum($returns) / count($returns);
        $annReturn = $mean * 252;
        $sqDiff    = 0.0;
        foreach ($returns as $r) {
            $sqDiff += ($r - $mean) ** 2;
        }
        $stdDev   = sqrt($sqDiff / count($returns));
        $annVol   = $stdDev * sqrt(252);
        $expected = $annVol > 0 ? $annReturn / $annVol : 0.0;

        $this->assertEqualsWithDelta(
            $expected,
            $sharpe,
            0.01,
            'Sharpe ratio does not match manual calculation'
        );
    }

    // ── Volatility ────────────────────────────────────────────────────────────

    /**
     * Constant returns (all 0.01) have zero variance, so volatility is 0.
     */
    public function testVolatilityWithConstantReturns(): void
    {
        $returns    = array_fill(0, 30, 0.01);
        $volatility = $this->calc->calculateVolatility($returns);

        $this->assertEqualsWithDelta(
            0.0,
            $volatility,
            0.000001,
            'Volatility of a constant return series must be 0'
        );
    }

    /**
     * An empty return array returns 0.0 without throwing.
     */
    public function testVolatilityWithEmptyReturns(): void
    {
        $volatility = $this->calc->calculateVolatility([]);
        $this->assertEqualsWithDelta(0.0, $volatility, 0.000001);
    }

    /**
     * Non-constant returns produce a positive annualised volatility.
     */
    public function testVolatilityIsPositiveForVariableReturns(): void
    {
        $returns = [];
        for ($i = 0; $i < 20; $i++) {
            $returns[] = ($i % 2 === 0) ? 0.03 : -0.02;
        }

        $volatility = $this->calc->calculateVolatility($returns);
        $this->assertGreaterThan(0.0, $volatility, 'Volatility must be positive for variable returns');
    }

    // ── Kelly Criterion ───────────────────────────────────────────────────────

    /**
     * Standard Kelly formula: Kelly = (WinRate * AvgWin - LossRate * AvgLoss) / AvgWin
     *
     * Given:
     *   winRate = 0.6, avgWin = 0.2, avgLoss = 0.1
     *   Kelly = (0.6 * 0.2 - 0.4 * 0.1) / 0.2
     *         = (0.12 - 0.04) / 0.2
     *         = 0.08 / 0.2
     *         = 0.40
     *
     * RiskCalculator caps Kelly at 0.25, so the result is 0.25.
     */
    public function testKellyCriterion(): void
    {
        $kelly = $this->calc->calculateKelly(0.6, 0.2, 0.1);

        // Raw Kelly = 0.40, capped at 0.25 by RiskCalculator.
        $this->assertEqualsWithDelta(
            0.25,
            $kelly,
            0.0001,
            'Kelly fraction with winRate=0.6, avgWin=0.2, avgLoss=0.1 should be capped at 0.25'
        );
    }

    /**
     * A system with a 50% win rate and equal win/loss sizes has Kelly = 0.
     * (0.5 * x - 0.5 * x) / x = 0
     */
    public function testKellyZeroExpectancy(): void
    {
        $kelly = $this->calc->calculateKelly(0.5, 0.1, 0.1);

        $this->assertEqualsWithDelta(
            0.0,
            $kelly,
            0.0001,
            'Kelly must be 0 for a zero-expectancy system'
        );
    }

    /**
     * A losing system (negative expectancy) must return 0, not a negative fraction.
     * winRate=0.3, avgWin=0.05, avgLoss=0.10
     * Raw Kelly = (0.3*0.05 - 0.7*0.10) / 0.05 = (0.015 - 0.07) / 0.05 = -1.1  -> clamped to 0
     */
    public function testKellyNegativeExpectancyReturnsZero(): void
    {
        $kelly = $this->calc->calculateKelly(0.3, 0.05, 0.10);

        $this->assertEqualsWithDelta(
            0.0,
            $kelly,
            0.0001,
            'Kelly must be clamped to 0 for a negative-expectancy system'
        );
    }

    /**
     * Kelly returns 0 when avgWin is zero (guard against division by zero).
     */
    public function testKellyWithZeroAvgWin(): void
    {
        $kelly = $this->calc->calculateKelly(0.6, 0.0, 0.1);
        $this->assertEqualsWithDelta(0.0, $kelly, 0.0001);
    }
}
