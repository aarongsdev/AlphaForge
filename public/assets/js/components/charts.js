/**
 * AlphaForge Charts Module
 * Chart.js configuration and chart builder functions.
 * Depends on Chart.js loaded globally via CDN.
 *
 * Usage: Charts.createSparkline(...), Charts.createLine(...), etc.
 */

const Charts = (() => {
  'use strict';

  // ---------------------------------------------------------------------------
  // Global Chart.js defaults — dark theme
  // ---------------------------------------------------------------------------

  if (typeof Chart !== 'undefined') {
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.animation = { duration: 200 };

    Chart.defaults.scale.grid = {
      color: 'rgba(30, 45, 77, 0.8)',
      borderColor: 'rgba(30, 45, 77, 0.8)',
    };

    Chart.defaults.scale.ticks = {
      color: '#64748b',
      font: { family: 'JetBrains Mono, monospace', size: 11 },
    };

    Chart.defaults.plugins.legend.labels.color = '#94a3b8';
    Chart.defaults.plugins.legend.labels.font = { family: 'Inter, sans-serif', size: 12 };

    Chart.defaults.plugins.tooltip.backgroundColor = '#0f1629';
    Chart.defaults.plugins.tooltip.borderColor = '#1e2d4d';
    Chart.defaults.plugins.tooltip.borderWidth = 1;
    Chart.defaults.plugins.tooltip.titleColor = '#e2e8f0';
    Chart.defaults.plugins.tooltip.bodyColor = '#e2e8f0';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 6;
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  /**
   * Retrieve a canvas element and its 2D context, destroying any existing
   * Chart.js instance attached to it first.
   * @param {string} canvasId
   * @returns {{ canvas: HTMLCanvasElement, ctx: CanvasRenderingContext2D } | null}
   */
  function _getCanvas(canvasId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
      console.warn(`[Charts] Canvas not found: #${canvasId}`);
      return null;
    }

    // Destroy existing Chart.js instance to avoid "Canvas is already in use" error
    const existing = Chart.getChart(canvas);
    if (existing) existing.destroy();

    const ctx = canvas.getContext('2d');
    return { canvas, ctx };
  }

  /**
   * Build a vertical gradient fill for a line chart dataset.
   * @param {CanvasRenderingContext2D} ctx
   * @param {HTMLCanvasElement} canvas
   * @param {string} color  hex/rgb colour of the line
   * @returns {CanvasGradient}
   */
  function _buildGradient(ctx, canvas, color) {
    const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    gradient.addColorStop(0, `${color}55`);   // ~33% opacity at top
    gradient.addColorStop(1, `${color}00`);   // transparent at bottom
    return gradient;
  }

  /**
   * Format a number as a currency string (USD).
   * @param {number} v
   * @returns {string}
   */
  function _fmtCurrency(v) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 }).format(v);
  }

  /**
   * Format a number as a percentage string.
   * @param {number} v
   * @returns {string}
   */
  function _fmtPercent(v) {
    return `${v >= 0 ? '+' : ''}${v.toFixed(2)}%`;
  }

  // ---------------------------------------------------------------------------
  // createSparkline
  // ---------------------------------------------------------------------------

  /**
   * Render a mini 30-day trend sparkline with no axes, no labels, gradient fill.
   * @param {string}   canvasId
   * @param {number[]} data      Array of numeric values
   * @param {string}   [color]   Line colour (default #2563eb)
   * @param {boolean}  [positive] Unused — colour is determined by `color` param; kept for API compat
   * @returns {Chart}
   */
  function createSparkline(canvasId, data, color = '#2563eb', positive = true) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { canvas, ctx } = target;

    const gradient = _buildGradient(ctx, canvas, color);

    return new Chart(ctx, {
      type: 'line',
      data: {
        labels: data.map((_, i) => i),
        datasets: [{
          data,
          borderColor: color,
          borderWidth: 1.5,
          fill: true,
          backgroundColor: gradient,
          pointRadius: 0,
          pointHoverRadius: 0,
          tension: 0.4,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 0 },
        plugins: {
          legend: { display: false },
          tooltip: { enabled: false },
        },
        scales: {
          x: { display: false },
          y: { display: false },
        },
        elements: {
          line: { borderCapStyle: 'round' },
        },
      },
    });
  }

  // ---------------------------------------------------------------------------
  // createOHLC
  // ---------------------------------------------------------------------------

  /**
   * Render an OHLC / candlestick-style chart using Chart.js floating bars.
   * Green = close > open (bullish), Red = close <= open (bearish).
   * @param {string} canvasId
   * @param {Array<{date:string, open:number, high:number, low:number, close:number, volume:number}>} ohlcvData
   * @returns {Chart}
   */
  function createOHLC(canvasId, ohlcvData) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { ctx } = target;

    const labels   = ohlcvData.map((d) => d.date);
    const bodyData = ohlcvData.map((d) => ({ x: d.date, y: [d.open, d.close] }));
    const wickData = ohlcvData.map((d) => ({ x: d.date, y: [d.low, d.high] }));

    const bullColor = '#10b981';
    const bearColor = '#ef4444';

    const bodyColors = ohlcvData.map((d) => (d.close >= d.open ? bullColor : bearColor));
    const wickColors = ohlcvData.map((d) => (d.close >= d.open ? `${bullColor}99` : `${bearColor}99`));

    return new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Wick',
            data: wickData,
            backgroundColor: wickColors,
            borderColor: wickColors,
            borderWidth: 1,
            barPercentage: 0.15,
            categoryPercentage: 0.5,
            order: 2,
          },
          {
            label: 'Body',
            data: bodyData,
            backgroundColor: bodyColors,
            borderColor: bodyColors,
            borderWidth: 1,
            barPercentage: 0.6,
            categoryPercentage: 0.5,
            order: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 200 },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (item) => {
                const d = ohlcvData[item.dataIndex];
                if (!d) return '';
                return [
                  `Open:   ${d.open.toFixed(2)}`,
                  `High:   ${d.high.toFixed(2)}`,
                  `Low:    ${d.low.toFixed(2)}`,
                  `Close:  ${d.close.toFixed(2)}`,
                  `Volume: ${d.volume ? d.volume.toLocaleString() : 'N/A'}`,
                ];
              },
              title: (items) => ohlcvData[items[0]?.dataIndex]?.date || '',
            },
          },
        },
        scales: {
          x: {
            stacked: false,
            ticks: { maxRotation: 45, maxTicksLimit: 12 },
          },
          y: {
            ticks: {
              callback: (v) => v.toFixed(2),
            },
          },
        },
      },
    });
  }

  // ---------------------------------------------------------------------------
  // createRSI
  // ---------------------------------------------------------------------------

  /**
   * RSI line chart with overbought/oversold zones and reference lines at 30/70.
   * @param {string} canvasId
   * @param {Array<{date:string, value:number}>} rsiData
   * @returns {Chart}
   */
  function createRSI(canvasId, rsiData) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { canvas, ctx } = target;

    const labels = rsiData.map((d) => d.date);
    const values = rsiData.map((d) => d.value);

    // Segment colour: green when > 50, red when < 50
    const segmentPlugin = {
      id: 'rsiSegmentColour',
    };

    // Overbought gradient (>70) — semi-transparent red fill
    const obGradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    obGradient.addColorStop(0, 'rgba(239,68,68,0.25)');
    obGradient.addColorStop(1, 'rgba(239,68,68,0)');

    // Oversold gradient (<30) — semi-transparent green fill
    const osGradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    osGradient.addColorStop(0, 'rgba(16,185,129,0)');
    osGradient.addColorStop(1, 'rgba(16,185,129,0.25)');

    return new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'RSI',
            data: values,
            borderColor: values.map((v) => (v >= 50 ? '#10b981' : '#ef4444')),
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 4,
            fill: false,
            tension: 0.3,
            segment: {
              borderColor: (ctx) => (ctx.p1.parsed.y >= 50 ? '#10b981' : '#ef4444'),
            },
          },
          // Overbought fill zone (70-100)
          {
            label: 'Overbought',
            data: labels.map(() => 70),
            borderColor: 'rgba(239,68,68,0.3)',
            borderWidth: 1,
            borderDash: [4, 4],
            pointRadius: 0,
            fill: { target: { value: 100 }, above: 'rgba(239,68,68,0.12)', below: 'transparent' },
            tension: 0,
          },
          // Oversold fill zone (0-30)
          {
            label: 'Oversold',
            data: labels.map(() => 30),
            borderColor: 'rgba(16,185,129,0.3)',
            borderWidth: 1,
            borderDash: [4, 4],
            pointRadius: 0,
            fill: { target: { value: 0 }, above: 'transparent', below: 'rgba(16,185,129,0.12)' },
            tension: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 200 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (item) => {
                if (item.datasetIndex !== 0) return null;
                return `RSI: ${item.parsed.y.toFixed(2)}`;
              },
              filter: (item) => item.datasetIndex === 0,
            },
          },
          annotation: {
            annotations: {
              line70: {
                type: 'line',
                yMin: 70,
                yMax: 70,
                borderColor: 'rgba(239,68,68,0.5)',
                borderWidth: 1,
                borderDash: [4, 4],
              },
              line30: {
                type: 'line',
                yMin: 30,
                yMax: 30,
                borderColor: 'rgba(16,185,129,0.5)',
                borderWidth: 1,
                borderDash: [4, 4],
              },
            },
          },
        },
        scales: {
          x: {
            ticks: { maxRotation: 0, maxTicksLimit: 8 },
          },
          y: {
            min: 0,
            max: 100,
            ticks: {
              stepSize: 10,
              callback: (v) => v,
            },
          },
        },
      },
    });
  }

  // ---------------------------------------------------------------------------
  // createMACD
  // ---------------------------------------------------------------------------

  /**
   * MACD chart: MACD line (blue) + Signal line (orange) + histogram bars.
   * @param {string} canvasId
   * @param {Array<{date:string, macd:number, signal:number, histogram:number}>} macdData
   * @returns {Chart}
   */
  function createMACD(canvasId, macdData) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { ctx } = target;

    const labels     = macdData.map((d) => d.date);
    const macdValues = macdData.map((d) => d.macd);
    const sigValues  = macdData.map((d) => d.signal);
    const histValues = macdData.map((d) => d.histogram);

    const histColors = histValues.map((v) => (v >= 0 ? 'rgba(16,185,129,0.7)' : 'rgba(239,68,68,0.7)'));
    const histBorder = histValues.map((v) => (v >= 0 ? '#10b981' : '#ef4444'));

    return new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Histogram',
            data: histValues,
            backgroundColor: histColors,
            borderColor: histBorder,
            borderWidth: 1,
            yAxisID: 'y',
            order: 2,
          },
          {
            type: 'line',
            label: 'MACD',
            data: macdValues,
            borderColor: '#2563eb',
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 4,
            fill: false,
            tension: 0.3,
            yAxisID: 'y',
            order: 1,
          },
          {
            type: 'line',
            label: 'Signal',
            data: sigValues,
            borderColor: '#f97316',
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 4,
            fill: false,
            tension: 0.3,
            yAxisID: 'y',
            order: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 200 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: true,
            position: 'top',
          },
          tooltip: {
            callbacks: {
              label: (item) => {
                const val = item.parsed.y != null ? item.parsed.y.toFixed(4) : 'N/A';
                return `${item.dataset.label}: ${val}`;
              },
            },
          },
        },
        scales: {
          x: {
            ticks: { maxRotation: 0, maxTicksLimit: 8 },
          },
          y: {
            ticks: {
              callback: (v) => v.toFixed(3),
            },
          },
        },
      },
    });
  }

  // ---------------------------------------------------------------------------
  // createDoughnut
  // ---------------------------------------------------------------------------

  /**
   * Portfolio allocation doughnut with center total text and custom legend.
   * @param {string}   canvasId
   * @param {string[]} labels
   * @param {number[]} data
   * @param {string[]} colors
   * @returns {Chart}
   */
  function createDoughnut(canvasId, labels, data, colors) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { ctx } = target;

    const total = data.reduce((acc, v) => acc + v, 0);

    // Center text plugin (local scope so it references `total`)
    const centerTextPlugin = {
      id: 'doughnutCenterText',
      afterDraw(chart) {
        if (chart.config.type !== 'doughnut') return;
        const { width, height } = chart;
        const cx = width / 2;
        const cy = height / 2;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = 'bold 18px Inter, sans-serif';
        ctx.fillStyle = '#e2e8f0';
        ctx.fillText(_fmtCurrency(total), cx, cy - 10);
        ctx.font = '12px Inter, sans-serif';
        ctx.fillStyle = '#64748b';
        ctx.fillText('Total Value', cx, cy + 12);
        ctx.restore();
      },
    };

    return new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: colors,
          borderColor: '#0f1629',
          borderWidth: 2,
          hoverOffset: 8,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        animation: { duration: 200 },
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              padding: 16,
              usePointStyle: true,
              pointStyle: 'circle',
              generateLabels: (chart) => {
                const d = chart.data;
                return d.labels.map((label, i) => {
                  const value = d.datasets[0].data[i];
                  const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                  return {
                    text: `${label}  ${pct}%`,
                    fillStyle: d.datasets[0].backgroundColor[i],
                    strokeStyle: d.datasets[0].backgroundColor[i],
                    index: i,
                    hidden: false,
                  };
                });
              },
            },
          },
          tooltip: {
            callbacks: {
              label: (item) => {
                const value = item.parsed;
                const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                return ` ${item.label}: ${_fmtCurrency(value)} (${pct}%)`;
              },
            },
          },
        },
      },
      plugins: [centerTextPlugin],
    });
  }

  // ---------------------------------------------------------------------------
  // createLine
  // ---------------------------------------------------------------------------

  /**
   * Multi-dataset line chart for equity curves.
   * @param {string} canvasId
   * @param {Array<{label:string, data:Array<{x:string|number, y:number}>, color:string}>} datasets
   * @param {{title?:string, yFormat?:'currency'|'percent', showLegend?:boolean, fill?:boolean}} [options]
   * @returns {Chart}
   */
  function createLine(canvasId, datasets, options = {}) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { canvas, ctx } = target;

    const { title, yFormat = 'currency', showLegend = true, fill = false } = options;

    const chartDatasets = datasets.map((ds) => {
      const gradient = fill ? _buildGradient(ctx, canvas, ds.color) : false;
      return {
        label: ds.label,
        data: ds.data,
        borderColor: ds.color,
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 5,
        fill: fill ? { target: 'origin', above: gradient } : false,
        backgroundColor: gradient || `${ds.color}33`,
        tension: 0.3,
      };
    });

    const yTickFmt = yFormat === 'currency'
      ? (v) => _fmtCurrency(v)
      : (v) => _fmtPercent(v);

    const tooltipFmt = yFormat === 'currency'
      ? (item) => ` ${item.dataset.label}: ${_fmtCurrency(item.parsed.y)}`
      : (item) => ` ${item.dataset.label}: ${_fmtPercent(item.parsed.y)}`;

    return new Chart(ctx, {
      type: 'line',
      data: { datasets: chartDatasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 200 },
        interaction: { mode: 'index', intersect: false },
        parsing: false,
        plugins: {
          legend: { display: showLegend, position: 'top' },
          title: {
            display: !!title,
            text: title || '',
            color: '#e2e8f0',
            font: { family: 'Inter, sans-serif', size: 14, weight: 'bold' },
            padding: { bottom: 12 },
          },
          tooltip: {
            callbacks: {
              label: tooltipFmt,
            },
          },
        },
        scales: {
          x: {
            type: 'time',
            time: { tooltipFormat: 'MMM d, yyyy' },
            ticks: { maxTicksLimit: 10 },
          },
          y: {
            ticks: { callback: yTickFmt },
          },
        },
      },
    });
  }

  // ---------------------------------------------------------------------------
  // createBar
  // ---------------------------------------------------------------------------

  /**
   * Bar chart for fundamental metrics — grouped or stacked.
   * @param {string}   canvasId
   * @param {string[]} labels
   * @param {Array<{label:string, data:number[], color:string}>} datasets
   * @param {{stacked?:boolean, title?:string, yFormat?:'currency'|'percent'|'number'}} [options]
   * @returns {Chart}
   */
  function createBar(canvasId, labels, datasets, options = {}) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { ctx } = target;

    const { stacked = false, title, yFormat = 'number' } = options;

    const chartDatasets = datasets.map((ds) => ({
      label: ds.label,
      data: ds.data,
      backgroundColor: `${ds.color}cc`,
      borderColor: ds.color,
      borderWidth: 1,
      borderRadius: 3,
    }));

    const yTickFmt = yFormat === 'currency'
      ? (v) => _fmtCurrency(v)
      : yFormat === 'percent'
        ? (v) => `${v}%`
        : (v) => v.toLocaleString();

    return new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: chartDatasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 200 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: datasets.length > 1, position: 'top' },
          title: {
            display: !!title,
            text: title || '',
            color: '#e2e8f0',
            font: { family: 'Inter, sans-serif', size: 14 },
            padding: { bottom: 10 },
          },
        },
        scales: {
          x: { stacked },
          y: {
            stacked,
            ticks: { callback: yTickFmt },
          },
        },
      },
    });
  }

  // ---------------------------------------------------------------------------
  // createGauge
  // ---------------------------------------------------------------------------

  /**
   * Gauge chart using a half-circle doughnut.
   * Colour zones: 0-33% red, 34-66% yellow, 67-100% green.
   * @param {string} canvasId
   * @param {number} value   Current value within [min, max]
   * @param {number} min
   * @param {number} max
   * @param {string} label   Text shown below the value
   * @returns {Chart}
   */
  function createGauge(canvasId, value, min, max, label) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { ctx } = target;

    const range = max - min;
    const clampedValue = Math.min(Math.max(value, min), max);
    const pct = ((clampedValue - min) / range) * 100;

    let fillColor;
    if (pct <= 33)      fillColor = '#ef4444';
    else if (pct <= 66) fillColor = '#eab308';
    else                fillColor = '#10b981';

    const fillAmt   = pct / 100;
    const emptyAmt  = 1 - fillAmt;

    const centerPlugin = {
      id: 'gaugeCenterText',
      afterDraw(chart) {
        const { width, height } = chart;
        const cx = width / 2;
        // For half-doughnut the midpoint is at ~75% height
        const cy = height * 0.75;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = 'bold 22px Inter, sans-serif';
        ctx.fillStyle = '#e2e8f0';
        ctx.fillText(clampedValue.toLocaleString(), cx, cy - 12);
        ctx.font = '12px Inter, sans-serif';
        ctx.fillStyle = '#64748b';
        ctx.fillText(label, cx, cy + 12);
        ctx.restore();
      },
    };

    return new Chart(ctx, {
      type: 'doughnut',
      data: {
        datasets: [{
          data: [fillAmt, emptyAmt],
          backgroundColor: [fillColor, 'rgba(30,45,77,0.4)'],
          borderWidth: 0,
          circumference: 180,
          rotation: -90,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '75%',
        animation: { duration: 200 },
        plugins: {
          legend: { display: false },
          tooltip: { enabled: false },
        },
      },
      plugins: [centerPlugin],
    });
  }

  // ---------------------------------------------------------------------------
  // createSentimentGauge
  // ---------------------------------------------------------------------------

  /**
   * Specialised sentiment gauge for the range -100 to +100.
   * Red = negative, yellow = neutral, green = positive.
   * @param {string} canvasId
   * @param {number} value   Sentiment value in [-100, 100]
   * @returns {Chart}
   */
  function createSentimentGauge(canvasId, value) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { ctx } = target;

    const clampedValue = Math.min(Math.max(value, -100), 100);

    // Map -100..+100 to 0..100 for display
    const pct = (clampedValue + 100) / 2;

    let fillColor, sentimentLabel;
    if (clampedValue < -20) {
      fillColor = '#ef4444';
      sentimentLabel = 'Bearish';
    } else if (clampedValue <= 20) {
      fillColor = '#eab308';
      sentimentLabel = 'Neutral';
    } else {
      fillColor = '#10b981';
      sentimentLabel = 'Bullish';
    }

    const fillAmt  = pct / 100;
    const emptyAmt = 1 - fillAmt;

    const centerPlugin = {
      id: 'sentimentCenterText',
      afterDraw(chart) {
        const { width, height } = chart;
        const cx = width / 2;
        const cy = height * 0.75;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = 'bold 20px Inter, sans-serif';
        ctx.fillStyle = fillColor;
        ctx.fillText(`${clampedValue > 0 ? '+' : ''}${clampedValue}`, cx, cy - 14);
        ctx.font = '12px Inter, sans-serif';
        ctx.fillStyle = '#64748b';
        ctx.fillText(sentimentLabel, cx, cy + 10);
        ctx.restore();
      },
    };

    return new Chart(ctx, {
      type: 'doughnut',
      data: {
        datasets: [{
          data: [fillAmt, emptyAmt],
          backgroundColor: [fillColor, 'rgba(30,45,77,0.4)'],
          borderWidth: 0,
          circumference: 180,
          rotation: -90,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        animation: { duration: 200 },
        plugins: {
          legend: { display: false },
          tooltip: { enabled: false },
        },
      },
      plugins: [centerPlugin],
    });
  }

  // ---------------------------------------------------------------------------
  // createMonthlyHeatmap
  // ---------------------------------------------------------------------------

  /**
   * Monthly returns heatmap rendered as a pure HTML/CSS grid (no Chart.js).
   * @param {string} containerId  ID of a container element (div)
   * @param {Object} data         { "2023": { "0": 1.5, "1": -0.3, ... } }
   *                              Keys: year string, month index 0-11
   */
  function createMonthlyHeatmap(containerId, data) {
    const container = document.getElementById(containerId);
    if (!container) {
      console.warn(`[Charts] Container not found: #${containerId}`);
      return;
    }

    container.innerHTML = '';

    const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                         'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    const years = Object.keys(data).sort();

    // Collect all return values to scale colour intensity
    const allValues = [];
    years.forEach((year) => {
      Object.values(data[year]).forEach((v) => {
        if (v != null && !isNaN(v)) allValues.push(Math.abs(v));
      });
    });
    const maxAbs = allValues.length > 0 ? Math.max(...allValues) : 1;

    /**
     * Map a return percentage to a background colour.
     * @param {number|null} v
     * @returns {string}  CSS colour string
     */
    function returnToColor(v) {
      if (v == null || isNaN(v)) return 'rgba(30,45,77,0.4)';
      const intensity = Math.min(Math.abs(v) / maxAbs, 1);
      const alpha = 0.15 + intensity * 0.75;
      if (v > 0) return `rgba(16, 185, 129, ${alpha.toFixed(2)})`;
      return `rgba(239, 68, 68, ${alpha.toFixed(2)})`;
    }

    // Wrapper styles
    container.style.overflowX = 'auto';

    // Build table
    const table = document.createElement('table');
    table.style.cssText = 'border-collapse:collapse;font-family:JetBrains Mono,monospace;font-size:11px;width:100%;';

    // Header row — month names
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');

    // Corner cell (empty)
    const cornerTh = document.createElement('th');
    cornerTh.style.cssText = 'padding:4px 8px;color:#64748b;text-align:left;min-width:54px;';
    headerRow.appendChild(cornerTh);

    MONTH_NAMES.forEach((month) => {
      const th = document.createElement('th');
      th.textContent = month;
      th.style.cssText = 'padding:4px 6px;color:#64748b;text-align:center;min-width:52px;font-weight:500;';
      headerRow.appendChild(th);
    });

    // Avg column header
    const avgTh = document.createElement('th');
    avgTh.textContent = 'Avg';
    avgTh.style.cssText = 'padding:4px 6px;color:#64748b;text-align:center;min-width:52px;font-weight:500;';
    headerRow.appendChild(avgTh);

    thead.appendChild(headerRow);
    table.appendChild(thead);

    // Body rows — one per year
    const tbody = document.createElement('tbody');

    years.forEach((year) => {
      const yearData = data[year];
      const tr = document.createElement('tr');

      // Year label cell
      const yearTd = document.createElement('td');
      yearTd.textContent = year;
      yearTd.style.cssText = 'padding:4px 8px;color:#94a3b8;font-weight:600;white-space:nowrap;';
      tr.appendChild(yearTd);

      const monthValues = [];

      for (let m = 0; m < 12; m++) {
        const v = yearData[m] != null ? yearData[m] : (yearData[String(m)] != null ? yearData[String(m)] : null);
        monthValues.push(v);

        const td = document.createElement('td');
        td.style.cssText = `
          padding: 5px 4px;
          text-align: center;
          border-radius: 3px;
          background: ${returnToColor(v)};
          color: ${v == null ? '#334155' : '#e2e8f0'};
          cursor: default;
        `;

        if (v != null && !isNaN(v)) {
          td.textContent = `${v >= 0 ? '+' : ''}${v.toFixed(1)}%`;
          td.title = `${year} ${MONTH_NAMES[m]}: ${v >= 0 ? '+' : ''}${v.toFixed(2)}%`;
        } else {
          td.textContent = '—';
        }

        tr.appendChild(td);
      }

      // Annual average
      const defined = monthValues.filter((v) => v != null && !isNaN(v));
      const avg = defined.length > 0 ? defined.reduce((a, b) => a + b, 0) / defined.length : null;

      const avgTd = document.createElement('td');
      avgTd.style.cssText = `
        padding: 5px 4px;
        text-align: center;
        border-radius: 3px;
        background: ${returnToColor(avg)};
        color: ${avg == null ? '#334155' : '#e2e8f0'};
        font-weight: 600;
      `;
      avgTd.textContent = avg != null ? `${avg >= 0 ? '+' : ''}${avg.toFixed(1)}%` : '—';
      tr.appendChild(avgTd);

      tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    container.appendChild(table);
  }

  // ---------------------------------------------------------------------------
  // createDrawdownChart
  // ---------------------------------------------------------------------------

  /**
   * Area chart showing drawdown (values should be negative or zero).
   * Fill is red-tinted.
   * @param {string} canvasId
   * @param {Array<{date:string, drawdown:number}>} data
   * @returns {Chart}
   */
  function createDrawdownChart(canvasId, data) {
    const target = _getCanvas(canvasId);
    if (!target) return null;
    const { canvas, ctx } = target;

    const labels = data.map((d) => d.date);
    const values = data.map((d) => d.drawdown);

    const redGradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    redGradient.addColorStop(0, 'rgba(239,68,68,0.4)');
    redGradient.addColorStop(1, 'rgba(239,68,68,0.02)');

    return new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Drawdown',
          data: values,
          borderColor: '#ef4444',
          borderWidth: 1.5,
          fill: true,
          backgroundColor: redGradient,
          pointRadius: 0,
          pointHoverRadius: 4,
          tension: 0.3,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 200 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (item) => ` Drawdown: ${item.parsed.y.toFixed(2)}%`,
            },
          },
        },
        scales: {
          x: {
            ticks: { maxRotation: 0, maxTicksLimit: 10 },
          },
          y: {
            ticks: {
              callback: (v) => `${v.toFixed(1)}%`,
            },
          },
        },
      },
    });
  }

  // ---------------------------------------------------------------------------
  // updateSparkline
  // ---------------------------------------------------------------------------

  /**
   * Update an existing sparkline chart with new data in-place.
   * @param {Chart}    chart    An existing Chart.js sparkline instance
   * @param {number[]} newData  New array of values
   */
  function updateSparkline(chart, newData) {
    if (!chart || !newData) return;
    chart.data.labels = newData.map((_, i) => i);
    chart.data.datasets[0].data = newData;
    chart.update('none'); // 'none' skips animation for live updates
  }

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  return {
    createSparkline,
    createOHLC,
    createRSI,
    createMACD,
    createDoughnut,
    createLine,
    createBar,
    createGauge,
    createSentimentGauge,
    createMonthlyHeatmap,
    createDrawdownChart,
    updateSparkline,
  };
})();
