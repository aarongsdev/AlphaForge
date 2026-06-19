-- ============================================================
-- Migration: 007_create_analysis_tables.sql
-- Description: Technical indicators, Fibonacci levels, and
--              volume profile data
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: interval_type
-- ------------------------------------------------------------
CREATE TYPE interval_type AS ENUM ('daily', 'weekly', 'monthly');

-- ------------------------------------------------------------
-- Enum: trend_direction
-- ------------------------------------------------------------
CREATE TYPE trend_direction AS ENUM ('uptrend', 'downtrend');

-- ------------------------------------------------------------
-- Table: technical_indicators
-- Pre-computed technical analysis values per asset and interval
-- ------------------------------------------------------------
CREATE TABLE technical_indicators (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id            UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    date                DATE NOT NULL,
    interval_type       interval_type NOT NULL,

    -- Relative Strength Index
    rsi_14              NUMERIC(8, 4),
    rsi_21              NUMERIC(8, 4),

    -- Moving Average Convergence/Divergence
    macd_line           NUMERIC(14, 6),
    macd_signal         NUMERIC(14, 6),
    macd_histogram      NUMERIC(14, 6),

    -- Exponential Moving Averages
    ema_9               NUMERIC(18, 6),
    ema_20              NUMERIC(18, 6),
    ema_50              NUMERIC(18, 6),
    ema_200             NUMERIC(18, 6),

    -- Simple Moving Averages
    sma_20              NUMERIC(18, 6),
    sma_50              NUMERIC(18, 6),
    sma_200             NUMERIC(18, 6),

    -- Average True Range
    atr_14              NUMERIC(14, 6),

    -- Bollinger Bands
    bb_upper            NUMERIC(18, 6),
    bb_middle           NUMERIC(18, 6),
    bb_lower            NUMERIC(18, 6),
    bb_width            NUMERIC(14, 6),

    -- Ichimoku Cloud
    ichimoku_tenkan     NUMERIC(18, 6),
    ichimoku_kijun      NUMERIC(18, 6),
    ichimoku_senkou_a   NUMERIC(18, 6),
    ichimoku_senkou_b   NUMERIC(18, 6),
    ichimoku_chikou     NUMERIC(18, 6),

    -- Volume Indicators
    vwap                NUMERIC(18, 6),
    volume_sma_20       NUMERIC(20, 2),

    -- Trend & Momentum Indicators
    adx_14              NUMERIC(8, 4),     -- Average Directional Index
    stoch_k             NUMERIC(8, 4),     -- Stochastic %K
    stoch_d             NUMERIC(8, 4),     -- Stochastic %D
    williams_r          NUMERIC(8, 4),     -- Williams %R (-100 to 0)
    cci_20              NUMERIC(10, 4),    -- Commodity Channel Index
    obv                 NUMERIC(20, 0),    -- On-Balance Volume

    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ti_asset_date_interval_unique UNIQUE (asset_id, date, interval_type),
    CONSTRAINT ti_rsi_14_range  CHECK (rsi_14 IS NULL OR (rsi_14 >= 0 AND rsi_14 <= 100)),
    CONSTRAINT ti_rsi_21_range  CHECK (rsi_21 IS NULL OR (rsi_21 >= 0 AND rsi_21 <= 100)),
    CONSTRAINT ti_adx_range     CHECK (adx_14 IS NULL OR (adx_14 >= 0 AND adx_14 <= 100)),
    CONSTRAINT ti_stoch_k_range CHECK (stoch_k IS NULL OR (stoch_k >= 0 AND stoch_k <= 100)),
    CONSTRAINT ti_stoch_d_range CHECK (stoch_d IS NULL OR (stoch_d >= 0 AND stoch_d <= 100)),
    CONSTRAINT ti_williams_r_range CHECK (williams_r IS NULL OR (williams_r >= -100 AND williams_r <= 0))
);

COMMENT ON TABLE  technical_indicators              IS 'Pre-computed technical analysis indicators per asset and time interval';
COMMENT ON COLUMN technical_indicators.rsi_14       IS 'RSI with 14-period lookback; 0-100 scale';
COMMENT ON COLUMN technical_indicators.bb_width     IS 'Bollinger Band width: (upper - lower) / middle';
COMMENT ON COLUMN technical_indicators.adx_14       IS 'Average Directional Index strength (0-100); >25 = trending';
COMMENT ON COLUMN technical_indicators.williams_r   IS 'Williams %R momentum oscillator: -100 (oversold) to 0 (overbought)';
COMMENT ON COLUMN technical_indicators.obv          IS 'Cumulative On-Balance Volume; direction signals trend confirmation';

CREATE INDEX idx_ti_asset_date          ON technical_indicators (asset_id, date DESC);
CREATE INDEX idx_ti_asset_interval      ON technical_indicators (asset_id, interval_type, date DESC);
CREATE INDEX idx_ti_date                ON technical_indicators (date DESC);
CREATE INDEX idx_ti_rsi_14              ON technical_indicators (asset_id, rsi_14);

-- ------------------------------------------------------------
-- Table: fibonacci_levels
-- Computed Fibonacci retracement and extension levels
-- ------------------------------------------------------------
CREATE TABLE fibonacci_levels (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id        UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    computed_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    trend           trend_direction NOT NULL,
    swing_high      NUMERIC(18, 6) NOT NULL,
    swing_low       NUMERIC(18, 6) NOT NULL,

    -- Retracement levels (price values, not ratios)
    level_0         NUMERIC(18, 6),    -- 0%   (swing low for uptrend)
    level_236       NUMERIC(18, 6),    -- 23.6%
    level_382       NUMERIC(18, 6),    -- 38.2%
    level_500       NUMERIC(18, 6),    -- 50.0%
    level_618       NUMERIC(18, 6),    -- 61.8% (Golden Ratio)
    level_786       NUMERIC(18, 6),    -- 78.6%
    level_1000      NUMERIC(18, 6),    -- 100%  (swing high for uptrend)

    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT fl_swing_integrity CHECK (swing_high > swing_low)
);

COMMENT ON TABLE  fibonacci_levels              IS 'Fibonacci retracement price levels computed from recent price swing';
COMMENT ON COLUMN fibonacci_levels.swing_high   IS 'Recent swing high price used as the reference point';
COMMENT ON COLUMN fibonacci_levels.swing_low    IS 'Recent swing low price used as the reference point';
COMMENT ON COLUMN fibonacci_levels.level_618    IS '61.8% Golden Ratio retracement — the most significant Fibonacci level';
COMMENT ON COLUMN fibonacci_levels.level_1000   IS '100% level = full retracement to the opposing swing';

CREATE INDEX idx_fl_asset_id        ON fibonacci_levels (asset_id);
CREATE INDEX idx_fl_computed_at     ON fibonacci_levels (asset_id, computed_at DESC);

-- ------------------------------------------------------------
-- Table: volume_profile
-- Volume-at-price (VAP) profile for a given time range
-- ------------------------------------------------------------
CREATE TABLE volume_profile (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id        UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    period_start    TIMESTAMPTZ NOT NULL,
    period_end      TIMESTAMPTZ NOT NULL,
    poc_price       NUMERIC(18, 6),                     -- Point of Control: highest-volume price level
    vah_price       NUMERIC(18, 6),                     -- Value Area High (70% of volume above)
    val_price       NUMERIC(18, 6),                     -- Value Area Low  (70% of volume below)
    profile_data    JSONB NOT NULL DEFAULT '{}',        -- full price-to-volume histogram
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT vp_period_order  CHECK (period_end > period_start)
);

COMMENT ON TABLE  volume_profile                IS 'Volume-at-price profile identifying key support/resistance via traded volume';
COMMENT ON COLUMN volume_profile.poc_price      IS 'Point of Control: price level with the highest traded volume in the period';
COMMENT ON COLUMN volume_profile.vah_price      IS 'Value Area High: upper boundary of the 70% volume concentration zone';
COMMENT ON COLUMN volume_profile.val_price      IS 'Value Area Low: lower boundary of the 70% volume concentration zone';
COMMENT ON COLUMN volume_profile.profile_data   IS 'Full histogram: JSON map of price bucket to volume, e.g. {"150.00": 1250000}';

CREATE INDEX idx_vp_asset_id        ON volume_profile (asset_id);
CREATE INDEX idx_vp_period          ON volume_profile (asset_id, period_start DESC);
CREATE INDEX idx_vp_profile_data    ON volume_profile USING GIN (profile_data);
