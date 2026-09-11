<?php

/*
|--------------------------------------------------------------------------
| LOCATION
|--------------------------------------------------------------------------
*/

$latitude  = isset($_GET['lat']) ? (float)$_GET['lat'] : 42.5;
$longitude = isset($_GET['lon']) ? (float)$_GET['lon'] : 42.0;

$period = isset($_GET['period']) ? strtoupper($_GET['period']) : 'ALL';


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
| ERA5 GRID
|--------------------------------------------------------------------------
|
| 0.25° grid:
|
| latitude index  = (lat + 90) * 4
| longitude index = lon * 4
|
|--------------------------------------------------------------------------
*/

$latitudeIndex =
    (int)round(($latitude + 90.0) * 4.0);

$longitudeIndex =
    (int)round($longitude * 4.0);

$latitudeIndex =
    max(0, min(720, $latitudeIndex));

$longitudeIndex =
    max(0, min(1439, $longitudeIndex));


$actualLatitude =
    $latitudeIndex / 4.0 - 90.0;

$actualLongitude =
    $longitudeIndex / 4.0;


/*
|--------------------------------------------------------------------------
| DATASET URLS
|--------------------------------------------------------------------------
|
| 925 hPa  = level index 3
| 1000 hPa = level index 0
|
| [0:] = complete time dimension
|
|--------------------------------------------------------------------------
*/

$url_925 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:][3][' .
    $latitudeIndex . '][' .
    $longitudeIndex . ']';


$url_1000 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Geopotential.ascii?zg[0:][0][' .
    $latitudeIndex . '][' .
    $longitudeIndex . ']';


/*
|--------------------------------------------------------------------------
| DOWNLOAD + PARSE
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

            $value = (float)$number;

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
| GET DATA
|--------------------------------------------------------------------------
*/

$phi925  = getValues($url_925);
$phi1000 = getValues($url_1000);


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


$n = count($phi925);


/*
|--------------------------------------------------------------------------
| HYPSOMETRIC CALCULATION
|--------------------------------------------------------------------------
|
| ERA5 zg is geopotential Φ in m²/s².
|
| T(K) =
|
| (Φ925 - Φ1000)
| --------------------------
| Rd * ln(1000 / 925)
|
|--------------------------------------------------------------------------
*/

$Rd = 287.05;

$denominator =
    $Rd *
    log(1000.0 / 925.0);


/*
|--------------------------------------------------------------------------
| MONTHLY TEMPERATURE
|--------------------------------------------------------------------------
*/

$monthlyTemperatureC = [];


for ($i = 0; $i < $n; $i++) {

    $deltaPhi =
        $phi925[$i] -
        $phi1000[$i];


    $temperatureK =
        $deltaPhi /
        $denominator;


    $temperatureC =
        $temperatureK -
        273.15;


    $monthlyTemperatureC[] =
        $temperatureC;
}


/*
|--------------------------------------------------------------------------
| TIME AXIS
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Change this if your APDRC dataset starts
| at another month.
|--------------------------------------------------------------------------
*/

$startDate =
    new DateTime('1940-01-01');


$dates = [];

$years = [];
$months = [];


for ($i = 0; $i < $n; $i++) {

    $date =
        clone $startDate;

    $date->modify(
        "+{$i} months"
    );


    $dates[] =
        $date->format('Y-m-d');

    $years[] =
        (int)$date->format('Y');

    $months[] =
        (int)$date->format('n');
}


/*
|--------------------------------------------------------------------------
| ALL-PERIOD MONTHLY CLIMATOLOGY
|--------------------------------------------------------------------------
|
| January climatology = mean of ALL Januaries
| February climatology = mean of ALL Februaries
| ...
|--------------------------------------------------------------------------
*/

$monthlySums =
    array_fill(1, 12, 0.0);

$monthlyCounts =
    array_fill(1, 12, 0);


for ($i = 0; $i < $n; $i++) {

    $month =
        $months[$i];

    $value =
        $monthlyTemperatureC[$i];


    if (is_finite($value)) {

        $monthlySums[$month] +=
            $value;

        $monthlyCounts[$month]++;
    }
}


$monthlyClimatology =
    array_fill(1, 12, null);


for ($month = 1; $month <= 12; $month++) {

    if ($monthlyCounts[$month] > 0) {

        $monthlyClimatology[$month] =
            $monthlySums[$month] /
            $monthlyCounts[$month];
    }
}


/*
|--------------------------------------------------------------------------
| BUILD ANNUAL MONTHLY SERIES
|--------------------------------------------------------------------------
|
| Used for January ... December.
|--------------------------------------------------------------------------
*/

$annualMonthSeries = [];


for ($month = 1; $month <= 12; $month++) {

    $annualMonthSeries[$month] = [];
}


for ($i = 0; $i < $n; $i++) {

    $month =
        $months[$i];

    $year =
        $years[$i];

    $temperature =
        $monthlyTemperatureC[$i];


    if (!isset($annualMonthSeries[$month][$year])) {

        $annualMonthSeries[$month][$year] =
            [];
    }


    $annualMonthSeries[$month][$year][] =
        $temperature;
}


/*
|--------------------------------------------------------------------------
| SEASONS
|--------------------------------------------------------------------------
|
| DJF:
| December + January + February
|
| MAM:
| March + April + May
|
| JJA:
| June + July + August
|
| SON:
| September + October + November
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
| BUILD SEASONAL MEANS
|--------------------------------------------------------------------------
|
| DJF is assigned to the YEAR of January/February.
|
| Example:
|
| December 2020
| January 2021
| February 2021
|
| becomes:
|
| DJF 2021
|--------------------------------------------------------------------------
*/

$seasonSeries = [];


foreach ($seasonDefinitions as $season => $seasonMonths) {

    $seasonSeries[$season] = [];

}


/*
 * We process every year.
 */
$uniqueYears =
    array_values(
        array_unique($years)
    );

sort($uniqueYears);


foreach ($seasonDefinitions as $season => $seasonMonths) {

    foreach ($uniqueYears as $year) {

        $values = [];


        if ($season === 'DJF') {

            /*
             * December belongs to previous year.
             */
            foreach ($seasonMonths as $month) {

                if ($month === 12) {

                    $targetYear =
                        $year - 1;

                } else {

                    $targetYear =
                        $year;
                }


                for ($i = 0; $i < $n; $i++) {

                    if (
                        $years[$i] === $targetYear &&
                        $months[$i] === $month
                    ) {

                        $values[] =
                            $monthlyTemperatureC[$i];

                        break;
                    }
                }
            }

        } else {

            foreach ($seasonMonths as $month) {

                for ($i = 0; $i < $n; $i++) {

                    if (
                        $years[$i] === $year &&
                        $months[$i] === $month
                    ) {

                        $values[] =
                            $monthlyTemperatureC[$i];

                        break;
                    }
                }
            }
        }


        /*
         * Require all 3 months.
         */
        if (count($values) === 3) {

            $seasonSeries[$season][$year] =
                array_sum($values) / 3.0;
        }
    }
}


/*
|--------------------------------------------------------------------------
| SEASON CLIMATOLOGY
|--------------------------------------------------------------------------
*/

$seasonClimatology = [];


foreach ($seasonDefinitions as $season => $unused) {

    $values =
        array_values(
            $seasonSeries[$season]
        );


    if (!empty($values)) {

        $seasonClimatology[$season] =
            array_sum($values) /
            count($values);

    } else {

        $seasonClimatology[$season] =
            null;
    }
}


/*
|--------------------------------------------------------------------------
| ALLOWED PERIODS
|--------------------------------------------------------------------------
*/

$allowedPeriods = [

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


if (!in_array($period, $allowedPeriods, true)) {
    $period = 'JAN';
}


/*
|--------------------------------------------------------------------------
| BUILD CHART SERIES
|--------------------------------------------------------------------------
*/

$chartRows = [];

$selectedClimatology = null;

$selectedLabel = 'January';


/*
|--------------------------------------------------------------------------
| MONTH
|--------------------------------------------------------------------------
*/

$monthMap = [

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


$monthNames = [

    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'

];


if (isset($monthMap[$period])) {

    $monthNumber =
        $monthMap[$period];

    $selectedClimatology =
        $monthlyClimatology[$monthNumber];

    $selectedLabel =
        $monthNames[$monthNumber];


    foreach (
        $annualMonthSeries[$monthNumber]
        as $year => $values
    ) {

        if (empty($values)) {
            continue;
        }


        $temperature =
            array_sum($values) /
            count($values);


        $anomaly =
            $temperature -
            $selectedClimatology;


        $chartRows[] = [

            'x' =>
                $year . '-01-01',

            'temperature' =>
                round($temperature, 4),

            'anomaly' =>
                round($anomaly, 4)

        ];
    }


} else {

    /*
     * SEASON
     */

    $selectedClimatology =
        $seasonClimatology[$period];

    $selectedLabel =
        $period;


    foreach (
        $seasonSeries[$period]
        as $year => $temperature
    ) {

        $anomaly =
            $temperature -
            $selectedClimatology;


        $chartRows[] = [

            'x' =>
                $year . '-01-01',

            'temperature' =>
                round($temperature, 4),

            'anomaly' =>
                round($anomaly, 4)

        ];
    }
}


/*
|--------------------------------------------------------------------------
| SORT CHART DATA
|--------------------------------------------------------------------------
*/

usort(
    $chartRows,
    function ($a, $b) {

        return strcmp(
            $a['x'],
            $b['x']
        );
    }
);


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


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>


<style>

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


/*
|--------------------------------------------------------------------------
| ADDITIONAL CONTROLS
|--------------------------------------------------------------------------
*/

.period-select {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 8px;
}

select {
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid #ccc;
    background: #fff;
    color: #111;
    font-family: inherit;
    cursor: pointer;
}

.location-row {
    margin-top: 10px;
    text-align: center;
}

.climatology {
    text-align: center;
    color: #94a3b8;
    font-size: 0.9rem;
    margin-bottom: 1rem;
}

.anomaly-value {
    color: #e2e8f0;
    font-weight: 600;
}

</style>

</head>


<body>


<div class="container">


<h1>
    1000–925 hPa Temperature Anomaly
</h1>


<div class="date-picker">


    <div class="period-select">

        <label for="period">
            Period:
        </label>

        <select
            id="period"
            onchange="changePeriod()"
        >

            <optgroup label="Months">

                <option
                    value="JAN"
                    <?= $period === 'JAN' ? 'selected' : '' ?>
                >
                    January
                </option>

                <option
                    value="FEB"
                    <?= $period === 'FEB' ? 'selected' : '' ?>
                >
                    February
                </option>

                <option
                    value="MAR"
                    <?= $period === 'MAR' ? 'selected' : '' ?>
                >
                    March
                </option>

                <option
                    value="APR"
                    <?= $period === 'APR' ? 'selected' : '' ?>
                >
                    April
                </option>

                <option
                    value="MAY"
                    <?= $period === 'MAY' ? 'selected' : '' ?>
                >
                    May
                </option>

                <option
                    value="JUN"
                    <?= $period === 'JUN' ? 'selected' : '' ?>
                >
                    June
                </option>

                <option
                    value="JUL"
                    <?= $period === 'JUL' ? 'selected' : '' ?>
                >
                    July
                </option>

                <option
                    value="AUG"
                    <?= $period === 'AUG' ? 'selected' : '' ?>
                >
                    August
                </option>

                <option
                    value="SEP"
                    <?= $period === 'SEP' ? 'selected' : '' ?>
                >
                    September
                </option>

                <option
                    value="OCT"
                    <?= $period === 'OCT' ? 'selected' : '' ?>
                >
                    October
                </option>

                <option
                    value="NOV"
                    <?= $period === 'NOV' ? 'selected' : '' ?>
                >
                    November
                </option>

                <option
                    value="DEC"
                    <?= $period === 'DEC' ? 'selected' : '' ?>
                >
                    December
                </option>

            </optgroup>


            <optgroup label="Seasons">

                <option
                    value="DJF"
                    <?= $period === 'DJF' ? 'selected' : '' ?>
                >
                    December–February
                </option>

                <option
                    value="MAM"
                    <?= $period === 'MAM' ? 'selected' : '' ?>
                >
                    March–May
                </option>

                <option
                    value="JJA"
                    <?= $period === 'JJA' ? 'selected' : '' ?>
                >
                    June–August
                </option>

                <option
                    value="SON"
                    <?= $period === 'SON' ? 'selected' : '' ?>
                >
                    September–November
                </option>

            </optgroup>

        </select>

    </div>


    <div class="location-row">

        <label>
            Latitude
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
            Longitude
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
            onclick="changeLocation()"
        >
            Load location
        </button>

    </div>

</div>


<div class="climatology">

    <?= htmlspecialchars($selectedLabel) ?>

    &nbsp;·&nbsp;

    All-period climate normal:

    <span class="anomaly-value">
        <?= number_format(
            $selectedClimatology,
            2
        ) ?> °C
    </span>

    &nbsp;·&nbsp;

    <?= $actualLatitude ?>°,
    <?= $actualLongitude ?>°

</div>


<div class="chart-container">

    <canvas id="temperatureChart"></canvas>

</div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/

const data =
    <?= $json ?>;


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

                        xAxisKey: 'x',

                        yAxisKey: 'anomaly'

                    },

                    borderColor:
                        '#ffffff',

                    backgroundColor:
                        'transparent',

                    borderWidth:
                        2.5,

                    pointRadius:
                        0,

                    pointHoverRadius:
                        5,

                    tension:
                        0.15,

                    fill:
                        false

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
                            'yyyy'

                    },

                    grid: {

                        color:
                            'rgba(255,255,255,0.06)'

                    },

                    ticks: {

                        color:
                            '#94a3b8'

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

                                const sign =
                                    value > 0
                                        ? '+'
                                        : '';

                                return (
                                    sign +
                                    value.toFixed(1) +
                                    ' °C'
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

                    callbacks: {

                        title:
                            function(items) {

                                if (!items.length) {
                                    return '';
                                }

                                return items[0]
                                    .raw
                                    .x
                                    .substring(0, 4);
                            },


                        label:
                            function(context) {

                                const anomaly =
                                    context.raw.anomaly;

                                const temperature =
                                    context.raw.temperature;


                                const sign =
                                    anomaly >= 0
                                        ? '+'
                                        : '';


                                return [

                                    'Anomaly: ' +
                                    sign +
                                    anomaly.toFixed(2) +
                                    ' °C',

                                    'Temperature: ' +
                                    temperature.toFixed(2) +
                                    ' °C'

                                ];

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
            .getElementById('period')
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


    const period =
        document
            .getElementById('period')
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
