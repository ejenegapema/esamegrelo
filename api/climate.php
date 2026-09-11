<?php

$url_925 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
         . 'Reanalysis_Data/ERA5/monthly_3d/'
         . 'Geopotential.ascii?zg[0:5][3][530][168]';

$url_1000 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
          . 'Reanalysis_Data/ERA5/monthly_3d/'
          . 'Geopotential.ascii?zg[0:5][0][530][168]';


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

/*
 * IMPORTANT:
 * Set this to the actual first month represented by index [0]
 * in the APDRC dataset.
 */
$startDate = new DateTime('1940-01-01');

/*
 * Previous 30 years = 360 months.
 */
$climateNormalMonths = 360;

/*
 * Rolling periods to calculate.
 */
$rollingPeriods = [
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
| ERA5 constants
|--------------------------------------------------------------------------
*/

$Rd = 287.05;       // J/(kg K)
$p1000 = 1000.0;    // hPa
$p925  = 925.0;     // hPa

/*
 * Hypsometric denominator.
 *
 * Since ERA5 zg is geopotential (m²/s²):
 *
 * T = ΔPhi / [Rd * ln(P1000/P925)]
 */
$hypsometricDenominator =
    $Rd * log($p1000 / $p925);


/*
|--------------------------------------------------------------------------
| DOWNLOAD + PARSE OPeNDAP ASCII
|--------------------------------------------------------------------------
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
        die(
            "Download failed:\n" .
            $url .
            "\n"
        );
    }

    /*
     * Example APDRC rows:
     *
     * [0][3][530][168], 12345.67
     */
    preg_match_all(
        '/^\s*(?:\[\d+\])+\s*,\s*(.*)$/m',
        $text,
        $rows
    );

    $values = [];

    foreach ($rows[1] as $row) {

        /*
         * Support:
         * 123.45
         * -123.45
         * 1.234e+05
         * etc.
         */
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
        die(
            "No numeric values found for:\n" .
            $url .
            "\n"
        );
    }

    return $values;
}


/*
|--------------------------------------------------------------------------
| GET BOTH PRESSURE LEVELS
|--------------------------------------------------------------------------
*/

$phi925  = getValues($url_925);
$phi1000 = getValues($url_1000);


/*
|--------------------------------------------------------------------------
| VALIDATE DATA
|--------------------------------------------------------------------------
*/

if (count($phi925) !== count($phi1000)) {

    die(
        "ERROR: Dataset size mismatch.\n" .
        "925 hPa : " . count($phi925) . "\n" .
        "1000 hPa: " . count($phi1000) . "\n"
    );
}

$n = count($phi925);

if ($n < $climateNormalMonths + 1) {

    die(
        "ERROR: Not enough data for a 30-year climate normal.\n" .
        "Need at least " .
        ($climateNormalMonths + 1) .
        " months.\n" .
        "Available: " .
        $n .
        " months.\n"
    );
}


/*
|--------------------------------------------------------------------------
| STEP 1
| Calculate monthly 1000–925 hPa layer temperature
|--------------------------------------------------------------------------
*/

$temperatureC = [];

for ($i = 0; $i < $n; $i++) {

    /*
     * ERA5 zg = geopotential (m²/s²)
     */
    $deltaPhi = $phi925[$i] - $phi1000[$i];

    /*
     * Layer mean temperature in Kelvin
     */
    $temperatureK =
        $deltaPhi /
        $hypsometricDenominator;

    /*
     * Kelvin -> Celsius
     */
    $temperatureC[$i] =
        $temperatureK - 273.15;
}


/*
|--------------------------------------------------------------------------
| STEP 2
| Build dates
|--------------------------------------------------------------------------
*/

$dates = [];

for ($i = 0; $i < $n; $i++) {

    $date = clone $startDate;

    $date->modify("+{$i} months");

    $dates[] = $date->format('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| STEP 3
| 30-YEAR TRAILING CLIMATE NORMAL
|--------------------------------------------------------------------------
|
| For month i:
|
| climatology(i) =
|
| mean of:
|
|   same calendar month
|   from i-360 through i-12
|
| Example:
|
| January 2020
|
| is compared with:
|
| January 1990 ... January 2019
|
| Current January 2020 is NOT included.
|--------------------------------------------------------------------------
*/

$monthlyAnomalyC = array_fill(0, $n, null);


/*
 * For efficiency, calculate climatology using direct
 * 30-year lookup. Since there are only 360 points,
 * this is still very manageable.
 */

for ($i = $climateNormalMonths; $i < $n; $i++) {

    $sum = 0.0;
    $count = 0;

    /*
     * We need previous 30 years, but only the same
     * calendar month.
     *
     * 360 months backward.
     */
    for (
        $j = $i - $climateNormalMonths;
        $j <= $i - 12;
        $j += 12
    ) {

        $value = $temperatureC[$j];

        if (is_finite($value)) {
            $sum += $value;
            $count++;
        }
    }

    /*
     * 30 observations expected:
     * j = i-360, i-348, ... i-12
     */
    if ($count === 30) {

        $climatology = $sum / 30.0;

        /*
         * Current temperature anomaly relative
         * to previous 30-year same-month normal.
         */
        $monthlyAnomalyC[$i] =
            $temperatureC[$i] - $climatology;
    }
}


/*
|--------------------------------------------------------------------------
| STEP 4
| Calculate rolling means of ANOMALIES
|--------------------------------------------------------------------------
|
| This is important:
|
| We roll the anomaly series,
| NOT the original temperatures.
|
|--------------------------------------------------------------------------
*/

$rollingData = [];


/*
 * Prefix sum allows fast rolling averages:
 *
 * O(N) instead of O(N × window)
 */
$prefixSum = array_fill(0, $n + 1, 0.0);
$prefixCount = array_fill(0, $n + 1, 0);

for ($i = 0; $i < $n; $i++) {

    $prefixSum[$i + 1] =
        $prefixSum[$i];

    $prefixCount[$i + 1] =
        $prefixCount[$i];

    if ($monthlyAnomalyC[$i] !== null &&
        is_finite($monthlyAnomalyC[$i])) {

        $prefixSum[$i + 1] +=
            $monthlyAnomalyC[$i];

        $prefixCount[$i + 1]++;
    }
}


/*
|--------------------------------------------------------------------------
| Calculate every requested rolling period
|--------------------------------------------------------------------------
*/

foreach ($rollingPeriods as $months) {

    $rollingData[$months] =
        array_fill(0, $n, null);

    for ($i = 0; $i < $n; $i++) {

        /*
         * Need a complete rolling window.
         */
        if ($i < $months - 1) {
            continue;
        }

        $start = $i - $months + 1;
        $end   = $i + 1;

        $sum =
            $prefixSum[$end] -
            $prefixSum[$start];

        $count =
            $prefixCount[$end] -
            $prefixCount[$start];

        /*
         * Require every month in the window
         * to have a valid anomaly.
         */
        if ($count === $months) {

            $rollingData[$months][$i] =
                $sum / $months;
        }
    }
}


/*
|--------------------------------------------------------------------------
| STEP 5
| Prepare chart JSON
|--------------------------------------------------------------------------
*/

$chartRows = [];

for ($i = 0; $i < $n; $i++) {

    $row = [
        'date' => $dates[$i],
    ];

    foreach ($rollingPeriods as $months) {

        $value = $rollingData[$months][$i];

        $row[(string)$months] =
            $value !== null
                ? round($value, 4)
                : null;
    }

    $chartRows[] = $row;
}

$jsonData = json_encode(
    $chartRows,
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

html,
body {
    margin: 0;
    padding: 0;
    background: #f5f7fa;
    font-family:
        Arial,
        Helvetica,
        sans-serif;
}

body {
    padding: 25px;
}

.container {
    max-width: 1500px;
    margin: auto;
    background: #ffffff;
    border-radius: 18px;
    padding: 25px;
    box-shadow:
        0 8px 35px rgba(0, 0, 0, 0.08);
}

h1 {
    margin: 0 0 8px 0;
    font-size: 25px;
}

.description {
    margin-bottom: 20px;
    color: #666;
    font-size: 14px;
}

.controls {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
}

.controls label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border-radius: 8px;
    background: #f0f2f5;
    cursor: pointer;
    font-size: 14px;
}

.controls input {
    cursor: pointer;
}

.chart-wrapper {
    position: relative;
    width: 100%;
    height: 700px;
}

.info {
    margin-top: 15px;
    font-size: 13px;
    color: #777;
}

</style>

</head>

<body>

<div class="container">

    <h1>
        1000–925 hPa Temperature Anomaly
    </h1>

    <div class="description">
        Anomaly relative to the preceding
        30-year same-calendar-month climate normal.
        Curves show rolling anomaly periods.
    </div>


    <div class="controls">

        <?php foreach ($rollingPeriods as $months): ?>

            <label>

                <input
                    type="checkbox"
                    class="rolling-toggle"
                    value="<?= $months ?>"
                    <?= $months === 12 ? 'checked' : '' ?>
                >

                <?= $months ?> month
                <?= $months === 1 ? '' : 's' ?>

            </label>

        <?php endforeach; ?>

    </div>


    <div class="chart-wrapper">

        <canvas id="temperatureChart"></canvas>

    </div>


    <div class="info">

        Monthly anomaly =
        current layer temperature minus the mean
        temperature for the same calendar month
        during the previous 30 years.

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| DATA FROM PHP
|--------------------------------------------------------------------------
*/

const data = <?= $jsonData ?>;


/*
|--------------------------------------------------------------------------
| Rolling periods
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
| Dataset definitions
|--------------------------------------------------------------------------
*/

const datasets = periods.map((months) => {

    return {

        label: `${months}-month rolling anomaly`,

        data: data.map(row => {

            return row[String(months)];

        }),

        hidden: months !== 12,

        borderWidth:
            months >= 120 ? 4 : 2,

        pointRadius: 0,

        tension: 0.15,

        fill: false,

        spanGaps: false
    };

});


/*
|--------------------------------------------------------------------------
| Chart
|--------------------------------------------------------------------------
*/

const ctx =
    document
        .getElementById('temperatureChart')
        .getContext('2d');


const chart =
    new Chart(ctx, {

        type: 'line',

        data: {

            datasets: datasets
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

                        text: 'Temperature anomaly (°C)'
                    },

                    ticks: {

                        callback: function(value) {

                            return value + ' °C';

                        }
                    },

                    /*
                     * Very useful for anomalies:
                     * put zero exactly in the middle
                     * when possible.
                     */
                    grid: {

                        drawOnChartArea: true
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

                        label:
                            function(context) {

                                const value =
                                    context.parsed.y;

                                if (value === null) {

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
| Checkbox controls
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.rolling-toggle')
    .forEach((checkbox) => {

        checkbox.addEventListener(
            'change',
            function() {

                const months =
                    Number(this.value);

                const dataset =
                    chart.data.datasets.find(
                        ds =>
                            ds.label ===
                            `${months}-month rolling anomaly`
                    );

                if (dataset) {

                    dataset.hidden =
                        !this.checked;

                    chart.update();
                }
            }
        );

    });

</script>

</body>
</html>
