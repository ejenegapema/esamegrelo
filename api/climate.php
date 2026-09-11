<?php

$latitude =
    isset($_GET['lat'])
        ? (float)$_GET['lat']
        : 42.5;

$longitude =
    isset($_GET['lon'])
        ? (float)$_GET['lon']
        : 42.0;

$period =
    isset($_GET['period'])
        ? strtoupper(trim($_GET['period']))
        : 'YEAR';


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
|
| Internally:
|   0 ... <360
|--------------------------------------------------------------------------
*/

$longitude =
    fmod($longitude, 360.0);

if ($longitude < 0) {
    $longitude += 360.0;
}


/*
|--------------------------------------------------------------------------
| ERA5 0.25° GRID
|--------------------------------------------------------------------------
|
| Latitude:
|
|   index = (lat + 90) * 4
|
| Longitude:
|
|   index = lon * 4
|--------------------------------------------------------------------------
*/

$latitudeIndex =
    (int)round(
        ($latitude + 90.0) * 4.0
    );

$longitudeIndex =
    (int)round(
        $longitude * 4.0
    );


/*
|--------------------------------------------------------------------------
| GRID LIMITS
|--------------------------------------------------------------------------
*/

$latitudeIndex =
    max(
        0,
        min(
            720,
            $latitudeIndex
        )
    );

$longitudeIndex =
    max(
        0,
        min(
            1439,
            $longitudeIndex
        )
    );


/*
|--------------------------------------------------------------------------
| ACTUAL GRID COORDINATES
|--------------------------------------------------------------------------
*/

$actualLatitude =
    $latitudeIndex / 4.0 - 90.0;

$actualLongitude =
    $longitudeIndex / 4.0;


/*
|--------------------------------------------------------------------------
| DATASET URLS
|--------------------------------------------------------------------------
|
| 925 hPa:
|   level index 3
|
| 1000 hPa:
|   level index 0
|
| [0:] = complete time dimension
|--------------------------------------------------------------------------
*/

$url_925 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:1039][3][' .
    $latitudeIndex .
    '][' .
    $longitudeIndex .
    ']';


$url_1000 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:1039][0][' .
    $latitudeIndex .
    '][' .
    $longitudeIndex .
    ']';


/*
|--------------------------------------------------------------------------
| DOWNLOAD + PARSE
|--------------------------------------------------------------------------
*/

function getValues(string $url): array
{
    $context =
        stream_context_create([
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
     * Typical APDRC data:
     *
     * [0][3][530][168], 7644.9766
     */
    preg_match_all(
        '/^\s*(?:\[\d+\])+\s*,\s*(.*?)\s*$/m',
        $text,
        $rows
    );


    $values = [];


    foreach ($rows[1] as $row) {

        /*
         * Decimal + scientific notation.
         */
        preg_match_all(
            '/[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?/',
            $row,
            $numbers
        );


        foreach ($numbers[0] as $number) {

            $value =
                (float)$number;


            if (is_finite($value)) {

                $values[] =
                    $value;
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
| LOAD DATA
|--------------------------------------------------------------------------
*/

$phi925 =
    getValues($url_925);

$phi1000 =
    getValues($url_1000);


/*
|--------------------------------------------------------------------------
| VALIDATE DATA LENGTH
|--------------------------------------------------------------------------
*/

if (
    count($phi925) !==
    count($phi1000)
) {

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


if ($n < 12) {

    die(
        "At least 12 months of data are required."
    );
}


/*
|--------------------------------------------------------------------------
| HYPSOMETRIC EQUATION
|--------------------------------------------------------------------------
|
| ERA5 zg = geopotential Φ [m²/s²]
|
| T(K) =
|
| (Phi925 - Phi1000)
| -----------------------------
| Rd × ln(1000 / 925)
|
|--------------------------------------------------------------------------
*/

$Rd =
    287.05;


$denominator =
    $Rd *
    log(
        1000.0 / 925.0
    );


/*
|--------------------------------------------------------------------------
| STEP 1
|
| MONTHLY 1000–925 hPa TEMPERATURE
|--------------------------------------------------------------------------
*/

$monthlyTemperatureC = [];


for ($i = 0; $i < $n; $i++) {

    /*
     * Geopotential thickness in geopotential units.
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
        $temperatureK -
        273.15;


    $monthlyTemperatureC[] =
        $temperatureC;
}


/*
|--------------------------------------------------------------------------
| DATASET START DATE
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| Change this if the APDRC time series starts at another month.
|--------------------------------------------------------------------------
*/

$startDate =
    new DateTime(
        '1940-01-01'
    );


/*
|--------------------------------------------------------------------------
| CREATE TIME ARRAYS
|--------------------------------------------------------------------------
*/

$years = [];
$months = [];
$dates = [];


for ($i = 0; $i < $n; $i++) {

    $date =
        clone $startDate;


    $date->modify(
        "+{$i} months"
    );


    $years[$i] =
        (int)$date->format('Y');


    $months[$i] =
        (int)$date->format('n');


    $dates[$i] =
        $date->format('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| BUILD YEAR -> MONTH -> TEMPERATURE
|--------------------------------------------------------------------------
*/

$yearlyData = [];


for ($i = 0; $i < $n; $i++) {

    $year =
        $years[$i];

    $month =
        $months[$i];


    if (!isset($yearlyData[$year])) {

        $yearlyData[$year] = [
            'months' => []
        ];
    }


    $yearlyData[$year]['months'][$month] =
        $monthlyTemperatureC[$i];
}


/*
|--------------------------------------------------------------------------
| MONTH CLIMATOLOGY
|--------------------------------------------------------------------------
|
| All available Januaries
| All available Februaries
| ...
|--------------------------------------------------------------------------
*/

$monthSums =
    array_fill(
        1,
        12,
        0.0
    );


$monthCounts =
    array_fill(
        1,
        12,
        0
    );


for ($i = 0; $i < $n; $i++) {

    $month =
        $months[$i];

    $value =
        $monthlyTemperatureC[$i];


    if (is_finite($value)) {

        $monthSums[$month] +=
            $value;

        $monthCounts[$month]++;
    }
}


$monthClimate =
    array_fill(
        1,
        12,
        null
    );


for ($month = 1; $month <= 12; $month++) {

    if ($monthCounts[$month] > 0) {

        $monthClimate[$month] =
            $monthSums[$month] /
            $monthCounts[$month];
    }
}


/*
|--------------------------------------------------------------------------
| FULL YEAR TEMPERATURE
|--------------------------------------------------------------------------
|
| Full year = mean of Jan...Dec.
|--------------------------------------------------------------------------
*/

$yearTemperature = [];


foreach (
    $yearlyData
    as $year => $info
) {

    /*
     * Require all 12 months.
     */
    if (
        count($info['months']) === 12
    ) {

        $yearTemperature[$year] =
            array_sum(
                $info['months']
            ) / 12.0;
    }
}


/*
|--------------------------------------------------------------------------
| FULL YEAR CLIMATE NORMAL
|--------------------------------------------------------------------------
*/

$yearClimate =
    null;


if (!empty($yearTemperature)) {

    $yearClimate =
        array_sum(
            $yearTemperature
        ) /
        count($yearTemperature);
}


/*
|--------------------------------------------------------------------------
| 12-MONTH ROLLING TEMPERATURE
|--------------------------------------------------------------------------
|
| trailing 12-month mean:
|
|   T12(i) =
|   mean(
|       T(i-11),
|       ...
|       T(i)
|   )
|--------------------------------------------------------------------------
*/

$rolling12 =
    array_fill(
        0,
        $n,
        null
    );


/*
 * Prefix sum for O(N) calculation.
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
| 12-MONTH ALL-PERIOD CLIMATE NORMAL
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| This is the mean of ALL available 12-month
| rolling-temperature values.
|
| It is NOT a moving 30-year normal.
|--------------------------------------------------------------------------
*/

$rolling12Sum =
    0.0;


$rolling12Count =
    0;


for ($i = 0; $i < $n; $i++) {

    if ($rolling12[$i] !== null) {

        $rolling12Sum +=
            $rolling12[$i];

        $rolling12Count++;
    }
}


if ($rolling12Count > 0) {

    $rolling12Climate =
        $rolling12Sum /
        $rolling12Count;

} else {

    $rolling12Climate =
        null;
}


/*
|--------------------------------------------------------------------------
| SEASONS
|--------------------------------------------------------------------------
|
| DJF 2020:
|
|   December 2019
|   January  2020
|   February 2020
|
|--------------------------------------------------------------------------
*/

$seasonDefinitions = [

    'DJF' => [
        12,
        1,
        2
    ],

    'MAM' => [
        3,
        4,
        5
    ],

    'JJA' => [
        6,
        7,
        8
    ],

    'SON' => [
        9,
        10,
        11
    ]

];


/*
|--------------------------------------------------------------------------
| BUILD SEASON SERIES
|--------------------------------------------------------------------------
*/

$seasonSeries = [];


foreach (
    $seasonDefinitions
    as $season => $seasonMonths
) {

    $seasonSeries[$season] =
        [];


    /*
     * The season year is the year containing
     * January and February.
     */
    foreach (
        $yearlyData
        as $year => $info
    ) {

        $values = [];


        foreach (
            $seasonMonths
            as $month
        ) {

            /*
             * DJF December comes from previous year.
             */
            if (
                $season === 'DJF' &&
                $month === 12
            ) {

                $targetYear =
                    $year - 1;

            } else {

                $targetYear =
                    $year;
            }


            if (
                isset(
                    $yearlyData[
                        $targetYear
                    ]['months'][$month]
                )
            ) {

                $values[] =
                    $yearlyData[
                        $targetYear
                    ]['months'][$month];
            }
        }


        /*
         * Require all 3 months.
         */
        if (count($values) === 3) {

            $seasonSeries[$season][$year] =
                array_sum($values) /
                3.0;
        }
    }
}


/*
|--------------------------------------------------------------------------
| SEASON CLIMATOLOGY
|--------------------------------------------------------------------------
*/

$seasonClimate = [];


foreach (
    $seasonSeries
    as $season => $values
) {

    if (!empty($values)) {

        $seasonClimate[$season] =
            array_sum($values) /
            count($values);

    } else {

        $seasonClimate[$season] =
            null;
    }
}


/*
|--------------------------------------------------------------------------
| PERIOD DEFINITIONS
|--------------------------------------------------------------------------
*/

$validPeriods = [

    'YEAR',

    'ROLL12',

    'JAN',
    'FEB',
    'MAR',
    'APR',
    'MAY',
    'JUN',
    'JUL',
    'AUG',
    'SEP',
    'OCT',
    'NOV',
    'DEC',

    'DJF',
    'MAM',
    'JJA',
    'SON'

];


if (
    !in_array(
        $period,
        $validPeriods,
        true
    )
) {

    $period =
        'YEAR';
}


/*
|--------------------------------------------------------------------------
| MONTH CODE MAP
|--------------------------------------------------------------------------
*/

$monthCodes = [

    'JAN' => 1,
    'FEB' => 2,
    'MAR' => 3,
    'APR' => 4,
    'MAY' => 5,
    'JUN' => 6,
    'JUL' => 7,
    'AUG' => 8,
    'SEP' => 9,
    'OCT' => 10,
    'NOV' => 11,
    'DEC' => 12

];


/*
|--------------------------------------------------------------------------
| PREPARE CHART DATA
|--------------------------------------------------------------------------
*/

$chartRows = [];


/*
|--------------------------------------------------------------------------
| FULL YEAR
|--------------------------------------------------------------------------
*/

if ($period === 'YEAR') {

    if ($yearClimate !== null) {

        foreach (
            $yearTemperature
            as $year => $temperature
        ) {

            $anomaly =
                $temperature -
                $yearClimate;


            $chartRows[] = [

                'x' =>
                    $year . '-01-01',

                'anomaly' =>
                    round(
                        $anomaly,
                        1
                    )

            ];
        }
    }


/*
|--------------------------------------------------------------------------
| 12-MONTH ROLLING
|--------------------------------------------------------------------------
|
| Climate normal =
| mean of ALL available 12-month rolling values.
|--------------------------------------------------------------------------
*/

} elseif ($period === 'ROLL12') {

    if ($rolling12Climate !== null) {

        for ($i = 11; $i < $n; $i++) {

            if ($rolling12[$i] === null) {
                continue;
            }


            $anomaly =
                $rolling12[$i] -
                $rolling12Climate;


            $chartRows[] = [

                'x' =>
                    $dates[$i],

                'anomaly' =>
                    round(
                        $anomaly,
                        1
                    )

            ];
        }
    }


/*
|--------------------------------------------------------------------------
| MONTH
|--------------------------------------------------------------------------
*/

} elseif (
    isset(
        $monthCodes[$period]
    )
) {

    $month =
        $monthCodes[$period];


    $climate =
        $monthClimate[$month];


    if ($climate !== null) {

        foreach (
            $yearlyData
            as $year => $info
        ) {

            if (
                !isset(
                    $info['months'][$month]
                )
            ) {
                continue;
            }


            $temperature =
                $info['months'][$month];


            $anomaly =
                $temperature -
                $climate;


            $chartRows[] = [

                'x' =>
                    $year . '-01-01',

                'anomaly' =>
                    round(
                        $anomaly,
                        1
                    )

            ];
        }
    }


/*
|--------------------------------------------------------------------------
| SEASON
|--------------------------------------------------------------------------
*/

} elseif (
    isset(
        $seasonDefinitions[$period]
    )
) {

    $climate =
        $seasonClimate[$period];


    if ($climate !== null) {

        foreach (
            $seasonSeries[$period]
            as $year => $temperature
        ) {

            $anomaly =
                $temperature -
                $climate;


            $chartRows[] = [

                'x' =>
                    $year . '-01-01',

                'anomaly' =>
                    round(
                        $anomaly,
                        1
                    )

            ];
        }
    }
}


/*
|--------------------------------------------------------------------------
| SORT CHART DATA
|--------------------------------------------------------------------------
*/

usort(
    $chartRows,
    function (
        $a,
        $b
    ) {

        return strcmp(
            $a['x'],
            $b['x']
        );
    }
);


/*
|--------------------------------------------------------------------------
| JSON
|--------------------------------------------------------------------------
*/

$json =
    json_encode(
        $chartRows,
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
    ERA5 1000–925 hPa Temperature Anomaly
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


<script
    src="https://cdn.jsdelivr.net/npm/chart.js"
></script>

<script
    src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"
></script>


<style>

/* =========================================================
   YOUR STYLE
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
  background: #3b82f6;
  color: #ffffff;
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
   CONTROLS
========================================================= */

.period-row {
    text-align: center;
    margin-bottom: 12px;
}

.location-row {
    text-align: center;
    margin-bottom: 10px;
}

select {
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid #ccc;
    background: #fff;
    color: #111;
    font-family: inherit;
    font-weight: 500;
    cursor: pointer;
}

</style>

</head>


<body>


<div class="container">


<h1>
    1000–925 hPa Temperature Anomaly
</h1>


<div class="date-picker">


    <!-- PERIOD SELECTOR -->

    <div class="period-row">

        <label for="period">
            Period:
        </label>


        <select
            id="period"
            onchange="changePeriod()"
        >

            <optgroup label="Annual">

                <option
                    value="YEAR"
                    <?= $period === 'YEAR'
                        ? 'selected'
                        : '' ?>
                >
                    Full Year
                    (January–December)
                </option>


                <option
                    value="ROLL12"
                    <?= $period === 'ROLL12'
                        ? 'selected'
                        : '' ?>
                >
                    12-Month Rolling
                </option>

            </optgroup>


            <optgroup label="Months">

                <?php foreach (
                    $monthNames = [
                        1  => 'January',
                        2  => 'February',
                        3  => 'March',
                        4  => 'April',
                        5  => 'May',
                        6  => 'June',
                        7  => 'July',
                        8  => 'August',
                        9  => 'September',
                        10 => 'October',
                        11 => 'November',
                        12 => 'December'
                    ]
                    as $number => $name
                ): ?>

                    <?php

                    $code =
                        array_search(
                            $number,
                            $monthCodes,
                            true
                        );

                    ?>

                    <option
                        value="<?= $code ?>"
                        <?= $period === $code
                            ? 'selected'
                            : '' ?>
                    >
                        <?= htmlspecialchars($name) ?>
                    </option>

                <?php endforeach; ?>

            </optgroup>


            <optgroup label="Seasons">

                <option
                    value="DJF"
                    <?= $period === 'DJF'
                        ? 'selected'
                        : '' ?>
                >
                    December–February
                </option>


                <option
                    value="MAM"
                    <?= $period === 'MAM'
                        ? 'selected'
                        : '' ?>
                >
                    March–May
                </option>


                <option
                    value="JJA"
                    <?= $period === 'JJA'
                        ? 'selected'
                        : '' ?>
                >
                    June–August
                </option>


                <option
                    value="SON"
                    <?= $period === 'SON'
                        ? 'selected'
                        : '' ?>
                >
                    September–November
                </option>

            </optgroup>

        </select>

    </div>


    <!-- LOCATION -->

    <div class="location-row">

        <label>
            Latitude:
        </label>


        <input
            type="number"
            id="latitude"
            min="-90"
            max="90"
            step="0.25"
            value="<?= htmlspecialchars(
                (string)$latitude
            ) ?>"
        >


        <label>
            Longitude:
        </label>


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
            Update
        </button>

    </div>

</div>


<div class="chart-container">

    <canvas
        id="temperatureChart"
    ></canvas>

</div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| CHART DATA
|--------------------------------------------------------------------------
*/

const data =
    <?= $json ?>;


/*
|--------------------------------------------------------------------------
| ZERO LINE
|--------------------------------------------------------------------------
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


        ctx.lineWidth =
            1;


        ctx.setLineDash([
            5,
            5
        ]);


        ctx.strokeStyle =
            'rgba(255,255,255,0.35)';


        ctx.stroke();


        ctx.restore();

    }

};


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


new Chart(
    ctx,
    {

        type: 'line',


        data: {

            datasets: [

                {

                    label:
                        'Temperature anomaly',

                    data:
                        data,

                    parsing: {

                        xAxisKey:
                            'x',

                        yAxisKey:
                            'anomaly'

                    },

                    borderColor:
                        '#ffffff',

                    backgroundColor:
                        'transparent',

                    borderWidth:
                        2.5,

                    pointRadius:
                        1,

                    pointHoverRadius:
                        5,

                    tension:
                        0.12,

                    fill:
                        false

                }

            ]

        },


        plugins: [
            zeroLinePlugin
        ],


        options: {

            responsive:
                true,

            maintainAspectRatio:
                false,

            interaction: {

                mode:
                    'index',

                intersect:
                    false

            },


            scales: {

                x: {

                    type:
                        'time',

                    time: {

                        unit:
                            'year',

                        tooltipFormat:
                            'yyyy'

                    },

                    grid: {

                        color:
                            'rgba(255,255,255,0.06)'

                    },

                    ticks: {

                        color:
                            '#94a3b8',
                        maxRotation: 
                            90,
                        minRotation: 
                            90

                    },

                    title: {

                        display:
                            true,

                        text:
                            'Year',

                        color:
                            '#94a3b8'

                    }

                },


                y: {

                    grid: {

                        color:
                            'rgba(255,255,255,0.08)'

                    },

                    ticks: {

                        color:
                            '#94a3b8',

                        callback:
                            function(value) {

                                const number =
                                    Number(value);

                                const sign =
                                    number > 0
                                        ? '+'
                                        : '';

                                return (
                                    sign +
                                    number.toFixed(1) +
                                    ''
                                );

                            }

                    },

                    title: {

                        display:
                            true,

                        text:
                            'Temperature anomaly (°C)',

                        color:
                            '#94a3b8'

                    }

                }

            },


            plugins: {

                legend: {

                    display:
                        false

                },


                tooltip: {

                    displayColors:
                        false,


                    callbacks: {

                        title:
                            function(items) {

                                if (!items.length) {
                                    return '';
                                }


                                return String(
                                    items[0]
                                        .raw
                                        .x
                                ).substring(
                                    0,
                                    4
                                );

                            },


                        label:
                            function(context) {

                                const value =
                                    Number(
                                        context.raw.anomaly
                                    );


                                const sign =
                                    value >= 0
                                        ? '+'
                                        : '';


                                return (
                                    sign +
                                    value.toFixed(1) +
                                    ' °C'
                                );

                            }

                    }

                }

            }

        }

    }
);


/*
|--------------------------------------------------------------------------
| CHANGE PERIOD
|--------------------------------------------------------------------------
*/

function changePeriod()
{
    const period =
        document
            .getElementById(
                'period'
            )
            .value;


    const params =
        new URLSearchParams(
            window.location.search
        );


    params.set(
        'period',
        period
    );


    window.location.href =
        '?' +
        params.toString();
}


/*
|--------------------------------------------------------------------------
| CHANGE LOCATION
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


    const period =
        document
            .getElementById(
                'period'
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


    params.set(
        'period',
        period
    );


    window.location.href =
        '?' +
        params.toString();
}

</script>


</body>

</html>
