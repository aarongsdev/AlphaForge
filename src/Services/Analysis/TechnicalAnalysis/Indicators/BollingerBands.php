<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Bollinger Bands Indicator
 *
 * Middle Band : SMA(period)
 * Upper Band  : SMA + stdDevMultiplier * σ  (population std-dev of window)
 * Lower Band  : SMA - stdDevMultiplier * σ
 * Width       : (Upper - Lower) / Middle
 * %B          : (Close - Lower) / (Upper - Lower)   — 1.0 at upper band, 0.0 at lower
 */
class BollingerBands
{
    /**
     * Calculate Bollinger Bands for a series of closing prices.
     *
     * The first ($period - 1) entries are null.
     *
     * @param float[] $closes           Chronologically ordered closing prices.
     * @param int     $period           SMA look-back period (default 20).
     * @param float   $stdDevMultiplier Band width multiplier (default 2.0).
     * @return array<int, array{upper: float|null, middle: float|null, lower: float|null, width: float|null, percent_b: float|null}>
     */
    public function calculate(array $closes, int $period = 20, float $stdDevMultiplier = 2.0): array
    {
        $closes = array_values($closes);
        $n      = count($closes);
        $result = [];

        for ($i = 0; $i < $n; $i++) {
            $result[] = ['upper' => null, 'middle' => null, 'lower' => null, 'width' => null, 'percent_b' => null];
        }

        if ($n < $period) {
            return $result;
        }

        // Rolling sum and sum-of-squares for O(1) window std-dev
        $windowSum  = 0.0;
        $windowSq   = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $windowSum += $closes[$i];
            $windowSq  += $closes[$i] ** 2;
        }

        $this->fillBand($result, $closes, $period - 1, $period, $windowSum, $windowSq, $stdDevMultiplier);

        for ($i = $period; $i < $n; $i++) {
            $leaving    = $closes[$i - $period];
            $entering   = $closes[$i];
            $windowSum += $entering - $leaving;
            $windowSq  += $entering ** 2 - $leaving ** 2;

            $this->fillBand($result, $closes, $i, $period, $windowSum, $windowSq, $stdDevMultiplier);
        }

        return $result;
    }

    /**
     * Fill a single Bollinger Band result entry.
     */
    private function fillBand(
        array &$result,
        array  $closes,
        int    $idx,
        int    $period,
        float  $windowSum,
        float  $windowSq,
        float  $multiplier
    ): void {
        $mean  = $windowSum / $period;
        // Population variance = E[X^2] - (E[X])^2
        $variance = ($windowSq / $period) - ($mean ** 2);
        $stdDev   = $variance > 0 ? sqrt($variance) : 0.0;

        $upper  = $mean + $multiplier * $stdDev;
        $lower  = $mean - $multiplier * $stdDev;
        $width  = ($mean != 0.0) ? ($upper - $lower) / $mean : 0.0;
        $close  = $closes[$idx];

        $percentB = ($upper != $lower)
            ? ($close - $lower) / ($upper - $lower)
            : 0.5;

        $result[$idx] = [
            'upper'     => $upper,
            'middle'    => $mean,
            'lower'     => $lower,
            'width'     => $width,
            'percent_b' => $percentB,
        ];
    }

    /**
     * Interpret the current Bollinger Band situation.
     *
     * Detects:
     * - Squeeze: band width is near recent minimum (low volatility)
     * - Breakout: price outside bands
     * - Overbought/oversold via %B
     *
     * @param array<int, array{upper: float|null, middle: float|null, lower: float|null, width: float|null, percent_b: float|null}> $bands
     * @param float $currentClose
     * @return array{signal: string, squeeze: bool, breakout: string|null, percent_b: float|null, description: string}
     */
    public function interpret(array $bands, float $currentClose): array
    {
        $bands = array_values($bands);
        $n     = count($bands);

        // Find last valid entry
        $lastBand = null;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($bands[$i]['middle'] !== null) {
                $lastBand = $bands[$i];
                break;
            }
        }

        if ($lastBand === null) {
            return [
                'signal'      => 'unknown',
                'squeeze'     => false,
                'breakout'    => null,
                'percent_b'   => null,
                'description' => 'Insufficient data for Bollinger Bands interpretation.',
            ];
        }

        // Squeeze detection: compare current width to the average of last 20 widths
        $widths = [];
        for ($i = max(0, $n - 20); $i < $n; $i++) {
            if ($bands[$i]['width'] !== null) {
                $widths[] = $bands[$i]['width'];
            }
        }
        $avgWidth = count($widths) > 0 ? array_sum($widths) / count($widths) : 0.0;
        $squeeze  = count($widths) >= 5 && (float)$lastBand['width'] < $avgWidth * 0.75;

        // Breakout detection
        $breakout = null;
        if ($currentClose > (float)$lastBand['upper']) {
            $breakout = 'above_upper';
        } elseif ($currentClose < (float)$lastBand['lower']) {
            $breakout = 'below_lower';
        }

        $percentB = $lastBand['percent_b'];

        // Signal
        if ($breakout === 'above_upper') {
            $signal = 'overbought_breakout';
        } elseif ($breakout === 'below_lower') {
            $signal = 'oversold_breakout';
        } elseif ($squeeze) {
            $signal = 'squeeze';
        } elseif ($percentB !== null && $percentB > 0.8) {
            $signal = 'approaching_upper';
        } elseif ($percentB !== null && $percentB < 0.2) {
            $signal = 'approaching_lower';
        } else {
            $signal = 'neutral';
        }

        $description = sprintf(
            'Upper: %.4f | Middle: %.4f | Lower: %.4f | %%B: %.2f | Width: %.4f.%s%s',
            $lastBand['upper'],
            $lastBand['middle'],
            $lastBand['lower'],
            $percentB ?? 0.0,
            $lastBand['width'],
            $squeeze   ? ' Band squeeze detected — expect volatility expansion.' : '',
            $breakout  ? sprintf(' Price has broken %s the band.', $breakout === 'above_upper' ? 'above' : 'below') : ''
        );

        return [
            'signal'      => $signal,
            'squeeze'     => $squeeze,
            'breakout'    => $breakout,
            'percent_b'   => $percentB,
            'description' => $description,
        ];
    }
}
