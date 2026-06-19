# AlphaForge — Institutional Financial Intelligence Platform

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php)](https://php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql)](https://postgresql.org)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat-square&logo=docker)](https://docker.com)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Active_Development-yellow?style=flat-square)]()

> Plataforma de análisis financiero de grado institucional impulsada por inteligencia artificial. Combina análisis técnico, fundamental, de sentimiento y macroeconómico para generar señales BUY/SELL/HOLD con puntuaciones de confianza y gestión de riesgo integrada.

---

## Table of Contents

1. [Features](#features)
2. [Architecture](#architecture)
3. [Tech Stack](#tech-stack)
4. [Project Structure](#project-structure)
5. [Quick Start](#quick-start)
6. [Environment Variables](#environment-variables)
7. [REST API Reference](#rest-api-reference)
8. [Prediction Methodology](#prediction-methodology)
9. [Development Roadmap](#development-roadmap)
10. [Contributing](#contributing)
11. [License](#license)
12. [Disclaimer](#disclaimer)

---

## Features

| Category | Capability |
|---|---|
| **Technical Analysis** | RSI (Wilder's smoothing), MACD (12/26/9), Bollinger Bands, EMA/SMA, ATR, Ichimoku Cloud, Fibonacci retracements, Volume Profile |
| **Fundamental Analysis** | P/E, P/B, EV/EBITDA, DCF valuation, earnings quality scoring, balance sheet health |
| **Sentiment Analysis** | News NLP scoring, social media aggregation, Fear & Greed index, options flow sentiment |
| **Macro Analysis** | Interest rate sensitivity, sector rotation tracking, correlation matrices, economic calendar events |
| **Signal Generation** | BUY / SELL / HOLD signals with 0-100 confidence scores, multi-timeframe confluence |
| **Risk Management** | VaR (95/99%), CVaR, Max Drawdown, Sharpe Ratio, Sortino Ratio, Beta, Kelly Criterion |
| **Backtesting** | Walk-forward testing on up to 5 years of daily OHLCV data, equity curve export |
| **Portfolio Tracking** | Multi-portfolio management, position sizing, P&L tracking, real-time performance |
| **Watchlists** | Named lists with custom alert thresholds, price and signal triggers |
| **Authentication** | Argon2id password hashing, JWT access + refresh tokens, email verification, rate limiting |
| **Caching** | Redis-backed result caching with per-endpoint configurable TTLs |
| **Observability** | Structured JSON logging via Monolog, request ID tracing, Sentry error tracking |

---

## Architecture

```
+---------------------------------------------------------------------------+
|                          CLIENT LAYER                                     |
|           Browser SPA  /  Mobile App  /  REST API consumers              |
+---------------------------------+-----------------------------------------+
                                  | HTTPS
+---------------------------------v-----------------------------------------+
|                        NGINX REVERSE PROXY                                |
|          TLS termination * Static file serving * Rate limit headers       |
+---------------------------------+-----------------------------------------+
                                  | FastCGI
+---------------------------------v-----------------------------------------+
|                     PHP 8.4 APPLICATION (php-fpm)                        |
|                                                                           |
|  +--------------+  +------------------------------------------------------+|
|  |  Middleware  |  |                  Router / Dispatcher                 ||
|  |  - CORS      |  |  POST /api/v1/auth/*      -> AuthController          ||
|  |  - Auth JWT  |  |  GET  /api/v1/market/*    -> MarketController        ||
|  |  - RateLimit |  |  GET  /api/v1/analysis/*  -> AnalysisController      ||
|  |  - Logging   |  |  GET  /api/v1/predictions/*-> PredictionController   ||
|  +--------------+  |  *    /api/v1/portfolio/* -> PortfolioController     ||
|                    |  *    /api/v1/watchlists/*-> WatchlistController      ||
|                    |  *    /api/v1/backtest/*  -> BacktestController       ||
|                    +------------------------------------------------------+|
|                                                                           |
|  +-----------------------------------------------------------------------+ |
|  |                     SERVICE LAYER                                     | |
|  |                                                                       | |
|  |  +-----------------+  +----------------+  +------------------------+  | |
|  |  |  MarketData     |  |  Auth Service  |  |  Prediction Engine     |  | |
|  |  |  Service        |  |  JwtService    |  |  - SignalGenerator     |  | |
|  |  |  - AlphaVantage |  |  PasswordSvc   |  |  - TrendDetector      |  | |
|  |  |  - Finnhub      |  |  AuthService   |  |  - AnomalyDetector    |  | |
|  |  +-----------------+  +----------------+  |  - RiskCalculator     |  | |
|  |                                           +------------------------+  | |
|  |  +------------------------------------------------------------------+ | |
|  |  |                  Analysis Engines                                | | |
|  |  |                                                                  | | |
|  |  |  Technical          Fundamental        Sentiment                | | |
|  |  |  - RSI              - DCF Valuation    - NewsAnalyzer           | | |
|  |  |  - MACD             - Ratio Analysis   - SocialMediaAnalyzer   | | |
|  |  |  - Bollinger        - Earnings Score   - SentimentEngine       | | |
|  |  |  - EMA / SMA        - Balance Sheet                            | | |
|  |  |  - ATR              - Growth Metrics                           | | |
|  |  |  - Ichimoku                                                    | | |
|  |  |  - Fibonacci                                                   | | |
|  |  |  - VolumeProfile                                               | | |
|  |  +------------------------------------------------------------------+ | |
|  |                                                                       | |
|  |  +-------------------+  +------------------------------------------+  | |
|  |  |  BacktestEngine   |  |         Core Infrastructure              |  | |
|  |  |  - Walk-forward   |  |  - Database (PDO/PostgreSQL)             |  | |
|  |  |  - Equity curve   |  |  - Cache (Redis / Predis)               |  | |
|  |  |  - Risk metrics   |  |  - Logger (Monolog)                     |  | |
|  |  +-------------------+  |  - Config, Router, Request, Response    |  | |
|  |                         +------------------------------------------+  | |
|  +-----------------------------------------------------------------------+ |
+----------------------------+----------------------------------------------+
                             |
            +----------------+------------------+
            |                                   |
+-----------v--------------+  +----------------v-------------+
|      PostgreSQL 16        |  |         Redis 7              |
|  - Users & Auth           |  |  - JWT session store         |
|  - Assets & Prices        |  |  - API result cache          |
|  - Analysis results       |  |  - Rate limit counters       |
|  - Signals & predictions  |  |  - Queue jobs                |
|  - Portfolios & trades    |  +------------------------------+
|  - Backtests              |
|  - Audit logs             |
+---------------------------+

        External Data Sources
        +-------------------------------------------------------+
        |  Alpha Vantage   Finnhub   Polygon.io   News API      |
        |  OpenAI (GPT-4o-mini for sentiment NLP)               |
        +-------------------------------------------------------+
```

---

## Tech Stack

| Layer | Technology | Version | Purpose |
|---|---|---|---|
| Runtime | PHP | 8.4 | Application language |
| Web Server | Nginx | 1.25 | Reverse proxy, TLS termination |
| PHP Process Manager | php-fpm | 8.4 | FastCGI process pool |
| Database | PostgreSQL | 16 | Primary data store |
| Cache / Queue | Redis | 7.2 | Caching, rate limiting, job queues |
| Containerization | Docker + Compose | 3.9 | Development and production environments |
| JWT | firebase/php-jwt | 6.x | Access and refresh token signing |
| HTTP Client | Guzzle | 7.x | External API requests |
| Logging | Monolog | 3.x | Structured log output |
| Caching abstraction | Symfony Cache | 7.x | PSR-6 cache interface |
| UUID generation | ramsey/uuid | 4.x | Unique request and resource IDs |
| Arbitrary precision | brick/math | 0.12 | Financial decimal arithmetic |
| Date/time | nesbot/carbon | 3.x | Timezone-aware date manipulation |
| IoC Container | league/container | 4.x | Dependency injection |
| Testing | PHPUnit | 11.x | Unit and integration tests |
| Static analysis | PHPStan | 1.x | Level 8 type safety |
| Coding standard | PHP_CodeSniffer | 3.x | PSR-12 enforcement |

---

## Project Structure

```
AlphaForge/
|-- .env.example                    # Environment template -- copy to .env
|-- .gitignore                      # Git ignore rules
|-- composer.json                   # PHP dependency manifest
|-- composer.lock                   # Locked dependency tree (tracked)
|-- docker-compose.yml              # Multi-service orchestration
|
|-- config/                         # Application configuration files
|   |-- app.php                     # Core app settings (name, timezone, locale)
|   |-- database.php                # Database connection parameters
|   `-- jwt.php                     # JWT algorithm, expiry, and audience config
|
|-- database/
|   |-- migrate.php                 # CLI migration runner (run/status/rollback)
|   |-- seed.php                    # Database seeder (admin user, reference data)
|   `-- migrations/
|       |-- 001_create_extensions.sql         # uuid-ossp, pgcrypto, pg_stat_statements
|       |-- 002_create_users_tables.sql       # users, email_verifications, password_resets
|       |-- 003_create_assets_tables.sql      # assets, price_history, market_data_snapshots
|       |-- 004_create_fundamental_tables.sql # fundamental_data, dcf_valuations
|       |-- 005_create_macro_tables.sql       # macro_indicators, sector_rotations
|       |-- 006_create_sentiment_tables.sql   # sentiment_scores, news_articles
|       |-- 007_create_analysis_tables.sql    # technical_analyses, analysis_cache
|       |-- 008_create_signals_tables.sql     # trading_signals, signal_performance
|       |-- 009_create_portfolio_tables.sql   # portfolios, positions, trades
|       |-- 010_create_backtesting_tables.sql # backtests, backtest_trades, equity_curves
|       |-- 011_create_risk_tables.sql        # risk_assessments, var_calculations
|       |-- 012_create_indexes.sql            # Performance indexes across all tables
|       `-- 013_seed_initial_data.sql         # Reference data (sectors, asset types)
|
|-- docker/
|   |-- nginx/
|   |   `-- nginx.conf              # Virtual host, TLS, proxy_pass configuration
|   |-- php/
|   |   |-- Dockerfile              # PHP 8.4-fpm image with extensions
|   |   `-- opcache.ini             # OPcache settings optimized for production
|   `-- postgres/
|       `-- postgresql.conf         # PostgreSQL tuning parameters
|
|-- docs/                           # Extended documentation
|   `-- api/                        # OpenAPI / Swagger specification files
|
|-- logs/                           # Runtime log files (gitignored)
|
|-- public/
|   |-- index.php                   # Front controller -- single HTTP entry point
|   |-- assets/
|   |   |-- css/
|   |   |   |-- main.css            # Dashboard / app styles
|   |   |   `-- auth.css            # Login / register page styles
|   |   `-- js/
|   |       |-- app.js              # Main SPA application bootstrap
|   |       |-- api.js              # Typed API client (fetch wrapper)
|   |       |-- auth.js             # Login, register, token refresh logic
|   |       `-- components/
|   |           `-- charts.js       # Chart.js / TradingView integrations
|   `-- views/
|       `-- auth/
|           |-- login.html          # Login page template
|           |-- register.html       # Registration page template
|           `-- forgot-password.html
|
|-- src/
|   |-- helpers.php                 # Global helper functions
|   |
|   |-- Api/
|   |   |-- Controllers/
|   |   |   |-- AuthController.php       # POST /auth/register, login, refresh, etc.
|   |   |   |-- MarketController.php     # GET /market/quote, /historical, /movers
|   |   |   |-- AnalysisController.php   # GET /analysis/{symbol}/technical, etc.
|   |   |   |-- PredictionController.php # GET /predictions/{symbol}
|   |   |   |-- PortfolioController.php  # CRUD portfolios, positions, trades
|   |   |   |-- WatchlistController.php  # CRUD watchlists and items
|   |   |   `-- BacktestController.php   # POST/GET /backtest
|   |   |-- Middleware/
|   |   |   |-- AuthMiddleware.php       # JWT validation
|   |   |   |-- CorsMiddleware.php       # CORS headers
|   |   |   |-- RateLimitMiddleware.php  # General API rate limiting (Redis)
|   |   |   |-- LoginRateLimitMiddleware.php
|   |   |   |-- RegisterRateLimitMiddleware.php
|   |   |   `-- ForgotPasswordRateLimitMiddleware.php
|   |   `-- Request.php                  # HTTP request wrapper
|   |
|   |-- Core/
|   |   |-- Cache.php               # Redis cache adapter (PSR-6 compatible)
|   |   |-- Config.php              # Configuration loader and accessor
|   |   |-- Database.php            # PDO singleton with query helpers
|   |   |-- Logger.php              # Monolog wrapper with context binding
|   |   |-- Request.php             # Input sanitization, body parsing
|   |   |-- Response.php            # JSON response factory with request-ID
|   |   |-- Router.php              # Method+path -> middleware+controller dispatch
|   |   `-- Validator.php           # Field validation rules
|   |
|   |-- Exceptions/
|   |   `-- ValidationException.php # Field-level validation errors
|   |
|   |-- Models/
|   |   `-- User.php                # User entity and data-mapping
|   |
|   `-- Services/
|       |-- Auth/
|       |   |-- AuthService.php         # Register, login, token refresh orchestration
|       |   |-- JwtService.php          # Token creation, verification, revocation
|       |   `-- PasswordService.php     # Argon2id hashing, strength validation
|       |
|       |-- Market/
|       |   |-- MarketDataService.php   # Unified quote / historical data facade
|       |   `-- DataProviders/
|       |       |-- AlphaVantageProvider.php
|       |       `-- FinnhubProvider.php
|       |
|       |-- News/
|       |   `-- NewsService.php         # News API integration and article storage
|       |
|       |-- Analysis/
|       |   |-- TechnicalAnalysis/
|       |   |   |-- TechnicalAnalysisEngine.php
|       |   |   `-- Indicators/
|       |   |       |-- RSI.php         # Wilder's RSI, divergence detection
|       |   |       |-- MACD.php        # MACD line, signal, histogram
|       |   |       |-- BollingerBands.php  # Upper/Middle/Lower, %B, Width
|       |   |       |-- EMA.php         # Exponential moving average, crossovers
|       |   |       |-- SMA.php         # Simple moving average
|       |   |       |-- ATR.php         # Average True Range
|       |   |       |-- Fibonacci.php   # Retracement / extension levels
|       |   |       |-- Ichimoku.php    # Ichimoku Cloud components
|       |   |       `-- VolumeProfile.php # Volume by price level
|       |   |-- FundamentalAnalysis/
|       |   |   `-- FundamentalAnalysisEngine.php
|       |   `-- SentimentAnalysis/
|       |       |-- SentimentEngine.php
|       |       `-- Sources/
|       |           |-- NewsAnalyzer.php
|       |           `-- SocialMediaAnalyzer.php
|       |
|       |-- Prediction/
|       |   |-- PredictionEngine.php    # Multi-model ensemble orchestrator
|       |   |-- RiskCalculator.php      # VaR, CVaR, Sharpe, Sortino, Kelly
|       |   `-- Models/
|       |       |-- SignalGenerator.php # Weighted signal scoring
|       |       |-- TrendDetector.php   # Trend state machine
|       |       `-- AnomalyDetector.php # Statistical anomaly detection
|       |
|       `-- Backtesting/
|           `-- BacktestEngine.php      # Walk-forward backtesting
|
|-- storage/
|   |-- cache/                      # File-based cache fallback (gitignored)
|   `-- uploads/                    # User-uploaded files (gitignored)
|
`-- tests/
    |-- phpunit.xml                 # PHPUnit configuration
    |-- Unit/
    |   `-- Services/
    |       |-- RSITest.php
    |       |-- MACDTest.php
    |       |-- BollingerBandsTest.php
    |       |-- RiskCalculatorTest.php
    |       `-- PasswordServiceTest.php
    `-- Integration/
        `-- (database-backed integration tests)
```

---

## Quick Start

### Prerequisites

- Docker 24+ and Docker Compose v2
- Git

### 1. Clone the Repository

```bash
git clone https://github.com/your-org/alphaforge.git
cd alphaforge
```

### 2. Configure Environment

```bash
cp .env.example .env
```

Open `.env` and fill in the required values. At minimum, replace all `REPLACE_WITH_*` placeholders:

| Placeholder | How to generate |
|---|---|
| `APP_KEY` | `php -r "echo 'base64:' . base64_encode(random_bytes(32));"` |
| `JWT_SECRET` | `php -r "echo bin2hex(random_bytes(64));"` |
| `DB_PASSWORD` | Choose a strong password |
| `REDIS_PASSWORD` | Choose a strong password |
| `ALPHA_VANTAGE_API_KEY` | Register at https://www.alphavantage.co |
| `FINNHUB_API_KEY` | Register at https://finnhub.io |
| `NEWS_API_KEY` | Register at https://newsapi.org |

### 3. Start Services

```bash
docker compose up -d
```

This starts four containers: `alphaforge_app` (php-fpm), `alphaforge_nginx` (port 80/443), `alphaforge_postgres` (PostgreSQL 16), and `alphaforge_redis` (Redis 7).

### 4. Install PHP Dependencies

```bash
docker compose exec app composer install
```

### 5. Run Database Migrations

```bash
docker compose exec app php database/migrate.php
```

Expected output:

```
AlphaForge Database Migration Runner
=====================================
[OK] 001_create_extensions.sql
[OK] 002_create_users_tables.sql
[OK] 003_create_assets_tables.sql
...
[OK] 013_seed_initial_data.sql

13 migrations applied successfully.
```

### 6. Seed the Database

```bash
docker compose exec app php database/seed.php
```

This creates the default admin account and reference data.

### 7. Access the Application

Open your browser at `http://localhost` (or `https://localhost` if TLS is configured).

The REST API is available at `http://localhost/api/v1/`.

### Running Tests

```bash
# All tests
docker compose exec app vendor/bin/phpunit --configuration tests/phpunit.xml

# Unit tests only
docker compose exec app vendor/bin/phpunit --configuration tests/phpunit.xml --testsuite Unit

# With coverage (requires Xdebug or PCOV)
docker compose exec app vendor/bin/phpunit --configuration tests/phpunit.xml --coverage-html coverage/html
```

### Checking Code Quality

```bash
# Static analysis (PHPStan level 8)
docker compose exec app vendor/bin/phpstan analyse src/

# Code style (PSR-12)
docker compose exec app vendor/bin/phpcs --standard=PSR12 src/ tests/

# Auto-fix style issues
docker compose exec app vendor/bin/phpcbf --standard=PSR12 src/ tests/
```

---

## Environment Variables

### Application

| Variable | Default | Description |
|---|---|---|
| `APP_NAME` | `AlphaForge` | Application display name |
| `APP_ENV` | `production` | Environment: `production`, `staging`, or `local` |
| `APP_KEY` | -- | Base64-encoded 32-byte secret for encryption |
| `APP_DEBUG` | `false` | Enable detailed error output (never `true` in production) |
| `APP_URL` | -- | Canonical URL used in email links |
| `APP_TIMEZONE` | `UTC` | PHP default timezone |
| `APP_VERSION` | `1.0.0` | Application version string |

### Database

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `postgres` | PostgreSQL hostname (Docker service name) |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_DATABASE` | `alphaforge_db` | Database name |
| `DB_USERNAME` | `alphaforge` | Database user |
| `DB_PASSWORD` | -- | Database password |
| `DB_SCHEMA` | `alphaforge` | PostgreSQL search_path schema |
| `DB_POOL_MIN` | `5` | Minimum connection pool size |
| `DB_POOL_MAX` | `20` | Maximum connection pool size |
| `DB_CONNECTION_TIMEOUT` | `5000` | Connection timeout in milliseconds |
| `DB_STATEMENT_TIMEOUT` | `30000` | Query timeout in milliseconds |
| `DB_SSLMODE` | `prefer` | SSL mode: `disable`, `prefer`, or `require` |

### Redis

| Variable | Default | Description |
|---|---|---|
| `REDIS_HOST` | `redis` | Redis hostname |
| `REDIS_PORT` | `6379` | Redis port |
| `REDIS_PASSWORD` | -- | Redis password (AUTH command) |
| `REDIS_DB` | `0` | Default Redis database index |
| `REDIS_CACHE_DB` | `1` | Database index for API response cache |
| `REDIS_SESSION_DB` | `3` | Database index for JWT session tracking |
| `CACHE_TTL_MARKET_DATA` | `60` | Market quote cache TTL in seconds |
| `CACHE_TTL_ANALYSIS` | `300` | Technical analysis cache TTL |
| `CACHE_TTL_NEWS` | `900` | News sentiment cache TTL |

### Authentication

| Variable | Default | Description |
|---|---|---|
| `JWT_SECRET` | -- | 64-byte hex secret for HMAC-SHA256 signing |
| `JWT_ALGORITHM` | `HS256` | JWT signing algorithm |
| `JWT_EXPIRY` | `3600` | Access token lifetime in seconds (1 hour) |
| `JWT_REFRESH_EXPIRY` | `604800` | Refresh token lifetime in seconds (7 days) |
| `JWT_ISSUER` | `alphaforge` | JWT `iss` claim |
| `JWT_AUDIENCE` | `alphaforge-api` | JWT `aud` claim |
| `ARGON2_MEMORY` | `65536` | Argon2id memory cost in KiB (64 MiB) |
| `ARGON2_TIME` | `4` | Argon2id time cost (iterations) |
| `ARGON2_THREADS` | `3` | Argon2id parallelism factor |

### Rate Limiting

| Variable | Default | Description |
|---|---|---|
| `RATE_LIMIT_API` | `60` | General API requests per window |
| `RATE_LIMIT_AUTH` | `10` | Auth endpoint requests per window |
| `RATE_LIMIT_WINDOW` | `60` | Rate limit window in seconds |

### Market Data APIs

| Variable | Description |
|---|---|
| `ALPHA_VANTAGE_API_KEY` | API key from alphavantage.co |
| `FINNHUB_API_KEY` | API key from finnhub.io |
| `POLYGON_API_KEY` | API key from polygon.io |
| `NEWS_API_KEY` | API key from newsapi.org |
| `OPENAI_API_KEY` | OpenAI API key for GPT-4o-mini sentiment analysis |
| `OPENAI_MODEL` | OpenAI model name (default: `gpt-4o-mini`) |

### Mail

| Variable | Default | Description |
|---|---|---|
| `MAIL_MAILER` | `smtp` | Mail driver |
| `MAIL_HOST` | -- | SMTP server hostname |
| `MAIL_PORT` | `587` | SMTP port |
| `MAIL_ENCRYPTION` | `tls` | SMTP encryption: `tls` or `ssl` |
| `MAIL_FROM_ADDRESS` | -- | Sender email address |

### Feature Flags

| Variable | Default | Description |
|---|---|---|
| `FEATURE_CRYPTO` | `true` | Enable cryptocurrency assets |
| `FEATURE_SENTIMENT_ANALYSIS` | `true` | Enable AI sentiment analysis |
| `FEATURE_ML_PREDICTIONS` | `false` | Enable ML-enhanced predictions (experimental) |
| `FEATURE_BACKTESTING` | `true` | Enable backtesting engine |
| `FEATURE_PAPER_TRADING` | `true` | Enable paper trading simulation |
| `FEATURE_REAL_TRADING` | `false` | Enable live order execution (disabled by default) |

---

## REST API Reference

All endpoints are served under the base path `/api/v1`. Requests and responses use `Content-Type: application/json`. Successful responses follow the envelope:

```json
{
  "success": true,
  "message": "Human-readable description",
  "data": { },
  "timestamp": "2025-01-15T10:30:00+00:00",
  "request_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

Error responses include an `errors` key with field-level detail where applicable.

Authentication uses the `Authorization: Bearer <access_token>` header. Endpoints marked **[Auth]** require a valid JWT.

### Authentication Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `POST` | `/api/v1/auth/register` | Create a new user account | No |
| `POST` | `/api/v1/auth/login` | Authenticate and receive access + refresh tokens | No |
| `POST` | `/api/v1/auth/logout` | Revoke the current session token | Yes |
| `POST` | `/api/v1/auth/refresh` | Exchange a refresh token for a new access token | No |
| `POST` | `/api/v1/auth/forgot-password` | Initiate the password reset flow (sends email) | No |
| `POST` | `/api/v1/auth/reset-password` | Complete the password reset with a valid token | No |
| `GET` | `/api/v1/auth/verify-email/{token}` | Verify an email address via one-time token | No |
| `GET` | `/api/v1/auth/me` | Return the authenticated user's profile | Yes |
| `PUT` | `/api/v1/auth/profile` | Update profile fields (name, username, phone) | Yes |
| `POST` | `/api/v1/auth/change-password` | Change password (requires current password) | Yes |

**Register request body:**

```json
{
  "email": "trader@example.com",
  "password": "StrongP@ssword123",
  "password_confirm": "StrongP@ssword123",
  "username": "trader99",
  "first_name": "Alex",
  "last_name": "Morgan"
}
```

**Login response data:**

```json
{
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "expires_in": 3600,
  "token_type": "Bearer",
  "user": { "id": "uuid", "email": "...", "username": "..." }
}
```

### Market Data Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `GET` | `/api/v1/market/quote/{symbol}` | Latest bid/ask/last price, volume, change | Yes |
| `GET` | `/api/v1/market/historical/{symbol}` | OHLCV bars (`?interval=daily&outputsize=100`) | Yes |
| `GET` | `/api/v1/market/search` | Symbol search by name or ticker (`?q=apple`) | Yes |
| `GET` | `/api/v1/market/overview` | Market-wide summary (indices, breadth, VIX) | Yes |
| `GET` | `/api/v1/market/movers` | Top gainers, losers, and most active | Yes |
| `GET` | `/api/v1/market/calendar` | Upcoming earnings, dividends, economic events | Yes |

**Historical query parameters:**

| Parameter | Values | Default | Description |
|---|---|---|---|
| `interval` | `daily`, `weekly`, `monthly` | `daily` | Bar interval |
| `outputsize` | `1`-`5000` | `100` | Number of bars to return |
| `from` | ISO 8601 date | 100 bars ago | Start date |
| `to` | ISO 8601 date | today | End date |

### Analysis Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `GET` | `/api/v1/analysis/{symbol}/technical` | Full technical analysis (all indicators) | Yes |
| `GET` | `/api/v1/analysis/{symbol}/fundamental` | Fundamental ratios and DCF valuation | Yes |
| `GET` | `/api/v1/analysis/{symbol}/sentiment` | News and social sentiment scores | Yes |
| `GET` | `/api/v1/analysis/{symbol}/full` | Combined technical + fundamental + sentiment | Yes |
| `GET` | `/api/v1/analysis/{symbol}/signals` | Derived entry/exit signals with confidence | Yes |
| `POST` | `/api/v1/analysis/{symbol}/refresh` | Force re-computation bypassing cache | Yes |

**Technical analysis response (excerpt):**

```json
{
  "symbol": "AAPL",
  "analyzed_at": "2025-01-15T10:30:00+00:00",
  "analysis": {
    "rsi": { "value": 58.3, "signal": "neutral", "strength": 79.0 },
    "macd": { "macd": 1.24, "signal": 0.98, "histogram": 0.26, "crossover": "macd_above_signal" },
    "bollinger_bands": { "upper": 195.4, "middle": 188.1, "lower": 180.8, "percent_b": 0.67 },
    "trend": "bullish",
    "overall_score": 72
  }
}
```

### Prediction Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `GET` | `/api/v1/predictions/{symbol}` | BUY/SELL/HOLD signal with confidence score | Yes |
| `GET` | `/api/v1/predictions/{symbol}/history` | Historical prediction log | Yes |
| `GET` | `/api/v1/predictions/signals/latest` | Most recent signals across all tracked assets | Yes |
| `GET` | `/api/v1/predictions/signals/performance` | Accuracy metrics for past predictions | Yes |

**Prediction query parameters:**

| Parameter | Values | Default | Description |
|---|---|---|---|
| `timeframe` | `short_term`, `medium_term`, `long_term` | `short_term` | Prediction horizon |
| `force_refresh` | `1`, `true` | `false` | Bypass 15-minute cache |

**Prediction response:**

```json
{
  "symbol": "AAPL",
  "signal": "BUY",
  "confidence": 74.5,
  "timeframe": "short_term",
  "risk_score": 38.2,
  "components": {
    "technical_score": 72.0,
    "fundamental_score": 68.0,
    "sentiment_score": 81.0,
    "macro_score": 55.0
  },
  "risk_metrics": {
    "var_95": 0.0234,
    "max_drawdown": -0.0891,
    "sharpe_ratio": 1.43,
    "kelly_fraction": 0.12
  },
  "generated_at": "2025-01-15T10:30:00+00:00",
  "expires_at": "2025-01-15T10:45:00+00:00"
}
```

### Portfolio Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `GET` | `/api/v1/portfolio` | List all portfolios for the authenticated user | Yes |
| `POST` | `/api/v1/portfolio` | Create a new portfolio | Yes |
| `GET` | `/api/v1/portfolio/{id}` | Get portfolio details | Yes |
| `PUT` | `/api/v1/portfolio/{id}` | Update portfolio name or description | Yes |
| `DELETE` | `/api/v1/portfolio/{id}` | Delete a portfolio (and all positions) | Yes |
| `GET` | `/api/v1/portfolio/{id}/positions` | List open positions | Yes |
| `GET` | `/api/v1/portfolio/{id}/performance` | P&L, return %, time-weighted return | Yes |
| `GET` | `/api/v1/portfolio/{id}/risk` | Portfolio risk metrics (VaR, beta, etc.) | Yes |
| `POST` | `/api/v1/portfolio/{id}/trade` | Record a buy or sell trade | Yes |
| `GET` | `/api/v1/portfolio/{id}/trades` | Trade history for the portfolio | Yes |

### Watchlist Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `GET` | `/api/v1/watchlists` | List all watchlists | Yes |
| `POST` | `/api/v1/watchlists` | Create a new watchlist | Yes |
| `GET` | `/api/v1/watchlists/{id}` | Get watchlist with all items | Yes |
| `PUT` | `/api/v1/watchlists/{id}` | Update watchlist name | Yes |
| `DELETE` | `/api/v1/watchlists/{id}` | Delete watchlist and all items | Yes |
| `POST` | `/api/v1/watchlists/{id}/items` | Add a symbol to a watchlist | Yes |
| `DELETE` | `/api/v1/watchlists/{id}/items/{itemId}` | Remove a symbol from a watchlist | Yes |
| `PUT` | `/api/v1/watchlists/{id}/items/{itemId}` | Update item alert thresholds | Yes |

### Backtesting Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `POST` | `/api/v1/backtest` | Submit a new backtest job | Yes |
| `GET` | `/api/v1/backtest/{id}` | Retrieve results of a completed backtest | Yes |
| `GET` | `/api/v1/backtest` | List all backtests for the user | Yes |

**Backtest request body:**

```json
{
  "symbol": "AAPL",
  "strategy": "macd_crossover",
  "from_date": "2020-01-01",
  "to_date": "2024-12-31",
  "initial_capital": 10000.00,
  "position_size_pct": 0.1,
  "commission_pct": 0.001
}
```

---

## Prediction Methodology

### Overview

AlphaForge generates directional signals by aggregating four independent scoring engines into a weighted ensemble. No single model dominates -- each provides an independent view, and the final confidence score reflects the degree of agreement across all four.

### Scoring Engines and Weights

| Engine | Weight | Inputs |
|---|---|---|
| Technical Analysis | 35% | RSI, MACD, Bollinger Bands, EMA cross, ATR, volume |
| Fundamental Analysis | 30% | P/E vs. sector median, P/B, revenue growth, margin trend, DCF fair value |
| Sentiment Analysis | 20% | News NLP scores (last 7 days), social media volume and tone |
| Macro Analysis | 15% | Sector rotation phase, interest rate environment, VIX regime, correlation to benchmark |

### Signal Generation Process

```
1. TECHNICAL SCORE (0-100)
   - RSI: oversold (<30) -> +bullish points; overbought (>70) -> +bearish points
   - MACD: bullish crossover -> +points; bearish crossover -> -points
   - Bollinger Bands: %B position and squeeze detection
   - EMA 50/200 golden cross / death cross
   - ATR-normalised volume spike detection
   -> Aggregated to a 0-100 score

2. FUNDAMENTAL SCORE (0-100)
   - P/E discount to sector: up to +20 points
   - P/B below 1.0: +10 points
   - Positive revenue growth YoY: +15 points
   - Improving gross margin: +10 points
   - DCF upside > 15%: +20 points
   - Debt/Equity < 1.0: +10 points
   - Altman Z-Score > 3.0: +15 points
   -> Aggregated to a 0-100 score

3. SENTIMENT SCORE (0-100)
   - NewsAPI articles from last 7 days: compound NLP score x volume weight
   - Social media mention velocity vs. 30-day baseline
   - Analyst upgrades/downgrades (Finnhub)
   -> Aggregated to a 0-100 score

4. MACRO SCORE (0-100)
   - Sector rotation: is the sector in accumulation or distribution phase?
   - Interest rate environment: rate-sensitive sectors penalised in rising-rate regime
   - VIX regime: high volatility -> lower macro score (uncertainty penalty)
   - Correlation to market: high-beta assets discounted when market is bearish
   -> Aggregated to a 0-100 score

5. COMPOSITE CONFIDENCE SCORE
   confidence = (technical x 0.35) + (fundamental x 0.30)
              + (sentiment x 0.20) + (macro x 0.15)

6. SIGNAL DETERMINATION
   confidence >= 65  AND composite > 0  -> BUY
   confidence >= 65  AND composite < 0  -> SELL
   otherwise                            -> HOLD
```

### Risk Metrics

Every prediction is accompanied by a risk assessment computed from the trailing 252 daily returns:

| Metric | Formula | Interpretation |
|---|---|---|
| **VaR (95%)** | 5th percentile of return distribution | Maximum expected daily loss with 95% confidence |
| **VaR (99%)** | 1st percentile of return distribution | Maximum expected daily loss with 99% confidence |
| **CVaR (95%)** | Mean of returns below the 5th percentile | Expected loss given a VaR breach (tail risk) |
| **Max Drawdown** | (Trough - Peak) / Peak | Worst peak-to-trough decline in the period |
| **Sharpe Ratio** | (Annualised return - Rf) / Annualised volatility | Risk-adjusted return per unit of total risk |
| **Sortino Ratio** | (Annualised return - Rf) / Downside deviation | Penalises only negative volatility |
| **Kelly Fraction** | (WinRate x AvgWin - LossRate x AvgLoss) / AvgWin | Theoretically optimal position size fraction |

### Why 100% Accuracy Is Mathematically Impossible

Markets are complex adaptive systems. The following factors guarantee that no prediction system can achieve 100% accuracy:

1. **Market efficiency**: Prices reflect all publicly available information (Efficient Market Hypothesis, semi-strong form). Exploitable mispricings are competed away within milliseconds by algorithmic traders with superior speed and co-location infrastructure.

2. **Non-stationarity**: The statistical properties of financial time series (mean, variance, correlations) change over time. A model calibrated on 2018-2022 data may fail in 2025 because the underlying generating process has structurally shifted.

3. **Fat tails and black swans**: Asset return distributions have heavier tails than a Gaussian model predicts. Rare extreme events (flash crashes, geopolitical shocks, central bank surprises) fall outside the training distribution and cannot be predicted from historical data alone.

4. **Reflexivity**: When a trading signal becomes widely known, market participants trade against it, arbitraging away its predictive power. Models that worked in backtests routinely underperform in live trading because the signal is already priced in.

5. **Information asymmetry**: Institutional investors and insiders act on material information not available in public data feeds. Retail-accessible data sources are permanently and legally incomplete.

6. **Feedback loops**: Algorithmic trading creates feedback loops that amplify or dampen the very signals used to generate predictions, altering market microstructure in ways that invalidate historical calibrations.

**AlphaForge signals should be used as one analytical input among many, never as the sole basis for a trading decision.** See the [Disclaimer](#disclaimer) below.

---

## Development Roadmap

### Phase 1 -- Core Platform (Complete)

- [x] PostgreSQL schema with 13 migration files
- [x] PHP 8.4 REST API with JWT authentication
- [x] Technical analysis: RSI, MACD, Bollinger Bands, EMA, SMA, ATR, Ichimoku, Fibonacci, Volume Profile
- [x] Fundamental analysis engine with DCF valuation
- [x] Sentiment analysis: NewsAPI + social media scoring
- [x] Prediction engine with weighted ensemble scoring
- [x] Risk metrics: VaR, CVaR, Max Drawdown, Sharpe, Sortino, Kelly
- [x] Portfolio management: CRUD, positions, P&L tracking
- [x] Watchlist management with alert thresholds
- [x] Backtesting engine (walk-forward, equity curve)
- [x] Docker Compose stack with Nginx + php-fpm + PostgreSQL + Redis
- [x] Argon2id password hashing, reset flow, email verification
- [x] Redis-backed rate limiting and result caching
- [x] PHPUnit test suite (unit tests for all financial indicators)
- [x] PHPStan level 8 + PSR-12 enforcement
- [x] GitHub Actions CI (tests + linting + security scanning)

### Phase 2 -- Data Enrichment (Planned Q3 2025)

- [ ] WebSocket real-time price streaming (Finnhub / Polygon.io)
- [ ] Options flow integration (unusual activity detection)
- [ ] Insider transaction tracking
- [ ] SEC EDGAR filing parser for fundamental data automation
- [ ] Economic calendar with market impact scoring
- [ ] Multi-asset correlation matrix dashboard
- [ ] Crypto exchange integration (Binance, Coinbase)

### Phase 3 -- Machine Learning Layer (Planned Q4 2025)

- [ ] LSTM model for price direction prediction
- [ ] Random Forest for feature importance ranking
- [ ] Walk-forward cross-validation framework
- [ ] Feature engineering pipeline (rolling statistics, regime indicators)
- [ ] Model versioning and A/B testing infrastructure
- [ ] Prediction confidence calibration (Platt scaling)

### Phase 4 -- Enterprise Features (Planned 2026)

- [ ] Multi-tenant SaaS architecture
- [ ] Team and role-based access control
- [ ] White-label configuration
- [ ] Automated report generation (PDF export)
- [ ] Alert engine with webhooks, Slack, and email notifications
- [ ] Paper trading execution simulator
- [ ] Broker API integration (Interactive Brokers, Alpaca)
- [ ] Compliance and audit trail module

---

## Contributing

Contributions are welcome. Please follow the process below.

### Getting Started

1. Fork the repository and clone your fork locally.
2. Create a feature branch from `main`: `git checkout -b feat/my-feature`.
3. Install dependencies: `composer install`.
4. Copy `.env.example` to `.env` and configure your local database.

### Code Standards

- PHP 8.4 strict types (`declare(strict_types=1)`) in every file.
- Follow PSR-12 coding style. Run `vendor/bin/phpcs --standard=PSR12 src/ tests/` before committing.
- PHPStan level 8 must pass. Run `vendor/bin/phpstan analyse src/`.
- Every new financial calculation must have corresponding unit tests.
- All public methods must have complete PHPDoc with `@param` and `@return` tags.

### Writing Tests

- Place unit tests under `tests/Unit/Services/` mirroring the `src/Services/` structure.
- Place integration tests (requiring a database) under `tests/Integration/`.
- Test classes must extend `PHPUnit\Framework\TestCase`.
- Use `setUp()` for shared state; avoid global state and static methods in tests.
- Test method names must start with `test` and describe the specific behavior being verified.

### Pull Request Process

1. Ensure all tests pass: `vendor/bin/phpunit`.
2. Ensure static analysis passes: `vendor/bin/phpstan analyse src/`.
3. Ensure code style is clean: `vendor/bin/phpcs --standard=PSR12 src/ tests/`.
4. Write a clear PR description explaining what changed and why.
5. Reference any related issues.
6. A maintainer will review within 5 business days.

### Commit Message Format

```
type(scope): short description (50 chars max)

Optional longer explanation wrapped at 72 characters.
Explain why, not what -- the diff shows what.

Refs: #123
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`.

---

## License

```
MIT License

Copyright (c) 2025 AlphaForge Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## Disclaimer

**AlphaForge is a software tool for educational and informational purposes only. Nothing in this platform constitutes financial, investment, legal, or tax advice.**

- Trading financial instruments involves substantial risk of loss, including the possible loss of the entire principal invested.
- Past performance of any strategy, signal, or prediction is not indicative of future results.
- The BUY/SELL/HOLD signals and confidence scores generated by AlphaForge are algorithmic outputs based on historical data and mathematical models. They do not account for your personal financial situation, risk tolerance, investment objectives, or time horizon.
- Market conditions can change rapidly and unpredictably. No algorithm can reliably predict future price movements.
- Always conduct your own due diligence and consult a qualified, licensed financial advisor before making any investment decision.
- The developers and contributors of AlphaForge accept no liability for any financial losses incurred as a result of using this software.

**Use at your own risk.**
