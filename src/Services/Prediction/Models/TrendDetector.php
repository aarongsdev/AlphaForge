<?php

declare(strict_types=1);

namespace AlphaForge\Services\Prediction\Models;

class TrendDetector
{
    /**
     * Detect trend direction, strength, duration and momentum from price closes and volumes.
     *
     * @param array<int, float> $closes
     * @param array<int, float> $volumes
     * @return array{trend: string, strength: float, duration_days: int, momentum: float}
     */
    public function detectTrend(array $closes, array $volumes): array
    {
        $count = count($closes);

        $ema9   = $this->calculateEMA($closes, 9);
        $ema20  = $this->calculateEMA($closes, 20);
        $ema50  = $this->calculateEMA($closes, 50);
        $ema200 = $this->calculateEMA($closes, 200);

        $adx = $this->calculateADX($closes);

        // Latest values from each EMA array
        $lastEma9   = end($ema9);
        $lastEma20  = end($ema20);
        $lastEma50  = end($ema50);
        $lastEma200 = end($ema200);

        // Determine trend direction based on EMA alignment
        $trend = 'sideways';
        if ($lastEma9 > $lastEma20 && $lastEma20 > $lastEma50 && $lastEma50 > $lastEma200) {
            $trend = 'bullish';
        } elseif ($lastEma9 < $lastEma20 && $lastEma20 < $lastEma50 && $lastEma50 < $lastEma200) {
            $trend = 'bearish';
        }

        // Count how many EMA pairs are in correct bullish order (for strength)
        $bullishPairs = 0;
        if ($lastEma9 > $lastEma20) {
            $bullishPairs++;
        }
        if ($lastEma20 > $lastEma50) {
            $bullishPairs++;
        }
        if ($lastEma50 > $lastEma200) {
            $bullishPairs++;
        }

        // Map pairs to base strength percentage
        $pairStrength = match ($bullishPairs) {
            3       => 100.0,
            2       => 66.0,
            1       => 33.0,
            default => 0.0,
        };

        // Modulate by ADX / 50 * 100, capped at 100
        $adxFactor = min($adx / 50.0 * 100.0, 100.0);
        $strength  = min($pairStrength * ($adxFactor / 100.0), 100.0);

        // Duration: consecutive days from the end where the close is above/below EMA20
        $ema20FullLength = count($ema20);
        // ema20 starts at index (20-1) = 19 of closes, so ema20[j] corresponds to closes[j + 19]
        $ema20Offset   = 20 - 1; // period - 1
        $durationDays  = 0;
        $lastClose     = $closes[$count - 1];

        if ($trend === 'bullish') {
            // Count consecutive days at the end where close > EMA20
            for ($i = $ema20FullLength - 1; $i >= 0; $i--) {
                $closesIdx = $i + $ema20Offset;
                if ($closesIdx >= $count) {
                    continue;
                }
                if ($closes[$closesIdx] > $ema20[$i]) {
                    $durationDays++;
                } else {
                    break;
                }
            }
        } elseif ($trend === 'bearish') {
            // Count consecutive days at the end where close < EMA20
            for ($i = $ema20FullLength - 1; $i >= 0; $i--) {
                $closesIdx = $i + $ema20Offset;
                if ($closesIdx >= $count) {
                    continue;
                }
                if ($closes[$closesIdx] < $ema20[$i]) {
                    $durationDays++;
                } else {
                    break;
                }
            }
        }

        // Momentum: 20-day return (last close vs close 20 days ago, i.e., index count-21)
        $momentum = 0.0;
        if ($count >= 21) {
            $close20DaysAgo = $closes[$count - 21];
            if ($close20DaysAgo != 0.0) {
                $momentum = ($lastClose - $close20DaysAgo) / $close20DaysAgo * 100.0;
            }
        }

        return [
            'trend'         => $trend,
            'strength'      => round($strength, 4),
            'duration_days' => $durationDays,
            'momentum'      => round($momentum, 4),
        ];
    }

    /**
     * Detect the current market regime from closes and volumes.
     *
     * @param array<int, float> $closes
     * @param array<int, float> $volumes
     * @return string 'bull'|'bear'|'sideways'|'volatile'
     */
    public function detectMarketRegime(array $closes, array $volumes): string
    {
        $count = count($closes);

        // 20-day annualized volatility
        $returns = [];
        $start   = max(1, $count - 20);
        for ($i = $start; $i < $count; $i++) {
            if ($closes[$i - 1] != 0.0) {
                $returns[] = $closes[$i] / $closes[$i - 1] - 1.0;
            }
        }

        $annualizedVol = 0.0;
        if (count($returns) > 1) {
            $mean    = array_sum($returns) / count($returns);
            $variance = 0.0;
            foreach ($returns as $r) {
                $variance += ($r - $mean) ** 2;
            }
            $variance       /= count($returns);
            $stdDev          = sqrt($variance);
            $annualizedVol   = $stdDev * sqrt(252.0) * 100.0;
        }

        $adx = $this->calculateADX($closes);

        // 50-day return: (last_close - closes[count-51]) / closes[count-51] * 100
        $return50d = 0.0;
        if ($count >= 51 && $closes[$count - 51] != 0.0) {
            $return50d = ($closes[$count - 1] - $closes[$count - 51]) / $closes[$count - 51] * 100.0;
        }

        // Rules in order
        if ($annualizedVol > 40.0) {
            return 'volatile';
        }

        if ($adx > 25.0 && $return50d > 5.0) {
            return 'bull';
        }

        if ($adx > 25.0 && $return50d < -5.0) {
            return 'bear';
        }

        return 'sideways';
    }

    /**
     * Detect EMA20/EMA50 crossover cycle changes.
     *
     * @param array<int, float> $closes
     * @param int $shortTerm
     * @param int $longTerm
     * @return array{change_detected: bool, from: string, to: string, confidence: float}
     */
    public function detectCycleChange(array $closes, int $shortTerm = 20, int $longTerm = 50): array
    {
        $result = [
            'change_detected' => false,
            'from'            => '',
            'to'              => '',
            'confidence'      => 0.0,
        ];

        $emaShort = $this->calculateEMA($closes, $shortTerm);
        $emaLong  = $this->calculateEMA($closes, $longTerm);

        // We need the last 2 aligned values. emaShort starts at index (shortTerm-1),
        // emaLong starts at index (longTerm-1) of closes.
        // The aligned portion: both cover closes from index (longTerm-1) onward.
        // emaShort[j] corresponds to closes[j + shortTerm - 1]
        // emaLong[j]  corresponds to closes[j + longTerm - 1]
        // For closes index c: emaShort index = c - (shortTerm - 1), emaLong index = c - (longTerm - 1)

        $countShort = count($emaShort);
        $countLong  = count($emaLong);

        // We need at least 2 aligned data points
        if ($countLong < 2 || $countShort < ($longTerm - $shortTerm + 2)) {
            return $result;
        }

        // The last aligned index in emaLong is countLong - 1 (corresponds to closes[count($closes)-1])
        // The corresponding emaShort index for that same close is:
        //   emaShort index = closeIdx - (shortTerm - 1)
        //   closeIdx for emaLong[n] = n + (longTerm - 1)
        //   So emaShort index = n + (longTerm - 1) - (shortTerm - 1) = n + (longTerm - shortTerm)
        $offset = $longTerm - $shortTerm;

        // Current (n-1 in emaLong): last index
        $longIdxCurr  = $countLong - 1;
        $shortIdxCurr = $longIdxCurr + $offset;

        // Previous (n-2 in emaLong)
        $longIdxPrev  = $countLong - 2;
        $shortIdxPrev = $longIdxPrev + $offset;

        if ($shortIdxCurr >= $countShort || $shortIdxPrev >= $countShort || $shortIdxPrev < 0) {
            return $result;
        }

        $emaShortCurr = $emaShort[$shortIdxCurr];
        $emaLongCurr  = $emaLong[$longIdxCurr];
        $emaShortPrev = $emaShort[$shortIdxPrev];
        $emaLongPrev  = $emaLong[$longIdxPrev];

        $wasBelow = $emaShortPrev < $emaLongPrev;
        $isAbove  = $emaShortCurr > $emaLongCurr;
        $wasAbove = $emaShortPrev > $emaLongPrev;
        $isBelow  = $emaShortCurr < $emaLongCurr;

        if ($wasBelow && $isAbove) {
            // Bearish to bullish crossover
            $confidence = 0.0;
            if ($emaLongCurr != 0.0) {
                $confidence = abs($emaShortCurr - $emaLongCurr) / $emaLongCurr * 100.0;
                $confidence = min($confidence, 100.0);
            }
            $result['change_detected'] = true;
            $result['from']            = 'bearish';
            $result['to']              = 'bullish';
            $result['confidence']      = round($confidence, 4);
        } elseif ($wasAbove && $isBelow) {
            // Bullish to bearish crossover
            $confidence = 0.0;
            if ($emaLongCurr != 0.0) {
                $confidence = abs($emaShortCurr - $emaLongCurr) / $emaLongCurr * 100.0;
                $confidence = min($confidence, 100.0);
            }
            $result['change_detected'] = true;
            $result['from']            = 'bullish';
            $result['to']              = 'bearish';
            $result['confidence']      = round($confidence, 4);
        }

        return $result;
    }

    /**
     * Calculate Exponential Moving Average for a given period.
     * Returns an array starting from index (period-1), so count = count($prices) - (period - 1).
     *
     * @param array<int, float> $prices
     * @param int $period
     * @return array<int, float>
     */
    private function calculateEMA(array $prices, int $period): array
    {
        $count = count($prices);

        if ($count < $period) {
            return [];
        }

        $k = 2.0 / ($period + 1);

        // Seed with SMA of first $period prices
        $sma = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sma += $prices[$i];
        }
        $sma /= $period;

        $ema    = [];
        $ema[0] = $sma;

        // Iterate from index $period onward
        $emaIdx = 1;
        for ($i = $period; $i < $count; $i++) {
            $ema[$emaIdx] = $prices[$i] * $k + $ema[$emaIdx - 1] * (1.0 - $k);
            $emaIdx++;
        }

        return $ema;
    }

    /**
     * Calculate Average Directional Index (ADX) using simplified close-only approach.
     *
     * @param array<int, float> $closes
     * @param int $period
     * @return float
     */
    private function calculateADX(array $closes, int $period = 14): float
    {
        $count = count($closes);

        // Need at least 2*period+1 data points to compute meaningful ADX
        if ($count < 2 * $period + 1) {
            return 0.0;
        }

        // Build TR, +DM, -DM arrays (from index 1 onward, comparing i and i-1)
        // For simplified close-only, we also need index i-2 for DM calculation
        $tr  = [];
        $pdm = [];
        $ndm = [];

        for ($i = 2; $i < $count; $i++) {
            $upMove   = $closes[$i] - $closes[$i - 1];
            $downMove = $closes[$i - 1] - $closes[$i];

            $tr[]  = abs($closes[$i] - $closes[$i - 1]);

            if ($upMove > $downMove && $upMove > 0.0) {
                $pdm[] = $upMove;
                $ndm[] = 0.0;
            } elseif ($downMove > $upMove && $downMove > 0.0) {
                $pdm[] = 0.0;
                $ndm[] = $downMove;
            } else {
                $pdm[] = 0.0;
                $ndm[] = 0.0;
            }
        }

        $dmCount = count($tr);

        if ($dmCount < $period) {
            return 0.0;
        }

        // Wilder's smoothing initial values (sum of first $period)
        $smoothedTR  = 0.0;
        $smoothedPDM = 0.0;
        $smoothedNDM = 0.0;

        for ($i = 0; $i < $period; $i++) {
            $smoothedTR  += $tr[$i];
            $smoothedPDM += $pdm[$i];
            $smoothedNDM += $ndm[$i];
        }

        // Collect DX values starting after first smoothing seed
        $dxValues = [];

        // Calculate first DX from initial smoothed values
        if ($smoothedTR > 0.0) {
            $pdi  = ($smoothedPDM / $smoothedTR) * 100.0;
            $ndi  = ($smoothedNDM / $smoothedTR) * 100.0;
            $diSum = $pdi + $ndi;
            if ($diSum > 0.0) {
                $dxValues[] = abs($pdi - $ndi) / $diSum * 100.0;
            }
        }

        // Continue Wilder's smoothing for remaining values
        for ($i = $period; $i < $dmCount; $i++) {
            $smoothedTR  = $smoothedTR - ($smoothedTR / $period) + $tr[$i];
            $smoothedPDM = $smoothedPDM - ($smoothedPDM / $period) + $pdm[$i];
            $smoothedNDM = $smoothedNDM - ($smoothedNDM / $period) + $ndm[$i];

            if ($smoothedTR > 0.0) {
                $pdi  = ($smoothedPDM / $smoothedTR) * 100.0;
                $ndi  = ($smoothedNDM / $smoothedTR) * 100.0;
                $diSum = $pdi + $ndi;
                if ($diSum > 0.0) {
                    $dxValues[] = abs($pdi - $ndi) / $diSum * 100.0;
                }
            }
        }

        if (count($dxValues) < $period) {
            return count($dxValues) > 0 ? array_sum($dxValues) / count($dxValues) : 0.0;
        }

        // Wilder's smoothed ADX: seed with average of first $period DX values
        $adx = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $adx += $dxValues[$i];
        }
        $adx /= $period;

        // Continue smoothing
        $dxCount = count($dxValues);
        for ($i = $period; $i < $dxCount; $i++) {
            $adx = ($adx * ($period - 1) + $dxValues[$i]) / $period;
        }

        return round($adx, 4);
    }
}
