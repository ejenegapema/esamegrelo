<?php
// ----------------- GET parameters from URL -----------------
$lat1 = isset($_GET['lat1']) ? floatval($_GET['lat1']) : 0;
$lat2 = isset($_GET['lat2']) ? floatval($_GET['lat2']) : 0;
$lon1 = isset($_GET['lon1']) ? floatval($_GET['lon1']) : 0;
$lon2 = isset($_GET['lon2']) ? floatval($_GET['lon2']) : 0;

// ----------------- Build NOAA URL dynamically -----------------
$noaaUrl = "https://psl.noaa.gov/cgi-bin/data/atmoswrit/timeseries.proc.pl?" . http_build_query([
    'justGotBACKed' => 0,
    'dataset1' => 'GPCC',
    'dataset2' => 'none',
    'var' => 'Precipitation Rate',
    'level' => '1000mb',
    'pgT1Sel' => 10,
    'fyear' => 1979,
    'fyear2' => 2025,
    'season' => 0,
    'fmonth' => 0,
    'fmonth2' => 11,
    'type' => 1,
    'climo1yr1' => 1991,
    'climo1yr2' => 2020,
    'climo2yr1' => 1991,
    'climo2yr2' => 2020,
    'xlat1' => $lat1,
    'xlat2' => $lat2,
    'xlon1' => $lon1,
    'xlon2' => $lon2,
    'maskx' => 0,
    'zlat1' => 0,
    'zlat2' => 90,
    'zlon1' => 0,
    'zlon2' => 360,
    'maskx2' => 0,
    'map' => 'on',
    'hmcolor' => 'hotcold_18lev',
    'smooth' => 1,
    'runmean' => 12,
    'Submit' => 'Create Plot'
]);

// ----------------- Get the HTML page -----------------
$html = file_get_contents($noaaUrl);
if ($html === false) {
    die("Failed to load NOAA page");
}

// ----------------- Extract the CSV link -----------------
if (preg_match('/href="(\/tmp\/[^"]+\.csv)"/i', $html, $match)) {
    $csvUrl = "https://psl.noaa.gov" . $match[1];
} else {
    die("CSV file not found in NOAA page");
}

// ----------------- Fetch CSV content -----------------
$csvContent = file_get_contents($csvUrl);
if ($csvContent === false) {
    die("Failed to fetch CSV data.");
}

// ----------------- Parse CSV -----------------
$lines = explode("\n", $csvContent);
$header = array_shift($lines); // remove header

$data = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === "") continue;
    $data[] = str_getcsv($line);
}

// ----------------- Output data -----------------
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'lat1' => $lat1,
    'lat2' => $lat2,
    'lon1' => $lon1,
    'lon2' => $lon2,
    'data' => $data
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
