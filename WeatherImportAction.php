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

if ($sessionId <= 0) {
    http_response_code(400);
    exit('No weather session was selected.');
}

$result = resultspack_weather_import_session_observations($sessionId);

if (!$result['ok']) {
    exit(
        'Weather observation import failed: '
        . htmlspecialchars($result['error'])
    );
}

header(
    'Location: WeatherControl.php?history_session_id='
    . $sessionId
    . '&imported=1'
    . '&received=' . (int) $result['received']
    . '&added=' . (int) $result['added']
);

exit;