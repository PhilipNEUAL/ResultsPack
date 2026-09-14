<?php

function resultspack_weather_config()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configFile = dirname(__DIR__) . '/WeatherConfig.local.php';

    if (!is_file($configFile)) {
        $config = array();
        return $config;
    }

    $loaded = require $configFile;

    if (!is_array($loaded)) {
        $config = array();
        return $config;
    }

    $config = $loaded;

    return $config;
}

function resultspack_weather_is_configured()
{
    $config = resultspack_weather_config();

    return !empty($config['personal_access_token']);
}

function resultspack_weather_config_summary()
{
    $config = resultspack_weather_config();

    return array(
        'configured' => resultspack_weather_is_configured(),
        'station_id' => $config['station_id'] ?? null,
        'device_id' => $config['device_id'] ?? null,
    );
}

//Make a request to the Tempest REST API.
function resultspack_weather_api_request($path, $query = array())
{
    $config = resultspack_weather_config();

    if (empty($config['personal_access_token'])) {
        return array(
            'ok' => false,
            'error' => 'Tempest access token is not configured.',
        );
    }

    if (!function_exists('curl_init')) {
        return array(
            'ok' => false,
            'error' => 'PHP cURL support is not available.',
        );
    }

    $query['token'] = $config['personal_access_token'];

    $url = 'https://swd.weatherflow.com/swd/rest/' . ltrim($path, '/')
        . '?' . http_build_query($query);

    $ch = curl_init();

    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ));

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return array(
            'ok' => false,
            'error' => 'Tempest request failed: ' . $error,
        );
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        return array(
            'ok' => false,
            'error' => 'Tempest returned HTTP ' . $httpCode . '.',
        );
    }

    if (!is_array($data)) {
        return array(
            'ok' => false,
            'error' => 'Tempest returned an invalid JSON response.',
        );
    }

    return array(
        'ok' => true,
        'data' => $data,
    );
}

//Return the stations available to this Tempest account.
function resultspack_weather_fetch_stations()
{
    return resultspack_weather_api_request('stations');
}

// Return the latest observation for a Tempest station.
function resultspack_weather_fetch_latest_observation($stationId)
{
    if (empty($stationId)) {
        return array(
            'ok' => false,
            'error' => 'No Tempest station ID was supplied.',
        );
    }

    return resultspack_weather_api_request(
        'observations/stn/' . rawurlencode((string) $stationId),
        array(
            'units_temp' => 'c',
            'units_wind' => 'mph',
            'units_pressure' => 'mb',
            'units_precip' => 'mm',
            'units_distance' => 'km',
        )
    );
}

//Format numeric weather value for display.
function resultspack_weather_format_number($value, $decimals = 1)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 'Not available';
    }

    return number_format((float) $value, $decimals, '.', '');
}

//Convert compass bearing into 16-point compass direction.
function resultspack_weather_compass_direction($degrees)
{
    if (!is_numeric($degrees)) {
        return 'Unknown';
    }

    $points = array(
        'N', 'NNE', 'NE', 'ENE',
        'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW',
        'W', 'WNW', 'NW', 'NNW'
    );

    $degrees = fmod(((float) $degrees + 360), 360);
    $index = (int) floor(($degrees + 11.25) / 22.5) % 16;

    return $points[$index];
}

//Describe how old an observation is.
function resultspack_weather_observation_age($timestamp)
{
    if (!is_numeric($timestamp)) {
        return 'Unknown age';
    }

    $seconds = max(0, time() - (int) $timestamp);

    if ($seconds < 10) {
        return 'just now';
    }

    if ($seconds < 60) {
        return $seconds . ' seconds ago';
    }

    $minutes = (int) floor($seconds / 60);

    if ($minutes === 1) {
        return '1 minute ago';
    }

    if ($minutes < 60) {
        return $minutes . ' minutes ago';
    }

    $hours = (int) floor($minutes / 60);

    return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
}

/* Describe wind direction relative to the shooting direction.
 * This might be overkill but I thought I'd give it a try.
 * He said, overkill, in a module about recording hyper-local weather events for archery shoots.
 * Tempest wind direction is the direction the wind is coming FROM.
 * Shooting bearing is the direction from the shooting line towards the targets.*/
function resultspack_weather_relative_wind($windDirection, $shootingBearing)
{
    if (!is_numeric($windDirection) || !is_numeric($shootingBearing)) {
        return null;
    }

    $windDirection = fmod(((float) $windDirection + 360), 360);
    $shootingBearing = fmod(((float) $shootingBearing + 360), 360);

    $relative = fmod($windDirection - $shootingBearing + 540, 360) - 180;
    $absolute = abs($relative);

    if ($absolute <= 22.5) {
        $label = 'Headwind';
    } elseif ($absolute >= 157.5) {
        $label = 'Tailwind';
    } elseif ($relative > 0) {
        if ($absolute < 67.5) {
            $label = 'Quartering headwind from the right';
        } elseif ($absolute <= 112.5) {
            $label = 'Right-to-left crosswind';
        } else {
            $label = 'Quartering tailwind from the right';
        }
    } else {
        if ($absolute < 67.5) {
            $label = 'Quartering headwind from the left';
        } elseif ($absolute <= 112.5) {
            $label = 'Left-to-right crosswind';
        } else {
            $label = 'Quartering tailwind from the left';
        }
    }

    return array(
        'angle' => round($relative, 1),
        'label' => $label,
    );
}

//Create a weather session table when first needed.
function resultspack_weather_ensure_sessions_table()
{
    static $done = false;

    if ($done) {
        return;
    }

    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS CustomResultsPackWeatherSessions (" .
        "CrwsId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwsTournament int NOT NULL," .
        "CrwsStationId int NOT NULL," .
        "CrwsDeviceId int DEFAULT NULL," .
        "CrwsStationName varchar(255) NOT NULL DEFAULT ''," .
        "CrwsStartedEpoch bigint unsigned NOT NULL," .
        "CrwsEndedEpoch bigint unsigned DEFAULT NULL," .
        "CrwsTimezone varchar(64) NOT NULL DEFAULT 'UTC'," .
        "CrwsShootingBearing decimal(5,1) DEFAULT NULL," .
        "CrwsSensorHeight decimal(5,2) DEFAULT NULL," .
        "CrwsPositionNotes text NOT NULL," .
        "CrwsCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwsId)," .
        "KEY CrwsTournament (CrwsTournament)," .
        "KEY CrwsActive (CrwsEndedEpoch)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

//Return the active weather session, if there is one.
function resultspack_weather_get_active_session()
{
    resultspack_weather_ensure_sessions_table();

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsTournament,CrwsStationId,CrwsDeviceId," .
        "CrwsStationName,CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes " .
        "FROM CustomResultsPackWeatherSessions " .
        "WHERE CrwsEndedEpoch IS NULL " .
        "ORDER BY CrwsId DESC LIMIT 1"
    );

    $row = safe_fetch($result);

    if (!$row) {
        return null;
    }

    return array(
        'id' => (int) $row->CrwsId,
        'tournament_id' => (int) $row->CrwsTournament,
        'station_id' => (int) $row->CrwsStationId,
        'device_id' => $row->CrwsDeviceId !== null ? (int) $row->CrwsDeviceId : null,
        'station_name' => (string) $row->CrwsStationName,
        'started_epoch' => (int) $row->CrwsStartedEpoch,
        'ended_epoch' => $row->CrwsEndedEpoch !== null ? (int) $row->CrwsEndedEpoch : null,
        'timezone' => (string) $row->CrwsTimezone,
        'shooting_bearing' => $row->CrwsShootingBearing !== null ? (float) $row->CrwsShootingBearing : null,
        'sensor_height' => $row->CrwsSensorHeight !== null ? (float) $row->CrwsSensorHeight : null,
        'position_notes' => (string) $row->CrwsPositionNotes,
    );
}

//Start a new weather-monitoring session.
function resultspack_weather_start_session(array $values)
{
    resultspack_weather_ensure_sessions_table();

    if (resultspack_weather_get_active_session()) {
        return array(
            'ok' => false,
            'error' => 'A weather session is already active.',
        );
    }

    $tournamentId = (int) ($values['tournament_id'] ?? 0);
    $stationId = (int) ($values['station_id'] ?? 0);
    $deviceId = (int) ($values['device_id'] ?? 0);

    if ($tournamentId <= 0) {
        return array('ok' => false, 'error' => 'No competition was selected.');
    }

    if ($stationId <= 0) {
        return array('ok' => false, 'error' => 'No Tempest station was available.');
    }

    $stationName = resultspack_normalise_whitespace($values['station_name'] ?? '');
    $timezone = resultspack_normalise_whitespace($values['timezone'] ?? 'UTC');

    if ($timezone === '') {
        $timezone = 'UTC';
    }

    $bearing = null;

    if (isset($values['shooting_bearing']) && is_numeric($values['shooting_bearing'])) {
        $candidate = (float) $values['shooting_bearing'];

        if ($candidate >= 0 && $candidate < 360) {
            $bearing = $candidate;
        }
    }

    $sensorHeight = null;

    if (isset($values['sensor_height']) && is_numeric($values['sensor_height'])) {
        $candidate = (float) $values['sensor_height'];

        if ($candidate > 0 && $candidate <= 20) {
            $sensorHeight = $candidate;
        }
    }

    $positionNotes = trim((string) ($values['position_notes'] ?? ''));

    if (function_exists('mb_substr')) {
        $positionNotes = mb_substr($positionNotes, 0, 2000, 'UTF-8');
    } else {
        $positionNotes = substr($positionNotes, 0, 2000);
    }

    $started = time();

    $deviceSql = $deviceId > 0 ? (string) $deviceId : 'NULL';
    $bearingSql = $bearing !== null
        ? number_format($bearing, 1, '.', '')
        : 'NULL';
    $heightSql = $sensorHeight !== null
        ? number_format($sensorHeight, 2, '.', '')
        : 'NULL';

    safe_w_sql(
        "INSERT INTO CustomResultsPackWeatherSessions (" .
        "CrwsTournament,CrwsStationId,CrwsDeviceId,CrwsStationName," .
        "CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes,CrwsCreated" .
        ") VALUES (" .
        $tournamentId . "," .
        $stationId . "," .
        $deviceSql . "," .
        StrSafe_DB($stationName) . "," .
        $started . "," .
        "NULL," .
        StrSafe_DB($timezone) . "," .
        $bearingSql . "," .
        $heightSql . "," .
        StrSafe_DB($positionNotes) . "," .
        "NOW())"
    );

    return array(
        'ok' => true,
        'started_epoch' => $started,
    );
}

//End the currently active weather-monitoring session.
function resultspack_weather_stop_session()
{
    $session = resultspack_weather_get_active_session();

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'There is no active weather session.',
        );
    }

    $ended = time();

    safe_w_sql(
        "UPDATE CustomResultsPackWeatherSessions " .
        "SET CrwsEndedEpoch=" . $ended .
        " WHERE CrwsId=" . (int) $session['id'] .
        " AND CrwsEndedEpoch IS NULL"
    );

    return array(
        'ok' => true,
        'ended_epoch' => $ended,
    );
}

//Return the most recently created weather session.
function resultspack_weather_get_latest_session()
{
    resultspack_weather_ensure_sessions_table();

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsTournament,CrwsStationId,CrwsDeviceId," .
        "CrwsStationName,CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes " .
        "FROM CustomResultsPackWeatherSessions " .
        "ORDER BY CrwsId DESC LIMIT 1"
    );

    $row = safe_fetch($result);

    if (!$row) {
        return null;
    }

    return array(
        'id' => (int) $row->CrwsId,
        'tournament_id' => (int) $row->CrwsTournament,
        'station_id' => (int) $row->CrwsStationId,
        'device_id' => $row->CrwsDeviceId !== null ? (int) $row->CrwsDeviceId : null,
        'station_name' => (string) $row->CrwsStationName,
        'started_epoch' => (int) $row->CrwsStartedEpoch,
        'ended_epoch' => $row->CrwsEndedEpoch !== null ? (int) $row->CrwsEndedEpoch : null,
        'timezone' => (string) $row->CrwsTimezone,
        'shooting_bearing' => $row->CrwsShootingBearing !== null
            ? (float) $row->CrwsShootingBearing
            : null,
        'sensor_height' => $row->CrwsSensorHeight !== null
            ? (float) $row->CrwsSensorHeight
            : null,
        'position_notes' => (string) $row->CrwsPositionNotes,
    );
}

/**
 * Assess age of Tempest observation.
 *
 * LIVE:
 *     no more than 2 reporting intervals old
 *
 * DELAYED:
 *     more than 2 but no more than 5 reporting intervals old
 *
 * STALE:
 *     more than 5 reporting intervals old
 */
function resultspack_weather_freshness($timestamp, $reportInterval = 1)
{
    if (!is_numeric($timestamp)) {
        return array(
            'status' => 'unknown',
            'label' => 'UNKNOWN',
            'age_seconds' => null,
            'age_text' => 'Observation time unavailable',
            'report_interval_minutes' => null,
        );
    }

    if (!is_numeric($reportInterval) || (float) $reportInterval <= 0) {
        $reportInterval = 1;
    }

    $reportInterval = (float) $reportInterval;
    $ageSeconds = max(0, time() - (int) $timestamp);
    $intervalSeconds = $reportInterval * 60;

    if ($ageSeconds <= ($intervalSeconds * 2)) {
        $status = 'live';
        $label = 'LIVE';
    } elseif ($ageSeconds <= ($intervalSeconds * 5)) {
        $status = 'delayed';
        $label = 'DELAYED';
    } else {
        $status = 'stale';
        $label = 'STALE';
    }

    return array(
        'status' => $status,
        'label' => $label,
        'age_seconds' => $ageSeconds,
        'age_text' => resultspack_weather_observation_age($timestamp),
        'report_interval_minutes' => $reportInterval,
    );
}