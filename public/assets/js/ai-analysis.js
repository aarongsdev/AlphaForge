/**
 * AlphaForge — AI Analysis Module
 * Claude API (browser-direct) + localStorage signal cache.
 */

const AF_AI = (() => {
  const KEY_STORAGE    = 'af_claude_api_key';
  const SIGNAL_CACHE   = 'af_signal_cache';
  const SIGNAL_TTL     = 4 * 60 * 60 * 1000; // 4 hours
  const CLAUDE_MODEL   = 'claude-haiku-4-5-20251001';
  const BATCH_SIZE     = 25;   // assets per Claude call
  const BATCH_TOKENS   = 6000; // safe for all haiku versions (max output 8192)

  /* ─── API Key ─────────────────────────────────────────────── */
  function getApiKey()   { return localStorage.getItem(KEY_STORAGE) || ''; }
  function saveApiKey(k) { localStorage.setItem(KEY_STORAGE, k.trim()); }
  function clearApiKey() { localStorage.removeItem(KEY_STORAGE); }
  function hasApiKey()   { return !!getApiKey(); }

  /* ─── Signal cache ────────────────────────────────────────── */
  function getCachedSignals() {
    try {
      const c = JSON.parse(localStorage.getItem(SIGNAL_CACHE) || 'null');
      if (!c || !c.ts || !c.signals) return null;
      if (Date.now() - c.ts > SIGNAL_TTL) return null;
      return c.signals;
    } catch { return null; }
  }

  function setCachedSignals(signals) {
    try {
      localStorage.setItem(SIGNAL_CACHE, JSON.stringify({ ts: Date.now(), signals }));
    } catch { /* storage full — ignore */ }
  }

  function getCacheAge() {
    try {
      const c = JSON.parse(localStorage.getItem(SIGNAL_CACHE) || 'null');
      if (!c || !c.ts) return null;
      return Math.round((Date.now() - c.ts) / 60000); // minutes
    } catch { return null; }
  }

  /* ─── Robust JSON extractor ───────────────────────────────── */
  function extractJSON(text, wantArray) {
    const open  = wantArray ? '[' : '{';
    const close = wantArray ? ']' : '}';
    const start = text.indexOf(open);
    if (start === -1) return null;

    let depth = 0, end = -1;
    for (let i = start; i < text.length; i++) {
      if (text[i] === open)  depth++;
      if (text[i] === close) depth--;
      if (depth === 0) { end = i; break; }
    }
    if (end === -1) return null;

    const raw = text.slice(start, end + 1);
    try {
      return JSON.parse(raw);
    } catch {
      // Fix trailing commas and unquoted keys
      const fixed = raw
        .replace(/,\s*([}\]])/g, '$1')
        .replace(/([{,]\s*)([a-zA-Z_]\w*)\s*:/g, '$1"$2":');
      try { return JSON.parse(fixed); } catch { return null; }
    }
  }

  /* ─── Stock data from Yahoo Finance via CORS proxies ──────── */
  async function fetchStockData(symbol) {
    const yfUrl = `https://query1.finance.yahoo.com/v8/finance/chart/${encodeURIComponent(symbol)}?interval=1d&range=3mo&includePrePost=false`;
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
        const result = json.chart?.result?.[0];
        if (!result) continue;

        const meta    = result.meta || {};
        const quote   = result.indicators?.quote?.[0] || {};
        const closes  = (quote.close  || []).filter(v => v != null);
        const volumes = (quote.volume || []).filter(v => v != null);
        if (!closes.length) continue;

        const current = meta.regularMarketPrice ?? closes.at(-1);
        const prev    = meta.chartPreviousClose ?? meta.previousClose ?? closes.at(-2) ?? current;
        const change  = prev ? +((current - prev) / prev * 100).toFixed(2) : 0;

        return {
          symbol:        meta.symbol || symbol,
          name:          meta.longName || meta.shortName || symbol,
          currency:      meta.currency || 'USD',
          currentPrice:  current,
          previousClose: prev,
          dailyChange:   change,
          high52:        meta.fiftyTwoWeekHigh,
          low52:         meta.fiftyTwoWeekLow,
          recentCloses:  closes.slice(-30),
          recentVolumes: volumes.slice(-30),
        };
      } catch { /* try next proxy */ }
    }
    return null; // Claude will use training knowledge
  }

  /* ─── Prompt for single-symbol deep analysis ──────────────── */
  function buildPrompt(symbol, stockData) {
    let data = '';
    if (stockData) {
      const c   = stockData.recentCloses;
      const min = Math.min(...c).toFixed(2);
      const max = Math.max(...c).toFixed(2);
      const trend = c.length >= 2 ? ((c.at(-1) - c[0]) / c[0] * 100).toFixed(1) : null;
      data = `
LIVE DATA (Yahoo Finance):
- Price: ${stockData.currency} ${stockData.currentPrice?.toFixed(2)}  Daily: ${stockData.dailyChange > 0 ? '+' : ''}${stockData.dailyChange}%
- 52w High/Low: ${stockData.high52?.toFixed(2)} / ${stockData.low52?.toFixed(2)}
- 30d Range: ${min}–${max}  Trend: ${trend !== null ? (trend > 0 ? '+' : '') + trend + '%' : 'N/A'}
- Last 10 closes: ${c.slice(-10).map(p => p.toFixed(2)).join(', ')}`;
    } else {
      data = `\nNo live data — use your training knowledge about ${symbol}.`;
    }

    return `Senior quant analyst. Trading signal for ${symbol}.
${data}

Analyze: Technical momentum, Fundamental valuation, Market sentiment.

JSON only — no markdown:
{"recommendation":"BUY"|"SELL"|"HOLD","confidence":<0-100>,"composite_score":<0-100>,"technical_score":<0-100>,"fundamental_score":<0-100>,"sentiment_score":<0-100>,"momentum_score":<0-100>,"reasoning":"<2-3 specific sentences>","risks":["<r1>","<r2>","<r3>","<r4>"],"entry_price":<n|null>,"stop_loss":<n|null>,"target_conservative":<n|null>,"target_base":<n|null>,"target_optimistic":<n|null>,"time_horizon":"<e.g. 1-3 months>","summary":"<executive summary paragraph>"}`;
  }

  /* ─── Raw Claude call ─────────────────────────────────────── */
  async function _claude(body) {
    const apiKey = getApiKey();
    if (!apiKey) throw new Error('NO_KEY');

    const res = await fetch('https://api.anthropic.com/v1/messages', {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-api-key':    apiKey,
        'anthropic-version': '2023-06-01',
        'anthropic-dangerous-direct-browser-access': 'true',
      },
      body: JSON.stringify(body),
    });

    if (!res.ok) {
      const e = await res.json().catch(() => ({}));
      throw new Error(e.error?.message || `HTTP ${res.status} — ${res.statusText}`);
    }
    return res.json();
  }

  /* ─── Single-symbol deep analysis ────────────────────────── */
  async function analyze(symbol) {
    if (!hasApiKey()) throw new Error('NO_KEY');

    const stockData = await fetchStockData(symbol);
    const prompt    = buildPrompt(symbol, stockData);
    const data      = await _claude({ model: CLAUDE_MODEL, max_tokens: 1400,
      messages: [{ role: 'user', content: prompt }] });

    const text   = data.content?.[0]?.text || '';
    const result = extractJSON(text, false);
    if (!result) throw new Error('La IA devolvió un formato inesperado. Inténtalo de nuevo.');
    return { analysis: result, stockData };
  }

  /* ─── Bulk market scan — splits into safe batches ─────────── */
  async function analyzeBulk(assets, onProgress) {
    if (!hasApiKey()) throw new Error('NO_KEY');

    const batches = [];
    for (let i = 0; i < assets.length; i += BATCH_SIZE) {
      batches.push(assets.slice(i, i + BATCH_SIZE));
    }

    const allResults = [];
    let done = 0;

    for (let b = 0; b < batches.length; b++) {
      const batch = batches[b];
      const batchNum = b + 1;
      if (onProgress) onProgress(
        Math.round(done / assets.length * 80),
        `Analizando batch ${batchNum}/${batches.length} (${batch.length} activos)...`
      );

      const list = batch.map(a =>
        `${a.sym} (${a.name}, ${a.assetType})`
      ).join('\n');

      const prompt = `Senior quant analyst. Analyze ALL ${batch.length} assets below. Use your knowledge as of your training cutoff.

ASSETS:
${list}

For each: BUY (meaningful upside), SELL (meaningful downside), HOLD (balanced/uncertain).
Include realistic current price estimates based on your knowledge.

JSON array only — no markdown, include ALL ${batch.length} assets:
[{"symbol":"<TICKER>","recommendation":"BUY"|"SELL"|"HOLD","confidence":<0-100>,"composite_score":<0-100>,"technical_score":<0-100>,"fundamental_score":<0-100>,"sentiment_score":<0-100>,"momentum_score":<0-100>,"reasoning":"<2 specific sentences>","entry_price":<approx current price|null>,"stop_loss":<n|null>,"target_conservative":<n|null>,"target_base":<n|null>,"target_optimistic":<n|null>,"time_horizon":"<1-3 months>"}]`;

      try {
        const data = await _claude({ model: CLAUDE_MODEL, max_tokens: BATCH_TOKENS,
          messages: [{ role: 'user', content: prompt }] });

        const text    = data.content?.[0]?.text || '';
        const results = extractJSON(text, true);

        if (!results || !Array.isArray(results)) {
          console.warn(`Batch ${batchNum}: respuesta inválida — saltando`);
          // Add fallback placeholders so we don't skip the batch silently
          batch.forEach(a => allResults.push({ symbol: a.sym, _failed: true }));
        } else {
          allResults.push(...results);
        }
      } catch (e) {
        console.error(`Batch ${batchNum} error:`, e.message);
        if (e.message === 'NO_KEY') throw e; // propagate key errors
        batch.forEach(a => allResults.push({ symbol: a.sym, _failed: true }));
      }

      done += batch.length;
      if (onProgress) onProgress(
        Math.round(done / assets.length * 80),
        `Batch ${batchNum}/${batches.length} completado`
      );

      // Small pause between batches to avoid rate limits
      if (b < batches.length - 1) await new Promise(r => setTimeout(r, 800));
    }

    if (onProgress) onProgress(90, 'Guardando señales...');

    // Cache the results
    setCachedSignals(allResults);
    return allResults;
  }

  /* ─── Public API ──────────────────────────────────────────── */
  return {
    getApiKey, saveApiKey, clearApiKey, hasApiKey,
    analyze, analyzeBulk, fetchStockData,
    getCachedSignals, getCacheAge, setCachedSignals,
  };
})();
