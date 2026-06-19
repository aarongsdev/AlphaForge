<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Ichimoku Kinko Hyo Cloud Indicator
 *
 * Components:
 *  Tenkan-sen (Conversion Line) : (highest_high + lowest_low) / 2  over tenkanPeriod
 *  Kijun-sen  (Base Line)       : (highest_high + lowest_low) / 2  over kijunPeriod
 *  Senkou Span A (Leading A)    : (Tenkan + Kijun) / 2, plotted $displacement periods ahead
 *  Senkou Span B (Leading B)    : (highest_high + lowest_low) / 2 over senkouBPeriod, plotted ahead
 *  Chikou Span  (Lagging Span)  : Current close, plotted $displacement periods behind
 *
 * The returned array has length n + displacement so that Senkou spans are
 * available at their forward-shifted positions.
 */
class Ichimoku
{
    /**
     * Calculate all Ichimoku components.
     *
     * @param float[] $highs          High prices.
     * @param float[] $lows           Low prices.
     * @param float[] $closes         Closing prices.
     * @param int     $tenkanPeriod   Tenkan-sen period  (default 9).
     * @param int     $kijunPeriod    Kijun-sen period   (default 26).
     * @param int     $senkouBPeriod  Senkou Span B period (default 52).
     * @param int     $displacement   Kumo displacement forward (default 26).
     * @return array<int, array{
     *   tenkan: float|null,
     *   kijun: float|null,
     *   senkou_a: float|null,
     *   senkou_b: float|null,
     *   chikou: float|null
     * }>
     */
    public function calculate(
        array $highs,
        array $lows,
        array $closes,
        int   $tenkanPeriod  = 9,
        int   $kijunPeriod   = 26,
        int   $senkouBPeriod = 52,
        int   $displacement  = 26
    ): array {
        $highs  = array_values($highs);
        $lows   = array_values($lows);
        $closes = array_values($closes);
        $n      = count($closes);

        // Result array extended forward by $displacement bars for future cloud
        $total  = $n + $displacement;
        $empty  = ['tenkan' => null, 'kijun' => null, 'senkou_a' => null, 'senkou_b' => null, 'chikou' => null];
        $result = array_fill(0, $total, $empty);

        // Pre-compute rolling highest-high and lowest-low for each required period
        $tenkanHL = $this->rollingHL($highs, $lows, $n, $tenkanPeriod);
        $kijunHL  = $this->rollingHL($highs, $lows, $n, $kijunPeriod);
        $senkouBHL= $this->rollingHL($highs, $lows, $n, $senkouBPeriod);

        for ($i = 0; $i < $n; $i++) {
            // Tenkan-sen
            $tenkan = $tenkanHL[$i] !== null
                ? ($tenkanHL[$i]['high'] + $tenkanHL[$i]['low']) / 2.0
                : null;

            // Kijun-sen
            $kijun = $kijunHL[$i] !== null
                ? ($kijunHL[$i]['high'] + $kijunHL[$i]['low']) / 2.0
                : null;

            $result[$i]['tenkan'] = $tenkan;
            $result[$i]['kijun']  = $kijun;

            // Senkou Span A — plot displacement bars into the future
            if ($tenkan !== null && $kijun !== null) {
                $result[$i + $displacement]['senkou_a'] = ($tenkan + $kijun) / 2.0;
            }

            // Senkou Span B — plot displacement bars into the future
            if ($senkouBHL[$i] !== null) {
                $result[$i + $displacement]['senkou_b'] =
                    ($senkouBHL[$i]['high'] + $senkouBHL[$i]['low']) / 2.0;
            }

            // Chikou Span — current close plotted $displacement bars in the past
            // We store it at index $i but tag it as representing bar $i - $displacement
            $chikouIdx = $i - $displacement;
            if ($chikouIdx >= 0) {
                $result[$chikouIdx]['chikou'] = $closes[$i];
            }
        }

        return $result;
    }

    /**
     * Interpret the current Ichimoku state.
     *
     * @param array<int, array{tenkan: float|null, kijun: float|null, senkou_a: float|null, senkou_b: float|null, chikou: float|null}> $ichimoku
     * @param float $currentClose
     * @return array{
     *   trend: string,
     *   cloud_color: string,
     *   cloud_thickness: float|null,
     *   price_vs_cloud: string,
     *   tk_cross: string,
     *   chikou_signal: string,
     *   bullish_signals: int,
     *   bearish_signals: int,
     *   description: string
     * }
     */
    public function interpret(array $ichimoku, float $currentClose): array
    {
        $ichimoku = array_values($ichimoku);
        $n        = count($ichimoku);

        // Find last bar with Tenkan/Kijun data
        $lastIdx = -1;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($ichimoku[$i]['tenkan'] !== null && $ichimoku[$i]['kijun'] !== null) {
                $lastIdx = $i;
                break;
            }
        }

        if ($lastIdx < 0) {
            return [
                'trend'           => 'unknown',
                'cloud_color'     => 'unknown',
                'cloud_thickness' => null,
                'price_vs_cloud'  => 'unknown',
                'tk_cross'        => 'unknown',
                'chikou_signal'   => 'unknown',
                'bullish_signals' => 0,
                'bearish_signals' => 0,
                'description'     => 'Insufficient data for Ichimoku interpretation.',
            ];
        }

        $current  = $ichimoku[$lastIdx];
        $tenkan   = (float)$current['tenkan'];
        $kijun    = (float)$current['kijun'];
        $senkouA  = $current['senkou_a'];
        $senkouB  = $current['senkou_b'];
        $chikou   = $current['chikou'];

        $bullishSignals = 0;
        $bearishSignals = 0;

        // --- TK Cross ---
        $tkCross = 'no_cross';
        if ($lastIdx > 0) {
            $prev = $ichimoku[$lastIdx - 1];
            if ($prev['tenkan'] !== null && $prev['kijun'] !== null) {
                $prevTAbove = (float)$prev['tenkan'] > (float)$prev['kijun'];
                $currTAbove = $tenkan > $kijun;
                if (!$prevTAbove && $currTAbove) {
                    $tkCross = 'bullish_tk_cross';
                    $bullishSignals++;
                } elseif ($prevTAbove && !$currTAbove) {
                    $tkCross = 'bearish_tk_cross';
                    $bearishSignals++;
                } elseif ($currTAbove) {
                    $bullishSignals++;
                } else {
                    $bearishSignals++;
                }
            }
        }

        // --- Cloud (Kumo) analysis ---
        $cloudColor     = 'unknown';
        $cloudThickness = null;
        $priceVsCloud   = 'inside_cloud';

        if ($senkouA !== null && $senkouB !== null) {
            $cloudTop    = max($senkouA, $senkouB);
            $cloudBottom = min($senkouA, $senkouB);
            $cloudThickness = $cloudTop - $cloudBottom;
            $cloudColor  = $senkouA >= $senkouB ? 'bullish_green' : 'bearish_red';

            if ($currentClose > $cloudTop) {
                $priceVsCloud = 'above_cloud';
                $bullishSignals++;
            } elseif ($currentClose < $cloudBottom) {
                $priceVsCloud = 'below_cloud';
                $bearishSignals++;
            } else {
                $priceVsCloud = 'inside_cloud';
            }
        }

        // --- Chikou Span ---
        $chikouSignal = 'unknown';
        if ($chikou !== null) {
            // Chikou is the current close plotted in the past; compare against price then
            // We compare the chikou value versus the closing price at lastIdx - displacement
            // (already embedded in the array); for simplicity we compare to $currentClose
            if ((float)$chikou > $currentClose) {
                $chikouSignal = 'bullish';
                $bullishSignals++;
            } else {
                $chikouSignal = 'bearish';
                $bearishSignals++;
            }
        }

        // --- Overall trend ---
        $trend = 'neutral';
        if ($bullishSignals > $bearishSignals + 1) {
            $trend = 'bullish';
        } elseif ($bearishSignals > $bullishSignals + 1) {
            $trend = 'bearish';
        }

        $description = sprintf(
            'Tenkan: %.4f | Kijun: %.4f | Cloud: %s (%s) | Price %s | TK: %s | Chikou: %s. '
            . 'Bullish signals: %d, Bearish signals: %d.',
            $tenkan, $kijun,
            $cloudColor,
            $cloudThickness !== null ? sprintf('thickness %.4f', $cloudThickness) : 'N/A',
            $priceVsCloud,
            $tkCross,
            $chikouSignal,
            $bullishSignals,
            $bearishSignals
        );

        return [
            'trend'           => $trend,
            'cloud_color'     => $cloudColor,
            'cloud_thickness' => $cloudThickness,
            'price_vs_cloud'  => $priceVsCloud,
            'tk_cross'        => $tkCross,
            'chikou_signal'   => $chikouSignal,
            'bullish_signals' => $bullishSignals,
            'bearish_signals' => $bearishSignals,
            'description'     => $description,
        ];
    }

    /**
     * Pre-compute rolling (highest high, lowest low) for a given period.
     *
     * Returns an array of length $n. Entries where the window is not yet
     * full (i < period - 1) are null.
     *
     * @param float[] $highs
     * @param float[] $lows
     * @param int     $n
     * @param int     $period
     * @return array<int, array{high: float, low: float}|null>
     */
    private function rollingHL(array $highs, array $lows, int $n, int $period): array
    {
        $result = array_fill(0, $n, null);

        for ($i = $period - 1; $i < $n; $i++) {
            $high = -PHP_FLOAT_MAX;
            $low  =  PHP_FLOAT_MAX;
            for ($j = $i - $period + 1; $j <= $i; $j++) {
                if ($highs[$j] > $high) {
                    $high = $highs[$j];
                }
                if ($lows[$j] < $low) {
                    $low = $lows[$j];
                }
            }
            $result[$i] = ['high' => $high, 'low' => $low];
        }

        return $result;
    }
}
