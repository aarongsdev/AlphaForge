<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\TechnicalAnalysis;

use PDO;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\ATR;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\BollingerBands;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\EMA;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\Fibonacci;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\Ichimoku;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\MACD;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\RSI;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\SMA;
use AlphaForge\Services\Analysis\TechnicalAnalysis\Indicators\VolumeProfile;

/**
 * Technical Analysis Engine
 *
 * Orchestrates all indicator calculations, generates individual signals,
 * produces a composite score, and persists the result to the database.
 */
class TechnicalAnalysisEngine
{
    private PDO           $pdo;
    private RSI           $rsi;
    private MACD          $macd;
    private EMA           $ema;
    private SMA           $sma;
    private ATR           $atr;
    private BollingerBands $bb;
    private Fibonacci     $fib;
    private Ichimoku      $ichimoku;
    private VolumeProfile $volumeProfile;

    public function __construct(PDO $pdo)
    {
        $this->pdo           = $pdo;
        $this->rsi           = new RSI();
        $this->macd          = new MACD();
        $this->ema           = new EMA();
        $this->sma           = new SMA();
        $this->atr           = new ATR();
        $this->bb            = new BollingerBands();
        $this->fib           = new Fibonacci();
        $this->ichimoku      = new Ichimoku();
        $this->volumeProfile = new VolumeProfile();
    }

    /**
     * Run a full technical analysis for an asset.
     *
     * @param string $assetId  Ticker or asset identifier.
     * @param string $interval 'daily' | 'weekly' | '1h' | '4h' etc.
     * @param int    $periods  Number of OHLCV bars to fetch (default 200).
     * @return array{
     *   asset_id: string,
     *   analyzed_at: string,
     *   interval: string,
     *   indicators: array,
     *   signals: array,
     *   composite_score: float,
     *   trend: string,
     *   trend_strength: float,
     *   key_levels: array{support: array, resistance: array},
     *   recommendation: string,
     *   confidence: float
     * }
     */
    public function analyze(string $assetId, string $interval = 'daily', int $periods = 200): array
    {
        $ohlcv   = $this->fetchOHLCV($assetId, $interval, $periods);
        $closes  = array_column($ohlcv, 'close');
        $highs   = array_column($ohlcv, 'high');
        $lows    = array_column($ohlcv, 'low');
        $n       = count($closes);

        if ($n < 52) {
            throw new \RuntimeException("Insufficient data for technical analysis (need ≥52 bars, got {$n}).");
        }

        // ── RSI ──────────────────────────────────────────────────────────────
        $rsiValues     = $this->rsi->calculate($closes, 14);
        $lastRsi       = $this->lastNonNull($rsiValues);
        $rsiInterpret  = $lastRsi !== null ? $this->rsi->interpret($lastRsi) : null;
        $rsiDivergence = $this->rsi->detectDivergence($closes, $rsiValues);

        // ── MACD ─────────────────────────────────────────────────────────────
        $macdValues    = $this->macd->calculate($closes, 12, 26, 9);
        $macdInterpret = $this->macd->interpret($macdValues);

        // ── EMA ──────────────────────────────────────────────────────────────
        $ema9    = $this->ema->calculate($closes, 9);
        $ema21   = $this->ema->calculate($closes, 21);
        $ema50   = $this->ema->calculate($closes, 50);
        $ema200  = $this->ema->calculate($closes, 200);
        $emaCrosses = $this->ema->crossover($ema50, $ema200);

        // ── SMA ──────────────────────────────────────────────────────────────
        $sma20  = $this->sma->calculate($closes, 20);
        $sma50  = $this->sma->calculate($closes, 50);
        $sma200 = $this->sma->calculate($closes, 200);
        $smaCrosses = $this->sma->crossover($sma50, $sma200);

        // ── ATR ──────────────────────────────────────────────────────────────
        $atrValues  = $this->atr->calculate($highs, $lows, $closes, 14);
        $natrValues = $this->atr->normalizedAtr($atrValues, $closes);
        $lastAtr    = $this->lastNonNull($atrValues);
        $lastNatr   = $this->lastNonNull($natrValues);

        // ── Bollinger Bands ───────────────────────────────────────────────────
        $bbValues    = $this->bb->calculate($closes, 20, 2.0);
        $currentClose = end($closes);
        $bbInterpret  = $this->bb->interpret($bbValues, $currentClose);

        // ── Ichimoku ─────────────────────────────────────────────────────────
        $ichimokuValues    = $this->ichimoku->calculate($highs, $lows, $closes);
        $ichimokuInterpret = $this->ichimoku->interpret($ichimokuValues, $currentClose);

        // ── Fibonacci ─────────────────────────────────────────────────────────
        $swings    = $this->fib->findSwings($highs, $lows, 20);
        $fibLevels = null;
        if ($swings['last_swing_high'] !== null && $swings['last_swing_low'] !== null) {
            $fibTrend  = $swings['last_swing_high']['index'] > $swings['last_swing_low']['index']
                ? 'downtrend' : 'uptrend';
            $fibLevels = $this->fib->calculateRetracement(
                $swings['last_swing_high']['price'],
                $swings['last_swing_low']['price'],
                $fibTrend
            );
        }
        $fibNearest = $fibLevels
            ? $this->fib->nearestLevel($currentClose, array_merge(
                $fibLevels['retracements'],
                $fibLevels['extensions']
            ))
            : null;

        // ── Volume Profile ────────────────────────────────────────────────────
        $vpResult = $this->volumeProfile->calculate($ohlcv, 100);
        $hvns     = $this->volumeProfile->findHighVolumeNodes($vpResult['profile']);
        $lvns     = $this->volumeProfile->findLowVolumeNodes($vpResult['profile']);

        // ── Key Levels (S/R) ──────────────────────────────────────────────────
        $lookback = min(50, $n);
        $keyLevels = $this->getSupportResistanceLevels(
            array_slice($highs,  -$lookback),
            array_slice($lows,   -$lookback)
        );

        // ── Signal collection ─────────────────────────────────────────────────
        $signals = $this->collectSignals(
            $rsiInterpret, $rsiDivergence,
            $macdInterpret,
            $emaCrosses, $smaCrosses,
            $ema50, $ema200, $closes,
            $bbInterpret,
            $ichimokuInterpret
        );

        // ── Composite score ───────────────────────────────────────────────────
        $compositeScore = $this->computeCompositeScore($signals, $ichimokuInterpret, $macdInterpret, $lastRsi);
        $trend          = $this->deriveTrend($compositeScore, $ichimokuInterpret);
        $trendStrength  = abs($compositeScore - 50.0) * 2.0; // 0-100

        // ── Recommendation & confidence ───────────────────────────────────────
        [$recommendation, $confidence] = $this->recommend($compositeScore, count($signals));

        $analysis = [
            'asset_id'    => $assetId,
            'analyzed_at' => date('Y-m-d H:i:s'),
            'interval'    => $interval,
            'indicators'  => [
                'rsi' => [
                    'values'     => $rsiValues,
                    'last_value' => $lastRsi,
                    'interpret'  => $rsiInterpret,
                    'divergence' => $rsiDivergence,
                ],
                'macd' => [
                    'values'    => $macdValues,
                    'interpret' => $macdInterpret,
                ],
                'ema' => [
                    'ema9'    => $ema9,
                    'ema21'   => $ema21,
                    'ema50'   => $ema50,
                    'ema200'  => $ema200,
                    'crosses' => $emaCrosses,
                ],
                'sma' => [
                    'sma20'   => $sma20,
                    'sma50'   => $sma50,
                    'sma200'  => $sma200,
                    'crosses' => $smaCrosses,
                ],
                'atr' => [
                    'values'      => $atrValues,
                    'last_atr'    => $lastAtr,
                    'natr_values' => $natrValues,
                    'last_natr'   => $lastNatr,
                ],
                'bollinger' => [
                    'values'    => $bbValues,
                    'interpret' => $bbInterpret,
                ],
                'ichimoku' => [
                    'values'    => $ichimokuValues,
                    'interpret' => $ichimokuInterpret,
                ],
                'volume_profile' => [
                    'poc'          => $vpResult['poc'],
                    'vah'          => $vpResult['vah'],
                    'val'          => $vpResult['val'],
                    'total_volume' => $vpResult['total_volume'],
                    'profile'      => $vpResult['profile'],
                    'hvns'         => $hvns,
                    'lvns'         => $lvns,
                ],
                'fibonacci' => [
                    'swings'   => $swings,
                    'levels'   => $fibLevels,
                    'nearest'  => $fibNearest,
                ],
            ],
            'signals'        => $signals,
            'composite_score'=> round($compositeScore, 2),
            'trend'          => $trend,
            'trend_strength' => round($trendStrength, 2),
            'key_levels'     => $keyLevels,
            'recommendation' => $recommendation,
            'confidence'     => round($confidence, 2),
        ];

        $this->saveToDatabase($analysis);

        return $analysis;
    }

    /**
     * Persist a full analysis result to the `technical_analysis` table.
     */
    public function saveToDatabase(array $analysis): void
    {
        $sql = <<<SQL
            INSERT INTO technical_analysis
                (asset_id, analyzed_at, interval, composite_score, trend, trend_strength,
                 recommendation, confidence, signals_json, indicators_json, key_levels_json)
            VALUES
                (:asset_id, :analyzed_at, :interval, :composite_score, :trend, :trend_strength,
                 :recommendation, :confidence, :signals_json, :indicators_json, :key_levels_json)
            ON DUPLICATE KEY UPDATE
                analyzed_at      = VALUES(analyzed_at),
                composite_score  = VALUES(composite_score),
                trend            = VALUES(trend),
                trend_strength   = VALUES(trend_strength),
                recommendation   = VALUES(recommendation),
                confidence       = VALUES(confidence),
                signals_json     = VALUES(signals_json),
                indicators_json  = VALUES(indicators_json),
                key_levels_json  = VALUES(key_levels_json)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':asset_id'       => $analysis['asset_id'],
            ':analyzed_at'    => $analysis['analyzed_at'],
            ':interval'       => $analysis['interval'],
            ':composite_score'=> $analysis['composite_score'],
            ':trend'          => $analysis['trend'],
            ':trend_strength' => $analysis['trend_strength'],
            ':recommendation' => $analysis['recommendation'],
            ':confidence'     => $analysis['confidence'],
            ':signals_json'   => json_encode($analysis['signals']),
            ':indicators_json'=> json_encode($analysis['indicators']),
            ':key_levels_json'=> json_encode($analysis['key_levels']),
        ]);
    }

    /**
     * Identify support and resistance levels using swing-point clustering.
     *
     * Swing highs become candidate resistance; swing lows become candidate support.
     * Nearby levels (within 0.5% of each other) are merged into a single level
     * (weighted average by touch count).
     *
     * @param float[] $highs
     * @param float[] $lows
     * @param int     $lookback Bars on each side to qualify as a swing point (default 50).
     * @return array{support: array<int,array{price: float, touches: int}>, resistance: array<int,array{price: float, touches: int}>}
     */
    public function getSupportResistanceLevels(array $highs, array $lows, int $lookback = 50): array
    {
        $highs = array_values($highs);
        $lows  = array_values($lows);
        $n     = count($highs);
        $window = 3; // local swing window

        $rawResistance = [];
        $rawSupport    = [];

        for ($i = $window; $i < $n - $window; $i++) {
            // Swing high
            $isHigh = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($j !== $i && $highs[$j] >= $highs[$i]) {
                    $isHigh = false;
                    break;
                }
            }
            if ($isHigh) {
                $rawResistance[] = $highs[$i];
            }

            // Swing low
            $isLow = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($j !== $i && $lows[$j] <= $lows[$i]) {
                    $isLow = false;
                    break;
                }
            }
            if ($isLow) {
                $rawSupport[] = $lows[$i];
            }
        }

        $resistance = $this->clusterLevels($rawResistance);
        $support    = $this->clusterLevels($rawSupport);

        // Sort descending for resistance, ascending for support
        usort($resistance, fn($a, $b) => $b['price'] <=> $a['price']);
        usort($support,    fn($a, $b) => $a['price'] <=> $b['price']);

        return [
            'support'    => $support,
            'resistance' => $resistance,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch OHLCV data from the database.
     *
     * @return array<int, array{open: float, high: float, low: float, close: float, volume: float}>
     */
    private function fetchOHLCV(string $assetId, string $interval, int $periods): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT open, high, low, close, volume
             FROM   ohlcv
             WHERE  asset_id = :asset_id
               AND  interval = :interval
             ORDER  BY bar_time ASC
             LIMIT  :periods'
        );
        $stmt->bindValue(':asset_id', $assetId, PDO::PARAM_STR);
        $stmt->bindValue(':interval', $interval, PDO::PARAM_STR);
        $stmt->bindValue(':periods',  $periods,  PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($r) => [
            'open'   => (float)$r['open'],
            'high'   => (float)$r['high'],
            'low'    => (float)$r['low'],
            'close'  => (float)$r['close'],
            'volume' => (float)$r['volume'],
        ], $rows);
    }

    /**
     * Find the last non-null value in an array.
     */
    private function lastNonNull(array $arr): float|null
    {
        for ($i = count($arr) - 1; $i >= 0; $i--) {
            if ($arr[$i] !== null) {
                return (float)$arr[$i];
            }
        }
        return null;
    }

    /**
     * Collect individual signals from all indicators.
     */
    private function collectSignals(
        ?array $rsiInterpret,
        array  $rsiDivergence,
        array  $macdInterpret,
        array  $emaCrosses,
        array  $smaCrosses,
        array  $ema50,
        array  $ema200,
        array  $closes,
        array  $bbInterpret,
        array  $ichimokuInterpret
    ): array {
        $signals = [];

        // RSI signal
        if ($rsiInterpret) {
            $signals[] = [
                'indicator' => 'RSI',
                'signal'    => $rsiInterpret['signal'],
                'strength'  => $rsiInterpret['strength'],
                'direction' => $rsiInterpret['signal'] === 'oversold' ? 'bullish'
                             : ($rsiInterpret['signal'] === 'overbought' ? 'bearish' : 'neutral'),
                'description'=> $rsiInterpret['description'],
            ];
        }

        // RSI divergence signals
        foreach ($rsiDivergence as $div) {
            $signals[] = [
                'indicator'  => 'RSI_Divergence',
                'signal'     => $div['type'] . '_divergence',
                'strength'   => 70.0,
                'direction'  => $div['type'] === 'bullish' ? 'bullish' : 'bearish',
                'description'=> $div['description'],
            ];
        }

        // MACD signal
        if (!empty($macdInterpret['crossover'])) {
            $macdDir = str_contains($macdInterpret['crossover'], 'bullish') ? 'bullish'
                     : (str_contains($macdInterpret['crossover'], 'bearish') ? 'bearish' : 'neutral');
            $signals[] = [
                'indicator'  => 'MACD',
                'signal'     => $macdInterpret['crossover'],
                'strength'   => 65.0,
                'direction'  => $macdDir,
                'description'=> $macdInterpret['description'],
            ];
        }

        // EMA cross signals (most recent only)
        if (!empty($emaCrosses)) {
            $lastCross = end($emaCrosses);
            $signals[] = [
                'indicator'  => 'EMA_Cross',
                'signal'     => $lastCross['type'],
                'strength'   => 80.0,
                'direction'  => $lastCross['type'] === 'golden_cross' ? 'bullish' : 'bearish',
                'description'=> $lastCross['description'],
            ];
        }

        // EMA trend bias: price vs EMA50 & EMA200
        $lastEma50  = $this->lastNonNull($ema50);
        $lastEma200 = $this->lastNonNull($ema200);
        $lastClose  = end($closes);
        if ($lastEma50 !== null && $lastEma200 !== null) {
            $aboveEma50  = $lastClose > $lastEma50;
            $aboveEma200 = $lastClose > $lastEma200;
            $direction   = ($aboveEma50 && $aboveEma200) ? 'bullish'
                         : (!$aboveEma50 && !$aboveEma200 ? 'bearish' : 'neutral');
            $signals[] = [
                'indicator'  => 'EMA_Trend',
                'signal'     => $direction . '_ema_bias',
                'strength'   => 60.0,
                'direction'  => $direction,
                'description'=> sprintf(
                    'Price (%.4f) is %s EMA50 (%.4f) and %s EMA200 (%.4f).',
                    $lastClose,
                    $aboveEma50  ? 'above' : 'below', $lastEma50,
                    $aboveEma200 ? 'above' : 'below', $lastEma200
                ),
            ];
        }

        // SMA cross signals
        if (!empty($smaCrosses)) {
            $lastCross = end($smaCrosses);
            $signals[] = [
                'indicator'  => 'SMA_Cross',
                'signal'     => $lastCross['type'],
                'strength'   => 75.0,
                'direction'  => $lastCross['type'] === 'golden_cross' ? 'bullish' : 'bearish',
                'description'=> $lastCross['description'],
            ];
        }

        // Bollinger Band signal
        if (!empty($bbInterpret['signal'])) {
            $bbDir = match($bbInterpret['signal']) {
                'oversold_breakout', 'approaching_lower', 'squeeze' => 'bullish',
                'overbought_breakout', 'approaching_upper'          => 'bearish',
                default                                              => 'neutral',
            };
            $signals[] = [
                'indicator'  => 'BollingerBands',
                'signal'     => $bbInterpret['signal'],
                'strength'   => $bbInterpret['squeeze'] ? 85.0 : 55.0,
                'direction'  => $bbDir,
                'description'=> $bbInterpret['description'],
            ];
        }

        // Ichimoku signal
        if (!empty($ichimokuInterpret['trend'])) {
            $signals[] = [
                'indicator'  => 'Ichimoku',
                'signal'     => $ichimokuInterpret['trend'],
                'strength'   => ($ichimokuInterpret['bullish_signals'] + $ichimokuInterpret['bearish_signals']) > 0
                    ? (max($ichimokuInterpret['bullish_signals'], $ichimokuInterpret['bearish_signals'])
                       / ($ichimokuInterpret['bullish_signals'] + $ichimokuInterpret['bearish_signals'])) * 100.0
                    : 50.0,
                'direction'  => $ichimokuInterpret['trend'],
                'description'=> $ichimokuInterpret['description'],
            ];
        }

        return $signals;
    }

    /**
     * Compute a 0-100 composite technical score.
     *
     * Bullish signals push the score above 50, bearish signals below 50.
     * Weights:
     *   RSI: 20, MACD: 20, EMA: 15, Ichimoku: 25, Bollinger: 10, SMA: 10
     */
    private function computeCompositeScore(
        array  $signals,
        array  $ichimokuInterpret,
        array  $macdInterpret,
        ?float $lastRsi
    ): float {
        $score  = 50.0; // neutral baseline
        $weight = 0.0;

        // RSI contribution (weight 20)
        if ($lastRsi !== null) {
            $rsiScore = $lastRsi; // Already 0-100; 50 = neutral
            $score   += ($rsiScore - 50.0) * 0.20;
            $weight  += 0.20;
        }

        // MACD contribution (weight 20)
        $macdBias = 0.0;
        if (!empty($macdInterpret['crossover'])) {
            $macdBias = match(true) {
                str_contains($macdInterpret['crossover'], 'bullish') =>  25.0,
                str_contains($macdInterpret['crossover'], 'bearish') => -25.0,
                $macdInterpret['macd_above_zero'] === true           =>  10.0,
                $macdInterpret['macd_above_zero'] === false          => -10.0,
                default => 0.0,
            };
        }
        $score  += $macdBias * 0.20;
        $weight += 0.20;

        // Ichimoku contribution (weight 25)
        $totalIchi = $ichimokuInterpret['bullish_signals'] + $ichimokuInterpret['bearish_signals'];
        if ($totalIchi > 0) {
            $ichiScore = (($ichimokuInterpret['bullish_signals'] / $totalIchi) - 0.5) * 2.0 * 50.0;
            $score    += $ichiScore * 0.25;
            $weight   += 0.25;
        }

        // Aggregate remaining signals (weight 35)
        $signalSum   = 0.0;
        $signalCount = 0;
        foreach ($signals as $sig) {
            $dir = $sig['direction'];
            if ($dir === 'bullish') {
                $signalSum += $sig['strength'];
                $signalCount++;
            } elseif ($dir === 'bearish') {
                $signalSum -= $sig['strength'];
                $signalCount++;
            }
        }
        if ($signalCount > 0) {
            $avgSignal = $signalSum / $signalCount; // -100 to +100
            $score    += ($avgSignal / 100.0) * 50.0 * 0.35;
            $weight   += 0.35;
        }

        return max(0.0, min(100.0, $score));
    }

    /**
     * Derive a trend label from the composite score and Ichimoku.
     */
    private function deriveTrend(float $score, array $ichimokuInterpret): string
    {
        if ($score >= 60) {
            return 'bullish';
        }
        if ($score <= 40) {
            return 'bearish';
        }
        return 'neutral';
    }

    /**
     * Map composite score to a recommendation and confidence.
     *
     * @return array{0: string, 1: float}
     */
    private function recommend(float $score, int $signalCount): array
    {
        $confidence = min(100.0, ($signalCount / 8.0) * 100.0);

        if ($score >= 65) {
            return ['BUY',  $confidence];
        }
        if ($score <= 35) {
            return ['SELL', $confidence];
        }
        return ['HOLD', $confidence * 0.8];
    }

    /**
     * Cluster price levels that are within 0.5% of each other.
     *
     * @param float[] $levels
     * @return array<int, array{price: float, touches: int}>
     */
    private function clusterLevels(array $levels): array
    {
        if (empty($levels)) {
            return [];
        }

        sort($levels);
        $clusters = [];
        $current  = [$levels[0]];

        for ($i = 1; $i < count($levels); $i++) {
            $prev = end($current);
            // Within 0.5%?
            if ($prev > 0 && abs($levels[$i] - $prev) / $prev <= 0.005) {
                $current[] = $levels[$i];
            } else {
                $clusters[] = $current;
                $current    = [$levels[$i]];
            }
        }
        $clusters[] = $current;

        return array_map(fn($c) => [
            'price'   => round(array_sum($c) / count($c), 4),
            'touches' => count($c),
        ], $clusters);
    }
}
