/**
 * AlphaForge — Real AI Analysis Module
 * Single-symbol deep analysis + bulk market scan via Claude API.
 */

const AF_AI = (() => {
  const KEY_STORAGE  = 'af_claude_api_key';
  const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';

  /* ─── API Key ─────────────────────────────────────────────────── */
  function getApiKey()   { return localStorage.getItem(KEY_STORAGE) || ''; }
  function saveApiKey(k) { localStorage.setItem(KEY_STORAGE, k.trim()); }
  function clearApiKey() { localStorage.removeItem(KEY_STORAGE); }
  function hasApiKey()   { return !!getApiKey(); }

  /* ─── Stock Data via Yahoo Finance (CORS proxies) ─────────────── */
  async function fetchStockData(symbol) {
    const yfUrl = `https://query1.finance.yahoo.com/v8/finance/chart/${encodeURIComponent(symbol)}?interval=1d&range=3mo&includePrePost=false`;
    const proxies = [
      `https://corsproxy.io/?${encodeURIComponent(yfUrl)}`,
      `https://api.allorigins.win/get?url=${encodeURIComponent(yfUrl)}`,
    ];

    for (const proxyUrl of proxies) {
      try {
        const res = await fetch(proxyUrl, { signal: AbortSignal.timeout(6000) });
        if (!res.ok) continue;
        let json = await res.json();
        if (json.contents) json = JSON.parse(json.contents);
        const result = json.chart?.result?.[0];
        if (!result) continue;

        const meta    = result.meta || {};
        const quote   = result.indicators?.quote?.[0] || {};
        const closes  = (quote.close  || []).filter(v => v != null);
        const volumes = (quote.volume || []).filter(v => v != null);
        if (!closes.length) continue;

        const current = meta.regularMarketPrice ?? closes[closes.length - 1];
        const prev    = meta.previousClose ?? closes[closes.length - 2] ?? current;
        const change  = prev ? ((current - prev) / prev * 100) : 0;

        return {
          symbol:       meta.symbol  || symbol,
          name:         meta.longName || meta.shortName || symbol,
          currency:     meta.currency || 'USD',
          currentPrice: current,
          previousClose: prev,
          dailyChange:  +change.toFixed(2),
          high52:       meta.fiftyTwoWeekHigh,
          low52:        meta.fiftyTwoWeekLow,
          marketCap:    meta.marketCap,
          recentCloses: closes.slice(-30),
          recentVolumes: volumes.slice(-30),
          source: 'yahoo',
        };
      } catch (e) { /* try next proxy */ }
    }
    return null;
  }

  /* ─── Build single-symbol deep prompt ────────────────────────── */
  function buildPrompt(symbol, stockData) {
    let dataBlock = '';
    if (stockData) {
      const closes   = stockData.recentCloses;
      const vols     = stockData.recentVolumes;
      const priceMin = Math.min(...closes).toFixed(2);
      const priceMax = Math.max(...closes).toFixed(2);
      const avgVol   = vols.length ? Math.round(vols.reduce((a, b) => a + b, 0) / vols.length) : null;
      const last10   = closes.slice(-10).map(p => p.toFixed(2)).join(', ');
      const trend30  = closes.length >= 2
        ? ((closes[closes.length - 1] - closes[0]) / closes[0] * 100).toFixed(1)
        : null;

      dataBlock = `
LIVE MARKET DATA (from Yahoo Finance, today):
- Symbol: ${stockData.symbol}
- Current Price: ${stockData.currency} ${stockData.currentPrice?.toFixed(2)}
- Daily Change: ${stockData.dailyChange > 0 ? '+' : ''}${stockData.dailyChange}%
- 52-Week High: ${stockData.high52?.toFixed(2) ?? 'N/A'}
- 52-Week Low:  ${stockData.low52?.toFixed(2) ?? 'N/A'}
- 30-day Range: ${priceMin} – ${priceMax}
- 30-day Trend: ${trend30 !== null ? (trend30 > 0 ? '+' : '') + trend30 + '%' : 'N/A'}
- Last 10 closes: ${last10}
${avgVol ? `- Avg Daily Volume (30d): ${avgVol.toLocaleString()}` : ''}
`;
    } else {
      dataBlock = `\nNOTE: Real-time price data unavailable. Use your training knowledge about ${symbol}.\n`;
    }

    return `You are a senior quantitative analyst at a top-tier hedge fund. Provide a rigorous trading signal for ${symbol}.
${dataBlock}
Analyze: 1) TECHNICAL — price action, momentum, trend  2) FUNDAMENTAL — valuation, growth  3) SENTIMENT — macro, news flow

Respond ONLY with a valid JSON object — no markdown:
{
  "recommendation": "BUY" | "SELL" | "HOLD",
  "confidence": <0-100>,
  "composite_score": <0-100>,
  "technical_score": <0-100>,
  "fundamental_score": <0-100>,
  "sentiment_score": <0-100>,
  "momentum_score": <0-100>,
  "reasoning": "<2-3 sentences, specific and data-driven>",
  "risks": ["<risk 1>", "<risk 2>", "<risk 3>", "<risk 4>"],
  "entry_price": <number|null>,
  "stop_loss": <number|null>,
  "target_conservative": <number|null>,
  "target_base": <number|null>,
  "target_optimistic": <number|null>,
  "time_horizon": "<e.g. 1-3 months>",
  "summary": "<one paragraph executive summary>"
}`;
  }

  /* ─── Low-level Claude call ───────────────────────────────────── */
  async function _callClaude(body, apiKey) {
    const res = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-api-key': apiKey,
        'anthropic-version': '2023-06-01',
        'anthropic-dangerous-direct-browser-access': 'true',
      },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.error?.message || `HTTP ${res.status}`);
    }
    return res.json();
  }

  /* ─── Single-symbol deep analysis ────────────────────────────── */
  async function analyze(symbol, apiKey) {
    apiKey = apiKey || getApiKey();
    if (!apiKey) throw new Error('NO_KEY');

    const stockData = await fetchStockData(symbol);
    const prompt    = buildPrompt(symbol, stockData);
    const data      = await _callClaude({
      model: CLAUDE_MODEL,
      max_tokens: 1400,
      messages: [{ role: 'user', content: prompt }],
    }, apiKey);

    const text  = data.content?.[0]?.text || '';
    const match = text.match(/\{[\s\S]*\}/);
    if (!match) throw new Error('La IA devolvió un formato inesperado.');
    return { analysis: JSON.parse(match[0]), stockData };
  }

  /* ─── Bulk market scan: all assets in ONE Claude call ─────────── */
  async function analyzeBulk(assets, apiKey, onProgress) {
    apiKey = apiKey || getApiKey();
    if (!apiKey) throw new Error('NO_KEY');

    const list = assets.map(a => {
      const sym  = a.sym  || a;
      const name = a.name || sym;
      const cat  = a.assetType || '';
      return `${sym} (${name}${cat ? ', ' + cat : ''})`;
    }).join('\n');

    const total = assets.length;

    const prompt = `You are a senior quantitative analyst at a top-tier hedge fund with access to current market data as of your knowledge cutoff. Analyze ALL ${total} assets below and provide concise but rigorous trading signals for each one.

ASSETS TO ANALYZE:
${list}

For each asset provide:
- BUY if you expect meaningful upside in the time horizon
- SELL if you expect meaningful downside or deteriorating fundamentals
- HOLD if risk/reward is balanced or uncertain

Consider: technical momentum, fundamental valuation, sector trends, macro environment, and recent news flow for each asset.

Respond ONLY with a valid JSON array containing ALL ${total} assets — no markdown, no extra text, no omissions:
[
  {
    "symbol": "<TICKER as given>",
    "recommendation": "BUY" | "SELL" | "HOLD",
    "confidence": <integer 0-100>,
    "composite_score": <integer 0-100>,
    "technical_score": <integer 0-100>,
    "fundamental_score": <integer 0-100>,
    "sentiment_score": <integer 0-100>,
    "momentum_score": <integer 0-100>,
    "reasoning": "<2 specific sentences with actual data points>",
    "entry_price": <approximate current market price as number, or null>,
    "stop_loss": <suggested stop loss as number, or null>,
    "target_conservative": <conservative 3-month target as number, or null>,
    "target_base": <base case 3-month target as number, or null>,
    "target_optimistic": <optimistic 3-month target as number, or null>,
    "time_horizon": "<e.g. 1-3 months>"
  }
]`;

    if (onProgress) onProgress(0, total, 'Consultando a Claude...');

    const data  = await _callClaude({
      model: CLAUDE_MODEL,
      max_tokens: 12000,
      messages: [{ role: 'user', content: prompt }],
    }, apiKey);

    if (onProgress) onProgress(total, total, 'Procesando respuesta...');

    const text  = data.content?.[0]?.text || '';
    const match = text.match(/\[[\s\S]*\]/);
    if (!match) throw new Error('Formato inesperado en la respuesta de IA.');
    return JSON.parse(match[0]);
  }

  /* ─── Public API ──────────────────────────────────────────────── */
  return { getApiKey, saveApiKey, clearApiKey, hasApiKey, analyze, analyzeBulk, fetchStockData };
})();
