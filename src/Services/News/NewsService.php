<?php

declare(strict_types=1);

namespace AlphaForge\Services\News;

use AlphaForge\Core\Config;
use AlphaForge\Core\Database;
use AlphaForge\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Ramsey\Uuid\Uuid;

/**
 * News ingestion and sentiment service.
 *
 * Fetches articles from the NewsAPI REST API, performs keyword-based sentiment
 * scoring, persists unique articles to news_items and links them to known assets
 * via news_asset_relations. Provides query methods for per-asset and market-wide
 * news retrieval with optional full-text filtering.
 */
class NewsService
{
    private Client   $client;
    private Database $db;
    private Logger   $logger;
    private string   $newsApiKey;
    private string   $newsApiUrl = 'https://newsapi.org/v2';

    /** @var array<string, float> Words with a +1 positive weight. */
    private const POSITIVE_WORDS = [
        'profit'   => 1.0,
        'growth'   => 1.0,
        'surge'    => 1.0,
        'rally'    => 1.0,
        'beat'     => 1.0,
        'record'   => 1.0,
        'strong'   => 1.0,
        'bullish'  => 1.0,
        'buy'      => 1.0,
        'upgrade'  => 1.0,
        'soar'     => 1.0,
        'gain'     => 1.0,
        'rise'     => 1.0,
        'outperform' => 1.0,
        'exceed'   => 1.0,
    ];

    /** @var array<string, float> Words with a -1 negative weight. */
    private const NEGATIVE_WORDS = [
        'loss'     => -1.0,
        'decline'  => -1.0,
        'fall'     => -1.0,
        'crash'    => -1.0,
        'miss'     => -1.0,
        'weak'     => -1.0,
        'sell'     => -1.0,
        'bearish'  => -1.0,
        'debt'     => -1.0,
        'risk'     => -1.0,
        'cut'      => -1.0,
        'layoff'   => -1.0,
        'lawsuit'  => -1.0,
        'downgrade'=> -1.0,
        'concern'  => -1.0,
    ];

    public function __construct()
    {
        $config           = Config::getInstance();
        $this->db         = Database::getInstance();
        $this->logger     = Logger::getInstance();
        $this->newsApiKey = (string) ($config->get('app.api_keys.newsapi') ?? '');

        $this->client = new Client([
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
            'headers'         => [
                'Accept'       => 'application/json',
                'User-Agent'   => 'AlphaForge/1.0',
                'X-Api-Key'    => $this->newsApiKey,
            ],
        ]);
    }

    /**
     * Fetch the latest news articles from NewsAPI.
     *
     * When $symbol is provided, searches for articles mentioning the symbol in
     * a financial context. Otherwise returns top business headlines.
     *
     * @param string $symbol Optional ticker symbol to narrow the search
     * @param int    $limit  Maximum number of articles to return (max 100)
     * @return list<array<string, mixed>> Raw article objects from NewsAPI
     * @throws \RuntimeException On HTTP or API error
     */
    public function fetchLatestNews(string $symbol = '', int $limit = 50): array
    {
        $limit = min($limit, 100);

        try {
            if ($symbol !== '') {
                $symbol  = strtoupper(trim($symbol));
                $query   = "{$symbol} AND (stock OR market OR shares OR earnings OR revenue)";
                $url     = $this->newsApiUrl . '/everything';
                $params  = [
                    'q'         => $query,
                    'language'  => 'en',
                    'sortBy'    => 'publishedAt',
                    'pageSize'  => $limit,
                ];
            } else {
                $url    = $this->newsApiUrl . '/top-headlines';
                $params = [
                    'category' => 'business',
                    'language' => 'en',
                    'pageSize' => $limit,
                ];
            }

            $response = $this->client->get($url, ['query' => $params]);
            $body     = (string) $response->getBody();
            $decoded  = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException('NewsAPI returned a non-array JSON response.');
            }

            if (($decoded['status'] ?? '') !== 'ok') {
                throw new \RuntimeException(
                    'NewsAPI error: ' . ($decoded['message'] ?? 'unknown error')
                );
            }

            $articles = (array) ($decoded['articles'] ?? []);

            // Sort descending by publishedAt.
            usort($articles, static function (mixed $a, mixed $b): int {
                $ta = strtotime((string) ($a['publishedAt'] ?? '')) ?: 0;
                $tb = strtotime((string) ($b['publishedAt'] ?? '')) ?: 0;
                return $tb <=> $ta;
            });

            return array_slice($articles, 0, $limit);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;

            $this->logger->error('NewsAPI HTTP request failed', [
                'symbol' => $symbol,
                'status' => $status,
                'error'  => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'NewsAPI request failed: ' . $e->getMessage(),
                $status,
                $e,
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('NewsAPI returned invalid JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Analyse, score, and persist a batch of raw article arrays.
     *
     * Skips articles whose URL is already stored in news_items. For each new
     * article: computes a keyword-based sentiment score, extracts entity
     * mentions (capitalised 1-5-letter tokens that look like tickers), and
     * inserts a row into news_items. When the article's title or description
     * contains the source symbol, a row is also inserted into news_asset_relations.
     *
     * @param list<array<string, mixed>> $articles Raw article objects (from NewsAPI or provider)
     * @return int Number of articles stored
     */
    public function analyzeAndStore(array $articles): int
    {
        $stored = 0;

        foreach ($articles as $article) {
            if (!is_array($article)) {
                continue;
            }

            $url = trim((string) ($article['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            // Skip duplicates.
            try {
                $existing = $this->db->fetchOne(
                    'SELECT id FROM alphaforge.news_items WHERE url = $1 LIMIT 1',
                    [$url],
                );

                if ($existing !== null) {
                    continue;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('analyzeAndStore: duplicate check failed', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $title       = (string) ($article['title']       ?? '');
            $summary     = (string) ($article['description'] ?? '');
            $publishedAt = (string) ($article['publishedAt'] ?? '');
            $source      = (string) ($article['source']['name'] ?? $article['source'] ?? '');
            $rawData     = $article;

            // Sentiment analysis on combined title + summary text.
            $fullText       = $title . ' ' . $summary;
            $sentimentScore = $this->calculateSentiment($fullText);
            $sentimentLabel = match (true) {
                $sentimentScore >  0.1 => 'positive',
                $sentimentScore < -0.1 => 'negative',
                default                => 'neutral',
            };

            // Extract ticker-like tokens (1-5 uppercase letters, word boundaries).
            preg_match_all('/\b([A-Z]{1,5})\b/', $fullText, $matches);
            $entities = array_values(array_unique($matches[1] ?? []));

            // Build topics list from categories / sections if available.
            $topics = [];
            if (isset($article['category'])) {
                $topics[] = (string) $article['category'];
            }

            $newsId = Uuid::uuid4()->toString();

            try {
                $this->db->query(
                    'INSERT INTO alphaforge.news_items
                        (id, source, title, url, published_at, summary, sentiment_score,
                         sentiment_label, entities, topics, raw_data)
                     VALUES ($1, $2, $3, $4, $5::timestamptz, $6, $7, $8, $9::jsonb, $10::jsonb, $11::jsonb)',
                    [
                        $newsId,
                        $source,
                        $title,
                        $url,
                        $publishedAt,
                        $summary,
                        $sentimentScore,
                        $sentimentLabel,
                        json_encode($entities, JSON_THROW_ON_ERROR),
                        json_encode($topics,   JSON_THROW_ON_ERROR),
                        json_encode($rawData,  JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ],
                );

                $stored++;
            } catch (\Throwable $e) {
                $this->logger->warning('analyzeAndStore: insert failed', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // Link to the asset when a related symbol is embedded in the article.
            $relatedSymbol = strtoupper(trim((string) ($article['symbol'] ?? '')));

            if ($relatedSymbol !== '') {
                try {
                    $asset = $this->db->fetchOne(
                        'SELECT id FROM alphaforge.assets WHERE symbol = $1 AND is_active = TRUE LIMIT 1',
                        [$relatedSymbol],
                    );

                    if ($asset !== null) {
                        // Relevance score: higher score for title mentions vs. body-only mentions.
                        $titleMentions = substr_count(strtoupper($title), $relatedSymbol);
                        $bodyMentions  = substr_count(strtoupper($summary), $relatedSymbol);
                        $relevance     = min(1.0, ($titleMentions * 0.4 + $bodyMentions * 0.1));

                        $this->db->query(
                            'INSERT INTO alphaforge.news_asset_relations
                                (news_id, asset_id, relevance_score)
                             VALUES ($1, $2, $3)
                             ON CONFLICT (news_id, asset_id) DO UPDATE
                                SET relevance_score = EXCLUDED.relevance_score',
                            [
                                $newsId,
                                (string) $asset['id'],
                                $relevance,
                            ],
                        );
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('analyzeAndStore: asset relation insert failed', [
                        'symbol' => $relatedSymbol,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info('analyzeAndStore complete', [
            'input'  => count($articles),
            'stored' => $stored,
        ]);

        return $stored;
    }

    /**
     * Return recent news articles linked to a specific asset.
     *
     * @param string $assetId UUID of the asset
     * @param int    $hours   Lookback window in hours (default 24)
     * @return list<array{news_id:string,asset_id:string,relevance_score:float,title:string,url:string,source:string,published_at:string,sentiment_score:float,sentiment_label:string}>
     */
    public function getNewsForAsset(string $assetId, int $hours = 24): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT
                     nar.news_id,
                     nar.asset_id,
                     nar.relevance_score,
                     ni.title,
                     ni.url,
                     ni.source,
                     ni.published_at,
                     ni.sentiment_score,
                     ni.sentiment_label
                 FROM alphaforge.news_asset_relations nar
                 JOIN alphaforge.news_items ni ON ni.id = nar.news_id
                 WHERE nar.asset_id = \$1
                   AND ni.published_at > NOW() - INTERVAL '{$hours} hours'
                 ORDER BY ni.published_at DESC",
                [$assetId],
            );
        } catch (\Throwable $e) {
            $this->logger->error('getNewsForAsset failed', [
                'asset_id' => $assetId,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Return recent market-wide news from the last 24 hours.
     *
     * @param int $limit Maximum number of articles (default 100)
     * @return list<array<string, mixed>>
     */
    public function getMarketNews(int $limit = 100): array
    {
        try {
            return $this->db->fetchAll(
                'SELECT id, source, title, url, published_at, summary,
                        sentiment_score, sentiment_label, entities, topics
                 FROM alphaforge.news_items
                 WHERE published_at > NOW() - INTERVAL \'24 hours\'
                 ORDER BY published_at DESC
                 LIMIT $1',
                [$limit],
            );
        } catch (\Throwable $e) {
            $this->logger->error('getMarketNews failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search news articles by a text query, with optional filters.
     *
     * Supported $filters keys:
     *   - sentiment_label (string): 'positive' | 'negative' | 'neutral'
     *   - date_from       (string): ISO 8601 or Y-m-d lower bound
     *   - date_to         (string): ISO 8601 or Y-m-d upper bound
     *   - source          (string): Exact source name
     *
     * @param string               $query   Substring to match in title or summary (ILIKE)
     * @param array<string, mixed> $filters Optional filter map
     * @return list<array<string, mixed>>
     */
    public function searchNews(string $query, array $filters = []): array
    {
        $likeQuery   = '%' . $query . '%';
        $params      = [$likeQuery];
        $paramIndex  = 2;
        $whereClauses = ['(title ILIKE $1 OR summary ILIKE $1)'];

        if (isset($filters['sentiment_label']) && $filters['sentiment_label'] !== '') {
            $whereClauses[] = "\$\${$paramIndex}";
            // Build the clause using positional placeholder.
            $placeholder    = '$' . $paramIndex;
            // Rebuild last element properly:
            array_pop($whereClauses);
            $whereClauses[] = "sentiment_label = {$placeholder}";
            $params[]       = (string) $filters['sentiment_label'];
            $paramIndex++;
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $placeholder    = '$' . $paramIndex;
            $whereClauses[] = "published_at >= {$placeholder}::timestamptz";
            $params[]       = (string) $filters['date_from'];
            $paramIndex++;
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $placeholder    = '$' . $paramIndex;
            $whereClauses[] = "published_at <= {$placeholder}::timestamptz";
            $params[]       = (string) $filters['date_to'];
            $paramIndex++;
        }

        if (isset($filters['source']) && $filters['source'] !== '') {
            $placeholder    = '$' . $paramIndex;
            $whereClauses[] = "source = {$placeholder}";
            $params[]       = (string) $filters['source'];
        }

        $where = implode(' AND ', $whereClauses);

        $sql = "SELECT id, source, title, url, published_at, summary,
                       sentiment_score, sentiment_label, entities, topics
                FROM alphaforge.news_items
                WHERE {$where}
                ORDER BY published_at DESC
                LIMIT 200";

        try {
            return $this->db->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->error('searchNews failed', [
                'query'  => $query,
                'error'  => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Compute a keyword-based sentiment score for the given text.
     *
     * Each positive/negative keyword found in the lower-cased text contributes
     * its weight to a running total. The final score is divided by the number
     * of matched keywords and clamped to [-1.0, 1.0]. Returns 0.0 when no
     * sentiment keywords are found.
     *
     * @param string $text Plaintext article content (title + summary)
     * @return float Score in [-1.0, 1.0]
     */
    private function calculateSentiment(string $text): float
    {
        if ($text === '') {
            return 0.0;
        }

        $lower   = strtolower($text);
        $total   = 0.0;
        $matched = 0;

        foreach (self::POSITIVE_WORDS as $word => $weight) {
            // Use word-boundary matching to avoid partial matches (e.g. "growth" in "regrowth").
            $occurrences = preg_match_all('/\b' . preg_quote($word, '/') . '\b/', $lower);

            if ($occurrences !== false && $occurrences > 0) {
                $total   += $weight * $occurrences;
                $matched += $occurrences;
            }
        }

        foreach (self::NEGATIVE_WORDS as $word => $weight) {
            $occurrences = preg_match_all('/\b' . preg_quote($word, '/') . '\b/', $lower);

            if ($occurrences !== false && $occurrences > 0) {
                $total   += $weight * $occurrences;
                $matched += $occurrences;
            }
        }

        if ($matched === 0) {
            return 0.0;
        }

        $score = $total / $matched;

        // Clamp to [-1.0, 1.0].
        return max(-1.0, min(1.0, $score));
    }
}
