<?php
session_start();

// ====== SECURITY CHECKS ======
if (!isset($_GET['key'])) {
    http_response_code(403);
    echo "Access denied. Missing key.";
    exit;
}
if (!isset($_SESSION['download_key']) || $_GET['key'] !== $_SESSION['download_key']) {
    http_response_code(403);
    echo "Access denied. Invalid key.";
    exit;
}
if (time() > $_SESSION['key_expires']) {
    http_response_code(403);
    echo "Access denied. Key expired.";
    exit;
}

// ====== PARAMETERS ======
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 42.5;
$lon = isset($_GET['lon']) ? floatval($_GET['lon']) : 41.9;

// Validate coords
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    http_response_code(400);
    echo "Invalid coordinates.";
    exit;
}

// ====== MODELS TO FETCH ======
$models = [
    "icon_global",
    "ecmwf_ifs025",
    "ecmwf_aifs025_single",
    "kma_gdps",
    "gfs_graphcast025",
    "gfs_global",
    "jma_gsm",
    "gem_global",
    "ukmo_global_deterministic_10km",
    "meteofrance_arpege_world"
];

// ====== API REQUEST ======
$models_string = implode(",", $models);
$api_url = "https://api.open-meteo.com/v1/forecast?" .
    "latitude={$lat}&longitude={$lon}" .
    "&daily=temperature_2m_max,temperature_2m_min,wind_gusts_10m_max,sunshine_duration,precipitation_sum,precipitation_probability_max" .
    "&models={$models_string}&forecast_days=16&timezone=auto";

$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(["error" => "Error fetching data: " . curl_error($ch)]);
    exit;
}

if ($http_code !== 200) {
    http_response_code($http_code);
    echo json_encode(["error" => "API returned HTTP code: " . $http_code]);
    exit;
}

// ====== PARSE JSON ======
$data = json_decode($response, true);
if (!$data) {
    http_response_code(500);
    echo json_encode(["error" => "Invalid response from Open-Meteo"]);
    exit;
}

// ====== RESTRUCTURE DATA FOR MULTI-MODEL RESPONSE ======
// The API returns an array when multiple models are requested
$structured_data = [];

if (isset($data[0]) && is_array($data[0])) {
    // Multi-model response - data is an array
    foreach ($data as $index => $model_data) {
        if (isset($models[$index])) {
            $structured_data[] = [
                "model" => $models[$index],
                "latitude" => $model_data["latitude"] ?? $lat,
                "longitude" => $model_data["longitude"] ?? $lon,
                "daily" => $model_data["daily"] ?? [],
                "hourly" => $model_data["hourly"] ?? []
            ];
        }
    }
} else {
    // Single model response (shouldn't happen with our query, but handle it)
    $structured_data[] = [
        "model" => $models[0],
        "latitude" => $data["latitude"] ?? $lat,
        "longitude" => $data["longitude"] ?? $lon,
        "daily" => $data["daily"] ?? [],
        "hourly" => $data["hourly"] ?? []
    ];
}

// ====== OUTPUT ======
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode($structured_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
