<?php

// ====== MODEL SHORT NAMES ======
$MODEL_NAMES = ["dwd_icon_global" => "icon", "ecmwf_ifs025" => "ecmwf_ifs", "ncep_gfs_global" => "gfs", "meteofrance_arpege_world" => "arpege", "jma_gsm" => "jma",
                "ncep_aigfs025" => "aigfs", "ukmo_global_deterministic_10km"=> "ukmet", "cmc_gem_gdps" => "gem", "ecmwf_aifs025_single" => "ecmwf_aifs", "cma_grapes_global" => "cma"];

// Reverse map: short → full
$MODEL_ALIASES = array_flip($MODEL_NAMES);

// ====== PARAMETERS ======
$lat   = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
$lon   = isset($_GET['lon']) ? floatval($_GET['lon']) : 0;
$user_model = $_GET['model'] ?? "icon_global";

// Validate coords
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    http_response_code(400);
    echo "Invalid coordinates.";
    exit;
}

// Map short → full (if user gave short name)
if (isset($MODEL_ALIASES[$user_model])) {
    $api_model = $MODEL_ALIASES[$user_model]; // full name
} else {
    $api_model = $user_model; // assume already full
}

// For output: full → short
$model_short = $MODEL_NAMES[$api_model] ?? $api_model;

// ====== API REQUEST ======
$api_url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}" .
    "&hourly=temperature_2m,precipitation,pressure_msl,wind_gusts_10m,temperature_925hPa,temperature_850hPa,geopotential_height_500hPa&forecast_days=16&timezone=auto&models={$api_model}";

$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false
]);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    http_response_code(500);
    echo "Error fetching data: " . curl_error($ch);
    exit;
}

// ====== PARSE JSON ======
$data = json_decode($response, true);
if (!$data) {
    http_response_code(500);
    echo "Invalid response from Open-Meteo.";
    exit;
}

// Add model info to response
$data["model_full"]  = $api_model;
$data["model_short"] = $model_short;

// ====== OUTPUT ======
header('Content-Type: application/json');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
