<?php

declare(strict_types=1);

namespace AlphaForge\Services\Prediction\Models;

class SignalGenerator
{
    /**
     * Generate individual technical signals from indicator data.
     *
     * @param array $indicators Associative array containing:
     *   - rsi (float)
     *   - macd (array: value, signal_line, histogram)
     *   - ema (array: ema9, ema20, ema50, ema200)
     *   - adx (float)
     *   - stochastic (array: k, d)
     *   - williams_r (float)
     *   - cci (float)
     *   - bollinger_bands (array: upper, middle, lower, price)
     * @return array Array of signal arrays, each with keys:
     *   indicator, signal, strength, value, description
     */
    public function generateTechnicalSignals(array $indicators): array
    {
        $signals = [];

        // --- RSI ---
        $rsi = (float) $indicators['rsi'];
        if ($rsi < 30.0) {
            $strength = min((30.0 - $rsi) / 30.0, 1.0);
            $signals[] = [
                'indicator'   => 'RSI',
                'signal'      => 'BUY',
                'strength'    => $strength,
                'value'       => $rsi,
                'description' => "RSI {$rsi} indicates oversold",
            ];
        } elseif ($rsi > 70.0) {
            $strength = min(($rsi - 70.0) / 30.0, 1.0);
            $signals[] = [
                'indicator'   => 'RSI',
                'signal'      => 'SELL',
                'strength'    => $strength,
                'value'       => $rsi,
                'description' => "RSI {$rsi} indicates overbought",
            ];
        } else {
            $signals[] = [
                'indicator'   => 'RSI',
                'signal'      => 'HOLD',
                'strength'    => 0.0,
                'value'       => $rsi,
                'description' => "RSI {$rsi} is neutral",
            ];
        }

        // --- MACD ---
        $macd      = $indicators['macd'];
        $histogram = (float) $macd['histogram'];
        $macdValue = (float) $macd['value'];

        if ($histogram > 0.0) {
            $strength = min(abs($histogram) / (abs($macdValue) + 0.0001), 1.0);
            $signals[] = [
                'indicator'   => 'MACD',
                'signal'      => 'BUY',
                'strength'    => $strength,
                'value'       => $macd,
                'description' => "MACD histogram {$histogram} - bullish momentum",
            ];
        } elseif ($histogram < 0.0) {
            $strength = min(abs($histogram) / (abs($macdValue) + 0.0001), 1.0);
            $signals[] = [
                'indicator'   => 'MACD',
                'signal'      => 'SELL',
                'strength'    => $strength,
                'value'       => $macd,
                'description' => "MACD histogram {$histogram} - bearish momentum",
            ];
        } else {
            $signals[] = [
                'indicator'   => 'MACD',
                'signal'      => 'HOLD',
                'strength'    => 0.0,
                'value'       => $macd,
                'description' => "MACD histogram {$histogram} - neutral",
            ];
        }

        // --- EMA Alignment ---
        $ema    = $indicators['ema'];
        $ema9   = (float) $ema['ema9'];
        $ema20  = (float) $ema['ema20'];
        $ema50  = (float) $ema['ema50'];
        $ema200 = (float) $ema['ema200'];

        $bullishPairs = 0;
        $bearishPairs = 0;

        if ($ema9 > $ema20)  { $bullishPairs++; }
        if ($ema20 > $ema50) { $bullishPairs++; }
        if ($ema50 > $ema200){ $bullishPairs++; }

        if ($ema9 < $ema20)  { $bearishPairs++; }
        if ($ema20 < $ema50) { $bearishPairs++; }
        if ($ema50 < $ema200){ $bearishPairs++; }

        if ($bullishPairs >= $bearishPairs && $bullishPairs > 0) {
            $strength = $bullishPairs / 3.0;
            $label    = match ($bullishPairs) {
                3 => 'strong bullish',
                2 => 'moderate bullish',
                default => 'slight bullish',
            };
            $signals[] = [
                'indicator'   => 'EMA',
                'signal'      => 'BUY',
                'strength'    => $strength,
                'value'       => $ema,
                'description' => "EMA alignment: bullish ({$bullishPairs}/3 pairs aligned)",
            ];
        } elseif ($bearishPairs > 0) {
            $strength = $bearishPairs / 3.0;
            $signals[] = [
                'indicator'   => 'EMA',
                'signal'      => 'SELL',
                'strength'    => $strength,
                'value'       => $ema,
                'description' => "EMA alignment: bearish ({$bearishPairs}/3 pairs aligned)",
            ];
        } else {
            $signals[] = [
                'indicator'   => 'EMA',
                'signal'      => 'HOLD',
                'strength'    => 0.0,
                'value'       => $ema,
                'description' => "EMA alignment: neutral (0/3 pairs aligned)",
            ];
        }

        // --- Stochastic ---
        if (isset($indicators['stochastic'])) {
            $stochastic = $indicators['stochastic'];
            $k          = (float) $stochastic['k'];
            $d          = (float) $stochastic['d'];

            if ($k < 20.0) {
                $strength = min((20.0 - $k) / 20.0, 1.0);
                $signals[] = [
                    'indicator'   => 'Stochastic',
                    'signal'      => 'BUY',
                    'strength'    => $strength,
                    'value'       => $stochastic,
                    'description' => "Stochastic K={$k}",
                ];
            } elseif ($k > 80.0) {
                $strength = min(($k - 80.0) / 20.0, 1.0);
                $signals[] = [
                    'indicator'   => 'Stochastic',
                    'signal'      => 'SELL',
                    'strength'    => $strength,
                    'value'       => $stochastic,
                    'description' => "Stochastic K={$k}",
                ];
            } else {
                $signals[] = [
                    'indicator'   => 'Stochastic',
                    'signal'      => 'HOLD',
                    'strength'    => 0.0,
                    'value'       => $stochastic,
                    'description' => "Stochastic K={$k}",
                ];
            }
        }

        // --- Williams %R ---
        $williamsR = (float) $indicators['williams_r'];
        if ($williamsR < -80.0) {
            $strength = min((-80.0 - $williamsR) / 20.0, 1.0);
            $signals[] = [
                'indicator'   => 'Williams %R',
                'signal'      => 'BUY',
                'strength'    => $strength,
                'value'       => $williamsR,
                'description' => "Williams %R={$williamsR}",
            ];
        } elseif ($williamsR > -20.0) {
            $strength = min(($williamsR + 20.0) / 20.0, 1.0);
            $signals[] = [
                'indicator'   => 'Williams %R',
                'signal'      => 'SELL',
                'strength'    => $strength,
                'value'       => $williamsR,
                'description' => "Williams %R={$williamsR}",
            ];
        } else {
            $signals[] = [
                'indicator'   => 'Williams %R',
                'signal'      => 'HOLD',
                'strength'    => 0.0,
                'value'       => $williamsR,
                'description' => "Williams %R={$williamsR}",
            ];
        }

        // --- CCI ---
        $cci = (float) $indicators['cci'];
        if ($cci < -100.0) {
            $strength = min(abs($cci + 100.0) / 100.0, 1.0);
            $signals[] = [
                'indicator'   => 'CCI',
                'signal'      => 'BUY',
                'strength'    => $strength,
                'value'       => $cci,
                'description' => "CCI={$cci}",
            ];
        } elseif ($cci > 100.0) {
            $strength = min(($cci - 100.0) / 100.0, 1.0);
            $signals[] = [
                'indicator'   => 'CCI',
                'signal'      => 'SELL',
                'strength'    => $strength,
                'value'       => $cci,
                'description' => "CCI={$cci}",
            ];
        } else {
            $signals[] = [
                'indicator'   => 'CCI',
                'signal'      => 'HOLD',
                'strength'    => 0.0,
                'value'       => $cci,
                'description' => "CCI={$cci}",
            ];
        }

        // --- Bollinger Bands ---
        if (isset($indicators['bollinger_bands'])) {
            $bb    = $indicators['bollinger_bands'];
            $upper = (float) $bb['upper'];
            $lower = (float) $bb['lower'];
            $price = (float) $bb['price'];

            if ($price <= $lower) {
                $strength = min(($lower - $price) / $lower + 0.5, 1.0);
                $signals[] = [
                    'indicator'   => 'Bollinger Bands',
                    'signal'      => 'BUY',
                    'strength'    => $strength,
                    'value'       => $bb,
                    'description' => "Price relative to Bollinger Bands",
                ];
            } elseif ($price >= $upper) {
                $strength = min(($price - $upper) / $upper + 0.5, 1.0);
                $signals[] = [
                    'indicator'   => 'Bollinger Bands',
                    'signal'      => 'SELL',
                    'strength'    => $strength,
                    'value'       => $bb,
                    'description' => "Price relative to Bollinger Bands",
                ];
            } else {
                $signals[] = [
                    'indicator'   => 'Bollinger Bands',
                    'signal'      => 'HOLD',
                    'strength'    => 0.0,
                    'value'       => $bb,
                    'description' => "Price relative to Bollinger Bands",
                ];
            }
        }

        return $signals;
    }

    /**
     * Generate a fundamental analysis signal from financial metrics.
     *
     * @param array $fundamentals Associative array with keys:
     *   pe_ratio (float), pb_ratio (float), roe (float),
     *   revenue_growth (float), debt_equity (float)
     * @return array Keys: signal, strength, score, description
     */
    public function generateFundamentalSignal(array $fundamentals): array
    {
        $score = 0.0;

        // P/E Ratio scoring
        $pe = (float) ($fundamentals['pe_ratio'] ?? PHP_FLOAT_MAX);
        if ($pe < 10.0)      { $score += 20.0; }
        elseif ($pe < 15.0)  { $score += 15.0; }
        elseif ($pe < 20.0)  { $score += 10.0; }
        elseif ($pe < 25.0)  { $score += 5.0; }

        // P/B Ratio scoring
        $pb = (float) ($fundamentals['pb_ratio'] ?? PHP_FLOAT_MAX);
        if ($pb < 1.0)       { $score += 20.0; }
        elseif ($pb < 1.5)   { $score += 15.0; }
        elseif ($pb < 2.0)   { $score += 10.0; }
        elseif ($pb < 3.0)   { $score += 5.0; }

        // ROE scoring
        $roe = (float) ($fundamentals['roe'] ?? 0.0);
        if ($roe > 20.0)     { $score += 20.0; }
        elseif ($roe > 15.0) { $score += 15.0; }
        elseif ($roe > 10.0) { $score += 10.0; }
        elseif ($roe > 5.0)  { $score += 5.0; }

        // Revenue Growth scoring
        $revGrowth = (float) ($fundamentals['revenue_growth'] ?? 0.0);
        if ($revGrowth > 20.0)     { $score += 20.0; }
        elseif ($revGrowth > 10.0) { $score += 15.0; }
        elseif ($revGrowth > 5.0)  { $score += 10.0; }
        elseif ($revGrowth > 0.0)  { $score += 5.0; }

        // Debt/Equity scoring
        $de = (float) ($fundamentals['debt_equity'] ?? PHP_FLOAT_MAX);
        if ($de < 0.5)      { $score += 20.0; }
        elseif ($de < 1.0)  { $score += 15.0; }
        elseif ($de < 1.5)  { $score += 10.0; }
        elseif ($de < 2.0)  { $score += 5.0; }

        // Determine signal
        if ($score > 65.0) {
            $signal = 'BUY';
        } elseif ($score < 35.0) {
            $signal = 'SELL';
        } else {
            $signal = 'HOLD';
        }

        $strength    = min(abs($score - 50.0) / 50.0, 1.0);
        $description = "Fundamental score {$score}/100: P/E={$pe}, P/B={$pb}, ROE={$roe}%";

        return [
            'signal'      => $signal,
            'strength'    => $strength,
            'score'       => $score,
            'description' => $description,
        ];
    }

    /**
     * Generate a sentiment composite signal.
     *
     * @param array $sentiment Associative array with keys:
     *   news_sentiment (float 0-100), social_sentiment (float 0-100),
     *   fear_greed (float 0-100)
     * @return array Keys: signal, strength, score, description
     */
    public function generateSentimentSignal(array $sentiment): array
    {
        $news    = (float) ($sentiment['news_sentiment']   ?? 50.0);
        $social  = (float) ($sentiment['social_sentiment'] ?? 50.0);
        $fg      = (float) ($sentiment['fear_greed']       ?? 50.0);

        $composite = ($news * 0.4) + ($social * 0.3) + ($fg * 0.3);

        if ($composite > 65.0) {
            $signal   = 'BUY';
            $strength = ($composite - 65.0) / 35.0;
        } elseif ($composite < 35.0) {
            $signal   = 'SELL';
            $strength = (35.0 - $composite) / 35.0;
        } else {
            $signal   = 'HOLD';
            $strength = 0.0;
        }

        $strength    = min($strength, 1.0);
        $description = "Sentiment composite {$composite}: news={$news}, social={$social}, fear/greed={$fg}";

        return [
            'signal'      => $signal,
            'strength'    => $strength,
            'score'       => $composite,
            'description' => $description,
        ];
    }

    /**
     * Combine multiple signals into a single weighted aggregate signal.
     *
     * @param array $signals Array of signal arrays, each with 'signal' and 'strength' keys
     * @param array $weights Optional numeric weights indexed the same as $signals.
     *                       If empty, equal weighting is applied.
     * @return array Keys: signal, strength (string label), score (0-100), components
     */
    public function combineSignals(array $signals, array $weights): array
    {
        if (empty($signals)) {
            return [
                'signal'     => 'HOLD',
                'strength'   => 'weak',
                'score'      => 50.0,
                'components' => $signals,
            ];
        }

        $useEqualWeights = empty($weights);
        $totalWeight     = 0.0;
        $weightedSum     = 0.0;

        foreach ($signals as $index => $sig) {
            $direction = match (strtoupper((string) ($sig['signal'] ?? 'HOLD'))) {
                'BUY'  =>  1.0,
                'SELL' => -1.0,
                default => 0.0,
            };

            $sigStrength = (float) ($sig['strength'] ?? 0.0);

            // If strength is a string label, convert it to a numeric approximation
            if (is_string($sig['strength'])) {
                $sigStrength = match (strtolower($sig['strength'])) {
                    'very_strong' => 0.9,
                    'strong'      => 0.65,
                    'moderate'    => 0.4,
                    'weak'        => 0.2,
                    'neutral'     => 0.0,
                    default       => 0.0,
                };
            }

            $numericScore = $direction * $sigStrength;

            if ($useEqualWeights) {
                $w = 1.0;
            } else {
                $w = isset($weights[$index]) ? (float) $weights[$index] : 1.0;
            }

            $weightedSum += $numericScore * $w;
            $totalWeight += $w;
        }

        $avg = $totalWeight > 0.0 ? $weightedSum / $totalWeight : 0.0;

        if ($avg > 0.1) {
            $signal = 'BUY';
        } elseif ($avg < -0.1) {
            $signal = 'SELL';
        } else {
            $signal = 'HOLD';
        }

        $absAvg = abs($avg);
        $strengthLabel = match (true) {
            $absAvg > 0.7 => 'very_strong',
            $absAvg > 0.5 => 'strong',
            $absAvg > 0.3 => 'moderate',
            default       => 'weak',
        };

        // Convert avg (-1 to +1) to 0-100 scale
        $score = (($avg + 1.0) / 2.0) * 100.0;

        return [
            'signal'     => $signal,
            'strength'   => $strengthLabel,
            'score'      => $score,
            'components' => $signals,
        ];
    }

    /**
     * Convert a signal + strength to a numeric score (0-100).
     *
     * @param string $signal   'BUY', 'SELL', or 'HOLD'
     * @param float  $strength Numeric 0-1 scale (interpreted as continuous float)
     * @return float Score in 0-100 range
     */
    public function signalToScore(string $signal, float $strength): float
    {
        return match (strtoupper($signal)) {
            'BUY'  => 50.0 + ($strength * 40.0),
            'SELL' => 50.0 - ($strength * 40.0),
            default => 50.0,
        };
    }

    /**
     * Convert a numeric score (0-100) to a signal array.
     *
     * @param float $score Score in 0-100 range
     * @return array Keys: signal (string), strength (string label)
     */
    public function scoreToSignal(float $score): array
    {
        return match (true) {
            $score <= 20.0  => ['signal' => 'SELL', 'strength' => 'very_strong'],
            $score <= 35.0  => ['signal' => 'SELL', 'strength' => 'strong'],
            $score <= 45.0  => ['signal' => 'SELL', 'strength' => 'moderate'],
            $score <= 55.0  => ['signal' => 'HOLD', 'strength' => 'neutral'],
            $score <= 65.0  => ['signal' => 'BUY',  'strength' => 'moderate'],
            $score <= 80.0  => ['signal' => 'BUY',  'strength' => 'strong'],
            default         => ['signal' => 'BUY',  'strength' => 'very_strong'],
        };
    }
}
