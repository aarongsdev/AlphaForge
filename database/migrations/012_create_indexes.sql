-- ============================================================
-- Migration: 012_create_indexes.sql
-- Description: Additional performance indexes beyond those
--              created inline in the table migrations.
--              Covers cross-table query patterns, composite
--              lookups, GIN indexes on JSONB, and partial
--              indexes for active/recent records.
-- Platform: PostgreSQL 15+
-- ============================================================

-- ============================================================
-- MARKET DATA INDEXES
-- ============================================================

-- Covering index for the most common market data query:
-- "Give me close + volume for asset X over date range Y"
CREATE INDEX idx_mdd_covering
    ON market_data_daily (asset_id, date DESC)
    INCLUDE (open, high, low, close, volume, adj_close);

-- Fast lookup of the latest trading day per asset
CREATE INDEX idx_mdd_latest_per_asset
    ON market_data_daily (asset_id, date DESC);

-- Intraday: retrieve 1-minute bars for an asset efficiently
CREATE INDEX idx_mdi_1min
    ON market_data_intraday (asset_id, timestamp DESC)
    WHERE interval_minutes = 1;

-- Intraday: retrieve 5-minute bars for an asset efficiently
CREATE INDEX idx_mdi_5min
    ON market_data_intraday (asset_id, timestamp DESC)
    WHERE interval_minutes = 5;

-- ============================================================
-- SIGNALS INDEXES
-- ============================================================

-- Active BUY signals ordered by composite score (power the signals feed)
CREATE INDEX idx_sig_active_buy
    ON signals (composite_score DESC, generated_at DESC)
    WHERE is_active = TRUE AND signal_type = 'BUY';

-- Active SELL signals ordered by composite score
CREATE INDEX idx_sig_active_sell
    ON signals (composite_score DESC, generated_at DESC)
    WHERE is_active = TRUE AND signal_type = 'SELL';

-- Signals by asset + type + timeframe (analyst drilldown)
CREATE INDEX idx_sig_asset_type_timeframe
    ON signals (asset_id, signal_type, timeframe, generated_at DESC);

-- Pending / active signals that have not yet expired
CREATE INDEX idx_sig_non_expired
    ON signals (expires_at, asset_id)
    WHERE status IN ('pending', 'active') AND is_active = TRUE;

-- ============================================================
-- NEWS & SENTIMENT INDEXES
-- ============================================================

-- Most recent high-impact news (sentiment score DESC)
CREATE INDEX idx_ni_high_sentiment
    ON news_items (published_at DESC, sentiment_score DESC)
    WHERE sentiment_score > 0.5 OR sentiment_score < -0.5;

-- News published today / last N hours (time-windowed query)
CREATE INDEX idx_ni_recent
    ON news_items (published_at DESC)
    WHERE published_at >= NOW() - INTERVAL '7 days';

-- Social sentiment: per-asset feed ordered by recency
CREATE INDEX idx_ss_asset_recent
    ON social_sentiment (asset_id, posted_at DESC)
    WHERE asset_id IS NOT NULL;

-- Social sentiment: high-engagement posts
CREATE INDEX idx_ss_engagement
    ON social_sentiment (asset_id, engagement_score DESC, posted_at DESC)
    WHERE engagement_score > 100;

-- ============================================================
-- TECHNICAL INDICATORS INDEXES
-- ============================================================

-- RSI screening: find oversold assets (RSI < 30)
CREATE INDEX idx_ti_oversold
    ON technical_indicators (rsi_14, date DESC)
    WHERE interval_type = 'daily' AND rsi_14 IS NOT NULL AND rsi_14 < 30;

-- RSI screening: find overbought assets (RSI > 70)
CREATE INDEX idx_ti_overbought
    ON technical_indicators (rsi_14, date DESC)
    WHERE interval_type = 'daily' AND rsi_14 IS NOT NULL AND rsi_14 > 70;

-- EMA crossover detection: assets where price is above EMA-200
CREATE INDEX idx_ti_ema200
    ON technical_indicators (asset_id, date DESC)
    INCLUDE (ema_50, ema_200, sma_200)
    WHERE interval_type = 'daily';

-- Latest technical snapshot per asset (covering)
CREATE INDEX idx_ti_latest_covering
    ON technical_indicators (asset_id, date DESC)
    INCLUDE (rsi_14, macd_line, macd_signal, bb_upper, bb_middle, bb_lower, adx_14);

-- ============================================================
-- PORTFOLIO POSITIONS INDEXES
-- ============================================================

-- Positions sorted by market value descending (portfolio overview)
CREATE INDEX idx_pp_market_value_desc
    ON portfolio_positions (portfolio_id, market_value DESC NULLS LAST);

-- Positions with unrealized loss (risk monitoring)
CREATE INDEX idx_pp_unrealized_loss
    ON portfolio_positions (portfolio_id, unrealized_pnl)
    WHERE unrealized_pnl < 0;

-- ============================================================
-- TRADES INDEXES
-- ============================================================

-- Trades covering index for P&L reports
CREATE INDEX idx_trades_report
    ON trades (portfolio_id, executed_at DESC)
    INCLUDE (asset_id, trade_type, quantity, price, total_value);

-- All buy trades for an asset across portfolios (fills history)
CREATE INDEX idx_trades_asset_buys
    ON trades (asset_id, executed_at DESC)
    WHERE trade_type = 'buy';

-- All sell trades for an asset across portfolios
CREATE INDEX idx_trades_asset_sells
    ON trades (asset_id, executed_at DESC)
    WHERE trade_type = 'sell';

-- ============================================================
-- FUNDAMENTAL DATA INDEXES
-- ============================================================

-- Screen by PE ratio (valuation screener)
CREATE INDEX idx_fd_pe_ratio
    ON fundamental_data (pe_ratio NULLS LAST, period_end_date DESC)
    WHERE period_type = 'annual' AND pe_ratio IS NOT NULL;

-- Screen by net margin (profitability screener)
CREATE INDEX idx_fd_net_margin
    ON fundamental_data (net_margin DESC NULLS LAST, period_end_date DESC)
    WHERE period_type = 'annual' AND net_margin IS NOT NULL;

-- Latest annual filing per asset
CREATE INDEX idx_fd_latest_annual
    ON fundamental_data (asset_id, period_end_date DESC)
    WHERE period_type = 'annual';

-- ============================================================
-- AUDIT LOG INDEXES
-- ============================================================

-- Search audit log by entity (compliance queries)
CREATE INDEX idx_audit_entity_time
    ON audit_logs (entity_type, entity_id, created_at DESC)
    WHERE entity_type IS NOT NULL;

-- Security: track failed login attempts
CREATE INDEX idx_audit_failed_logins
    ON audit_logs (user_id, created_at DESC)
    WHERE action = 'user.login.failed';

-- ============================================================
-- JSONB GIN INDEXES (cross-table)
-- ============================================================

-- Economic calendar components
CREATE INDEX idx_cal_components_gin     ON economic_calendar USING GIN (components)
    WHERE components IS NOT NULL;

-- Signal key_factors full-text search
CREATE INDEX idx_sig_key_factors_gin    ON signals USING GIN (key_factors);
CREATE INDEX idx_sig_risk_factors_gin   ON signals USING GIN (risk_factors);

-- Backtest results equity curve
CREATE INDEX idx_br_equity_gin          ON backtest_results USING GIN (equity_curve);

-- Risk metrics exposure maps
CREATE INDEX idx_rm_concentration_gin   ON risk_metrics USING GIN (concentration_risk);

-- Fundamental metadata (some providers store raw JSON)
CREATE INDEX idx_assets_metadata_gin    ON assets USING GIN (metadata);

-- User roles permissions
CREATE INDEX idx_roles_perms_gin        ON roles USING GIN (permissions);

-- API key permissions
CREATE INDEX idx_apikeys_perms_gin      ON api_keys USING GIN (permissions);

-- ============================================================
-- TRIGRAM TEXT SEARCH INDEXES
-- ============================================================

-- News source full-text search
CREATE INDEX idx_ni_source_trgm
    ON news_items USING GIN (source_name gin_trgm_ops)
    WHERE source_name IS NOT NULL;

-- Insider name search
CREATE INDEX idx_it_insider_trgm
    ON insider_transactions USING GIN (insider_name gin_trgm_ops);

-- Institution name search
CREATE INDEX idx_ih_institution_trgm
    ON institutional_holdings USING GIN (institution_name gin_trgm_ops);

-- Economic indicator name search
CREATE INDEX idx_ei_name_trgm
    ON economic_indicators USING GIN (name gin_trgm_ops);

-- ============================================================
-- PARTIAL INDEXES — ACTIVE RECORDS ONLY
-- ============================================================

-- Only active API keys (the majority of key lookups will be active)
CREATE INDEX idx_apikeys_active_partial
    ON api_keys (key_hash, user_id)
    WHERE is_active = TRUE;

-- Only unexpired user sessions
CREATE INDEX idx_sessions_active_partial
    ON user_sessions (token_hash, user_id, expires_at)
    WHERE is_active = TRUE;

-- Pending/active price alerts (used by the real-time alert engine)
CREATE INDEX idx_pa_pending_partial
    ON price_alerts (asset_id, alert_type, threshold_value)
    WHERE is_active = TRUE AND is_triggered = FALSE;

-- Active portfolios only
CREATE INDEX idx_portfolios_active_partial
    ON portfolios (user_id, created_at DESC)
    WHERE is_paper_trading = FALSE;

-- Upcoming earnings (next 90 days)
CREATE INDEX idx_ec_upcoming_partial
    ON earnings_calendar (earnings_date ASC)
    WHERE earnings_date >= CURRENT_DATE AND actual_eps IS NULL;

-- ============================================================
-- COMPOSITE MULTI-COLUMN INDEXES
-- ============================================================

-- Risk metrics time-series retrieval
CREATE INDEX idx_rm_portfolio_time
    ON risk_metrics (portfolio_id, calculated_at DESC)
    INCLUDE (var_95, var_99, beta_spy, volatility_annual, max_drawdown_current);

-- Portfolio snapshot equity curve retrieval
CREATE INDEX idx_ps_portfolio_date
    ON portfolio_snapshots (portfolio_id, snapshot_date DESC)
    INCLUDE (total_value, daily_pnl_percent, total_return_percent);

-- Watchlist items per user (join through watchlists)
CREATE INDEX idx_wi_asset_watchlist
    ON watchlist_items (asset_id, watchlist_id);

-- Dividends upcoming ex-date
CREATE INDEX idx_div_upcoming
    ON dividends (ex_date ASC, asset_id)
    WHERE ex_date >= CURRENT_DATE;
