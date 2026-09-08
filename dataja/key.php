<?php
session_start();
// Generate a more secure key that lasts longer
$key = hash('sha256', uniqid() . time() . rand());
$key = substr($key, 0, 16); // Use first 16 characters
// Store key with timestamp (valid for 1 hour)
$_SESSION['download_key'] = $key;
$_SESSION['key_timestamp'] = time();
$_SESSION['key_expires'] = time() + 3600;
// Log for debugging
error_log("Generated key: $key for session: " . session_id());
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow CORS if needed
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');
echo json_encode([
    "key" => $key,
    "expires_in" => 3600,
    "session_id" => session_id() // For debugging
]);
?>