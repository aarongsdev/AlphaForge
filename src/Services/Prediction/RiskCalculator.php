<?php

declare(strict_types=1);

namespace AlphaForge\Services\Prediction;

class RiskCalculator
{
    public function calculateVaR(array $returns, float $confidence = 0.95): float
    {
        if (empty($returns)) {
            return 0.0;
        }

        $sortedReturns = $returns;
        sort($sortedReturns);

        // floor((1-conf)*n) gives the 1-indexed position; subtract 1 for 0-indexed.
        // Clamped to 0 to handle edge cases where n is very small.
        $index = max(0, (int) floor((1 - $confidence) * count($sortedReturns)) - 1);

        return abs($sortedReturns[$index]);
    }

    public function calculateCVaR(array $returns, float $confidence = 0.95): float
    {
        if (empty($returns)) {
            return 0.0;
        }

        $sortedReturns = $returns;
        sort($sortedReturns);

        $cutoffIndex = max(1, (int) floor((1 - $confidence) * count($sortedReturns)));

        $tailValues = array_slice($sortedReturns, 0, $cutoffIndex);

        if (empty($tailValues)) {
            return 0.0;
        }

        $average = $this->calculateMean($tailValues);

        return abs($average);
    }

    public function calculateMaxDrawdown(array $prices): array
    {
        $result = [
            'max_drawdown_percent' => 0.0,
            'peak_index'           => 0,
            'trough_index'         => 0,
            'recovery_index'       => null,
        ];

        if (count($prices) < 2) {
            return $result;
        }

        $peak          = $prices[0];
        $peakIndex     = 0;
        $maxDrawdown   = 0.0;
        $maxPeakIndex  = 0;
        $maxTroughIndex = 0;

        for ($i = 1; $i < count($prices); $i++) {
            $currentPrice = $prices[$i];

            if ($currentPrice > $peak) {
                $peak      = $currentPrice;
                $peakIndex = $i;
            }

            if ($peak > 0) {
                $drawdown = ($currentPrice - $peak) / $peak;

                if ($drawdown < $maxDrawdown) {
                    $maxDrawdown    = $drawdown;
                    $maxPeakIndex   = $peakIndex;
                    $maxTroughIndex = $i;
                }
            }
        }

        $recoveryIndex = null;
        $peakPrice     = $prices[$maxPeakIndex];

        for ($i = $maxTroughIndex + 1; $i < count($prices); $i++) {
            if ($prices[$i] >= $peakPrice) {
                $recoveryIndex = $i;
                break;
            }
        }

        $result['max_drawdown_percent'] = $maxDrawdown * 100;
        $result['peak_index']           = $maxPeakIndex;
        $result['trough_index']         = $maxTroughIndex;
        $result['recovery_index']       = $recoveryIndex;

        return $result;
    }

    public function calculateSharpeRatio(array $returns, float $riskFreeRate = 0.05): float
    {
        if (empty($returns)) {
            return 0.0;
        }

        $avgReturn        = $this->calculateMean($returns);
        $annualizedReturn = $avgReturn * 252;

        $stdDev        = $this->calculateStdDev($returns);
        $annualizedVol = $stdDev * sqrt(252);

        if ($annualizedVol == 0.0) {
            return 0.0;
        }

        $sharpe = ($annualizedReturn - $riskFreeRate) / $annualizedVol;

        return round($sharpe, 4);
    }

    public function calculateSortinoRatio(array $returns, float $riskFreeRate = 0.05): float
    {
        if (empty($returns)) {
            return 0.0;
        }

        $avgReturn        = $this->calculateMean($returns);
        $annualizedReturn = $avgReturn * 252;

        $downsideReturns = array_values(array_filter($returns, fn(float $r) => $r < 0));

        if (empty($downsideReturns)) {
            return 0.0;
        }

        $squaredDownside     = array_map(fn(float $r) => $r ** 2, $downsideReturns);
        $meanSquared         = $this->calculateMean($squaredDownside);
        $downsideDeviation   = sqrt($meanSquared) * sqrt(252);

        if ($downsideDeviation == 0.0) {
            return 0.0;
        }

        $sortino = ($annualizedReturn - $riskFreeRate) / $downsideDeviation;

        return round($sortino, 4);
    }

    public function calculateBeta(array $assetReturns, array $marketReturns): float
    {
        $length = min(count($assetReturns), count($marketReturns));

        if ($length === 0) {
            return 1.0;
        }

        $assetReturns  = array_slice($assetReturns, 0, $length);
        $marketReturns = array_slice($marketReturns, 0, $length);

        $meanAsset  = $this->calculateMean($assetReturns);
        $meanMarket = $this->calculateMean($marketReturns);

        $covarianceSum   = 0.0;
        $varianceMarketSum = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $assetDiff  = $assetReturns[$i] - $meanAsset;
            $marketDiff = $marketReturns[$i] - $meanMarket;

            $covarianceSum     += $assetDiff * $marketDiff;
            $varianceMarketSum += $marketDiff ** 2;
        }

        $covariance      = $covarianceSum / $length;
        $varianceMarket  = $varianceMarketSum / $length;

        if ($varianceMarket == 0.0) {
            return 1.0;
        }

        $beta = $covariance / $varianceMarket;

        return round($beta, 4);
    }

    public function calculateVolatility(array $returns, int $annualizationFactor = 252): float
    {
        if (empty($returns)) {
            return 0.0;
        }

        $stdDev     = $this->calculateStdDev($returns);
        $volatility = $stdDev * sqrt($annualizationFactor);

        return round($volatility, 6);
    }

    public function calculateKelly(float $winRate, float $avgWin, float $avgLoss): float
    {
        if ($avgWin == 0.0) {
            return 0.0;
        }

        $kelly = ($winRate * $avgWin - (1 - $winRate) * $avgLoss) / $avgWin;

        $kelly = min($kelly, 0.25);
        $kelly = max($kelly, 0.0);

        return round($kelly, 4);
    }

    private function calculateMean(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function calculateStdDev(array $values): float
    {
        $count = count($values);

        if ($count <= 1) {
            return 0.0;
        }

        $mean       = $this->calculateMean($values);
        $squaredSum = 0.0;

        foreach ($values as $value) {
            $squaredSum += ($value - $mean) ** 2;
        }

        return sqrt($squaredSum / ($count - 1));
    }
}
