-- ============================================================
-- Migration: 010_create_backtesting_tables.sql
-- Description: Strategy backtesting configurations and results
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: backtest_status
-- ------------------------------------------------------------
CREATE TYPE backtest_status AS ENUM (
    'pending',
    'running',
    'completed',
    'failed'
);

-- ------------------------------------------------------------
-- Table: backtests
-- Backtest run configuration and execution state
-- ------------------------------------------------------------
CREATE TABLE backtests (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id             UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name                VARCHAR(200) NOT NULL,
    description         TEXT,
    strategy_config     JSONB NOT NULL DEFAULT '{}',    -- full strategy parameter set
    assets              JSONB NOT NULL DEFAULT '[]',    -- JSON array of symbol strings
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    initial_capital     NUMERIC(20, 2) NOT NULL,
    commission_rate     NUMERIC(8, 6) NOT NULL DEFAULT 0.001,   -- e.g. 0.001 = 0.1%
    slippage_rate       NUMERIC(8, 6) NOT NULL DEFAULT 0.0005,  -- e.g. 0.0005 = 0.05%
    status              backtest_status NOT NULL DEFAULT 'pending',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT bt_date_order            CHECK (end_date > start_date),
    CONSTRAINT bt_initial_capital_pos   CHECK (initial_capital > 0),
    CONSTRAINT bt_commission_range      CHECK (commission_rate >= 0 AND commission_rate < 1),
    CONSTRAINT bt_slippage_range        CHECK (slippage_rate >= 0 AND slippage_rate < 1),
    CONSTRAINT bt_name_not_empty        CHECK (char_length(TRIM(name)) > 0)
);

COMMENT ON TABLE  backtests                     IS 'Backtest configuration records; each row defines one strategy simulation run';
COMMENT ON COLUMN backtests.strategy_config     IS 'Full strategy parameter object: indicators, entry/exit rules, position sizing, etc.';
COMMENT ON COLUMN backtests.assets              IS 'JSON array of ticker symbols included in the backtest universe';
COMMENT ON COLUMN backtests.commission_rate     IS 'Per-trade commission as a decimal fraction of trade value';
COMMENT ON COLUMN backtests.slippage_rate       IS 'Simulated market impact as a decimal fraction applied to each fill';

CREATE INDEX idx_bt_user_id     ON backtests (user_id);
CREATE INDEX idx_bt_status      ON backtests (status, created_at DESC);
CREATE INDEX idx_bt_created_at  ON backtests (user_id, created_at DESC);
CREATE INDEX idx_bt_strategy    ON backtests USING GIN (strategy_config);
CREATE INDEX idx_bt_assets      ON backtests USING GIN (assets);

-- ------------------------------------------------------------
-- Table: backtest_results
-- Computed performance metrics for a completed backtest
-- ------------------------------------------------------------
CREATE TABLE backtest_results (
    id                          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    backtest_id                 UUID NOT NULL REFERENCES backtests (id) ON DELETE CASCADE,

    -- Returns
    total_return                NUMERIC(12, 4),             -- total % return over period
    annualized_return           NUMERIC(12, 4),             -- CAGR
    benchmark_return            NUMERIC(12, 4),             -- benchmark (e.g. SPY) return over same period

    -- Risk-Adjusted Returns
    sharpe_ratio                NUMERIC(10, 4),
    sortino_ratio               NUMERIC(10, 4),
    calmar_ratio                NUMERIC(10, 4),

    -- Drawdown
    max_drawdown                NUMERIC(10, 4),             -- peak-to-trough as negative %
    max_drawdown_duration_days  INTEGER,

    -- Trade Statistics
    win_rate                    NUMERIC(8, 4),              -- 0.00 to 100.00 %
    profit_factor               NUMERIC(10, 4),             -- gross profit / gross loss
    total_trades                INTEGER NOT NULL DEFAULT 0,
    winning_trades              INTEGER NOT NULL DEFAULT 0,
    losing_trades               INTEGER NOT NULL DEFAULT 0,
    avg_trade_return            NUMERIC(10, 4),
    avg_win_return              NUMERIC(10, 4),
    avg_loss_return             NUMERIC(10, 4),
    best_trade                  NUMERIC(10, 4),
    worst_trade                 NUMERIC(10, 4),

    -- Benchmark Comparison
    alpha                       NUMERIC(10, 4),             -- Jensen's alpha
    beta                        NUMERIC(10, 4),             -- portfolio beta vs benchmark

    -- Detailed Data (stored as JSON for charting)
    equity_curve                JSONB NOT NULL DEFAULT '[]',    -- [{date, value}, ...]
    monthly_returns             JSONB NOT NULL DEFAULT '{}',    -- {YYYY-MM: return_pct, ...}
    trade_log                   JSONB NOT NULL DEFAULT '[]',    -- full per-trade detail

    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT br_backtest_id_unique        UNIQUE (backtest_id),
    CONSTRAINT br_total_trades_nn           CHECK (total_trades >= 0),
    CONSTRAINT br_winning_trades_nn         CHECK (winning_trades >= 0),
    CONSTRAINT br_losing_trades_nn          CHECK (losing_trades >= 0),
    CONSTRAINT br_win_rate_range            CHECK (win_rate IS NULL OR (win_rate >= 0 AND win_rate <= 100)),
    CONSTRAINT br_profit_factor_positive    CHECK (profit_factor IS NULL OR profit_factor >= 0),
    CONSTRAINT br_max_drawdown_range        CHECK (max_drawdown IS NULL OR (max_drawdown >= -100 AND max_drawdown <= 0))
);

COMMENT ON TABLE  backtest_results                      IS 'Complete performance metrics for a finished backtest simulation';
COMMENT ON COLUMN backtest_results.sharpe_ratio         IS 'Risk-adjusted return: (annualized_return - risk_free_rate) / annualized_volatility';
COMMENT ON COLUMN backtest_results.sortino_ratio        IS 'Like Sharpe but only penalizes downside volatility';
COMMENT ON COLUMN backtest_results.calmar_ratio         IS 'annualized_return / |max_drawdown|';
COMMENT ON COLUMN backtest_results.profit_factor        IS 'Gross winning trades / gross losing trades; >1 is profitable';
COMMENT ON COLUMN backtest_results.alpha                IS 'Excess return above what beta-adjusted benchmark exposure predicts';
COMMENT ON COLUMN backtest_results.equity_curve         IS 'JSON array of {date, value} points for portfolio value over time';
COMMENT ON COLUMN backtest_results.monthly_returns      IS 'JSON map of YYYY-MM to monthly return percentage';
COMMENT ON COLUMN backtest_results.trade_log            IS 'JSON array of per-trade records: entry, exit, P&L, duration';

CREATE INDEX idx_br_backtest_id ON backtest_results (backtest_id);
CREATE INDEX idx_br_equity_curve ON backtest_results USING GIN (equity_curve);
CREATE INDEX idx_br_trade_log   ON backtest_results USING GIN (trade_log);
