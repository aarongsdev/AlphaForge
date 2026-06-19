-- ============================================================
-- Migration: 003_create_assets_tables.sql
-- Description: Financial assets, market data, dividends, splits
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: asset_type
-- ------------------------------------------------------------
CREATE TYPE asset_type AS ENUM (
    'stock',
    'etf',
    'crypto',
    'commodity',
    'forex',
    'futures',
    'options',
    'index'
);

-- ------------------------------------------------------------
-- Table: assets
-- Master reference for all tradeable instruments
-- ------------------------------------------------------------
CREATE TABLE assets (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    symbol              VARCHAR(20) NOT NULL,
    name                VARCHAR(255),
    exchange            VARCHAR(50),
    asset_type          asset_type NOT NULL,
    currency            VARCHAR(10) NOT NULL DEFAULT 'USD',
    sector              VARCHAR(100),
    industry            VARCHAR(100),
    country             VARCHAR(3),                 -- ISO 3166-1 alpha-3
    market_cap          NUMERIC(20, 2),
    shares_outstanding  NUMERIC(20, 0),
    description         TEXT,
    logo_url            TEXT,
    website             TEXT,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    metadata            JSONB NOT NULL DEFAULT '{}',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT assets_symbol_exchange_type_unique UNIQUE (symbol, exchange, asset_type),
    CONSTRAINT assets_market_cap_positive         CHECK (market_cap IS NULL OR market_cap >= 0),
    CONSTRAINT assets_shares_positive             CHECK (shares_outstanding IS NULL OR shares_outstanding >= 0),
    CONSTRAINT assets_currency_length             CHECK (char_length(currency) BETWEEN 3 AND 10)
);

COMMENT ON TABLE  assets                    IS 'Master catalogue of all financial instruments tracked by the platform';
COMMENT ON COLUMN assets.symbol             IS 'Ticker symbol, e.g. AAPL, BTC-USD';
COMMENT ON COLUMN assets.exchange           IS 'Exchange code, e.g. NASDAQ, NYSE, BINANCE';
COMMENT ON COLUMN assets.country            IS 'ISO 3166-1 alpha-3 country code of primary listing';
COMMENT ON COLUMN assets.metadata           IS 'Extensible key-value store for provider-specific attributes';

CREATE INDEX idx_assets_symbol          ON assets (symbol);
CREATE INDEX idx_assets_asset_type      ON assets (asset_type);
CREATE INDEX idx_assets_exchange        ON assets (exchange);
CREATE INDEX idx_assets_sector          ON assets (sector);
CREATE INDEX idx_assets_is_active       ON assets (is_active) WHERE is_active = TRUE;
CREATE INDEX idx_assets_metadata        ON assets USING GIN (metadata);
CREATE INDEX idx_assets_symbol_trgm     ON assets USING GIN (symbol gin_trgm_ops);
CREATE INDEX idx_assets_name_trgm       ON assets USING GIN (name gin_trgm_ops);

-- ------------------------------------------------------------
-- Table: market_data_daily
-- End-of-day OHLCV price data
-- ------------------------------------------------------------
CREATE TABLE market_data_daily (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id            UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    date                DATE NOT NULL,
    open                NUMERIC(18, 6),
    high                NUMERIC(18, 6),
    low                 NUMERIC(18, 6),
    close               NUMERIC(18, 6),
    volume              BIGINT,
    adj_close           NUMERIC(18, 6),
    vwap                NUMERIC(18, 6),
    transactions_count  INTEGER,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT mdd_asset_date_unique    UNIQUE (asset_id, date),
    CONSTRAINT mdd_ohlc_integrity       CHECK (
        high IS NULL OR low IS NULL OR (high >= low)
    ),
    CONSTRAINT mdd_open_positive        CHECK (open IS NULL OR open >= 0),
    CONSTRAINT mdd_close_positive       CHECK (close IS NULL OR close >= 0),
    CONSTRAINT mdd_volume_positive      CHECK (volume IS NULL OR volume >= 0)
);

COMMENT ON TABLE  market_data_daily             IS 'Daily OHLCV market data with adjusted close and VWAP';
COMMENT ON COLUMN market_data_daily.adj_close   IS 'Adjusted close price accounting for splits and dividends';
COMMENT ON COLUMN market_data_daily.vwap        IS 'Volume-weighted average price for the session';

CREATE INDEX idx_mdd_asset_date         ON market_data_daily (asset_id, date DESC);
CREATE INDEX idx_mdd_date               ON market_data_daily (date DESC);
CREATE INDEX idx_mdd_volume             ON market_data_daily (asset_id, volume DESC);

-- ------------------------------------------------------------
-- Table: market_data_intraday
-- Sub-daily OHLCV bars at configurable intervals
-- ------------------------------------------------------------
CREATE TABLE market_data_intraday (
    id               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id         UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    timestamp        TIMESTAMPTZ NOT NULL,
    interval_minutes INTEGER NOT NULL,
    open             NUMERIC(18, 6),
    high             NUMERIC(18, 6),
    low              NUMERIC(18, 6),
    close            NUMERIC(18, 6),
    volume           BIGINT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT mdi_asset_ts_interval_unique UNIQUE (asset_id, timestamp, interval_minutes),
    CONSTRAINT mdi_interval_positive        CHECK (interval_minutes > 0),
    CONSTRAINT mdi_ohlc_integrity           CHECK (
        high IS NULL OR low IS NULL OR (high >= low)
    ),
    CONSTRAINT mdi_volume_positive          CHECK (volume IS NULL OR volume >= 0)
);

COMMENT ON TABLE  market_data_intraday                  IS 'Intraday OHLCV bars; interval_minutes defines bar granularity (1, 5, 15, 30, 60)';
COMMENT ON COLUMN market_data_intraday.interval_minutes IS 'Bar duration in minutes: 1, 5, 15, 30, 60, etc.';

CREATE INDEX idx_mdi_asset_timestamp    ON market_data_intraday (asset_id, timestamp DESC);
CREATE INDEX idx_mdi_asset_interval     ON market_data_intraday (asset_id, interval_minutes, timestamp DESC);

-- ------------------------------------------------------------
-- Table: dividends
-- Cash and stock dividend history
-- ------------------------------------------------------------
CREATE TABLE dividends (
    id           UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id     UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    ex_date      DATE NOT NULL,
    payment_date DATE,
    record_date  DATE,
    amount       NUMERIC(12, 6) NOT NULL,
    frequency    VARCHAR(20),                        -- annual, semi-annual, quarterly, monthly, special
    currency     VARCHAR(10) NOT NULL DEFAULT 'USD',
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT dividends_amount_positive CHECK (amount > 0)
);

COMMENT ON TABLE  dividends             IS 'Dividend announcements and payment history per asset';
COMMENT ON COLUMN dividends.ex_date     IS 'Ex-dividend date: must hold shares before this date to receive the dividend';
COMMENT ON COLUMN dividends.frequency   IS 'Payment cadence: annual, semi-annual, quarterly, monthly, special';

CREATE INDEX idx_dividends_asset_id ON dividends (asset_id);
CREATE INDEX idx_dividends_ex_date  ON dividends (asset_id, ex_date DESC);

-- ------------------------------------------------------------
-- Table: stock_splits
-- Corporate stock split and reverse-split history
-- ------------------------------------------------------------
CREATE TABLE stock_splits (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id    UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    split_date  DATE NOT NULL,
    ratio_from  NUMERIC(10, 4) NOT NULL,   -- shares before the split
    ratio_to    NUMERIC(10, 4) NOT NULL,   -- shares after the split
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT stock_splits_ratio_positive CHECK (ratio_from > 0 AND ratio_to > 0)
);

COMMENT ON TABLE  stock_splits              IS 'Stock split and reverse-split history; ratio_to/ratio_from = multiplier per share';
COMMENT ON COLUMN stock_splits.ratio_from   IS 'Pre-split share count (e.g. 1 for a 4:1 split)';
COMMENT ON COLUMN stock_splits.ratio_to     IS 'Post-split share count (e.g. 4 for a 4:1 split)';

CREATE INDEX idx_stock_splits_asset_id   ON stock_splits (asset_id);
CREATE INDEX idx_stock_splits_split_date ON stock_splits (asset_id, split_date DESC);
