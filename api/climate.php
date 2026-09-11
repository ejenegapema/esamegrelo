<?php

$latitude  = isset($_GET['lat']) ? (float)$_GET['lat'] : 42.5;
$longitude = isset($_GET['lon']) ? (float)$_GET['lon'] : 42.0;


/*
|--------------------------------------------------------------------------
| VALIDATE LATITUDE
|--------------------------------------------------------------------------
*/

if ($latitude < -90 || $latitude > 90) {
    die("Latitude must be between -90 and 90.\n");
}


/*
|--------------------------------------------------------------------------
| NORMALIZE LONGITUDE
|--------------------------------------------------------------------------
|
| Accept both:
|   0 ... 360
|
| and:
|   -180 ... +180
|
| Internally convert to:
|   0 ... <360
|
*/

$longitude = fmod($longitude, 360.0);

if ($longitude < 0) {
    $longitude += 360.0;
}


/*
|--------------------------------------------------------------------------
| SNAP TO ERA5 0.25° GRID
|--------------------------------------------------------------------------
*/

$latitudeIndex  = (int)round(($latitude + 90.0) * 4.0);
$longitudeIndex = (int)round($longitude * 4.0);


/*
|--------------------------------------------------------------------------
| Make sure indices stay inside the grid
|--------------------------------------------------------------------------
*/

$latitudeIndex = max(0, min(720, $latitudeIndex));

/*
 * 0 ... 1439 for 0 ... 359.75°
 */
$longitudeIndex = $longitudeIndex % 1440;


/*
|--------------------------------------------------------------------------
| Convert index back to exact grid coordinate
|--------------------------------------------------------------------------
*/

$actualLatitude  = $latitudeIndex / 4.0 - 90.0;
$actualLongitude = $longitudeIndex / 4.0;


/*
|--------------------------------------------------------------------------
| PRESSURE LEVEL INDICES
|--------------------------------------------------------------------------
|
| From your dataset:
|
| [3] = 925 hPa
| [0] = 1000 hPa
|
*/

$level925  = 3;
$level1000 = 0;


/*
|--------------------------------------------------------------------------
| IMPORTANT:
|
| DO NOT use [0:5]
|
| [0:5] means ONLY SIX MONTHS.
|
| We need enough data for 360-month rolling + 30 previous
| rolling values.
|
| With the dataset's full time axis, use:
|
| [0:]
|
| This requests the complete time dimension.
|--------------------------------------------------------------------------
*/

$url_925 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:1039][' .
    $level925 . '][' .
    $latitudeIndex . '][' .
    $longitudeIndex . ']';


$url_1000 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:1039][' .
    $level1000 . '][' .
    $latitudeIndex . '][' .
    $longitudeIndex . ']';


/*
|--------------------------------------------------------------------------
| DOWNLOAD FUNCTION
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

    $text = file_get_contents($url, false, $context);

    if ($text === false) {
        die(
            "Download failed.\n\n" .
            $url . "\n"
        );
    }


    /*
     * APDRC ASCII indexed rows may look like:
     *
     * [0][3][530][168], 7625.1234
     *
     * Extract everything after the comma.
     */
    preg_match_all(
        '/^\s*(?:\[\d+\])+\s*,\s*(.*?)\s*$/m',
        $text,
        $rows
    );


    $values = [];


    foreach ($rows[1] as $row) {

        /*
         * Parse decimal and scientific notation.
         */
        preg_match_all(
            '/[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?/',
            $row,
            $numbers
        );


        foreach ($numbers[0] as $number) {

            $value = (float)$number;

            if (is_finite($value)) {
                $values[] = $value;
            }
        }
    }


    if (empty($values)) {
        die(
            "No numeric data found.\n\n" .
            $url . "\n"
        );
    }


    return $values;
}


/*
|--------------------------------------------------------------------------
| DOWNLOAD GEOPOTENTIAL
|--------------------------------------------------------------------------
*/

$phi925  = getValues($url_925);
$phi1000 = getValues($url_1000);


/*
|--------------------------------------------------------------------------
| DATASET SIZE CHECK
|--------------------------------------------------------------------------
*/

if (count($phi925) !== count($phi1000)) {

    die(
        "ERROR: Different number of months.\n" .
        "925 hPa : " . count($phi925) . "\n" .
        "1000 hPa: " . count($phi1000) . "\n"
    );
}


$n = count($phi925);


/*
|--------------------------------------------------------------------------
| ROLLING PERIODS
|--------------------------------------------------------------------------
*/

$periods = [
    12,
    24,
    36,
    48,
    60,
    120,
    240,
    360
];


/*
|--------------------------------------------------------------------------
| NEED AT LEAST:
|
| 360 months for the first 360-month rolling value
| + 30 previous rolling values
|
| = 390 months
|--------------------------------------------------------------------------
*/

$minimumRequired = max($periods) + 30;

if ($n < $minimumRequired) {

    die(
        "ERROR: Not enough monthly data.\n\n" .
        "Available months: " . $n . "\n" .
        "Required at least: " . $minimumRequired . "\n"
    );
}


/*
|--------------------------------------------------------------------------
| HYPSOMETRIC CONSTANT
|--------------------------------------------------------------------------
|
| ERA5 zg = geopotential Φ in m²/s².
|
| T(K) =
|
|     Φ925 - Φ1000
|     --------------------------
|     Rd * ln(1000 / 925)
|
*/

$Rd = 287.05;

$denominator =
    $Rd * log(1000.0 / 925.0);


/*
|--------------------------------------------------------------------------
| STEP 1
|
| MONTHLY 1000–925 hPa LAYER TEMPERATURE
|--------------------------------------------------------------------------
*/

$monthlyTemperatureC = [];

for ($i = 0; $i < $n; $i++) {

    /*
     * Geopotential difference.
     */
    $deltaPhi =
        $phi925[$i] -
        $phi1000[$i];


    /*
     * Kelvin.
     */
    $temperatureK =
        $deltaPhi /
        $denominator;


    /*
     * Celsius.
     */
    $temperatureC =
        $temperatureK - 273.15;


    $monthlyTemperatureC[] =
        $temperatureC;
}


/*
|--------------------------------------------------------------------------
| STEP 2
|
| CALCULATE EACH ROLLING TEMPERATURE SERIES
|
| IMPORTANT:
| We calculate these from the MONTHLY TEMPERATURE,
| not from anomalies.
|--------------------------------------------------------------------------
*/

$rolling = [];


/*
 * Prefix sum makes calculation O(N)
 * instead of O(N × window).
 */

$prefix = array_fill(0, $n + 1, 0.0);

for ($i = 0; $i < $n; $i++) {

    $prefix[$i + 1] =
        $prefix[$i] +
        $monthlyTemperatureC[$i];
}


foreach ($periods as $window) {

    $rolling[$window] =
        array_fill(0, $n, null);


    for ($i = $window - 1; $i < $n; $i++) {

        $start =
            $i - $window + 1;

        $sum =
            $prefix[$i + 1] -
            $prefix[$start];

        $rolling[$window][$i] =
            $sum / $window;
    }
}


/*
|--------------------------------------------------------------------------
| STEP 3
|
| CALCULATE ANOMALY OF EACH ROLLING SERIES
|
| THIS IS THE CRITICAL FIX.
|
| For each rolling window:
|
| current rolling value
| -
| mean of PREVIOUS 30 rolling values
|
| Example for 12 months:
|
| anomaly at month T =
|
| rolling12[T]
| -
| mean(
|     rolling12[T-30],
|     ...
|     rolling12[T-1]
| )
|
| Same logic for 24, 36, ..., 360.
|--------------------------------------------------------------------------
*/

$rollingAnomaly = [];


/*
 * Exactly 30 previous rolling observations.
 */
$normalLength = 30;


foreach ($periods as $window) {

    $rollingAnomaly[$window] =
        array_fill(0, $n, null);


    /*
     * Prefix sum for THIS rolling series.
     *
     * This lets us calculate the previous 30
     * rolling values very efficiently.
     */
    $rPrefix =
        array_fill(0, $n + 1, 0.0);

    $rCount =
        array_fill(0, $n + 1, 0);


    for ($i = 0; $i < $n; $i++) {

        $rPrefix[$i + 1] =
            $rPrefix[$i];

        $rCount[$i + 1] =
            $rCount[$i];


        if ($rolling[$window][$i] !== null) {

            $rPrefix[$i + 1] +=
                $rolling[$window][$i];

            $rCount[$i + 1]++;
        }
    }


    /*
     * We need:
     *
     * 30 previous rolling values
     *
     * so:
     *
     * i >= (window - 1) + 30
     */
    $firstAnomalyIndex =
        ($window - 1) +
        $normalLength;


    for (
        $i = $firstAnomalyIndex;
        $i < $n;
        $i++
    ) {

        /*
         * Previous 30 rolling values:
         *
         * i-30 ... i-1
         */
        $start =
            $i - $normalLength;

        $end =
            $i;


        $sum =
            $rPrefix[$end] -
            $rPrefix[$start];


        $count =
            $rCount[$end] -
            $rCount[$start];


        /*
         * Require exactly 30 valid previous values.
         */
        if ($count !== $normalLength) {
            continue;
        }


        $previous30Mean =
            $sum / $normalLength;


        /*
         * THE ANOMALY
         */
        $rollingAnomaly[$window][$i] =
            $rolling[$window][$i] -
            $previous30Mean;
    }
}


/*
|--------------------------------------------------------------------------
| STEP 4
|
| BUILD DATES
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Change this if the APDRC monthly series starts
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
| STEP 5
|
| PREPARE JSON
|--------------------------------------------------------------------------
*/

$chartData = [];

for ($i = 0; $i < $n; $i++) {

    $row = [
        'date' => $dates[$i]
    ];


    foreach ($periods as $window) {

        $value =
            $rollingAnomaly[$window][$i];


        $row[(string)$window] =
            $value === null
                ? null
                : round($value, 4);
    }


    $chartData[] =
        $row;
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

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
    1000–925 hPa Temperature Anomaly
</title>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>


<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 24px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f4f6f8;
}

.container {
    max-width: 1500px;
    margin: auto;

    background: white;

    border-radius: 18px;

    padding: 25px;

    box-shadow:
        0 8px 35px
        rgba(0,0,0,0.08);
}

h1 {
    margin:
        0 0 6px 0;

    font-size: 26px;
}

.subtitle {
    color: #666;

    margin-bottom: 20px;

    font-size: 14px;
}

.location {
    display: flex;

    flex-wrap: wrap;

    gap: 10px;

    margin-bottom: 18px;
}

.location label {
    display: flex;

    align-items: center;

    gap: 6px;

    font-size: 14px;
}

.location input {
    width: 110px;

    padding: 7px 9px;

    border: 1px solid #ccc;

    border-radius: 7px;

    font-size: 14px;
}

button {
    padding:
        7px 13px;

    border: 0;

    border-radius: 7px;

    cursor: pointer;

    font-size: 14px;
}

.periods {
    display: flex;

    flex-wrap: wrap;

    gap: 7px;

    margin-bottom: 20px;
}

.periods label {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    background: #eef1f4;

    padding:
        7px 10px;

    border-radius: 8px;

    cursor: pointer;

    font-size: 13px;
}

.chart-wrap {
    position: relative;

    width: 100%;

    height: 700px;
}

.metadata {
    margin-top: 16px;

    font-size: 13px;

    color: #666;
}

</style>

</head>


<body>


<div class="container">


<h1>
    1000–925 hPa Temperature Anomaly
</h1>


<div class="subtitle">

    Anomaly of each rolling-period temperature
    relative to the previous 30 rolling values.

</div>


<div class="location">

    <label>

        Latitude:

        <input
            id="latitude"
            type="number"
            step="0.25"
            min="-90"
            max="90"
            value="<?= htmlspecialchars((string)$latitude) ?>"
        >

    </label>


    <label>

        Longitude:

        <input
            id="longitude"
            type="number"
            step="0.25"
            value="<?= htmlspecialchars((string)$longitude) ?>"
        >

    </label>


    <button
        onclick="changeLocation()"
    >
        Load location
    </button>

</div>


<div class="metadata">

    Requested:
    <?= htmlspecialchars((string)$latitude) ?>°,
    <?= htmlspecialchars((string)$longitude) ?>°

    &nbsp;→&nbsp;

    Grid:
    <?= $actualLatitude ?>°,
    <?= $actualLongitude ?>°

    &nbsp;→&nbsp;

    indices:
    <?= $latitudeIndex ?>,
    <?= $longitudeIndex ?>

</div>


<br>


<div class="periods">

<?php foreach ($periods as $months): ?>

    <label>

        <input
            type="checkbox"
            class="period-toggle"
            value="<?= $months ?>"
            <?= $months === 12 ? 'checked' : '' ?>
        >

        <?= $months ?> months

    </label>

<?php endforeach; ?>

</div>


<div class="chart-wrap">

    <canvas id="chart"></canvas>

</div>


<div class="metadata">

    <b>Calculation:</b>

    Current rolling temperature − mean of the
    previous 30 rolling-temperature values.

    &nbsp; | &nbsp;

    Layer: 1000–925 hPa.

</div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| PHP DATA
|--------------------------------------------------------------------------
*/

const data = <?= $json ?>;


/*
|--------------------------------------------------------------------------
| ROLLING PERIODS
|--------------------------------------------------------------------------
*/

const periods = [
    12,
    24,
    36,
    48,
    60,
    120,
    240,
    360
];


/*
|--------------------------------------------------------------------------
| BUILD DATASETS
|--------------------------------------------------------------------------
*/

const datasets =
    periods.map((months) => {

        return {

            label:
                `${months}-month anomaly`,

            data:
                data.map(row => ({
                    x: row.date,
                    y: row[String(months)]
                })),

            hidden:
                months !== 12,

            pointRadius: 0,

            borderWidth:
                months >= 120
                    ? 3
                    : 2,

            tension: 0.12,

            fill: false,

            spanGaps: false
        };

    });


/*
|--------------------------------------------------------------------------
| CHART
|--------------------------------------------------------------------------
*/

const ctx =
    document
        .getElementById('chart')
        .getContext('2d');


const chart =
    new Chart(ctx, {

        type: 'line',

        data: {

            datasets:
                datasets
        },

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

                    title: {

                        display: true,

                        text: 'Year'
                    }
                },


                y: {

                    title: {

                        display: true,

                        text:
                            'Temperature anomaly (°C)'
                    },

                    ticks: {

                        callback:
                            function(value) {

                                return (
                                    value +
                                    ' °C'
                                );

                            }
                    }
                }

            },


            plugins: {

                legend: {

                    position: 'top',

                    display: true
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

                                    return (
                                        context.dataset.label +
                                        ': —'
                                    );
                                }

                                return (
                                    context.dataset.label +
                                    ': ' +
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
| CHECKBOXES
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.period-toggle')
    .forEach(
        checkbox => {

            checkbox.addEventListener(
                'change',
                function() {

                    const months =
                        Number(this.value);


                    const dataset =
                        chart.data.datasets.find(
                            ds =>
                                ds.label ===
                                `${months}-month anomaly`
                        );


                    if (dataset) {

                        dataset.hidden =
                            !this.checked;

                        chart.update();
                    }

                }
            );

        }
    );


/*
|--------------------------------------------------------------------------
| CHANGE LOCATION
|--------------------------------------------------------------------------
*/

function changeLocation()
{
    const lat =
        document
            .getElementById('latitude')
            .value;

    const lon =
        document
            .getElementById('longitude')
            .value;


    const params =
        new URLSearchParams();


    params.set('lat', lat);
    params.set('lon', lon);


    window.location.href =
        '?' + params.toString();
}

</script>


</body>

</html>
