/**
 * AlphaForge API Client
 * Centralized HTTP client for all backend communication.
 * Exports to window.AlphaForgeAPI
 */
const AlphaForgeAPI = (() => {
  'use strict';

  const BASE_URL = '/api/v1';
  const TOKEN_KEY = 'alphaforge_token';
  const MAX_RETRIES = 3;
  const BACKOFF_BASE_MS = 1000;

  // ---------------------------------------------------------------------------
  // Token management
  // ---------------------------------------------------------------------------

  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || null;
  }

  function setToken(token) {
    if (token) {
      localStorage.setItem(TOKEN_KEY, token);
    }
  }

  function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
  }

  // ---------------------------------------------------------------------------
  // Notification helper (minimal — full system lives in app.js)
  // ---------------------------------------------------------------------------

  function _showNotification(message, type) {
    // Delegate to AlphaForge notify if available, otherwise console.
    if (window.AlphaForge && window.AlphaForge.notify) {
      window.AlphaForge.notify.show(message, type);
    } else {
      console.warn('[AlphaForgeAPI]', type, message);
    }
  }

  // ---------------------------------------------------------------------------
  // Core request function
  // ---------------------------------------------------------------------------

  /**
   * Makes an authenticated HTTP request with retry logic.
   *
   * @param {string} method         HTTP verb (GET, POST, PUT, DELETE, etc.)
   * @param {string} endpoint       Path relative to BASE_URL (must start with /)
   * @param {*}      data           Request body (serialised to JSON automatically)
   * @param {boolean} requiresAuth  Whether to attach the Bearer token header
   * @returns {Promise<*>}          Parsed JSON response body
   */
  async function request(method, endpoint, data = null, requiresAuth = true) {
    const url = `${BASE_URL}${endpoint}`;

    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };

    if (requiresAuth) {
      const token = getToken();
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }
    }

    const init = { method: method.toUpperCase(), headers };

    if (data !== null && !['GET', 'HEAD', 'DELETE'].includes(method.toUpperCase())) {
      init.body = JSON.stringify(data);
    }

    // Retry loop with exponential backoff
    let lastError = null;

    for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
      try {
        const response = await fetch(url, init);

        // --- 401 Unauthorised ---
        if (response.status === 401) {
          clearToken();
          const currentPath = window.location.pathname + window.location.hash;
          const loginPath = '/views/auth/login.html';
          if (!currentPath.includes('login')) {
            window.location.href = loginPath;
          }
          const errBody = await _safeParseJSON(response);
          throw new APIError(
            errBody.message || 'Unauthorised. Please log in again.',
            401,
            errBody
          );
        }

        // --- 429 Rate Limited ---
        if (response.status === 429) {
          const retryAfter = response.headers.get('Retry-After');
          const seconds = retryAfter ? parseInt(retryAfter, 10) : 60;
          const minutes = Math.ceil(seconds / 60);
          const msg = minutes > 1
            ? `Rate limit exceeded. Please wait ${minutes} minutes before retrying.`
            : `Rate limit exceeded. Please wait ${seconds} seconds before retrying.`;
          _showNotification(msg, 'warning');
          const errBody = await _safeParseJSON(response);
          throw new APIError(msg, 429, errBody);
        }

        // --- 2xx success ---
        if (response.ok) {
          // 204 No Content
          if (response.status === 204) {
            return null;
          }
          return await response.json();
        }

        // --- Other non-2xx errors ---
        const errBody = await _safeParseJSON(response);
        const message =
          errBody.message || errBody.error || `Request failed with status ${response.status}`;
        throw new APIError(message, response.status, errBody);

      } catch (err) {
        // Re-throw non-network errors immediately (API errors, etc.)
        if (err instanceof APIError) {
          throw err;
        }

        // Network / fetch failure — retry with backoff
        lastError = err;
        if (attempt < MAX_RETRIES - 1) {
          const delay = BACKOFF_BASE_MS * Math.pow(2, attempt); // 1s, 2s, 4s
          console.warn(
            `[AlphaForgeAPI] Network error on attempt ${attempt + 1}/${MAX_RETRIES}. ` +
            `Retrying in ${delay}ms…`, err.message
          );
          await _sleep(delay);
        }
      }
    }

    // All retries exhausted
    throw new APIError(
      `Network error after ${MAX_RETRIES} attempts: ${lastError ? lastError.message : 'unknown'}`,
      0,
      null
    );
  }

  // ---------------------------------------------------------------------------
  // Custom error class
  // ---------------------------------------------------------------------------

  class APIError extends Error {
    constructor(message, status, body) {
      super(message);
      this.name = 'APIError';
      this.status = status;
      this.body = body;
    }
  }

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  async function _safeParseJSON(response) {
    try {
      return await response.json();
    } catch (_) {
      return {};
    }
  }

  function _sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  return {
    // Token helpers exposed for app.js / auth flows
    getToken,
    setToken,
    clearToken,

    // Error class exposed so callers can do instanceof checks
    APIError,

    // -------------------------------------------------------------------------
    // Auth endpoints
    // -------------------------------------------------------------------------
    auth: {
      /**
       * Authenticate with email + password.
       * The returned token should be stored via AlphaForgeAPI.setToken().
       */
      login: (email, password) =>
        request('POST', '/auth/login', { email, password }, false),

      /**
       * Create a new account.
       * @param {{ email, password, name, [extra] }} data
       */
      register: (data) =>
        request('POST', '/auth/register', data, false),

      /**
       * Log out: clears local token and navigates to login page.
       */
      logout: () => {
        clearToken();
        window.location.href = '/views/auth/login.html';
      },

      /**
       * Fetch the currently authenticated user profile.
       */
      me: () =>
        request('GET', '/auth/me'),

      /**
       * Initiate a forgot-password flow.
       * @param {string} email
       */
      forgotPassword: (email) =>
        request('POST', '/auth/forgot-password', { email }, false),

      /**
       * Complete a password reset using the emailed token.
       * @param {string} token
       * @param {string} password
       */
      resetPassword: (token, password) =>
        request('POST', '/auth/reset-password', { token, password }, false),

      /**
       * Exchange a short-lived access token for a fresh one.
       */
      refreshToken: () =>
        request('POST', '/auth/refresh'),
    },

    // -------------------------------------------------------------------------
    // Market data endpoints
    // -------------------------------------------------------------------------
    market: {
      /**
       * Fetch a real-time quote for a single symbol.
       * @param {string} symbol  e.g. "AAPL"
       */
      getQuote: (symbol) =>
        request('GET', `/market/quote/${encodeURIComponent(symbol)}`),

      /**
       * Fetch OHLCV historical data.
       * @param {string} symbol
       * @param {string} interval  '1m'|'5m'|'15m'|'1h'|'1d'|'1w' (default '1d')
       * @param {number} periods   Number of bars to return (default 30)
       */
      getHistorical: (symbol, interval = '1d', periods = 30) =>
        request(
          'GET',
          `/market/historical/${encodeURIComponent(symbol)}?interval=${interval}&periods=${periods}`
        ),

      /**
       * Search for symbols / companies.
       * @param {string} query
       */
      search: (query) =>
        request('GET', `/market/search?q=${encodeURIComponent(query)}`),

      /**
       * Fetch a high-level market overview (indices, sector performance, etc.).
       */
      getOverview: () =>
        request('GET', '/market/overview'),

      /**
       * Top gainers, losers, and most active symbols.
       */
      getMovers: () =>
        request('GET', '/market/movers'),

      /**
       * Economic and earnings calendar for the week.
       */
      getCalendar: () =>
        request('GET', '/market/calendar'),
    },

    // -------------------------------------------------------------------------
    // Analysis endpoints
    // -------------------------------------------------------------------------
    analysis: {
      /**
       * Technical indicators (RSI, MACD, Bollinger Bands, etc.) for a symbol.
       */
      getTechnical: (symbol) =>
        request('GET', `/analysis/technical/${encodeURIComponent(symbol)}`),

      /**
       * Fundamental data (P/E, EPS, revenue, margins, etc.).
       */
      getFundamental: (symbol) =>
        request('GET', `/analysis/fundamental/${encodeURIComponent(symbol)}`),

      /**
       * Sentiment score derived from news + social media.
       */
      getSentiment: (symbol) =>
        request('GET', `/analysis/sentiment/${encodeURIComponent(symbol)}`),

      /**
       * Combined technical + fundamental + sentiment in a single call.
       */
      getFullAnalysis: (symbol) =>
        request('GET', `/analysis/full/${encodeURIComponent(symbol)}`),

      /**
       * Actionable buy / sell / hold signals.
       */
      getSignals: (symbol) =>
        request('GET', `/analysis/signals/${encodeURIComponent(symbol)}`),
    },

    // -------------------------------------------------------------------------
    // Predictions / signals endpoints
    // -------------------------------------------------------------------------
    predictions: {
      /**
       * AI-generated price prediction for a symbol.
       * @param {string} symbol
       * @param {'short'|'medium'|'long'} timeframe
       */
      get: (symbol, timeframe = 'short') =>
        request(
          'GET',
          `/predictions/${encodeURIComponent(symbol)}?timeframe=${timeframe}`
        ),

      /**
       * Paginated list of the latest trading signals.
       * @param {{ page?, limit?, type?, confidence? }} params
       */
      getLatestSignals: (params = {}) => {
        const q = new URLSearchParams(params).toString();
        return request('GET', `/predictions/signals${q ? '?' + q : ''}`);
      },

      /**
       * Historical predictions for a symbol (used for accuracy benchmarking).
       */
      getHistory: (symbol) =>
        request('GET', `/predictions/history/${encodeURIComponent(symbol)}`),

      /**
       * Aggregate model performance metrics (win rate, avg return, etc.).
       */
      getPerformance: () =>
        request('GET', '/predictions/performance'),
    },

    // -------------------------------------------------------------------------
    // Portfolio management endpoints
    // -------------------------------------------------------------------------
    portfolio: {
      /** List all portfolios owned by the authenticated user. */
      list: () =>
        request('GET', '/portfolio'),

      /** Fetch a single portfolio by ID. */
      get: (id) =>
        request('GET', `/portfolio/${id}`),

      /**
       * Create a new portfolio.
       * @param {{ name, description?, currency?, initialCash? }} data
       */
      create: (data) =>
        request('POST', '/portfolio', data),

      /**
       * Update portfolio metadata.
       * @param {string|number} id
       * @param {{ name?, description?, currency? }} data
       */
      update: (id, data) =>
        request('PUT', `/portfolio/${id}`, data),

      /** Delete a portfolio permanently. */
      delete: (id) =>
        request('DELETE', `/portfolio/${id}`),

      /** Fetch all current positions for a portfolio. */
      getPositions: (id) =>
        request('GET', `/portfolio/${id}/positions`),

      /**
       * Historical P&L and performance metrics.
       * @param {string|number} id
       */
      getPerformance: (id) =>
        request('GET', `/portfolio/${id}/performance`),

      /**
       * Record a buy or sell trade.
       * @param {string|number} id
       * @param {{ symbol, side, quantity, price, timestamp? }} data
       */
      trade: (id, data) =>
        request('POST', `/portfolio/${id}/trades`, data),

      /** Fetch trade history for a portfolio. */
      getTrades: (id) =>
        request('GET', `/portfolio/${id}/trades`),

      /** Fetch risk metrics (VaR, Sharpe, beta, correlation, etc.). */
      getRisk: (id) =>
        request('GET', `/portfolio/${id}/risk`),
    },

    // -------------------------------------------------------------------------
    // Watchlist endpoints
    // -------------------------------------------------------------------------
    watchlist: {
      /** List all watchlists for the authenticated user. */
      list: () =>
        request('GET', '/watchlist'),

      /** Fetch a single watchlist with its items. */
      get: (id) =>
        request('GET', `/watchlist/${id}`),

      /**
       * Create a new watchlist.
       * @param {{ name, description? }} data
       */
      create: (data) =>
        request('POST', '/watchlist', data),

      /**
       * Add a symbol to a watchlist.
       * @param {string|number} id       Watchlist ID
       * @param {string}        symbol   e.g. "TSLA"
       */
      addItem: (id, symbol) =>
        request('POST', `/watchlist/${id}/items`, { symbol }),

      /**
       * Remove an item from a watchlist.
       * @param {string|number} id      Watchlist ID
       * @param {string|number} itemId  Item ID (returned when adding)
       */
      removeItem: (id, itemId) =>
        request('DELETE', `/watchlist/${id}/items/${itemId}`),
    },

    // -------------------------------------------------------------------------
    // News endpoints
    // -------------------------------------------------------------------------
    news: {
      /**
       * Fetch latest market news articles.
       * @param {number} limit  Max articles to return (default 20)
       */
      getLatest: (limit = 20) =>
        request('GET', `/news?limit=${limit}`),

      /**
       * Fetch news articles relevant to a specific symbol.
       * @param {string} symbol
       */
      getForSymbol: (symbol) =>
        request('GET', `/news/${encodeURIComponent(symbol)}`),

      /**
       * Full-text search across news articles.
       * @param {string} query
       */
      search: (query) =>
        request('GET', `/news/search?q=${encodeURIComponent(query)}`),
    },

    // -------------------------------------------------------------------------
    // Backtest endpoints
    // -------------------------------------------------------------------------
    backtest: {
      /**
       * Submit a new backtest job.
       * @param {{
       *   strategy: string,
       *   symbol: string,
       *   startDate: string,
       *   endDate: string,
       *   parameters?: object,
       *   initialCapital?: number
       * }} config
       */
      run: (config) =>
        request('POST', '/backtest/run', config),

      /**
       * Fetch results for a completed backtest.
       * @param {string|number} id
       */
      getResult: (id) =>
        request('GET', `/backtest/${id}`),

      /** List all backtests for the authenticated user. */
      list: () =>
        request('GET', '/backtest'),
    },
  };
})();

// Expose globally
window.AlphaForgeAPI = AlphaForgeAPI;
