-- ============================================================
-- Migration: 013_seed_initial_data.sql
-- Description: Seed default roles, major assets, and key
--              economic indicators for the AlphaForge platform
-- Platform: PostgreSQL 15+
-- ============================================================

-- ============================================================
-- DEFAULT ROLES
-- ============================================================

INSERT INTO roles (id, name, description, permissions) VALUES

(
    uuid_generate_v4(),
    'admin',
    'Full platform administrator with unrestricted access to all features and user management.',
    '{
        "users":        {"create": true, "read": true, "update": true, "delete": true},
        "roles":        {"create": true, "read": true, "update": true, "delete": true},
        "assets":       {"create": true, "read": true, "update": true, "delete": true},
        "market_data":  {"create": true, "read": true, "update": true, "delete": true},
        "signals":      {"create": true, "read": true, "update": true, "delete": true},
        "portfolios":   {"create": true, "read": true, "update": true, "delete": true},
        "backtests":    {"create": true, "read": true, "update": true, "delete": true},
        "risk":         {"read": true,   "manage": true},
        "api_keys":     {"create": true, "read": true, "update": true, "delete": true},
        "audit_logs":   {"read": true},
        "system":       {"settings": true, "maintenance": true}
    }'::jsonb
),

(
    uuid_generate_v4(),
    'analyst',
    'Financial analyst with read access to all data, ability to create signals and run backtests, but no user management.',
    '{
        "users":        {"read": true},
        "assets":       {"read": true},
        "market_data":  {"read": true},
        "fundamentals": {"read": true},
        "macro":        {"read": true},
        "sentiment":    {"read": true},
        "signals":      {"create": true, "read": true, "update": true, "delete": false},
        "portfolios":   {"create": true, "read": true, "update": true, "delete": false},
        "backtests":    {"create": true, "read": true, "update": false, "delete": false},
        "risk":         {"read": true},
        "api_keys":     {"create": true, "read": true, "update": true, "delete": false},
        "audit_logs":   {"read": false}
    }'::jsonb
),

(
    uuid_generate_v4(),
    'trader',
    'Active trader with full portfolio and trade management, limited to their own portfolios and public signals.',
    '{
        "assets":       {"read": true},
        "market_data":  {"read": true},
        "fundamentals": {"read": true},
        "macro":        {"read": true},
        "sentiment":    {"read": true},
        "signals":      {"create": false, "read": true, "update": false, "delete": false},
        "portfolios":   {"create": true,  "read": true, "update": true,  "delete": true,  "own_only": true},
        "trades":       {"create": true,  "read": true, "update": false, "delete": false, "own_only": true},
        "backtests":    {"create": true,  "read": true, "update": false, "delete": false, "own_only": true},
        "risk":         {"read": true,    "own_only": true},
        "api_keys":     {"create": true,  "read": true, "update": true,  "delete": true,  "own_only": true},
        "watchlists":   {"create": true,  "read": true, "update": true,  "delete": true,  "own_only": true}
    }'::jsonb
),

(
    uuid_generate_v4(),
    'viewer',
    'Read-only access to market data, signals, and public analytics. Cannot create trades or portfolios.',
    '{
        "assets":       {"read": true},
        "market_data":  {"read": true},
        "fundamentals": {"read": true},
        "macro":        {"read": true},
        "sentiment":    {"read": true},
        "signals":      {"create": false, "read": true,  "update": false, "delete": false},
        "portfolios":   {"create": false, "read": false, "update": false, "delete": false},
        "backtests":    {"create": false, "read": false, "update": false, "delete": false},
        "api_keys":     {"create": true,  "read": true,  "update": false, "delete": false, "own_only": true},
        "watchlists":   {"create": true,  "read": true,  "update": true,  "delete": true,  "own_only": true}
    }'::jsonb
)

ON CONFLICT (name) DO UPDATE
    SET description = EXCLUDED.description,
        permissions = EXCLUDED.permissions;

-- ============================================================
-- MAJOR ASSETS
-- ============================================================

INSERT INTO assets (
    id, symbol, name, exchange, asset_type, currency,
    sector, industry, country, market_cap, description,
    logo_url, website, is_active, metadata
)
VALUES

-- -----------------------------------------------
-- US Large-Cap Technology Stocks
-- -----------------------------------------------
(
    uuid_generate_v4(), 'AAPL', 'Apple Inc.', 'NASDAQ', 'stock', 'USD',
    'Technology', 'Consumer Electronics', 'USA',
    3000000000000.00,
    'Apple Inc. designs, manufactures, and markets smartphones, personal computers, tablets, wearables, and accessories worldwide.',
    'https://logo.clearbit.com/apple.com',
    'https://www.apple.com',
    TRUE,
    '{"isin": "US0378331005", "figi": "BBG000B9XRY4", "cik": "0000320193", "employees": 164000, "founded": 1976}'::jsonb
),
(
    uuid_generate_v4(), 'MSFT', 'Microsoft Corporation', 'NASDAQ', 'stock', 'USD',
    'Technology', 'Software - Infrastructure', 'USA',
    3100000000000.00,
    'Microsoft Corporation develops, licenses, and supports software, services, devices, and solutions worldwide.',
    'https://logo.clearbit.com/microsoft.com',
    'https://www.microsoft.com',
    TRUE,
    '{"isin": "US5949181045", "figi": "BBG000BPH459", "cik": "0000789019", "employees": 228000, "founded": 1975}'::jsonb
),
(
    uuid_generate_v4(), 'GOOGL', 'Alphabet Inc. Class A', 'NASDAQ', 'stock', 'USD',
    'Technology', 'Internet Content & Information', 'USA',
    2100000000000.00,
    'Alphabet Inc. provides various products and platforms in the United States, Europe, the Middle East, Africa, the Asia-Pacific, Canada, and Latin America.',
    'https://logo.clearbit.com/abc.xyz',
    'https://abc.xyz',
    TRUE,
    '{"isin": "US02079K3059", "figi": "BBG009S39JX6", "cik": "0001652044", "employees": 181269, "founded": 1998}'::jsonb
),
(
    uuid_generate_v4(), 'AMZN', 'Amazon.com Inc.', 'NASDAQ', 'stock', 'USD',
    'Consumer Cyclical', 'Internet Retail', 'USA',
    1900000000000.00,
    'Amazon.com, Inc. engages in the retail sale of consumer products, advertising, and subscription services through online and physical stores.',
    'https://logo.clearbit.com/amazon.com',
    'https://www.amazon.com',
    TRUE,
    '{"isin": "US0231351067", "figi": "BBG000BVPV84", "cik": "0001018724", "employees": 1525000, "founded": 1994}'::jsonb
),
(
    uuid_generate_v4(), 'NVDA', 'NVIDIA Corporation', 'NASDAQ', 'stock', 'USD',
    'Technology', 'Semiconductors', 'USA',
    2900000000000.00,
    'NVIDIA Corporation provides graphics and compute and networking solutions in the United States, Taiwan, China, and internationally.',
    'https://logo.clearbit.com/nvidia.com',
    'https://www.nvidia.com',
    TRUE,
    '{"isin": "US67066G1040", "figi": "BBG000BBJQV0", "cik": "0001045810", "employees": 29600, "founded": 1993}'::jsonb
),
(
    uuid_generate_v4(), 'TSLA', 'Tesla Inc.', 'NASDAQ', 'stock', 'USD',
    'Consumer Cyclical', 'Auto Manufacturers', 'USA',
    650000000000.00,
    'Tesla, Inc. designs, develops, manufactures, leases, and sells electric vehicles, energy generation and storage systems.',
    'https://logo.clearbit.com/tesla.com',
    'https://www.tesla.com',
    TRUE,
    '{"isin": "US88160R1014", "figi": "BBG000N9MNX3", "cik": "0001318605", "employees": 127855, "founded": 2003}'::jsonb
),
(
    uuid_generate_v4(), 'META', 'Meta Platforms Inc.', 'NASDAQ', 'stock', 'USD',
    'Technology', 'Internet Content & Information', 'USA',
    1400000000000.00,
    'Meta Platforms, Inc. engages in the development of products that enable people to connect and share with friends and family.',
    'https://logo.clearbit.com/meta.com',
    'https://www.meta.com',
    TRUE,
    '{"isin": "US30303M1027", "figi": "BBG000MM2P62", "cik": "0001326801", "employees": 86482, "founded": 2004}'::jsonb
),

-- -----------------------------------------------
-- ETFs
-- -----------------------------------------------
(
    uuid_generate_v4(), 'SPY', 'SPDR S&P 500 ETF Trust', 'NYSE', 'etf', 'USD',
    NULL, NULL, 'USA',
    NULL,
    'The SPDR S&P 500 ETF Trust seeks to provide investment results that, before expenses, correspond generally to the price and yield performance of the S&P 500 Index.',
    'https://logo.clearbit.com/ssga.com',
    'https://www.ssga.com/us/en/intermediary/etfs/spdr-sp-500-etf-trust-spy',
    TRUE,
    '{"isin": "US78462F1030", "inception_date": "1993-01-22", "expense_ratio": 0.0945, "aum_billions": 520, "index": "S&P 500"}'::jsonb
),
(
    uuid_generate_v4(), 'QQQ', 'Invesco QQQ Trust', 'NASDAQ', 'etf', 'USD',
    NULL, NULL, 'USA',
    NULL,
    'The Invesco QQQ Trust seeks investment results that generally correspond to the price and yield performance of the NASDAQ-100 Index.',
    'https://logo.clearbit.com/invesco.com',
    'https://www.invesco.com/qqq-etf/en/home.html',
    TRUE,
    '{"isin": "US46090E1038", "inception_date": "1999-03-10", "expense_ratio": 0.20, "aum_billions": 250, "index": "NASDAQ-100"}'::jsonb
),

-- -----------------------------------------------
-- Cryptocurrencies
-- -----------------------------------------------
(
    uuid_generate_v4(), 'BTC-USD', 'Bitcoin', 'CRYPTO', 'crypto', 'USD',
    NULL, 'Cryptocurrency', NULL,
    NULL,
    'Bitcoin is a decentralized digital currency that can be transferred on the peer-to-peer bitcoin network without intermediaries.',
    'https://cryptologos.cc/logos/bitcoin-btc-logo.png',
    'https://bitcoin.org',
    TRUE,
    '{"symbol_base": "BTC", "symbol_quote": "USD", "max_supply": 21000000, "consensus": "Proof of Work", "launch_year": 2009}'::jsonb
),
(
    uuid_generate_v4(), 'ETH-USD', 'Ethereum', 'CRYPTO', 'crypto', 'USD',
    NULL, 'Cryptocurrency', NULL,
    NULL,
    'Ethereum is a decentralized, open-source blockchain with smart contract functionality.',
    'https://cryptologos.cc/logos/ethereum-eth-logo.png',
    'https://ethereum.org',
    TRUE,
    '{"symbol_base": "ETH", "symbol_quote": "USD", "consensus": "Proof of Stake", "launch_year": 2015, "turing_complete": true}'::jsonb
),

-- -----------------------------------------------
-- Commodities
-- -----------------------------------------------
(
    uuid_generate_v4(), 'GLD', 'SPDR Gold Shares ETF', 'NYSE', 'commodity', 'USD',
    NULL, 'Precious Metals', 'USA',
    NULL,
    'SPDR Gold Shares ETF tracks the price of gold bullion, offering exposure to gold without physical ownership.',
    'https://logo.clearbit.com/ssga.com',
    'https://www.spdrgoldshares.com',
    TRUE,
    '{"isin": "US78463V1070", "inception_date": "2004-11-18", "expense_ratio": 0.40, "backed_by": "physical_gold", "custodian": "HSBC Bank plc"}'::jsonb
),
(
    uuid_generate_v4(), 'OIL', 'iPath Pure Beta Crude Oil ETN', 'NYSE', 'commodity', 'USD',
    NULL, 'Energy', 'USA',
    NULL,
    'The iPath Pure Beta Crude Oil ETN is designed to provide exposure to crude oil futures.',
    'https://logo.clearbit.com/barclays.com',
    'https://www.ipathetn.com',
    TRUE,
    '{"isin": "US06738C4428", "underlying": "WTI Crude Oil", "product_type": "ETN", "roll_type": "pure_beta"}'::jsonb
),

-- -----------------------------------------------
-- Forex
-- -----------------------------------------------
(
    uuid_generate_v4(), 'EUR-USD', 'Euro / US Dollar', 'FOREX', 'forex', 'USD',
    NULL, 'Foreign Exchange', NULL,
    NULL,
    'The EUR/USD currency pair represents how many US Dollars are needed to purchase one Euro. It is the most traded currency pair in the world.',
    NULL,
    NULL,
    TRUE,
    '{"symbol_base": "EUR", "symbol_quote": "USD", "pip_size": 0.0001, "typical_spread_pips": 0.6, "trading_hours": "24/5"}'::jsonb
)

ON CONFLICT (symbol, exchange, asset_type) DO UPDATE
    SET name         = EXCLUDED.name,
        market_cap   = EXCLUDED.market_cap,
        description  = EXCLUDED.description,
        metadata     = EXCLUDED.metadata,
        updated_at   = NOW();

-- ============================================================
-- ECONOMIC INDICATORS
-- ============================================================

INSERT INTO economic_indicators (
    id, name, code, description, source, country, frequency, category, unit
)
VALUES

-- -----------------------------------------------
-- United States
-- -----------------------------------------------
(
    uuid_generate_v4(),
    'US Consumer Price Index',
    'US_CPI',
    'Measures the average change over time in the prices paid by urban consumers for a market basket of consumer goods and services. Primary inflation gauge for the US.',
    'Bureau of Labor Statistics (BLS)',
    'USA',
    'monthly',
    'inflation',
    'Index (1982-1984 = 100)'
),
(
    uuid_generate_v4(),
    'US Gross Domestic Product',
    'US_GDP',
    'The total monetary or market value of all the finished goods and services produced within the United States borders in a specific time period. Released quarterly by the BEA.',
    'Bureau of Economic Analysis (BEA)',
    'USA',
    'quarterly',
    'gdp',
    'Billions of Chained 2017 Dollars (SAAR)'
),
(
    uuid_generate_v4(),
    'US Unemployment Rate',
    'US_UNEMPLOYMENT',
    'The percentage of the labor force that is unemployed but actively seeking employment. Sourced from the BLS monthly Employment Situation report.',
    'Bureau of Labor Statistics (BLS)',
    'USA',
    'monthly',
    'employment',
    'Percent'
),
(
    uuid_generate_v4(),
    'US Federal Funds Rate',
    'US_FEDFUNDS',
    'The interest rate at which depository institutions lend reserve balances to other depository institutions overnight on an uncollateralized basis. Set by the FOMC.',
    'Federal Reserve (FOMC)',
    'USA',
    'meeting-based',
    'rates',
    'Percent per Annum'
),
(
    uuid_generate_v4(),
    'US ISM Manufacturing PMI',
    'US_PMI',
    'The ISM Manufacturing Purchasing Managers Index. A reading above 50 indicates expansion; below 50 indicates contraction in the manufacturing sector.',
    'Institute for Supply Management (ISM)',
    'USA',
    'monthly',
    'manufacturing',
    'Index (50 = Neutral)'
),

-- -----------------------------------------------
-- European Union
-- -----------------------------------------------
(
    uuid_generate_v4(),
    'EU Harmonised Index of Consumer Prices',
    'EU_CPI',
    'The Harmonised Index of Consumer Prices (HICP) measures the change over time in the prices of consumer goods and services acquired by euro area households. Used by the ECB.',
    'Eurostat / European Central Bank (ECB)',
    'EUR',
    'monthly',
    'inflation',
    'Annual Percent Change'
),
(
    uuid_generate_v4(),
    'EU Gross Domestic Product',
    'EU_GDP',
    'The total economic output of the European Union, measuring the monetary value of all goods and services produced in the EU in a given quarter.',
    'Eurostat',
    'EUR',
    'quarterly',
    'gdp',
    'Seasonally Adjusted Annual Rate (EUR Billions)'
),

-- -----------------------------------------------
-- China
-- -----------------------------------------------
(
    uuid_generate_v4(),
    'China Consumer Price Index',
    'CHINA_CPI',
    'China''s CPI measures the average change over time in prices paid by consumers for goods and services. Published monthly by the National Bureau of Statistics of China.',
    'National Bureau of Statistics of China (NBS)',
    'CHN',
    'monthly',
    'inflation',
    'Annual Percent Change'
),
(
    uuid_generate_v4(),
    'China Gross Domestic Product',
    'CHINA_GDP',
    'China''s total economic output measured as GDP growth rate. Released quarterly; one of the most closely watched indicators for global commodity markets.',
    'National Bureau of Statistics of China (NBS)',
    'CHN',
    'quarterly',
    'gdp',
    'Annual Percent Change'
)

ON CONFLICT (code) DO UPDATE
    SET name        = EXCLUDED.name,
        description = EXCLUDED.description,
        source      = EXCLUDED.source,
        frequency   = EXCLUDED.frequency,
        category    = EXCLUDED.category,
        unit        = EXCLUDED.unit;
