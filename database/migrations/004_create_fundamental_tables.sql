-- ============================================================
-- Migration: 004_create_fundamental_tables.sql
-- Description: Fundamental financial data, earnings, insider
--              transactions, and institutional holdings
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Enum: period_type
-- ------------------------------------------------------------
CREATE TYPE period_type AS ENUM ('annual', 'quarterly');

-- ------------------------------------------------------------
-- Enum: insider_transaction_type
-- ------------------------------------------------------------
CREATE TYPE insider_transaction_type AS ENUM (
    'buy',
    'sell',
    'grant',
    'exercise'
);

-- ------------------------------------------------------------
-- Table: fundamental_data
-- Income statement, balance sheet, cash flow, and valuation ratios
-- ------------------------------------------------------------
CREATE TABLE fundamental_data (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id                UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    period_type             period_type NOT NULL,
    period_end_date         DATE NOT NULL,

    -- Income Statement
    revenue                 NUMERIC(20, 2),
    gross_profit            NUMERIC(20, 2),
    operating_income        NUMERIC(20, 2),
    net_income              NUMERIC(20, 2),
    ebitda                  NUMERIC(20, 2),
    eps                     NUMERIC(12, 4),
    eps_diluted             NUMERIC(12, 4),
    shares_outstanding      NUMERIC(20, 0),

    -- Per Share & Book Value
    book_value_per_share    NUMERIC(12, 4),

    -- Cash Flow
    free_cash_flow          NUMERIC(20, 2),
    operating_cash_flow     NUMERIC(20, 2),
    capital_expenditures    NUMERIC(20, 2),

    -- Balance Sheet
    total_assets            NUMERIC(20, 2),
    total_liabilities       NUMERIC(20, 2),
    total_equity            NUMERIC(20, 2),
    long_term_debt          NUMERIC(20, 2),
    short_term_debt         NUMERIC(20, 2),
    cash_and_equivalents    NUMERIC(20, 2),

    -- Valuation Ratios
    pe_ratio                NUMERIC(12, 4),
    pb_ratio                NUMERIC(12, 4),
    ps_ratio                NUMERIC(12, 4),
    ev_ebitda               NUMERIC(12, 4),

    -- Profitability Ratios
    roe                     NUMERIC(10, 4),   -- Return on Equity
    roa                     NUMERIC(10, 4),   -- Return on Assets
    roic                    NUMERIC(10, 4),   -- Return on Invested Capital

    -- Margin Ratios (expressed as decimals, e.g. 0.25 = 25%)
    gross_margin            NUMERIC(8, 6),
    operating_margin        NUMERIC(8, 6),
    net_margin              NUMERIC(8, 6),

    -- Liquidity & Leverage
    debt_to_equity          NUMERIC(10, 4),
    current_ratio           NUMERIC(10, 4),
    quick_ratio             NUMERIC(10, 4),

    -- Growth Rates (year-over-year, expressed as decimals)
    revenue_growth_yoy      NUMERIC(10, 6),
    earnings_growth_yoy     NUMERIC(10, 6),

    reported_at             TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT fd_asset_period_unique UNIQUE (asset_id, period_type, period_end_date)
);

COMMENT ON TABLE  fundamental_data                  IS 'Aggregated financial statements and ratios per reporting period';
COMMENT ON COLUMN fundamental_data.period_type      IS 'annual or quarterly filing';
COMMENT ON COLUMN fundamental_data.gross_margin     IS 'Decimal fraction: gross_profit / revenue';
COMMENT ON COLUMN fundamental_data.revenue_growth_yoy IS 'YoY revenue growth as decimal: 0.10 = 10% growth';

CREATE INDEX idx_fd_asset_id        ON fundamental_data (asset_id);
CREATE INDEX idx_fd_period          ON fundamental_data (asset_id, period_type, period_end_date DESC);
CREATE INDEX idx_fd_reported_at     ON fundamental_data (reported_at DESC);

-- ------------------------------------------------------------
-- Table: earnings_calendar
-- Scheduled and actual earnings releases
-- ------------------------------------------------------------
CREATE TABLE earnings_calendar (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id            UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    earnings_date       TIMESTAMPTZ NOT NULL,
    period              VARCHAR(20),                 -- e.g. Q1 2024, FY 2024
    estimated_eps       NUMERIC(12, 4),
    actual_eps          NUMERIC(12, 4),
    estimated_revenue   NUMERIC(20, 2),
    actual_revenue      NUMERIC(20, 2),
    surprise_percent    NUMERIC(8, 4),               -- (actual - estimate) / |estimate| * 100
    guidance_raised     BOOLEAN,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  earnings_calendar                 IS 'Past and upcoming earnings announcements with consensus estimates';
COMMENT ON COLUMN earnings_calendar.surprise_percent IS 'EPS beat/miss percentage vs consensus estimate';
COMMENT ON COLUMN earnings_calendar.guidance_raised  IS 'TRUE if management raised forward guidance';

CREATE INDEX idx_ec_asset_id        ON earnings_calendar (asset_id);
CREATE INDEX idx_ec_earnings_date   ON earnings_calendar (earnings_date DESC);
CREATE INDEX idx_ec_asset_date      ON earnings_calendar (asset_id, earnings_date DESC);

-- ------------------------------------------------------------
-- Table: insider_transactions
-- SEC Form 3/4/5 insider buy/sell filings
-- ------------------------------------------------------------
CREATE TABLE insider_transactions (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id            UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    insider_name        VARCHAR(200) NOT NULL,
    insider_title       VARCHAR(200),
    transaction_type    insider_transaction_type NOT NULL,
    transaction_date    DATE NOT NULL,
    shares              NUMERIC(16, 0),
    price               NUMERIC(14, 4),
    value               NUMERIC(20, 2),
    shares_owned_after  NUMERIC(16, 0),
    filing_date         DATE,
    form_type           VARCHAR(10),                 -- Form 3, Form 4, Form 5
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT it_shares_positive CHECK (shares IS NULL OR shares >= 0),
    CONSTRAINT it_price_positive  CHECK (price IS NULL OR price >= 0)
);

COMMENT ON TABLE  insider_transactions              IS 'SEC Form 3/4/5 insider trading activity';
COMMENT ON COLUMN insider_transactions.form_type    IS 'SEC form type: Form 3 (initial), Form 4 (change), Form 5 (annual)';
COMMENT ON COLUMN insider_transactions.value        IS 'Total transaction value = shares * price';

CREATE INDEX idx_it_asset_id        ON insider_transactions (asset_id);
CREATE INDEX idx_it_transaction_date ON insider_transactions (asset_id, transaction_date DESC);
CREATE INDEX idx_it_insider_name    ON insider_transactions (insider_name);
CREATE INDEX idx_it_type            ON insider_transactions (transaction_type);

-- ------------------------------------------------------------
-- Table: institutional_holdings
-- 13-F quarterly institutional ownership filings
-- ------------------------------------------------------------
CREATE TABLE institutional_holdings (
    id                   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    asset_id             UUID NOT NULL REFERENCES assets (id) ON DELETE CASCADE,
    institution_name     VARCHAR(300) NOT NULL,
    institution_type     VARCHAR(100),               -- hedge_fund, mutual_fund, pension, etf, bank, insurance
    report_date          DATE NOT NULL,
    shares_held          NUMERIC(20, 0),
    value                NUMERIC(20, 2),
    percent_outstanding  NUMERIC(8, 4),              -- % of total shares outstanding
    change_shares        NUMERIC(20, 0),             -- positive = bought, negative = sold
    change_percent       NUMERIC(10, 4),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ih_shares_held_positive     CHECK (shares_held IS NULL OR shares_held >= 0),
    CONSTRAINT ih_percent_outstanding_range CHECK (
        percent_outstanding IS NULL OR
        (percent_outstanding >= 0 AND percent_outstanding <= 100)
    )
);

COMMENT ON TABLE  institutional_holdings                    IS '13-F quarterly institutional position filings';
COMMENT ON COLUMN institutional_holdings.change_shares      IS 'Net share change vs prior quarter: positive = increased, negative = decreased';
COMMENT ON COLUMN institutional_holdings.percent_outstanding IS 'Percentage of total float held by this institution';

CREATE INDEX idx_ih_asset_id        ON institutional_holdings (asset_id);
CREATE INDEX idx_ih_report_date     ON institutional_holdings (asset_id, report_date DESC);
CREATE INDEX idx_ih_institution     ON institutional_holdings (institution_name);
CREATE INDEX idx_ih_value           ON institutional_holdings (asset_id, value DESC);
