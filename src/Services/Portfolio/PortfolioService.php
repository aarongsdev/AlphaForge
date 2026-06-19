<?php

declare(strict_types=1);

namespace AlphaForge\Services\Portfolio;

use AlphaForge\Models\Portfolio;
use AlphaForge\Models\Asset;
use AlphaForge\Services\Market\MarketDataService;
use AlphaForge\Core\Logger;

/**
 * Portfolio business logic — trade execution, position tracking, performance calculation.
 */
class PortfolioService
{
    private Portfolio $portfolioModel;
    private Asset $assetModel;
    private MarketDataService $marketData;
    private Logger $logger;

    public function __construct()
    {
        $this->portfolioModel = new Portfolio();
        $this->assetModel     = new Asset();
        $this->marketData     = new MarketDataService();
        $this->logger         = Logger::getInstance();
    }

    /**
     * Execute a trade (BUY or SELL) and update positions.
     */
    public function executeTrade(string $portfolioId, array $tradeData): array
    {
        $portfolio = $this->portfolioModel->findById($portfolioId);
        if (!$portfolio) {
            throw new \RuntimeException('Portfolio not found.');
        }

        $asset = $this->assetModel->findBySymbol($tradeData['symbol']);
        if (!$asset) {
            throw new \RuntimeException("Asset {$tradeData['symbol']} not found.");
        }

        $type     = strtolower($tradeData['trade_type']);
        $qty      = (float) $tradeData['quantity'];
        $price    = (float) $tradeData['price'];
        $commission = (float) ($tradeData['commission'] ?? 0);
        $total    = $qty * $price + $commission;

        if ($qty <= 0 || $price <= 0) {
            throw new \InvalidArgumentException('Quantity and price must be positive.');
        }

        // Record the trade
        $tradeId = $this->portfolioModel->recordTrade($portfolioId, [
            'asset_id'    => $asset['id'],
            'signal_id'   => $tradeData['signal_id'] ?? null,
            'trade_type'  => $type,
            'quantity'    => $qty,
            'price'       => $price,
            'commission'  => $commission,
            'total_value' => $total,
            'currency'    => $portfolio['currency'],
            'notes'       => $tradeData['notes'] ?? null,
        ]);

        // Update position
        $this->updatePosition($portfolioId, $asset['id'], $type, $qty, $price);

        $this->logger->info('Trade executed', [
            'portfolio_id' => $portfolioId,
            'asset'        => $asset['symbol'],
            'type'         => $type,
            'qty'          => $qty,
            'price'        => $price,
        ]);

        return ['trade_id' => $tradeId, 'asset' => $asset['symbol'], 'type' => $type, 'total' => $total];
    }

    /**
     * Update or close a portfolio position after a trade.
     */
    private function updatePosition(string $portfolioId, string $assetId, string $type, float $qty, float $price): void
    {
        $positions = $this->portfolioModel->getPositions($portfolioId);
        $existing  = null;
        foreach ($positions as $pos) {
            if ($pos['asset_id'] === $assetId) {
                $existing = $pos;
                break;
            }
        }

        if ($type === 'buy') {
            if ($existing) {
                // Update average cost basis (weighted average)
                $oldQty  = (float) $existing['quantity'];
                $oldCost = (float) $existing['avg_cost_basis'];
                $newQty  = $oldQty + $qty;
                $newCost = ($oldQty * $oldCost + $qty * $price) / $newQty;

                $this->portfolioModel->upsertPosition($portfolioId, $assetId, [
                    'quantity'        => $newQty,
                    'avg_cost_basis'  => round($newCost, 6),
                    'current_price'   => $price,
                    'market_value'    => round($newQty * $price, 2),
                    'unrealized_pnl'  => round($newQty * ($price - $newCost), 2),
                    'unrealized_pnl_percent' => round(($price - $newCost) / $newCost * 100, 4),
                ]);
            } else {
                $this->portfolioModel->upsertPosition($portfolioId, $assetId, [
                    'quantity'               => $qty,
                    'avg_cost_basis'         => $price,
                    'current_price'          => $price,
                    'market_value'           => round($qty * $price, 2),
                    'unrealized_pnl'         => 0,
                    'unrealized_pnl_percent' => 0,
                    'realized_pnl'           => 0,
                ]);
            }
        } elseif ($type === 'sell' && $existing) {
            $oldQty     = (float) $existing['quantity'];
            $costBasis  = (float) $existing['avg_cost_basis'];
            $soldQty    = min($qty, $oldQty);
            $realizedPnl = $soldQty * ($price - $costBasis);
            $remainingQty = $oldQty - $soldQty;

            if ($remainingQty <= 0.000001) {
                // Position fully closed
                \AlphaForge\Core\Database::getInstance()->delete('portfolio_positions', [
                    'portfolio_id' => $portfolioId,
                    'asset_id'     => $assetId,
                ]);
            } else {
                $this->portfolioModel->upsertPosition($portfolioId, $assetId, [
                    'quantity'               => $remainingQty,
                    'current_price'          => $price,
                    'market_value'           => round($remainingQty * $price, 2),
                    'unrealized_pnl'         => round($remainingQty * ($price - $costBasis), 2),
                    'unrealized_pnl_percent' => round(($price - $costBasis) / $costBasis * 100, 4),
                    'realized_pnl'           => round((float) ($existing['realized_pnl'] ?? 0) + $realizedPnl, 2),
                ]);
            }
        }
    }

    /**
     * Refresh current prices for all positions in a portfolio.
     */
    public function refreshPositionPrices(string $portfolioId): array
    {
        $positions = $this->portfolioModel->getPositions($portfolioId);
        $updated   = [];

        foreach ($positions as $pos) {
            try {
                $quote = $this->marketData->getQuote($pos['symbol']);
                if ($quote && isset($quote['close'])) {
                    $currentPrice = (float) $quote['close'];
                    $costBasis    = (float) $pos['avg_cost_basis'];
                    $qty          = (float) $pos['quantity'];

                    $this->portfolioModel->upsertPosition($portfolioId, $pos['asset_id'], [
                        'current_price'          => $currentPrice,
                        'market_value'           => round($qty * $currentPrice, 2),
                        'unrealized_pnl'         => round($qty * ($currentPrice - $costBasis), 2),
                        'unrealized_pnl_percent' => $costBasis > 0
                            ? round(($currentPrice - $costBasis) / $costBasis * 100, 4)
                            : 0,
                    ]);
                    $updated[] = $pos['symbol'];
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Failed to refresh price for {$pos['symbol']}", ['error' => $e->getMessage()]);
            }
        }

        return $updated;
    }

    /**
     * Calculate comprehensive risk metrics for a portfolio.
     */
    public function calculateRiskMetrics(string $portfolioId): array
    {
        $snapshots = $this->portfolioModel->getSnapshots($portfolioId, 252);
        $positions = $this->portfolioModel->getPositions($portfolioId);

        if (empty($snapshots) || empty($positions)) {
            return $this->emptyRiskMetrics();
        }

        // Daily returns from equity curve
        $returns = [];
        $peak    = 0;
        $maxDD   = 0;
        $prev    = null;

        foreach ($snapshots as $snap) {
            $val = (float) $snap['total_value'];
            if ($prev !== null && $prev > 0) {
                $returns[] = ($val - $prev) / $prev;
            }
            $peak  = max($peak, $val);
            $dd    = $peak > 0 ? ($peak - $val) / $peak : 0;
            $maxDD = max($maxDD, $dd);
            $prev  = $val;
        }

        // VaR (Historical Simulation)
        sort($returns);
        $n     = count($returns);
        $var95 = $n > 0 ? abs($returns[(int) floor($n * 0.05)] ?? 0) : 0;
        $var99 = $n > 0 ? abs($returns[(int) floor($n * 0.01)] ?? 0) : 0;

        // CVaR (Expected Shortfall)
        $tailIdx = (int) floor($n * 0.05);
        $tail    = array_slice($returns, 0, $tailIdx);
        $cvar95  = !empty($tail) ? abs(array_sum($tail) / count($tail)) : 0;

        // Volatility
        $avgRet  = !empty($returns) ? array_sum($returns) / count($returns) : 0;
        $variance = 0;
        foreach ($returns as $r) {
            $variance += ($r - $avgRet) ** 2;
        }
        $stdDev     = $n > 1 ? sqrt($variance / ($n - 1)) : 0;
        $volatility = $stdDev * sqrt(252);

        // Sector & asset exposure
        $totalValue     = array_sum(array_column($positions, 'market_value'));
        $sectorExposure = [];
        $assetExposure  = [];

        foreach ($positions as $pos) {
            $weight = $totalValue > 0 ? (float) $pos['market_value'] / $totalValue : 0;
            $sector = $pos['sector'] ?? 'Unknown';
            $sectorExposure[$sector] = ($sectorExposure[$sector] ?? 0) + $weight;
            $assetExposure[$pos['symbol']] = round($weight * 100, 2);
        }

        return [
            'var_95'           => round($var95 * 100, 4),
            'var_99'           => round($var99 * 100, 4),
            'cvar_95'          => round($cvar95 * 100, 4),
            'volatility_annual'=> round($volatility * 100, 4),
            'max_drawdown'     => round($maxDD * 100, 2),
            'sector_exposure'  => array_map(fn($v) => round($v * 100, 2), $sectorExposure),
            'asset_exposure'   => $assetExposure,
            'positions_count'  => count($positions),
            'total_value'      => $totalValue,
        ];
    }

    private function emptyRiskMetrics(): array
    {
        return [
            'var_95' => 0, 'var_99' => 0, 'cvar_95' => 0,
            'volatility_annual' => 0, 'max_drawdown' => 0,
            'sector_exposure' => [], 'asset_exposure' => [],
            'positions_count' => 0, 'total_value' => 0,
        ];
    }
}
