<?php

/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

$latitude  = isset($_GET['lat']) ? (float)$_GET['lat'] : 42.5;
$longitude = isset($_GET['lon']) ? (float)$_GET['lon'] : 42.0;


/*
|--------------------------------------------------------------------------
| VALIDATE LATITUDE
|--------------------------------------------------------------------------
*/

if ($latitude < -90 || $latitude > 90) {
    die("Latitude must be between -90 and 90.");
}


/*
|--------------------------------------------------------------------------
| NORMALIZE LONGITUDE
|--------------------------------------------------------------------------
*/

$longitude = fmod($longitude, 360.0);

if ($longitude < 0) {
    $longitude += 360.0;
}


/*
|--------------------------------------------------------------------------
| ERA5 GRID INDEX
|--------------------------------------------------------------------------
|
| Latitude:
|   -90 ... +90
|   step 0.25°
|
| index = (lat + 90) * 4
|
| Longitude:
|   0 ... 359.75
|   step 0.25°
|
| index = lon * 4
|
|--------------------------------------------------------------------------
*/

$latitudeIndex =
    (int)round(($latitude + 90.0) * 4.0);

$longitudeIndex =
    (int)round($longitude * 4.0);


/*
|--------------------------------------------------------------------------
| Keep indices inside the ERA5 grid
|--------------------------------------------------------------------------
*/

$latitudeIndex =
    max(0, min(720, $latitudeIndex));

$longitudeIndex =
    max(0, min(1439, $longitudeIndex));


/*
|--------------------------------------------------------------------------
| Actual grid coordinates after snapping
|--------------------------------------------------------------------------
*/

$actualLatitude =
    $latitudeIndex / 4.0 - 90.0;

$actualLongitude =
    $longitudeIndex / 4.0;


/*
|--------------------------------------------------------------------------
| URLs
|--------------------------------------------------------------------------
|
| [0:] = complete available time dimension
|
| 925 hPa = level index 3
| 1000 hPa = level index 0
|--------------------------------------------------------------------------
*/

$url_925 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:1039][3][' .
    $latitudeIndex . '][' .
    $longitudeIndex . ']';

$url_1000 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:1039][0][' .
    $latitudeIndex . '][' .
    $longitudeIndex . ']';


/*
|--------------------------------------------------------------------------
| DOWNLOAD + PARSE DATA
|--------------------------------------------------------------------------
*/

function getValues(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);

    $text =
        file_get_contents(
            $url,
            false,
            $context
        );

    if ($text === false) {
        die(
            "Download failed:<br><br>" .
            htmlspecialchars($url)
        );
    }


    /*
     * Example:
     *
     * [0][3][530][168], 12345.678
     *
     * Everything after the comma is parsed.
     */
    preg_match_all(
        '/^\s*(?:\[\d+\])+\s*,\s*(.*?)\s*$/m',
        $text,
        $rows
    );


    $values = [];


    foreach ($rows[1] as $row) {

        preg_match_all(
            '/[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?/',
            $row,
            $numbers
        );


        foreach ($numbers[0] as $number) {

            $value =
                (float)$number;

            if (is_finite($value)) {
                $values[] = $value;
            }
        }
    }


    if (empty($values)) {

        die(
            "No numeric values found:<br><br>" .
            htmlspecialchars($url)
        );
    }


    return $values;
}


/*
|--------------------------------------------------------------------------
| DOWNLOAD BOTH LEVELS
|--------------------------------------------------------------------------
*/

$phi925 =
    getValues($url_925);

$phi1000 =
    getValues($url_1000);


/*
|--------------------------------------------------------------------------
| VALIDATE
|--------------------------------------------------------------------------
*/

if (count($phi925) !== count($phi1000)) {

    die(
        "Dataset size mismatch.<br>" .
        "925 hPa: " .
        count($phi925) .
        "<br>" .
        "1000 hPa: " .
        count($phi1000)
    );
}


$n =
    count($phi925);


/*
|--------------------------------------------------------------------------
| HYPSOMETRIC CONSTANT
|--------------------------------------------------------------------------
|
| ERA5 zg = geopotential Φ in m²/s²
|
| T(K) =
|
| (Φ925 - Φ1000)
| ----------------------------
| Rd × ln(1000 / 925)
|
|--------------------------------------------------------------------------
*/

$Rd =
    287.05;

$denominator =
    $Rd *
    log(1000.0 / 925.0);


/*
|--------------------------------------------------------------------------
| STEP 1
|
| MONTHLY 1000–925 hPa LAYER TEMPERATURE
|--------------------------------------------------------------------------
*/

$monthlyTemperatureC = [];


for ($i = 0; $i < $n; $i++) {

    $deltaPhi =
        $phi925[$i] -
        $phi1000[$i];


    /*
     * Kelvin
     */
    $temperatureK =
        $deltaPhi /
        $denominator;


    /*
     * Celsius
     */
    $temperatureC =
        $temperatureK -
        273.15;


    $monthlyTemperatureC[] =
        $temperatureC;
}


/*
|--------------------------------------------------------------------------
| STEP 2
|
| 12-MONTH ROLLING MEAN
|--------------------------------------------------------------------------
|
| rolling12[i] =
|
| mean of:
|
| i-11 ... i
|
|--------------------------------------------------------------------------
*/


$rolling12 =
    array_fill(
        0,
        $n,
        null
    );


/*
 * Prefix sum for efficient O(N) rolling calculation.
 */

$prefix =
    array_fill(
        0,
        $n + 1,
        0.0
    );


for ($i = 0; $i < $n; $i++) {

    $prefix[$i + 1] =
        $prefix[$i] +
        $monthlyTemperatureC[$i];
}


for ($i = 11; $i < $n; $i++) {

    $start =
        $i - 11;


    $sum =
        $prefix[$i + 1] -
        $prefix[$start];


    $rolling12[$i] =
        $sum / 12.0;
}


/*
|--------------------------------------------------------------------------
| STEP 3
|
| ONE CLIMATE NORMAL
|
| Mean of ALL available 12-month rolling values.
|--------------------------------------------------------------------------
*/

$sumRolling =
    0.0;

$countRolling =
    0;


foreach ($rolling12 as $value) {

    if (
        $value !== null &&
        is_finite($value)
    ) {

        $sumRolling +=
            $value;

        $countRolling++;
    }
}


if ($countRolling === 0) {

    die(
        "Could not calculate 12-month rolling climate normal."
    );
}


$climateNormal =
    $sumRolling /
    $countRolling;


/*
|--------------------------------------------------------------------------
| STEP 4
|
| TEMPERATURE ANOMALY
|
| anomaly =
|
| rolling12 temperature
| -
| ALL-PERIOD climate normal
|--------------------------------------------------------------------------
*/

$anomaly =
    array_fill(
        0,
        $n,
        null
    );


for ($i = 0; $i < $n; $i++) {

    if ($rolling12[$i] !== null) {

        $anomaly[$i] =
            $rolling12[$i] -
            $climateNormal;
    }
}


/*
|--------------------------------------------------------------------------
| STEP 5
|
| DATES
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Change this date if your APDRC time series starts
| at a different month.
|--------------------------------------------------------------------------
*/

$startDate =
    new DateTime('1940-01-01');


$dates = [];


for ($i = 0; $i < $n; $i++) {

    $date =
        clone $startDate;

    $date->modify(
        "+{$i} months"
    );

    $dates[] =
        $date->format('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| STEP 6
|
| JSON FOR JAVASCRIPT
|--------------------------------------------------------------------------
*/

$chartData = [];


for ($i = 0; $i < $n; $i++) {

    $chartData[] = [

        'date' =>
            $dates[$i],

        'anomaly' =>
            $anomaly[$i] === null
                ? null
                : round(
                    $anomaly[$i],
                    4
                )
    ];
}


$json =
    json_encode(
        $chartData,
        JSON_UNESCAPED_SLASHES |
        JSON_NUMERIC_CHECK
    );

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    1000–925 hPa Temperature Anomaly
</title>


<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
    rel="stylesheet"
>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>


<style>

/* =========================================================
   USER PROVIDED STYLE
   ========================================================= */

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  background: #0a0a0f;
  color: #e2e8f0;
  font-family: 'Inter', sans-serif;
  padding: 20px;
}

.container {
  max-width: 1200px;
  margin: 0 auto;
}

h1 {
  text-align: center;
  font-size: 2.2rem;
  font-weight: 700;
  margin-bottom: 1rem;
}

.chart-container {
  background: rgba(15, 23, 42, 0.6);
  padding: 2rem;
  border-radius: 20px;
  margin-bottom: 2rem;
  height: 400px;
}

.date-picker {
  text-align: center;
  margin-bottom: 1rem;
}

input[type=date],
input[type=number] {
  padding: 4px 8px;
  margin: 0 5px;
  border-radius: 8px;
  border: 1px solid #ccc;
}

button {
  padding: 4px 8px;
  border-radius: 8px;
  border: none;
  background: #0906c4;
  color: #000;
  font-weight: 600;
  cursor: pointer;
  margin-left: 5px;
}

.stats-container {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  margin-top: 1rem;
}

.stat-box {
  flex: 1 1 18%;
  background: rgba(15, 23, 42, 0.6);
  border-radius: 15px;
  padding: 1rem;
  text-align: center;
  font-weight: 600;
  font-size: 1.2rem;
  color: #00d4ff;
}

.stat-box span {
  display: block;
  font-size: 2rem;
  margin-top: 0.3rem;
  color: #e2e8f0;
}


/* =========================================================
   SMALL ADDITIONS
   ========================================================= */

.location-row {
    text-align: center;
    margin-bottom: 1rem;
}

.location-label {
    margin-right: 5px;
}

.chart-container canvas {
    width: 100% !important;
    height: 100% !important;
}

.normal-info {
    text-align: center;
    margin-top: 1rem;
    color: #94a3b8;
    font-size: 0.9rem;
}

</style>

</head>


<body>


<div class="container">


<h1>
    1000–925 hPa Temperature Anomaly
</h1>


<div class="date-picker">

    <div class="location-row">

        <span class="location-label">
            Latitude
        </span>

        <input
            type="number"
            id="latitude"
            step="0.25"
            min="-90"
            max="90"
            value="<?= htmlspecialchars(
                (string)$latitude
            ) ?>"
        >


        <span class="location-label">
            Longitude
        </span>

        <input
            type="number"
            id="longitude"
            step="0.25"
            value="<?= htmlspecialchars(
                (string)$longitude
            ) ?>"
        >


        <button
            type="button"
            onclick="loadLocation()"
        >
            Load location
        </button>

    </div>

</div>


<div class="chart-container">

    <canvas id="temperatureChart"></canvas>

</div>


<div class="stats-container">


    <div class="stat-box">

        Location

        <span>

            <?= $actualLatitude ?>°,
            <?= $actualLongitude ?>°

        </span>

    </div>


    <div class="stat-box">

        12M climate normal

        <span>

            <?= number_format(
                $climateNormal,
                2
            ) ?> °C

        </span>

    </div>


    <div class="stat-box">

        Latest anomaly

        <span id="latestAnomaly">

            —

        </span>

    </div>


    <div class="stat-box">

        Maximum anomaly

        <span id="maxAnomaly">

            —

        </span>

    </div>


    <div class="stat-box">

        Minimum anomaly

        <span id="minAnomaly">

            —

        </span>

    </div>

</div>


<div class="normal-info">

    12-month rolling temperature anomaly
    relative to the mean of ALL available
    12-month rolling values.

</div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| DATA FROM PHP
|--------------------------------------------------------------------------
*/

const data =
    <?= $json ?>;


/*
|--------------------------------------------------------------------------
| VALID VALUES ONLY
|--------------------------------------------------------------------------
*/

const validData =
    data.filter(
        row =>
            row.anomaly !== null
    );


/*
|--------------------------------------------------------------------------
| CHART
|--------------------------------------------------------------------------
*/

const ctx =
    document
        .getElementById(
            'temperatureChart'
        )
        .getContext('2d');


/*
 * Zero line plugin.
 */

const zeroLinePlugin = {

    id: 'zeroLine',

    afterDraw(chart) {

        const {
            ctx,
            chartArea,
            scales
        } = chart;

        if (!scales.y) {
            return;
        }

        const y =
            scales.y.getPixelForValue(0);

        if (
            y < chartArea.top ||
            y > chartArea.bottom
        ) {
            return;
        }

        ctx.save();

        ctx.beginPath();

        ctx.moveTo(
            chartArea.left,
            y
        );

        ctx.lineTo(
            chartArea.right,
            y
        );

        ctx.lineWidth = 1;

        ctx.setLineDash([5, 5]);

        ctx.strokeStyle =
            'rgba(226,232,240,0.45)';

        ctx.stroke();

        ctx.restore();
    }
};


new Chart(ctx, {

    type: 'line',

    data: {

        datasets: [

            {

                label:
                    '12-month rolling temperature anomaly',

                data:
                    data.map(
                        row => ({
                            x: row.date,
                            y: row.anomaly
                        })
                    ),

                borderWidth: 3,

                pointRadius: 0,

                tension: 0.15,

                fill: false,

                spanGaps: false

            }

        ]

    },


    plugins: [
        zeroLinePlugin
    ],


    options: {

        responsive: true,

        maintainAspectRatio: false,

        interaction: {

            mode: 'index',

            intersect: false

        },


        scales: {

            x: {

                type: 'time',

                time: {

                    unit: 'year',

                    tooltipFormat:
                        'MMM yyyy'

                },

                grid: {

                    display: false

                },

                ticks: {

                    color: '#94a3b8'

                },

                title: {

                    display: true,

                    text: 'Year',

                    color: '#94a3b8'

                }

            },


            y: {

                ticks: {

                    color: '#94a3b8',

                    callback:
                        function(value) {

                            return (
                                value.toFixed(1) +
                                ' °C'
                            );

                        }

                },

                grid: {

                    color:
                        'rgba(148,163,184,0.12)'

                },

                title: {

                    display: true,

                    text:
                        'Temperature anomaly (°C)',

                    color: '#94a3b8'

                }

            }

        },


        plugins: {

            legend: {

                labels: {

                    color: '#e2e8f0'

                }

            },


            tooltip: {

                callbacks: {

                    label:
                        function(context) {

                            const value =
                                context.parsed.y;

                            if (
                                value === null ||
                                value === undefined
                            ) {

                                return 'No data';

                            }

                            const sign =
                                value > 0
                                    ? '+'
                                    : '';

                            return (
                                'Anomaly: ' +
                                sign +
                                value.toFixed(2) +
                                ' °C'
                            );

                        }

                }

            }

        }

    }

});


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

if (validData.length > 0) {

    const values =
        validData.map(
            row => row.anomaly
        );


    const latest =
        values[values.length - 1];


    const maximum =
        Math.max(...values);


    const minimum =
        Math.min(...values);


    document
        .getElementById(
            'latestAnomaly'
        )
        .textContent =
            (
                latest >= 0
                    ? '+'
                    : ''
            ) +
            latest.toFixed(2) +
            ' °C';


    document
        .getElementById(
            'maxAnomaly'
        )
        .textContent =
            (
                maximum >= 0
                    ? '+'
                    : ''
            ) +
            maximum.toFixed(2) +
            ' °C';


    document
        .getElementById(
            'minAnomaly'
        )
        .textContent =
            (
                minimum >= 0
                    ? '+'
                    : ''
            ) +
            minimum.toFixed(2) +
            ' °C';
}


/*
|--------------------------------------------------------------------------
| LOCATION
|--------------------------------------------------------------------------
*/

function loadLocation()
{
    const lat =
        document
            .getElementById(
                'latitude'
            )
            .value;


    const lon =
        document
            .getElementById(
                'longitude'
            )
            .value;


    const params =
        new URLSearchParams();


    params.set(
        'lat',
        lat
    );


    params.set(
        'lon',
        lon
    );


    window.location.href =
        '?' +
        params.toString();
}

</script>


</body>

</html>
