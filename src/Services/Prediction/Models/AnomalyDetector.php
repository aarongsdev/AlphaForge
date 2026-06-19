<?php

declare(strict_types=1);

namespace AlphaForge\Services\Prediction\Models;

use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;

class AnomalyDetector
{
    private Database $db;
    private Logger $logger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    public function detectVolumeAnomaly(array $volumes, int $period = 20): array
    {
        $count = count($volumes);
        $recentVolume = (float) $volumes[$count - 1];

        $referenceVolumes = array_slice($volumes, $count - $period - 1, $period);

        $mean = $this->calculateMean($referenceVolumes);
        $stdDev = $this->calculateStdDev($referenceVolumes);

        if ($stdDev == 0.0) {
            $this->logger->debug('AnomalyDetector: stdDev is zero for volume anomaly detection');
            return [
                'detected'    => false,
                'z_score'     => 0.0,
                'severity'    => 'normal',
                'description' => 'Volume within normal range',
            ];
        }

        $zScore = ($recentVolume - $mean) / $stdDev;
        $detected = abs($zScore) >= 1.5;
        $severity = $this->scoreAnomaly($zScore);

        if ($detected) {
            $multiple = $mean > 0.0 ? round($recentVolume / $mean, 1) : 0.0;
            $description = sprintf('Volume spike detected: %.1fx average volume', $multiple);
        } else {
            $description = 'Volume within normal range';
        }

        return [
            'detected'    => $detected,
            'z_score'     => $zScore,
            'severity'    => $severity,
            'description' => $description,
        ];
    }

    public function detectPriceAnomaly(array $closes, int $period = 20): array
    {
        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            $prev = (float) $closes[$i - 1];
            $curr = (float) $closes[$i];
            if ($prev != 0.0) {
                $returns[] = ($curr - $prev) / $prev;
            }
        }

        $returnCount = count($returns);
        $recentReturn = $returns[$returnCount - 1];

        $referenceReturns = array_slice($returns, $returnCount - $period - 1, $period);

        $mean = $this->calculateMean($referenceReturns);
        $stdDev = $this->calculateStdDev($referenceReturns);

        if ($stdDev == 0.0) {
            $this->logger->debug('AnomalyDetector: stdDev is zero for price anomaly detection');
            return [
                'detected'    => false,
                'z_score'     => 0.0,
                'severity'    => 'normal',
                'description' => 'Price movement within normal range',
            ];
        }

        $zScore = ($recentReturn - $mean) / $stdDev;
        $detected = abs($zScore) >= 1.5;
        $severity = $this->scoreAnomaly($zScore);

        if ($detected) {
            $recentPct = round($recentReturn * 100, 1);
            $avgPct = round(abs($mean) * 100, 1);
            $sign = $recentPct >= 0 ? '+' : '';
            $description = sprintf(
                'Unusual price movement: %s%.1f%% vs %.1f%% daily average',
                $sign,
                $recentPct,
                $avgPct
            );
        } else {
            $description = 'Price movement within normal range';
        }

        return [
            'detected'    => $detected,
            'z_score'     => $zScore,
            'severity'    => $severity,
            'description' => $description,
        ];
    }

    public function detectInsiderActivity(string $assetId): array
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT transaction_type, SUM(value) as total_value, COUNT(*) as count
                 FROM insider_transactions
                 WHERE asset_id = ?
                   AND transaction_date >= NOW() - INTERVAL '30 days'
                 GROUP BY transaction_type",
                [$assetId]
            );
        } catch (\Throwable $e) {
            $this->logger->exception('AnomalyDetector: failed to query insider_transactions', $e);
            return [
                'detected'          => false,
                'net_direction'     => 'neutral',
                'transaction_count' => 0,
                'net_value'         => 0.0,
                'description'       => 'No significant insider activity detected',
            ];
        }

        $buyValue = 0.0;
        $sellValue = 0.0;
        $transactionCount = 0;

        foreach ($rows as $row) {
            $type = strtolower((string) $row['transaction_type']);
            $totalValue = (float) $row['total_value'];
            $count = (int) $row['count'];
            $transactionCount += $count;

            if ($type === 'buy') {
                $buyValue += $totalValue;
            } elseif ($type === 'sell') {
                $sellValue += $totalValue;
            }
        }

        $netValue = $buyValue - $sellValue;

        if ($netValue > 0) {
            $netDirection = 'buying';
        } elseif ($netValue < 0) {
            $netDirection = 'selling';
        } else {
            $netDirection = 'neutral';
        }

        $detected = abs($netValue) > 1_000_000 || $transactionCount >= 5;

        if ($detected) {
            $absMillions = round(abs($netValue) / 1_000_000, 1);
            $dirLabel = $netValue >= 0 ? 'buying' : 'selling';
            $description = sprintf(
                'Significant insider %s: $%.1fM net %ss in last 30 days (%d transactions)',
                $dirLabel,
                $absMillions,
                $dirLabel,
                $transactionCount
            );
        } else {
            $description = 'No significant insider activity detected';
        }

        return [
            'detected'          => $detected,
            'net_direction'     => $netDirection,
            'transaction_count' => $transactionCount,
            'net_value'         => $netValue,
            'description'       => $description,
        ];
    }

    public function detectInstitutionalActivity(string $assetId): array
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT institution_name, shares_held, change_shares, change_percent
                 FROM institutional_holdings
                 WHERE asset_id = ?
                   AND report_date >= NOW() - INTERVAL '90 days'
                 ORDER BY report_date DESC",
                [$assetId]
            );
        } catch (\Throwable $e) {
            $this->logger->exception('AnomalyDetector: failed to query institutional_holdings', $e);
            return [
                'detected'       => false,
                'direction'      => 'neutral',
                'change_percent' => 0.0,
                'description'    => 'No significant institutional activity',
            ];
        }

        $institutionCount = count($rows);

        if ($institutionCount === 0) {
            return [
                'detected'       => false,
                'direction'      => 'neutral',
                'change_percent' => 0.0,
                'description'    => 'No significant institutional activity',
            ];
        }

        $changePercentValues = array_map(
            static fn(array $row): float => (float) $row['change_percent'],
            $rows
        );

        $avgChangePercent = $this->calculateMean($changePercentValues);

        if ($avgChangePercent > 2.0) {
            $direction = 'accumulating';
        } elseif ($avgChangePercent < -2.0) {
            $direction = 'distributing';
        } else {
            $direction = 'neutral';
        }

        $detected = abs($avgChangePercent) > 5.0;
        $changePercentRounded = round($avgChangePercent, 2);

        if ($detected) {
            $dirLabel = $direction === 'accumulating' ? 'accumulation' : 'distribution';
            $sign = $avgChangePercent >= 0 ? '+' : '';
            $description = sprintf(
                'Institutional %s detected: average %s%.1f%% %s in holdings across %d institutions',
                $dirLabel,
                $sign,
                abs($avgChangePercent),
                $direction === 'accumulating' ? 'increase' : 'decrease',
                $institutionCount
            );
        } else {
            $description = 'No significant institutional activity';
        }

        return [
            'detected'       => $detected,
            'direction'      => $direction,
            'change_percent' => $changePercentRounded,
            'description'    => $description,
        ];
    }

    public function scoreAnomaly(float $zScore): string
    {
        $abs = abs($zScore);

        if ($abs < 1.5) {
            return 'normal';
        }

        if ($abs < 2.0) {
            return 'suspicious';
        }

        if ($abs < 3.0) {
            return 'anomalous';
        }

        return 'extreme';
    }

    private function calculateMean(array $values): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        return array_sum($values) / $count;
    }

    private function calculateStdDev(array $values): float
    {
        $count = count($values);

        if ($count <= 1) {
            return 0.0;
        }

        $mean = $this->calculateMean($values);
        $sumSquaredDiffs = 0.0;

        foreach ($values as $value) {
            $diff = (float) $value - $mean;
            $sumSquaredDiffs += $diff * $diff;
        }

        return sqrt($sumSquaredDiffs / $count);
    }
}
