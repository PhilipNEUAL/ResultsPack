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

$result = resultspack_weather_update_session_timing(
    $sessionId,
    $_POST['started_local'] ?? '',
    $_POST['ended_local'] ?? '',
    $_POST['correction_reason'] ?? ''
);

if (!$result['ok']) {
    http_response_code(400);
    exit(htmlspecialchars($result['error']));
}

$redirect =
    'WeatherSessionView.php?session_id=' . $sessionId
    . '&timing_corrected=1'
    . '&timing_changed=' . (!empty($result['changed']) ? '1' : '0');

//The corrected time window is now authoritative. Re-run the historical
//import so an earlier start/later end is filled automatically. INSERT IGNORE
//means already stored observations are not duplicated.
$importResult = resultspack_weather_import_session_observations($sessionId);

if ($importResult['ok']) {
    $redirect .=
        '&timing_import=ok'
        . '&received=' . (int) ($importResult['received'] ?? 0)
        . '&added=' . (int) ($importResult['added'] ?? 0);

    $quality = resultspack_weather_session_quality($sessionId);

    if ($quality && $quality['coverage_percent'] !== null) {
        $redirect .=
            '&coverage=' . rawurlencode(
                number_format($quality['coverage_percent'], 1, '.', '')
            )
            . '&missing=' . (int) ($quality['missing'] ?? 0);
    }
} else {
    $redirect .=
        '&timing_import=failed'
        . '&import_error=' . rawurlencode(
            $importResult['error'] ?? 'Unknown import error'
        );
}

header('Location: ' . $redirect);
exit;
