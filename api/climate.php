<?php

$url_925 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
         . 'Reanalysis_Data/ERA5/monthly_3d/'
         . 'Geopotential.ascii?zg[0:5][3][530][168]';

$url_1000 = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
          . 'Reanalysis_Data/ERA5/monthly_3d/'
          . 'Geopotential.ascii?zg[0:5][0][530][168]';


/**
 * Download and extract numeric values from an OPeNDAP ASCII response.
 */
function getValues(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
        ],
    ]);

    $text = file_get_contents($url, false, $context);

    if ($text === false) {
        die("Download failed:\n$url\n");
    }

    /*
     * Match rows such as:
     *
     * [0][0][0], 7644.9766
     *
     * and also rows containing multiple comma-separated values.
     */
    preg_match_all(
        '/^\s*(?:\[\d+\])+\s*,\s*([^\r\n]+)/m',
        $text,
        $matches
    );

    $values = [];

    foreach ($matches[1] as $row) {
        foreach (explode(',', $row) as $item) {
            $item = trim($item);

            if (is_numeric($item)) {
                $values[] = (float)$item;
            }
        }
    }

    if (empty($values)) {
        die("No numeric values found for:\n$url\n");
    }

    return $values;
}


/*
 * Constants
 */
$g  = 9.80665;  // m/s²
$Rd = 287.05;   // J/(kg·K)

$p1000 = 1000.0; // hPa
$p925  = 925.0;  // hPa


/*
 * Download both pressure-level datasets
 */
$z925  = getValues($url_925);
$z1000 = getValues($url_1000);


/*
 * Make sure both datasets have the same number of values
 */
if (count($z925) !== count($z1000)) {
    die(
        "Dataset size mismatch: " .
        count($z925) .
        " values at 925 hPa, " .
        count($z1000) .
        " values at 1000 hPa.\n"
    );
}


/*
 * Hypsometric equation:
 *
 * Tmean = g * (Z925 - Z1000)
 *         -------------------------
 *         Rd * ln(1000 / 925)
 *
 */
$layerTemperaturesK  = [];
$layerTemperaturesC  = [];
$thicknesses         = [];
$relativeGeopotential = [];


for ($i = 0; $i < count($z925); $i++) {

    /*
     * Geopotential difference / thickness
     */
    $deltaZ = $z925[$i] - $z1000[$i];

    /*
     * Mean layer temperature in Kelvin
     */
    $temperatureK =
        ($g * $deltaZ) /
        ($Rd * log($p1000 / $p925));

    /*
     * Convert Kelvin -> Celsius
     */
    $temperatureC = $temperatureK - 273.15;

    /*
     * Store results
     */
    $thicknesses[]          = $deltaZ;
    $relativeGeopotential[] = $deltaZ;
    $layerTemperaturesK[]   = $temperatureK;
    $layerTemperaturesC[]   = $temperatureC;
}


/*
 * Print ONLY the mean layer temperatures in °C
 */
foreach ($layerTemperaturesC as $temperature) {
    echo $temperature . PHP_EOL;
}
