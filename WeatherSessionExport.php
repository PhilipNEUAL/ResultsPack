<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

$sessionId = isset($_GET['session_id'])
    ? (int) $_GET['session_id']
    : 0;

if ($sessionId <= 0) {
    http_response_code(400);
    exit('No completed weather session was selected.');
}

$result = resultspack_weather_build_transfer_package($sessionId);

if (!$result['ok']) {
    http_response_code(404);
    exit(htmlspecialchars($result['error']));
}

$package = $result['package'];
$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));

if ($format === 'csv') {
    $csv = resultspack_weather_transfer_csv($package);

    if ($csv === false) {
        http_response_code(500);
        exit('The weather data could not be encoded as CSV.');
    }

    $filename = resultspack_weather_transfer_filename($package, 'csv');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    echo $csv;
    exit;
}

$filename = resultspack_weather_transfer_filename($package, 'json');

$json = json_encode(
    $package,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false) {
    http_response_code(500);
    exit('The weather package could not be encoded as JSON.');
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

echo $json;
exit;
