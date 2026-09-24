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

$sessionId =
    (int) ($_POST['session_id'] ?? 0);

$verifiedBearing =
    $_POST['verified_bearing'] ?? null;

$verification =
    $_POST['verification'] ?? 'unverified';

$reason =
    $_POST['reason'] ?? '';

$result =
    resultspack_weather_update_direction_reference(
        $sessionId,
        $verifiedBearing,
        $verification,
        $reason
    );

if (!$result['ok']) {
    exit(
        'Direction reference correction failed: '
        . htmlspecialchars($result['error'])
    );
}

header(
    'Location: WeatherSessionView.php?session_id='
    . $sessionId
    . '&direction_corrected=1'
);

exit;