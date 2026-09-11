<?php

$url_925 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
         . 'Reanalysis_Data/ERA5/monthly_3d/'
         . 'Geopotential.ascii?zg[0:5][3][530][168]';

$url_1000 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
          . 'Reanalysis_Data/ERA5/monthly_3d/'
          . 'Geopotential.ascii?zg[0:5][0][530][168]';


/**
 * Download ERA5 ASCII data and extract all numeric values.
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

    /*
     * Extract numbers after the indexed part.
     *
     * Example:
     * [0][3][530][168], 7625.1234
     */
    preg_match_all(
        '/^\s*(?:\[\d+\])+\s*,\s*(.*)$/m',
        $text,
        $rows
    );

    $values = [];

    foreach ($rows[1] as $row) {

        /*
         * Extract floats/scientific notation safely.
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
        die("No numeric values found.\n");
    }

    return $values;
}


/*
 * ERA5 constants
 */
$Rd = 287.05;   // J/(kg·K)

/*
 * Pressure values in hPa.
 * The ratio is dimensionless, so hPa is fine here.
 */
$p1000 = 1000.0;
$p925  = 925.0;


/*
 * Download both pressure levels
 */
$phi925  = getValues($url_925);
$phi1000 = getValues($url_1000);


/*
 * Verify that both arrays match.
 */
if (count($phi925) !== count($phi1000)) {
    die(
        "ERROR: Different number of values.\n" .
        "925 hPa : " . count($phi925) . "\n" .
        "1000 hPa: " . count($phi1000) . "\n"
    );
}


/*
 * Constant part of the hypsometric equation:
 *
 * T = ΔΦ / (Rd * ln(P1000/P925))
 *
 * ΔΦ is in m²/s².
 */
$denominator = $Rd * log($p1000 / $p925);


$temperaturesC = [];


for ($i = 0; $i < count($phi925); $i++) {

    /*
     * Geopotential difference:
     *
     * Φ925 > Φ1000 normally
     */
    $deltaPhi = $phi925[$i] - $phi1000[$i];


    /*
     * Mean temperature in Kelvin
     */
    $temperatureK = $deltaPhi / $denominator;


    /*
     * Kelvin -> Celsius
     */
    $temperatureC = $temperatureK - 273.15;


    /*
     * Ignore invalid values
     */
    if (is_finite($temperatureC)) {
        $temperaturesC[] = $temperatureC;
    }
}


/*
 * Print ONLY Celsius values
 */
foreach ($temperaturesC as $temperatureC) {
    printf("%.2f\n", $temperatureC);
}
