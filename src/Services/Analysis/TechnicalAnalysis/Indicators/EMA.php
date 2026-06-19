<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Exponential Moving Average (EMA) Indicator
 *
 * Formula: EMA_t = Close_t * k + EMA_{t-1} * (1 - k)
 * where k = 2 / (period + 1)
 *
 * The first EMA value is seeded as the simple average of the first $period closes.
 */
class EMA
{
    /**
     * Calculate EMA for a series of closing prices.
     *
     * The first ($period - 1) values are null (insufficient data).
     * Index ($period - 1) is the seed SMA, and subsequent values use the EMA formula.
     *
     * @param float[] $closes Chronologically ordered closing prices.
     * @param int     $period Look-back period.
     * @return (float|null)[]
     */
    public function calculate(array $closes, int $period): array
    {
        $closes = array_values($closes);
        $n      = count($closes);
        $result = array_fill(0, $n, null);

        if ($n < $period) {
            return $result;
        }

        // Smoothing factor
        $k = 2.0 / ($period + 1.0);

        // Seed: SMA of first $period closes
        $sum = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $closes[$i];
        }
        $ema = $sum / $period;
        $result[$period - 1] = $ema;

        // Apply EMA formula
        for ($i = $period; $i < $n; $i++) {
            $ema      = $closes[$i] * $k + $ema * (1.0 - $k);
            $result[$i] = $ema;
        }

        return $result;
    }

    /**
     * Detect golden cross (short EMA crosses above long EMA) and
     * death cross (short EMA crosses below long EMA) events.
     *
     * Both input arrays must be the same length and produced by calculate()
     * over the same price series.
     *
     * @param (float|null)[] $shortEma EMA values for the shorter period.
     * @param (float|null)[] $longEma  EMA values for the longer period.
     * @return array<int, array{index: int, type: string, short_ema: float, long_ema: float, description: string}>
     */
    public function crossover(array $shortEma, array $longEma): array
    {
        $shortEma = array_values($shortEma);
        $longEma  = array_values($longEma);
        $n        = min(count($shortEma), count($longEma));
        $crosses  = [];

        // Find first index where both arrays are non-null
        $prevShortAbove = null;

        for ($i = 0; $i < $n; $i++) {
            if ($shortEma[$i] === null || $longEma[$i] === null) {
                continue;
            }

            $shortAbove = $shortEma[$i] > $longEma[$i];

            if ($prevShortAbove !== null && $shortAbove !== $prevShortAbove) {
                if ($shortAbove) {
                    $type        = 'golden_cross';
                    $description = sprintf(
                        'Golden Cross at bar %d: short EMA (%.4f) crossed above long EMA (%.4f).',
                        $i,
                        $shortEma[$i],
                        $longEma[$i]
                    );
                } else {
                    $type        = 'death_cross';
                    $description = sprintf(
                        'Death Cross at bar %d: short EMA (%.4f) crossed below long EMA (%.4f).',
                        $i,
                        $shortEma[$i],
                        $longEma[$i]
                    );
                }

                $crosses[] = [
                    'index'       => $i,
                    'type'        => $type,
                    'short_ema'   => $shortEma[$i],
                    'long_ema'    => $longEma[$i],
                    'description' => $description,
                ];
            }

            $prevShortAbove = $shortAbove;
        }

        return $crosses;
    }
}
