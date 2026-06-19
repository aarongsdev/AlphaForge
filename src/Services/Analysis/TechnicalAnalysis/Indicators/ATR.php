<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Average True Range (ATR) Indicator
 *
 * True Range for bar i (i > 0):
 *   TR_i = max( High_i - Low_i,
 *               |High_i - Close_{i-1}|,
 *               |Low_i  - Close_{i-1}| )
 *
 * For the first bar (no previous close), TR_0 = High_0 - Low_0.
 *
 * ATR is the Wilder's smoothed moving average of TR:
 *   ATR_i = (ATR_{i-1} * (period - 1) + TR_i) / period
 * seeded with the simple average of the first $period TR values.
 */
class ATR
{
    /**
     * Calculate ATR for OHLC data.
     *
     * The first ($period - 1) values are null; from index ($period - 1) onward
     * ATR values are available.
     *
     * @param float[] $highs  Daily high prices.
     * @param float[] $lows   Daily low prices.
     * @param float[] $closes Daily closing prices.
     * @param int     $period Look-back period (default 14).
     * @return (float|null)[]
     */
    public function calculate(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $highs  = array_values($highs);
        $lows   = array_values($lows);
        $closes = array_values($closes);
        $n      = count($highs);
        $result = array_fill(0, $n, null);

        if ($n < $period) {
            return $result;
        }

        // Compute True Range array
        $tr = [];
        $tr[0] = $highs[0] - $lows[0];
        for ($i = 1; $i < $n; $i++) {
            $hl   = $highs[$i] - $lows[$i];
            $hpc  = abs($highs[$i] - $closes[$i - 1]);
            $lpc  = abs($lows[$i]  - $closes[$i - 1]);
            $tr[] = max($hl, $hpc, $lpc);
        }

        // Seed ATR as simple average of first $period TR values
        $atrSeed = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $atrSeed += $tr[$i];
        }
        $atr = $atrSeed / $period;
        $result[$period - 1] = $atr;

        // Wilder's smoothing
        for ($i = $period; $i < $n; $i++) {
            $atr        = ($atr * ($period - 1) + $tr[$i]) / $period;
            $result[$i] = $atr;
        }

        return $result;
    }

    /**
     * Normalised ATR: express ATR as a percentage of the corresponding closing price.
     *
     * natr[i] = atr[i] / close[i] * 100
     *
     * Null entries in $atrValues produce null entries in the output.
     *
     * @param (float|null)[] $atrValues ATR array from calculate().
     * @param float[]        $closes    Corresponding closing prices.
     * @return (float|null)[]
     */
    public function normalizedAtr(array $atrValues, array $closes): array
    {
        $atrValues = array_values($atrValues);
        $closes    = array_values($closes);
        $n         = min(count($atrValues), count($closes));
        $result    = array_fill(0, $n, null);

        for ($i = 0; $i < $n; $i++) {
            if ($atrValues[$i] !== null && $closes[$i] != 0.0) {
                $result[$i] = ($atrValues[$i] / $closes[$i]) * 100.0;
            }
        }

        return $result;
    }
}
