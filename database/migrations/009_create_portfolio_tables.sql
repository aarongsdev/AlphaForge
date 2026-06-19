-- ============================================================
-- Migration: 009_create_portfolio_tables.sql
-- Description: Portfolios, positions, trades, snapshots,
--              watchlists, and watchlist items
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: trade_type
-- ------------------------------------------------------------
CREATE TYPE trade_type AS ENUM ('buy', 'sell', 'short', 'cover');

-- ------------------------------------------------------------
-- Table: portfolios
-- User-owned investment portfolios (live or paper trading)
-- ------------------------------------------------------------
CREATE TABLE portfolios (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id             UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name                VARCHAR(200) NOT NULL,
    description         TEXT,
    currency            VARCHAR(10) NOT NULL DEFAULT 'USD',
    initial_capital     NUMERIC(20, 2) NOT NULL,
    is_paper_trading    BOOLEAN NOT NULL DEFAULT TRUE,
    is_default          BOOLEAN NOT NULL DEFAULT FALSE,
    benchmark_symbol    VARCHAR(20) NOT NULL DEFAULT 'SPY',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT portfolios_name_not_empty        CHECK (char_length(TRIM(name)) > 0),
    CONSTRAINT portfolios_initial_capital_pos   CHECK (initial_capital > 0),
    CONSTRAINT portfolios_currency_length       CHECK (char_length(currency) BETWEEN 3 AND 10)
);

COMMENT ON TABLE  portfolios                    IS 'User investment portfolios, supporting both live and paper trading modes';
COMMENT ON COLUMN portfolios.is_paper_trading   IS 'TRUE = simulated paper trading; FALSE = live real-money trading';
COMMENT ON COLUMN portfolios.is_default         IS 'TRUE for the user''s primary portfolio shown on dashboard';
COMMENT ON COLUMN portfolios.benchmark_symbol   IS 'Ticker used for relative performance comparison, default SPY';

CREATE INDEX idx_portfolios_user_id     ON portfolios (user_id);
CREATE INDEX idx_portfolios_default     ON portfolios (user_id, is_default) WHERE is_default = TRUE;

-- ------------------------------------------------------------
-- Table: portfolio_positions
-- Current open positions held within a portfolio
-- ------------------------------------------------------------
CREATE TABLE portfolio_positions (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    portfolio_id            UUID NOT NULL REFERENCES portfolios (id) ON DELETE CASCADE,
    asset_id                UUID NOT NULL REFERENCES assets (id) ON DELETE RESTRICT,
    quantity                NUMERIC(18, 8) NOT NULL,
    avg_cost_basis          NUMERIC(18, 6) NOT NULL,
    current_price           NUMERIC(18, 6),
    market_value            NUMERIC(20, 2),
    unrealized_pnl          NUMERIC(20, 2),
    unrealized_pnl_percent  NUMERIC(10, 4),
    realized_pnl            NUMERIC(20, 2) NOT NULL DEFAULT 0,
    first_purchase_date     DATE,
    last_updated            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT pp_portfolio_asset_unique    UNIQUE (portfolio_id, asset_id),
    CONSTRAINT pp_quantity_positive         CHECK (quantity > 0),
    CONSTRAINT pp_avg_cost_basis_positive   CHECK (avg_cost_basis > 0)
);

COMMENT ON TABLE  portfolio_positions                       IS 'Current open positions; recalculated on each trade or price update';
COMMENT ON COLUMN portfolio_positions.avg_cost_basis        IS 'Weighted average cost per share across all buy transactions';
COMMENT ON COLUMN portfolio_positions.unrealized_pnl        IS 'Mark-to-market P&L: (current_price - avg_cost_basis) * quantity';
COMMENT ON COLUMN portfolio_positions.realized_pnl          IS 'Cumulative P&L from closed portions of this position';

CREATE INDEX idx_pp_portfolio_id    ON portfolio_positions (portfolio_id);
CREATE INDEX idx_pp_asset_id        ON portfolio_positions (asset_id);
CREATE INDEX idx_pp_market_value    ON portfolio_positions (portfolio_id, market_value DESC);

-- ------------------------------------------------------------
-- Table: trades
-- Individual trade execution records (immutable ledger)
-- ------------------------------------------------------------
CREATE TABLE trades (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    portfolio_id    UUID NOT NULL REFERENCES portfolios (id) ON DELETE CASCADE,
    asset_id        UUID NOT NULL REFERENCES assets (id) ON DELETE RESTRICT,
    signal_id       UUID REFERENCES signals (id) ON DELETE SET NULL,   -- nullable
    trade_type      trade_type NOT NULL,
    quantity        NUMERIC(18, 8) NOT NULL,
    price           NUMERIC(18, 6) NOT NULL,
    commission      NUMERIC(10, 4) NOT NULL DEFAULT 0,
    total_value     NUMERIC(20, 4) NOT NULL,           -- quantity * price + commission
    currency        VARCHAR(10) NOT NULL DEFAULT 'USD',
    executed_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    notes           TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT trades_quantity_positive     CHECK (quantity > 0),
    CONSTRAINT trades_price_positive        CHECK (price > 0),
    CONSTRAINT trades_commission_nn         CHECK (commission >= 0),
    CONSTRAINT trades_total_value_positive  CHECK (total_value > 0),
    CONSTRAINT trades_currency_length       CHECK (char_length(currency) BETWEEN 3 AND 10)
);

COMMENT ON TABLE  trades                    IS 'Immutable trade execution ledger; never deleted, only amended by offsetting trades';
COMMENT ON COLUMN trades.signal_id          IS 'Optional reference to the signal that prompted this trade';
COMMENT ON COLUMN trades.total_value        IS 'quantity * price + commission (full cost of the transaction)';

CREATE INDEX idx_trades_portfolio_id    ON trades (portfolio_id);
CREATE INDEX idx_trades_asset_id        ON trades (asset_id);
CREATE INDEX idx_trades_executed_at     ON trades (portfolio_id, executed_at DESC);
CREATE INDEX idx_trades_signal_id       ON trades (signal_id) WHERE signal_id IS NOT NULL;
CREATE INDEX idx_trades_type            ON trades (trade_type, executed_at DESC);

-- ------------------------------------------------------------
-- Table: portfolio_snapshots
-- Daily portfolio valuation snapshots for performance tracking
-- ------------------------------------------------------------
CREATE TABLE portfolio_snapshots (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    portfolio_id            UUID NOT NULL REFERENCES portfolios (id) ON DELETE CASCADE,
    snapshot_date           DATE NOT NULL,
    total_value             NUMERIC(20, 2) NOT NULL,
    cash_balance            NUMERIC(20, 2) NOT NULL,
    positions_value         NUMERIC(20, 2) NOT NULL,
    daily_pnl               NUMERIC(20, 2),
    daily_pnl_percent       NUMERIC(10, 4),
    total_return            NUMERIC(20, 2),
    total_return_percent    NUMERIC(10, 4),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ps_portfolio_date_unique UNIQUE (portfolio_id, snapshot_date),
    CONSTRAINT ps_total_value_positive  CHECK (total_value >= 0),
    CONSTRAINT ps_cash_balance_nn       CHECK (cash_balance >= 0)
);

COMMENT ON TABLE  portfolio_snapshots               IS 'End-of-day portfolio valuation snapshots; powers equity curve visualization';
COMMENT ON COLUMN portfolio_snapshots.total_return  IS 'Cumulative return from initial_capital to snapshot_date';

CREATE INDEX idx_ps_portfolio_id    ON portfolio_snapshots (portfolio_id);
CREATE INDEX idx_ps_snapshot_date   ON portfolio_snapshots (portfolio_id, snapshot_date DESC);

-- ------------------------------------------------------------
-- Table: watchlists
-- Named collections of assets for monitoring
-- ------------------------------------------------------------
CREATE TABLE watchlists (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id     UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name        VARCHAR(200) NOT NULL,
    description TEXT,
    is_default  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT watchlists_name_not_empty CHECK (char_length(TRIM(name)) > 0)
);

COMMENT ON TABLE  watchlists            IS 'User-curated asset watchlists for monitoring without formal position management';
COMMENT ON COLUMN watchlists.is_default IS 'TRUE for the primary watchlist displayed on dashboard';

CREATE INDEX idx_watchlists_user_id ON watchlists (user_id);
CREATE INDEX idx_watchlists_default ON watchlists (user_id, is_default) WHERE is_default = TRUE;

-- ------------------------------------------------------------
-- Table: watchlist_items
-- Individual assets within a watchlist, with optional price alerts
-- ------------------------------------------------------------
CREATE TABLE watchlist_items (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    watchlist_id        UUID NOT NULL REFERENCES watchlists (id) ON DELETE CASCADE,
    asset_id            UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    notes               TEXT,
    price_alert_high    NUMERIC(18, 6),
    price_alert_low     NUMERIC(18, 6),
    added_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT wi_watchlist_asset_unique    UNIQUE (watchlist_id, asset_id),
    CONSTRAINT wi_alert_order               CHECK (
        price_alert_high IS NULL OR
        price_alert_low IS NULL OR
        price_alert_high > price_alert_low
    ),
    CONSTRAINT wi_alert_high_positive       CHECK (price_alert_high IS NULL OR price_alert_high > 0),
    CONSTRAINT wi_alert_low_positive        CHECK (price_alert_low IS NULL OR price_alert_low > 0)
);

COMMENT ON TABLE  watchlist_items                   IS 'Assets tracked within a watchlist, with optional personal price alert thresholds';
COMMENT ON COLUMN watchlist_items.price_alert_high  IS 'Send notification when asset price rises above this level';
COMMENT ON COLUMN watchlist_items.price_alert_low   IS 'Send notification when asset price drops below this level';

CREATE INDEX idx_wi_watchlist_id    ON watchlist_items (watchlist_id);
CREATE INDEX idx_wi_asset_id        ON watchlist_items (asset_id);
