-- ============================================================
-- Migration: 005_create_macro_tables.sql
-- Description: Macroeconomic indicators, releases, economic
--              calendar, and central bank decisions
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: indicator_category
-- ------------------------------------------------------------
CREATE TYPE indicator_category AS ENUM (
    'inflation',
    'employment',
    'gdp',
    'rates',
    'trade',
    'housing',
    'manufacturing',
    'consumer'
);

-- ------------------------------------------------------------
-- Enum: calendar_impact
-- ------------------------------------------------------------
CREATE TYPE calendar_impact AS ENUM ('low', 'medium', 'high');

-- ------------------------------------------------------------
-- Enum: central_bank_decision
-- ------------------------------------------------------------
CREATE TYPE central_bank_decision AS ENUM ('raise', 'cut', 'hold');

-- ------------------------------------------------------------
-- Table: economic_indicators
-- Reference catalogue of macroeconomic data series
-- ------------------------------------------------------------
CREATE TABLE economic_indicators (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name        VARCHAR(200) NOT NULL,
    code        VARCHAR(50) NOT NULL,
    description TEXT,
    source      VARCHAR(200),           -- e.g. BLS, BEA, Fed, ECB, Eurostat
    country     VARCHAR(3),             -- ISO 3166-1 alpha-3
    frequency   VARCHAR(30),            -- daily, weekly, monthly, quarterly, annual
    category    indicator_category NOT NULL,
    unit        VARCHAR(50),            -- e.g. "percent", "index", "billions USD"
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ei_code_unique   UNIQUE (code),
    CONSTRAINT ei_code_not_empty CHECK (char_length(TRIM(code)) > 0)
);

COMMENT ON TABLE  economic_indicators           IS 'Reference catalogue of tracked macroeconomic data series';
COMMENT ON COLUMN economic_indicators.code      IS 'Unique short identifier, e.g. US_CPI, US_GDP, EU_CPI';
COMMENT ON COLUMN economic_indicators.source    IS 'Publishing authority: BLS, BEA, Federal Reserve, ECB, etc.';
COMMENT ON COLUMN economic_indicators.frequency IS 'Release cadence: daily, weekly, monthly, quarterly, annual';

CREATE INDEX idx_ei_code        ON economic_indicators (code);
CREATE INDEX idx_ei_country     ON economic_indicators (country);
CREATE INDEX idx_ei_category    ON economic_indicators (category);

-- ------------------------------------------------------------
-- Table: economic_data
-- Time-series observations for economic indicators
-- ------------------------------------------------------------
CREATE TABLE economic_data (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    indicator_id    UUID NOT NULL REFERENCES economic_indicators (id) ON DELETE CASCADE,
    date            DATE NOT NULL,
    value           NUMERIC(18, 6),
    revised_value   NUMERIC(18, 6),    -- most recent revised figure (NULL if unrevised)
    previous_value  NUMERIC(18, 6),    -- prior period value at time of release
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ed_indicator_date_unique UNIQUE (indicator_id, date)
);

COMMENT ON TABLE  economic_data                 IS 'Time-series data points for each economic indicator';
COMMENT ON COLUMN economic_data.revised_value   IS 'Revised figure published after the initial release';
COMMENT ON COLUMN economic_data.previous_value  IS 'The prior period value reported alongside this release';

CREATE INDEX idx_ed_indicator_id    ON economic_data (indicator_id);
CREATE INDEX idx_ed_date            ON economic_data (indicator_id, date DESC);

-- ------------------------------------------------------------
-- Table: economic_calendar
-- Upcoming and past economic event schedule
-- ------------------------------------------------------------
CREATE TABLE economic_calendar (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    indicator_id    UUID REFERENCES economic_indicators (id) ON DELETE SET NULL,  -- nullable
    event_name      VARCHAR(300) NOT NULL,
    country         VARCHAR(3),
    date            DATE NOT NULL,
    time_utc        TIME,
    impact          calendar_impact,
    forecast        NUMERIC(18, 6),
    previous        NUMERIC(18, 6),
    actual          NUMERIC(18, 6),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  economic_calendar             IS 'Economic event schedule with impact ratings and consensus forecasts';
COMMENT ON COLUMN economic_calendar.indicator_id IS 'Optional link to a tracked indicator series; NULL for one-off events';
COMMENT ON COLUMN economic_calendar.time_utc    IS 'Scheduled release time in UTC';
COMMENT ON COLUMN economic_calendar.impact      IS 'Market impact classification: low, medium, high';

CREATE INDEX idx_cal_date           ON economic_calendar (date DESC);
CREATE INDEX idx_cal_country        ON economic_calendar (country, date DESC);
CREATE INDEX idx_cal_impact         ON economic_calendar (impact, date DESC);
CREATE INDEX idx_cal_indicator_id   ON economic_calendar (indicator_id);

-- ------------------------------------------------------------
-- Table: central_bank_data
-- Central bank policy rate decisions and statement sentiment
-- ------------------------------------------------------------
CREATE TABLE central_bank_data (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    bank_name           VARCHAR(200) NOT NULL,
    country             VARCHAR(3),
    announcement_date   DATE NOT NULL,
    decision            central_bank_decision NOT NULL,
    rate_before         NUMERIC(6, 4) NOT NULL,         -- policy rate before decision (percentage)
    rate_after          NUMERIC(6, 4) NOT NULL,         -- policy rate after decision (percentage)
    statement_sentiment NUMERIC(4, 3),                  -- NLP sentiment: -1.0 (hawkish) to +1.0 (dovish)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT cbd_rate_before_range CHECK (rate_before >= 0 AND rate_before <= 100),
    CONSTRAINT cbd_rate_after_range  CHECK (rate_after  >= 0 AND rate_after  <= 100),
    CONSTRAINT cbd_sentiment_range   CHECK (
        statement_sentiment IS NULL OR
        (statement_sentiment >= -1.0 AND statement_sentiment <= 1.0)
    )
);

COMMENT ON TABLE  central_bank_data                     IS 'Central bank interest rate decisions and policy statement sentiment';
COMMENT ON COLUMN central_bank_data.rate_before         IS 'Policy rate (%) before the announced decision';
COMMENT ON COLUMN central_bank_data.rate_after          IS 'Policy rate (%) after the announced decision';
COMMENT ON COLUMN central_bank_data.statement_sentiment IS 'NLP-derived sentiment: -1 = hawkish, 0 = neutral, +1 = dovish';

CREATE INDEX idx_cbd_country            ON central_bank_data (country, announcement_date DESC);
CREATE INDEX idx_cbd_announcement_date  ON central_bank_data (announcement_date DESC);
CREATE INDEX idx_cbd_decision           ON central_bank_data (decision);
