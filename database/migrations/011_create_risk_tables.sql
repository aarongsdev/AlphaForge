-- ============================================================
-- Migration: 011_create_risk_tables.sql
-- Description: Portfolio risk metrics and price alert system
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: alert_type
-- ------------------------------------------------------------
CREATE TYPE alert_type AS ENUM (
    'price_above',
    'price_below',
    'percent_change',
    'volume_spike',
    'signal_generated'
);

-- ------------------------------------------------------------
-- Table: risk_metrics
-- Computed portfolio risk statistics at a point in time
-- ------------------------------------------------------------
CREATE TABLE risk_metrics (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    portfolio_id            UUID NOT NULL REFERENCES portfolios (id) ON DELETE CASCADE,
    calculated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- Value at Risk (expressed as negative percentage loss)
    var_95                  NUMERIC(10, 4),     -- 95% confidence 1-day VaR
    var_99                  NUMERIC(10, 4),     -- 99% confidence 1-day VaR
    cvar_95                 NUMERIC(10, 4),     -- Conditional VaR (Expected Shortfall) at 95%

    -- Market Exposure
    beta_spy                NUMERIC(8, 4),      -- portfolio beta vs SPY
    correlation_spy         NUMERIC(6, 4),      -- Pearson correlation with SPY (-1 to 1)

    -- Volatility
    volatility_annual       NUMERIC(10, 4),     -- annualised portfolio volatility (std dev of returns)
    volatility_30d          NUMERIC(10, 4),     -- rolling 30-day realised volatility

    -- Drawdown
    max_drawdown_current    NUMERIC(10, 4),     -- current drawdown from last peak (negative %)

    -- Concentration / Exposure (stored as JSON for flexibility)
    concentration_risk      JSONB NOT NULL DEFAULT '{}',    -- HHI and top-N weights
    sector_exposure         JSONB NOT NULL DEFAULT '{}',    -- {sector: weight_pct, ...}
    asset_exposure          JSONB NOT NULL DEFAULT '{}',    -- {symbol: weight_pct, ...}

    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT rm_portfolio_time_unique     UNIQUE (portfolio_id, calculated_at),
    CONSTRAINT rm_correlation_range         CHECK (
        correlation_spy IS NULL OR
        (correlation_spy >= -1.0 AND correlation_spy <= 1.0)
    ),
    CONSTRAINT rm_volatility_positive       CHECK (
        volatility_annual IS NULL OR volatility_annual >= 0
    ),
    CONSTRAINT rm_max_drawdown_range        CHECK (
        max_drawdown_current IS NULL OR
        (max_drawdown_current >= -100 AND max_drawdown_current <= 0)
    )
);

COMMENT ON TABLE  risk_metrics                      IS 'Point-in-time risk statistics for a portfolio; recalculated on each market close';
COMMENT ON COLUMN risk_metrics.var_95               IS '1-day VaR at 95% confidence: max expected loss (negative %) not exceeded 95% of the time';
COMMENT ON COLUMN risk_metrics.cvar_95              IS 'Expected Shortfall at 95%: average loss in the worst 5% of scenarios';
COMMENT ON COLUMN risk_metrics.beta_spy             IS 'Portfolio sensitivity to SPY movements; 1.0 = moves in lockstep';
COMMENT ON COLUMN risk_metrics.concentration_risk   IS 'JSON with HHI score and top-5 holdings by weight';
COMMENT ON COLUMN risk_metrics.sector_exposure      IS 'JSON map of GICS sector name to portfolio weight percentage';
COMMENT ON COLUMN risk_metrics.asset_exposure       IS 'JSON map of ticker symbol to portfolio weight percentage';

CREATE INDEX idx_rm_portfolio_id    ON risk_metrics (portfolio_id);
CREATE INDEX idx_rm_calculated_at   ON risk_metrics (portfolio_id, calculated_at DESC);
CREATE INDEX idx_rm_sector          ON risk_metrics USING GIN (sector_exposure);
CREATE INDEX idx_rm_asset_exposure  ON risk_metrics USING GIN (asset_exposure);

-- ------------------------------------------------------------
-- Table: price_alerts
-- User-defined asset price and event alert rules
-- ------------------------------------------------------------
CREATE TABLE price_alerts (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id             UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    asset_id            UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    alert_type          alert_type NOT NULL,
    threshold_value     NUMERIC(18, 6) NOT NULL,
    is_triggered        BOOLEAN NOT NULL DEFAULT FALSE,
    triggered_at        TIMESTAMPTZ,
    notification_sent   BOOLEAN NOT NULL DEFAULT FALSE,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT pa_trigger_consistency CHECK (
        (is_triggered = FALSE AND triggered_at IS NULL) OR
        (is_triggered = TRUE  AND triggered_at IS NOT NULL)
    ),
    CONSTRAINT pa_notification_after_trigger CHECK (
        notification_sent = FALSE OR is_triggered = TRUE
    )
);

COMMENT ON TABLE  price_alerts                      IS 'User-configured price, percentage-change, volume, and signal alerts';
COMMENT ON COLUMN price_alerts.alert_type           IS 'price_above / price_below / percent_change / volume_spike / signal_generated';
COMMENT ON COLUMN price_alerts.threshold_value      IS 'Numeric trigger: price level, percentage, volume multiplier, etc.';
COMMENT ON COLUMN price_alerts.is_triggered         IS 'Set to TRUE when the alert condition is first satisfied';
COMMENT ON COLUMN price_alerts.notification_sent    IS 'TRUE once the notification has been dispatched to the user';

CREATE INDEX idx_pa_user_id         ON price_alerts (user_id);
CREATE INDEX idx_pa_asset_id        ON price_alerts (asset_id);
CREATE INDEX idx_pa_active          ON price_alerts (asset_id, alert_type, is_active) WHERE is_active = TRUE;
CREATE INDEX idx_pa_untriggered     ON price_alerts (asset_id, is_triggered) WHERE is_triggered = FALSE AND is_active = TRUE;
