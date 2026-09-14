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

/**
 * Describe wind direction relative to the shooting direction.
 *
 * Tempest wind direction is the direction the wind is coming FROM.
 * Shooting bearing is the direction from the shooting line towards the targets.
 */
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