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

$confirmation =
    trim((string) ($_POST['confirm_delete'] ?? ''));

$expectedConfirmation =
    'DELETE ' . $sessionId;

if (
    $sessionId <= 0
    || $confirmation !== $expectedConfirmation
) {
    http_response_code(400);
    exit(
        'Deletion confirmation did not match. '
        . 'No weather data was deleted.'
    );
}

$result =
    resultspack_weather_delete_test_session(
        $sessionId
    );

if (!$result['ok']) {
    http_response_code(400);
    exit(htmlspecialchars($result['error']));
}

header(
    'Location: TempestTest.php'
    . '?session_deleted=1'
    . '&deleted_session_id=' . $sessionId
);

exit;