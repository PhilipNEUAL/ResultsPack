<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

$PAGE_TITLE = 'Tempest Weather Test';

include('Common/Templates/head.php');

$summary = resultspack_weather_config_summary();

$shootingBearing = null;

if (isset($_GET['shooting_bearing']) && is_numeric($_GET['shooting_bearing'])) {
    $shootingBearing = (float) $_GET['shooting_bearing'];

    if ($shootingBearing >= 0 && $shootingBearing < 360) {
        // Valid bearing.
    } else {
        $shootingBearing = null;
    }
}

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Tempest Weather Integration Test</th></tr>';

echo '<tr>';
echo '<td class="Bold">Configuration</td>';
echo '<td>';

if ($summary['configured']) {
    echo '<span style="color:green"><b>Configured</b></span>';
} else {
    echo '<span style="color:#a00"><b>Not configured yet</b></span>';
}

echo '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Station ID</td>';
echo '<td>' . (!empty($summary['station_id']) ? htmlspecialchars((string) $summary['station_id']) : 'Not set') . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Device ID</td>';
echo '<td>' . (!empty($summary['device_id']) ? htmlspecialchars((string) $summary['device_id']) : 'Not set') . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td class="Bold">Access token</td>';
echo '<td>Hidden</td>';
echo '</tr>';

echo '</table>';

if ($summary['configured']) {
    $stationsResponse = resultspack_weather_fetch_stations();

    echo '<br>';

    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="3">Tempest stations</th></tr>';

    if (!$stationsResponse['ok']) {
        echo '<tr><td colspan="3"><b>Connection failed:</b> '
            . htmlspecialchars($stationsResponse['error'])
            . '</td></tr>';
    } else {
        $stations = $stationsResponse['data']['stations'] ?? array();

        if (!$stations) {
            echo '<tr><td colspan="3">No stations were returned for this account.</td></tr>';
        } else {
            echo '<tr>';
            echo '<th class="Title">Station</th>';
            echo '<th class="Title">Station ID</th>';
            echo '<th class="Title">Devices</th>';
            echo '</tr>';

            foreach ($stations as $station) {
                $deviceLabels = array();

                foreach (($station['devices'] ?? array()) as $device) {
                    $deviceLabels[] =
                        ($device['device_type'] ?? 'Unknown')
                        . ' '
                        . ($device['device_id'] ?? '');
                }

                echo '<tr>';
                echo '<td>' . htmlspecialchars($station['name'] ?? 'Unnamed station') . '</td>';
                echo '<td class="Center">' . htmlspecialchars((string) ($station['station_id'] ?? '')) . '</td>';
                echo '<td>' . htmlspecialchars(implode(', ', $deviceLabels)) . '</td>';
                echo '</tr>';
            }
        }
    }

    echo '</table>';

    if (!empty($stations)) {
    $station = reset($stations);
    $stationId = $station['station_id'] ?? null;

    echo '<br>';

    $observationResponse = resultspack_weather_fetch_latest_observation($stationId);

    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">Latest Tempest observation</th></tr>';

        if (!$observationResponse['ok']) {
        echo '<tr><td colspan="2"><b>Observation request failed:</b> '
            . htmlspecialchars($observationResponse['error'])
            . '</td></tr>';
    } else {
        $data = $observationResponse['data'];

        $fields = $data['ob_fields'] ?? array();
        $values = $data['obs'][0] ?? array();

        $obs = array();

        if ($fields && $values && count($fields) === count($values)) {
            $obs = array_combine($fields, $values);
        }

        echo '<tr><td class="Bold">Station</td><td>'
            . htmlspecialchars($station['name'] ?? 'Unnamed station')
            . '</td></tr>';
        
        echo '<tr><td class="Bold">Shooting direction</td><td>';
        echo '<form method="get" action="TempestTest.php" style="margin:0">';
        echo '<input type="number" name="shooting_bearing" min="0" max="359" step="1"'
            . ($shootingBearing !== null ? ' value="' . htmlspecialchars((string) $shootingBearing) . '"' : '')
            . ' placeholder="0-359"> ° ';
        echo '<input type="submit" value="Apply bearing">';
        echo ' <span class="resultspack-muted">Direction from the shooting line towards the targets.</span>';
        echo '</form>';
        echo '</td></tr>';

        if (!empty($obs['timestamp'])) {
            $timezoneName = $data['timezone'] ?? 'UTC';

            try {
                $observationTime = new DateTime('@' . (int) $obs['timestamp']);
                $observationTime->setTimezone(new DateTimeZone($timezoneName));
                $timeLabel = $observationTime->format('H:i:s');
            } catch (Exception $e) {
                $timeLabel = date('H:i:s', (int) $obs['timestamp']);
            }

            echo '<tr><td class="Bold">Observation time</td><td>'
                . htmlspecialchars($timeLabel)
                . ' <span class="resultspack-muted">('
                . htmlspecialchars(resultspack_weather_observation_age($obs['timestamp']))
                . ')</span></td></tr>';
        }

        echo '<tr><td class="Bold">Temperature</td><td>'
            . resultspack_weather_format_number($obs['air_temp'] ?? null, 1)
            . ' °C</td></tr>';

        echo '<tr><td class="Bold">Humidity</td><td>'
            . resultspack_weather_format_number($obs['rh'] ?? null, 0)
            . ' %</td></tr>';

        echo '<tr><td class="Bold">Wind lull</td><td>'
            . resultspack_weather_format_number($obs['wind_lull'] ?? null, 1)
            . ' mph</td></tr>';

        echo '<tr><td class="Bold">Wind average</td><td>'
            . resultspack_weather_format_number($obs['wind_avg'] ?? null, 1)
            . ' mph</td></tr>';

        echo '<tr><td class="Bold">Wind gust</td><td>'
            . resultspack_weather_format_number($obs['wind_gust'] ?? null, 1)
            . ' mph</td></tr>';

        $windDirection = $obs['wind_dir'] ?? null;

            echo '<tr><td class="Bold">Wind direction</td><td>';

            if (is_numeric($windDirection)) {
                echo htmlspecialchars(resultspack_weather_compass_direction($windDirection))
                    . ' (' . resultspack_weather_format_number($windDirection, 0) . '°)';
            } else {
                echo 'Not available';
            }

            echo '</td></tr>';

            if ($shootingBearing !== null && is_numeric($windDirection)) {
            $relativeWind = resultspack_weather_relative_wind(
                $windDirection,
                $shootingBearing
            );

            echo '<tr><td class="Bold">Relative to shooting line</td><td>'
                . htmlspecialchars($relativeWind['label'])
                . ' <span class="resultspack-muted">('
                . htmlspecialchars((string) $relativeWind['angle'])
                . '° relative)</span></td></tr>';
            }

        echo '<tr><td class="Bold">Rain today</td><td>'
            . resultspack_weather_format_number($obs['local_day_precip_accumulation'] ?? null, 2)
            . ' mm</td></tr>';

        $strikeCount = isset($obs['strike_count']) && is_numeric($obs['strike_count'])
            ? (int) $obs['strike_count']
            : 0;

        if ($strikeCount > 0) {
            echo '<tr style="background:#fff3cd">';
            echo '<td class="Bold">Lightning detected</td>';
            echo '<td><b>'
                . htmlspecialchars((string) $strikeCount)
                . ' strike' . ($strikeCount === 1 ? '' : 's')
                . ' detected during this observation';

            if (isset($obs['strike_distance']) && is_numeric($obs['strike_distance'])) {
                echo ' — average distance '
                    . resultspack_weather_format_number($obs['strike_distance'], 1)
                    . ' km';
            }

            echo '</b></td></tr>';
        } else {
            echo '<tr><td class="Bold">Lightning</td>';
            echo '<td>No strikes detected during the current observation.</td></tr>';
        }
    }

    echo '</table>';
}
}

include('Common/Templates/tail.php');