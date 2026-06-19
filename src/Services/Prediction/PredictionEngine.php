<?php

declare(strict_types=1);

namespace AlphaForge\Services\Prediction;

use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Config;
use AlphaForge\Services\Analysis\TechnicalAnalysisService;
use AlphaForge\Services\Analysis\FundamentalAnalysisService;
use AlphaForge\Services\Analysis\SentimentAnalysisService;
use AlphaForge\Services\Analysis\MacroAnalysisService;
use AlphaForge\Services\Prediction\Models\TrendDetector;
use AlphaForge\Services\Prediction\Models\AnomalyDetector;
use AlphaForge\Services\Prediction\Models\SignalGenerator;
use AlphaForge\Services\Prediction\RiskCalculator;
use Carbon\Carbon;

class PredictionEngine
{
    private readonly Database $db;
    private readonly Logger $logger;
    private readonly Config $config;
    private readonly TechnicalAnalysisService $technicalAnalysis;
    private readonly FundamentalAnalysisService $fundamentalAnalysis;
    private readonly SentimentAnalysisService $sentimentAnalysis;
    private readonly MacroAnalysisService $macroAnalysis;
    private readonly TrendDetector $trendDetector;
    private readonly AnomalyDetector $anomalyDetector;
    private readonly SignalGenerator $signalGenerator;
    private readonly RiskCalculator $riskCalculator;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();

        $this->technicalAnalysis = new TechnicalAnalysisService();
        $this->fundamentalAnalysis = new FundamentalAnalysisService();
        $this->sentimentAnalysis = new SentimentAnalysisService();
        $this->macroAnalysis = new MacroAnalysisService();

        $this->trendDetector = new TrendDetector();
        $this->anomalyDetector = new AnomalyDetector();
        $this->signalGenerator = new SignalGenerator();
        $this->riskCalculator = new RiskCalculator();
    }

    public function predict(string $assetId, string $timeframe = 'short_term'): array
    {
        $this->logger->info("Starting prediction for asset: {$assetId}, timeframe: {$timeframe}");

        $asset = $this->db->fetchOne("SELECT * FROM assets WHERE id = ?", [$assetId]);
        if ($asset === null) {
            $this->logger->error("Asset not found: {$assetId}");
            throw new \RuntimeException("Asset not found: {$assetId}");
        }

        $priceRows = $this->db->fetchAll(
            "SELECT close, volume FROM market_data_daily WHERE asset_id = ? ORDER BY date DESC LIMIT 200",
            [$assetId]
        );

        $closes = array_column($priceRows, 'close');
        $volumes = array_column($priceRows, 'volume');

        $currentPrice = (float) ($closes[0] ?? 0.0);

        $technical = $this->technicalAnalysis->analyze($assetId);
        $fundamental = $this->fundamentalAnalysis->analyze($assetId);
        $sentiment = $this->sentimentAnalysis->analyze($assetId);
        $macro = $this->macroAnalysis->analyze($assetId);

        $compositeScore = ($technical['score'] * 0.35)
            + ($fundamental['score'] * 0.30)
            + ($sentiment['score'] * 0.20)
            + ($macro['score'] * 0.15);

        $signalInfo = $this->signalGenerator->scoreToSignal($compositeScore);
        $signal = $signalInfo['signal'];
        $strength = $signalInfo['strength'];

        $marketRegime = $this->trendDetector->detectMarketRegime($closes, $volumes);
        $trendInfo = $this->trendDetector->detectTrend($closes, $volumes);

        $anomalies = [
            'volume' => $this->anomalyDetector->detectVolumeAnomaly($volumes),
            'price' => $this->anomalyDetector->detectPriceAnomaly($closes),
            'insider' => $this->anomalyDetector->detectInsiderActivity($assetId),
            'institutional' => $this->anomalyDetector->detectInstitutionalActivity($assetId),
        ];

        $technicalSignals = $this->signalGenerator->generateTechnicalSignals($technical['indicators'] ?? []);
        $fundamentalSignal = $this->signalGenerator->generateFundamentalSignal($fundamental);
        $sentimentSignal = $this->signalGenerator->generateSentimentSignal($sentiment);

        $signals = [$technicalSignals, $fundamentalSignal, $sentimentSignal];
        $weights = [0.35, 0.30, 0.20];
        $combinedSignals = $this->signalGenerator->combineSignals($signals, $weights);

        $targets = $this->calculatePriceTargets($currentPrice, $signal, $technical);

        $riskReward = $this->calculateRiskReward(
            $currentPrice,
            $targets['target_1'],
            $targets['stop_loss'],
            $signal
        );

        $scores = [
            'technical' => $technical['score'],
            'fundamental' => $fundamental['score'],
            'sentiment' => $sentiment['score'],
            'macro' => $macro['score'],
            'composite' => $compositeScore,
        ];

        $analysis = [
            'technical' => $technical,
            'fundamental' => $fundamental,
            'sentiment' => $sentiment,
            'macro' => $macro,
        ];

        $reasoning = $this->generateReasoning($scores, $signal, $analysis);

        $now = Carbon::now();
        $expiresAt = match ($timeframe) {
            'intraday'     => $now->copy()->addHour(),
            'short_term'   => $now->copy()->addDay(),
            'medium_term'  => $now->copy()->addDays(7),
            'long_term'    => $now->copy()->addDays(30),
            default        => $now->copy()->addDay(),
        };

        $keyFactors = [
            'trend' => $technical['trend'] ?? '',
            'support' => $technical['support'] ?? 0.0,
            'resistance' => $technical['resistance'] ?? 0.0,
            'adx' => $technical['adx'] ?? 0.0,
            'market_regime' => $marketRegime,
            'trend_info' => $trendInfo,
            'combined_signals' => $combinedSignals,
        ];

        $riskFactors = [
            'atr' => $technical['atr'] ?? 0.0,
            'risk_reward' => $riskReward,
            'stop_loss' => $targets['stop_loss'],
        ];

        $probability = min(100.0, max(0.0, $compositeScore));
        $confidence = min(100.0, max(0.0, $compositeScore));

        $prediction = [
            'asset_id'         => $assetId,
            'asset'            => $asset,
            'timeframe'        => $timeframe,
            'signal'           => $signal,
            'strength'         => $strength,
            'confidence'       => round($confidence, 2),
            'probability'      => round($probability, 2),
            'current_price'    => $currentPrice,
            'target_1'         => $targets['target_1'],
            'target_2'         => $targets['target_2'],
            'stop_loss'        => $targets['stop_loss'],
            'risk_reward'      => $riskReward,
            'technical_score'  => round($technical['score'], 4),
            'fundamental_score' => round($fundamental['score'], 4),
            'sentiment_score'  => round($sentiment['score'], 4),
            'macro_score'      => round($macro['score'], 4),
            'composite_score'  => round($compositeScore, 4),
            'market_regime'    => $marketRegime,
            'trend_info'       => $trendInfo,
            'anomalies'        => $anomalies,
            'key_factors'      => $keyFactors,
            'risk_factors'     => $riskFactors,
            'reasoning'        => $reasoning,
            'expires_at'       => $expiresAt->toDateTimeString(),
            'created_at'       => $now->toDateTimeString(),
        ];

        $signalId = $this->saveSignal($prediction);
        $prediction['signal_id'] = $signalId;

        $this->logger->info("Prediction generated for asset {$assetId}: signal={$signal}, score=" . round($compositeScore, 2));

        return $prediction;
    }

    public function generateReasoning(array $scores, string $signal, array $analysis): string
    {
        $technicalScore = round($scores['technical'], 1);
        $fundamentalScore = round($scores['fundamental'], 1);
        $sentimentScore = round($scores['sentiment'], 1);
        $compositeScore = round($scores['composite'], 1);

        $rsi = round((float) ($analysis['technical']['rsi'] ?? 0.0), 1);
        $macdHistogram = (float) ($analysis['technical']['macd']['histogram'] ?? 0.0);
        $peRatio = round((float) ($analysis['fundamental']['pe_ratio'] ?? 0.0), 1);

        $rsiCondition = match (true) {
            $rsi < 30 => 'indicating oversold conditions',
            $rsi > 70 => 'indicating overbought conditions',
            default   => 'in neutral territory',
        };

        $macdDescription = $macdHistogram >= 0
            ? 'bullish MACD crossover'
            : 'bearish MACD crossover';

        $confidenceLabel = match (true) {
            $compositeScore >= 75 => 'strong',
            $compositeScore >= 55 => 'moderate',
            default               => 'weak',
        };

        $sentimentMomentum = (float) ($analysis['sentiment']['change_48h'] ?? 0.0) >= 0
            ? 'positive momentum'
            : 'negative momentum';

        $reasoning = "Technical analysis (score: {$technicalScore}) shows RSI at {$rsi} {$rsiCondition} with {$macdDescription}. "
            . "Fundamental score of {$fundamentalScore} supported by P/E ratio of {$peRatio}. "
            . "Market sentiment at {$sentimentScore} with {$sentimentMomentum}. "
            . "Composite score of {$compositeScore} generating {$signal} signal with {$confidenceLabel} confidence.";

        return $reasoning;
    }

    public function calculatePriceTargets(float $currentPrice, string $signal, array $technical): array
    {
        $atr = (float) ($technical['atr'] ?? 0.0);

        return match ($signal) {
            'BUY' => [
                'target_1'  => round($currentPrice + (1.5 * $atr), 4),
                'target_2'  => round($currentPrice + (2.5 * $atr), 4),
                'stop_loss' => round($currentPrice - (1.0 * $atr), 4),
            ],
            'SELL' => [
                'target_1'  => round($currentPrice - (1.5 * $atr), 4),
                'target_2'  => round($currentPrice - (2.5 * $atr), 4),
                'stop_loss' => round($currentPrice + (1.0 * $atr), 4),
            ],
            default => [
                'target_1'  => round($currentPrice + (1.0 * $atr), 4),
                'target_2'  => round($currentPrice - (1.0 * $atr), 4),
                'stop_loss' => round($currentPrice - (1.0 * $atr), 4),
            ],
        };
    }

    public function calculateRiskReward(float $entry, float $target1, float $stopLoss, string $signal = 'BUY'): float
    {
        if ($signal === 'BUY') {
            $denominator = $entry - $stopLoss;
            if ($denominator == 0.0) {
                return 0.0;
            }
            return round(($target1 - $entry) / $denominator, 2);
        }

        if ($signal === 'SELL') {
            $denominator = $stopLoss - $entry;
            if ($denominator == 0.0) {
                return 0.0;
            }
            return round(($entry - $target1) / $denominator, 2);
        }

        return 0.0;
    }

    public function saveSignal(array $prediction): string
    {
        $data = [
            'asset_id'            => $prediction['asset_id'],
            'signal_type'         => $prediction['signal'],
            'strength'            => $prediction['strength'],
            'timeframe'           => $prediction['timeframe'],
            'confidence_score'    => $prediction['confidence'],
            'probability_success' => $prediction['probability'],
            'entry_price'         => $prediction['current_price'],
            'target_price_1'      => $prediction['target_1'],
            'target_price_2'      => $prediction['target_2'],
            'stop_loss_price'     => $prediction['stop_loss'],
            'risk_reward_ratio'   => $prediction['risk_reward'],
            'technical_score'     => $prediction['technical_score'],
            'fundamental_score'   => $prediction['fundamental_score'],
            'sentiment_score'     => $prediction['sentiment_score'],
            'macro_score'         => $prediction['macro_score'],
            'composite_score'     => $prediction['composite_score'],
            'reasoning'           => $prediction['reasoning'],
            'key_factors'         => json_encode($prediction['key_factors']),
            'risk_factors'        => json_encode($prediction['risk_factors']),
            'anomalies'           => json_encode($prediction['anomalies']),
            'market_regime'       => $prediction['market_regime'],
            'expires_at'          => $prediction['expires_at'],
        ];

        $uuid = $this->db->insert('signals', $data);

        $this->logger->debug("Signal saved with UUID: {$uuid}");

        return (string) $uuid;
    }

    public function getHistoricalAccuracy(string $assetId, int $days = 90): array
    {
        $sql = "SELECT s.id, s.signal_type, s.created_at, sp.outcome, sp.actual_return_percent AS actual_return
                FROM signals s
                LEFT JOIN signal_performance sp ON s.id = sp.signal_id
                WHERE s.asset_id = ? AND s.created_at >= NOW() - INTERVAL '{$days} days'";

        $rows = $this->db->fetchAll($sql, [$assetId]);

        $totalSignals = count($rows);
        $wins = 0;
        $losses = 0;
        $returns = [];
        $bestReturn = null;
        $worstReturn = null;
        $bestSignal = '';
        $worstSignal = '';

        foreach ($rows as $row) {
            if (($row['outcome'] ?? null) === 'success') {
                $wins++;
            } elseif (($row['outcome'] ?? null) === 'failure') {
                $losses++;
            }

            if ($row['actual_return'] !== null) {
                $actualReturn = (float) $row['actual_return'];
                $returns[] = $actualReturn;

                if ($bestReturn === null || $actualReturn > $bestReturn) {
                    $bestReturn = $actualReturn;
                    $bestSignal = (string) ($row['signal_type'] ?? '');
                }

                if ($worstReturn === null || $actualReturn < $worstReturn) {
                    $worstReturn = $actualReturn;
                    $worstSignal = (string) ($row['signal_type'] ?? '');
                }
            }
        }

        $winRate = $totalSignals > 0 ? round($wins / $totalSignals, 4) : 0.0;
        $avgReturn = count($returns) > 0 ? round(array_sum($returns) / count($returns), 4) : 0.0;

        return [
            'total_signals' => $totalSignals,
            'wins'          => $wins,
            'losses'        => $losses,
            'win_rate'      => $winRate,
            'avg_return'    => $avgReturn,
            'best_signal'   => $bestSignal,
            'worst_signal'  => $worstSignal,
        ];
    }
}
