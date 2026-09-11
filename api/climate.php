<?php

$url_925 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
         . 'Reanalysis_Data/ERA5/monthly_3d/'
         . 'Geopotential.ascii?zg[0:1039][3][530][168]';

$url_1000 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
          . 'Reanalysis_Data/ERA5/monthly_3d/'
          . 'Geopotential.ascii?zg[0:1039][0][530][168]';


/**
 * Download ERA5 ASCII data and extract numeric values.
 */
function getValues(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $text = file_get_contents($url, false, $context);

    if ($text === false) {
        die("Download failed:\n$url\n");
    }

    preg_match_all(
        '/^\s*(?:\[\d+\])+\s*,\s*(.*)$/m',
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
            $values[] = (float)$number;
        }
    }

    if (empty($values)) {
        die("No numeric values found.\n");
    }

    return $values;
}


/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/

$Rd = 287.05;            // J/(kg·K)
$p1000 = 1000.0;         // hPa
$p925  = 925.0;          // hPa

$denominator = $Rd * log($p1000 / $p925);


/*
|--------------------------------------------------------------------------
| Download geopotential
|--------------------------------------------------------------------------
*/

$phi925  = getValues($url_925);
$phi1000 = getValues($url_1000);


/*
|--------------------------------------------------------------------------
| Validate
|--------------------------------------------------------------------------
*/

if (count($phi925) !== count($phi1000)) {
    die(
        "ERROR: Different number of values.\n" .
        "925 hPa : " . count($phi925) . "\n" .
        "1000 hPa: " . count($phi1000) . "\n"
    );
}


/*
|--------------------------------------------------------------------------
| Calculate monthly layer temperature
|--------------------------------------------------------------------------
*/

$temperaturesC = [];

for ($i = 0; $i < count($phi925); $i++) {

    // Geopotential difference, m²/s²
    $deltaPhi = $phi925[$i] - $phi1000[$i];

    // Layer mean temperature, Kelvin
    $temperatureK = $deltaPhi / $denominator;

    // Kelvin -> Celsius
    $temperatureC = $temperatureK - 273.15;

    if (is_finite($temperatureC)) {
        $temperaturesC[] = $temperatureC;
    }
}


/*
|--------------------------------------------------------------------------
| 12-month rolling mean
|--------------------------------------------------------------------------
|
| For month i:
|
| rolling12[i] =
|     mean(temperature[i-11 ... i])
|
| The first 11 months are NULL because a complete
| 12-month window does not yet exist.
|
*/

$rolling12 = [];
$n = count($temperaturesC);

for ($i = 0; $i < $n; $i++) {

    if ($i < 11) {
        $rolling12[] = null;
        continue;
    }

    $sum = 0.0;

    for ($j = $i - 11; $j <= $i; $j++) {
        $sum += $temperaturesC[$j];
    }

    $rolling12[] = $sum / 12.0;
}


/*
|--------------------------------------------------------------------------
| Create dates
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Replace this with the actual start month of your dataset.
|
| Example below assumes January 1940.
|
*/

$startDate = new DateTime('1940-01-01');

$dates = [];

for ($i = 0; $i < $n; $i++) {

    $date = clone $startDate;
    $date->modify("+{$i} months");

    $dates[] = $date->format('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| Prepare JSON for JavaScript
|--------------------------------------------------------------------------
*/

$chartData = [];

for ($i = 0; $i < $n; $i++) {

    $chartData[] = [
        'date'       => $dates[$i],
        'monthly'    => round($temperaturesC[$i], 3),
        'rolling12'  => $rolling12[$i] !== null
            ? round($rolling12[$i], 3)
            : null
    ];
}

$jsonData = json_encode(
    $chartData,
    JSON_UNESCAPED_SLASHES |
    JSON_NUMERIC_CHECK
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>ERA5 1000–925 hPa Layer Temperature</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

<style>

body {
    margin: 0;
    padding: 30px;
    background: #f5f7fa;
    font-family: Arial, sans-serif;
}

.chart-container {
    max-width: 1400px;
    height: 700px;
    margin: auto;
    background: white;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.08);
}

h1 {
    margin-top: 0;
    font-size: 24px;
}

.subtitle {
    color: #666;
    margin-bottom: 20px;
}

</style>
</head>

<body>

<div class="chart-container">

    <h1>
        Mean Temperature of 1000–925 hPa Layer
    </h1>

    <div class="subtitle">
        Monthly temperature with emphasized 12-month rolling mean
    </div>

    <canvas id="temperatureChart"></canvas>

</div>


<script>

const data = <?= $jsonData ?>;

const labels = data.map(row => row.date);

const monthly = data.map(row => row.monthly);

const rolling12 = data.map(row => row.rolling12);


const ctx = document
    .getElementById('temperatureChart')
    .getContext('2d');


new Chart(ctx, {

    type: 'line',

    data: {

        labels: labels,

        datasets: [

            {
                label: 'Monthly temperature',

                data: monthly,

                borderWidth: 1,

                pointRadius: 0,

                tension: 0.15,

                fill: false,

                opacity: 0.25
            },

            {
                label: '12-month rolling mean',

                data: rolling12,

                borderWidth: 4,

                pointRadius: 0,

                tension: 0.15,

                fill: false
            }

        ]
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
                    tooltipFormat: 'MMM yyyy'
                },

                title: {
                    display: true,
                    text: 'Year'
                }
            },

            y: {

                title: {
                    display: true,
                    text: 'Temperature (°C)'
                },

                ticks: {
                    callback: function(value) {
                        return value + ' °C';
                    }
                }
            }
        },

        plugins: {

            legend: {
                display: true,
                position: 'top'
            },

            tooltip: {

                callbacks: {

                    label: function(context) {

                        const value = context.parsed.y;

                        if (value === null) {
                            return context.dataset.label + ': —';
                        }

                        return context.dataset.label +
                               ': ' +
                               value.toFixed(2) +
                               ' °C';
                    }
                }
            }
        }
    }

});

</script>

</body>
</html>
