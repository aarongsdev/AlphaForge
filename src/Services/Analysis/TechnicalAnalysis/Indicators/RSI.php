<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Relative Strength Index (RSI) Indicator
 *
 * Uses Wilder's smoothing method (exponential smoothing with alpha = 1/period).
 */
class RSI
{
    /**
     * Calculate RSI values for an array of closing prices.
     *
     * Returns an array of the same length as $closes. The first ($period - 1)
     * values are null because there is insufficient data to compute RSI.
     * The $period-th value is seeded with a simple average of the first $period
     * gains/losses; subsequent values use Wilder's smoothing.
     *
     * @param float[] $closes  Chronologically ordered closing prices (oldest first).
     * @param int     $period  Look-back period (default 14).
     * @return (float|null)[]
     */
    public function calculate(array $closes, int $period = 14): array
    {
        $closes = array_values($closes);
        $n      = count($closes);
        $result = array_fill(0, $n, null);

        if ($n < $period + 1) {
            return $result;
        }

        // Build change array (n-1 elements; change[i] = close[i+1] - close[i])
        $changes = [];
        for ($i = 1; $i < $n; $i++) {
            $changes[] = $closes[$i] - $closes[$i - 1];
        }

        // Seed: simple average of first $period gains and losses
        $seedGains  = 0.0;
        $seedLosses = 0.0;
        for ($i = 0; $i < $period; $i++) {
            if ($changes[$i] > 0) {
                $seedGains += $changes[$i];
            } else {
                $seedLosses += abs($changes[$i]);
            }
        }

        $avgGain = $seedGains  / $period;
        $avgLoss = $seedLosses / $period;

        // RSI for the $period-th close (index $period in $closes)
        $result[$period] = $this->rsiFromAverages($avgGain, $avgLoss);

        // Wilder's smoothing for subsequent values
        for ($i = $period; $i < count($changes); $i++) {
            $change = $changes[$i];
            $gain   = $change > 0 ? $change : 0.0;
            $loss   = $change < 0 ? abs($change) : 0.0;

            $avgGain = ($avgGain * ($period - 1) + $gain)  / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $loss)  / $period;

            $result[$i + 1] = $this->rsiFromAverages($avgGain, $avgLoss);
        }

        return $result;
    }

    /**
     * Convert smoothed averages to an RSI value in [0, 100].
     */
    private function rsiFromAverages(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss == 0.0) {
            return 100.0;
        }
        $rs = $avgGain / $avgLoss;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    /**
     * Interpret a single RSI reading.
     *
     * @return array{signal: string, strength: float, description: string}
     */
    public function interpret(float $rsi): array
    {
        if ($rsi < 30) {
            $signal   = 'oversold';
            // Strength scaled 0-100: the further below 30, the stronger the signal
            $strength = min(100.0, ((30.0 - $rsi) / 30.0) * 100.0);
            $description = sprintf(
                'RSI %.2f indicates oversold conditions. A potential bullish reversal may be imminent.',
                $rsi
            );
        } elseif ($rsi > 70) {
            $signal   = 'overbought';
            $strength = min(100.0, (($rsi - 70.0) / 30.0) * 100.0);
            $description = sprintf(
                'RSI %.2f indicates overbought conditions. A potential bearish reversal may be imminent.',
                $rsi
            );
        } else {
            $signal   = 'neutral';
            // Strength of neutrality: 100 at midpoint (50), 0 near boundaries (30/70)
            $distFromMid = abs($rsi - 50.0);
            $strength = max(0.0, 100.0 - ($distFromMid / 20.0) * 100.0);
            $description = sprintf(
                'RSI %.2f is in neutral territory. No strong overbought or oversold signal.',
                $rsi
            );
        }

        return [
            'signal'      => $signal,
            'strength'    => round($strength, 2),
            'description' => $description,
        ];
    }

    /**
     * Detect bullish and bearish divergences between price and RSI.
     *
     * A BULLISH divergence occurs when price makes a lower low but RSI makes a
     * higher low — indicating weakening selling momentum.
     *
     * A BEARISH divergence occurs when price makes a higher high but RSI makes a
     * lower high — indicating weakening buying momentum.
     *
     * Only swing points (local extremes within a ±5-bar window) are compared.
     *
     * @param float[]        $closes    Closing prices.
     * @param (float|null)[] $rsiValues RSI array returned by calculate().
     * @return array<int, array{type: string, price_index_a: int, price_index_b: int, description: string}>
     */
    public function detectDivergence(array $closes, array $rsiValues): array
    {
        $closes      = array_values($closes);
        $rsiValues   = array_values($rsiValues);
        $n           = count($closes);
        $window      = 5;
        $divergences = [];

        $swingLows  = [];
        $swingHighs = [];

        for ($i = $window; $i < $n - $window; $i++) {
            if ($rsiValues[$i] === null) {
                continue;
            }

            $isLow  = true;
            $isHigh = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($closes[$j] <= $closes[$i]) {
                    $isLow = false;
                }
                if ($closes[$j] >= $closes[$i]) {
                    $isHigh = false;
                }
            }

            if ($isLow) {
                $swingLows[] = $i;
            }
            if ($isHigh) {
                $swingHighs[] = $i;
            }
        }

        // Bullish divergence: consecutive swing lows
        for ($k = 0; $k < count($swingLows) - 1; $k++) {
            $a = $swingLows[$k];
            $b = $swingLows[$k + 1];
            if ($closes[$b] < $closes[$a] && (float)$rsiValues[$b] > (float)$rsiValues[$a]) {
                $divergences[] = [
                    'type'          => 'bullish',
                    'price_index_a' => $a,
                    'price_index_b' => $b,
                    'description'   => sprintf(
                        'Bullish divergence: price made lower low (%.4f → %.4f) '
                        . 'while RSI made higher low (%.2f → %.2f) between bars %d and %d.',
                        $closes[$a], $closes[$b],
                        $rsiValues[$a], $rsiValues[$b],
                        $a, $b
                    ),
                ];
            }
        }

        // Bearish divergence: consecutive swing highs
        for ($k = 0; $k < count($swingHighs) - 1; $k++) {
            $a = $swingHighs[$k];
            $b = $swingHighs[$k + 1];
            if ($closes[$b] > $closes[$a] && (float)$rsiValues[$b] < (float)$rsiValues[$a]) {
                $divergences[] = [
                    'type'          => 'bearish',
                    'price_index_a' => $a,
                    'price_index_b' => $b,
                    'description'   => sprintf(
                        'Bearish divergence: price made higher high (%.4f → %.4f) '
                        . 'while RSI made lower high (%.2f → %.2f) between bars %d and %d.',
                        $closes[$a], $closes[$b],
                        $rsiValues[$a], $rsiValues[$b],
                        $a, $b
                    ),
                ];
            }
        }

        return $divergences;
    }
}
