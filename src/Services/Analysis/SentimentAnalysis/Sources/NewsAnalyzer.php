<?php

declare(strict_types=1);

namespace AlphaForge\Services\Analysis\SentimentAnalysis\Sources;

/**
 * News Sentiment Analyzer
 *
 * Analyzes financial news articles using a rule-based lexicon approach.
 * Each article score is a weighted combination of title and body sentiment,
 * adjusted for source credibility and time decay.
 */
class NewsAnalyzer
{
    /**
     * Half-life for the exponential time-decay weight (in hours).
     * At 12 hours the weight is 0.5; at 24 hours ~0.25.
     */
    private const DECAY_HALF_LIFE_HOURS = 12.0;

    /**
     * Credibility weights per source domain (default 1.0 for unknown sources).
     */
    private const SOURCE_CREDIBILITY = [
        'reuters.com'        => 1.5,
        'bloomberg.com'      => 1.5,
        'wsj.com'            => 1.4,
        'ft.com'             => 1.4,
        'cnbc.com'           => 1.2,
        'marketwatch.com'    => 1.2,
        'seekingalpha.com'   => 0.9,
        'finance.yahoo.com'  => 1.0,
        'businessinsider.com'=> 1.0,
        'thestreet.com'      => 0.9,
    ];

    /**
     * Positive financial lexicon with scores in (0, 1].
     */
    private const POSITIVE_WORDS = [
        'beat'          => 0.8,
        'beats'         => 0.8,
        'exceeded'      => 0.7,
        'record'        => 0.7,
        'growth'        => 0.6,
        'grew'          => 0.6,
        'surge'         => 0.8,
        'surged'        => 0.8,
        'rally'         => 0.7,
        'bullish'       => 0.8,
        'upgrade'       => 0.8,
        'upgraded'      => 0.8,
        'outperform'    => 0.7,
        'strong'        => 0.5,
        'profit'        => 0.5,
        'revenue'       => 0.3,  // neutral-positive
        'expansion'     => 0.6,
        'innovation'    => 0.5,
        'partnership'   => 0.5,
        'acquisition'   => 0.4,
        'dividend'      => 0.4,
        'buyback'       => 0.6,
        'raising'       => 0.5,
        'raised'        => 0.5,
        'guidance'      => 0.3,
        'optimistic'    => 0.6,
        'positive'      => 0.5,
        'recovery'      => 0.6,
        'rebound'       => 0.6,
        'momentum'      => 0.5,
        'breakthrough'  => 0.7,
    ];

    /**
     * Negative financial lexicon with scores in (0, 1].
     */
    private const NEGATIVE_WORDS = [
        'miss'          => 0.8,
        'missed'        => 0.8,
        'decline'       => 0.7,
        'declined'      => 0.7,
        'loss'          => 0.7,
        'losses'        => 0.7,
        'bearish'       => 0.8,
        'downgrade'     => 0.8,
        'downgraded'    => 0.8,
        'underperform'  => 0.7,
        'bankrupt'      => 1.0,
        'bankruptcy'    => 1.0,
        'lawsuit'       => 0.6,
        'investigation' => 0.6,
        'fraud'         => 0.9,
        'recall'        => 0.5,
        'warning'       => 0.5,
        'disappointing' => 0.7,
        'weak'          => 0.6,
        'slowdown'      => 0.6,
        'contraction'   => 0.6,
        'concern'       => 0.4,
        'risk'          => 0.3,
        'debt'          => 0.3,
        'default'       => 0.9,
        'cut'           => 0.5,
        'cuts'          => 0.5,
        'layoff'        => 0.7,
        'layoffs'       => 0.7,
        'restructuring' => 0.5,
        'penalty'       => 0.6,
        'fine'          => 0.5,
        'below'         => 0.4,
    ];

    /**
     * Negation words that flip the sentiment of the following word.
     */
    private const NEGATION_WORDS = ['not', 'no', 'never', 'neither', 'without', "n't", 'failed', 'unable'];

    /**
     * Analyze a single article and return its sentiment score and key topics.
     *
     * @param array{
     *   title: string,
     *   content: string,
     *   source: string,
     *   published_at: string
     * } $article
     * @return array{
     *   score: float,
     *   label: string,
     *   title_score: float,
     *   content_score: float,
     *   credibility_weight: float,
     *   topics: array<string>
     * }
     */
    public function analyzeArticle(array $article): array
    {
        $title   = $article['title']   ?? '';
        $content = $article['content'] ?? '';
        $source  = $article['source']  ?? '';

        $titleScore   = $this->scoreSentiment($title);
        $contentScore = $this->scoreSentiment($content);

        // Weighted combination: title 40%, content 60%
        $rawScore = $titleScore * 0.4 + $contentScore * 0.6;

        // Source credibility multiplier (does not flip sign, only amplifies/dampens)
        $credibility = $this->credibilityWeight($source);

        $finalScore = max(-1.0, min(1.0, $rawScore * $credibility));

        $topics = $this->extractTopics($title . ' ' . $content);

        return [
            'score'             => round($finalScore, 4),
            'label'             => $this->scoreToLabel($finalScore),
            'title_score'       => round($titleScore,   4),
            'content_score'     => round($contentScore, 4),
            'credibility_weight'=> round($credibility,  4),
            'topics'            => $topics,
        ];
    }

    /**
     * Aggregate sentiment for all recent news articles about an asset.
     *
     * @param array<int, array{
     *   title: string,
     *   content: string,
     *   source: string,
     *   published_at: string
     * }> $articles
     * @param int $lookbackHours Number of hours to look back (for decay calculation).
     * @return array{
     *   score: float,
     *   volume: int,
     *   positive_count: int,
     *   negative_count: int,
     *   neutral_count: int,
     *   top_articles: array,
     *   weighted_scores: array<float>
     * }
     */
    public function aggregateForAsset(string $assetId, int $lookbackHours, array $articles = []): array
    {
        if (empty($articles)) {
            return [
                'score'          => 0.0,
                'volume'         => 0,
                'positive_count' => 0,
                'negative_count' => 0,
                'neutral_count'  => 0,
                'top_articles'   => [],
                'weighted_scores'=> [],
            ];
        }

        $now = time();
        $weightedSum  = 0.0;
        $totalWeight  = 0.0;
        $positiveCount = 0;
        $negativeCount = 0;
        $neutralCount  = 0;
        $scoredArticles = [];

        foreach ($articles as $article) {
            $result = $this->analyzeArticle($article);

            // Time-decay weight: w = 0.5^(age_in_hours / half_life)
            $publishedAt = strtotime($article['published_at'] ?? 'now');
            $ageHours    = ($now - $publishedAt) / 3600.0;
            $decayWeight = pow(0.5, $ageHours / self::DECAY_HALF_LIFE_HOURS);
            $credWeight  = $result['credibility_weight'];

            $compositeWeight = $decayWeight * $credWeight;

            $weightedSum += $result['score'] * $compositeWeight;
            $totalWeight += $compositeWeight;

            if ($result['score'] > 0.1) {
                $positiveCount++;
            } elseif ($result['score'] < -0.1) {
                $negativeCount++;
            } else {
                $neutralCount++;
            }

            $scoredArticles[] = [
                'title'          => $article['title'] ?? '',
                'source'         => $article['source'] ?? '',
                'published_at'   => $article['published_at'] ?? '',
                'score'          => $result['score'],
                'topics'         => $result['topics'],
                'decay_weight'   => round($decayWeight, 4),
                'final_weight'   => round($compositeWeight, 4),
            ];
        }

        $aggregateScore = $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;

        // Sort by |score| descending to surface the most impactful articles
        usort($scoredArticles, fn($a, $b) => abs($b['score']) <=> abs($a['score']));
        $topArticles = array_slice($scoredArticles, 0, 5);

        return [
            'score'          => round($aggregateScore, 4),
            'volume'         => count($articles),
            'positive_count' => $positiveCount,
            'negative_count' => $negativeCount,
            'neutral_count'  => $neutralCount,
            'top_articles'   => $topArticles,
            'weighted_scores'=> array_column($scoredArticles, 'score'),
        ];
    }

    /**
     * Rule-based sentiment scoring using the financial lexicon.
     *
     * Returns a score in [-1, 1].
     * Handles simple negation (e.g., "not beat" becomes negative).
     */
    public function scoreSentiment(string $text): float
    {
        if (trim($text) === '') {
            return 0.0;
        }

        $text   = strtolower($text);
        $tokens = preg_split('/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $posSum = 0.0;
        $negSum = 0.0;

        for ($i = 0; $i < count($tokens); $i++) {
            $word    = $tokens[$i];
            $negated = false;

            // Check for negation in the 3 words preceding this token
            for ($j = max(0, $i - 3); $j < $i; $j++) {
                if (in_array($tokens[$j], self::NEGATION_WORDS, true)) {
                    $negated = true;
                    break;
                }
            }

            if (isset(self::POSITIVE_WORDS[$word])) {
                $weight = self::POSITIVE_WORDS[$word];
                if ($negated) {
                    $negSum += $weight * 0.8; // Negated positive → partial negative
                } else {
                    $posSum += $weight;
                }
            } elseif (isset(self::NEGATIVE_WORDS[$word])) {
                $weight = self::NEGATIVE_WORDS[$word];
                if ($negated) {
                    $posSum += $weight * 0.8; // Negated negative → partial positive
                } else {
                    $negSum += $weight;
                }
            }
        }

        $total = $posSum + $negSum;
        if ($total == 0.0) {
            return 0.0;
        }

        // Normalised score: ranges from -1 (all negative) to +1 (all positive)
        return ($posSum - $negSum) / $total;
    }

    /**
     * Extract key financial topics from text using keyword matching.
     *
     * @return string[]
     */
    public function extractTopics(string $text): array
    {
        $text   = strtolower($text);
        $topics = [];

        $topicMap = [
            'earnings'     => ['earnings', 'eps', 'profit', 'quarterly results', 'annual results'],
            'revenue'      => ['revenue', 'sales', 'top line'],
            'guidance'     => ['guidance', 'outlook', 'forecast', 'expects'],
            'acquisition'  => ['acquisition', 'merger', 'takeover', 'acquired', 'buyout'],
            'dividend'     => ['dividend', 'payout', 'yield'],
            'debt'         => ['debt', 'bond', 'credit', 'refinanc', 'default'],
            'analyst'      => ['analyst', 'rating', 'price target', 'upgrade', 'downgrade'],
            'regulatory'   => ['sec', 'regulation', 'regulatory', 'compliance', 'investigation', 'lawsuit'],
            'management'   => ['ceo', 'cfo', 'executive', 'resignation', 'appointed'],
            'product'      => ['product', 'launch', 'release', 'innovation'],
            'macro'        => ['fed', 'interest rate', 'inflation', 'recession', 'gdp'],
            'insider'      => ['insider', 'short', 'short interest'],
            'buyback'      => ['buyback', 'repurchase', 'share repurchase'],
            'ipo'          => ['ipo', 'initial public offering', 'direct listing'],
        ];

        foreach ($topicMap as $topic => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        return array_unique($topics);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function credibilityWeight(string $source): float
    {
        $source = strtolower($source);
        foreach (self::SOURCE_CREDIBILITY as $domain => $weight) {
            if (str_contains($source, $domain)) {
                return $weight;
            }
        }
        return 1.0;
    }

    private function scoreToLabel(float $score): string
    {
        if ($score >  0.5) return 'very_positive';
        if ($score >  0.1) return 'positive';
        if ($score < -0.5) return 'very_negative';
        if ($score < -0.1) return 'negative';
        return 'neutral';
    }
}
