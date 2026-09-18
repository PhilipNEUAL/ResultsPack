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

$action = (string) ($_POST['weather_action'] ?? '');

if ($action === 'stop') {
    //First stop the session. This succeeds independently of the Tempest import. I want to avoid a temporary API/internet failure to prevent the session closing.
    $result = resultspack_weather_stop_session();

    if (!$result['ok']) {
        exit(htmlspecialchars($result['error']));
    }

    $sessionId = (int) ($result['session_id'] ?? 0);

    //Now try to archive the historical Tempest observations.
    $importResult = resultspack_weather_import_session_observations(
        $sessionId
    );

    $redirect =
        'TempestTest.php?session=stopped'
        . '&history_session_id=' . $sessionId;

    if ($importResult['ok']) {
        $redirect .=
            '&auto_import=ok'
            . '&received=' . (int) ($importResult['received'] ?? 0)
            . '&added=' . (int) ($importResult['added'] ?? 0);

        //Calculate the quality result after importing.
        $quality = resultspack_weather_session_quality($sessionId);

        if (
            $quality
            && $quality['coverage_percent'] !== null
        ) {
            $redirect .=
                '&coverage='
                . rawurlencode(
                    number_format(
                        $quality['coverage_percent'],
                        1,
                        '.',
                        ''
                    )
                );
        }
    } else {
        $redirect .=
            '&auto_import=failed'
            . '&import_error='
            . rawurlencode(
                $importResult['error'] ?? 'Unknown import error'
            );
    }

    header('Location: ' . $redirect);
    exit;
}

if ($action !== 'start') {
    http_response_code(400);
    exit('Unknown weather-session action.');
}

$stationsResponse = resultspack_weather_fetch_stations();

if (!$stationsResponse['ok']) {
    exit('Unable to contact Tempest: ' . htmlspecialchars($stationsResponse['error']));
}

$stations = $stationsResponse['data']['stations'] ?? array();

if (!$stations) {
    exit('No Tempest stations are available.');
}

$station = reset($stations);

$stationId = (int) ($station['station_id'] ?? 0);
$stationName = (string) ($station['name'] ?? 'Tempest station');

$deviceId = null;

foreach (($station['devices'] ?? array()) as $device) {
    if (($device['device_type'] ?? '') === 'ST') {
        $deviceId = (int) ($device['device_id'] ?? 0);
        break;
    }
}

$timezone = 'UTC';
$currentFreshness = null;

$observationResponse = resultspack_weather_fetch_latest_observation($stationId);

if ($observationResponse['ok']) {
    $observationData = $observationResponse['data'];

    $timezone = (string) (
        $observationData['timezone']
        ?? resultspack_weather_timezone()
    );

    $fields = $observationData['ob_fields'] ?? array();
    $values = $observationData['obs'][0] ?? array();

    $obs = array();

    if ($fields && $values && count($fields) === count($values)) {
        $obs = array_combine($fields, $values);
    }

    $currentFreshness = resultspack_weather_freshness(
        $obs['timestamp'] ?? null,
        $obs['report_interval'] ?? 1
    );
}

//Do not start a research session against non-live weather data without verification
if (!$currentFreshness || $currentFreshness['status'] !== 'live') {
    $allowNonLive = !empty($_POST['allow_nonlive_weather']);

    if (!$allowNonLive) {
        http_response_code(409);

        $statusLabel = $currentFreshness
            ? $currentFreshness['label']
            : 'UNKNOWN';

        exit(
            'The Tempest weather data is currently '
            . htmlspecialchars($statusLabel)
            . '. Return to the weather monitor, review the warning, '
            . 'and explicitly choose to start the session anyway if appropriate.'
        );
    }
}

$result = resultspack_weather_start_session(array(
    'tournament_id' => $_POST['tournament_id'] ?? 0,
    'station_id' => $stationId,
    'device_id' => $deviceId,
    'station_name' => $stationName,
    'timezone' => $timezone,
    'shooting_bearing' => $_POST['shooting_bearing'] ?? null,
    'sensor_height' => $_POST['sensor_height'] ?? null,
    'position_notes' => $_POST['position_notes'] ?? '',
));

if (!$result['ok']) {
    exit(htmlspecialchars($result['error']));
}

$bearing = isset($_POST['shooting_bearing'])
    ? rawurlencode((string) $_POST['shooting_bearing'])
    : '';

header(
    'Location: TempestTest.php?session=started'
    . ($bearing !== '' ? '&shooting_bearing=' . $bearing : '')
);

exit;