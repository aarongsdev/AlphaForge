<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Fibonacci Retracement and Extension Levels
 *
 * Standard retracement ratios: 0, 0.236, 0.382, 0.5, 0.618, 0.786, 1.0
 * Extension ratios:            1.272, 1.618, 2.618
 *
 * For an uptrend:
 *   Retracement level = swingHigh - ratio * (swingHigh - swingLow)
 *
 * For a downtrend:
 *   Retracement level = swingLow  + ratio * (swingHigh - swingLow)
 */
class Fibonacci
{
    /** @var float[] Standard retracement ratios */
    private const RETRACEMENT_RATIOS = [0.0, 0.236, 0.382, 0.5, 0.618, 0.786, 1.0];

    /** @var float[] Extension ratios */
    private const EXTENSION_RATIOS = [1.272, 1.618, 2.618];

    /**
     * Calculate Fibonacci retracement and extension levels.
     *
     * @param float  $swingHigh Swing high price.
     * @param float  $swingLow  Swing low price.
     * @param string $trend     'uptrend' or 'downtrend'.
     * @return array{
     *   swing_high: float,
     *   swing_low: float,
     *   trend: string,
     *   range: float,
     *   retracements: array<string, float>,
     *   extensions: array<string, float>
     * }
     */
    public function calculateRetracement(
        float  $swingHigh,
        float  $swingLow,
        string $trend = 'uptrend'
    ): array {
        $range        = $swingHigh - $swingLow;
        $retracements = [];
        $extensions   = [];

        foreach (self::RETRACEMENT_RATIOS as $ratio) {
            $label = $this->ratioLabel($ratio);
            if ($trend === 'uptrend') {
                // Price retraces downward from the swing high
                $retracements[$label] = $swingHigh - $ratio * $range;
            } else {
                // Price retraces upward from the swing low
                $retracements[$label] = $swingLow + $ratio * $range;
            }
        }

        foreach (self::EXTENSION_RATIOS as $ratio) {
            $label = $this->ratioLabel($ratio);
            if ($trend === 'uptrend') {
                // Extension above swing high
                $extensions[$label] = $swingLow + $ratio * $range;
            } else {
                // Extension below swing low
                $extensions[$label] = $swingHigh - $ratio * $range;
            }
        }

        return [
            'swing_high'    => $swingHigh,
            'swing_low'     => $swingLow,
            'trend'         => $trend,
            'range'         => $range,
            'retracements'  => $retracements,
            'extensions'    => $extensions,
        ];
    }

    /**
     * Find the most recent significant swing highs and lows in OHLC data.
     *
     * A swing high at bar i: the highest high within the window [i-lookback, i+lookback].
     * A swing low at bar i:  the lowest  low  within the window [i-lookback, i+lookback].
     *
     * @param float[] $highs    High prices.
     * @param float[] $lows     Low prices.
     * @param int     $lookback Bars on each side required to qualify as a swing (default 20).
     * @return array{
     *   swing_highs: array<int, array{index: int, price: float}>,
     *   swing_lows:  array<int, array{index: int, price: float}>,
     *   last_swing_high: array{index: int, price: float}|null,
     *   last_swing_low:  array{index: int, price: float}|null
     * }
     */
    public function findSwings(array $highs, array $lows, int $lookback = 20): array
    {
        $highs = array_values($highs);
        $lows  = array_values($lows);
        $n     = count($highs);

        $swingHighs = [];
        $swingLows  = [];

        for ($i = $lookback; $i < $n - $lookback; $i++) {
            // Swing high: highest high in window
            $isSwingHigh = true;
            for ($j = $i - $lookback; $j <= $i + $lookback; $j++) {
                if ($j !== $i && $highs[$j] >= $highs[$i]) {
                    $isSwingHigh = false;
                    break;
                }
            }
            if ($isSwingHigh) {
                $swingHighs[] = ['index' => $i, 'price' => $highs[$i]];
            }

            // Swing low: lowest low in window
            $isSwingLow = true;
            for ($j = $i - $lookback; $j <= $i + $lookback; $j++) {
                if ($j !== $i && $lows[$j] <= $lows[$i]) {
                    $isSwingLow = false;
                    break;
                }
            }
            if ($isSwingLow) {
                $swingLows[] = ['index' => $i, 'price' => $lows[$i]];
            }
        }

        return [
            'swing_highs'     => $swingHighs,
            'swing_lows'      => $swingLows,
            'last_swing_high' => !empty($swingHighs) ? end($swingHighs) : null,
            'last_swing_low'  => !empty($swingLows)  ? end($swingLows)  : null,
        ];
    }

    /**
     * Find the Fibonacci level nearest to the current price and return
     * the label, price, and the distance as a percentage of current price.
     *
     * @param float               $currentPrice Current market price.
     * @param array<string,float> $levels       Flat map of label → price level.
     * @return array{label: string, level_price: float, distance_pct: float, above: bool}
     */
    public function nearestLevel(float $currentPrice, array $levels): array
    {
        if (empty($levels)) {
            return ['label' => 'none', 'level_price' => 0.0, 'distance_pct' => 0.0, 'above' => false];
        }

        $bestLabel    = '';
        $bestPrice    = 0.0;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($levels as $label => $price) {
            $dist = abs($currentPrice - $price);
            if ($dist < $bestDistance) {
                $bestDistance = $dist;
                $bestLabel    = $label;
                $bestPrice    = $price;
            }
        }

        $distancePct = $currentPrice != 0.0
            ? ($bestDistance / $currentPrice) * 100.0
            : 0.0;

        return [
            'label'        => $bestLabel,
            'level_price'  => $bestPrice,
            'distance_pct' => round($distancePct, 4),
            'above'        => $currentPrice > $bestPrice,
        ];
    }

    /**
     * Convert a ratio to a human-readable percentage label (e.g. 0.618 → "61.8%").
     */
    private function ratioLabel(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio * 100, 1), '0'), '.') . '%';
    }
}
