-- ============================================================
-- Migration: 006_create_sentiment_tables.sql
-- Description: News ingestion, social sentiment, aggregates,
--              and fear & greed index
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: sentiment_label
-- ------------------------------------------------------------
CREATE TYPE sentiment_label AS ENUM (
    'very_negative',
    'negative',
    'neutral',
    'positive',
    'very_positive'
);

-- ------------------------------------------------------------
-- Enum: social_platform
-- ------------------------------------------------------------
CREATE TYPE social_platform AS ENUM (
    'twitter',
    'reddit',
    'stocktwits',
    'telegram',
    'discord'
);

-- ------------------------------------------------------------
-- Enum: sentiment_period_type
-- ------------------------------------------------------------
CREATE TYPE sentiment_period_type AS ENUM ('hourly', 'daily', 'weekly');

-- ------------------------------------------------------------
-- Table: news_items
-- Ingested news articles with NLP sentiment scoring
-- ------------------------------------------------------------
CREATE TABLE news_items (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    title           TEXT NOT NULL,
    content         TEXT,
    summary         TEXT,
    source_name     VARCHAR(200),
    source_url      TEXT,
    url             TEXT NOT NULL,
    author          VARCHAR(200),
    published_at    TIMESTAMPTZ NOT NULL,
    language        VARCHAR(10) NOT NULL DEFAULT 'en',
    sentiment_score NUMERIC(4, 3),                  -- -1.000 to 1.000
    sentiment_label sentiment_label,
    relevance_score NUMERIC(5, 4),                  -- 0.0000 to 1.0000
    entities        JSONB NOT NULL DEFAULT '{}',    -- extracted named entities
    topics          JSONB NOT NULL DEFAULT '[]',    -- classified topics array
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ni_url_unique            UNIQUE (url),
    CONSTRAINT ni_sentiment_range       CHECK (
        sentiment_score IS NULL OR
        (sentiment_score >= -1.0 AND sentiment_score <= 1.0)
    ),
    CONSTRAINT ni_relevance_range       CHECK (
        relevance_score IS NULL OR
        (relevance_score >= 0.0 AND relevance_score <= 1.0)
    ),
    CONSTRAINT ni_title_not_empty       CHECK (char_length(TRIM(title)) > 0)
);

COMMENT ON TABLE  news_items                IS 'Ingested and NLP-processed news articles from all sources';
COMMENT ON COLUMN news_items.sentiment_score IS 'Compound sentiment: -1.0 (very negative) to +1.0 (very positive)';
COMMENT ON COLUMN news_items.entities       IS 'JSON map of entity type to array of names, e.g. {"ORG": ["Apple"], "PERSON": ["Cook"]}';
COMMENT ON COLUMN news_items.topics         IS 'JSON array of classified topic tags, e.g. ["earnings", "M&A"]';

CREATE INDEX idx_ni_published_at        ON news_items (published_at DESC);
CREATE INDEX idx_ni_sentiment_score     ON news_items (sentiment_score);
CREATE INDEX idx_ni_sentiment_label     ON news_items (sentiment_label);
CREATE INDEX idx_ni_source_name         ON news_items (source_name);
CREATE INDEX idx_ni_language            ON news_items (language);
CREATE INDEX idx_ni_entities            ON news_items USING GIN (entities);
CREATE INDEX idx_ni_topics              ON news_items USING GIN (topics);
CREATE INDEX idx_ni_title_trgm          ON news_items USING GIN (title gin_trgm_ops);

-- ------------------------------------------------------------
-- Table: news_asset_relations
-- Many-to-many: news articles linked to relevant assets
-- ------------------------------------------------------------
CREATE TABLE news_asset_relations (
    news_id         UUID NOT NULL REFERENCES news_items (id) ON DELETE CASCADE,
    asset_id        UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    relevance_score NUMERIC(5, 4),

    CONSTRAINT nar_pk PRIMARY KEY (news_id, asset_id),
    CONSTRAINT nar_relevance_range CHECK (
        relevance_score IS NULL OR
        (relevance_score >= 0.0 AND relevance_score <= 1.0)
    )
);

COMMENT ON TABLE news_asset_relations IS 'Maps news articles to the assets they are relevant to, with relevance weighting';

CREATE INDEX idx_nar_news_id        ON news_asset_relations (news_id);
CREATE INDEX idx_nar_asset_id       ON news_asset_relations (asset_id);
CREATE INDEX idx_nar_relevance      ON news_asset_relations (asset_id, relevance_score DESC);

-- ------------------------------------------------------------
-- Table: social_sentiment
-- Individual social media posts with sentiment scoring
-- ------------------------------------------------------------
CREATE TABLE social_sentiment (
    id               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id         UUID REFERENCES assets (id) ON DELETE SET NULL,  -- nullable: not every post is asset-specific
    platform         social_platform NOT NULL,
    content          TEXT NOT NULL,
    author           VARCHAR(200),
    posted_at        TIMESTAMPTZ NOT NULL,
    sentiment_score  NUMERIC(4, 3),
    sentiment_label  sentiment_label,
    engagement_score NUMERIC(14, 2),                -- weighted: likes + shares + comments
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ss_sentiment_range   CHECK (
        sentiment_score IS NULL OR
        (sentiment_score >= -1.0 AND sentiment_score <= 1.0)
    ),
    CONSTRAINT ss_engagement_positive CHECK (
        engagement_score IS NULL OR engagement_score >= 0
    )
);

COMMENT ON TABLE  social_sentiment                  IS 'Individual social media posts with NLP sentiment analysis';
COMMENT ON COLUMN social_sentiment.asset_id         IS 'NULL when the post covers macro topics rather than a specific asset';
COMMENT ON COLUMN social_sentiment.engagement_score IS 'Composite engagement: likes + shares + comments (can be weighted)';

CREATE INDEX idx_ss_asset_id        ON social_sentiment (asset_id);
CREATE INDEX idx_ss_platform        ON social_sentiment (platform);
CREATE INDEX idx_ss_posted_at       ON social_sentiment (posted_at DESC);
CREATE INDEX idx_ss_asset_platform  ON social_sentiment (asset_id, platform, posted_at DESC);
CREATE INDEX idx_ss_sentiment_score ON social_sentiment (sentiment_score);

-- ------------------------------------------------------------
-- Table: sentiment_aggregates
-- Pre-computed sentiment aggregates per asset and time window
-- ------------------------------------------------------------
CREATE TABLE sentiment_aggregates (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id                UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    period_start            TIMESTAMPTZ NOT NULL,
    period_end              TIMESTAMPTZ NOT NULL,
    period_type             sentiment_period_type NOT NULL,
    news_sentiment_avg      NUMERIC(4, 3),
    social_sentiment_avg    NUMERIC(4, 3),
    composite_sentiment     NUMERIC(4, 3),           -- weighted blend of news + social
    news_volume             INTEGER NOT NULL DEFAULT 0,
    social_volume           INTEGER NOT NULL DEFAULT 0,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT sa_asset_period_unique   UNIQUE (asset_id, period_start, period_type),
    CONSTRAINT sa_period_order          CHECK (period_end > period_start),
    CONSTRAINT sa_news_volume_positive  CHECK (news_volume >= 0),
    CONSTRAINT sa_social_volume_positive CHECK (social_volume >= 0),
    CONSTRAINT sa_news_sent_range       CHECK (
        news_sentiment_avg IS NULL OR
        (news_sentiment_avg >= -1.0 AND news_sentiment_avg <= 1.0)
    ),
    CONSTRAINT sa_social_sent_range     CHECK (
        social_sentiment_avg IS NULL OR
        (social_sentiment_avg >= -1.0 AND social_sentiment_avg <= 1.0)
    ),
    CONSTRAINT sa_composite_range       CHECK (
        composite_sentiment IS NULL OR
        (composite_sentiment >= -1.0 AND composite_sentiment <= 1.0)
    )
);

COMMENT ON TABLE  sentiment_aggregates                  IS 'Pre-aggregated sentiment metrics for fast dashboard queries';
COMMENT ON COLUMN sentiment_aggregates.composite_sentiment IS 'Weighted blend of news and social sentiment scores';

CREATE INDEX idx_sa_asset_id        ON sentiment_aggregates (asset_id);
CREATE INDEX idx_sa_period_start    ON sentiment_aggregates (asset_id, period_start DESC);
CREATE INDEX idx_sa_period_type     ON sentiment_aggregates (period_type, period_start DESC);

-- ------------------------------------------------------------
-- Table: fear_greed_index
-- Daily CNN-style Fear & Greed composite reading
-- ------------------------------------------------------------
CREATE TABLE fear_greed_index (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    date        DATE NOT NULL,
    value       NUMERIC(5, 2) NOT NULL,     -- 0.00 to 100.00
    label       VARCHAR(50),                 -- Extreme Fear / Fear / Neutral / Greed / Extreme Greed
    components  JSONB NOT NULL DEFAULT '{}', -- individual component scores
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT fgi_date_unique      UNIQUE (date),
    CONSTRAINT fgi_value_range      CHECK (value >= 0 AND value <= 100)
);

COMMENT ON TABLE  fear_greed_index              IS 'Daily Fear & Greed Index composite reading (0 = Extreme Fear, 100 = Extreme Greed)';
COMMENT ON COLUMN fear_greed_index.components   IS 'JSON breakdown of contributing factors: momentum, volatility, put/call ratio, etc.';

CREATE INDEX idx_fgi_date       ON fear_greed_index (date DESC);
CREATE INDEX idx_fgi_components ON fear_greed_index USING GIN (components);
