<?php

declare(strict_types=1);

namespace AlphaForge\Services\Backtesting;

use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use AlphaForge\Core\Config;

/**
 * Historical backtesting engine that simulates trading based on technical signals.
 *
 * Supports RSI, MACD, and EMA crossover indicators with configurable thresholds.
 * Applies realistic slippage and commission models to simulate execution costs.
 * Produces a full equity curve, trade log, monthly returns, and performance metrics.
 */
class BacktestEngine
{
    private readonly Database $db;
    private readonly Logger $logger;
    private readonly Config $config;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
        $this->config = Config::getInstance();
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Run a backtest simulation for a given asset and strategy configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When required config keys are missing
     * @throws \RuntimeException When no market data is found
     */
    public function run(array $config): array
    {
        $symbol         = (string) ($config['symbol']          ?? '');
        $assetId        = (string) ($config['asset_id']        ?? '');
        $startDate      = (string) ($config['start_date']      ?? '');
        $endDate        = (string) ($config['end_date']        ?? '');
        $initialCapital = (float)  ($config['initial_capital'] ?? 10000.0);
        $commissionRate = (float)  ($config['commission_rate'] ?? 0.001);
        $slippage       = (float)  ($config['slippage']        ?? 0.001);
        $strategy       = (array)  ($config['strategy']        ?? []);

        $indicators = (array) ($strategy['indicators'] ?? []);
        $thresholds = (array) ($strategy['thresholds'] ?? [
            'rsi_oversold'   => 30,
            'rsi_overbought' => 70,
            'ema_short'      => 20,
            'ema_long'       => 50,
        ]);

        $this->logger->info('BacktestEngine: starting run', [
            'symbol'     => $symbol,
            'asset_id'   => $assetId,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]);

        // ── 1. Load OHLCV data ────────────────────────────────────────────────
        $rows = $this->db->fetchAll(
            'SELECT date, open, high, low, close, adj_close, volume
               FROM market_data_daily
              WHERE asset_id = ?
                AND date >= ?
                AND date <= ?
              ORDER BY date ASC',
            [$assetId, $startDate, $endDate],
        );

        if (empty($rows)) {
            $this->logger->warning('BacktestEngine: no market data found', [
                'asset_id'   => $assetId,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]);
            throw new \RuntimeException("No market data found for asset {$assetId} between {$startDate} and {$endDate}.");
        }

        $dates   = array_column($rows, 'date');
        $closes  = array_map('floatval', array_column($rows, 'close'));
        $n       = count($closes);

        // ── 2. Pre-calculate indicators ───────────────────────────────────────
        $emaShortPeriod = (int) ($thresholds['ema_short'] ?? 20);
        $emaLongPeriod  = (int) ($thresholds['ema_long']  ?? 50);

        $rsiValues  = in_array('RSI', $indicators, true)       ? $this->calculateRSI($closes)              : array_fill(0, $n, null);
        $macdValues = in_array('MACD', $indicators, true)      ? $this->calculateMACD($closes)             : array_fill(0, $n, ['macd' => null, 'signal' => null, 'histogram' => null]);
        $emaShort   = in_array('EMA_CROSS', $indicators, true) ? $this->calculateEMA($closes, $emaShortPeriod) : array_fill(0, $n, null);
        $emaLong    = in_array('EMA_CROSS', $indicators, true) ? $this->calculateEMA($closes, $emaLongPeriod)  : array_fill(0, $n, null);

        // ── 3. Simulate day by day ────────────────────────────────────────────
        $cash          = $initialCapital;
        $position      = 0.0;   // shares held
        $entryPrice    = 0.0;
        $entryDate     = '';
        $totalCommissions = 0.0;
        $tradeLog      = [];
        $equityCurve   = [];

        for ($i = 0; $i < $n; $i++) {
            $close       = $closes[$i];
            $currentDate = $dates[$i];

            $signal = $this->generateSignal($i, $closes, $rsiValues, $macdValues, $emaShort, $emaLong, $thresholds);

            if ($signal === 'BUY' && $position === 0.0 && $cash > 0.0) {
                // Execute buy at close + slippage
                $execPrice  = $close * (1.0 + $slippage);
                $shares     = floor($cash / $execPrice);

                if ($shares > 0.0) {
                    $tradeValue    = $shares * $execPrice;
                    $commission    = $tradeValue * $commissionRate;
                    $totalCost     = $tradeValue + $commission;

                    if ($totalCost <= $cash) {
                        $cash          -= $totalCost;
                        $position       = $shares;
                        $entryPrice     = $execPrice;
                        $entryDate      = $currentDate;
                        $totalCommissions += $commission;

                        $this->logger->info('BacktestEngine: BUY', [
                            'date'       => $currentDate,
                            'price'      => $execPrice,
                            'shares'     => $shares,
                            'commission' => $commission,
                        ]);
                    }
                }
            } elseif ($signal === 'SELL' && $position > 0.0) {
                // Execute sell at close - slippage
                $execPrice  = $close * (1.0 - $slippage);
                $tradeValue = $position * $execPrice;
                $commission = $tradeValue * $commissionRate;
                $proceeds   = $tradeValue - $commission;

                $pnl           = $proceeds - ($position * $entryPrice) - ($position * $entryPrice * $commissionRate);
                $returnPct     = (($execPrice - $entryPrice) / $entryPrice) * 100.0;

                // Calculate holding days
                $holdingDays = 0;
                if ($entryDate !== '' && $currentDate !== '') {
                    $entryDt     = new \DateTimeImmutable($entryDate);
                    $exitDt      = new \DateTimeImmutable($currentDate);
                    $holdingDays = (int) $entryDt->diff($exitDt)->days;
                }

                $tradeLog[] = [
                    'entry_date'     => $entryDate,
                    'entry_price'    => $entryPrice,
                    'exit_date'      => $currentDate,
                    'exit_price'     => $execPrice,
                    'quantity'       => $position,
                    'pnl'            => $pnl,
                    'return_percent' => $returnPct,
                    'holding_days'   => $holdingDays,
                ];

                $cash         += $proceeds;
                $totalCommissions += $commission;
                $position      = 0.0;
                $entryPrice    = 0.0;
                $entryDate     = '';

                $this->logger->info('BacktestEngine: SELL', [
                    'date'       => $currentDate,
                    'price'      => $execPrice,
                    'pnl'        => $pnl,
                    'return_pct' => $returnPct,
                ]);
            }

            // Record daily equity
            $positionValue   = $position * $close;
            $equityCurve[]   = [
                'date'  => $currentDate,
                'value' => $cash + $positionValue,
            ];
        }

        // Close any open position at last close price
        if ($position > 0.0 && $n > 0) {
            $lastClose  = $closes[$n - 1];
            $execPrice  = $lastClose * (1.0 - $slippage);
            $tradeValue = $position * $execPrice;
            $commission = $tradeValue * $commissionRate;
            $proceeds   = $tradeValue - $commission;

            $pnl        = $proceeds - ($position * $entryPrice) - ($position * $entryPrice * $commissionRate);
            $returnPct  = (($execPrice - $entryPrice) / $entryPrice) * 100.0;

            $holdingDays = 0;
            if ($entryDate !== '' && isset($dates[$n - 1])) {
                $entryDt     = new \DateTimeImmutable($entryDate);
                $exitDt      = new \DateTimeImmutable($dates[$n - 1]);
                $holdingDays = (int) $entryDt->diff($exitDt)->days;
            }

            $tradeLog[] = [
                'entry_date'     => $entryDate,
                'entry_price'    => $entryPrice,
                'exit_date'      => $dates[$n - 1],
                'exit_price'     => $execPrice,
                'quantity'       => $position,
                'pnl'            => $pnl,
                'return_percent' => $returnPct,
                'holding_days'   => $holdingDays,
            ];

            $cash         += $proceeds;
            $totalCommissions += $commission;
            $position      = 0.0;

            // Update last equity curve entry
            if (!empty($equityCurve)) {
                $equityCurve[$n - 1]['value'] = $cash;
            }
        }

        $finalCapital = $cash;
        $totalReturn  = $initialCapital > 0.0
            ? (($finalCapital - $initialCapital) / $initialCapital) * 100.0
            : 0.0;

        // ── 4–6. Metrics, monthly returns ─────────────────────────────────────
        $metrics       = $this->calculateMetrics($tradeLog, $equityCurve, $initialCapital);
        $monthlyReturns = $this->generateMonthlyReturns($equityCurve);

        // Aggregate trade stats
        $totalTrades   = count($tradeLog);
        $winningTrades = 0;
        $losingTrades  = 0;
        $returns       = [];
        $holdingDaysAll = [];

        foreach ($tradeLog as $trade) {
            $returns[] = $trade['return_percent'];
            $holdingDaysAll[] = $trade['holding_days'];

            if ($trade['pnl'] > 0.0) {
                $winningTrades++;
            } else {
                $losingTrades++;
            }
        }

        $winRate          = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100.0 : 0.0;
        $avgTradeReturn   = $this->mean($returns);
        $bestTradeReturn  = !empty($returns) ? max($returns) : 0.0;
        $worstTradeReturn = !empty($returns) ? min($returns) : 0.0;
        $avgHoldingDays   = $this->mean($holdingDaysAll);

        // Profit factor
        $grossWins   = 0.0;
        $grossLosses = 0.0;
        foreach ($tradeLog as $trade) {
            if ($trade['pnl'] > 0.0) {
                $grossWins += $trade['pnl'];
            } else {
                $grossLosses += abs($trade['pnl']);
            }
        }
        $profitFactor = $grossLosses > 0.0 ? $grossWins / $grossLosses : ($grossWins > 0.0 ? PHP_FLOAT_MAX : 0.0);

        $this->logger->info('BacktestEngine: run complete', [
            'symbol'        => $symbol,
            'total_trades'  => $totalTrades,
            'final_capital' => $finalCapital,
            'total_return'  => $totalReturn,
        ]);

        return [
            'symbol'                => $symbol,
            'start_date'            => $startDate,
            'end_date'              => $endDate,
            'initial_capital'       => $initialCapital,
            'final_capital'         => $finalCapital,
            'total_return_percent'  => $totalReturn,
            'total_trades'          => $totalTrades,
            'winning_trades'        => $winningTrades,
            'losing_trades'         => $losingTrades,
            'win_rate'              => $winRate,
            'profit_factor'         => $profitFactor,
            'max_drawdown_percent'  => $metrics['max_drawdown_percent'],
            'sharpe_ratio'          => $metrics['sharpe_ratio'],
            'sortino_ratio'         => $metrics['sortino_ratio'],
            'calmar_ratio'          => $metrics['calmar_ratio'],
            'avg_trade_return'      => $avgTradeReturn,
            'best_trade_return'     => $bestTradeReturn,
            'worst_trade_return'    => $worstTradeReturn,
            'avg_holding_days'      => $avgHoldingDays,
            'equity_curve'          => $equityCurve,
            'trade_log'             => $tradeLog,
            'monthly_returns'       => $monthlyReturns,
            'metrics'               => $metrics,
        ];
    }

    /**
     * Calculate comprehensive performance metrics from trade log and equity curve.
     *
     * @param array<int, array<string, mixed>> $trades
     * @param array<int, array<string, mixed>> $equityCurve
     * @param float $initialCapital
     * @return array<string, mixed>
     */
    public function calculateMetrics(array $trades, array $equityCurve, float $initialCapital): array
    {
        // ── Daily returns from equity curve ───────────────────────────────────
        $dailyReturns = [];
        for ($i = 1, $len = count($equityCurve); $i < $len; $i++) {
            $prev = (float) $equityCurve[$i - 1]['value'];
            $curr = (float) $equityCurve[$i]['value'];

            if ($prev > 0.0) {
                $dailyReturns[] = ($curr - $prev) / $prev;
            }
        }

        // ── Annualised return and volatility ──────────────────────────────────
        $meanDailyReturn  = $this->mean($dailyReturns);
        $annualisedReturn = $meanDailyReturn * 252.0;
        $dailyVol         = $this->calculateStdDev($dailyReturns);
        $annualisedVol    = $dailyVol * sqrt(252.0);

        // ── Sharpe Ratio (risk-free rate = 5%) ────────────────────────────────
        $riskFreeRate = 0.05;
        $sharpeRatio  = $annualisedVol > 0.0
            ? ($annualisedReturn - $riskFreeRate) / $annualisedVol
            : 0.0;

        // ── Sortino Ratio ─────────────────────────────────────────────────────
        $downsideReturns = array_values(array_filter($dailyReturns, static fn(float $r): bool => $r < 0.0));
        $downsideStdDev  = $this->calculateStdDev($downsideReturns) * sqrt(252.0);
        $sortinoRatio    = $downsideStdDev > 0.0
            ? ($annualisedReturn - $riskFreeRate) / $downsideStdDev
            : 0.0;

        // ── Max Drawdown ──────────────────────────────────────────────────────
        $peakEquity    = $initialCapital;
        $maxDrawdown   = 0.0;

        foreach ($equityCurve as $point) {
            $equity = (float) $point['value'];

            if ($equity > $peakEquity) {
                $peakEquity = $equity;
            }

            if ($peakEquity > 0.0) {
                $drawdown = ($peakEquity - $equity) / $peakEquity * 100.0;

                if ($drawdown > $maxDrawdown) {
                    $maxDrawdown = $drawdown;
                }
            }
        }

        // ── Calmar Ratio ──────────────────────────────────────────────────────
        $calmarRatio = $maxDrawdown > 0.0
            ? ($annualisedReturn * 100.0) / $maxDrawdown
            : 0.0;

        // ── Trade-level stats ─────────────────────────────────────────────────
        $totalTrades   = count($trades);
        $winningTrades = 0;
        $losingTrades  = 0;
        $grossWins     = 0.0;
        $grossLosses   = 0.0;
        $tradeReturns  = [];
        $holdingDays   = [];
        $totalCommissions = 0.0;

        foreach ($trades as $trade) {
            $pnl    = (float) $trade['pnl'];
            $retPct = (float) $trade['return_percent'];

            $tradeReturns[] = $retPct;
            $holdingDays[]  = (int) ($trade['holding_days'] ?? 0);

            // Approximate commission as entry commission (exit commission already deducted in pnl)
            $entryValue       = (float) $trade['entry_price'] * (float) $trade['quantity'];
            $exitValue        = (float) $trade['exit_price']  * (float) $trade['quantity'];
            $totalCommissions += ($entryValue + $exitValue) * 0.001; // approximate; actual tracked in run()

            if ($pnl > 0.0) {
                $winningTrades++;
                $grossWins += $pnl;
            } else {
                $losingTrades++;
                $grossLosses += abs($pnl);
            }
        }

        $winRate      = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100.0 : 0.0;
        $profitFactor = $grossLosses > 0.0 ? $grossWins / $grossLosses : ($grossWins > 0.0 ? PHP_FLOAT_MAX : 0.0);

        $avgTradeReturn   = $this->mean($tradeReturns);
        $bestTradeReturn  = !empty($tradeReturns) ? max($tradeReturns) : 0.0;
        $worstTradeReturn = !empty($tradeReturns) ? min($tradeReturns) : 0.0;
        $avgHoldingDays   = $this->mean($holdingDays);

        return [
            'sharpe_ratio'              => round($sharpeRatio, 4),
            'sortino_ratio'             => round($sortinoRatio, 4),
            'max_drawdown_percent'      => round($maxDrawdown, 4),
            'calmar_ratio'              => round($calmarRatio, 4),
            'win_rate'                  => round($winRate, 4),
            'profit_factor'             => round($profitFactor, 4),
            'total_trades'              => $totalTrades,
            'winning_trades'            => $winningTrades,
            'losing_trades'             => $losingTrades,
            'avg_trade_return_percent'  => round($avgTradeReturn, 4),
            'best_trade_return_percent' => round($bestTradeReturn, 4),
            'worst_trade_return_percent'=> round($worstTradeReturn, 4),
            'avg_holding_days'          => round($avgHoldingDays, 2),
            'total_commissions'         => round($totalCommissions, 4),
            'annualized_return'         => round($annualisedReturn * 100.0, 4),
            'volatility'                => round($annualisedVol * 100.0, 4),
        ];
    }

    /**
     * Generate monthly return breakdown from the equity curve.
     *
     * @param array<int, array<string, mixed>> $equityCurve
     * @return array<int, array<string, mixed>>
     */
    public function generateMonthlyReturns(array $equityCurve): array
    {
        if (empty($equityCurve)) {
            return [];
        }

        // Group by YYYY-MM
        /** @var array<string, list<float>> $byMonth */
        $byMonth = [];

        foreach ($equityCurve as $point) {
            $date  = (string) $point['date'];
            $value = (float)  $point['value'];
            $month = substr($date, 0, 7); // 'YYYY-MM'

            if (!isset($byMonth[$month])) {
                $byMonth[$month] = [];
            }
            $byMonth[$month][] = $value;
        }

        ksort($byMonth);

        $result = [];

        foreach ($byMonth as $month => $values) {
            $startValue    = $values[0];
            $endValue      = $values[count($values) - 1];
            $monthlyReturn = $startValue > 0.0
                ? (($endValue - $startValue) / $startValue) * 100.0
                : 0.0;

            $result[] = [
                'month'          => $month,
                'return_percent' => round($monthlyReturn, 4),
                'start_value'    => round($startValue, 4),
                'end_value'      => round($endValue, 4),
            ];
        }

        return $result;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Calculate RSI using Wilder's smoothing method.
     *
     * @param array<int, float> $closes
     * @param int $period
     * @return array<int, float|null>
     */
    private function calculateRSI(array $closes, int $period = 14): array
    {
        $n   = count($closes);
        $rsi = array_fill(0, $n, null);

        if ($n < $period + 1) {
            return $rsi;
        }

        // ── Seed: simple average of first $period gains and losses ────────────
        $avgGain = 0.0;
        $avgLoss = 0.0;

        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];

            if ($change > 0.0) {
                $avgGain += $change;
            } else {
                $avgLoss += abs($change);
            }
        }

        $avgGain /= $period;
        $avgLoss /= $period;

        $rs         = $avgLoss > 0.0 ? $avgGain / $avgLoss : 0.0;
        $rsi[$period] = 100.0 - (100.0 / (1.0 + $rs));

        // ── Subsequent values: Wilder's smoothing ─────────────────────────────
        for ($i = $period + 1; $i < $n; $i++) {
            $change  = $closes[$i] - $closes[$i - 1];
            $gain    = $change > 0.0 ? $change : 0.0;
            $loss    = $change < 0.0 ? abs($change) : 0.0;

            $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;

            $rs      = $avgLoss > 0.0 ? $avgGain / $avgLoss : 0.0;
            $rsi[$i] = 100.0 - (100.0 / (1.0 + $rs));
        }

        return $rsi;
    }

    /**
     * Calculate MACD (12/26/9 by default).
     *
     * @param array<int, float> $closes
     * @param int $fast
     * @param int $slow
     * @param int $signal
     * @return array<int, array{macd: float|null, signal: float|null, histogram: float|null}>
     */
    private function calculateMACD(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $n       = count($closes);
        $empty   = ['macd' => null, 'signal' => null, 'histogram' => null];
        $result  = array_fill(0, $n, $empty);

        if ($n < $slow) {
            return $result;
        }

        $emaFast = $this->calculateEMA($closes, $fast);
        $emaSlow = $this->calculateEMA($closes, $slow);

        // MACD line: first valid index is $slow - 1
        $macdLine = [];
        $macdIdx  = [];

        for ($i = 0; $i < $n; $i++) {
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
                $macdLine[] = (float) $emaFast[$i] - (float) $emaSlow[$i];
                $macdIdx[]  = $i;
            }
        }

        if (count($macdLine) < $signal) {
            // Not enough MACD values to compute signal line
            for ($j = 0, $total = count($macdLine); $j < $total; $j++) {
                $origIdx = $macdIdx[$j];
                $result[$origIdx] = ['macd' => $macdLine[$j], 'signal' => null, 'histogram' => null];
            }
            return $result;
        }

        // Signal line: EMA(signal) of MACD line
        $signalLine = $this->calculateEMA($macdLine, $signal);

        for ($j = 0, $total = count($macdLine); $j < $total; $j++) {
            $origIdx   = $macdIdx[$j];
            $macdVal   = $macdLine[$j];
            $sigVal    = $signalLine[$j];
            $histVal   = ($sigVal !== null) ? $macdVal - (float) $sigVal : null;

            $result[$origIdx] = [
                'macd'      => $macdVal,
                'signal'    => $sigVal,
                'histogram' => $histVal,
            ];
        }

        return $result;
    }

    /**
     * Calculate Exponential Moving Average.
     *
     * The first ($period - 1) entries are null; entry at index ($period - 1) is seeded
     * by the SMA of the first $period prices; subsequent values use the EMA formula.
     *
     * @param array<int, float> $prices
     * @param int $period
     * @return array<int, float|null>
     */
    private function calculateEMA(array $prices, int $period): array
    {
        $n   = count($prices);
        $ema = array_fill(0, $n, null);

        if ($n < $period) {
            return $ema;
        }

        $k = 2.0 / ($period + 1.0);

        // Seed with SMA
        $sum = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $prices[$i];
        }
        $ema[$period - 1] = $sum / $period;

        // Calculate subsequent EMA values
        for ($i = $period; $i < $n; $i++) {
            $ema[$i] = $prices[$i] * $k + (float) $ema[$i - 1] * (1.0 - $k);
        }

        return $ema;
    }

    /**
     * Generate a trading signal for the given day index.
     *
     * Evaluates RSI, MACD histogram, and EMA crossover signals. Uses majority voting
     * to produce a final BUY, SELL, or HOLD signal.
     *
     * @param int $index
     * @param array<int, float> $closes
     * @param array<int, float|null> $rsi
     * @param array<int, array{macd: float|null, signal: float|null, histogram: float|null}> $macd
     * @param array<int, float|null> $emaShort
     * @param array<int, float|null> $emaLong
     * @param array<string, mixed> $thresholds
     * @return string 'BUY'|'SELL'|'HOLD'
     */
    private function generateSignal(
        int   $index,
        array $closes,
        array $rsi,
        array $macd,
        array $emaShort,
        array $emaLong,
        array $thresholds,
    ): string {
        $buyVotes  = 0;
        $sellVotes = 0;

        $rsiOversold   = (float) ($thresholds['rsi_oversold']   ?? 30.0);
        $rsiOverbought = (float) ($thresholds['rsi_overbought'] ?? 70.0);

        // ── RSI signal ────────────────────────────────────────────────────────
        if (isset($rsi[$index])) {
            $rsiVal = $rsi[$index];

            if ($rsiVal !== null) {
                if ($rsiVal < $rsiOversold) {
                    $buyVotes++;
                } elseif ($rsiVal > $rsiOverbought) {
                    $sellVotes++;
                }
            }
        }

        // ── MACD histogram crossover signal ───────────────────────────────────
        if ($index >= 1 && isset($macd[$index], $macd[$index - 1])) {
            $currHist = $macd[$index]['histogram'];
            $prevHist = $macd[$index - 1]['histogram'];

            if ($currHist !== null && $prevHist !== null) {
                if ($currHist > 0.0 && $prevHist <= 0.0) {
                    // Bullish crossover: histogram crossed above zero
                    $buyVotes++;
                } elseif ($currHist < 0.0 && $prevHist >= 0.0) {
                    // Bearish crossover: histogram crossed below zero
                    $sellVotes++;
                }
            }
        }

        // ── EMA crossover signal ──────────────────────────────────────────────
        if ($index >= 1
            && isset($emaShort[$index], $emaShort[$index - 1])
            && isset($emaLong[$index],  $emaLong[$index - 1])
        ) {
            $currShort = $emaShort[$index];
            $currLong  = $emaLong[$index];
            $prevShort = $emaShort[$index - 1];
            $prevLong  = $emaLong[$index - 1];

            if ($currShort !== null && $currLong !== null && $prevShort !== null && $prevLong !== null) {
                $currAbove = $currShort > $currLong;
                $prevAbove = $prevShort > $prevLong;

                if ($currAbove && !$prevAbove) {
                    // Golden cross: short EMA crossed above long EMA
                    $buyVotes++;
                } elseif (!$currAbove && $prevAbove) {
                    // Death cross: short EMA crossed below long EMA
                    $sellVotes++;
                }
            }
        }

        // ── Majority vote ─────────────────────────────────────────────────────
        if ($buyVotes > $sellVotes) {
            return 'BUY';
        }

        if ($sellVotes > $buyVotes) {
            return 'SELL';
        }

        return 'HOLD';
    }

    /**
     * Sample standard deviation (divides by n-1).
     *
     * @param array<int, float|int> $values
     */
    private function calculateStdDev(array $values): float
    {
        $n = count($values);

        if ($n < 2) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sumSq = 0.0;

        foreach ($values as $v) {
            $diff   = (float) $v - $mean;
            $sumSq += $diff * $diff;
        }

        return sqrt($sumSq / ($n - 1));
    }

    /**
     * Arithmetic mean of a numeric array.
     *
     * @param array<int, float|int> $values
     */
    private function mean(array $values): float
    {
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($values as $v) {
            $sum += (float) $v;
        }

        return $sum / $n;
    }
}
