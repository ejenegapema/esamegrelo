(() => {
  "use strict";

  // ---------------------------------------------------------------------------
  // CONFIG
  // ---------------------------------------------------------------------------

  const API_URL = "https://api.open-meteo.com/v1/forecast";

  // Keep this model aligned with the current heatmap implementation.
  const MODEL = "ncep_gfs_global";

  const TIMEZONE = "auto";

  // Pressure levels, from near-surface to upper atmosphere.
  // 1000 hPa is usually close to sea-level pressure.
  const PRESSURE_LEVELS = [
    1000,
    975,
    950,
    925,
    900,
    850,
    800,
    700,
    600,
    500,
    400,
    300,
  ];

  // Surface + pressure levels.
  const TEMPERATURE_VARS = [
    "temperature_2m",
    ...PRESSURE_LEVELS.map((pressure) => `temperature_${pressure}hPa`),
  ];

  const CURRENT_VARS = [
    "temperature_2m",
    "relative_humidity_2m",
    "wind_speed_10m",
    "precipitation",
  ];

  // ---------------------------------------------------------------------------
  // DOM HELPERS
  // ---------------------------------------------------------------------------

  const $ = (id) => document.getElementById(id);

  const elements = {
    lat: $("lat"),
    lon: $("lon"),
    hours: $("hours"),
    refresh: $("refresh"),
    status: $("status"),
    chart: $("chart"),
  };

  // ---------------------------------------------------------------------------
  // STATUS
  // ---------------------------------------------------------------------------

  function setStatus(message, type = "normal") {
    if (!elements.status) return;

    elements.status.textContent = message;
    elements.status.dataset.type = type;
  }

  function setLoading(isLoading) {
    if (!elements.refresh) return;

    elements.refresh.disabled = isLoading;

    if (isLoading) {
      elements.refresh.classList.add("is-loading");
      elements.refresh.textContent = "Loading…";
    } else {
      elements.refresh.classList.remove("is-loading");
      elements.refresh.textContent = "Refresh";
    }
  }

  // ---------------------------------------------------------------------------
  // CONFIG FROM UI
  // ---------------------------------------------------------------------------

  function getConfigFromUI() {
    const latitude = Number.parseFloat(elements.lat?.value);
    const longitude = Number.parseFloat(elements.lon?.value);
    const forecastHours =
      Number.parseInt(elements.hours?.value, 10) || 72;

    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90) {
      throw new Error("Latitude must be between -90 and 90.");
    }

    if (!Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
      throw new Error("Longitude must be between -180 and 180.");
    }

    return {
      latitude,
      longitude,
      forecastHours: Math.max(6, Math.min(240, forecastHours)),
    };
  }

  // ---------------------------------------------------------------------------
  // API
  // ---------------------------------------------------------------------------

  async function fetchForecast(config) {
    const params = new URLSearchParams({
      latitude: String(config.latitude),
      longitude: String(config.longitude),
      hourly: TEMPERATURE_VARS.join(","),
      current: CURRENT_VARS.join(","),
      models: MODEL,
      timezone: TIMEZONE,
    });

    const response = await fetch(`${API_URL}?${params.toString()}`, {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
    });

    if (!response.ok) {
      throw new Error(`Open-Meteo returned HTTP ${response.status}`);
    }

    const data = await response.json();

    if (!data.hourly || !Array.isArray(data.hourly.time)) {
      throw new Error("The weather API returned no hourly data.");
    }

    return data;
  }

  // ---------------------------------------------------------------------------
  // ATMOSPHERIC CONVERSION
  // ---------------------------------------------------------------------------

  /**
   * ICAO / standard-atmosphere approximation.
   * Converts pressure (hPa) to geopotential altitude (m).
   */
  function pressureToHeightM(pressureHpa, surfacePressure = 1013.25) {
    return (
      44330 *
      (1 - Math.pow(pressureHpa / surfacePressure, 1 / 5.255))
    );
  }

  /**
   * A pressure level above the actual surface may be physically impossible
   * for high-elevation locations. We keep the level only if it has data.
   */
  function buildGrid(data, config) {
    const hourly = data.hourly;

    const totalTimes = hourly.time.length;

    const count = Math.min(
      Number.isFinite(config.forecastHours)
        ? config.forecastHours
        : totalTimes,
      totalTimes
    );

    const times = hourly.time
      .slice(0, count)
      .map((time) => new Date(time));

    const elevation = Number(data.elevation) || 0;

    const levels = [];

    // -----------------------------------------------------------------------
    // Surface
    // -----------------------------------------------------------------------

    if (Array.isArray(hourly.temperature_2m)) {
      levels.push({
        pressure: null,
        height: elevation + 2,
        label: "Surface",
        values: hourly.temperature_2m
          .slice(0, count)
          .map(Number),
      });
    }

    // -----------------------------------------------------------------------
    // Pressure levels
    // -----------------------------------------------------------------------

    for (const pressure of PRESSURE_LEVELS) {
      const key = `temperature_${pressure}hPa`;

      if (!Array.isArray(hourly[key])) {
        continue;
      }

      const values = hourly[key]
        .slice(0, count)
        .map(Number);

      if (!values.length) continue;

      levels.push({
        pressure,
        height: pressureToHeightM(pressure),
        label: `${pressure} hPa`,
        values,
      });
    }

    levels.sort((a, b) => a.height - b.height);

    return {
      times,
      heights: levels.map((level) => level.height),
      temperatures: levels.map((level) => level.values),
      pressureLevels: levels,
      elevation,
    };
  }

  // ---------------------------------------------------------------------------
  // DATA HELPERS
  // ---------------------------------------------------------------------------

  function flattenNumeric(matrix) {
    const values = [];

    for (const row of matrix) {
      for (const value of row) {
        if (Number.isFinite(value)) {
          values.push(value);
        }
      }
    }

    return values;
  }

  function getMinMax(matrix) {
    const values = flattenNumeric(matrix);

    if (!values.length) {
      return [-10, 10];
    }

    return [
      Math.min(...values),
      Math.max(...values),
    ];
  }

  function niceStep(range) {
    if (range <= 8) return 1;
    if (range <= 16) return 2;
    if (range <= 35) return 5;
    if (range <= 70) return 10;
    return 20;
  }

  function buildTemperatureLevels(min, max) {
    const range = Math.max(1, max - min);
    const step = niceStep(range);

    const start = Math.floor(min / step) * step;
    const end = Math.ceil(max / step) * step;

    const levels = [];

    for (let value = start; value <= end + step / 2; value += step) {
      levels.push(Number(value.toFixed(4)));
    }

    return levels;
  }

  // ---------------------------------------------------------------------------
  // COLOR SCALE
  // ---------------------------------------------------------------------------

  /**
   * Cold -> warm.
   *
   * 0%   = deep blue
   * 25%  = cyan
   * 50%  = green
   * 75%  = yellow/orange
   * 100% = deep red
   *
   * This is intentionally the opposite direction of the old heatmap array.
   */
  function makeTemperatureColorscale() {
    const colors = [
      "#1a1a4e",
      "#1f4fa3",
      "#2a8bc7",
      "#54c2c9",
      "#a4e0a0",
      "#f4e07d",
      "#f0a542",
      "#d8542f",
      "#7a1414",
    ];

    return colors.map((color, index) => [
      index / (colors.length - 1),
      color,
    ]);
  }

  // ---------------------------------------------------------------------------
  // FORMATTERS
  // ---------------------------------------------------------------------------

  function formatCoordinate(value) {
    const direction =
      value >= 0
        ? "N"
        : "S";

    return `${Math.abs(value).toFixed(2)}°${direction}`;
  }

  function formatLongitude(value) {
    const direction =
      value >= 0
        ? "E"
        : "W";

    return `${Math.abs(value).toFixed(2)}°${direction}`;
  }

  function formatTemperature(value) {
    if (!Number.isFinite(value)) return "—";

    return `${value.toFixed(1)}°C`;
  }

  function formatHeight(value) {
    return `${Math.round(value).toLocaleString()} m`;
  }

  function formatDateTime(value) {
    if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
      return "—";
    }

    return value.toLocaleString([], {
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  // ---------------------------------------------------------------------------
  // CURRENT CONDITIONS
  // ---------------------------------------------------------------------------

  function getCurrentInfo(data) {
    const current = data.current || {};

    return {
      time: current.time ? new Date(current.time) : null,
      temperature: Number(current.temperature_2m),
      humidity: Number(current.relative_humidity_2m),
      wind: Number(current.wind_speed_10m),
      precipitation: Number(current.precipitation),
    };
  }

  // ---------------------------------------------------------------------------
  // PLOT
  // ---------------------------------------------------------------------------

  function plotCrossSection(times, heights, temperatures, data, config) {
    if (!times.length || !heights.length || !temperatures.length) {
      throw new Error("Not enough data to draw the heatmap.");
    }

    if (typeof Plotly === "undefined") {
      throw new Error(
        "Plotly.js is not loaded. Check heatmap/index.html."
      );
    }

    const colorscale = makeTemperatureColorscale();

    const [rawMin, rawMax] = getMinMax(temperatures);

    const levelValues = buildTemperatureLevels(
      rawMin,
      rawMax
    );

    const tmin = levelValues[0];
    const tmax = levelValues[levelValues.length - 1];

    const elevation = Number(data.elevation) || 0;

    const minHeight = Math.min(...heights);
    const maxHeight = Math.max(...heights);

    const heightPadding =
      Math.max(maxHeight - minHeight, 100) * 0.025;

    const yRange = [
      minHeight - heightPadding,
      maxHeight + heightPadding,
    ];

    // -----------------------------------------------------------------------
    // Main contour heatmap
    // -----------------------------------------------------------------------

    const mainTrace = {
      type: "contour",

      x: times,
      y: heights,
      z: temperatures,

      colorscale,
      zmin: tmin,
      zmax: tmax,

      connectgaps: true,

      autocontour: false,

      contours: {
        coloring: "heatmap",
        showlines: true,
        start: tmin,
        end: tmax,
        size: niceStep(tmax - tmin) / 2,
        showlabels: false,
      },

      line: {
        width: 0.35,
        color: "rgba(255,255,255,0.28)",
      },

      colorbar: {
        title: {
          text: "Temperature",
          side: "right",
          font: {
            color: "#cbd5e1",
            size: 13,
            family: "Inter, sans-serif",
          },
        },

        tickfont: {
          color: "#cbd5e1",
          size: 11,
          family: "Inter, sans-serif",
        },

        ticksuffix: "°C",

        thickness: 13,
        len: 0.82,

        outlinewidth: 0,

        bgcolor: "rgba(15,23,42,0.15)",
      },

      hovertemplate:
        "<b>%{x|%d %b %H:%M}</b>" +
        "<br>Altitude: %{y:,.0f} m" +
        "<br>Temperature: %{z:.1f}°C" +
        "<extra></extra>",
    };

    const traces = [mainTrace];

    // -----------------------------------------------------------------------
    // 0°C freezing line
    // -----------------------------------------------------------------------

    if (rawMin <= 0 && rawMax >= 0) {
      traces.push({
        type: "contour",

        x: times,
        y: heights,
        z: temperatures,

        showscale: false,

        contours: {
          start: 0,
          end: 0,
          size: 1,
          coloring: "lines",
          showlabels: true,
        },

        line: {
          width: 2.2,
          color: "#38bdf8",
        },

        labelfont: {
          size: 10,
          color: "#e0f2fe",
          family: "Inter, sans-serif",
        },

        hoverinfo: "skip",
      });
    }

    // -----------------------------------------------------------------------
    // Pressure ticks / secondary axis
    // -----------------------------------------------------------------------

    const validPressureLevels = PRESSURE_LEVELS
      .filter((pressure) =>
        heights.some(
          (height) =>
            Math.abs(
              height - pressureToHeightM(pressure)
            ) < 1
        )
      );

    const pressureTickHeights =
      validPressureLevels.map(
        (pressure) => pressureToHeightM(pressure)
      );

    const pressureTickLabels =
      validPressureLevels.map(
        (pressure) => `${pressure} hPa`
      );

    // -----------------------------------------------------------------------
    // Layout
    // -----------------------------------------------------------------------

    const layout = {
      autosize: true,

      paper_bgcolor: "#1e293b",
      plot_bgcolor: "#1e293b",

      font: {
        color: "#e2e8f0",
        family: "Inter, sans-serif",
      },

      title: {
        text:
          `<b>Vertical Temperature Cross-Section</b>` +
          `<br><span style="font-size:12px;color:#94a3b8">` +
          `${formatCoordinate(config.latitude)}, ` +
          `${formatLongitude(config.longitude)}` +
          ` · ${MODEL}` +
          ` · elevation ${Math.round(elevation)} m` +
          `</span>`,

        x: 0.02,
        xanchor: "left",

        y: 0.98,
        yanchor: "top",

        font: {
          size: 18,
          color: "#f1f5f9",
          family: "Inter, sans-serif",
        },
      },

      margin: {
        t: 75,
        r: 105,
        b: 70,
        l: 75,
      },

      hoverlabel: {
        bgcolor: "#0f172a",
        bordercolor: "#334155",
        font: {
          color: "#f8fafc",
          family: "Inter, sans-serif",
          size: 12,
        },
      },

      xaxis: {
        title: {
          text: "Time",
          font: {
            size: 12,
            color: "#94a3b8",
          },
        },

        type: "date",

        gridcolor: "#334155",
        gridwidth: 1,

        linecolor: "#475569",
        linewidth: 1,

        tickfont: {
          color: "#cbd5e1",
          size: 11,
        },

        zeroline: false,

        rangeslider: {
          visible: false,
        },
      },

      yaxis: {
        title: {
          text: "Altitude",
          font: {
            size: 12,
            color: "#94a3b8",
          },
        },

        range: yRange,

        gridcolor: "#334155",
        gridwidth: 1,

        linecolor: "#475569",
        linewidth: 1,

        tickfont: {
          color: "#cbd5e1",
          size: 11,
        },

        ticksuffix: " m",

        zeroline: false,
      },

      yaxis2: {
        overlaying: "y",

        side: "right",

        range: yRange,

        tickmode: "array",

        tickvals: pressureTickHeights,
        ticktext: pressureTickLabels,

        title: {
          text: "Pressure",
          font: {
            size: 12,
            color: "#94a3b8",
          },
        },

        tickfont: {
          color: "#94a3b8",
          size: 10,
        },

        linecolor: "#475569",
        linewidth: 1,

        showgrid: false,

        zeroline: false,
      },

      shapes: [],

      annotations: [],
    };

    // -----------------------------------------------------------------------
    // Current time marker
    // -----------------------------------------------------------------------

    const current = getCurrentInfo(data);

    if (
      current.time &&
      current.time >= times[0] &&
      current.time <= times[times.length - 1]
    ) {
      layout.shapes.push({
        type: "line",

        xref: "x",
        yref: "paper",

        x0: current.time,
        x1: current.time,

        y0: 0,
        y1: 1,

        line: {
          color: "#f8fafc",
          width: 1.5,
          dash: "dot",
        },
      });

      const temperatureLabel = Number.isFinite(
        current.temperature
      )
        ? formatTemperature(current.temperature)
        : "—";

      layout.annotations.push({
        x: current.time,
        y: 1,

        xref: "x",
        yref: "paper",

        text: `NOW · ${temperatureLabel}`,

        showarrow: false,

        xanchor: "left",
        yanchor: "bottom",

        yshift: 5,

        font: {
          color: "#f8fafc",
          size: 10,
          family: "Inter, sans-serif",
          weight: 600,
        },

        bgcolor: "#3b82f6",

        bordercolor: "#3b82f6",

        borderpad: 5,
      });
    }

    // -----------------------------------------------------------------------
    // Plot
    // -----------------------------------------------------------------------

    const plotConfig = {
      responsive: true,
      displaylogo: false,

      modeBarButtonsToRemove: [
        "lasso2d",
        "select2d",
        "autoScale2d",
      ],

      modeBarButtonsToAdd: [
        "resetScale2d",
      ],

      toImageButtonOptions: {
        format: "png",
        filename: "esamegrelo_temperature_cross_section",
        height: 900,
        width: 1600,
        scale: 2,
      },
    };

    Plotly.react(
      elements.chart,
      traces,
      layout,
      plotConfig
    );
  }

  // ---------------------------------------------------------------------------
  // EXTRA INFO ABOVE CHART
  // ---------------------------------------------------------------------------

  function updateHeader(config, data) {
    const existing = document.getElementById(
      "heatmapHeaderInfo"
    );

    const current = getCurrentInfo(data);

    let container = existing;

    if (!container) {
      container = document.createElement("div");
      container.id = "heatmapHeaderInfo";
      container.className = "heatmap-header-info";

      const controls = document.getElementById("controls");

      if (controls?.parentNode) {
        controls.parentNode.insertBefore(
          container,
          controls
        );
      }
    }

    const currentTemp = Number.isFinite(current.temperature)
      ? formatTemperature(current.temperature)
      : "—";

    const currentHumidity =
      Number.isFinite(current.humidity)
        ? `${Math.round(current.humidity)}%`
        : "—";

    const currentWind =
      Number.isFinite(current.wind)
        ? `${current.wind.toFixed(1)} km/h`
        : "—";

    const elevation =
      Number(data.elevation);

    const elevationText =
      Number.isFinite(elevation)
        ? `${Math.round(elevation)} m`
        : "—";

    container.innerHTML = `
      <div class="heatmap-location">
        <span class="heatmap-location-dot"></span>
        <span>
          ${formatCoordinate(config.latitude)}
          ·
          ${formatLongitude(config.longitude)}
        </span>
      </div>

      <div class="heatmap-meta">
        <div class="heatmap-meta-item">
          <span class="heatmap-meta-label">Current</span>
          <strong>${currentTemp}</strong>
        </div>

        <div class="heatmap-meta-item">
          <span class="heatmap-meta-label">Humidity</span>
          <strong>${currentHumidity}</strong>
        </div>

        <div class="heatmap-meta-item">
          <span class="heatmap-meta-label">Wind</span>
          <strong>${currentWind}</strong>
        </div>

        <div class="heatmap-meta-item">
          <span class="heatmap-meta-label">Elevation</span>
          <strong>${elevationText}</strong>
        </div>

        <div class="heatmap-meta-item">
          <span class="heatmap-meta-label">Forecast</span>
          <strong>${config.forecastHours} h</strong>
        </div>
      </div>
    `;
  }

  // ---------------------------------------------------------------------------
  // INITIAL UI ENHANCEMENTS
  // ---------------------------------------------------------------------------

  function enhanceControls() {
    const controls = document.getElementById("controls");

    if (!controls) return;

    controls.classList.add("heatmap-controls");

    // Add explicit labels / hints without requiring HTML changes.
    const labels = controls.querySelectorAll("label");

    labels.forEach((label) => {
      label.classList.add("heatmap-control-label");
    });

    if (elements.lat) {
      elements.lat.title =
        "Latitude: -90 to 90";
    }

    if (elements.lon) {
      elements.lon.title =
        "Longitude: -180 to 180";
    }

    if (elements.hours) {
      elements.hours.title =
        "Forecast length in hours";
    }
  }

  // ---------------------------------------------------------------------------
  // RUN
  // ---------------------------------------------------------------------------

  let requestCounter = 0;

  async function main() {
    const requestId = ++requestCounter;

    try {
      const config = getConfigFromUI();

      setLoading(true);
      setStatus("Fetching forecast…", "loading");

      const data = await fetchForecast(config);

      if (requestId !== requestCounter) {
        return;
      }

      const {
        times,
        heights,
        temperatures,
      } = buildGrid(data, config);

      if (!times.length || !heights.length) {
        throw new Error(
          "No usable temperature levels were returned."
        );
      }

      updateHeader(config, data);

      plotCrossSection(
        times,
        heights,
        temperatures,
        data,
        config
      );

      setStatus(
        `Updated ${new Date().toLocaleTimeString([], {
          hour: "2-digit",
          minute: "2-digit",
        })}`,
        "success"
      );
    } catch (error) {
      console.error("Heatmap error:", error);

      setStatus(
        error instanceof Error
          ? error.message
          : "Unable to load weather data.",
        "error"
      );

      if (elements.chart) {
        elements.chart.innerHTML = `
          <div class="heatmap-error">
            <div class="heatmap-error-icon">!</div>
            <div>
              <strong>Unable to load forecast</strong>
              <span>${String(
                error?.message ||
                  "Unknown error"
              ).replace(/</g, "&lt;")}</span>
            </div>
          </div>
        `;
      }
    } finally {
      if (requestId === requestCounter) {
        setLoading(false);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // EVENTS
  // ---------------------------------------------------------------------------

  elements.refresh?.addEventListener(
    "click",
    main
  );

  // Enter key on inputs.
  [
    elements.lat,
    elements.lon,
    elements.hours,
  ].forEach((input) => {
    input?.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        main();
      }
    });
  });

  // Resize Plotly cleanly.
  window.addEventListener(
    "resize",
    () => {
      if (
        elements.chart &&
        typeof Plotly !== "undefined"
      ) {
        Plotly.Plots.resize(elements.chart);
      }
    },
    { passive: true }
  );

  // ---------------------------------------------------------------------------
  // START
  // ---------------------------------------------------------------------------

  function start() {
    enhanceControls();
    main();
  }

  if (document.readyState === "loading") {
    document.addEventListener(
      "DOMContentLoaded",
      start,
      { once: true }
    );
  } else {
    start();
  }
})();
