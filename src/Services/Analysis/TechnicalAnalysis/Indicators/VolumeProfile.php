<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators;

/**
 * Volume Profile Indicator
 *
 * Distributes the volume of each bar across a grid of price buckets using the
 * Typical Price (TP = (H + L + C) / 3) as the representative price.  If OHLCV
 * data includes per-bar high/low we distribute volume across all price rows the
 * bar spans (proportional to the fraction of the bar's high–low range each row
 * represents); otherwise the full volume goes into the TP bucket.
 *
 * Key output metrics:
 *  POC (Point of Control) — price row with the highest volume
 *  VAH (Value Area High)  — upper boundary of Value Area (70% of total volume)
 *  VAL (Value Area Low)   — lower boundary of Value Area
 */
class VolumeProfile
{
    /**
     * Build a volume profile from OHLCV bars.
     *
     * @param array<int, array{open: float, high: float, low: float, close: float, volume: float}> $ohlcv
     * @param int $priceRows Number of price buckets (default 100).
     * @return array{
     *   poc: float,
     *   vah: float,
     *   val: float,
     *   value_area_pct: float,
     *   total_volume: float,
     *   profile: array<int, array{price_low: float, price_high: float, mid_price: float, volume: float, pct: float}>
     * }
     */
    public function calculate(array $ohlcv, int $priceRows = 100): array
    {
        if (empty($ohlcv)) {
            return [
                'poc'            => 0.0,
                'vah'            => 0.0,
                'val'            => 0.0,
                'value_area_pct' => 70.0,
                'total_volume'   => 0.0,
                'profile'        => [],
            ];
        }

        // Determine price range across all bars
        $priceMin = PHP_FLOAT_MAX;
        $priceMax = -PHP_FLOAT_MAX;
        foreach ($ohlcv as $bar) {
            if ($bar['low']  < $priceMin) $priceMin = $bar['low'];
            if ($bar['high'] > $priceMax) $priceMax = $bar['high'];
        }

        if ($priceMax <= $priceMin) {
            $priceMax = $priceMin + 1.0;
        }

        $rowSize = ($priceMax - $priceMin) / $priceRows;
        // Avoid floating-point edge cases at the top
        $rowSize = $rowSize > 0 ? $rowSize : 1e-9;

        // Initialise volume buckets
        $buckets = array_fill(0, $priceRows, 0.0);
        $totalVolume = 0.0;

        foreach ($ohlcv as $bar) {
            $vol  = (float)$bar['volume'];
            $high = (float)$bar['high'];
            $low  = (float)$bar['low'];
            $barRange = $high - $low;

            if ($barRange <= 0) {
                // Use typical price for zero-range bars
                $tp  = ((float)$bar['high'] + (float)$bar['low'] + (float)$bar['close']) / 3.0;
                $idx = (int)(($tp - $priceMin) / $rowSize);
                $idx = min($idx, $priceRows - 1);
                $buckets[$idx] += $vol;
            } else {
                // Distribute volume proportionally across rows the bar spans
                $firstRow = (int)(($low  - $priceMin) / $rowSize);
                $lastRow  = (int)(($high - $priceMin) / $rowSize);
                $firstRow = max(0, min($firstRow, $priceRows - 1));
                $lastRow  = max(0, min($lastRow,  $priceRows - 1));

                for ($r = $firstRow; $r <= $lastRow; $r++) {
                    $rowLow  = $priceMin + $r * $rowSize;
                    $rowHigh = $rowLow + $rowSize;
                    $overlap = min($rowHigh, $high) - max($rowLow, $low);
                    if ($overlap > 0) {
                        $fraction    = $overlap / $barRange;
                        $buckets[$r] += $vol * $fraction;
                    }
                }
            }
            $totalVolume += $vol;
        }

        // Build profile array
        $profile  = [];
        $pocIdx   = 0;
        $pocVol   = -1.0;

        for ($r = 0; $r < $priceRows; $r++) {
            $rowLow  = $priceMin + $r * $rowSize;
            $rowHigh = $rowLow + $rowSize;
            $pct     = $totalVolume > 0 ? ($buckets[$r] / $totalVolume) * 100.0 : 0.0;

            $profile[] = [
                'price_low'  => $rowLow,
                'price_high' => $rowHigh,
                'mid_price'  => ($rowLow + $rowHigh) / 2.0,
                'volume'     => $buckets[$r],
                'pct'        => round($pct, 4),
            ];

            if ($buckets[$r] > $pocVol) {
                $pocVol = $buckets[$r];
                $pocIdx = $r;
            }
        }

        $poc = $profile[$pocIdx]['mid_price'];

        // Value Area: 70% of total volume centred on POC
        [$val, $vah] = $this->computeValueArea($buckets, $profile, $pocIdx, $totalVolume, 0.70);

        return [
            'poc'            => $poc,
            'vah'            => $vah,
            'val'            => $val,
            'value_area_pct' => 70.0,
            'total_volume'   => $totalVolume,
            'profile'        => $profile,
        ];
    }

    /**
     * Find High Volume Nodes (HVNs): price rows where volume is significantly
     * above the mean — these act as magnets and areas of support/resistance.
     *
     * Threshold: volume > mean + 1.0 * std-dev
     *
     * @param array<int, array{price_low: float, price_high: float, mid_price: float, volume: float, pct: float}> $profile
     * @return array<int, array{mid_price: float, volume: float, pct: float}>
     */
    public function findHighVolumeNodes(array $profile): array
    {
        if (empty($profile)) {
            return [];
        }

        [$mean, $stdDev] = $this->profileStats($profile);
        $threshold = $mean + $stdDev;
        $hvns = [];

        foreach ($profile as $row) {
            if ($row['volume'] >= $threshold) {
                $hvns[] = [
                    'mid_price' => $row['mid_price'],
                    'volume'    => $row['volume'],
                    'pct'       => $row['pct'],
                ];
            }
        }

        return $hvns;
    }

    /**
     * Find Low Volume Nodes (LVNs): price gaps that price tends to travel
     * through quickly — potential breakout zones.
     *
     * Threshold: volume < mean - 0.5 * std-dev (but >= 0)
     *
     * @param array<int, array{price_low: float, price_high: float, mid_price: float, volume: float, pct: float}> $profile
     * @return array<int, array{mid_price: float, volume: float, pct: float}>
     */
    public function findLowVolumeNodes(array $profile): array
    {
        if (empty($profile)) {
            return [];
        }

        [$mean, $stdDev] = $this->profileStats($profile);
        $threshold = max(0.0, $mean - 0.5 * $stdDev);
        $lvns = [];

        foreach ($profile as $row) {
            if ($row['volume'] <= $threshold) {
                $lvns[] = [
                    'mid_price' => $row['mid_price'],
                    'volume'    => $row['volume'],
                    'pct'       => $row['pct'],
                ];
            }
        }

        return $lvns;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Expand outward from POC to accumulate 70% of total volume.
     *
     * @return array{float, float}  [VAL, VAH]
     */
    private function computeValueArea(
        array $buckets,
        array $profile,
        int   $pocIdx,
        float $totalVolume,
        float $targetFraction
    ): array {
        $target     = $totalVolume * $targetFraction;
        $accumulated = $buckets[$pocIdx];
        $lo = $pocIdx;
        $hi = $pocIdx;
        $n  = count($buckets);

        while ($accumulated < $target && ($lo > 0 || $hi < $n - 1)) {
            $addHigh = ($hi < $n - 1) ? $buckets[$hi + 1] : -1.0;
            $addLow  = ($lo > 0)      ? $buckets[$lo - 1] : -1.0;

            if ($addHigh >= $addLow) {
                $hi++;
                $accumulated += $buckets[$hi];
            } else {
                $lo--;
                $accumulated += $buckets[$lo];
            }
        }

        return [$profile[$lo]['price_low'], $profile[$hi]['price_high']];
    }

    /**
     * Compute mean and population std-dev of volume across profile rows.
     *
     * @return array{float, float}  [mean, stdDev]
     */
    private function profileStats(array $profile): array
    {
        $volumes = array_column($profile, 'volume');
        $count   = count($volumes);
        if ($count === 0) {
            return [0.0, 0.0];
        }

        $mean     = array_sum($volumes) / $count;
        $variance = 0.0;
        foreach ($volumes as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stdDev = sqrt($variance / $count);

        return [$mean, $stdDev];
    }
}
