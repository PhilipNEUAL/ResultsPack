<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/archeryweather.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function resultspack_archeryweather_reply($status, array $payload)
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    resultspack_archeryweather_reply(
        405,
        array('ok' => false, 'error' => 'Please use the summary button.')
    );
}

$ids = array();

foreach (array('tournament_id', 'session_id') as $key) {
    $value = $_GET[$key] ?? '';

    $id = is_string($value)
        ? filter_var(
            $value,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1))
        )
        : false;

    if ($id === false) {
        resultspack_archeryweather_reply(
            400,
            array('ok' => false, 'error' => 'Please select a weather session.')
        );
    }

    $ids[$key] = $id;
}

try {
    if (!resultspack_archeryweather_available()) {
        resultspack_archeryweather_reply(
            503,
            array(
                'ok' => false,
                'error' => 'The ArcheryWeather integration is unavailable.',
            )
        );
    }

    // ArcheryWeather checks eligibility and calculates the summary.
    $result = archeryweather_integration_summary(
        $ids['tournament_id'],
        $ids['session_id']
    );

    resultspack_archeryweather_reply(
        !empty($result['ok']) ? 200 : 422,
        $result
    );
} catch (Throwable $exception) {
    resultspack_archeryweather_reply(
        500,
        array(
            'ok' => false,
            'error' => 'The weather summary could not be loaded. Please try again.',
        )
    );
}