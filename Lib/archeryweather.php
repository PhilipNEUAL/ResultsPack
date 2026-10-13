<?php

// Load ArcheryWeather's optional public integration interface.
function resultspack_archeryweather_available()
{
    $interface = dirname(__DIR__, 2)
        . '/ArcheryWeather/Lib/integration.php';

    if (!is_readable($interface)) {
        return false;
    }

    // Avoid loading both generations of the weather library.
    if (function_exists('resultspack_weather_results_summary')) {
        return false;
    }

    require_once($interface);

    return function_exists('archeryweather_integration_version')
        && archeryweather_integration_version() === 1
        && function_exists('archeryweather_integration_sessions')
        && function_exists('archeryweather_integration_summary');
}

// Supply session choices to the ResultsPack builder.
function resultspack_archeryweather_sessions($tournamentId)
{
    $result = array(
        'sessions' => array(),
        'message' => '',
    );

    if (
        (int) $tournamentId <= 0
        || !resultspack_archeryweather_available()
    ) {
        return $result;
    }

    $result['sessions'] = archeryweather_integration_sessions(
        $tournamentId
    );

    if (!$result['sessions']) {
        $result['message'] =
            'No completed Real ArcheryWeather sessions are linked '
            . 'to this competition. Manual weather entry is available.';
    }

    return $result;
}