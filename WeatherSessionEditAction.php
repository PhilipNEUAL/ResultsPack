<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST request required.');
}

if (!resultspack_validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid request token.');
}

$sessionId = (int) ($_POST['session_id'] ?? 0);

$result = resultspack_weather_update_session(
    $sessionId,
    array(
        'shooting_bearing' => $_POST['shooting_bearing'] ?? null,
        'sensor_height' => $_POST['sensor_height'] ?? null,
        'forward_offset' => $_POST['forward_offset'] ?? null,
        'lateral_offset' => $_POST['lateral_offset'] ?? null,
        'ground_surface' => $_POST['ground_surface'] ?? '',
        'exposure' => $_POST['exposure'] ?? '',
        'position_notes' => $_POST['position_notes'] ?? '',
        'research_status' => $_POST['research_status'] ?? 'real',
    )
);

if (!$result['ok']) {
    exit(htmlspecialchars($result['error']));
}

header('Location: WeatherControl.php?session_updated=1');
exit;