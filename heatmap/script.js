/*
 * Beautiful Temperature Cross-Section (Time x Pressure/Height)
 * ==============================================================
 * Fetches hourly forecast temperatures at multiple pressure levels from the
 * Open-Meteo API (ECMWF AIFS 0.25 deg single model) and renders them as a
 * smooth, publication-quality heatmap using Plotly.js:
 * time on the x-axis, altitude (derived from pressure) on the y-axis,
 * temperature as color.
 */

// --------------------------------------------------------------------------
// 1. CONFIGURATION
// --------------------------------------------------------------------------

const MODEL = "ncep_gfs_global";
const TIMEZONE = "auto"; // local time at the requested coordinates

// Pressure levels requested, ordered from the ground up (hPa)
const PRESSURE_LEVELS = [1000, 975, 950, 925, 900, 850, 800, 700, 600, 500, 400, 300];

const HOURLY_VARS = [
  "relative_humidity_2m",
  ...PRESSURE_LEVELS.map((p) => `relative_humidity_${p}hPa`),
];
const CURRENT_VARS = ["relative_humidity_2m"];

const API_URL = "https://api.open-meteo.com/v1/forecast";

// --------------------------------------------------------------------------
// 2. FETCH DATA
// --------------------------------------------------------------------------

async function fetchForecast(config) {
  const params = new URLSearchParams({
    latitude: config.latitude,
    longitude: config.longitude,
    hourly: HOURLY_VARS.join(","),
    current: CURRENT_VARS.join(","),
    models: MODEL,
    timezone: TIMEZONE,
  });

  const resp = await fetch(`${API_URL}?${params.toString()}`);
  if (!resp.ok) {
    throw new Error(`HTTP ${resp.status} while fetching forecast`);
  }
  return resp.json();
}

// --------------------------------------------------------------------------
// 3. BUILD THE (TIME x HEIGHT) TEMPERATURE GRID
// --------------------------------------------------------------------------

/** ICAO standard-atmosphere approximation of geopotential height (m). */
function pressureToHeightM(pHpa, p0 = 1013.25) {
  return 44330.0 * (1.0 - Math.pow(pHpa / p0, 1.0 / 5.255));
}

function buildGrid(data, config) {
  const hourly = data.hourly;
  let times = hourly.time.map((t) => new Date(t));

  const n = config.forecastHours
    ? Math.min(config.forecastHours, times.length)
    : times.length;
  times = times.slice(0, n);

  // Surface point: 2 m air temperature, placed at station elevation + 2 m
  const elevation = data.elevation || 0.0;
  const levels = [
    {
      height: elevation + 2.0,
      temps: hourly.relative_humidity_2m.slice(0, n).map(Number),
    },
  ];

  // Pressure-level points
  for (const p of PRESSURE_LEVELS) {
    const key = `relative_humidity_${p}hPa`;
    if (!(key in hourly)) continue;
    levels.push({
      height: pressureToHeightM(p),
      temps: hourly[key].slice(0, n).map(Number),
    });
  }

  levels.sort((a, b) => a.height - b.height);

  const heights = levels.map((l) => l.height);
  const temps = levels.map((l) => l.temps); // rows: levels (asc), cols: time

  return { times, heights, temps };
}

// --------------------------------------------------------------------------
// 4. PLOT
// --------------------------------------------------------------------------

function makeColorscale() {
  // Deep blue (cold) -> cyan -> green -> yellow -> orange -> deep red (hot)
  const colors = [
    "#1a1a4e", "#1f4fa3", "#2a8bc7", "#54c2c9",
    "#a4e0a0", "#f4e07d", "#f0a542", "#d8542f", "#7a1414",
  ];
  const n = colors.length;
  return colors.map((c, i) => [i / (n - 1), c]);
}

function flatMinMax(arr2d) {
  let mn = Infinity, mx = -Infinity;
  for (const row of arr2d) {
    for (const v of row) {
      if (v < mn) mn = v;
      if (v > mx) mx = v;
    }
  }
  return [mn, mx];
}

function plotCrossSection(times, heights, temps, data, config) {
  const colorscale = makeColorscale();
  const [tmin, tmax] = flatMinMax(temps);
  const reversedColorscale = colorscale.slice().reverse();
  // --- smooth heatmap + thin 2C contour lines ---
  const mainContour = {
    type: "contour",
    x: times,
    y: heights,
    z: temps,
    colorscale: reversedColorscale,
    zmin: 0,
    zmax: 100,
    connectgaps: true,
    contours: {
      coloring: "heatmap",
      showlines: true,
      start: Math.floor(tmin / 2) * 2,
      end: Math.ceil(tmax / 2) * 2 + 2,
      size: 2,
      showlabels: true,
      labelfont: { size: 9, color: "white" },
    },
    line: { width: 0.5, color: "rgba(255,255,255,0.35)" },
    colorbar: {
      title: { text: "Relative Humidity (%)", font: { color: "white" } },
      tickfont: { color: "white" },
      outlinecolor: "#444444",
    },
    hovertemplate:
      "Time: %{x}<br>Height: %{y:.0f} m<br>Rh: %{z:.1f}%<extra></extra>",
  };

  const traces = [mainContour];

  // --- highlight the 0C isotherm (freezing level) ---
  if (tmin < 0 && tmax > 0) {
    traces.push({
      type: "contour",
      x: times,
      y: heights,
      z: temps,
      showscale: false,
      contours: {
        start: 0,
        end: 0,
        size: 1,
        coloring: "lines",
        showlabels: true,
        labelfont: { size: 10, color: "#00e5ff" },
      },
      line: { width: 2.5, color: "#00e5ff" },
      hoverinfo: "skip",
    });
  }

  // --- secondary y-axis: pressure levels (hPa) ---
  const tickHeights = PRESSURE_LEVELS.map((p) => pressureToHeightM(p));
  const tickLabels = PRESSURE_LEVELS.map((p) => `${p} hPa`);

  const yMin = Math.min(...heights);
  const yMax = Math.max(...heights);
  const pad = (yMax - yMin) * 0.03;
  const yRange = [yMin - pad, yMax + pad];

  const layout = {
    paper_bgcolor: "#0d1117",
    plot_bgcolor: "#0d1117",
    font: { color: "white" },
    title: {
      text:
        `Vertical Relative Humidity Cross-Section  •  ${config.latitude}°N, ` +
        `${config.longitude}°E  •  model: ${MODEL}`,
      font: { size: 16, color: "white" },
    },
    xaxis: {
      title: "Time",
      type: "date",
      gridcolor: "#333333",
      tickfont: { color: "white" },
      linecolor: "#444444",
    },
    yaxis: {
      title: "Altitude (m, approx.)",
      gridcolor: "#333333",
      tickfont: { color: "white" },
      linecolor: "#444444",
      range: yRange,
    },
    yaxis2: {
      overlaying: "y",
      side: "right",
      tickmode: "array",
      tickvals: tickHeights,
      ticktext: tickLabels,
      title: "Pressure level",
      tickfont: { color: "white" },
      linecolor: "#444444",
      showgrid: false,
      range: yRange,
    },
    shapes: [],
    annotations: [],
    margin: { t: 70, r: 130, b: 60, l: 70 },
  };

  // --- current-condition marker ---
  const current = data.current || {};
  if (current && current.time) {
    const nowDate = new Date(current.time);
    if (nowDate >= times[0] && nowDate <= times[times.length - 1]) {
      layout.shapes.push({
        type: "line",
        xref: "x",
        yref: "paper",
        x0: nowDate,
        x1: nowDate,
        y0: 0,
        y1: 1,
        line: { color: "white", dash: "dash", width: 1 },
      });

      let label = `Now: ${current.relative_humidity_2m ?? "?"}°C`;
      if ("precipitation" in current) {
        label += `, ${current.precipitation} mm precip`;
      }

      layout.annotations.push({
        x: nowDate,
        y: 1,
        xref: "x",
        yref: "paper",
        text: label,
        showarrow: false,
        xanchor: "left",
        yanchor: "bottom",
        font: { color: "white", size: 11 },
        bgcolor: "#1f2937",
        bordercolor: "#444444",
        borderpad: 4,
      });
    }
  }

  Plotly.newPlot("chart", traces, layout, {
    responsive: true,
    displaylogo: false,
  });
}

// --------------------------------------------------------------------------
// 5. MAIN
// --------------------------------------------------------------------------

function setStatus(msg) {
  document.getElementById("status").textContent = msg;
}

function getConfigFromUI() {
  return {
    latitude: parseFloat(document.getElementById("lat").value),
    longitude: parseFloat(document.getElementById("lon").value),
    forecastHours: parseInt(document.getElementById("hours").value, 10) || null,
  };
}

async function main() {
  const config = getConfigFromUI();
  try {
    setStatus("Fetching data…");
    const data = await fetchForecast(config);
    const { times, heights, temps } = buildGrid(data, config);
    plotCrossSection(times, heights, temps, data, config);
    setStatus(`Updated: ${new Date().toLocaleTimeString()}`);
  } catch (err) {
    console.error(err);
    setStatus(`Error: ${err.message}`);
  }
}

document.getElementById("refresh").addEventListener("click", main);
document.addEventListener("DOMContentLoaded", main);
