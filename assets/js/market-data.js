/**
 * AlphaForge — Market Data Module
 * CoinGecko (crypto, free, no key, CORS-safe) + Yahoo Finance via proxy (indices)
 * localStorage cache with 5-minute TTL
 */

const AF_MD = (() => {
  const CACHE_KEY = 'af_market_prices';
  const CACHE_TTL = 5 * 60 * 1000; // 5 minutes

  const CRYPTO_IDS = {
    BTC:   'bitcoin',
    ETH:   'ethereum',
    SOL:   'solana',
    BNB:   'binancecoin',
    XRP:   'ripple',
    ADA:   'cardano',
    DOGE:  'dogecoin',
    AVAX:  'avalanche-2',
    LINK:  'chainlink',
    DOT:   'polkadot',
    MATIC: 'matic-network',
    UNI:   'uniswap',
  };

  /* ─── Cache helpers ───────────────────────────────────────────── */
  function getCache() {
    try {
      const c = JSON.parse(localStorage.getItem(CACHE_KEY) || 'null');
      if (!c || Date.now() - c.ts > CACHE_TTL) return null;
      return c.data;
    } catch { return null; }
  }

  function setCache(data) {
    try {
      localStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), data }));
    } catch { /* storage full — ignore */ }
  }

  /* ─── CoinGecko (native CORS, no API key) ────────────────────── */
  async function fetchCryptoPrices() {
    const ids = Object.values(CRYPTO_IDS).join(',');
    const url = `https://api.coingecko.com/api/v3/simple/price?ids=${ids}&vs_currencies=usd&include_24hr_change=true`;
    try {
      const res = await fetch(url, { signal: AbortSignal.timeout(9000) });
      if (!res.ok) return {};
      const json = await res.json();
      const result = {};
      for (const [sym, id] of Object.entries(CRYPTO_IDS)) {
        if (json[id]) {
          result[sym] = {
            price:     json[id].usd,
            change24h: json[id].usd_24h_change ?? 0,
          };
        }
      }
      return result;
    } catch { return {}; }
  }

  /* ─── Yahoo Finance via CORS proxies (indices) ────────────────── */
  async function fetchYahoo(yfSymbol) {
    const yfUrl = `https://query1.finance.yahoo.com/v8/finance/chart/${encodeURIComponent(yfSymbol)}?interval=1d&range=1d`;
    const proxies = [
      `https://corsproxy.io/?${encodeURIComponent(yfUrl)}`,
      `https://api.allorigins.win/get?url=${encodeURIComponent(yfUrl)}`,
      `https://thingproxy.freeboard.io/fetch/${yfUrl}`,
    ];

    for (const proxy of proxies) {
      try {
        const res = await fetch(proxy, { signal: AbortSignal.timeout(7000) });
        if (!res.ok) continue;
        let json = await res.json();
        if (json.contents) json = JSON.parse(json.contents);
        const meta = json.chart?.result?.[0]?.meta;
        if (!meta || !meta.regularMarketPrice) continue;
        const price = meta.regularMarketPrice;
        const prev  = meta.chartPreviousClose ?? meta.previousClose ?? price;
        return {
          price,
          change24h: prev ? ((price - prev) / prev * 100) : 0,
        };
      } catch { /* try next proxy */ }
    }
    return null;
  }

  /* ─── Fetch everything in parallel ───────────────────────────── */
  async function fetchAll() {
    const cached = getCache();
    if (cached) return cached;

    const [crypto, sp500Raw, nasdaqRaw] = await Promise.all([
      fetchCryptoPrices(),
      fetchYahoo('^GSPC'),
      fetchYahoo('^IXIC'),
    ]);

    const data = {
      crypto,
      indices: {
        SP500:  sp500Raw  || null,
        NASDAQ: nasdaqRaw || null,
      },
    };

    setCache(data);
    return data;
  }

  /* ─── DOM helpers ─────────────────────────────────────────────── */
  function fmtIndex(n) {
    if (n == null) return '—';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fmtCrypto(n) {
    if (n == null) return '—';
    if (n >= 1000) return '$' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
  }

  function fmtChange(n) {
    if (n == null) return '';
    return (n >= 0 ? '+' : '') + n.toFixed(2) + '%';
  }

  function setEl(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function setChangeCard(id, change) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = (change >= 0 ? '▲ ' : '▼ ') + fmtChange(change);
    el.className = 'card-change ' + (change >= 0 ? 'positive' : 'negative');
  }

  function updateTickerChip(id, priceText, change) {
    const el = document.getElementById(id);
    if (!el) return;
    const priceEl  = el.querySelector('.t-price');
    const changeEl = el.querySelector('.t-chg');
    if (priceEl)  priceEl.textContent = priceText;
    if (changeEl) {
      changeEl.textContent = fmtChange(change);
      changeEl.style.color = change >= 0 ? '#10b981' : '#ef4444';
    }
  }

  function applyToPage(data) {
    const sp500 = data.indices?.SP500;
    if (sp500) {
      setEl('sp500-price', fmtIndex(sp500.price));
      setChangeCard('sp500-change', sp500.change24h);
      updateTickerChip('ticker-sp500', fmtIndex(sp500.price), sp500.change24h);
    }

    const nasdaq = data.indices?.NASDAQ;
    if (nasdaq) {
      setEl('nasdaq-price', fmtIndex(nasdaq.price));
      setChangeCard('nasdaq-change', nasdaq.change24h);
      updateTickerChip('ticker-nasdaq', fmtIndex(nasdaq.price), nasdaq.change24h);
    }

    const btc = data.crypto?.BTC;
    if (btc) {
      setEl('btc-price', fmtCrypto(btc.price));
      setChangeCard('btc-change', btc.change24h);
      updateTickerChip('ticker-btc', fmtCrypto(btc.price).replace('$', ''), btc.change24h);
    }
  }

  /* ─── Public init — fetch + apply, then refresh every 60 s ───── */
  async function init() {
    try {
      const data = await fetchAll();
      applyToPage(data);
    } catch (e) {
      console.warn('[AF_MD] init error:', e.message);
    }
    setInterval(async () => {
      try {
        // Force a fresh fetch by clearing cache first
        try { localStorage.removeItem(CACHE_KEY); } catch { /* ignore */ }
        const data = await fetchAll();
        applyToPage(data);
      } catch { /* ignore */ }
    }, 60 * 1000);
  }

  return {
    init, fetchAll, fetchCryptoPrices, fetchYahoo,
    getCache, CRYPTO_IDS,
  };
})();
