<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\FundamentalAnalysis;

use PDO;

/**
 * Fundamental Analysis Engine
 *
 * Fetches the latest financial data from the database, calculates every standard
 * fundamental metric, scores them relative to sector peers, and returns a
 * structured analysis with a BUY / SELL / HOLD recommendation.
 *
 * Expected DB table: `fundamentals`
 *   asset_id, period, revenue, revenue_prev_year,
 *   gross_profit, operating_income, net_income, ebitda, ebit,
 *   total_assets, total_liabilities, total_equity, current_assets,
 *   current_liabilities, working_capital, retained_earnings,
 *   total_debt, cash_and_equivalents, free_cash_flow, capex,
 *   earnings_per_share, earnings_per_share_prev_year,
 *   shares_outstanding, market_cap, enterprise_value,
 *   dividend_per_share, book_value_per_share, sector
 *
 * Expected DB table: `sector_averages`
 *   sector, metric, avg_value, p25_value, p75_value
 */
class FundamentalAnalysisEngine
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Run a full fundamental analysis for an asset.
     *
     * @return array{
     *   asset_id: string,
     *   analyzed_at: string,
     *   period: string,
     *   valuation: array,
     *   profitability: array,
     *   growth: array,
     *   financial_health: array,
     *   quality_score: float,
     *   fundamental_score: float,
     *   vs_sector: array,
     *   recommendation: string,
     *   summary: string
     * }
     */
    public function analyze(string $assetId): array
    {
        $fin = $this->fetchFundamentals($assetId);

        if (empty($fin)) {
            throw new \RuntimeException("No fundamental data found for asset: {$assetId}");
        }

        $sector = $fin['sector'] ?? 'unknown';

        // ── Valuation ──────────────────────────────────────────────────────────
        $marketCap       = (float)($fin['market_cap']        ?? 0);
        $enterpriseValue = (float)($fin['enterprise_value']  ?? 0);
        $revenue         = (float)($fin['revenue']           ?? 0);
        $netIncome       = (float)($fin['net_income']        ?? 0);
        $ebitda          = (float)($fin['ebitda']            ?? 0);
        $bookValue       = (float)($fin['book_value_per_share'] ?? 0);
        $sharesOut       = (float)($fin['shares_outstanding'] ?? 1);
        $eps             = (float)($fin['earnings_per_share'] ?? 0);
        $epsPrev         = (float)($fin['earnings_per_share_prev_year'] ?? 0);
        $fcf             = (float)($fin['free_cash_flow']    ?? 0);
        $revPrev         = (float)($fin['revenue_prev_year'] ?? 0);

        $peRatio   = ($eps != 0)           ? $marketCap / ($eps * $sharesOut) : null;
        $pbRatio   = ($bookValue != 0)     ? ($marketCap / $sharesOut) / $bookValue : null;
        $psRatio   = ($revenue != 0)       ? $marketCap / $revenue : null;
        $evEbitda  = ($ebitda != 0)        ? $enterpriseValue / $ebitda : null;
        $pegRatio  = ($peRatio !== null && $eps > 0 && $epsPrev > 0)
            ? $peRatio / (max(0.001, (($eps - $epsPrev) / abs($epsPrev)) * 100.0))
            : null;

        $dcfValue = $this->calculateDCF($fin);

        $valuation = [
            'pe_ratio'      => $this->roundOrNull($peRatio),
            'pb_ratio'      => $this->roundOrNull($pbRatio),
            'ps_ratio'      => $this->roundOrNull($psRatio),
            'ev_ebitda'     => $this->roundOrNull($evEbitda),
            'peg_ratio'     => $this->roundOrNull($pegRatio),
            'dcf_fair_value'=> round($dcfValue, 4),
        ];

        // ── Profitability ──────────────────────────────────────────────────────
        $grossProfit     = (float)($fin['gross_profit']     ?? 0);
        $operatingIncome = (float)($fin['operating_income'] ?? 0);
        $totalAssets     = (float)($fin['total_assets']     ?? 1);
        $totalEquity     = (float)($fin['total_equity']     ?? 1);
        $totalInvestedCapital = $totalEquity + (float)($fin['total_debt'] ?? 0);

        $grossMargin    = $revenue != 0 ? ($grossProfit     / $revenue) * 100.0 : 0.0;
        $operatingMargin= $revenue != 0 ? ($operatingIncome / $revenue) * 100.0 : 0.0;
        $netMargin      = $revenue != 0 ? ($netIncome       / $revenue) * 100.0 : 0.0;
        $ebitdaMargin   = $revenue != 0 ? ($ebitda          / $revenue) * 100.0 : 0.0;
        $roe            = $totalEquity  != 0 ? ($netIncome  / $totalEquity)  * 100.0 : 0.0;
        $roa            = $totalAssets  != 0 ? ($netIncome  / $totalAssets)  * 100.0 : 0.0;
        $roic           = $totalInvestedCapital != 0
            ? ($operatingIncome * (1.0 - 0.21) / $totalInvestedCapital) * 100.0 // assumes 21% tax
            : 0.0;

        $profitability = [
            'gross_margin'     => round($grossMargin,    4),
            'operating_margin' => round($operatingMargin,4),
            'net_margin'       => round($netMargin,      4),
            'roe'              => round($roe,            4),
            'roa'              => round($roa,            4),
            'roic'             => round($roic,           4),
            'ebitda_margin'    => round($ebitdaMargin,   4),
        ];

        // ── Growth ────────────────────────────────────────────────────────────
        $revGrowthYoY  = $revPrev  != 0 ? (($revenue  - $revPrev)  / abs($revPrev))  * 100.0 : 0.0;
        $epsGrowthYoY  = $epsPrev  != 0 ? (($eps      - $epsPrev)  / abs($epsPrev))  * 100.0 : 0.0;
        $fcfGrowth     = 0.0; // Requires prior-period FCF; placeholder returns 0 when unavailable

        $growth = [
            'revenue_growth_yoy'  => round($revGrowthYoY, 4),
            'earnings_growth_yoy' => round($epsGrowthYoY, 4),
            'fcf_growth'          => round($fcfGrowth,    4),
            'eps_growth'          => round($epsGrowthYoY, 4),
        ];

        // ── Financial Health ───────────────────────────────────────────────────
        $totalLiabilities  = (float)($fin['total_liabilities']  ?? 0);
        $currentAssets     = (float)($fin['current_assets']     ?? 0);
        $currentLiabilities= (float)($fin['current_liabilities']?? 1);
        $cash              = (float)($fin['cash_and_equivalents']?? 0);
        $inventory         = (float)($fin['inventory']          ?? 0);
        $interestExpense   = (float)($fin['interest_expense']   ?? 1);
        $totalDebt         = (float)($fin['total_debt']         ?? 0);
        $ebit              = (float)($fin['ebit']               ?? 0);

        $debtToEquity     = $totalEquity          != 0 ? $totalDebt         / $totalEquity          : null;
        $currentRatio     = $currentLiabilities   != 0 ? $currentAssets     / $currentLiabilities   : null;
        $quickRatio       = $currentLiabilities   != 0
            ? ($currentAssets - $inventory) / $currentLiabilities
            : null;
        $interestCoverage = $interestExpense       != 0 ? $ebit              / $interestExpense       : null;

        $altmanZ = $this->calculateAltmanZScore($fin);

        $financialHealth = [
            'debt_to_equity'     => $this->roundOrNull($debtToEquity),
            'current_ratio'      => $this->roundOrNull($currentRatio),
            'quick_ratio'        => $this->roundOrNull($quickRatio),
            'interest_coverage'  => $this->roundOrNull($interestCoverage),
            'altman_z_score'     => round($altmanZ, 4),
            'z_score_zone'       => $this->zScoreZone($altmanZ),
        ];

        // ── Scores ────────────────────────────────────────────────────────────
        $qualityScore     = $this->computeQualityScore($profitability, $financialHealth);
        $fundamentalScore = $this->computeFundamentalScore(
            $valuation, $profitability, $growth, $financialHealth, $sector, $dcfValue, $marketCap / max(1, $sharesOut)
        );

        // ── Sector comparison ─────────────────────────────────────────────────
        $vsSector = $this->compareToSectorPeers($assetId, $sector);

        // ── Recommendation ────────────────────────────────────────────────────
        [$recommendation, $summary] = $this->generateRecommendation(
            $fundamentalScore, $qualityScore, $altmanZ, $dcfValue, $marketCap / max(1, $sharesOut)
        );

        $analysis = [
            'asset_id'         => $assetId,
            'analyzed_at'      => date('Y-m-d H:i:s'),
            'period'           => $fin['period'] ?? 'latest',
            'valuation'        => $valuation,
            'profitability'    => $profitability,
            'growth'           => $growth,
            'financial_health' => $financialHealth,
            'quality_score'    => round($qualityScore,     2),
            'fundamental_score'=> round($fundamentalScore, 2),
            'vs_sector'        => $vsSector,
            'recommendation'   => $recommendation,
            'summary'          => $summary,
        ];

        $this->saveFundamentalAnalysis($analysis);

        return $analysis;
    }

    /**
     * Discounted Cash Flow valuation.
     *
     * Projects FCF for 5 years at the historical growth rate (capped at 30%),
     * then applies a Gordon Growth terminal value.
     *
     * @param float $discountRate        WACC (default 10%).
     * @param float $terminalGrowthRate  Long-run FCF growth (default 2.5%).
     */
    public function calculateDCF(
        array $financials,
        float $discountRate       = 0.10,
        float $terminalGrowthRate = 0.025
    ): float {
        $fcf     = (float)($financials['free_cash_flow']    ?? 0);
        $fcfPrev = (float)($financials['free_cash_flow_prev'] ?? $fcf * 0.90); // fallback if missing

        if ($fcf <= 0) {
            return 0.0; // Cannot value negative FCF with this model
        }

        // Estimated near-term FCF growth rate (cap at 30%, floor at terminalGrowthRate)
        $historicalGrowth = $fcfPrev != 0
            ? ($fcf - $fcfPrev) / abs($fcfPrev)
            : $terminalGrowthRate;
        $growthRate = max($terminalGrowthRate, min(0.30, $historicalGrowth));

        // Project 5 years
        $pv = 0.0;
        $projectedFcf = $fcf;
        for ($t = 1; $t <= 5; $t++) {
            $projectedFcf = $projectedFcf * (1.0 + $growthRate);
            $pv += $projectedFcf / ((1.0 + $discountRate) ** $t);
        }

        // Terminal value (Gordon Growth Model applied to Year 5 FCF)
        if ($discountRate <= $terminalGrowthRate) {
            // Avoid division by zero; use a conservative estimate
            $terminalValue = $projectedFcf * 10.0;
        } else {
            $terminalValue = ($projectedFcf * (1.0 + $terminalGrowthRate))
                / ($discountRate - $terminalGrowthRate);
        }

        // PV of terminal value
        $pv += $terminalValue / ((1.0 + $discountRate) ** 5);

        // Per-share intrinsic value
        $shares = (float)($financials['shares_outstanding'] ?? 1);
        return $shares > 0 ? $pv / $shares : 0.0;
    }

    /**
     * Altman Z-Score for public companies.
     *
     * Z = 1.2*X1 + 1.4*X2 + 3.3*X3 + 0.6*X4 + 1.0*X5
     *
     *   X1 = Working Capital   / Total Assets
     *   X2 = Retained Earnings / Total Assets
     *   X3 = EBIT              / Total Assets
     *   X4 = Market Cap        / Total Liabilities
     *   X5 = Revenue           / Total Assets
     */
    public function calculateAltmanZScore(array $financials): float
    {
        $totalAssets       = (float)($financials['total_assets']        ?? 1);
        $workingCapital    = (float)($financials['working_capital']      ?? 0);
        $retainedEarnings  = (float)($financials['retained_earnings']    ?? 0);
        $ebit              = (float)($financials['ebit']                 ?? 0);
        $marketCap         = (float)($financials['market_cap']           ?? 0);
        $totalLiabilities  = (float)($financials['total_liabilities']    ?? 1);
        $revenue           = (float)($financials['revenue']              ?? 0);

        if ($totalAssets == 0) {
            return 0.0;
        }

        $x1 = $workingCapital   / $totalAssets;
        $x2 = $retainedEarnings / $totalAssets;
        $x3 = $ebit             / $totalAssets;
        $x4 = $totalLiabilities != 0 ? $marketCap / $totalLiabilities : 0.0;
        $x5 = $revenue          / $totalAssets;

        return 1.2 * $x1 + 1.4 * $x2 + 3.3 * $x3 + 0.6 * $x4 + 1.0 * $x5;
    }

    /**
     * Score a single metric relative to sector norms on a 0-100 scale.
     *
     * Higher score = better. The direction of "better" is metric-dependent:
     *  - Higher is better: margins, ROE, ROA, current_ratio, revenue_growth, etc.
     *  - Lower is better:  pe_ratio, pb_ratio, debt_to_equity, ev_ebitda, etc.
     *
     * Scoring formula (percentile-based using sector p25/p75):
     *   score = clamp( (value - p25) / (p75 - p25), 0, 1 ) * 100
     * Inverted for "lower is better" metrics.
     */
    public function scoreMetric(string $metric, float $value, string $sector): float
    {
        $sectorData = $this->fetchSectorAverages($sector, $metric);

        if ($sectorData === null) {
            // No sector data — return neutral 50
            return 50.0;
        }

        $p25 = $sectorData['p25_value'];
        $p75 = $sectorData['p75_value'];

        if ($p75 == $p25) {
            return 50.0;
        }

        // Metrics where lower value is better
        $lowerIsBetter = ['pe_ratio', 'pb_ratio', 'ps_ratio', 'ev_ebitda', 'peg_ratio',
                          'debt_to_equity', 'price_to_fcf'];

        $normalized = ($value - $p25) / ($p75 - $p25);
        $normalized = max(0.0, min(1.0, $normalized));

        $score = in_array($metric, $lowerIsBetter, true)
            ? (1.0 - $normalized) * 100.0
            : $normalized * 100.0;

        return round($score, 2);
    }

    /**
     * Compare the asset to its sector peers by pulling aggregate sector stats.
     *
     * @return array{overvalued_metrics: array, undervalued_metrics: array, peer_scores: array}
     */
    public function compareToSectorPeers(string $assetId, string $sector): array
    {
        $fin = $this->fetchFundamentals($assetId);

        $metricsToScore = [
            'pe_ratio', 'pb_ratio', 'ev_ebitda',
            'gross_margin', 'net_margin', 'roe',
            'debt_to_equity', 'current_ratio',
        ];

        // Derive metric values from the fundamentals row
        $metricValues = $this->deriveMetricValues($fin);

        $overvalued   = [];
        $undervalued  = [];
        $peerScores   = [];

        foreach ($metricsToScore as $metric) {
            if (!isset($metricValues[$metric])) {
                continue;
            }

            $score = $this->scoreMetric($metric, (float)$metricValues[$metric], $sector);
            $peerScores[$metric] = $score;

            // Overvalued: "lower is better" metric but score < 40 means the asset is expensive
            $lowerIsBetter = ['pe_ratio', 'pb_ratio', 'ev_ebitda', 'debt_to_equity'];
            if (in_array($metric, $lowerIsBetter, true) && $score < 40) {
                $overvalued[] = $metric;
            } elseif (!in_array($metric, $lowerIsBetter, true) && $score < 40) {
                $undervalued[] = $metric;
            }
        }

        return [
            'overvalued_metrics'  => $overvalued,
            'undervalued_metrics' => $undervalued,
            'peer_scores'         => $peerScores,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchFundamentals(string $assetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fundamentals
             WHERE  asset_id = :asset_id
             ORDER  BY period DESC
             LIMIT  1'
        );
        $stmt->execute([':asset_id' => $assetId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchSectorAverages(string $sector, string $metric): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT avg_value, p25_value, p75_value
             FROM   sector_averages
             WHERE  sector = :sector AND metric = :metric
             LIMIT  1'
        );
        $stmt->execute([':sector' => $sector, ':metric' => $metric]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function saveFundamentalAnalysis(array $analysis): void
    {
        $sql = <<<SQL
            INSERT INTO fundamental_analysis
                (asset_id, analyzed_at, period, fundamental_score, quality_score,
                 recommendation, valuation_json, profitability_json, growth_json,
                 financial_health_json, vs_sector_json, summary)
            VALUES
                (:asset_id, :analyzed_at, :period, :fundamental_score, :quality_score,
                 :recommendation, :valuation_json, :profitability_json, :growth_json,
                 :financial_health_json, :vs_sector_json, :summary)
            ON DUPLICATE KEY UPDATE
                analyzed_at           = VALUES(analyzed_at),
                fundamental_score     = VALUES(fundamental_score),
                quality_score         = VALUES(quality_score),
                recommendation        = VALUES(recommendation),
                valuation_json        = VALUES(valuation_json),
                profitability_json    = VALUES(profitability_json),
                growth_json           = VALUES(growth_json),
                financial_health_json = VALUES(financial_health_json),
                vs_sector_json        = VALUES(vs_sector_json),
                summary               = VALUES(summary)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':asset_id'            => $analysis['asset_id'],
            ':analyzed_at'         => $analysis['analyzed_at'],
            ':period'              => $analysis['period'],
            ':fundamental_score'   => $analysis['fundamental_score'],
            ':quality_score'       => $analysis['quality_score'],
            ':recommendation'      => $analysis['recommendation'],
            ':valuation_json'      => json_encode($analysis['valuation']),
            ':profitability_json'  => json_encode($analysis['profitability']),
            ':growth_json'         => json_encode($analysis['growth']),
            ':financial_health_json' => json_encode($analysis['financial_health']),
            ':vs_sector_json'      => json_encode($analysis['vs_sector']),
            ':summary'             => $analysis['summary'],
        ]);
    }

    /**
     * Quality score: composite of profitability (60%) and financial health (40%).
     */
    private function computeQualityScore(array $profitability, array $financialHealth): float
    {
        // Profitability sub-score (0-100)
        $grossScore  = min(100.0, max(0.0, $profitability['gross_margin']     * 1.5));
        $netScore    = min(100.0, max(0.0, ($profitability['net_margin']      + 10.0) * 3.5));
        $roeScore    = min(100.0, max(0.0, $profitability['roe']              * 2.0));
        $roicScore   = min(100.0, max(0.0, $profitability['roic']             * 2.5));
        $profScore   = ($grossScore + $netScore + $roeScore + $roicScore) / 4.0;

        // Financial health sub-score (0-100)
        $crScore = $financialHealth['current_ratio'] !== null
            ? min(100.0, (float)$financialHealth['current_ratio'] * 40.0)
            : 50.0;
        $dtScore = $financialHealth['debt_to_equity'] !== null
            ? min(100.0, max(0.0, 100.0 - (float)$financialHealth['debt_to_equity'] * 25.0))
            : 50.0;
        $zScore  = min(100.0, max(0.0, $financialHealth['altman_z_score'] * 16.67));  // 6.0 maps to 100
        $healthScore = ($crScore + $dtScore + $zScore) / 3.0;

        return $profScore * 0.60 + $healthScore * 0.40;
    }

    /**
     * Composite fundamental score (0-100).
     */
    private function computeFundamentalScore(
        array  $valuation,
        array  $profitability,
        array  $growth,
        array  $financialHealth,
        string $sector,
        float  $dcfValue,
        float  $currentPrice
    ): float {
        $weights = [
            'valuation'   => 0.30,
            'quality'     => 0.30,
            'growth'      => 0.20,
            'health'      => 0.20,
        ];

        // Valuation: DCF margin of safety
        $marginOfSafety = $currentPrice > 0 && $dcfValue > 0
            ? (($dcfValue - $currentPrice) / $dcfValue) * 100.0
            : 0.0;
        $valuationScore = min(100.0, max(0.0, 50.0 + $marginOfSafety));

        // Quality
        $qualityScore = $this->computeQualityScore($profitability, $financialHealth);

        // Growth
        $revGrowthScore  = min(100.0, max(0.0, 50.0 + $growth['revenue_growth_yoy']  * 2.0));
        $epsGrowthScore  = min(100.0, max(0.0, 50.0 + $growth['earnings_growth_yoy'] * 1.5));
        $growthScore     = ($revGrowthScore + $epsGrowthScore) / 2.0;

        // Health
        $zScore = min(100.0, max(0.0, $financialHealth['altman_z_score'] * 16.67));
        $crScore= $financialHealth['current_ratio'] !== null
            ? min(100.0, (float)$financialHealth['current_ratio'] * 40.0)
            : 50.0;
        $healthScore = ($zScore + $crScore) / 2.0;

        return $valuationScore * $weights['valuation']
            + $qualityScore   * $weights['quality']
            + $growthScore    * $weights['growth']
            + $healthScore    * $weights['health'];
    }

    /**
     * @return array{0: string, 1: string}  [recommendation, summary]
     */
    private function generateRecommendation(
        float $fundamentalScore,
        float $qualityScore,
        float $altmanZ,
        float $dcfValue,
        float $currentPrice
    ): array {
        $marginOfSafety = $currentPrice > 0 && $dcfValue > 0
            ? (($dcfValue - $currentPrice) / $dcfValue) * 100.0
            : 0.0;

        $distressRisk = $altmanZ < 1.81;

        if ($distressRisk) {
            $rec = 'SELL';
            $summary = sprintf(
                'Altman Z-Score of %.2f signals financial distress (zone: %s). '
                . 'Fundamental score %.1f/100. Avoid until balance sheet improves.',
                $altmanZ, $this->zScoreZone($altmanZ), $fundamentalScore
            );
        } elseif ($fundamentalScore >= 65 && $marginOfSafety >= 15) {
            $rec = 'BUY';
            $summary = sprintf(
                'Strong fundamentals (score: %.1f/100) with a %.1f%% DCF margin of safety. '
                . 'Quality score %.1f/100. Altman Z-Score %.2f (%s). '
                . 'Asset appears undervalued relative to intrinsic value.',
                $fundamentalScore, $marginOfSafety, $qualityScore,
                $altmanZ, $this->zScoreZone($altmanZ)
            );
        } elseif ($fundamentalScore <= 40 || $marginOfSafety < -20) {
            $rec = 'SELL';
            $summary = sprintf(
                'Weak fundamentals (score: %.1f/100) or significant overvaluation '
                . '(margin of safety: %.1f%%). Quality score %.1f/100.',
                $fundamentalScore, $marginOfSafety, $qualityScore
            );
        } else {
            $rec = 'HOLD';
            $summary = sprintf(
                'Mixed fundamentals (score: %.1f/100). Margin of safety: %.1f%%. '
                . 'Quality score: %.1f/100. Altman Z-Score: %.2f (%s). '
                . 'Monitor for changes in growth trajectory or valuation.',
                $fundamentalScore, $marginOfSafety, $qualityScore,
                $altmanZ, $this->zScoreZone($altmanZ)
            );
        }

        return [$rec, $summary];
    }

    private function zScoreZone(float $z): string
    {
        if ($z > 2.99) return 'safe';
        if ($z > 1.81) return 'grey';
        return 'distress';
    }

    /**
     * Derive a flat map of metric_name => value from a fundamentals row.
     */
    private function deriveMetricValues(array $fin): array
    {
        $revenue   = (float)($fin['revenue']          ?? 0);
        $netIncome = (float)($fin['net_income']       ?? 0);
        $grossProfit = (float)($fin['gross_profit']   ?? 0);
        $ebitda    = (float)($fin['ebitda']           ?? 0);
        $marketCap = (float)($fin['market_cap']       ?? 0);
        $ev        = (float)($fin['enterprise_value'] ?? 0);
        $eps       = (float)($fin['earnings_per_share'] ?? 0);
        $sharesOut = (float)($fin['shares_outstanding'] ?? 1);
        $bookValue = (float)($fin['book_value_per_share'] ?? 0);
        $equity    = (float)($fin['total_equity']     ?? 1);
        $assets    = (float)($fin['total_assets']     ?? 1);
        $debt      = (float)($fin['total_debt']       ?? 0);
        $curAssets = (float)($fin['current_assets']   ?? 0);
        $curLiab   = (float)($fin['current_liabilities'] ?? 1);

        return [
            'pe_ratio'       => $eps != 0 ? $marketCap / ($eps * $sharesOut) : null,
            'pb_ratio'       => $bookValue != 0 ? ($marketCap / $sharesOut) / $bookValue : null,
            'ev_ebitda'      => $ebitda != 0 ? $ev / $ebitda : null,
            'gross_margin'   => $revenue != 0 ? ($grossProfit / $revenue) * 100 : null,
            'net_margin'     => $revenue != 0 ? ($netIncome   / $revenue) * 100 : null,
            'roe'            => $equity  != 0 ? ($netIncome   / $equity)  * 100 : null,
            'debt_to_equity' => $equity  != 0 ? $debt / $equity : null,
            'current_ratio'  => $curLiab != 0 ? $curAssets / $curLiab : null,
        ];
    }

    private function roundOrNull(?float $v, int $decimals = 4): ?float
    {
        return $v !== null ? round($v, $decimals) : null;
    }
}
