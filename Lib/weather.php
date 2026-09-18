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

//Return the configured timezone for weather sessions.
function resultspack_weather_timezone()
{
    $config = resultspack_weather_config();

    $timezone = trim(
        (string) ($config['timezone'] ?? 'UTC')
    );

    if ($timezone === '') {
        return 'UTC';
    }

    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Exception $e) {
        return 'UTC';
    }
}

//Format a Unix timestamp in the requested weather-session timezone.
function resultspack_weather_format_timestamp(
    $timestamp,
    $timezone = null,
    $format = 'Y-m-d H:i:s'
) {
    if (!is_numeric($timestamp)) {
        return 'Not available';
    }

    if ($timezone === null || trim((string) $timezone) === '') {
        $timezone = resultspack_weather_timezone();
    }

    try {
        $tz = new DateTimeZone((string) $timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    $date = new DateTimeImmutable('@' . (int) $timestamp);

    return $date
        ->setTimezone($tz)
        ->format($format);
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

//Fetch historical one-minute observations for a Tempest station. Times are Unix timestamps in UTC.
function resultspack_weather_fetch_observations($stationId, $startEpoch, $endEpoch)
{
    if (empty($stationId)) {
        return array(
            'ok' => false,
            'error' => 'No Tempest station ID was supplied.',
        );
    }

    $startEpoch = (int) $startEpoch;
    $endEpoch = (int) $endEpoch;

    if ($startEpoch <= 0 || $endEpoch <= 0) {
        return array(
            'ok' => false,
            'error' => 'Invalid weather observation time range.',
        );
    }

    if ($endEpoch <= $startEpoch) {
        return array(
            'ok' => false,
            'error' => 'Weather observation end time must be after the start time.',
        );
    }

    return resultspack_weather_api_request(
        'observations/stn/' . rawurlencode((string) $stationId),
        array(
            'time_start' => $startEpoch,
            'time_end' => $endEpoch,
            'bucket' => 1,
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
        "CrwsResearchStatus varchar(16) NOT NULL DEFAULT 'test'," .
        "CrwsCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwsId)," .
        "KEY CrwsTournament (CrwsTournament)," .
        "KEY CrwsActive (CrwsEndedEpoch)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columnCheck = safe_r_sql(
        "SHOW COLUMNS FROM CustomResultsPackWeatherSessions " .
        "LIKE 'CrwsResearchStatus'"
    );

    if (!safe_fetch($columnCheck)) {
        safe_w_sql(
            "ALTER TABLE CustomResultsPackWeatherSessions " .
            "ADD COLUMN CrwsResearchStatus varchar(16) NOT NULL DEFAULT 'test' " .
            "AFTER CrwsPositionNotes"
        );
    }

    $done = true;
}

//Return the active weather session, if there is one.
function resultspack_weather_get_active_session()
{
    resultspack_weather_ensure_sessions_table();

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsTournament,CrwsStationId,CrwsDeviceId," .
        "CrwsStationName,CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes,CrwsResearchStatus " .
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
        'research_status' => resultspack_weather_research_status((string) $row->CrwsResearchStatus),
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

    $researchStatus = resultspack_weather_research_status(
    $values['research_status'] ?? 'real'
    );

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
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes," .
        "CrwsResearchStatus,CrwsCreated" .
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
        StrSafe_DB($researchStatus) . "," .
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
        'session_id' => (int) $session['id'],
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
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes,CrwsResearchStatus " .
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
        'research_status' => resultspack_weather_research_status((string) $row->CrwsResearchStatus),
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

//Return completed weather sessions, newest first.
function resultspack_weather_get_completed_sessions()
{
    resultspack_weather_ensure_sessions_table();

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsTournament,CrwsStationId,CrwsDeviceId," .
        "CrwsStationName,CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes,CrwsResearchStatus " .
        "FROM CustomResultsPackWeatherSessions " .
        "WHERE CrwsEndedEpoch IS NOT NULL " .
        "ORDER BY CrwsStartedEpoch DESC"
    );

    $sessions = array();

    while ($row = safe_fetch($result)) {
        $sessions[] = array(
            'id' => (int) $row->CrwsId,
            'tournament_id' => (int) $row->CrwsTournament,
            'station_id' => (int) $row->CrwsStationId,
            'device_id' => $row->CrwsDeviceId !== null
                ? (int) $row->CrwsDeviceId
                : null,
            'station_name' => (string) $row->CrwsStationName,
            'started_epoch' => (int) $row->CrwsStartedEpoch,
            'ended_epoch' => (int) $row->CrwsEndedEpoch,
            'timezone' => (string) $row->CrwsTimezone,
            'shooting_bearing' => $row->CrwsShootingBearing !== null
                ? (float) $row->CrwsShootingBearing
                : null,
            'sensor_height' => $row->CrwsSensorHeight !== null
                ? (float) $row->CrwsSensorHeight
                : null,
            'position_notes' => (string) $row->CrwsPositionNotes,
            'research_status' => resultspack_weather_research_status((string) $row->CrwsResearchStatus),
        );
    }

    return $sessions;
}

//Find one completed weather session by ID.
function resultspack_weather_get_completed_session($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return null;
    }

    foreach (resultspack_weather_get_completed_sessions() as $session) {
        if ((int) $session['id'] === $sessionId) {
            return $session;
        }
    }

    return null;
}

//Create the locally stored Tempest observation table when first needed.
function resultspack_weather_ensure_observations_table()
{
    static $done = false;

    if ($done) {
        return;
    }

    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS CustomResultsPackWeatherObservations (" .
        "CrwoId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwoSession int unsigned NOT NULL," .
        "CrwoTimestamp bigint unsigned NOT NULL," .
        "CrwoReportInterval int unsigned DEFAULT NULL," .
        "CrwoWindLull decimal(10,3) DEFAULT NULL," .
        "CrwoWindAvg decimal(10,3) DEFAULT NULL," .
        "CrwoWindGust decimal(10,3) DEFAULT NULL," .
        "CrwoWindDir decimal(6,2) DEFAULT NULL," .
        "CrwoStationPressure decimal(10,3) DEFAULT NULL," .
        "CrwoSeaLevelPressure decimal(10,3) DEFAULT NULL," .
        "CrwoAirTemp decimal(10,3) DEFAULT NULL," .
        "CrwoRh decimal(6,2) DEFAULT NULL," .
        "CrwoIlluminance decimal(14,3) DEFAULT NULL," .
        "CrwoUv decimal(10,3) DEFAULT NULL," .
        "CrwoSolarRadiation decimal(14,3) DEFAULT NULL," .
        "CrwoPrecipAccumulation decimal(14,6) DEFAULT NULL," .
        "CrwoLocalDayPrecipAccumulation decimal(14,6) DEFAULT NULL," .
        "CrwoPrecipType int DEFAULT NULL," .
        "CrwoStrikeCount int DEFAULT NULL," .
        "CrwoStrikeDistance decimal(10,3) DEFAULT NULL," .
        "CrwoImported datetime NOT NULL," .
        "PRIMARY KEY (CrwoId)," .
        "UNIQUE KEY CrwoSessionTimestamp (CrwoSession,CrwoTimestamp)," .
        "KEY CrwoSession (CrwoSession)," .
        "KEY CrwoTimestamp (CrwoTimestamp)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

//Research status (identify tests)
function resultspack_weather_research_status($value)
{
    $allowed = array('real', 'test', 'excluded');

    return in_array($value, $allowed, true)
        ? $value
        : 'test';
}

//Turn a nullable numeric Tempest value into safeSQL.
function resultspack_weather_sql_number($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 'NULL';
    }

    return (string) (0 + $value);
}

//Count locally stored observations for one weather session.
function resultspack_weather_count_observations($sessionId)
{
    resultspack_weather_ensure_observations_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return 0;
    }

    //Count only observations inside the session's current research window.
    //Rows from an older, wider window are retained for audit/reversibility.
    $session = resultspack_weather_get_completed_session($sessionId);

    $where = "CrwoSession=" . $sessionId;

    if ($session) {
        $where .=
            " AND CrwoTimestamp>=" . (int) $session['started_epoch'] .
            " AND CrwoTimestamp<=" . (int) $session['ended_epoch'];
    }

    $result = safe_r_sql(
        "SELECT COUNT(*) AS ObservationCount " .
        "FROM CustomResultsPackWeatherObservations " .
        "WHERE " . $where
    );

    $row = safe_fetch($result);

    return $row ? (int) $row->ObservationCount : 0;
}

//Import historical Tempest observations for a completed session. Duplicate session/timestamp combinations are ignored.
function resultspack_weather_import_session_observations($sessionId)
{
    resultspack_weather_ensure_observations_table();

    $session = resultspack_weather_get_completed_session($sessionId);

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'Completed weather session not found.',
        );
    }

    $response = resultspack_weather_fetch_observations(
        $session['station_id'],
        $session['started_epoch'],
        $session['ended_epoch']
    );

    if (!$response['ok']) {
        return $response;
    }

    $data = $response['data'];

    $fields = $data['ob_fields'] ?? array();
    $rows = $data['obs'] ?? array();

    if (!$fields || !$rows) {
        return array(
            'ok' => true,
            'received' => 0,
            'before' => resultspack_weather_count_observations($sessionId),
            'after' => resultspack_weather_count_observations($sessionId),
            'added' => 0,
        );
    }

    $before = resultspack_weather_count_observations($sessionId);

    foreach ($rows as $values) {
        if (count($fields) !== count($values)) {
            continue;
        }

        $obs = array_combine($fields, $values);

        $timestamp = isset($obs['timestamp'])
            ? (int) $obs['timestamp']
            : 0;

        if ($timestamp <= 0) {
            continue;
        }

        safe_w_sql(
            "INSERT IGNORE INTO CustomResultsPackWeatherObservations (" .
            "CrwoSession,CrwoTimestamp,CrwoReportInterval," .
            "CrwoWindLull,CrwoWindAvg,CrwoWindGust,CrwoWindDir," .
            "CrwoStationPressure,CrwoSeaLevelPressure,CrwoAirTemp,CrwoRh," .
            "CrwoIlluminance,CrwoUv,CrwoSolarRadiation," .
            "CrwoPrecipAccumulation,CrwoLocalDayPrecipAccumulation," .
            "CrwoPrecipType,CrwoStrikeCount,CrwoStrikeDistance,CrwoImported" .
            ") VALUES (" .
            (int) $sessionId . "," .
            $timestamp . "," .
            resultspack_weather_sql_number($obs['report_interval'] ?? null) . "," .
            resultspack_weather_sql_number($obs['wind_lull'] ?? null) . "," .
            resultspack_weather_sql_number($obs['wind_avg'] ?? null) . "," .
            resultspack_weather_sql_number($obs['wind_gust'] ?? null) . "," .
            resultspack_weather_sql_number($obs['wind_dir'] ?? null) . "," .
            resultspack_weather_sql_number($obs['station_pressure'] ?? null) . "," .
            resultspack_weather_sql_number($obs['sea_level_pressure'] ?? null) . "," .
            resultspack_weather_sql_number($obs['air_temp'] ?? null) . "," .
            resultspack_weather_sql_number($obs['rh'] ?? null) . "," .
            resultspack_weather_sql_number($obs['illuminance'] ?? null) . "," .
            resultspack_weather_sql_number($obs['uv'] ?? null) . "," .
            resultspack_weather_sql_number($obs['solar_radiation'] ?? null) . "," .
            resultspack_weather_sql_number($obs['precip_accumulation'] ?? null) . "," .
            resultspack_weather_sql_number($obs['local_day_precip_accumulation'] ?? null) . "," .
            resultspack_weather_sql_number($obs['precip_type'] ?? null) . "," .
            resultspack_weather_sql_number($obs['strike_count'] ?? null) . "," .
            resultspack_weather_sql_number($obs['strike_distance'] ?? null) . "," .
            "NOW())"
        );
    }

    $after = resultspack_weather_count_observations($sessionId);

    return array(
        'ok' => true,
        'received' => count($rows),
        'before' => $before,
        'after' => $after,
        'added' => max(0, $after - $before),
    );
}

//Return the timestamps stored locally for one weather session.
function resultspack_weather_get_observation_timestamps($sessionId)
{
    resultspack_weather_ensure_observations_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    $session = resultspack_weather_get_completed_session($sessionId);

    $where = "CrwoSession=" . $sessionId;

    if ($session) {
        $where .=
            " AND CrwoTimestamp>=" . (int) $session['started_epoch'] .
            " AND CrwoTimestamp<=" . (int) $session['ended_epoch'];
    }

    $result = safe_r_sql(
        "SELECT CrwoTimestamp " .
        "FROM CustomResultsPackWeatherObservations " .
        "WHERE " . $where . " " .
        "ORDER BY CrwoTimestamp ASC"
    );

    $timestamps = array();

    while ($row = safe_fetch($result)) {
        $timestamps[] = (int) $row->CrwoTimestamp;
    }

    return $timestamps;
}

/**
 * Calculate data-coverage statistics for one completed weather session.
 *
 * Tempest bucket=1 observations are minute-aligned, so the expected
 * observations are the complete minute marks falling inside the
 * recorded session window.
 */
function resultspack_weather_session_quality($sessionId)
{
    $session = resultspack_weather_get_completed_session($sessionId);

    if (!$session) {
        return null;
    }

    $start = (int) $session['started_epoch'];
    $end = (int) $session['ended_epoch'];

    if ($start <= 0 || $end <= $start) {
        return null;
    }

    $intervalSeconds = 60;

    /*
     * Example:
     *
     * session starts 15:01:23
     * session ends   15:22:41
     *
     * expected minute observations are:
     * 15:02, 15:03 ... 15:22
     */
    $firstExpected = (int) (ceil($start / $intervalSeconds) * $intervalSeconds);
    $lastExpected = (int) (floor($end / $intervalSeconds) * $intervalSeconds);

    $expected = 0;

    if ($firstExpected <= $lastExpected) {
        $expected =
            (int) floor(
                ($lastExpected - $firstExpected) / $intervalSeconds
            ) + 1;
    }

    $timestamps = resultspack_weather_get_observation_timestamps($sessionId);

    $storedMap = array();

    foreach ($timestamps as $timestamp) {
        $storedMap[$timestamp] = true;
    }

    $receivedExpected = 0;
    $missing = 0;
    $currentMissingRun = 0;
    $longestMissingRun = 0;

    if ($expected > 0) {
        for (
            $timestamp = $firstExpected;
            $timestamp <= $lastExpected;
            $timestamp += $intervalSeconds
        ) {
            if (isset($storedMap[$timestamp])) {
                $receivedExpected++;
                $currentMissingRun = 0;
            } else {
                $missing++;
                $currentMissingRun++;

                if ($currentMissingRun > $longestMissingRun) {
                    $longestMissingRun = $currentMissingRun;
                }
            }
        }
    }

    $coverage = $expected > 0
        ? ($receivedExpected / $expected) * 100
        : null;

    return array(
        'expected' => $expected,
        'received' => $receivedExpected,
        'stored_total' => count($timestamps),
        'missing' => $missing,
        'coverage_percent' => $coverage,
        'longest_gap_minutes' => $longestMissingRun,
        'first_timestamp' => $timestamps ? reset($timestamps) : null,
        'last_timestamp' => $timestamps ? end($timestamps) : null,
    );
}

/**
 * Create the weather-event table when first needed.
 */
function resultspack_weather_ensure_events_table()
{
    static $done = false;

    if ($done) {
        return;
    }

    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS CustomResultsPackWeatherEvents (" .
        "CrweId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrweSession int unsigned NOT NULL," .
        "CrweTimestamp bigint unsigned NOT NULL," .
        "CrweAction varchar(32) NOT NULL," .
        "CrweReason varchar(64) NOT NULL DEFAULT ''," .
        "CrweNote text NOT NULL," .
        "CrweCreated datetime NOT NULL," .
        "PRIMARY KEY (CrweId)," .
        "KEY CrweSession (CrweSession)," .
        "KEY CrweTimestamp (CrweTimestamp)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

//Record a judge/weather event against the active weather session.
function resultspack_weather_add_event($action, $reason = '', $note = '')
{
    resultspack_weather_ensure_events_table();

    $session = resultspack_weather_get_active_session();

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'There is no active weather session.',
        );
    }

    $allowedActions = array(
        'delay',
        'suspend',
        'resume',
        'abandon',
    );

    if (!in_array($action, $allowedActions, true)) {
        return array(
            'ok' => false,
            'error' => 'Unknown weather event.',
        );
    }

    $allowedReasons = array(
        '',
        'wind',
        'lightning',
        'rain',
        'heat',
        'cold',
        'visibility',
        'field_conditions',
        'equipment',
        'other',
    );

    if (!in_array($reason, $allowedReasons, true)) {
        $reason = 'other';
    }

    $note = trim((string) $note);

    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 1000, 'UTF-8');
    } else {
        $note = substr($note, 0, 1000);
    }

    $timestamp = time();

    safe_w_sql(
        "INSERT INTO CustomResultsPackWeatherEvents (" .
        "CrweSession,CrweTimestamp,CrweAction,CrweReason,CrweNote,CrweCreated" .
        ") VALUES (" .
        (int) $session['id'] . "," .
        $timestamp . "," .
        StrSafe_DB($action) . "," .
        StrSafe_DB($reason) . "," .
        StrSafe_DB($note) . "," .
        "NOW())"
    );

    return array(
        'ok' => true,
        'timestamp' => $timestamp,
    );
}

//Return recorded judge/weather events for one session.
function resultspack_weather_get_events($sessionId)
{
    resultspack_weather_ensure_events_table();

    $sessionId = (int) $sessionId;

    $result = safe_r_sql(
        "SELECT CrweId,CrweTimestamp,CrweAction,CrweReason,CrweNote " .
        "FROM CustomResultsPackWeatherEvents " .
        "WHERE CrweSession=" . $sessionId . " " .
        "ORDER BY CrweTimestamp ASC, CrweId ASC"
    );

    $events = array();

    while ($row = safe_fetch($result)) {
        $events[] = array(
            'id' => (int) $row->CrweId,
            'timestamp' => (int) $row->CrweTimestamp,
            'action' => (string) $row->CrweAction,
            'reason' => (string) $row->CrweReason,
            'note' => (string) $row->CrweNote,
        );
    }

    return $events;
}

//Create the timing-correction audit table when first needed.
function resultspack_weather_ensure_timing_corrections_table()
{
    static $done = false;

    if ($done) {
        return;
    }

    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS CustomResultsPackWeatherTimingCorrections (" .
        "CrwtcId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwtcSession int unsigned NOT NULL," .
        "CrwtcOldStartedEpoch bigint unsigned NOT NULL," .
        "CrwtcOldEndedEpoch bigint unsigned NOT NULL," .
        "CrwtcNewStartedEpoch bigint unsigned NOT NULL," .
        "CrwtcNewEndedEpoch bigint unsigned NOT NULL," .
        "CrwtcTimezone varchar(64) NOT NULL DEFAULT 'UTC'," .
        "CrwtcReason text NOT NULL," .
        "CrwtcCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwtcId)," .
        "KEY CrwtcSession (CrwtcSession)," .
        "KEY CrwtcCreated (CrwtcCreated)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $done = true;
}

//Turn an HTML datetime-local value into a Unix timestamp in a named timezone.
function resultspack_weather_parse_local_datetime($value, $timezone)
{
    $value = trim((string) $value);
    $timezone = trim((string) $timezone);

    if ($value === '') {
        return null;
    }

    if ($timezone === '') {
        $timezone = resultspack_weather_timezone();
    }

    try {
        $tz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        return null;
    }

    foreach (array('Y-m-d\\TH:i:s', 'Y-m-d\\TH:i') as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $tz);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date
            && ($errors === false || (
                (int) ($errors['warning_count'] ?? 0) === 0
                && (int) ($errors['error_count'] ?? 0) === 0
            ))
            && $date->format($format) === $value
        ) {
            return $date->getTimestamp();
        }
    }

    return null;
}

//Correct the start/end of a completed weather session while preserving an audit trail.
function resultspack_weather_update_session_timing(
    $sessionId,
    $startLocal,
    $endLocal,
    $reason
) {
    resultspack_weather_ensure_sessions_table();
    resultspack_weather_ensure_timing_corrections_table();

    $sessionId = (int) $sessionId;

    $session = resultspack_weather_get_completed_session($sessionId);

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'Completed weather session not found.',
        );
    }

    $reason = trim((string) $reason);

    if ($reason === '') {
        return array(
            'ok' => false,
            'error' => 'Please record why the session timing is being corrected.',
        );
    }

    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 2000, 'UTF-8');
    } else {
        $reason = substr($reason, 0, 2000);
    }

    $timezone = trim((string) ($session['timezone'] ?? ''));

    if ($timezone === '') {
        $timezone = resultspack_weather_timezone();
    }

    $newStarted = resultspack_weather_parse_local_datetime(
        $startLocal,
        $timezone
    );

    $newEnded = resultspack_weather_parse_local_datetime(
        $endLocal,
        $timezone
    );

    if ($newStarted === null || $newEnded === null) {
        return array(
            'ok' => false,
            'error' => 'The corrected start or end time could not be understood.',
        );
    }

    if ($newEnded <= $newStarted) {
        return array(
            'ok' => false,
            'error' => 'The corrected end time must be after the corrected start time.',
        );
    }

    //A completed research window should not extend into the future.
    if ($newStarted > time() + 300 || $newEnded > time() + 300) {
        return array(
            'ok' => false,
            'error' => 'The corrected session times cannot be in the future.',
        );
    }

    $oldStarted = (int) $session['started_epoch'];
    $oldEnded = (int) $session['ended_epoch'];

    if ($newStarted === $oldStarted && $newEnded === $oldEnded) {
        return array(
            'ok' => true,
            'changed' => false,
            'session_id' => $sessionId,
            'started_epoch' => $newStarted,
            'ended_epoch' => $newEnded,
            'timezone' => $timezone,
        );
    }

    //Record the old and new values before changing the session itself.
    safe_w_sql(
        "INSERT INTO CustomResultsPackWeatherTimingCorrections (" .
        "CrwtcSession,CrwtcOldStartedEpoch,CrwtcOldEndedEpoch," .
        "CrwtcNewStartedEpoch,CrwtcNewEndedEpoch,CrwtcTimezone," .
        "CrwtcReason,CrwtcCreated" .
        ") VALUES (" .
        $sessionId . "," .
        $oldStarted . "," .
        $oldEnded . "," .
        $newStarted . "," .
        $newEnded . "," .
        StrSafe_DB($timezone) . "," .
        StrSafe_DB($reason) . "," .
        "NOW())"
    );

    safe_w_sql(
        "UPDATE CustomResultsPackWeatherSessions SET " .
        "CrwsStartedEpoch=" . $newStarted . "," .
        "CrwsEndedEpoch=" . $newEnded . " " .
        "WHERE CrwsId=" . $sessionId . " " .
        "AND CrwsEndedEpoch IS NOT NULL"
    );

    return array(
        'ok' => true,
        'changed' => true,
        'session_id' => $sessionId,
        'old_started_epoch' => $oldStarted,
        'old_ended_epoch' => $oldEnded,
        'started_epoch' => $newStarted,
        'ended_epoch' => $newEnded,
        'timezone' => $timezone,
    );
}

//Return timing corrections for one weather session, newest first.
function resultspack_weather_get_timing_corrections($sessionId)
{
    resultspack_weather_ensure_timing_corrections_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT CrwtcId,CrwtcSession,CrwtcOldStartedEpoch,CrwtcOldEndedEpoch," .
        "CrwtcNewStartedEpoch,CrwtcNewEndedEpoch,CrwtcTimezone," .
        "CrwtcReason,CrwtcCreated " .
        "FROM CustomResultsPackWeatherTimingCorrections " .
        "WHERE CrwtcSession=" . $sessionId . " " .
        "ORDER BY CrwtcId DESC"
    );

    $corrections = array();

    while ($row = safe_fetch($result)) {
        $corrections[] = array(
            'id' => (int) $row->CrwtcId,
            'session_id' => (int) $row->CrwtcSession,
            'old_started_epoch' => (int) $row->CrwtcOldStartedEpoch,
            'old_ended_epoch' => (int) $row->CrwtcOldEndedEpoch,
            'new_started_epoch' => (int) $row->CrwtcNewStartedEpoch,
            'new_ended_epoch' => (int) $row->CrwtcNewEndedEpoch,
            'timezone' => (string) $row->CrwtcTimezone,
            'reason' => (string) $row->CrwtcReason,
            'created' => (string) $row->CrwtcCreated,
        );
    }

    return $corrections;
}

//Return all weather sessions, newest first.
function resultspack_weather_get_sessions()
{
    resultspack_weather_ensure_sessions_table();

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsTournament,CrwsStationId,CrwsDeviceId," .
        "CrwsStationName,CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight,CrwsPositionNotes," .
        "CrwsResearchStatus " .
        "FROM CustomResultsPackWeatherSessions " .
        "ORDER BY CrwsStartedEpoch DESC"
    );

    $sessions = array();

    while ($row = safe_fetch($result)) {
        $sessions[] = array(
            'id' => (int) $row->CrwsId,
            'tournament_id' => (int) $row->CrwsTournament,
            'station_id' => (int) $row->CrwsStationId,
            'station_name' => (string) $row->CrwsStationName,
            'started_epoch' => (int) $row->CrwsStartedEpoch,
            'ended_epoch' => $row->CrwsEndedEpoch !== null
                ? (int) $row->CrwsEndedEpoch
                : null,
            'timezone' => (string) $row->CrwsTimezone,
            'shooting_bearing' => $row->CrwsShootingBearing !== null
                ? (float) $row->CrwsShootingBearing
                : null,
            'sensor_height' => $row->CrwsSensorHeight !== null
                ? (float) $row->CrwsSensorHeight
                : null,
            'position_notes' => (string) $row->CrwsPositionNotes,
            'research_status' => resultspack_weather_research_status(
                (string) $row->CrwsResearchStatus
            ),
        );
    }

    return $sessions;
}

//Update the editable setup information for a weather session.
function resultspack_weather_update_session($sessionId, array $values)
{
    resultspack_weather_ensure_sessions_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array(
            'ok' => false,
            'error' => 'Invalid weather session.',
        );
    }

    //Shooting bearing must be between 0 and 359 degrees.
    $bearing = null;

    if (
        isset($values['shooting_bearing'])
        && is_numeric($values['shooting_bearing'])
    ) {
        $candidate = (float) $values['shooting_bearing'];

        if ($candidate >= 0 && $candidate < 360) {
            $bearing = $candidate;
        }
    }

    //Sensor height must be a sensible positive value.
    $height = null;

    if (
        isset($values['sensor_height'])
        && is_numeric($values['sensor_height'])
    ) {
        $candidate = (float) $values['sensor_height'];

        if ($candidate > 0 && $candidate <= 20) {
            $height = $candidate;
        }
    }

    //Tidy and limit the free-text position notes.
    $notes = trim((string) ($values['position_notes'] ?? ''));

    if (function_exists('mb_substr')) {
        $notes = mb_substr($notes, 0, 2000, 'UTF-8');
    } else {
        $notes = substr($notes, 0, 2000);
    }

    //Only allow our three recognised research statuses.
    $status = resultspack_weather_research_status(
        $values['research_status'] ?? 'test'
    );

    //Prepare nullable numbers for the SQL query.
    $bearingSql = $bearing !== null
        ? number_format($bearing, 1, '.', '')
        : 'NULL';

    $heightSql = $height !== null
        ? number_format($height, 2, '.', '')
        : 'NULL';

    //Update only the editable metadata.
    safe_w_sql(
        "UPDATE CustomResultsPackWeatherSessions SET " .
        "CrwsShootingBearing=" . $bearingSql . "," .
        "CrwsSensorHeight=" . $heightSql . "," .
        "CrwsPositionNotes=" . StrSafe_DB($notes) . "," .
        "CrwsResearchStatus=" . StrSafe_DB($status) . " " .
        "WHERE CrwsId=" . $sessionId
    );

    return array(
        'ok' => true,
    );
}

//Return all locally stored observations for one weather session.
function resultspack_weather_get_observations($sessionId)
{
    resultspack_weather_ensure_observations_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    $session = resultspack_weather_get_completed_session($sessionId);

    $where = "CrwoSession=" . $sessionId;

    if ($session) {
        $where .=
            " AND CrwoTimestamp>=" . (int) $session['started_epoch'] .
            " AND CrwoTimestamp<=" . (int) $session['ended_epoch'];
    }

    $result = safe_r_sql(
        "SELECT " .
        "CrwoTimestamp,CrwoReportInterval," .
        "CrwoWindLull,CrwoWindAvg,CrwoWindGust,CrwoWindDir," .
        "CrwoStationPressure,CrwoSeaLevelPressure," .
        "CrwoAirTemp,CrwoRh," .
        "CrwoIlluminance,CrwoUv,CrwoSolarRadiation," .
        "CrwoPrecipAccumulation,CrwoLocalDayPrecipAccumulation," .
        "CrwoPrecipType,CrwoStrikeCount,CrwoStrikeDistance " .
        "FROM CustomResultsPackWeatherObservations " .
        "WHERE " . $where . " " .
        "ORDER BY CrwoTimestamp ASC"
    );

    $observations = array();

    while ($row = safe_fetch($result)) {
        $observations[] = array(
            'timestamp' => (int) $row->CrwoTimestamp,
            'report_interval' => $row->CrwoReportInterval !== null
                ? (int) $row->CrwoReportInterval
                : null,

            'wind_lull' => $row->CrwoWindLull !== null
                ? (float) $row->CrwoWindLull
                : null,

            'wind_avg' => $row->CrwoWindAvg !== null
                ? (float) $row->CrwoWindAvg
                : null,

            'wind_gust' => $row->CrwoWindGust !== null
                ? (float) $row->CrwoWindGust
                : null,

            'wind_dir' => $row->CrwoWindDir !== null
                ? (float) $row->CrwoWindDir
                : null,

            'station_pressure' => $row->CrwoStationPressure !== null
                ? (float) $row->CrwoStationPressure
                : null,

            'sea_level_pressure' => $row->CrwoSeaLevelPressure !== null
                ? (float) $row->CrwoSeaLevelPressure
                : null,

            'air_temp' => $row->CrwoAirTemp !== null
                ? (float) $row->CrwoAirTemp
                : null,

            'humidity' => $row->CrwoRh !== null
                ? (float) $row->CrwoRh
                : null,

            'illuminance' => $row->CrwoIlluminance !== null
                ? (float) $row->CrwoIlluminance
                : null,

            'uv' => $row->CrwoUv !== null
                ? (float) $row->CrwoUv
                : null,

            'solar_radiation' => $row->CrwoSolarRadiation !== null
                ? (float) $row->CrwoSolarRadiation
                : null,

            'precip_accumulation' => $row->CrwoPrecipAccumulation !== null
                ? (float) $row->CrwoPrecipAccumulation
                : null,

            'local_day_precip' => $row->CrwoLocalDayPrecipAccumulation !== null
                ? (float) $row->CrwoLocalDayPrecipAccumulation
                : null,

            'precip_type' => $row->CrwoPrecipType !== null
                ? (int) $row->CrwoPrecipType
                : null,

            'strike_count' => $row->CrwoStrikeCount !== null
                ? (int) $row->CrwoStrikeCount
                : null,

            'strike_distance' => $row->CrwoStrikeDistance !== null
                ? (float) $row->CrwoStrikeDistance
                : null,
        );
    }

    return $observations;
}