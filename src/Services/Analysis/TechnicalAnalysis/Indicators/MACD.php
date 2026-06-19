<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Moving Average Convergence Divergence (MACD) Indicator
 *
 * MACD line  = EMA(fastPeriod) - EMA(slowPeriod)
 * Signal line = EMA(signalPeriod) of MACD line
 * Histogram   = MACD line - Signal line
 */
class MACD
{
    private EMA $ema;

    public function __construct()
    {
        $this->ema = new EMA();
    }

    /**
     * Calculate MACD, Signal, and Histogram for a closing-price series.
     *
     * Returns an array of the same length as $closes. Entries where there is
     * insufficient data are null.
     *
     * @param float[] $closes       Chronologically ordered closing prices.
     * @param int     $fastPeriod   Fast EMA period (default 12).
     * @param int     $slowPeriod   Slow EMA period (default 26).
     * @param int     $signalPeriod Signal EMA period (default 9).
     * @return array<int, array{macd: float|null, signal: float|null, histogram: float|null}>
     */
    public function calculate(
        array $closes,
        int $fastPeriod   = 12,
        int $slowPeriod   = 26,
        int $signalPeriod = 9
    ): array {
        $closes = array_values($closes);
        $n      = count($closes);

        $fastEma = $this->ema->calculate($closes, $fastPeriod);
        $slowEma = $this->ema->calculate($closes, $slowPeriod);

        // Build the raw MACD line (only where both EMAs are non-null)
        $macdLine = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($fastEma[$i] !== null && $slowEma[$i] !== null) {
                $macdLine[$i] = $fastEma[$i] - $slowEma[$i];
            }
        }

        // Extract the non-null MACD values to calculate Signal EMA
        // We need to know the starting index of valid MACD values
        $macdStart = -1;
        for ($i = 0; $i < $n; $i++) {
            if ($macdLine[$i] !== null) {
                $macdStart = $i;
                break;
            }
        }

        // Build signal-EMA input: a dense array of MACD values from $macdStart onward.
        // Guard: if $macdStart stayed -1 (no valid fast+slow EMA overlap), return early.
        if ($macdStart < 0) {
            return $result;
        }

        $macdDense = [];
        for ($i = $macdStart; $i < $n; $i++) {
            $macdDense[] = (float)$macdLine[$i];
        }

        $signalDense = ($macdStart >= 0 && count($macdDense) >= $signalPeriod)
            ? $this->ema->calculate($macdDense, $signalPeriod)
            : array_fill(0, count($macdDense), null);

        // Reconstruct full-length result
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $result[$i] = ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        for ($i = 0; $i < count($macdDense); $i++) {
            $absIdx = $macdStart + $i;
            $macd   = $macdLine[$absIdx];
            $signal = $signalDense[$i];

            $result[$absIdx] = [
                'macd'      => $macd,
                'signal'    => $signal,
                'histogram' => ($macd !== null && $signal !== null)
                    ? $macd - $signal
                    : null,
            ];
        }

        return $result;
    }

    /**
     * Interpret a full MACD series, detecting crossovers and histogram trend.
     *
     * Looks at the last bar (and compares with the previous bar).
     *
     * @param array<int, array{macd: float|null, signal: float|null, histogram: float|null}> $macdValues
     * @return array{
     *   crossover: string,
     *   histogram_trend: string,
     *   macd_above_zero: bool|null,
     *   last_macd: float|null,
     *   last_signal: float|null,
     *   last_histogram: float|null,
     *   description: string,
     *   recent_crosses: array
     * }
     */
    public function interpret(array $macdValues): array
    {
        $macdValues = array_values($macdValues);
        $n          = count($macdValues);

        // Find last two valid entries
        $validIdxs = [];
        for ($i = 0; $i < $n; $i++) {
            if ($macdValues[$i]['macd'] !== null && $macdValues[$i]['signal'] !== null) {
                $validIdxs[] = $i;
            }
        }

        if (count($validIdxs) < 2) {
            return [
                'crossover'       => 'none',
                'histogram_trend' => 'unknown',
                'macd_above_zero' => null,
                'last_macd'       => null,
                'last_signal'     => null,
                'last_histogram'  => null,
                'description'     => 'Insufficient data for MACD interpretation.',
                'recent_crosses'  => [],
            ];
        }

        $lastIdx = end($validIdxs);
        $prevIdx = $validIdxs[count($validIdxs) - 2];

        $lastMacd      = $macdValues[$lastIdx]['macd'];
        $lastSignal    = $macdValues[$lastIdx]['signal'];
        $lastHistogram = $macdValues[$lastIdx]['histogram'];
        $prevHistogram = $macdValues[$prevIdx]['histogram'];

        // Current crossover state
        $prevMacdAbove = $macdValues[$prevIdx]['macd'] > $macdValues[$prevIdx]['signal'];
        $lastMacdAbove = $lastMacd > $lastSignal;

        if ($prevMacdAbove && !$lastMacdAbove) {
            $crossover = 'bearish_crossover';
        } elseif (!$prevMacdAbove && $lastMacdAbove) {
            $crossover = 'bullish_crossover';
        } else {
            $crossover = $lastMacdAbove ? 'macd_above_signal' : 'macd_below_signal';
        }

        // Histogram trend
        if ($lastHistogram !== null && $prevHistogram !== null) {
            if ($lastHistogram > $prevHistogram) {
                $histogramTrend = $lastHistogram > 0 ? 'strengthening_bullish' : 'weakening_bearish';
            } elseif ($lastHistogram < $prevHistogram) {
                $histogramTrend = $lastHistogram < 0 ? 'strengthening_bearish' : 'weakening_bullish';
            } else {
                $histogramTrend = 'flat';
            }
        } else {
            $histogramTrend = 'unknown';
        }

        // Scan for all recent crossovers
        $recentCrosses = [];
        for ($k = 1; $k < count($validIdxs); $k++) {
            $a    = $validIdxs[$k - 1];
            $b    = $validIdxs[$k];
            $pAbv = $macdValues[$a]['macd'] > $macdValues[$a]['signal'];
            $cAbv = $macdValues[$b]['macd'] > $macdValues[$b]['signal'];
            if ($pAbv !== $cAbv) {
                $recentCrosses[] = [
                    'index' => $b,
                    'type'  => $cAbv ? 'bullish' : 'bearish',
                ];
            }
        }

        $description = sprintf(
            'MACD: %.4f | Signal: %.4f | Histogram: %.4f. %s. Histogram is %s.',
            $lastMacd,
            $lastSignal,
            $lastHistogram ?? 0.0,
            ucfirst(str_replace('_', ' ', $crossover)),
            str_replace('_', ' ', $histogramTrend)
        );

        return [
            'crossover'       => $crossover,
            'histogram_trend' => $histogramTrend,
            'macd_above_zero' => $lastMacd !== null ? $lastMacd > 0 : null,
            'last_macd'       => $lastMacd,
            'last_signal'     => $lastSignal,
            'last_histogram'  => $lastHistogram,
            'description'     => $description,
            'recent_crosses'  => $recentCrosses,
        ];
    }
}
