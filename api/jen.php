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
| T2M:
|   Surface.ascii?t2m[time][lat][lon]
|
| 975 hPa:
|   Temperature.ascii?temp[time][1][lat][lon]
|
| 925 hPa:
|   Temperature.ascii?temp[time][3][lat][lon]
|
|--------------------------------------------------------------------------
*/

$url_t2m =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_2d/' .
    'Surface.ascii?t2m[0:1039][' .
    $latitudeIndex .
    '][' .
    $longitudeIndex .
    ']';


$url_975 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Temperature.ascii?temp[0:1039][1][' .
    $latitudeIndex .
    '][' .
    $longitudeIndex .
    ']';


$url_925 =
    'https://apdrc.soest.hawaii.edu/dods/public_data/' .
    'Reanalysis_Data/ERA5/monthly_3d/' .
    'Temperature.ascii?temp[0:1039][3][' .
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
     * Typical APDRC response:
     *
     * [0][530][168], 288.123
     * [0][1][530][168], 272.123
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

$t2mK =
    getValues($url_t2m);

$temp975K =
    getValues($url_975);

$temp925K =
    getValues($url_925);


/*
|--------------------------------------------------------------------------
| ALIGN DATA LENGTHS
|--------------------------------------------------------------------------
|
| Use only months available in ALL THREE datasets.
|--------------------------------------------------------------------------
*/

$n =
    min(
        count($t2mK),
        count($temp975K),
        count($temp925K)
    );


if ($n < 12) {

    die(
        "At least 12 common months of data are required.<br>" .
        "T2m: " . count($t2mK) . "<br>" .
        "975 hPa: " . count($temp975K) . "<br>" .
        "925 hPa: " . count($temp925K)
    );
}


/*
|--------------------------------------------------------------------------
| CONVERT KELVIN -> CELSIUS
|--------------------------------------------------------------------------
*/

$t2mC = [];
$temp975C = [];
$temp925C = [];


for ($i = 0; $i < $n; $i++) {

    $t2mC[$i] =
        $t2mK[$i] - 273.15;


    $temp975C[$i] =
        $temp975K[$i] - 273.15;


    $temp925C[$i] =
        $temp925K[$i] - 273.15;
}


/*
|--------------------------------------------------------------------------
| DATASET START DATE
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
| BUILD YEAR -> MONTH -> VALUES
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

            'months_t2m' =>
                [],

            'months_975' =>
                [],

            'months_925' =>
                []

        ];
    }


    $yearlyData[$year]['months_t2m'][$month] =
        $t2mC[$i];


    $yearlyData[$year]['months_975'][$month] =
        $temp975C[$i];


    $yearlyData[$year]['months_925'][$month] =
        $temp925C[$i];
}


/*
|--------------------------------------------------------------------------
| MONTH CLIMATOLOGY FUNCTION
|--------------------------------------------------------------------------
*/

function calculateMonthClimate(array $monthlyValues, array $months): array
{
    $sums =
        array_fill(
            1,
            12,
            0.0
        );


    $counts =
        array_fill(
            1,
            12,
            0
        );


    $n =
        count($monthlyValues);


    for ($i = 0; $i < $n; $i++) {

        $month =
            $months[$i];

        $value =
            $monthlyValues[$i];


        if (is_finite($value)) {

            $sums[$month] +=
                $value;

            $counts[$month]++;
        }
    }


    $climate =
        array_fill(
            1,
            12,
            null
        );


    for ($month = 1; $month <= 12; $month++) {

        if ($counts[$month] > 0) {

            $climate[$month] =
                $sums[$month] /
                $counts[$month];
        }
    }


    return $climate;
}


/*
|--------------------------------------------------------------------------
| MONTH CLIMATOLOGY FOR EACH LEVEL
|--------------------------------------------------------------------------
*/

$t2mMonthClimate =
    calculateMonthClimate(
        $t2mC,
        $months
    );


$temp975MonthClimate =
    calculateMonthClimate(
        $temp975C,
        $months
    );


$temp925MonthClimate =
    calculateMonthClimate(
        $temp925C,
        $months
    );


/*
|--------------------------------------------------------------------------
| YEARLY TEMPERATURE FUNCTION
|--------------------------------------------------------------------------
*/

function calculateYearTemperature(
    array $yearlyData,
    string $key
): array
{
    $result = [];


    foreach (
        $yearlyData
        as $year => $info
    ) {

        if (
            count($info[$key]) === 12
        ) {

            $result[$year] =
                array_sum(
                    $info[$key]
                ) / 12.0;
        }
    }


    return $result;
}


/*
|--------------------------------------------------------------------------
| YEARLY VALUES
|--------------------------------------------------------------------------
*/

$yearT2m =
    calculateYearTemperature(
        $yearlyData,
        'months_t2m'
    );


$year975 =
    calculateYearTemperature(
        $yearlyData,
        'months_975'
    );


$year925 =
    calculateYearTemperature(
        $yearlyData,
        'months_925'
    );


/*
|--------------------------------------------------------------------------
| YEAR CLIMATOLOGY
|--------------------------------------------------------------------------
*/

$yearClimateT2m =
    !empty($yearT2m)
        ? array_sum($yearT2m) / count($yearT2m)
        : null;


$yearClimate975 =
    !empty($year975)
        ? array_sum($year975) / count($year975)
        : null;


$yearClimate925 =
    !empty($year925)
        ? array_sum($year925) / count($year925)
        : null;


/*
|--------------------------------------------------------------------------
| ROLLING 12-MONTH FUNCTION
|--------------------------------------------------------------------------
*/

function calculateRolling12(array $values): array
{
    $n =
        count($values);


    $rolling =
        array_fill(
            0,
            $n,
            null
        );


    $prefix =
        array_fill(
            0,
            $n + 1,
            0.0
        );


    for ($i = 0; $i < $n; $i++) {

        $prefix[$i + 1] =
            $prefix[$i] +
            $values[$i];
    }


    for ($i = 11; $i < $n; $i++) {

        $start =
            $i - 11;


        $sum =
            $prefix[$i + 1] -
            $prefix[$start];


        $rolling[$i] =
            $sum / 12.0;
    }


    return $rolling;
}


/*
|--------------------------------------------------------------------------
| ROLLING VALUES
|--------------------------------------------------------------------------
*/

$rollingT2m =
    calculateRolling12(
        $t2mC
    );


$rolling975 =
    calculateRolling12(
        $temp975C
    );


$rolling925 =
    calculateRolling12(
        $temp925C
    );


/*
|--------------------------------------------------------------------------
| ROLLING CLIMATE
|--------------------------------------------------------------------------
*/

function calculateRollingClimate(array $rolling): ?float
{
    $sum =
        0.0;

    $count =
        0;


    foreach ($rolling as $value) {

        if ($value !== null) {

            $sum +=
                $value;

            $count++;
        }
    }


    if ($count === 0) {
        return null;
    }


    return $sum / $count;
}


$rollingClimateT2m =
    calculateRollingClimate(
        $rollingT2m
    );


$rollingClimate975 =
    calculateRollingClimate(
        $rolling975
    );


$rollingClimate925 =
    calculateRollingClimate(
        $rolling925
    );


/*
|--------------------------------------------------------------------------
| SEASON DEFINITIONS
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
| SEASON SERIES FUNCTION
|--------------------------------------------------------------------------
*/

function buildSeasonSeries(
    array $yearlyData,
    array $seasonDefinitions,
    string $key
): array
{
    $result = [];


    foreach (
        $seasonDefinitions
        as $season => $seasonMonths
    ) {

        $result[$season] =
            [];


        foreach (
            $yearlyData
            as $year => $info
        ) {

            $values = [];


            foreach (
                $seasonMonths
                as $month
            ) {

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
                        ][$key][$month]
                    )
                ) {

                    $values[] =
                        $yearlyData[
                            $targetYear
                        ][$key][$month];
                }
            }


            if (count($values) === 3) {

                $result[$season][$year] =
                    array_sum($values) / 3.0;
            }
        }
    }


    return $result;
}


/*
|--------------------------------------------------------------------------
| SEASON SERIES
|--------------------------------------------------------------------------
*/

$seasonT2m =
    buildSeasonSeries(
        $yearlyData,
        $seasonDefinitions,
        'months_t2m'
    );


$season975 =
    buildSeasonSeries(
        $yearlyData,
        $seasonDefinitions,
        'months_975'
    );


$season925 =
    buildSeasonSeries(
        $yearlyData,
        $seasonDefinitions,
        'months_925'
    );


/*
|--------------------------------------------------------------------------
| SEASON CLIMATE
|--------------------------------------------------------------------------
*/

function calculateSeasonClimate(array $seasonSeries): array
{
    $climate = [];


    foreach (
        $seasonSeries
        as $season => $values
    ) {

        if (!empty($values)) {

            $climate[$season] =
                array_sum($values) /
                count($values);

        } else {

            $climate[$season] =
                null;
        }
    }


    return $climate;
}


$seasonClimateT2m =
    calculateSeasonClimate(
        $seasonT2m
    );


$seasonClimate975 =
    calculateSeasonClimate(
        $season975
    );


$seasonClimate925 =
    calculateSeasonClimate(
        $season925
    );


/*
|--------------------------------------------------------------------------
| VALID PERIODS
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
| PREPARE THREE CHART SERIES
|--------------------------------------------------------------------------
*/

$chartT2m = [];
$chart975 = [];
$chart925 = [];


/*
|--------------------------------------------------------------------------
| PREPARE DIFFERENCE SERIES
|--------------------------------------------------------------------------
|
| difference =
|
|   T2m anomaly - 975 hPa anomaly
|
|--------------------------------------------------------------------------
*/

$differenceValues = [];


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function addChartPoint(
    array &$target,
    string $x,
    float $value
): void
{
    $target[] = [

        'x' =>
            $x,

        'anomaly' =>
            round(
                $value,
                2
            )

    ];
}


/*
|--------------------------------------------------------------------------
| FULL YEAR
|--------------------------------------------------------------------------
*/

if ($period === 'YEAR') {

    /*
     * Use only years present at all three levels.
     */

    $commonYears =
        array_intersect_key(
            $yearT2m,
            $year975
        );

    $commonYears =
        array_intersect_key(
            $commonYears,
            $year925
        );


    if (
        $yearClimateT2m !== null &&
        $yearClimate975 !== null &&
        $yearClimate925 !== null
    ) {

        foreach (
            $commonYears
            as $year => $unused
        ) {

            $anomalyT2m =
                $yearT2m[$year] -
                $yearClimateT2m;


            $anomaly975 =
                $year975[$year] -
                $yearClimate975;


            $anomaly925 =
                $year925[$year] -
                $yearClimate925;


            addChartPoint(
                $chartT2m,
                $year . '-01-01',
                $anomalyT2m
            );


            addChartPoint(
                $chart975,
                $year . '-01-01',
                $anomaly975
            );


            addChartPoint(
                $chart925,
                $year . '-01-01',
                $anomaly925
            );


            $differenceValues[] =
                $anomalyT2m -
                $anomaly975;
        }
    }


/*
|--------------------------------------------------------------------------
| ROLLING 12
|--------------------------------------------------------------------------
*/

} elseif ($period === 'ROLL12') {

    if (
        $rollingClimateT2m !== null &&
        $rollingClimate975 !== null &&
        $rollingClimate925 !== null
    ) {

        for ($i = 11; $i < $n; $i++) {

            if (
                $rollingT2m[$i] === null ||
                $rolling975[$i] === null ||
                $rolling925[$i] === null
            ) {
                continue;
            }


            $anomalyT2m =
                $rollingT2m[$i] -
                $rollingClimateT2m;


            $anomaly975 =
                $rolling975[$i] -
                $rollingClimate975;


            $anomaly925 =
                $rolling925[$i] -
                $rollingClimate925;


            addChartPoint(
                $chartT2m,
                $dates[$i],
                $anomalyT2m
            );


            addChartPoint(
                $chart975,
                $dates[$i],
                $anomaly975
            );


            addChartPoint(
                $chart925,
                $dates[$i],
                $anomaly925
            );


            $differenceValues[] =
                $anomalyT2m -
                $anomaly975;
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


    $climateT2m =
        $t2mMonthClimate[$month];


    $climate975 =
        $temp975MonthClimate[$month];


    $climate925 =
        $temp925MonthClimate[$month];


    if (
        $climateT2m !== null &&
        $climate975 !== null &&
        $climate925 !== null
    ) {

        foreach (
            $yearlyData
            as $year => $info
        ) {

            if (
                !isset(
                    $info['months_t2m'][$month],
                    $info['months_975'][$month],
                    $info['months_925'][$month]
                )
            ) {
                continue;
            }


            $anomalyT2m =
                $info['months_t2m'][$month] -
                $climateT2m;


            $anomaly975 =
                $info['months_975'][$month] -
                $climate975;


            $anomaly925 =
                $info['months_925'][$month] -
                $climate925;


            addChartPoint(
                $chartT2m,
                $year . '-01-01',
                $anomalyT2m
            );


            addChartPoint(
                $chart975,
                $year . '-01-01',
                $anomaly975
            );


            addChartPoint(
                $chart925,
                $year . '-01-01',
                $anomaly925
            );


            $differenceValues[] =
                $anomalyT2m -
                $anomaly975;
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

    $climateT2m =
        $seasonClimateT2m[$period];


    $climate975 =
        $seasonClimate975[$period];


    $climate925 =
        $seasonClimate925[$period];


    if (
        $climateT2m !== null &&
        $climate975 !== null &&
        $climate925 !== null
    ) {

        foreach (
            $seasonT2m[$period]
            as $year => $temperatureT2m
        ) {

            if (
                !isset(
                    $season975[$period][$year],
                    $season925[$period][$year]
                )
            ) {
                continue;
            }


            $temperature975 =
                $season975[$period][$year];


            $temperature925 =
                $season925[$period][$year];


            $anomalyT2m =
                $temperatureT2m -
                $climateT2m;


            $anomaly975 =
                $temperature975 -
                $climate975;


            $anomaly925 =
                $temperature925 -
                $climate925;


            addChartPoint(
                $chartT2m,
                $year . '-01-01',
                $anomalyT2m
            );


            addChartPoint(
                $chart975,
                $year . '-01-01',
                $anomaly975
            );


            addChartPoint(
                $chart925,
                $year . '-01-01',
                $anomaly925
            );


            $differenceValues[] =
                $anomalyT2m -
                $anomaly975;
        }
    }
}


/*
|--------------------------------------------------------------------------
| SORT ALL CHART SERIES
|--------------------------------------------------------------------------
*/

$sortCallback =
    function (
        $a,
        $b
    ) {

        return strcmp(
            $a['x'],
            $b['x']
        );
    };


usort(
    $chartT2m,
    $sortCallback
);

usort(
    $chart975,
    $sortCallback
);

usort(
    $chart925,
    $sortCallback
);


/*
|--------------------------------------------------------------------------
| HISTOGRAM
|--------------------------------------------------------------------------
|
| Automatic binning:
|
|   20 bins across the observed difference range.
|--------------------------------------------------------------------------
*/

$histogramLabels = [];
$histogramCounts = [];


if (!empty($differenceValues)) {

    $minDifference =
        min($differenceValues);

    $maxDifference =
        max($differenceValues);


    /*
     * Avoid zero-width histogram.
     */

    if (
        abs(
            $maxDifference -
            $minDifference
        ) < 0.000001
    ) {

        $minDifference -=
            0.5;

        $maxDifference +=
            0.5;
    }


    $binCount =
        20;


    $binWidth =
        ($maxDifference - $minDifference) /
        $binCount;


    $histogramCounts =
        array_fill(
            0,
            $binCount,
            0
        );


    for (
        $i = 0;
        $i < $binCount;
        $i++
    ) {

        $binStart =
            $minDifference +
            ($i * $binWidth);


        $binEnd =
            $binStart +
            $binWidth;


        $histogramLabels[] =

            round(
                $binStart,
                1
            )
            . ' to '
            .
            round(
                $binEnd,
                1
            )
            . ' °C';
    }


    foreach (
        $differenceValues
        as $value
    ) {

        $index =
            (int)floor(
                ($value - $minDifference) /
                $binWidth
            );


        /*
         * Maximum value belongs
         * to the final bin.
         */

        if (
            $index >= $binCount
        ) {

            $index =
                $binCount - 1;
        }


        if ($index < 0) {
            $index = 0;
        }


        $histogramCounts[$index]++;
    }
}


/*
|--------------------------------------------------------------------------
| JSON
|--------------------------------------------------------------------------
*/

$jsonT2m =
    json_encode(
        $chartT2m,
        JSON_UNESCAPED_SLASHES |
        JSON_NUMERIC_CHECK
    );


$json975 =
    json_encode(
        $chart975,
        JSON_UNESCAPED_SLASHES |
        JSON_NUMERIC_CHECK
    );


$json925 =
    json_encode(
        $chart925,
        JSON_UNESCAPED_SLASHES |
        JSON_NUMERIC_CHECK
    );


$jsonHistogramLabels =
    json_encode(
        $histogramLabels,
        JSON_UNESCAPED_SLASHES |
        JSON_NUMERIC_CHECK
    );


$jsonHistogramCounts =
    json_encode(
        $histogramCounts,
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
    ERA5 T2m / 975 / 925 hPa Temperature Anomalies
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

h2 {
    text-align: center;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: #cbd5e1;
}

.chart-container {
    background: rgba(15, 23, 42, 0.6);
    padding: 2rem;
    border-radius: 20px;
    margin-bottom: 2rem;
    height: 400px;
}

.histogram-container {
    background: rgba(15, 23, 42, 0.6);
    padding: 2rem;
    border-radius: 20px;
    margin-bottom: 2rem;
    height: 420px;
}

.date-picker {
    text-align: center;
    margin-bottom: 1rem;
}

.period-row {
    text-align: center;
    margin-bottom: 12px;
}

.location-row {
    text-align: center;
    margin-bottom: 10px;
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
    ERA5 Temperature Anomalies
</h1>


<div class="date-picker">

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
                    Rolling 12-Month
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


<!-- =========================================================
     FIRST CHART
========================================================= -->

<div class="chart-container">

    <canvas
        id="temperatureChart"
    ></canvas>

</div>


<!-- =========================================================
     SECOND CHART
========================================================= -->

<div class="histogram-container">

    <h2>
        Histogram: T2m anomaly − 975 hPa anomaly
    </h2>


    <canvas
        id="differenceHistogram"
    ></canvas>

</div>


</div>


<script>

/*
|--------------------------------------------------------------------------
| CHART DATA
|--------------------------------------------------------------------------
*/

const dataT2m =
    <?= $jsonT2m ?>;


const data975 =
    <?= $json975 ?>;


const data925 =
    <?= $json925 ?>;


const histogramLabels =
    <?= $jsonHistogramLabels ?>;


const histogramCounts =
    <?= $jsonHistogramCounts ?>;


/*
|--------------------------------------------------------------------------
| ZERO LINE PLUGIN
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
| FIRST CHART
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
                        'T2m',

                    data:
                        dataT2m,

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

                },


                {

                    label:
                        '975 hPa',

                    data:
                        data975,

                    parsing: {

                        xAxisKey:
                            'x',

                        yAxisKey:
                            'anomaly'

                    },

                    borderColor:
                        '#38bdf8',

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

                },


                {

                    label:
                        '925 hPa',

                    data:
                        data925,

                    parsing: {

                        xAxisKey:
                            'x',

                        yAxisKey:
                            'anomaly'

                    },

                    borderColor:
                        '#f59e0b',

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
                                    number.toFixed(1)
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
                        true,

                    labels: {

                        color:
                            '#e2e8f0'

                    }

                },


                tooltip: {

                    displayColors:
                        true,


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
                                    context.dataset.label +
                                    ': ' +
                                    sign +
                                    value.toFixed(2) +
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
| HISTOGRAM
|--------------------------------------------------------------------------
*/

const histogramCtx =
    document
        .getElementById(
            'differenceHistogram'
        )
        .getContext('2d');


new Chart(
    histogramCtx,
    {

        type:
            'bar',


        data: {

            labels:
                histogramLabels,

            datasets: [

                {

                    label:
                        'Number of periods',

                    data:
                        histogramCounts,

                    borderWidth:
                        1,

                    borderColor:
                        '#ffffff',

                    backgroundColor:
                        'rgba(56,189,248,0.45)'

                }

            ]

        },


        options: {

            responsive:
                true,

            maintainAspectRatio:
                false,


            scales: {

                x: {

                    title: {

                        display:
                            true,

                        text:
                            'T2m anomaly − 975 hPa anomaly (°C)',

                        color:
                            '#94a3b8'

                    },

                    ticks: {

                        color:
                            '#94a3b8',

                        maxRotation:
                            60,

                        minRotation:
                            60

                    },

                    grid: {

                        color:
                            'rgba(255,255,255,0.06)'

                    }

                },


                y: {

                    beginAtZero:
                        true,

                    title: {

                        display:
                            true,

                        text:
                            'Frequency',

                        color:
                            '#94a3b8'

                    },

                    ticks: {

                        color:
                            '#94a3b8'

                    },

                    grid: {

                        color:
                            'rgba(255,255,255,0.08)'

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

                        label:
                            function(context) {

                                return (
                                    'Frequency: ' +
                                    context.raw
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
