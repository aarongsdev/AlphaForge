<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\SentimentAnalysis;

use PDO;
use AlphaForge\Services\Analysis\SentimentAnalysis\Sources\NewsAnalyzer;
use AlphaForge\Services\Analysis\SentimentAnalysis\Sources\SocialMediaAnalyzer;

/**
 * Composite Sentiment Analysis Engine
 *
 * Combines news and social media sentiment into a single composite score,
 * tracks momentum over time, and produces a BUY / SELL / HOLD recommendation.
 *
 * Final composite score is normalised from [-1, 1] to [0, 100] for
 * consistency with the technical and fundamental engines.
 */
class SentimentEngine
{
    private PDO                 $pdo;
    private NewsAnalyzer        $newsAnalyzer;
    private SocialMediaAnalyzer $socialAnalyzer;

    /**
     * Weight allocated to news vs social media sentiment.
     * News is typically higher quality; social has higher velocity.
     */
    private const NEWS_WEIGHT   = 0.60;
    private const SOCIAL_WEIGHT = 0.40;

    public function __construct(PDO $pdo)
    {
        $this->pdo            = $pdo;
        $this->newsAnalyzer   = new NewsAnalyzer();
        $this->socialAnalyzer = new SocialMediaAnalyzer();
    }

    /**
     * Run a full sentiment analysis for an asset.
     *
     * @return array{
     *   asset_id: string,
     *   analyzed_at: string,
     *   lookback_hours: int,
     *   news: array,
     *   social: array,
     *   composite_score: float,
     *   label: string,
     *   momentum: string,
     *   key_topics: array,
     *   recommendation: string
     * }
     */
    public function analyze(string $assetId, int $lookbackHours = 48): array
    {
        // ── Fetch raw data ─────────────────────────────────────────────────────
        $articles = $this->fetchNewsArticles($assetId, $lookbackHours);
        $posts    = $this->fetchSocialPosts($assetId,  $lookbackHours);

        // ── News analysis ──────────────────────────────────────────────────────
        $newsSentiment = $this->newsAnalyzer->aggregateForAsset($assetId, $lookbackHours, $articles);

        // ── Social analysis ────────────────────────────────────────────────────
        $socialSentiment = $this->socialAnalyzer->aggregateForAsset($assetId, $lookbackHours, $posts);

        // ── Composite score (raw: -1 to 1) ────────────────────────────────────
        $totalVolume = $newsSentiment['volume'] + $socialSentiment['volume'];
        if ($totalVolume === 0) {
            $rawComposite = 0.0;
        } else {
            // Volume-adjusted weights within the news/social split
            $newsVolumeWeight   = $newsSentiment['volume']   > 0 ? self::NEWS_WEIGHT   : 0.0;
            $socialVolumeWeight = $socialSentiment['volume'] > 0 ? self::SOCIAL_WEIGHT : 0.0;
            $sumWeights         = $newsVolumeWeight + $socialVolumeWeight;

            if ($sumWeights == 0) {
                $rawComposite = 0.0;
            } else {
                $rawComposite = (
                    $newsSentiment['score']   * $newsVolumeWeight
                    + $socialSentiment['score'] * $socialVolumeWeight
                ) / $sumWeights;
            }
        }

        // Normalise [-1, 1] → [0, 100]
        $compositeScore = ($rawComposite + 1.0) / 2.0 * 100.0;
        $compositeScore = max(0.0, min(100.0, $compositeScore));

        // ── Key topics ─────────────────────────────────────────────────────────
        $keyTopics = $this->aggregateTopics($articles);

        // ── Historical momentum ────────────────────────────────────────────────
        $historicalScores = $this->fetchHistoricalScores($assetId, 10);
        $momentum         = $this->calculateMomentum(
            array_merge($historicalScores, [$compositeScore])
        );

        // ── Label & recommendation ─────────────────────────────────────────────
        $label          = $this->scoreToLabel($compositeScore);
        $recommendation = $this->recommend($compositeScore, $momentum);

        $analysis = [
            'asset_id'       => $assetId,
            'analyzed_at'    => date('Y-m-d H:i:s'),
            'lookback_hours' => $lookbackHours,
            'news'           => [
                'score'          => $newsSentiment['score'],
                'volume'         => $newsSentiment['volume'],
                'positive_count' => $newsSentiment['positive_count'],
                'negative_count' => $newsSentiment['negative_count'],
                'neutral_count'  => $newsSentiment['neutral_count'],
                'top_articles'   => $newsSentiment['top_articles'],
            ],
            'social'         => [
                'score'              => $socialSentiment['score'],
                'volume'             => $socialSentiment['volume'],
                'platform_breakdown' => $socialSentiment['platform_breakdown'],
            ],
            'composite_score' => round($compositeScore, 2),
            'label'           => $label,
            'momentum'        => $momentum,
            'key_topics'      => $keyTopics,
            'recommendation'  => $recommendation,
        ];

        $this->saveToDatabase($analysis);

        return $analysis;
    }

    /**
     * Rule-based sentiment scorer (delegates to NewsAnalyzer for consistency).
     *
     * Returns a score in [-1, 1].
     */
    public function scoreSentiment(string $text): float
    {
        return $this->newsAnalyzer->scoreSentiment($text);
    }

    /**
     * Extract key financial topics from text (delegates to NewsAnalyzer).
     *
     * @return string[]
     */
    public function extractTopics(string $text): array
    {
        return $this->newsAnalyzer->extractTopics($text);
    }

    /**
     * Calculate sentiment momentum by comparing the most recent scores
     * to an earlier window.
     *
     * Strategy:
     *   recent  = mean of last ceil(n/2) scores
     *   older   = mean of first floor(n/2) scores
     *   delta   = recent - older
     *
     *   delta > +3  → improving
     *   delta < -3  → deteriorating
     *   otherwise   → stable
     *
     * @param float[] $historicalScores Array of normalised [0-100] scores, oldest first.
     */
    public function calculateMomentum(array $historicalScores): string
    {
        $n = count($historicalScores);
        if ($n < 2) {
            return 'stable';
        }

        $half   = (int)ceil($n / 2);
        $older  = array_slice($historicalScores, 0, (int)floor($n / 2));
        $recent = array_slice($historicalScores, -$half);

        $avgOlder  = count($older)  > 0 ? array_sum($older)  / count($older)  : 50.0;
        $avgRecent = count($recent) > 0 ? array_sum($recent) / count($recent) : 50.0;

        $delta = $avgRecent - $avgOlder;

        if ($delta > 3.0) {
            return 'improving';
        }
        if ($delta < -3.0) {
            return 'deteriorating';
        }
        return 'stable';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Fetch recent news articles from the database.
     *
     * Expected table: `news_articles`
     *   id, asset_id, title, content, source, published_at
     *
     * @return array<int, array{title: string, content: string, source: string, published_at: string}>
     */
    private function fetchNewsArticles(string $assetId, int $lookbackHours): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT title, content, source, published_at
             FROM   news_articles
             WHERE  asset_id   = :asset_id
               AND  published_at >= NOW() - INTERVAL :hours HOUR
             ORDER  BY published_at DESC
             LIMIT  200'
        );
        $stmt->bindValue(':asset_id', $assetId,      PDO::PARAM_STR);
        $stmt->bindValue(':hours',    $lookbackHours, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Fetch recent social media posts from the database.
     *
     * Expected table: `social_posts`
     *   id, asset_id, platform, text, username, created_at,
     *   likes, reposts, replies, account_age_days, sentiment
     *
     * @return array<int, array{
     *   platform: string, text: string, username: string, created_at: string,
     *   likes: int, reposts: int, replies: int, account_age_days: float, sentiment: string|null
     * }>
     */
    private function fetchSocialPosts(string $assetId, int $lookbackHours): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT platform, text, username, created_at,
                    likes, reposts, replies, account_age_days, sentiment
             FROM   social_posts
             WHERE  asset_id   = :asset_id
               AND  created_at >= NOW() - INTERVAL :hours HOUR
             ORDER  BY created_at DESC
             LIMIT  1000'
        );
        $stmt->bindValue(':asset_id', $assetId,      PDO::PARAM_STR);
        $stmt->bindValue(':hours',    $lookbackHours, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Fetch the last N composite sentiment scores for momentum calculation.
     *
     * @return float[]
     */
    private function fetchHistoricalScores(string $assetId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT composite_score
             FROM   sentiment_analysis
             WHERE  asset_id = :asset_id
             ORDER  BY analyzed_at DESC
             LIMIT  :limit'
        );
        $stmt->bindValue(':asset_id', $assetId, PDO::PARAM_STR);
        $stmt->bindValue(':limit',    $limit,   PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        // Reverse so oldest score is first
        return array_reverse(array_map('floatval', $rows));
    }

    /**
     * Aggregate topics from all articles and return sorted by frequency.
     *
     * @param array<int, array{title: string, content: string}> $articles
     * @return array<string, int>   topic => mention_count, sorted descending
     */
    private function aggregateTopics(array $articles): array
    {
        $topicCounts = [];

        foreach ($articles as $article) {
            $text   = ($article['title'] ?? '') . ' ' . ($article['content'] ?? '');
            $topics = $this->extractTopics($text);
            foreach ($topics as $topic) {
                $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
            }
        }

        arsort($topicCounts);
        return $topicCounts;
    }

    /**
     * Convert a 0-100 composite score to a text label.
     */
    private function scoreToLabel(float $score): string
    {
        if ($score >= 75) return 'very_positive';
        if ($score >= 60) return 'positive';
        if ($score <= 25) return 'very_negative';
        if ($score <= 40) return 'negative';
        return 'neutral';
    }

    /**
     * Generate a trading recommendation from composite score and momentum.
     */
    private function recommend(float $compositeScore, string $momentum): string
    {
        if ($compositeScore >= 65 && $momentum !== 'deteriorating') {
            return 'BUY';
        }
        if ($compositeScore <= 35 && $momentum !== 'improving') {
            return 'SELL';
        }
        return 'HOLD';
    }

    /**
     * Persist the analysis to the `sentiment_analysis` table.
     */
    private function saveToDatabase(array $analysis): void
    {
        $sql = <<<SQL
            INSERT INTO sentiment_analysis
                (asset_id, analyzed_at, lookback_hours, composite_score, label,
                 momentum, recommendation, news_json, social_json, key_topics_json)
            VALUES
                (:asset_id, :analyzed_at, :lookback_hours, :composite_score, :label,
                 :momentum, :recommendation, :news_json, :social_json, :key_topics_json)
            ON DUPLICATE KEY UPDATE
                analyzed_at     = VALUES(analyzed_at),
                composite_score = VALUES(composite_score),
                label           = VALUES(label),
                momentum        = VALUES(momentum),
                recommendation  = VALUES(recommendation),
                news_json       = VALUES(news_json),
                social_json     = VALUES(social_json),
                key_topics_json = VALUES(key_topics_json)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':asset_id'       => $analysis['asset_id'],
            ':analyzed_at'    => $analysis['analyzed_at'],
            ':lookback_hours' => $analysis['lookback_hours'],
            ':composite_score'=> $analysis['composite_score'],
            ':label'          => $analysis['label'],
            ':momentum'       => $analysis['momentum'],
            ':recommendation' => $analysis['recommendation'],
            ':news_json'      => json_encode($analysis['news']),
            ':social_json'    => json_encode($analysis['social']),
            ':key_topics_json'=> json_encode($analysis['key_topics']),
        ]);
    }
}
