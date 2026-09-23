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

$action = (string) ($_POST['event_action'] ?? '');
$reason = (string) ($_POST['event_reason'] ?? '');
$note = (string) ($_POST['event_note'] ?? '');

$result = resultspack_weather_add_event(
    $action,
    $reason,
    $note
);

if (!$result['ok']) {
    exit(htmlspecialchars($result['error']));
}

header('Location: WeatherControl.php?event_recorded=1');
exit;