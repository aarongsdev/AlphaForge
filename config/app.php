<?php

declare(strict_types=1);

/**
 * AlphaForge Application Configuration
 *
 * This file returns the core configuration array for the AlphaForge platform.
 * All environment-sensitive values must be provided via environment variables.
 */

return [

    // -------------------------------------------------------------------------
    // Core Application Identity
    // -------------------------------------------------------------------------

    'name'    => (string) ($_ENV['APP_NAME'] ?? 'AlphaForge'),
    'version' => '1.0.0',
    'env'     => (string) ($_ENV['APP_ENV'] ?? 'production'),
    'debug'   => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'     => (string) ($_ENV['APP_URL'] ?? 'http://localhost'),
    'key'     => (string) ($_ENV['APP_KEY'] ?? ''),

    // -------------------------------------------------------------------------
    // Locale & Time
    // -------------------------------------------------------------------------

    'timezone' => (string) ($_ENV['APP_TIMEZONE'] ?? 'UTC'),
    'locale'   => (string) ($_ENV['APP_LOCALE'] ?? 'en'),

    // -------------------------------------------------------------------------
    // Supported Asset Classes
    // -------------------------------------------------------------------------

    'supported_assets' => [
        'stocks',
        'etfs',
        'crypto',
        'commodities',
        'forex',
        'futures',
        'options',
    ],

    // -------------------------------------------------------------------------
    // Supported Exchanges
    // -------------------------------------------------------------------------

    'supported_exchanges' => [
        // US Equities
        'NYSE'    => 'New York Stock Exchange',
        'NASDAQ'  => 'NASDAQ Stock Market',
        'AMEX'    => 'NYSE American (AMEX)',
        'BATS'    => 'Cboe BZX Exchange',
        'ARCA'    => 'NYSE Arca',

        // Futures & Options
        'CME'     => 'Chicago Mercantile Exchange',
        'CBOT'    => 'Chicago Board of Trade',
        'NYMEX'   => 'New York Mercantile Exchange',
        'COMEX'   => 'Commodity Exchange',
        'CBOE'    => 'Chicago Board Options Exchange',

        // Crypto
        'COINBASE' => 'Coinbase Exchange',
        'BINANCE'  => 'Binance',
        'KRAKEN'   => 'Kraken',
        'GEMINI'   => 'Gemini',
        'OKX'      => 'OKX Exchange',
        'BYBIT'    => 'Bybit',

        // Forex
        'FOREX'    => 'Foreign Exchange Market (OTC)',

        // International
        'LSE'      => 'London Stock Exchange',
        'TSE'      => 'Tokyo Stock Exchange',
        'HKEX'     => 'Hong Kong Stock Exchange',
        'SSE'      => 'Shanghai Stock Exchange',
        'EURONEXT' => 'Euronext',
        'XETRA'    => 'Deutsche Boerse Xetra',
    ],

    // -------------------------------------------------------------------------
    // Analysis Weights
    // -------------------------------------------------------------------------
    // These weights determine how much each analysis dimension contributes to
    // the overall composite AlphaForge Score. Must sum to 1.0.

    'analysis_weights' => [
        'technical'   => 0.35,   // Technical indicators, chart patterns, momentum
        'fundamental' => 0.30,   // P/E, EPS growth, balance sheet, earnings quality
        'sentiment'   => 0.20,   // News sentiment, social media, analyst ratings
        'macro'       => 0.15,   // Interest rates, inflation, sector rotation, GDP
    ],

    // -------------------------------------------------------------------------
    // Signal Thresholds
    // -------------------------------------------------------------------------
    // Composite score thresholds that determine buy/sell/hold signals.
    // Scores range from 0 (very bearish) to 100 (very bullish).

    'signal_thresholds' => [
        'strong_buy'  => 80,
        'buy'         => 60,
        'hold_upper'  => 59,
        'hold_lower'  => 41,
        'sell'        => 40,
        'strong_sell' => 20,
    ],

    // -------------------------------------------------------------------------
    // Technical Analysis Indicators
    // -------------------------------------------------------------------------

    'technical_indicators' => [
        'moving_averages' => [
            'sma'  => [9, 20, 50, 100, 200],
            'ema'  => [9, 12, 20, 26, 50, 200],
            'wma'  => [20, 50],
            'hull' => [9, 16],
        ],
        'oscillators' => [
            'rsi_period'       => 14,
            'rsi_overbought'   => 70,
            'rsi_oversold'     => 30,
            'stoch_k_period'   => 14,
            'stoch_d_period'   => 3,
            'stoch_smooth'     => 3,
            'macd_fast'        => 12,
            'macd_slow'        => 26,
            'macd_signal'      => 9,
            'cci_period'       => 20,
            'williams_r_period'=> 14,
            'mfi_period'       => 14,
        ],
        'volatility' => [
            'atr_period'         => 14,
            'bb_period'          => 20,
            'bb_std_dev'         => 2.0,
            'keltner_ema_period' => 20,
            'keltner_atr_mult'   => 1.5,
            'donchian_period'    => 20,
        ],
        'volume' => [
            'obv_enabled'     => true,
            'vwap_enabled'    => true,
            'adl_enabled'     => true,
            'chaikin_period'  => 10,
            'volume_sma'      => 20,
        ],
        'trend' => [
            'adx_period'       => 14,
            'adx_strong_trend' => 25,
            'psar_step'        => 0.02,
            'psar_max_step'    => 0.20,
            'ichimoku_tenkan'  => 9,
            'ichimoku_kijun'   => 26,
            'ichimoku_senkou'  => 52,
        ],
    ],

    // -------------------------------------------------------------------------
    // Risk Management
    // -------------------------------------------------------------------------

    'risk' => [
        'max_position_pct'     => 10.0,  // Max % of portfolio in a single position
        'max_sector_pct'       => 30.0,  // Max % in any single sector
        'default_stop_loss'    => 5.0,   // Default stop-loss percentage
        'default_take_profit'  => 15.0,  // Default take-profit percentage
        'max_leverage'         => 3.0,   // Maximum leverage multiplier
        'kelly_fraction'       => 0.25,  // Fractional Kelly criterion
        'var_confidence'       => 0.95,  // Value-at-Risk confidence level
        'var_window_days'      => 252,   // VaR rolling window (1 year)
    ],

    // -------------------------------------------------------------------------
    // Caching
    // -------------------------------------------------------------------------

    'cache' => [
        'driver'  => (string) ($_ENV['CACHE_DRIVER'] ?? 'redis'),
        'prefix'  => 'alphaforge:',
        'ttl'     => [
            'market_data'  => (int) ($_ENV['CACHE_TTL_MARKET_DATA'] ?? 60),
            'analysis'     => (int) ($_ENV['CACHE_TTL_ANALYSIS'] ?? 300),
            'news'         => (int) ($_ENV['CACHE_TTL_NEWS'] ?? 900),
            'user_profile' => (int) ($_ENV['CACHE_TTL_USER_PROFILE'] ?? 1800),
            'default'      => (int) ($_ENV['CACHE_TTL_DEFAULT'] ?? 3600),
        ],
    ],

    // -------------------------------------------------------------------------
    // Feature Flags
    // -------------------------------------------------------------------------

    'features' => [
        'crypto'             => filter_var($_ENV['FEATURE_CRYPTO'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'options'            => filter_var($_ENV['FEATURE_OPTIONS'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'sentiment_analysis' => filter_var($_ENV['FEATURE_SENTIMENT_ANALYSIS'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'ml_predictions'     => filter_var($_ENV['FEATURE_ML_PREDICTIONS'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'backtesting'        => filter_var($_ENV['FEATURE_BACKTESTING'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'paper_trading'      => filter_var($_ENV['FEATURE_PAPER_TRADING'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'real_trading'       => filter_var($_ENV['FEATURE_REAL_TRADING'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    'logging' => [
        'channel'      => (string) ($_ENV['LOG_CHANNEL'] ?? 'stack'),
        'level'        => (string) ($_ENV['LOG_LEVEL'] ?? 'warning'),
        'max_files'    => (int) ($_ENV['LOG_MAX_FILES'] ?? 30),
        'sentry_dsn'   => (string) ($_ENV['SENTRY_DSN'] ?? ''),
    ],

];
