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

//Station offset in metres
function resultspack_weather_site_offset($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    $value = (float) $value;

    if ($value < -1000 || $value > 1000) {
        return null;
    }

    return $value;
}

function resultspack_weather_ground_surface($value)
{
    $value = strtolower(trim((string) $value));

    $allowed = array(
        '',
        'grass',
        'artificial_turf',
        'hardstanding',
        'indoor_floor',
        'mixed',
        'other',
    );

    return in_array($value, $allowed, true)
        ? $value
        : '';
}

function resultspack_weather_site_exposure($value)
{
    $value = strtolower(trim((string) $value));

    $allowed = array(
        '',
        'open',
        'partly_sheltered',
        'sheltered',
        'indoor',
        'other',
    );

    return in_array($value, $allowed, true)
        ? $value
        : '';
}

function resultspack_weather_forward_offset_label($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 'Not recorded';
    }

    $value = (float) $value;

    if (abs($value) < 0.005) {
        return 'On shooting line';
    }

    return resultspack_weather_format_number(abs($value), 1)
        . ' m '
        . ($value > 0
            ? 'toward targets'
            : 'behind shooting line');
}

function resultspack_weather_lateral_offset_label($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 'Not recorded';
    }

    $value = (float) $value;

    if (abs($value) < 0.005) {
        return 'On field centre line';
    }

    return resultspack_weather_format_number(abs($value), 1)
        . ' m '
        . ($value > 0
            ? 'right of centre'
            : 'left of centre');
}

//Normalise a compass direction to 0 <= direction < 360.
function resultspack_weather_normalise_direction($degrees)
{
    if ($degrees === null || $degrees === '' || !is_numeric($degrees)) {
        return null;
    }

    $degrees = fmod((float) $degrees, 360.0);

    if ($degrees < 0) {
        $degrees += 360.0;
    }

    return $degrees;
}


//Return the shortest signed correction from one direction to another.
//Example:
// recorded = 56
// verified = 359
// result = -57
function resultspack_weather_direction_difference($recorded, $verified)
{
    $recorded = resultspack_weather_normalise_direction($recorded);
    $verified = resultspack_weather_normalise_direction($verified);

    if ($recorded === null || $verified === null) {
        return null;
    }

    $difference = $verified - $recorded;

    while ($difference > 180) {
        $difference -= 360;
    }

    while ($difference <= -180) {
        $difference += 360;
    }

    return $difference;
}


//Apply correction to direction reference without changing raw data
function resultspack_weather_apply_direction_correction(
    $direction,
    $correction = 0
) {
    $direction = resultspack_weather_normalise_direction($direction);

    if ($direction === null) {
        return null;
    }

    if (!is_numeric($correction)) {
        $correction = 0;
    }

    return resultspack_weather_normalise_direction(
        $direction + (float) $correction
    );
}


//Return the shooting bearing that should be used for interpretation/display
function resultspack_weather_effective_shooting_bearing(array $session)
{
    return resultspack_weather_apply_direction_correction(
        $session['shooting_bearing'] ?? null,
        $session['direction_correction'] ?? 0
    );
}


//Return a Tempest wind direction corrected to the same geographic reference
function resultspack_weather_effective_wind_direction(
    $windDirection,
    array $session
) {
    return resultspack_weather_apply_direction_correction(
        $windDirection,
        $session['direction_correction'] ?? 0
    );
}

//How shooting direction was verified
function resultspack_weather_direction_verification($value)
{
    $value = strtolower(trim((string) $value));

    $allowed = array(
        'unverified',
        'phone_compass',
        'map_satellite',
        'second_compass',
        'known_site_alignment',
        'surveyed_bearing',
        'other',
    );

    return in_array($value, $allowed, true)
        ? $value
        : 'unverified';
}


//Readable label for a direction-verification method
function resultspack_weather_direction_verification_label($value)
{
    $value = resultspack_weather_direction_verification($value);

    $labels = array(
        'unverified' => 'Unverified',
        'phone_compass' => 'Phone compass only',
        'map_satellite' => 'Map / satellite',
        'second_compass' => 'Second compass',
        'known_site_alignment' => 'Known site alignment',
        'surveyed_bearing' => 'Surveyed bearing',
        'other' => 'Other',
    );

    return $labels[$value] ?? 'Unverified';
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
        "CrwsDirectionCorrection decimal(6,2) NOT NULL DEFAULT 0.00," .
        "CrwsDirectionVerification varchar(32) NOT NULL DEFAULT 'unverified'," .
        "CrwsSensorHeight decimal(5,2) DEFAULT NULL," .
        "CrwsForwardOffset decimal(7,2) DEFAULT NULL," .
        "CrwsLateralOffset decimal(7,2) DEFAULT NULL," .
        "CrwsGroundSurface varchar(32) NOT NULL DEFAULT ''," .
        "CrwsExposure varchar(32) NOT NULL DEFAULT ''," .
        "CrwsPositionNotes text NOT NULL," .
        "CrwsResearchStatus varchar(16) NOT NULL DEFAULT 'test'," .
        "CrwsCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwsId)," .
        "KEY CrwsTournament (CrwsTournament)," .
        "KEY CrwsActive (CrwsEndedEpoch)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $siteColumns = array(
        'CrwsDirectionCorrection' =>
            "decimal(6,2) NOT NULL DEFAULT 0.00 AFTER CrwsShootingBearing",

        'CrwsDirectionVerification' =>
            "varchar(32) NOT NULL DEFAULT 'unverified' AFTER CrwsDirectionCorrection",
    
        'CrwsForwardOffset' =>
            "decimal(7,2) DEFAULT NULL AFTER CrwsSensorHeight",

        'CrwsLateralOffset' =>
            "decimal(7,2) DEFAULT NULL AFTER CrwsForwardOffset",

        'CrwsGroundSurface' =>
            "varchar(32) NOT NULL DEFAULT '' AFTER CrwsLateralOffset",

        'CrwsExposure' =>
            "varchar(32) NOT NULL DEFAULT '' AFTER CrwsGroundSurface",
    );

    foreach ($siteColumns as $columnName => $definition) {
        $siteColumnCheck = safe_r_sql(
            "SHOW COLUMNS FROM CustomResultsPackWeatherSessions " .
            "LIKE " . StrSafe_DB($columnName)
        );

        if (!safe_fetch($siteColumnCheck)) {
            safe_w_sql(
                "ALTER TABLE CustomResultsPackWeatherSessions " .
                "ADD COLUMN " . $columnName . " " . $definition
            );
        }
    }

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
        "CrwsShootingBearing,CrwsDirectionCorrection,CrwsDirectionVerification," .
        "CrwsSensorHeight,CrwsForwardOffset,CrwsLateralOffset," .
        "CrwsGroundSurface,CrwsExposure,CrwsPositionNotes,CrwsResearchStatus " .
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
        'shooting_bearing' => $row->CrwsShootingBearing !== null
            ? (float) $row->CrwsShootingBearing
            : null,
        'direction_correction' => $row->CrwsDirectionCorrection !== null
            ? (float) $row->CrwsDirectionCorrection
            : 0.0,
        'direction_verification' =>
            (string) $row->CrwsDirectionVerification,
        'sensor_height' => $row->CrwsSensorHeight !== null
            ? (float) $row->CrwsSensorHeight
            : null,
        'forward_offset' => $row->CrwsForwardOffset !== null
            ? (float) $row->CrwsForwardOffset
            : null,
        'lateral_offset' => $row->CrwsLateralOffset !== null
            ? (float) $row->CrwsLateralOffset
            : null,
        'ground_surface' => (string) $row->CrwsGroundSurface,
        'exposure' => (string) $row->CrwsExposure,
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

    $forwardOffset = resultspack_weather_site_offset(
        $values['forward_offset'] ?? null
    );

    $lateralOffset = resultspack_weather_site_offset(
        $values['lateral_offset'] ?? null
    );

    $groundSurface = resultspack_weather_ground_surface(
        $values['ground_surface'] ?? ''
    );

    $exposure = resultspack_weather_site_exposure(
        $values['exposure'] ?? ''
    );

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
    $forwardOffsetSql =
    resultspack_weather_sql_number($forwardOffset);

$lateralOffsetSql =
    resultspack_weather_sql_number($lateralOffset);

    safe_w_sql(
        "INSERT INTO CustomResultsPackWeatherSessions (" .
        "CrwsTournament,CrwsStationId,CrwsDeviceId,CrwsStationName," .
        "CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight," .
        "CrwsForwardOffset,CrwsLateralOffset," .
        "CrwsGroundSurface,CrwsExposure,CrwsPositionNotes," .
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
        $forwardOffsetSql . "," .
        $lateralOffsetSql . "," .
        StrSafe_DB($groundSurface) . "," .
        StrSafe_DB($exposure) . "," .
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
        "CrwsShootingBearing,CrwsDirectionCorrection,CrwsDirectionVerification," .
        "CrwsSensorHeight,CrwsForwardOffset,CrwsLateralOffset," .
        "CrwsGroundSurface,CrwsExposure," .
        "CrwsPositionNotes,CrwsResearchStatus " .
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
        'direction_correction' => $row->CrwsDirectionCorrection !== null
            ? (float) $row->CrwsDirectionCorrection
            : 0.0,
        'direction_verification' =>
            (string) $row->CrwsDirectionVerification,
        'sensor_height' => $row->CrwsSensorHeight !== null
            ? (float) $row->CrwsSensorHeight
            : null,
        'forward_offset' => $row->CrwsForwardOffset !== null
            ? (float) $row->CrwsForwardOffset
            : null,
        'lateral_offset' => $row->CrwsLateralOffset !== null
            ? (float) $row->CrwsLateralOffset
            : null,
        'ground_surface' => (string) $row->CrwsGroundSurface,
        'exposure' => (string) $row->CrwsExposure,
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
        "CrwsShootingBearing,CrwsDirectionCorrection,CrwsDirectionVerification," .
        "CrwsSensorHeight,CrwsForwardOffset,CrwsLateralOffset," .
        "CrwsGroundSurface,CrwsExposure," .
        "CrwsPositionNotes,CrwsResearchStatus " .
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
            'direction_correction' => $row->CrwsDirectionCorrection !== null
                ? (float) $row->CrwsDirectionCorrection
                : 0.0,
            'direction_verification' =>
                (string) $row->CrwsDirectionVerification,
            'sensor_height' => $row->CrwsSensorHeight !== null
                ? (float) $row->CrwsSensorHeight
                : null,
            'forward_offset' => $row->CrwsForwardOffset !== null
                ? (float) $row->CrwsForwardOffset
                : null,
            'lateral_offset' => $row->CrwsLateralOffset !== null
                ? (float) $row->CrwsLateralOffset
                : null,
            'ground_surface' => (string) $row->CrwsGroundSurface,
            'exposure' => (string) $row->CrwsExposure,
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

//Create the direction reference correction audit table when first needed.
function resultspack_weather_ensure_direction_corrections_table()
{
    static $done = false;

    if ($done) {
        return;
    }

    safe_w_sql(
        "CREATE TABLE IF NOT EXISTS CustomResultsPackWeatherDirectionCorrections (" .
        "CrwdcId int unsigned NOT NULL AUTO_INCREMENT," .
        "CrwdcSession int unsigned NOT NULL," .
        "CrwdcOldCorrection decimal(6,2) NOT NULL DEFAULT 0.00," .
        "CrwdcNewCorrection decimal(6,2) NOT NULL DEFAULT 0.00," .
        "CrwdcRecordedBearing decimal(6,2) DEFAULT NULL," .
        "CrwdcVerifiedBearing decimal(6,2) NOT NULL," .
        "CrwdcVerification varchar(32) NOT NULL DEFAULT 'other'," .
        "CrwdcReason text NOT NULL," .
        "CrwdcCreated datetime NOT NULL," .
        "PRIMARY KEY (CrwdcId)," .
        "KEY CrwdcSession (CrwdcSession)," .
        "KEY CrwdcCreated (CrwdcCreated)" .
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

//Correct a completed session's direction reference while preserving raw bearings.
function resultspack_weather_update_direction_reference(
    $sessionId,
    $verifiedBearing,
    $verification,
    $reason
) {
    resultspack_weather_ensure_sessions_table();
    resultspack_weather_ensure_direction_corrections_table();

    $sessionId = (int) $sessionId;

    $session =
        resultspack_weather_get_completed_session($sessionId);

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'Completed weather session not found.',
        );
    }

    $recordedBearing =
        $session['shooting_bearing'] ?? null;

    if (
        $recordedBearing === null
        || !is_numeric($recordedBearing)
    ) {
        return array(
            'ok' => false,
            'error' =>
                'This session has no recorded shooting bearing to correct.',
        );
    }

    if (!is_numeric($verifiedBearing)) {
        return array(
            'ok' => false,
            'error' =>
                'Please enter a valid verified shooting bearing.',
        );
    }

    $verifiedBearing = (float) $verifiedBearing;

    if (
        $verifiedBearing < 0
        || $verifiedBearing >= 360
    ) {
        return array(
            'ok' => false,
            'error' =>
                'Verified shooting bearing must be between 0 and 359.99 degrees.',
        );
    }

    $verification =
        resultspack_weather_direction_verification(
            $verification
        );

    if (
        $verification === 'unverified'
        || $verification === 'phone_compass'
    ) {
        return array(
            'ok' => false,
            'error' =>
                'Please choose an independent verification method.',
        );
    }

    $reason = trim((string) $reason);

    if ($reason === '') {
        return array(
            'ok' => false,
            'error' =>
                'Please record why the direction reference is being corrected.',
        );
    }

    if (function_exists('mb_substr')) {
        $reason =
            mb_substr($reason, 0, 2000, 'UTF-8');
    } else {
        $reason =
            substr($reason, 0, 2000);
    }

    $oldCorrection =
        isset($session['direction_correction'])
        && is_numeric($session['direction_correction'])
            ? (float) $session['direction_correction']
            : 0.0;

    $newCorrection =
        resultspack_weather_direction_difference(
            $recordedBearing,
            $verifiedBearing
        );

    if ($newCorrection === null) {
        return array(
            'ok' => false,
            'error' =>
                'The direction correction could not be calculated.',
        );
    }

    //Audit the correction before changing the session.
    safe_w_sql(
        "INSERT INTO CustomResultsPackWeatherDirectionCorrections (" .
        "CrwdcSession,CrwdcOldCorrection,CrwdcNewCorrection," .
        "CrwdcRecordedBearing,CrwdcVerifiedBearing," .
        "CrwdcVerification,CrwdcReason,CrwdcCreated" .
        ") VALUES (" .
        $sessionId . "," .
        resultspack_weather_sql_number($oldCorrection) . "," .
        resultspack_weather_sql_number($newCorrection) . "," .
        resultspack_weather_sql_number($recordedBearing) . "," .
        resultspack_weather_sql_number($verifiedBearing) . "," .
        StrSafe_DB($verification) . "," .
        StrSafe_DB($reason) . "," .
        "NOW())"
    );

    safe_w_sql(
        "UPDATE CustomResultsPackWeatherSessions SET " .
        "CrwsDirectionCorrection=" .
        resultspack_weather_sql_number($newCorrection) . "," .
        "CrwsDirectionVerification=" .
        StrSafe_DB($verification) . " " .
        "WHERE CrwsId=" . $sessionId . " " .
        "AND CrwsEndedEpoch IS NOT NULL"
    );

    return array(
        'ok' => true,
        'changed' => true,
        'session_id' => $sessionId,
        'recorded_bearing' =>
            (float) $recordedBearing,
        'verified_bearing' =>
            (float) $verifiedBearing,
        'old_correction' =>
            (float) $oldCorrection,
        'new_correction' =>
            (float) $newCorrection,
        'verification' =>
            $verification,
    );
}

//Return direction-reference corrections for one session, newest first.
function resultspack_weather_get_direction_corrections($sessionId)
{
    resultspack_weather_ensure_direction_corrections_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array();
    }

    $result = safe_r_sql(
        "SELECT CrwdcId,CrwdcSession," .
        "CrwdcOldCorrection,CrwdcNewCorrection," .
        "CrwdcRecordedBearing,CrwdcVerifiedBearing," .
        "CrwdcVerification,CrwdcReason,CrwdcCreated " .
        "FROM CustomResultsPackWeatherDirectionCorrections " .
        "WHERE CrwdcSession=" . $sessionId . " " .
        "ORDER BY CrwdcId DESC"
    );

    $corrections = array();

    while ($row = safe_fetch($result)) {
        $corrections[] = array(
            'id' => (int) $row->CrwdcId,
            'session_id' => (int) $row->CrwdcSession,

            'old_correction' =>
                (float) $row->CrwdcOldCorrection,

            'new_correction' =>
                (float) $row->CrwdcNewCorrection,

            'recorded_bearing' =>
                $row->CrwdcRecordedBearing !== null
                    ? (float) $row->CrwdcRecordedBearing
                    : null,

            'verified_bearing' =>
                (float) $row->CrwdcVerifiedBearing,

            'verification' =>
                (string) $row->CrwdcVerification,

            'reason' =>
                (string) $row->CrwdcReason,

            'created' =>
                (string) $row->CrwdcCreated,
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
        "CrwsShootingBearing,CrwsDirectionCorrection,CrwsDirectionVerification," .
        "CrwsSensorHeight,CrwsForwardOffset,CrwsLateralOffset," .
        "CrwsGroundSurface,CrwsExposure," .
        "CrwsPositionNotes,CrwsResearchStatus " .
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
            'direction_correction' => $row->CrwsDirectionCorrection !== null
                ? (float) $row->CrwsDirectionCorrection
                : 0.0,
            'direction_verification' =>
                (string) $row->CrwsDirectionVerification,
            'sensor_height' => $row->CrwsSensorHeight !== null
                ? (float) $row->CrwsSensorHeight
                : null,
            'forward_offset' => $row->CrwsForwardOffset !== null
                ? (float) $row->CrwsForwardOffset
                : null,
            'lateral_offset' => $row->CrwsLateralOffset !== null
                ? (float) $row->CrwsLateralOffset
                : null,
            'ground_surface' => (string) $row->CrwsGroundSurface,
            'exposure' => (string) $row->CrwsExposure,
            'position_notes' => (string) $row->CrwsPositionNotes,
            'research_status' => resultspack_weather_research_status(
                (string) $row->CrwsResearchStatus
            ),
        );
    }

    return $sessions;
}

//Find one weather session by ID, whether active or completed.
function resultspack_weather_get_session($sessionId)
{
    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return null;
    }

    foreach (resultspack_weather_get_sessions() as $session) {
        if ((int) $session['id'] === $sessionId) {
            return $session;
        }
    }

    return null;
}

function resultspack_weather_delete_preview($sessionId)
{
    resultspack_weather_ensure_sessions_table();
    resultspack_weather_ensure_observations_table();
    resultspack_weather_ensure_events_table();
    resultspack_weather_ensure_timing_corrections_table();
    resultspack_weather_ensure_direction_corrections_table();

    $sessionId = (int) $sessionId;
    $session = resultspack_weather_get_session($sessionId);

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'Weather session not found.',
        );
    }

    $observationResult = safe_r_sql(
        "SELECT COUNT(*) AS RowCount " .
        "FROM CustomResultsPackWeatherObservations " .
        "WHERE CrwoSession=" . $sessionId
    );

    $eventResult = safe_r_sql(
        "SELECT COUNT(*) AS RowCount " .
        "FROM CustomResultsPackWeatherEvents " .
        "WHERE CrweSession=" . $sessionId
    );

    $correctionResult = safe_r_sql(
        "SELECT COUNT(*) AS RowCount " .
        "FROM CustomResultsPackWeatherTimingCorrections " .
        "WHERE CrwtcSession=" . $sessionId
    );

    $directionCorrectionResult = safe_r_sql(
        "SELECT COUNT(*) AS RowCount " .
        "FROM CustomResultsPackWeatherDirectionCorrections " .
        "WHERE CrwdcSession=" . $sessionId
    );

    $observationRow = safe_fetch($observationResult);
    $eventRow = safe_fetch($eventResult);
    $correctionRow = safe_fetch($correctionResult);
    $directionCorrectionRow = safe_fetch($directionCorrectionResult);

    return array(
        'ok' => true,
        'session' => $session,
        'observations' => $observationRow
            ? (int) $observationRow->RowCount
            : 0,
        'events' => $eventRow
            ? (int) $eventRow->RowCount
            : 0,
        'timing_corrections' => $correctionRow
            ? (int) $correctionRow->RowCount
            : 0,
        'direction_corrections' => $directionCorrectionRow
            ? (int) $directionCorrectionRow->RowCount
            : 0,
    );
}

function resultspack_weather_delete_test_session($sessionId)
{
    resultspack_weather_ensure_sessions_table();
    resultspack_weather_ensure_observations_table();
    resultspack_weather_ensure_events_table();
    resultspack_weather_ensure_timing_corrections_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return array(
            'ok' => false,
            'error' => 'Invalid weather session.',
        );
    }

    safe_w_sql('START TRANSACTION');

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsResearchStatus,CrwsEndedEpoch " .
        "FROM CustomResultsPackWeatherSessions " .
        "WHERE CrwsId=" . $sessionId . " " .
        "FOR UPDATE"
    );

    $row = safe_fetch($result);

    if (!$row) {
        safe_w_sql('ROLLBACK');

        return array(
            'ok' => false,
            'error' => 'Weather session not found.',
        );
    }

    $status = resultspack_weather_research_status(
        (string) $row->CrwsResearchStatus
    );

    if ($status !== 'test') {
        safe_w_sql('ROLLBACK');

        return array(
            'ok' => false,
            'error' => 'Only Test weather sessions may be deleted.',
        );
    }

    if ($row->CrwsEndedEpoch === null) {
        safe_w_sql('ROLLBACK');

        return array(
            'ok' => false,
            'error' => 'An active weather session cannot be deleted. Stop it first.',
        );
    }

    $preview = resultspack_weather_delete_preview($sessionId);

    resultspack_weather_ensure_direction_corrections_table();

    safe_w_sql(
        "DELETE FROM CustomResultsPackWeatherDirectionCorrections " .
        "WHERE CrwdcSession=" . $sessionId
    );

    safe_w_sql(
        "DELETE FROM CustomResultsPackWeatherTimingCorrections " .
        "WHERE CrwtcSession=" . $sessionId
    );

    safe_w_sql(
        "DELETE FROM CustomResultsPackWeatherEvents " .
        "WHERE CrweSession=" . $sessionId
    );

    safe_w_sql(
        "DELETE FROM CustomResultsPackWeatherObservations " .
        "WHERE CrwoSession=" . $sessionId
    );

    safe_w_sql(
        "DELETE FROM CustomResultsPackWeatherSessions " .
        "WHERE CrwsId=" . $sessionId . " " .
        "AND CrwsResearchStatus='test' " .
        "AND CrwsEndedEpoch IS NOT NULL"
    );

    safe_w_sql('COMMIT');

    return array(
        'ok' => true,
        'session_id' => $sessionId,
        'observations_deleted' =>
            $preview['observations'] ?? 0,
        'events_deleted' =>
            $preview['events'] ?? 0,
        'timing_corrections_deleted' =>
            $preview['timing_corrections'] ?? 0,
        'direction_corrections_deleted' =>
            $preview['direction_corrections'] ?? 0,
            );
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

    $forwardOffset = resultspack_weather_site_offset(
        $values['forward_offset'] ?? null
    );

    $lateralOffset = resultspack_weather_site_offset(
        $values['lateral_offset'] ?? null
    );

    $groundSurface = resultspack_weather_ground_surface(
        $values['ground_surface'] ?? ''
    );

    $exposure = resultspack_weather_site_exposure(
        $values['exposure'] ?? ''
    );

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

        $forwardOffsetSql =
            resultspack_weather_sql_number($forwardOffset);

        $lateralOffsetSql =
            resultspack_weather_sql_number($lateralOffset);

    //Update only the editable metadata.
    safe_w_sql(
        "UPDATE CustomResultsPackWeatherSessions SET " .
        "CrwsShootingBearing=" . $bearingSql . "," .
        "CrwsSensorHeight=" . $heightSql . "," .
        "CrwsForwardOffset=" . $forwardOffsetSql . "," .
        "CrwsLateralOffset=" . $lateralOffsetSql . "," .
        "CrwsGroundSurface=" . StrSafe_DB($groundSurface) . "," .
        "CrwsExposure=" . StrSafe_DB($exposure) . "," .
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

//Summarise a list of numeric weather values.
function resultspack_weather_numeric_summary(array $values)
{
    $numbers = array();

    foreach ($values as $value) {
        if ($value !== null && is_numeric($value)) {
            $numbers[] = (float) $value;
        }
    }

    if (!$numbers) {
        return null;
    }

    return array(
        'count' => count($numbers),
        'average' => array_sum($numbers) / count($numbers),
        'min' => min($numbers),
        'max' => max($numbers),
    );
}

//Calculate circular mean of compass bearings since average does not work for directions; for example, the average of 359° and 1° should be 0°, not 180°.
function resultspack_weather_circular_mean(array $directions)
{
    $x = 0.0;
    $y = 0.0;
    $count = 0;

    foreach ($directions as $direction) {
        if ($direction === null || !is_numeric($direction)) {
            continue;
        }

        $radians = deg2rad((float) $direction);

        $x += cos($radians);
        $y += sin($radians);
        $count++;
    }

    if ($count === 0) {
        return null;
    }

    //If directions cancel one another there is no single prevailing direction
    if (abs($x) < 0.000001 && abs($y) < 0.000001) {
        return null;
    }

    $degrees = rad2deg(atan2($y, $x));

    if ($degrees < 0) {
        $degrees += 360;
    }

    return $degrees;
}

//Environmental summary for one tournament. Looking for all REAL sessions within the date range to account for multi-day tournaments and the possibility of service interruption.
function resultspack_weather_results_summary($tournamentId)
{
    $tournamentId = (int) $tournamentId;

    if ($tournamentId <= 0) {
        return array(
            'ok' => false,
            'error' => 'Invalid tournament ID.',
        );
    }

    $sessions = array();

    foreach (resultspack_weather_get_completed_sessions() as $session) {
        if (
            (int) $session['tournament_id'] === $tournamentId
            && $session['research_status'] === 'real'
        ) {
            $sessions[] = $session;
        }
    }

    if (!$sessions) {
        return array(
            'ok' => false,
            'error' => 'No completed real environmental session was found for this tournament.',
        );
    }

    $temperatures = array();
    $humidities = array();
    $windAverages = array();
    $windGusts = array();
    $windDirections = array();

    $relativeWindCounts = array();

    $precipitationTotal = 0.0;
    $hasPrecipitationData = false;

    $observationCount = 0;
    $expectedCount = 0;
    $receivedCount = 0;

    $sessionIds = array();

    foreach ($sessions as $session) {
        $sessionIds[] = (int) $session['id'];

        $quality = resultspack_weather_session_quality($session['id']);

        if ($quality) {
            $expectedCount += (int) $quality['expected'];
            $receivedCount += (int) $quality['received'];
        }

        $observations = resultspack_weather_get_observations($session['id']);

        foreach ($observations as $observation) {
            $observationCount++;

            if ($observation['air_temp'] !== null) {
                $temperatures[] = $observation['air_temp'];
            }

            if ($observation['humidity'] !== null) {
                $humidities[] = $observation['humidity'];
            }

            if ($observation['wind_avg'] !== null) {
                $windAverages[] = $observation['wind_avg'];
            }

            if ($observation['wind_gust'] !== null) {
                $windGusts[] = $observation['wind_gust'];
            }

            if ($observation['wind_dir'] !== null) {
                $windDirections[] = $observation['wind_dir'];
            }

            if ($observation['precip_accumulation'] !== null) {
                $precipitationTotal += (float) $observation['precip_accumulation'];
                $hasPrecipitationData = true;
            }

            if (
                $observation['wind_dir'] !== null
                && $session['shooting_bearing'] !== null
            ) {
                $relativeWind = resultspack_weather_relative_wind(
                    $observation['wind_dir'],
                    $session['shooting_bearing']
                );

                if ($relativeWind && !empty($relativeWind['label'])) {
                    $label = $relativeWind['label'];

                    if (!isset($relativeWindCounts[$label])) {
                        $relativeWindCounts[$label] = 0;
                    }

                    $relativeWindCounts[$label]++;
                }
            }
        }
    }

    if ($observationCount === 0) {
        return array(
            'ok' => false,
            'error' => 'The cenvironmental session contains no observations.',
        );
    }

    $temperature = resultspack_weather_numeric_summary($temperatures);
    $humidity = resultspack_weather_numeric_summary($humidities);
    $windAverage = resultspack_weather_numeric_summary($windAverages);
    $windGust = resultspack_weather_numeric_summary($windGusts);

    //Tempest observations are stored in mph; convert to km/h for ResultsPack presentation without altering the raw data.
    $windAverageKmh = $windAverage
        ? $windAverage['average'] * 1.609344
        : null;

    $windGustMaxKmh = $windGust
        ? $windGust['max'] * 1.609344
        : null;

    $prevailingDirection = resultspack_weather_circular_mean($windDirections);

    $dominantRelativeWind = null;
    $dominantRelativeWindCount = 0;

    if ($relativeWindCounts) {
        arsort($relativeWindCounts);

        $dominantRelativeWind = array_key_first($relativeWindCounts);
        $dominantRelativeWindCount = $relativeWindCounts[$dominantRelativeWind];
    }

    $coveragePercent = null;

    if ($expectedCount > 0) {
        $coveragePercent = ($receivedCount / $expectedCount) * 100;
    }

    //One-sentence summary for resultspack
    $summaryParts = array();

    if ($temperature) {
        $summaryParts[] =
            number_format($temperature['average'], 1) .
            ' °C average (' .
            number_format($temperature['min'], 1) .
            '–' .
            number_format($temperature['max'], 1) .
            ' °C)';
    }

    if ($windAverageKmh !== null) {
        $windText =
            'wind ' .
            number_format($windAverageKmh, 1) .
            ' km/h average';

        if ($windGustMaxKmh !== null) {
            $windText .=
                ', gusting to ' .
                number_format($windGustMaxKmh, 1) .
                ' km/h';
        }

        $summaryParts[] = $windText;
    }

    if ($dominantRelativeWind !== null) {
        $summaryParts[] = 'predominantly ' . strtolower($dominantRelativeWind);
    } elseif ($prevailingDirection !== null) {
        $summaryParts[] =
            'prevailing ' .
            resultspack_weather_compass_direction($prevailingDirection) .
            ' (' .
            number_format($prevailingDirection, 0) .
            '°)';
    }

    if ($hasPrecipitationData) {
        if ($precipitationTotal <= 0) {
            $summaryParts[] = 'dry';
        } else {
            $summaryParts[] =
                number_format($precipitationTotal, 1) .
                ' mm precipitation recorded';
        }
    }

    $summary = implode('; ', $summaryParts);

    if ($summary !== '') {
        $summary .= '.';
    }

    return array(
        'ok' => true,

        'session_ids' => $sessionIds,
        'session_count' => count($sessions),

        'observation_count' => $observationCount,
        'expected_observations' => $expectedCount,
        'received_observations' => $receivedCount,
        'coverage_percent' => $coveragePercent,

        'temperature_average' => $temperature
            ? round($temperature['average'], 1)
            : null,
        'temperature_min' => $temperature
            ? round($temperature['min'], 1)
            : null,
        'temperature_max' => $temperature
            ? round($temperature['max'], 1)
            : null,

        'humidity_average' => $humidity
            ? round($humidity['average'], 1)
            : null,

        'wind_average' => $windAverage
            ? round($windAverage['average'], 1)
            : null,
        'wind_gust_max' => $windGust
            ? round($windGust['max'], 1)
            : null,

            'wind_average_kmh' => $windAverageKmh !== null
                ? round($windAverageKmh, 1)
                : null,

            'wind_gust_max_kmh' => $windGustMaxKmh !== null
                ? round($windGustMaxKmh, 1)
                : null,

        'prevailing_direction' => $prevailingDirection !== null
            ? round($prevailingDirection, 1)
            : null,
        'prevailing_compass' => $prevailingDirection !== null
            ? resultspack_weather_compass_direction($prevailingDirection)
            : null,

        'dominant_relative_wind' => $dominantRelativeWind,
        'dominant_relative_wind_count' => $dominantRelativeWindCount,

        'precipitation_total' => $hasPrecipitationData
            ? round($precipitationTotal, 2)
            : null,

        'summary' => $summary,
    );
}

//Weather session transfer helpers
function resultspack_weather_transfer_format_version()
{
    return 1;
}

//Return one session record
function resultspack_weather_transfer_session_record($sessionId)
{
    resultspack_weather_ensure_sessions_table();

    $sessionId = (int) $sessionId;

    if ($sessionId <= 0) {
        return null;
    }

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsTournament,CrwsStationId,CrwsDeviceId," .
        "CrwsStationName,CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
        "CrwsShootingBearing,CrwsSensorHeight,CrwsDirectionCorrection,CrwsDirectionVerification,CrwsPositionNotes," .
        "CrwsResearchStatus,CrwsCreated " .
        "FROM CustomResultsPackWeatherSessions " .
        "WHERE CrwsId=" . $sessionId . " " .
        "AND CrwsEndedEpoch IS NOT NULL " .
        "LIMIT 1"
    );

    $row = safe_fetch($result);

    if (!$row) {
        return null;
    }

    return array(
        'source_session_id' => (int) $row->CrwsId,
        'source_tournament_id' => (int) $row->CrwsTournament,
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
        'direction_correction' => $row->CrwsDirectionCorrection !== null
            ? (float) $row->CrwsDirectionCorrection
            : 0.0,

        'direction_verification' =>
            (string) $row->CrwsDirectionVerification,
        'sensor_height' => $row->CrwsSensorHeight !== null
            ? (float) $row->CrwsSensorHeight
            : null,
        'forward_offset' => $row->CrwsForwardOffset !== null
            ? (float) $row->CrwsForwardOffset
            : null,
        'lateral_offset' => $row->CrwsLateralOffset !== null
            ? (float) $row->CrwsLateralOffset
            : null,
        'ground_surface' => (string) $row->CrwsGroundSurface,
        'exposure' => (string) $row->CrwsExposure,            
        'position_notes' => (string) $row->CrwsPositionNotes,
        'research_status' => resultspack_weather_research_status(
            (string) $row->CrwsResearchStatus
        ),
        'created' => (string) $row->CrwsCreated,
    );
}

//Return tournament metadata
function resultspack_weather_transfer_tournament_record($tournamentId)
{
    $tournamentId = (int) $tournamentId;

    foreach (resultspack_fetch_tournament_list() as $tournament) {
        if ((int) $tournament['id'] !== $tournamentId) {
            continue;
        }

        return array(
            'source_tournament_id' => $tournamentId,
            'code' => (string) ($tournament['code'] ?? ''),
            'name' => (string) ($tournament['name'] ?? ''),
            'short_name' => (string) ($tournament['short_name'] ?? ''),
            'venue' => (string) ($tournament['venue'] ?? ''),
            'where' => (string) ($tournament['where'] ?? ''),
            'date_from' => (string) ($tournament['date_from'] ?? ''),
            'date_to' => (string) ($tournament['date_to'] ?? ''),
            'type_name' => (string) ($tournament['type_name'] ?? ''),
        );
    }

    return array(
        'source_tournament_id' => $tournamentId,
        'code' => '',
        'name' => '',
        'short_name' => '',
        'venue' => '',
        'where' => '',
        'date_from' => '',
        'date_to' => '',
        'type_name' => '',
    );
}

//Return observations for transfer
function resultspack_weather_transfer_observations($sessionId)
{
    resultspack_weather_ensure_observations_table();

    $sessionId = (int) $sessionId;

    $result = safe_r_sql(
        "SELECT CrwoTimestamp,CrwoReportInterval," .
        "CrwoWindLull,CrwoWindAvg,CrwoWindGust,CrwoWindDir," .
        "CrwoStationPressure,CrwoSeaLevelPressure,CrwoAirTemp,CrwoRh," .
        "CrwoIlluminance,CrwoUv,CrwoSolarRadiation," .
        "CrwoPrecipAccumulation,CrwoLocalDayPrecipAccumulation," .
        "CrwoPrecipType,CrwoStrikeCount,CrwoStrikeDistance,CrwoImported " .
        "FROM CustomResultsPackWeatherObservations " .
        "WHERE CrwoSession=" . $sessionId . " " .
        "ORDER BY CrwoTimestamp ASC"
    );

    $rows = array();

    while ($row = safe_fetch($result)) {
        $rows[] = array(
            'timestamp' => (int) $row->CrwoTimestamp,
            'report_interval' => $row->CrwoReportInterval !== null
                ? (int) $row->CrwoReportInterval
                : null,
            'wind_lull' => $row->CrwoWindLull !== null ? (float) $row->CrwoWindLull : null,
            'wind_avg' => $row->CrwoWindAvg !== null ? (float) $row->CrwoWindAvg : null,
            'wind_gust' => $row->CrwoWindGust !== null ? (float) $row->CrwoWindGust : null,
            'wind_dir' => $row->CrwoWindDir !== null ? (float) $row->CrwoWindDir : null,
            'station_pressure' => $row->CrwoStationPressure !== null ? (float) $row->CrwoStationPressure : null,
            'sea_level_pressure' => $row->CrwoSeaLevelPressure !== null ? (float) $row->CrwoSeaLevelPressure : null,
            'air_temp' => $row->CrwoAirTemp !== null ? (float) $row->CrwoAirTemp : null,
            'humidity' => $row->CrwoRh !== null ? (float) $row->CrwoRh : null,
            'illuminance' => $row->CrwoIlluminance !== null ? (float) $row->CrwoIlluminance : null,
            'uv' => $row->CrwoUv !== null ? (float) $row->CrwoUv : null,
            'solar_radiation' => $row->CrwoSolarRadiation !== null ? (float) $row->CrwoSolarRadiation : null,
            'precip_accumulation' => $row->CrwoPrecipAccumulation !== null ? (float) $row->CrwoPrecipAccumulation : null,
            'local_day_precip' => $row->CrwoLocalDayPrecipAccumulation !== null ? (float) $row->CrwoLocalDayPrecipAccumulation : null,
            'precip_type' => $row->CrwoPrecipType !== null ? (int) $row->CrwoPrecipType : null,
            'strike_count' => $row->CrwoStrikeCount !== null ? (int) $row->CrwoStrikeCount : null,
            'strike_distance' => $row->CrwoStrikeDistance !== null ? (float) $row->CrwoStrikeDistance : null,
            'imported' => (string) $row->CrwoImported,
        );
    }

    return $rows;
}

//Count number of stored observations
function resultspack_weather_transfer_observation_count($sessionId)
{
    resultspack_weather_ensure_observations_table();

    $result = safe_r_sql(
        "SELECT COUNT(*) AS ObservationCount " .
        "FROM CustomResultsPackWeatherObservations " .
        "WHERE CrwoSession=" . (int) $sessionId
    );

    $row = safe_fetch($result);

    return $row ? (int) $row->ObservationCount : 0;
}

//Return event rows
function resultspack_weather_transfer_events($sessionId)
{
    resultspack_weather_ensure_events_table();

    $sessionId = (int) $sessionId;

    $result = safe_r_sql(
        "SELECT CrweId,CrweTimestamp,CrweAction,CrweReason,CrweNote,CrweCreated " .
        "FROM CustomResultsPackWeatherEvents " .
        "WHERE CrweSession=" . $sessionId . " " .
        "ORDER BY CrweTimestamp ASC,CrweId ASC"
    );

    $events = array();

    while ($row = safe_fetch($result)) {
        $events[] = array(
            'source_event_id' => (int) $row->CrweId,
            'timestamp' => (int) $row->CrweTimestamp,
            'action' => (string) $row->CrweAction,
            'reason' => (string) $row->CrweReason,
            'note' => (string) $row->CrweNote,
            'created' => (string) $row->CrweCreated,
        );
    }

    return $events;
}

//Create an ID for a completed session independent of local DB IDs
function resultspack_weather_transfer_fingerprint(array $session)
{
    return hash(
        'sha256',
        implode('|', array(
            (string) ($session['station_id'] ?? ''),
            (string) ($session['device_id'] ?? ''),
            (string) ($session['started_epoch'] ?? ''),
            (string) ($session['ended_epoch'] ?? ''),
            (string) ($session['timezone'] ?? ''),
        ))
    );
}

//Build complete transfer package for one session
function resultspack_weather_build_transfer_package($sessionId)
{
    $session = resultspack_weather_transfer_session_record($sessionId);

    if (!$session) {
        return array(
            'ok' => false,
            'error' => 'Completed weather session not found.',
        );
    }

    $tournament = resultspack_weather_transfer_tournament_record(
        $session['source_tournament_id']
    );

    $corrections = resultspack_weather_get_timing_corrections($sessionId);

    //Don't import local ID as DB ID
    foreach ($corrections as &$correction) {
        unset($correction['id'], $correction['session_id']);
    }
    unset($correction);

    $directionCorrections =
        resultspack_weather_get_direction_corrections(
            $sessionId
        );

    //Don't export local database IDs.
    foreach ($directionCorrections as &$directionCorrection) {
        unset(
            $directionCorrection['id'],
            $directionCorrection['session_id']
        );
    }
    unset($directionCorrection);

    $package = array(
        'format' => 'resultspack-weather-session',
        'format_version' => resultspack_weather_transfer_format_version(),
        'exported_at_utc' => gmdate('c'),
        'session_fingerprint' => resultspack_weather_transfer_fingerprint($session),
        'tournament' => $tournament,
        'session' => $session,
        'quality' => resultspack_weather_session_quality($sessionId),
        'observations' => resultspack_weather_transfer_observations($sessionId),
        'events' => resultspack_weather_transfer_events($sessionId),
        'timing_corrections' => $corrections,
        'direction_corrections' => $directionCorrections,
    );

    return array(
        'ok' => true,
        'package' => $package,
    );
}

//Date comes before competition code so a backup folder sorts chronologically
function resultspack_weather_transfer_filename(array $package, $extension = 'json')
{
    $tournament = $package['tournament'] ?? array();
    $session = $package['session'] ?? array();

    $label = trim((string) ($tournament['code'] ?? ''));

    if ($label === '') {
        $label = trim((string) ($tournament['name'] ?? 'WeatherSession'));
    }

    $label = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label);
    $label = trim((string) $label, '-_.');

    if ($label === '') {
        $label = 'WeatherSession';
    }

    $date = 'unknown-date';

    if (!empty($session['started_epoch'])) {
        $date = resultspack_weather_format_timestamp(
            (int) $session['started_epoch'],
            $session['timezone'] ?? 'UTC',
            'Y-m-d'
        );
    }

    $extension = strtolower(trim((string) $extension));

    if (!in_array($extension, array('json', 'csv'), true)) {
        $extension = 'json';
    }

    // Make filename start with year for sorting
    if (preg_match('/^(\d{2})(.+)$/', $label, $matches)) {
        $label = $matches[1] . '-' . $matches[2];
    }

    return 'ResultsPack-Weather-' . $date . '-' . $label . '.' . $extension;
}

function resultspack_weather_csv_text($value)
{
    $value = (string) $value;

    if ($value !== '' && preg_match('/^[=+@-]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

//Make CSV
function resultspack_weather_transfer_csv(array $package)
{
    $tournament = $package['tournament'] ?? array();
    $session = $package['session'] ?? array();
    $quality = $package['quality'] ?? array();
    $observations = $package['observations'] ?? array();

    $directionCorrection =
        isset($session['direction_correction'])
        && is_numeric($session['direction_correction'])
            ? (float) $session['direction_correction']
            : 0.0;

    $effectiveShootingBearing =
        resultspack_weather_effective_shooting_bearing(
            $session
        );

    $timezone = trim((string) ($session['timezone'] ?? 'UTC'));

    if ($timezone === '') {
        $timezone = 'UTC';
    }

    $startedLocal = !empty($session['started_epoch'])
        ? resultspack_weather_format_timestamp(
            (int) $session['started_epoch'],
            $timezone,
            'Y-m-d H:i:s T'
        )
        : '';

    $endedLocal = !empty($session['ended_epoch'])
        ? resultspack_weather_format_timestamp(
            (int) $session['ended_epoch'],
            $timezone,
            'Y-m-d H:i:s T'
        )
        : '';

    $handle = fopen('php://temp', 'w+');

    if ($handle === false) {
        return false;
    }

    $headers = array(
        'competition_code',
        'competition_name',
        'competition_date_from',
        'competition_date_to',
        'session_fingerprint',
        'research_status',
        'station_name',
        'station_id',
        'device_id',
        'timezone',
        'shooting_bearing_deg',
        'direction_reference_correction_deg',
        'corrected_shooting_bearing_deg',
        'direction_verification',
        'sensor_height_m',
        'station_forward_offset_m',
        'station_lateral_offset_m',
        'ground_surface',
        'site_exposure',
        'position_notes',
        'session_started_local',
        'session_ended_local',
        'coverage_percent',
        'missing_observations',
        'observation_time_local',
        'observation_epoch',
        'report_interval_minutes',
        'wind_lull_mph',
        'wind_avg_mph',
        'wind_gust_mph',
        'wind_direction_from_deg',
        'corrected_wind_direction_from_deg',
        'relative_wind_angle_deg',
        'relative_wind_description',
        'station_pressure_mb',
        'sea_level_pressure_mb',
        'air_temp_c',
        'relative_humidity_percent',
        'illuminance_lux',
        'uv_index',
        'solar_radiation_w_m2',
        'precip_accumulation_mm',
        'local_day_precip_mm',
        'precip_type',
        'lightning_strike_count',
        'lightning_strike_distance_km',
        'imported_at'
    );

    fputcsv($handle, $headers);

    foreach ($observations as $observation) {
        $relativeWind = null;
        $effectiveWindDirection = null;

        if (
            isset($observation['wind_dir'])
            && $observation['wind_dir'] !== null
        ) {
            $effectiveWindDirection =
                resultspack_weather_effective_wind_direction(
                    $observation['wind_dir'],
                    $session
                );
        }

        if (
            $effectiveWindDirection !== null
            && $effectiveShootingBearing !== null
        ) {
            $relativeWind =
                resultspack_weather_relative_wind(
                    $effectiveWindDirection,
                    $effectiveShootingBearing
                );
        }

        $observationLocal = !empty($observation['timestamp'])
            ? resultspack_weather_format_timestamp(
                (int) $observation['timestamp'],
                $timezone,
                'Y-m-d H:i:s T'
            )
            : '';

        $row = array(
            resultspack_weather_csv_text($tournament['code'] ?? ''),
            resultspack_weather_csv_text($tournament['name'] ?? ''),
            resultspack_weather_csv_text($tournament['date_from'] ?? ''),
            resultspack_weather_csv_text($tournament['date_to'] ?? ''),
            resultspack_weather_csv_text($package['session_fingerprint'] ?? ''),
            resultspack_weather_csv_text($session['research_status'] ?? ''),
            resultspack_weather_csv_text($session['station_name'] ?? ''),
            $session['station_id'] ?? '',
            $session['device_id'] ?? '',
            resultspack_weather_csv_text($timezone),
            $session['shooting_bearing'] ?? '',
            $directionCorrection,
            $effectiveShootingBearing !== null
                ? $effectiveShootingBearing
                : '',
            resultspack_weather_csv_text($session['direction_verification'] ?? 'unverified'),
            $session['sensor_height'] ?? '',
            $session['forward_offset'] ?? '',
            $session['lateral_offset'] ?? '',
            resultspack_weather_csv_text($session['ground_surface'] ?? ''),
            resultspack_weather_csv_text($session['exposure'] ?? ''),
            resultspack_weather_csv_text($session['position_notes'] ?? ''),
            resultspack_weather_csv_text($startedLocal),
            resultspack_weather_csv_text($endedLocal),
            $quality['coverage_percent'] ?? '',
            $quality['missing'] ?? '',
            resultspack_weather_csv_text($observationLocal),
            $observation['timestamp'] ?? '',
            $observation['report_interval'] ?? '',
            $observation['wind_lull'] ?? '',
            $observation['wind_avg'] ?? '',
            $observation['wind_gust'] ?? '',
            $observation['wind_dir'] ?? '',
            $effectiveWindDirection !== null
                ? $effectiveWindDirection
                : '',
            $relativeWind['angle'] ?? '',
            resultspack_weather_csv_text($relativeWind['label'] ?? ''),
            $observation['station_pressure'] ?? '',
            $observation['sea_level_pressure'] ?? '',
            $observation['air_temp'] ?? '',
            $observation['humidity'] ?? '',
            $observation['illuminance'] ?? '',
            $observation['uv'] ?? '',
            $observation['solar_radiation'] ?? '',
            $observation['precip_accumulation'] ?? '',
            $observation['local_day_precip'] ?? '',
            $observation['precip_type'] ?? '',
            $observation['strike_count'] ?? '',
            $observation['strike_distance'] ?? '',
            resultspack_weather_csv_text($observation['imported'] ?? '')
        );

        fputcsv($handle, $row);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

//Return validated SQL datetime expression
function resultspack_weather_transfer_sql_datetime($value)
{
    $value = trim((string) $value);

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return StrSafe_DB($value);
    }

    return 'NOW()';
}

//Limit imported free text
function resultspack_weather_transfer_text($value, $limit)
{
    $value = trim((string) $value);
    $limit = max(0, (int) $limit);

    if ($limit === 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}

//Check package structure
function resultspack_weather_validate_transfer_package(array $package)
{
    if (($package['format'] ?? '') !== 'resultspack-weather-session') {
        return array(
            'ok' => false,
            'error' => 'This is not a ResultsPack weather-session package.',
        );
    }

    if ((int) ($package['format_version'] ?? 0) !== resultspack_weather_transfer_format_version()) {
        return array(
            'ok' => false,
            'error' => 'This weather package uses an unsupported format version.',
        );
    }

    if (empty($package['session']) || !is_array($package['session'])) {
        return array(
            'ok' => false,
            'error' => 'The weather package has no session metadata.',
        );
    }

    $session = $package['session'];
    $stationId = (int) ($session['station_id'] ?? 0);
    $started = (int) ($session['started_epoch'] ?? 0);
    $ended = (int) ($session['ended_epoch'] ?? 0);

    if ($stationId <= 0 || $started <= 0 || $ended <= $started) {
        return array(
            'ok' => false,
            'error' => 'The weather package contains invalid session identifiers or times.',
        );
    }

    $timezone = trim((string) ($session['timezone'] ?? 'UTC'));

    try {
        new DateTimeZone($timezone);
    } catch (Exception $e) {
        return array(
            'ok' => false,
            'error' => 'The weather package contains an invalid timezone.',
        );
    }

    $fingerprint = (string) ($package['session_fingerprint'] ?? '');
    $expectedFingerprint = resultspack_weather_transfer_fingerprint($session);

    if ($fingerprint !== '' && !hash_equals($expectedFingerprint, $fingerprint)) {
        return array(
            'ok' => false,
            'error' => 'The weather package session fingerprint does not match its metadata.',
        );
    }

    foreach (array('observations', 'events', 'timing_corrections') as $key) {
        if (isset($package[$key]) && !is_array($package[$key])) {
            return array(
                'ok' => false,
                'error' => 'The weather package has an invalid ' . $key . ' section.',
            );
        }
    }

    if (count($package['observations'] ?? array()) > 100000) {
        return array(
            'ok' => false,
            'error' => 'The weather package contains too many observations to import safely.',
        );
    }

    foreach (($package['observations'] ?? array()) as $observation) {
        if (!is_array($observation) || (int) ($observation['timestamp'] ?? 0) <= 0) {
            return array(
                'ok' => false,
                'error' => 'The weather package contains an invalid observation row.',
            );
        }
    }

    return array('ok' => true);
}

//Resolve package tournament onto local IANSEO installation
function resultspack_weather_transfer_resolve_tournament(array $package, $requestedTournamentId = 0)
{
    $tournaments = resultspack_fetch_tournament_list();
    $requestedTournamentId = (int) $requestedTournamentId;

    if ($requestedTournamentId > 0) {
        foreach ($tournaments as $tournament) {
            if ((int) $tournament['id'] === $requestedTournamentId) {
                return array(
                    'ok' => true,
                    'tournament_id' => $requestedTournamentId,
                    'matched_by' => 'selected',
                );
            }
        }

        return array(
            'ok' => false,
            'error' => 'The selected local competition could not be found.',
        );
    }

    $source = $package['tournament'] ?? array();
    $code = trim((string) ($source['code'] ?? ''));
    $name = trim((string) ($source['name'] ?? ''));
    $dateFrom = trim((string) ($source['date_from'] ?? ''));

    if ($code !== '') {
        $matches = array();

        foreach ($tournaments as $tournament) {
            if (strcasecmp((string) $tournament['code'], $code) === 0) {
                $matches[] = $tournament;
            }
        }

        if (count($matches) === 1) {
            return array(
                'ok' => true,
                'tournament_id' => (int) $matches[0]['id'],
                'matched_by' => 'competition code',
            );
        }

        if (count($matches) > 1 && $dateFrom !== '') {
            $dated = array_values(array_filter(
                $matches,
                function ($tournament) use ($dateFrom) {
                    return (string) ($tournament['date_from'] ?? '') === $dateFrom;
                }
            ));

            if (count($dated) === 1) {
                return array(
                    'ok' => true,
                    'tournament_id' => (int) $dated[0]['id'],
                    'matched_by' => 'competition code and date',
                );
            }
        }
    }

    if ($name !== '') {
        $matches = array();

        foreach ($tournaments as $tournament) {
            if (strcasecmp(trim((string) $tournament['name']), $name) === 0) {
                if ($dateFrom === '' || (string) ($tournament['date_from'] ?? '') === $dateFrom) {
                    $matches[] = $tournament;
                }
            }
        }

        if (count($matches) === 1) {
            return array(
                'ok' => true,
                'tournament_id' => (int) $matches[0]['id'],
                'matched_by' => $dateFrom !== '' ? 'competition name and date' : 'competition name',
            );
        }
    }

    return array(
        'ok' => false,
        'needs_tournament' => true,
        'error' => 'ResultsPack could not match the package to exactly one local competition. Please choose the local competition explicitly and import again.',
    );
}

//Find an already imported copy of the same portable weather session
function resultspack_weather_transfer_find_duplicate(array $session)
{
    resultspack_weather_ensure_sessions_table();

    $stationId = (int) ($session['station_id'] ?? 0);
    $started = (int) ($session['started_epoch'] ?? 0);
    $ended = (int) ($session['ended_epoch'] ?? 0);

    $result = safe_r_sql(
        "SELECT CrwsId,CrwsTournament " .
        "FROM CustomResultsPackWeatherSessions " .
        "WHERE CrwsStationId=" . $stationId . " " .
        "AND CrwsStartedEpoch=" . $started . " " .
        "AND CrwsEndedEpoch=" . $ended . " " .
        "ORDER BY CrwsId DESC LIMIT 1"
    );

    $row = safe_fetch($result);

    if (!$row) {
        return null;
    }

    return array(
        'session_id' => (int) $row->CrwsId,
        'tournament_id' => (int) $row->CrwsTournament,
    );
}

//Import/merge a package without reusing source database IDs
function resultspack_weather_import_transfer_package(array $package, $requestedTournamentId = 0)
{
    $validation = resultspack_weather_validate_transfer_package($package);

    if (!$validation['ok']) {
        return $validation;
    }

    $tournament = resultspack_weather_transfer_resolve_tournament(
        $package,
        $requestedTournamentId
    );

    if (!$tournament['ok']) {
        return $tournament;
    }

    resultspack_weather_ensure_sessions_table();
    resultspack_weather_ensure_observations_table();
    resultspack_weather_ensure_events_table();
    resultspack_weather_ensure_timing_corrections_table();
    resultspack_weather_ensure_direction_corrections_table();

    $session = $package['session'];
    $duplicate = resultspack_weather_transfer_find_duplicate($session);
    $sessionId = 0;
    $existing = false;
    $warning = '';

    safe_w_sql('START TRANSACTION');

    if ($duplicate) {
        $sessionId = (int) $duplicate['session_id'];
        $existing = true;

        if ((int) $duplicate['tournament_id'] !== (int) $tournament['tournament_id']) {
            $warning =
                'The matching weather session already existed and is attached to a different local competition. Its existing competition link was left unchanged.';
        }
    } else {
        $deviceId = isset($session['device_id']) && is_numeric($session['device_id'])
            ? (int) $session['device_id']
            : 0;

        $deviceSql = $deviceId > 0 ? (string) $deviceId : 'NULL';

        $bearingSql = isset($session['shooting_bearing']) && is_numeric($session['shooting_bearing'])
            ? resultspack_weather_sql_number((float) $session['shooting_bearing'])
            : 'NULL';

        $directionCorrectionSql =
            isset($session['direction_correction'])
            && is_numeric($session['direction_correction'])
                ? resultspack_weather_sql_number(
                    (float) $session['direction_correction']
                )
                : resultspack_weather_sql_number(0);

        $directionVerification =
            resultspack_weather_direction_verification(
                $session['direction_verification'] ?? 'unverified'
            );

        $heightSql = isset($session['sensor_height']) && is_numeric($session['sensor_height'])
            ? resultspack_weather_sql_number((float) $session['sensor_height'])
            : 'NULL';

        $forwardOffsetSql =
            resultspack_weather_sql_number(
                resultspack_weather_site_offset(
                    $session['forward_offset'] ?? null
                )
            );

        $lateralOffsetSql =
            resultspack_weather_sql_number(
                resultspack_weather_site_offset(
                    $session['lateral_offset'] ?? null
                )
            );

        $groundSurface =
            resultspack_weather_ground_surface(
                $session['ground_surface'] ?? ''
            );

        $exposure =
            resultspack_weather_site_exposure(
                $session['exposure'] ?? ''
            );

        $stationName = resultspack_weather_transfer_text(
            $session['station_name'] ?? '',
            255
        );

        $positionNotes = resultspack_weather_transfer_text(
            $session['position_notes'] ?? '',
            2000
        );

        $status = resultspack_weather_research_status(
            $session['research_status'] ?? 'test'
        );

        safe_w_sql(
            "INSERT INTO CustomResultsPackWeatherSessions (" .
            "CrwsTournament,CrwsStationId,CrwsDeviceId,CrwsStationName," .
            "CrwsStartedEpoch,CrwsEndedEpoch,CrwsTimezone," .
            "CrwsShootingBearing,CrwsDirectionCorrection,CrwsDirectionVerification,CrwsSensorHeight," .
            "CrwsForwardOffset,CrwsLateralOffset," .
            "CrwsGroundSurface,CrwsExposure,CrwsPositionNotes," .
            "CrwsResearchStatus,CrwsCreated " .
            ") VALUES (" .
            (int) $tournament['tournament_id'] . "," .
            (int) $session['station_id'] . "," .
            $deviceSql . "," .
            StrSafe_DB($stationName) . "," .
            (int) $session['started_epoch'] . "," .
            (int) $session['ended_epoch'] . "," .
            StrSafe_DB((string) $session['timezone']) . "," .
            $bearingSql . "," .
            $directionCorrectionSql . "," .
            StrSafe_DB($directionVerification) . "," .
            $heightSql . "," .
            $forwardOffsetSql . "," .
            $lateralOffsetSql . "," .
            StrSafe_DB($groundSurface) . "," .
            StrSafe_DB($exposure) . "," .
            StrSafe_DB($positionNotes) . "," .
            StrSafe_DB($status) . "," .
            resultspack_weather_transfer_sql_datetime($session['created'] ?? '') .
            ")"
        );

        //The INSERT has created a new local session. Use MySQL's primary key.
        $insertIdResult = safe_w_sql(
            "SELECT LAST_INSERT_ID() AS SessionId"
        );

        $insertIdRow = safe_fetch($insertIdResult);

        $sessionId =
            $insertIdRow
                ? (int) $insertIdRow->SessionId
                : 0;


        //Make sure the ID belongs to the session we have attempted to import.
                $insertedSessionCheck = null;

        if ($sessionId > 0) {
            $checkResult = safe_w_sql(
                "SELECT CrwsId " .
                "FROM CustomResultsPackWeatherSessions " .
                "WHERE CrwsId=" . $sessionId . " " .
                "AND CrwsStationId=" .
                (int) $session['station_id'] . " " .
                "AND CrwsStartedEpoch=" .
                (int) $session['started_epoch'] . " " .
                "AND CrwsEndedEpoch=" .
                (int) $session['ended_epoch'] . " " .
                "LIMIT 1"
            );

            $insertedSessionCheck =
                safe_fetch($checkResult);
        }


        if (!$insertedSessionCheck) {
            safe_w_sql('ROLLBACK');

            return array(
                'ok' => false,
                'error' =>
                    'The weather session INSERT did not produce a valid local session ID.',
            );
        }
    }

    //Restore current direction reference if present; if not, omit.
    if (
        array_key_exists('direction_correction', $session)
        || array_key_exists('direction_verification', $session)
    ) {
        $importedDirectionCorrection =
            isset($session['direction_correction'])
            && is_numeric($session['direction_correction'])
                ? (float) $session['direction_correction']
                : 0.0;

        $importedDirectionVerification =
            resultspack_weather_direction_verification(
                $session['direction_verification'] ?? 'unverified'
            );

        safe_w_sql(
            "UPDATE CustomResultsPackWeatherSessions SET " .
            "CrwsDirectionCorrection=" .
            resultspack_weather_sql_number(
                $importedDirectionCorrection
            ) . "," .
            "CrwsDirectionVerification=" .
            StrSafe_DB(
                $importedDirectionVerification
            ) . " " .
            "WHERE CrwsId=" . (int) $sessionId
        );
    }

    $beforeObservations = resultspack_weather_transfer_observation_count($sessionId);

    foreach (($package['observations'] ?? array()) as $observation) {
        $timestamp = (int) ($observation['timestamp'] ?? 0);

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
            $sessionId . "," .
            $timestamp . "," .
            resultspack_weather_sql_number($observation['report_interval'] ?? null) . "," .
            resultspack_weather_sql_number($observation['wind_lull'] ?? null) . "," .
            resultspack_weather_sql_number($observation['wind_avg'] ?? null) . "," .
            resultspack_weather_sql_number($observation['wind_gust'] ?? null) . "," .
            resultspack_weather_sql_number($observation['wind_dir'] ?? null) . "," .
            resultspack_weather_sql_number($observation['station_pressure'] ?? null) . "," .
            resultspack_weather_sql_number($observation['sea_level_pressure'] ?? null) . "," .
            resultspack_weather_sql_number($observation['air_temp'] ?? null) . "," .
            resultspack_weather_sql_number($observation['humidity'] ?? null) . "," .
            resultspack_weather_sql_number($observation['illuminance'] ?? null) . "," .
            resultspack_weather_sql_number($observation['uv'] ?? null) . "," .
            resultspack_weather_sql_number($observation['solar_radiation'] ?? null) . "," .
            resultspack_weather_sql_number($observation['precip_accumulation'] ?? null) . "," .
            resultspack_weather_sql_number($observation['local_day_precip'] ?? null) . "," .
            resultspack_weather_sql_number($observation['precip_type'] ?? null) . "," .
            resultspack_weather_sql_number($observation['strike_count'] ?? null) . "," .
            resultspack_weather_sql_number($observation['strike_distance'] ?? null) . "," .
            resultspack_weather_transfer_sql_datetime($observation['imported'] ?? '') .
            ")"
        );
    }

    $eventsAdded = 0;

    foreach (($package['events'] ?? array()) as $event) {
        if (!is_array($event)) {
            continue;
        }

        $timestamp = (int) ($event['timestamp'] ?? 0);
        $action = strtolower(resultspack_weather_transfer_text($event['action'] ?? '', 32));
        $reason = resultspack_weather_transfer_text($event['reason'] ?? '', 64);
        $note = resultspack_weather_transfer_text($event['note'] ?? '', 1000);

        if ($timestamp <= 0 || !in_array($action, array('delay', 'suspend', 'resume', 'abandon'), true)) {
            continue;
        }

        $existingEvent = safe_r_sql(
            "SELECT CrweId FROM CustomResultsPackWeatherEvents " .
            "WHERE CrweSession=" . $sessionId . " " .
            "AND CrweTimestamp=" . $timestamp . " " .
            "AND CrweAction=" . StrSafe_DB($action) . " " .
            "AND CrweReason=" . StrSafe_DB($reason) . " " .
            "AND CrweNote=" . StrSafe_DB($note) . " " .
            "LIMIT 1"
        );

        if (safe_fetch($existingEvent)) {
            continue;
        }

        safe_w_sql(
            "INSERT INTO CustomResultsPackWeatherEvents (" .
            "CrweSession,CrweTimestamp,CrweAction,CrweReason,CrweNote,CrweCreated" .
            ") VALUES (" .
            $sessionId . "," .
            $timestamp . "," .
            StrSafe_DB($action) . "," .
            StrSafe_DB($reason) . "," .
            StrSafe_DB($note) . "," .
            resultspack_weather_transfer_sql_datetime($event['created'] ?? '') .
            ")"
        );

        $eventsAdded++;
    }

$correctionsAdded = 0;

foreach (
    ($package['timing_corrections'] ?? array())
    as $correction
) {
    if (!is_array($correction)) {
        continue;
    }

    $oldStarted =
        (int) ($correction['old_started_epoch'] ?? 0);

    $oldEnded =
        (int) ($correction['old_ended_epoch'] ?? 0);

    $newStarted =
        (int) ($correction['new_started_epoch'] ?? 0);

    $newEnded =
        (int) ($correction['new_ended_epoch'] ?? 0);

    $timezone =
        resultspack_weather_transfer_text(
            $correction['timezone']
                ?? ($session['timezone'] ?? 'UTC'),
            64
        );

    $reason =
        resultspack_weather_transfer_text(
            $correction['reason'] ?? '',
            2000
        );

    if (
        $oldStarted <= 0
        || $oldEnded <= 0
        || $newStarted <= 0
        || $newEnded <= 0
    ) {
        continue;
    }

    $existingCorrection = safe_w_sql(
        "SELECT CrwtcId " .
        "FROM CustomResultsPackWeatherTimingCorrections " .
        "WHERE CrwtcSession=" . (int) $sessionId . " " .
        "AND CrwtcOldStartedEpoch=" . $oldStarted . " " .
        "AND CrwtcOldEndedEpoch=" . $oldEnded . " " .
        "AND CrwtcNewStartedEpoch=" . $newStarted . " " .
        "AND CrwtcNewEndedEpoch=" . $newEnded . " " .
        "AND CrwtcTimezone=" .
        StrSafe_DB($timezone) . " " .
        "AND CrwtcReason=" .
        StrSafe_DB($reason) . " " .
        "LIMIT 1"
    );

    if (safe_fetch($existingCorrection)) {
        continue;
    }

    safe_w_sql(
        "INSERT INTO CustomResultsPackWeatherTimingCorrections (" .
        "CrwtcSession,CrwtcOldStartedEpoch,CrwtcOldEndedEpoch," .
        "CrwtcNewStartedEpoch,CrwtcNewEndedEpoch,CrwtcTimezone," .
        "CrwtcReason,CrwtcCreated" .
        ") VALUES (" .
        (int) $sessionId . "," .
        $oldStarted . "," .
        $oldEnded . "," .
        $newStarted . "," .
        $newEnded . "," .
        StrSafe_DB($timezone) . "," .
        StrSafe_DB($reason) . "," .
        resultspack_weather_transfer_sql_datetime(
            $correction['created'] ?? ''
        ) .
        ")"
    );

    $correctionsAdded++;
}


    //Import direction-reference correction audit history separately.
    $directionCorrectionsReceived =
    count($package['direction_corrections'] ?? array());
    
    $directionCorrectionsAdded = 0;

    foreach (
        ($package['direction_corrections'] ?? array())
        as $directionCorrection
    ) {
        if (!is_array($directionCorrection)) {
            continue;
        }

        $oldCorrection =
            isset($directionCorrection['old_correction'])
            && is_numeric($directionCorrection['old_correction'])
                ? (float) $directionCorrection['old_correction']
                : 0.0;

        $newCorrection =
            isset($directionCorrection['new_correction'])
            && is_numeric($directionCorrection['new_correction'])
                ? (float) $directionCorrection['new_correction']
                : 0.0;

        $recordedBearing =
            isset($directionCorrection['recorded_bearing'])
            && is_numeric($directionCorrection['recorded_bearing'])
                ? (float) $directionCorrection['recorded_bearing']
                : null;

        $verifiedBearing =
            isset($directionCorrection['verified_bearing'])
            && is_numeric($directionCorrection['verified_bearing'])
                ? (float) $directionCorrection['verified_bearing']
                : null;

        $verification =
            resultspack_weather_direction_verification(
                $directionCorrection['verification']
                    ?? 'unverified'
            );

        $reason =
            resultspack_weather_transfer_text(
                $directionCorrection['reason'] ?? '',
                2000
            );

        if ($verifiedBearing === null) {
            continue;
        }

        $existingDirectionCorrection = safe_w_sql(
            "SELECT CrwdcId " .
            "FROM CustomResultsPackWeatherDirectionCorrections " .
            "WHERE CrwdcSession=" . (int) $sessionId . " " .
            "AND CrwdcOldCorrection=" .
            resultspack_weather_sql_number(
                $oldCorrection
            ) . " " .
            "AND CrwdcNewCorrection=" .
            resultspack_weather_sql_number(
                $newCorrection
            ) . " " .
            "AND CrwdcVerifiedBearing=" .
            resultspack_weather_sql_number(
                $verifiedBearing
            ) . " " .
            "AND CrwdcVerification=" .
            StrSafe_DB($verification) . " " .
            "AND CrwdcReason=" .
            StrSafe_DB($reason) . " " .
            "LIMIT 1"
        );

        if (safe_fetch($existingDirectionCorrection)) {
            continue;
        }

        safe_w_sql(
            "INSERT INTO CustomResultsPackWeatherDirectionCorrections (" .
            "CrwdcSession,CrwdcOldCorrection,CrwdcNewCorrection," .
            "CrwdcRecordedBearing,CrwdcVerifiedBearing," .
            "CrwdcVerification,CrwdcReason,CrwdcCreated" .
            ") VALUES (" .
            (int) $sessionId . "," .
            resultspack_weather_sql_number(
                $oldCorrection
            ) . "," .
            resultspack_weather_sql_number(
                $newCorrection
            ) . "," .
            resultspack_weather_sql_number(
                $recordedBearing
            ) . "," .
            resultspack_weather_sql_number(
                $verifiedBearing
            ) . "," .
            StrSafe_DB($verification) . "," .
            StrSafe_DB($reason) . "," .
            resultspack_weather_transfer_sql_datetime(
                $directionCorrection['created'] ?? ''
            ) .
            ")"
        );

        $directionCorrectionsAdded++;
    }

    safe_w_sql('COMMIT');

    $afterObservations =
        resultspack_weather_transfer_observation_count(
            $sessionId
        );

    $quality =
        resultspack_weather_session_quality($sessionId);

    return array(
        'ok' => true,
        'session_id' => $sessionId,
        'existing_session' => $existing,
        'tournament_id' => $existing && $duplicate
            ? (int) $duplicate['tournament_id']
            : (int) $tournament['tournament_id'],
        'tournament_match' => (string) ($tournament['matched_by'] ?? ''),
        'observations_received' => count($package['observations'] ?? array()),
        'observations_added' => max(0, $afterObservations - $beforeObservations),
        'events_received' => count($package['events'] ?? array()),
        'events_added' => $eventsAdded,
        'corrections_received' => count($package['timing_corrections'] ?? array()),
        'corrections_added' => $correctionsAdded,
        'direction_corrections_received' => count($package['direction_corrections'] ?? array()),
        'direction_corrections_added' => $directionCorrectionsAdded,
        'quality' => $quality,
        'warning' => $warning,
    );
}

