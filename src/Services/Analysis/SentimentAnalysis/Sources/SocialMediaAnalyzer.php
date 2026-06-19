<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\SentimentAnalysis\Sources;

/**
 * Social Media Sentiment Analyzer
 *
 * Aggregates and scores social media posts from StockTwits, Reddit, and Twitter/X.
 *
 * Platform weights:
 *   StockTwits : 0.40  (financially focused, generally higher signal)
 *   Reddit      : 0.35  (community boards like r/wallstreetbets have real market impact)
 *   Twitter/X   : 0.25  (high noise-to-signal ratio)
 *
 * Engagement weighting:
 *   Each post weight = log(1 + engagement) / sum_of_weights
 *   (Logarithmic to dampen the influence of viral outliers)
 *
 * Bot-detection heuristics:
 *   - Post created_at within 0–30 seconds of account creation
 *   - Engagement = 0 and post text < 10 characters
 *   - Username matches bot-like patterns (e.g., all digits, or "bot" substring)
 */
class SocialMediaAnalyzer
{
    /**
     * Platform weights (must sum to 1.0).
     */
    private const PLATFORM_WEIGHTS = [
        'stocktwits' => 0.40,
        'reddit'     => 0.35,
        'twitter'    => 0.25,
    ];

    /**
     * Reuse the financial lexicon from NewsAnalyzer.
     */
    private NewsAnalyzer $newsAnalyzer;

    public function __construct()
    {
        $this->newsAnalyzer = new NewsAnalyzer();
    }

    /**
     * Aggregate social media sentiment for an asset over the lookback window.
     *
     * $posts is expected to be an array of post records, each with:
     *   platform    : 'stocktwits' | 'reddit' | 'twitter'
     *   text        : string
     *   username    : string
     *   created_at  : datetime string
     *   likes       : int    (or 0)
     *   reposts     : int    (or 0)
     *   replies     : int    (or 0)
     *   account_age_days : float  (days since account creation)
     *   sentiment   : 'bullish' | 'bearish' | null  (StockTwits provides this natively)
     *
     * @param array<int, array{
     *   platform: string,
     *   text: string,
     *   username: string,
     *   created_at: string,
     *   likes: int,
     *   reposts: int,
     *   replies: int,
     *   account_age_days: float,
     *   sentiment: string|null
     * }> $posts
     * @return array{
     *   score: float,
     *   volume: int,
     *   platform_breakdown: array,
     *   bull_bear_ratio: float,
     *   bull_count: int,
     *   bear_count: int,
     *   filtered_bot_count: int,
     *   top_posts: array
     * }
     */
    public function aggregateForAsset(string $assetId, int $lookbackHours, array $posts = []): array
    {
        if (empty($posts)) {
            return $this->emptyResult();
        }

        // Separate and score posts per platform, filter bots
        $byPlatform      = ['stocktwits' => [], 'reddit' => [], 'twitter' => []];
        $filteredBotCount = 0;

        foreach ($posts as $post) {
            if ($this->isBot($post)) {
                $filteredBotCount++;
                continue;
            }

            $platform = strtolower($post['platform'] ?? 'twitter');
            if (!isset($byPlatform[$platform])) {
                $platform = 'twitter';
            }

            $engagement   = $this->engagementScore($post);
            $textScore    = $this->newsAnalyzer->scoreSentiment($post['text'] ?? '');
            // StockTwits provides explicit bull/bear — use it as a strong signal
            $nativeScore  = $this->nativeSentimentScore($post['sentiment'] ?? null);
            $combinedScore = ($nativeScore !== null)
                ? $nativeScore * 0.6 + $textScore * 0.4
                : $textScore;

            $byPlatform[$platform][] = [
                'score'      => $combinedScore,
                'engagement' => $engagement,
                'text'       => $post['text'] ?? '',
                'username'   => $post['username'] ?? '',
                'created_at' => $post['created_at'] ?? '',
                'bull_bear'  => $this->bullBearLabel($combinedScore),
            ];
        }

        // Compute per-platform weighted scores
        $platformBreakdown = [];
        $platformScores    = [];
        $totalBulls        = 0;
        $totalBears        = 0;
        $allScoredPosts    = [];

        foreach ($byPlatform as $platform => $platformPosts) {
            if (empty($platformPosts)) {
                $platformBreakdown[$platform] = [
                    'score'          => 0.0,
                    'volume'         => 0,
                    'bull_count'     => 0,
                    'bear_count'     => 0,
                    'neutral_count'  => 0,
                ];
                continue;
            }

            [$platformScore, $bulls, $bears, $neutrals] = $this->weightedPlatformScore($platformPosts);

            $platformBreakdown[$platform] = [
                'score'         => round($platformScore, 4),
                'volume'        => count($platformPosts),
                'bull_count'    => $bulls,
                'bear_count'    => $bears,
                'neutral_count' => $neutrals,
            ];
            $platformScores[$platform] = $platformScore;
            $totalBulls += $bulls;
            $totalBears += $bears;

            foreach ($platformPosts as $p) {
                $allScoredPosts[] = $p;
            }
        }

        // Final score: weighted average across platforms
        $finalScore = 0.0;
        foreach (self::PLATFORM_WEIGHTS as $platform => $weight) {
            $finalScore += ($platformScores[$platform] ?? 0.0) * $weight;
        }

        // Bull/bear ratio
        $bullBearRatio = $totalBears > 0 ? $totalBulls / $totalBears : (float)$totalBulls;

        // Top posts by |score| × log(1 + engagement)
        usort($allScoredPosts, fn($a, $b) =>
            (abs($b['score']) * log(1 + $b['engagement'])) <=>
            (abs($a['score']) * log(1 + $a['engagement']))
        );

        return [
            'score'              => round($finalScore, 4),
            'volume'             => count($posts) - $filteredBotCount,
            'platform_breakdown' => $platformBreakdown,
            'bull_bear_ratio'    => round($bullBearRatio, 4),
            'bull_count'         => $totalBulls,
            'bear_count'         => $totalBears,
            'filtered_bot_count' => $filteredBotCount,
            'top_posts'          => array_slice($allScoredPosts, 0, 5),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect bot-like posts using heuristic rules.
     */
    private function isBot(array $post): bool
    {
        $username      = strtolower($post['username'] ?? '');
        $text          = $post['text'] ?? '';
        $accountAgeDays= (float)($post['account_age_days'] ?? 9999);
        $engagement    = ($post['likes'] ?? 0) + ($post['reposts'] ?? 0) + ($post['replies'] ?? 0);

        // Rule 1: "bot" in username or purely numeric username
        if (str_contains($username, 'bot') || ctype_digit(str_replace(['_', '-', '.'], '', $username))) {
            return true;
        }

        // Rule 2: Very new account (< 1 day) with zero engagement
        if ($accountAgeDays < 1.0 && $engagement === 0) {
            return true;
        }

        // Rule 3: Extremely short text with zero engagement (spam / filler)
        if (strlen(trim($text)) < 5 && $engagement === 0) {
            return true;
        }

        // Rule 4: Text is a repeated character pattern (e.g., "aaaaaa")
        if (preg_match('/^(.)\1{4,}$/', trim($text))) {
            return true;
        }

        return false;
    }

    /**
     * Compute a logarithmic engagement score from likes, reposts, and replies.
     *
     * Weights: reposts 1.5x, replies 1.0x, likes 0.5x (reposts have most signal).
     */
    private function engagementScore(array $post): float
    {
        $likes   = (int)($post['likes']   ?? 0);
        $reposts = (int)($post['reposts'] ?? 0);
        $replies = (int)($post['replies'] ?? 0);

        $raw = $likes * 0.5 + $reposts * 1.5 + $replies * 1.0;
        return log(1.0 + $raw);
    }

    /**
     * Convert native StockTwits bull/bear label to a [-1, 1] score.
     */
    private function nativeSentimentScore(?string $sentiment): ?float
    {
        if ($sentiment === null) {
            return null;
        }
        return match(strtolower($sentiment)) {
            'bullish' =>  0.75,
            'bearish' => -0.75,
            default   => null,
        };
    }

    /**
     * Compute a weighted average score across posts on one platform.
     *
     * Weight = log(1 + engagement); fall back to uniform weight 1.0 when engagement = 0.
     *
     * @return array{float, int, int, int}  [score, bulls, bears, neutrals]
     */
    private function weightedPlatformScore(array $posts): array
    {
        $weightedSum = 0.0;
        $totalWeight = 0.0;
        $bulls = 0;
        $bears = 0;
        $neutrals = 0;

        foreach ($posts as $p) {
            $weight = max(1.0, $p['engagement']); // floor at 1 to avoid zero weight
            $weightedSum += $p['score'] * $weight;
            $totalWeight += $weight;

            match ($p['bull_bear']) {
                'bullish' => $bulls++,
                'bearish' => $bears++,
                default   => $neutrals++,
            };
        }

        $score = $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
        return [$score, $bulls, $bears, $neutrals];
    }

    /**
     * Map a [-1, 1] sentiment score to a bull/bear/neutral label.
     */
    private function bullBearLabel(float $score): string
    {
        if ($score > 0.15) return 'bullish';
        if ($score < -0.15) return 'bearish';
        return 'neutral';
    }

    private function emptyResult(): array
    {
        return [
            'score'              => 0.0,
            'volume'             => 0,
            'platform_breakdown' => [
                'stocktwits' => ['score' => 0.0, 'volume' => 0, 'bull_count' => 0, 'bear_count' => 0, 'neutral_count' => 0],
                'reddit'     => ['score' => 0.0, 'volume' => 0, 'bull_count' => 0, 'bear_count' => 0, 'neutral_count' => 0],
                'twitter'    => ['score' => 0.0, 'volume' => 0, 'bull_count' => 0, 'bear_count' => 0, 'neutral_count' => 0],
            ],
            'bull_bear_ratio'    => 1.0,
            'bull_count'         => 0,
            'bear_count'         => 0,
            'filtered_bot_count' => 0,
            'top_posts'          => [],
        ];
    }
}
