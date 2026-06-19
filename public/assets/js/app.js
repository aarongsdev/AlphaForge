/**
 * AlphaForge Core Application
 * Initialisation, store, event bus, router, notifications, utilities, and WebSocket.
 * Exports to window.AlphaForge
 */
const AlphaForge = (() => {
  'use strict';

  // ---------------------------------------------------------------------------
  // 1. Reactive Store
  // ---------------------------------------------------------------------------

  const _subscribers = {};

  const store = {
    user: null,
    portfolio: null,
    watchlist: [],
    signals: [],
    notifications: [],

    /**
     * Subscribe to changes on a specific store key.
     * @param {string}   key  Store property name
     * @param {Function} cb   Called with (newValue, oldValue) when key changes
     * @returns {Function}    Unsubscribe function
     */
    subscribe(key, cb) {
      if (!_subscribers[key]) _subscribers[key] = [];
      _subscribers[key].push(cb);
      return () => {
        _subscribers[key] = _subscribers[key].filter((fn) => fn !== cb);
      };
    },

    /**
     * Update one or more store properties and notify subscribers.
     * @param {object} changes  Plain object of key → new value pairs
     */
    update(changes) {
      Object.entries(changes).forEach(([key, newValue]) => {
        const oldValue = this[key];
        this[key] = newValue;
        if (_subscribers[key]) {
          _subscribers[key].forEach((cb) => {
            try {
              cb(newValue, oldValue);
            } catch (err) {
              console.error('[AlphaForge Store] subscriber error:', err);
            }
          });
        }
      });
    },
  };

  // ---------------------------------------------------------------------------
  // 2. Event Bus
  // ---------------------------------------------------------------------------

  const events = {
    listeners: {},

    /**
     * Register an event listener.
     * @param {string}   event  Event name
     * @param {Function} cb     Handler
     */
    on(event, cb) {
      if (!this.listeners[event]) this.listeners[event] = [];
      this.listeners[event].push(cb);
    },

    /**
     * Remove a previously registered listener.
     * @param {string}   event  Event name
     * @param {Function} cb     Exact reference that was passed to on()
     */
    off(event, cb) {
      if (!this.listeners[event]) return;
      this.listeners[event] = this.listeners[event].filter((fn) => fn !== cb);
    },

    /**
     * Emit an event, calling all registered handlers.
     * @param {string} event  Event name
     * @param {*}      data   Payload (passed to each handler)
     */
    emit(event, data) {
      if (!this.listeners[event]) return;
      this.listeners[event].forEach((cb) => {
        try {
          cb(data);
        } catch (err) {
          console.error(`[AlphaForge Events] error in handler for "${event}":`, err);
        }
      });
    },
  };

  // ---------------------------------------------------------------------------
  // 3. Hash-based Router
  // ---------------------------------------------------------------------------

  const ROUTE_TITLES = {
    '#/dashboard':    'Dashboard — AlphaForge',
    '#/portfolio':    'Portfolio — AlphaForge',
    '#/watchlist':    'Watchlist — AlphaForge',
    '#/signals':      'Signals — AlphaForge',
    '#/analysis':     'Analysis — AlphaForge',
    '#/backtest':     'Backtest — AlphaForge',
    '#/news':         'News — AlphaForge',
    '#/settings':     'Settings — AlphaForge',
    '#/login':        'Login — AlphaForge',
    '#/register':     'Create Account — AlphaForge',
    '#/forgot':       'Forgot Password — AlphaForge',
  };

  const AUTH_ROUTES = new Set(['#/login', '#/register', '#/forgot']);

  function _isAuthPage() {
    const path = window.location.pathname;
    const hash = window.location.hash;
    return (
      path.includes('/auth/') ||
      path.includes('login') ||
      path.includes('register') ||
      AUTH_ROUTES.has(hash)
    );
  }

  const router = {
    init() {
      window.addEventListener('hashchange', () => this._handleRoute());
      this._handleRoute();
    },

    navigate(hash) {
      window.location.hash = hash;
    },

    _handleRoute() {
      const hash = window.location.hash || '#/dashboard';

      // Update page title
      const title = ROUTE_TITLES[hash];
      if (title) document.title = title;

      // Auth guard
      const hasToken = !!(window.AlphaForgeAPI && window.AlphaForgeAPI.getToken());
      if (!hasToken && !_isAuthPage()) {
        window.location.href = '/views/auth/login.html';
        return;
      }

      events.emit('routeChange', { hash, title });
    },
  };

  // ---------------------------------------------------------------------------
  // 4. Notification / Toast System
  // ---------------------------------------------------------------------------

  const NOTIFY_ICONS = {
    success: '✓',
    error:   '✕',
    warning: '⚠',
    info:    'ℹ',
  };

  const NOTIFY_COLORS = {
    success: '#16a34a',
    error:   '#dc2626',
    warning: '#d97706',
    info:    '#2563eb',
  };

  let _toastContainer = null;

  function _getToastContainer() {
    if (_toastContainer) return _toastContainer;

    _toastContainer = document.createElement('div');
    _toastContainer.id = 'af-toast-container';
    Object.assign(_toastContainer.style, {
      position:      'fixed',
      top:           '1rem',
      right:         '1rem',
      zIndex:        '99999',
      display:       'flex',
      flexDirection: 'column',
      gap:           '0.5rem',
      pointerEvents: 'none',
    });

    document.body.appendChild(_toastContainer);
    return _toastContainer;
  }

  const notify = {
    /**
     * Display a toast notification.
     * @param {string} message   Text to display
     * @param {string} type      'success' | 'error' | 'warning' | 'info'
     * @param {number} duration  Auto-dismiss delay in ms (default 4000)
     */
    show(message, type = 'info', duration = 4000) {
      const container = _getToastContainer();
      const color = NOTIFY_COLORS[type] || NOTIFY_COLORS.info;
      const icon  = NOTIFY_ICONS[type]  || NOTIFY_ICONS.info;

      const toast = document.createElement('div');
      toast.setAttribute('role', 'alert');
      toast.setAttribute('aria-live', 'assertive');

      Object.assign(toast.style, {
        display:       'flex',
        alignItems:    'center',
        gap:           '0.6rem',
        padding:       '0.75rem 1rem',
        borderRadius:  '0.5rem',
        background:    '#1e293b',
        color:         '#f1f5f9',
        boxShadow:     '0 4px 12px rgba(0,0,0,0.4)',
        fontSize:      '0.875rem',
        lineHeight:    '1.4',
        maxWidth:      '360px',
        pointerEvents: 'all',
        cursor:        'pointer',
        borderLeft:    `4px solid ${color}`,
        // Slide-in animation
        transform:     'translateX(110%)',
        transition:    'transform 0.3s ease, opacity 0.3s ease',
        opacity:       '0',
      });

      // Icon bubble
      const iconEl = document.createElement('span');
      iconEl.textContent = icon;
      Object.assign(iconEl.style, {
        display:        'flex',
        alignItems:     'center',
        justifyContent: 'center',
        width:          '1.5rem',
        height:         '1.5rem',
        borderRadius:   '50%',
        background:     color,
        color:          '#fff',
        fontWeight:     'bold',
        fontSize:       '0.75rem',
        flexShrink:     '0',
      });

      // Message text
      const textEl = document.createElement('span');
      textEl.textContent = message;
      textEl.style.flex = '1';

      // Close button
      const closeEl = document.createElement('button');
      closeEl.textContent = '×';
      Object.assign(closeEl.style, {
        background:  'none',
        border:      'none',
        color:       '#94a3b8',
        fontSize:    '1.1rem',
        cursor:      'pointer',
        padding:     '0',
        lineHeight:  '1',
        flexShrink:  '0',
      });

      toast.appendChild(iconEl);
      toast.appendChild(textEl);
      toast.appendChild(closeEl);
      container.appendChild(toast);

      // Add to store notifications list
      const notifObj = { id: Date.now(), message, type, timestamp: new Date() };
      store.update({ notifications: [...store.notifications, notifObj] });

      // Trigger slide-in on next frame
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          toast.style.transform = 'translateX(0)';
          toast.style.opacity   = '1';
        });
      });

      const dismiss = () => {
        toast.style.transform = 'translateX(110%)';
        toast.style.opacity   = '0';
        setTimeout(() => {
          if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 320);
      };

      closeEl.addEventListener('click', dismiss);
      toast.addEventListener('click', dismiss);

      if (duration > 0) {
        setTimeout(dismiss, duration);
      }

      return dismiss; // allow manual dismiss
    },

    success(message, duration = 4000) {
      return this.show(message, 'success', duration);
    },

    error(message, duration = 6000) {
      return this.show(message, 'error', duration);
    },

    warning(message, duration = 5000) {
      return this.show(message, 'warning', duration);
    },

    info(message, duration = 4000) {
      return this.show(message, 'info', duration);
    },
  };

  // ---------------------------------------------------------------------------
  // 5. Format Utilities
  // ---------------------------------------------------------------------------

  const utils = {
    /**
     * Format a number as a currency string.
     * @param {number} value
     * @param {string} currency  ISO 4217 code (default 'USD')
     * @returns {string}  e.g. "$1,234.56"
     */
    formatCurrency(value, currency = 'USD') {
      if (value === null || value === undefined || isNaN(value)) return '—';
      return new Intl.NumberFormat('en-US', {
        style:    'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(value);
    },

    /**
     * Format a percentage, including leading sign, wrapped in a <span>.
     * @param {number} value     Raw decimal or percentage (values > 1 treated as %)
     * @param {number} decimals  Decimal places (default 2)
     * @returns {string}  HTML string: <span class="positive">+1.23%</span>
     */
    formatPercent(value, decimals = 2) {
      if (value === null || value === undefined || isNaN(value)) {
        return '<span class="neutral">—</span>';
      }
      // Normalise: treat values in (-1, 1) exclusive as decimals (e.g. 0.0123 → 1.23%)
      const pct = Math.abs(value) < 1 ? value * 100 : value;
      const sign = pct >= 0 ? '+' : '';
      const cls  = pct >= 0 ? 'positive' : 'negative';
      return `<span class="${cls}">${sign}${pct.toFixed(decimals)}%</span>`;
    },

    /**
     * Format a large number with K / M / B suffixes.
     * @param {number} value
     * @param {number} decimals  Decimal places for the mantissa (default 0 — auto)
     * @returns {string}  e.g. "1.2K", "3.4M", "1.2B"
     */
    formatNumber(value, decimals = undefined) {
      if (value === null || value === undefined || isNaN(value)) return '—';

      const abs = Math.abs(value);
      const sign = value < 0 ? '-' : '';

      const fmt = (v, suffix) => {
        const d = decimals !== undefined ? decimals : v >= 10 ? 1 : 2;
        return `${sign}${v.toFixed(d)}${suffix}`;
      };

      if (abs >= 1e9)  return fmt(abs / 1e9, 'B');
      if (abs >= 1e6)  return fmt(abs / 1e6, 'M');
      if (abs >= 1e3)  return fmt(abs / 1e3, 'K');

      const d = decimals !== undefined ? decimals : 0;
      return `${sign}${abs.toFixed(d)}`;
    },

    /**
     * Format a timestamp as a human-readable date/time string.
     * @param {number|string|Date} timestamp
     * @param {'short'|'long'|'time'|'datetime'} format
     * @returns {string}
     */
    formatDate(timestamp, format = 'short') {
      if (!timestamp) return '—';
      const date = timestamp instanceof Date ? timestamp : new Date(timestamp);
      if (isNaN(date.getTime())) return '—';

      switch (format) {
        case 'short':
          return new Intl.DateTimeFormat('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
          }).format(date);

        case 'long':
          return new Intl.DateTimeFormat('en-US', {
            weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
          }).format(date);

        case 'time':
          return new Intl.DateTimeFormat('en-US', {
            hour: 'numeric', minute: '2-digit', hour12: true,
          }).format(date);

        case 'datetime':
          return new Intl.DateTimeFormat('en-US', {
            month:  'short',
            day:    'numeric',
            year:   'numeric',
            hour:   'numeric',
            minute: '2-digit',
            hour12: true,
          }).format(date);

        default:
          return date.toLocaleDateString();
      }
    },

    /**
     * Return the CSS class name to colour-code a numeric change.
     * @param {number} value
     * @returns {'positive'|'negative'|'neutral'}
     */
    colorForChange(value) {
      if (value === null || value === undefined || isNaN(value)) return 'neutral';
      if (value > 0) return 'positive';
      if (value < 0) return 'negative';
      return 'neutral';
    },

    /**
     * Return a human-friendly relative time string.
     * @param {number|string|Date} timestamp
     * @returns {string}  e.g. "just now", "5 minutes ago", "3 days ago"
     */
    timeAgo(timestamp) {
      if (!timestamp) return '—';
      const date  = timestamp instanceof Date ? timestamp : new Date(timestamp);
      if (isNaN(date.getTime())) return '—';

      const seconds = Math.floor((Date.now() - date.getTime()) / 1000);

      if (seconds < 10)   return 'just now';
      if (seconds < 60)   return `${seconds} seconds ago`;

      const minutes = Math.floor(seconds / 60);
      if (minutes < 60)   return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;

      const hours = Math.floor(minutes / 60);
      if (hours < 24)     return `${hours} hour${hours !== 1 ? 's' : ''} ago`;

      const days = Math.floor(hours / 24);
      if (days < 7)       return `${days} day${days !== 1 ? 's' : ''} ago`;

      const weeks = Math.floor(days / 7);
      if (weeks < 4)      return `${weeks} week${weeks !== 1 ? 's' : ''} ago`;

      const months = Math.floor(days / 30);
      if (months < 12)    return `${months} month${months !== 1 ? 's' : ''} ago`;

      const years = Math.floor(days / 365);
      return `${years} year${years !== 1 ? 's' : ''} ago`;
    },
  };

  // ---------------------------------------------------------------------------
  // 6. WebSocket — Real-time Price Updates
  // ---------------------------------------------------------------------------

  const ws = {
    socket:              null,
    _priceCallbacks:     [],
    _reconnectAttempt:   0,
    _reconnectTimer:     null,
    _subscribedSymbols:  [],
    _intentionallyClosed: false,

    /**
     * Open the WebSocket connection.
     * Automatically determines ws:// vs wss:// based on page protocol.
     */
    connect() {
      if (this.socket && (this.socket.readyState === WebSocket.OPEN ||
                          this.socket.readyState === WebSocket.CONNECTING)) {
        return;
      }

      this._intentionallyClosed = false;
      const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const host     = window.location.hostname;
      const port     = 8080;
      const url      = `${protocol}//${host}:${port}`;

      console.info('[AlphaForge WS] Connecting to', url);

      try {
        this.socket = new WebSocket(url);
      } catch (err) {
        console.error('[AlphaForge WS] Failed to create WebSocket:', err);
        this._scheduleReconnect();
        return;
      }

      this.socket.addEventListener('open', () => {
        console.info('[AlphaForge WS] Connected.');
        this._reconnectAttempt = 0;
        if (this._reconnectTimer) {
          clearTimeout(this._reconnectTimer);
          this._reconnectTimer = null;
        }
        events.emit('wsConnected', null);

        // Re-subscribe to any previously subscribed symbols
        if (this._subscribedSymbols.length > 0) {
          this._sendSubscribe(this._subscribedSymbols);
        }
      });

      this.socket.addEventListener('message', (event) => {
        let data;
        try {
          data = JSON.parse(event.data);
        } catch (_) {
          console.warn('[AlphaForge WS] Non-JSON message:', event.data);
          return;
        }

        if (data.type === 'price' || data.type === 'quote') {
          this._priceCallbacks.forEach((cb) => {
            try { cb(data.payload || data); } catch (err) {
              console.error('[AlphaForge WS] price callback error:', err);
            }
          });
          events.emit('priceUpdate', data.payload || data);
        } else if (data.type === 'ping') {
          this._safeSend({ type: 'pong' });
        } else {
          events.emit('wsMessage', data);
        }
      });

      this.socket.addEventListener('close', (event) => {
        console.warn('[AlphaForge WS] Closed. Code:', event.code, 'Reason:', event.reason);
        events.emit('wsDisconnected', { code: event.code });
        if (!this._intentionallyClosed) {
          this._scheduleReconnect();
        }
      });

      this.socket.addEventListener('error', (err) => {
        console.error('[AlphaForge WS] Error:', err);
        events.emit('wsError', err);
        // close event fires after error — reconnect handled there
      });
    },

    /** Close the WebSocket connection permanently (no auto-reconnect). */
    disconnect() {
      this._intentionallyClosed = true;
      if (this._reconnectTimer) {
        clearTimeout(this._reconnectTimer);
        this._reconnectTimer = null;
      }
      if (this.socket) {
        this.socket.close(1000, 'Client disconnecting');
        this.socket = null;
      }
    },

    /**
     * Subscribe to real-time price updates for one or more symbols.
     * @param {string|string[]} symbols  e.g. 'AAPL' or ['AAPL', 'MSFT']
     */
    subscribe(symbols) {
      const list = Array.isArray(symbols) ? symbols : [symbols];
      // Merge without duplicates
      list.forEach((s) => {
        if (!this._subscribedSymbols.includes(s)) {
          this._subscribedSymbols.push(s);
        }
      });
      this._sendSubscribe(list);
    },

    /**
     * Register a callback for incoming price update messages.
     * @param {Function} cb  Called with the price payload object
     * @returns {Function}   Unregister function
     */
    onPriceUpdate(cb) {
      this._priceCallbacks.push(cb);
      return () => {
        this._priceCallbacks = this._priceCallbacks.filter((fn) => fn !== cb);
      };
    },

    // --- Internal helpers ---

    _sendSubscribe(symbols) {
      this._safeSend({ type: 'subscribe', symbols });
    },

    _safeSend(payload) {
      if (this.socket && this.socket.readyState === WebSocket.OPEN) {
        try {
          this.socket.send(JSON.stringify(payload));
        } catch (err) {
          console.error('[AlphaForge WS] send error:', err);
        }
      }
    },

    _scheduleReconnect() {
      if (this._reconnectTimer) return; // already scheduled

      // Exponential backoff: 2s, 4s, 8s … capped at 60s
      const delay = Math.min(2000 * Math.pow(2, this._reconnectAttempt), 60000);
      this._reconnectAttempt += 1;

      console.info(`[AlphaForge WS] Reconnecting in ${delay / 1000}s (attempt ${this._reconnectAttempt})…`);

      this._reconnectTimer = setTimeout(() => {
        this._reconnectTimer = null;
        this.connect();
      }, delay);
    },
  };

  // ---------------------------------------------------------------------------
  // 7. JWT Token Refresh
  // ---------------------------------------------------------------------------

  let _refreshTimer = null;

  /**
   * Parse the exp claim from a JWT without verifying the signature.
   * @param {string} token
   * @returns {number|null}  Expiry Unix timestamp (seconds), or null if unreadable
   */
  function _getTokenExpiry(token) {
    try {
      const payload = token.split('.')[1];
      if (!payload) return null;
      const decoded = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/')));
      return decoded.exp || null;
    } catch (_) {
      return null;
    }
  }

  /**
   * Schedule a token refresh 5 minutes before the JWT expires.
   * Clears any existing scheduled refresh first.
   */
  function scheduleTokenRefresh() {
    if (_refreshTimer) {
      clearTimeout(_refreshTimer);
      _refreshTimer = null;
    }

    const token = window.AlphaForgeAPI ? window.AlphaForgeAPI.getToken() : null;
    if (!token) return;

    const expiry = _getTokenExpiry(token);
    if (!expiry) return;

    const nowSeconds    = Date.now() / 1000;
    const secondsUntilExpiry = expiry - nowSeconds;
    const refreshInSeconds   = secondsUntilExpiry - 300; // 5 minutes before expiry

    if (refreshInSeconds <= 0) {
      // Token already expired or expiring very soon — refresh now
      _doTokenRefresh();
      return;
    }

    console.info(
      `[AlphaForge Auth] Token refresh scheduled in ${Math.floor(refreshInSeconds / 60)}m ${Math.floor(refreshInSeconds % 60)}s`
    );

    _refreshTimer = setTimeout(_doTokenRefresh, refreshInSeconds * 1000);
  }

  async function _doTokenRefresh() {
    if (!window.AlphaForgeAPI) return;
    try {
      const result = await window.AlphaForgeAPI.auth.refreshToken();
      if (result && result.token) {
        window.AlphaForgeAPI.setToken(result.token);
        events.emit('tokenRefreshed', result);
        scheduleTokenRefresh(); // schedule the next refresh
      }
    } catch (err) {
      console.error('[AlphaForge Auth] Token refresh failed:', err);
      // If 401, the request handler in api.js will redirect to login
    }
  }

  // ---------------------------------------------------------------------------
  // 8. Global Search Initialisation
  // ---------------------------------------------------------------------------

  function _initGlobalSearch() {
    const searchInput = document.getElementById('global-search') ||
                        document.querySelector('[data-role="global-search"]');
    if (!searchInput) return;

    let _searchDebounce = null;
    const _resultsDropdown = document.getElementById('global-search-results') ||
                             (() => {
                               const el = document.createElement('div');
                               el.id = 'global-search-results';
                               Object.assign(el.style, {
                                 position:        'absolute',
                                 background:      '#1e293b',
                                 border:          '1px solid #334155',
                                 borderRadius:    '0.5rem',
                                 boxShadow:       '0 8px 24px rgba(0,0,0,0.4)',
                                 zIndex:          '9999',
                                 maxHeight:       '320px',
                                 overflowY:       'auto',
                                 display:         'none',
                                 minWidth:        '280px',
                               });
                               searchInput.parentNode.style.position = 'relative';
                               searchInput.parentNode.appendChild(el);
                               return el;
                             })();

    searchInput.addEventListener('input', () => {
      clearTimeout(_searchDebounce);
      const query = searchInput.value.trim();
      if (query.length < 2) {
        _resultsDropdown.style.display = 'none';
        return;
      }
      _searchDebounce = setTimeout(async () => {
        try {
          const results = await window.AlphaForgeAPI.market.search(query);
          _renderSearchResults(results, _resultsDropdown);
        } catch (err) {
          console.error('[AlphaForge Search] Error:', err);
        }
      }, 300);
    });

    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        _resultsDropdown.style.display = 'none';
        searchInput.blur();
      }
    });

    document.addEventListener('click', (e) => {
      if (!searchInput.contains(e.target) && !_resultsDropdown.contains(e.target)) {
        _resultsDropdown.style.display = 'none';
      }
    });
  }

  function _renderSearchResults(results, container) {
    container.innerHTML = '';
    const items = Array.isArray(results) ? results : (results.results || results.data || []);

    if (!items.length) {
      const empty = document.createElement('div');
      empty.textContent = 'No results found.';
      Object.assign(empty.style, { padding: '0.75rem 1rem', color: '#64748b', fontSize: '0.875rem' });
      container.appendChild(empty);
      container.style.display = 'block';
      return;
    }

    items.slice(0, 10).forEach((item) => {
      const row = document.createElement('div');
      Object.assign(row.style, {
        padding:      '0.6rem 1rem',
        cursor:       'pointer',
        display:      'flex',
        alignItems:   'center',
        gap:          '0.75rem',
        borderBottom: '1px solid #1e293b',
        transition:   'background 0.15s',
      });

      row.addEventListener('mouseenter', () => { row.style.background = '#0f172a'; });
      row.addEventListener('mouseleave', () => { row.style.background = 'transparent'; });

      const symbol = document.createElement('span');
      symbol.textContent = item.symbol || '';
      Object.assign(symbol.style, {
        fontWeight: 'bold', color: '#38bdf8', minWidth: '60px', fontSize: '0.875rem',
      });

      const name = document.createElement('span');
      name.textContent = item.name || item.description || '';
      Object.assign(name.style, { color: '#94a3b8', fontSize: '0.8rem', overflow: 'hidden',
        textOverflow: 'ellipsis', whiteSpace: 'nowrap' });

      row.appendChild(symbol);
      row.appendChild(name);

      row.addEventListener('click', () => {
        events.emit('symbolSelected', item);
        container.style.display = 'none';
        const input = document.getElementById('global-search') ||
                      document.querySelector('[data-role="global-search"]');
        if (input) input.value = item.symbol || '';
      });

      container.appendChild(row);
    });

    container.style.display = 'block';
  }

  // ---------------------------------------------------------------------------
  // 9. DOMContentLoaded Initialisation
  // ---------------------------------------------------------------------------

  async function _init() {
    console.info('[AlphaForge] Initialising…');

    // Initialise router (auth guard runs immediately)
    router.init();

    // Only proceed with authenticated setup if we have a token
    const token = window.AlphaForgeAPI ? window.AlphaForgeAPI.getToken() : null;

    if (token) {
      // Load current user profile into store
      try {
        const user = await window.AlphaForgeAPI.auth.me();
        store.update({ user });
        events.emit('userLoaded', user);
        console.info('[AlphaForge] User loaded:', user.email || user.name || user.id);
      } catch (err) {
        console.warn('[AlphaForge] Could not load user profile:', err.message);
      }

      // Schedule JWT refresh
      scheduleTokenRefresh();

      // Connect WebSocket for real-time data
      ws.connect();
    }

    // Initialise global search (available on all pages)
    _initGlobalSearch();

    events.emit('appReady', null);
    console.info('[AlphaForge] Ready.');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _init);
  } else {
    // DOM already parsed (script loaded with defer/async or at bottom of body)
    _init();
  }

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  return {
    store,
    events,
    router,
    notify,
    utils,
    ws,
    scheduleTokenRefresh,
  };
})();

// Expose globally
window.AlphaForge = AlphaForge;
