<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Simple Moving Average (SMA) Indicator
 */
class SMA
{
    /**
     * Calculate SMA for a series of closing prices.
     *
     * The first ($period - 1) values are null.
     * Starting at index ($period - 1), each value is the arithmetic mean
     * of the preceding $period closes.
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

        // Seed the rolling sum
        $windowSum = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $windowSum += $closes[$i];
        }
        $result[$period - 1] = $windowSum / $period;

        // Slide window
        for ($i = $period; $i < $n; $i++) {
            $windowSum += $closes[$i] - $closes[$i - $period];
            $result[$i] = $windowSum / $period;
        }

        return $result;
    }

    /**
     * Detect golden cross (short SMA crosses above long SMA) and
     * death cross (short SMA crosses below long SMA) events.
     *
     * Both arrays must be the same length and produced by calculate()
     * over the same price series.
     *
     * @param (float|null)[] $shortSma SMA values for the shorter period.
     * @param (float|null)[] $longSma  SMA values for the longer period.
     * @return array<int, array{index: int, type: string, short_sma: float, long_sma: float, description: string}>
     */
    public function crossover(array $shortSma, array $longSma): array
    {
        $shortSma = array_values($shortSma);
        $longSma  = array_values($longSma);
        $n        = min(count($shortSma), count($longSma));
        $crosses  = [];

        $prevShortAbove = null;

        for ($i = 0; $i < $n; $i++) {
            if ($shortSma[$i] === null || $longSma[$i] === null) {
                continue;
            }

            $shortAbove = $shortSma[$i] > $longSma[$i];

            if ($prevShortAbove !== null && $shortAbove !== $prevShortAbove) {
                if ($shortAbove) {
                    $type        = 'golden_cross';
                    $description = sprintf(
                        'Golden Cross at bar %d: short SMA (%.4f) crossed above long SMA (%.4f).',
                        $i,
                        $shortSma[$i],
                        $longSma[$i]
                    );
                } else {
                    $type        = 'death_cross';
                    $description = sprintf(
                        'Death Cross at bar %d: short SMA (%.4f) crossed below long SMA (%.4f).',
                        $i,
                        $shortSma[$i],
                        $longSma[$i]
                    );
                }

                $crosses[] = [
                    'index'       => $i,
                    'type'        => $type,
                    'short_sma'   => $shortSma[$i],
                    'long_sma'    => $longSma[$i],
                    'description' => $description,
                ];
            }

            $prevShortAbove = $shortAbove;
        }

        return $crosses;
    }
}
