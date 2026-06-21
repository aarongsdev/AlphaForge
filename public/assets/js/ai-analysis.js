/**
 * AlphaForge — Real AI Analysis Module
 * Calls Claude API with live stock data for genuine buy/sell/hold recommendations.
 */

const AF_AI = (() => {
  const KEY_STORAGE  = 'af_claude_api_key';
  const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';

  /* ─── API Key ─────────────────────────────────────────────────── */
  function getApiKey()       { return localStorage.getItem(KEY_STORAGE) || ''; }
  function saveApiKey(k)     { localStorage.setItem(KEY_STORAGE, k.trim()); }
  function clearApiKey()     { localStorage.removeItem(KEY_STORAGE); }
  function hasApiKey()       { return !!getApiKey(); }

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
        if (json.contents) json = JSON.parse(json.contents); // allorigins wrapper
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
          previousClose:prev,
          dailyChange:  +change.toFixed(2),
          high52:       meta.fiftyTwoWeekHigh,
          low52:        meta.fiftyTwoWeekLow,
          marketCap:    meta.marketCap,
          recentCloses: closes.slice(-30),
          recentVolumes:volumes.slice(-30),
          source: 'yahoo',
        };
      } catch (e) {
        // try next proxy
      }
    }
    return null; // couldn't get real-time data
  }

  /* ─── Build Prompt ────────────────────────────────────────────── */
  function buildPrompt(symbol, stockData) {
    let dataBlock = '';

    if (stockData) {
      const closes  = stockData.recentCloses;
      const vols    = stockData.recentVolumes;
      const priceMin = Math.min(...closes).toFixed(2);
      const priceMax = Math.max(...closes).toFixed(2);
      const avgVol   = vols.length ? Math.round(vols.reduce((a,b) => a+b, 0) / vols.length) : null;
      const last10   = closes.slice(-10).map(p => p.toFixed(2)).join(', ');
      const trend30  = closes.length >= 2
        ? ((closes[closes.length-1] - closes[0]) / closes[0] * 100).toFixed(1)
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
      dataBlock = `
NOTE: Real-time price data could not be fetched. Use your training knowledge about ${symbol}
to provide the best analysis possible, noting that prices may have changed.
`;
    }

    return `You are a senior quantitative analyst at a top-tier hedge fund. Provide a concise but rigorous trading signal for ${symbol}.
${dataBlock}
Analyze from three angles:
1. TECHNICAL — price action, momentum, trend, key levels
2. FUNDAMENTAL — business quality, valuation, growth drivers
3. SENTIMENT — market positioning, news flow, macro context

Respond ONLY with a valid JSON object — no markdown, no extra text:
{
  "recommendation": "BUY" | "SELL" | "HOLD",
  "confidence": <integer 0-100>,
  "composite_score": <integer 0-100>,
  "technical_score":   <integer 0-100>,
  "fundamental_score": <integer 0-100>,
  "sentiment_score":   <integer 0-100>,
  "momentum_score":    <integer 0-100>,
  "reasoning": "<2-3 sentences — be specific and data-driven>",
  "risks": ["<risk 1>", "<risk 2>", "<risk 3>", "<risk 4>"],
  "entry_price": <number | null>,
  "stop_loss":   <number | null>,
  "target_conservative": <number | null>,
  "target_base":         <number | null>,
  "target_optimistic":   <number | null>,
  "time_horizon": "<e.g. 1-3 months>",
  "summary": "<one paragraph executive summary for a professional investor>"
}`;
  }

  /* ─── Call Claude API ─────────────────────────────────────────── */
  async function callClaude(prompt, apiKey) {
    const res = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-api-key': apiKey,
        'anthropic-version': '2023-06-01',
        'anthropic-dangerous-direct-browser-access': 'true',
      },
      body: JSON.stringify({
        model: CLAUDE_MODEL,
        max_tokens: 1200,
        messages: [{ role: 'user', content: prompt }],
      }),
    });

    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      const msg = err.error?.message || `HTTP ${res.status}`;
      throw new Error(msg);
    }

    const data = await res.json();
    const text = data.content?.[0]?.text || '';
    const match = text.match(/\{[\s\S]*\}/);
    if (!match) throw new Error('La IA devolvió un formato inesperado.');
    return JSON.parse(match[0]);
  }

  /* ─── Main: analyze a symbol ──────────────────────────────────── */
  async function analyze(symbol, apiKey) {
    apiKey = apiKey || getApiKey();
    if (!apiKey) throw new Error('NO_KEY');

    const stockData = await fetchStockData(symbol);
    const prompt    = buildPrompt(symbol, stockData);
    const analysis  = await callClaude(prompt, apiKey);

    return { analysis, stockData };
  }

  /* ─── Public API ──────────────────────────────────────────────── */
  return { getApiKey, saveApiKey, clearApiKey, hasApiKey, analyze, fetchStockData };
})();
