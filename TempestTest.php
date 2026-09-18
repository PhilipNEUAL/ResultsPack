<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

$PAGE_TITLE = 'Tempest Weather Test';

include('Common/Templates/head.php');

$summary = resultspack_weather_config_summary();
$currentFreshness = null;

$activeSession = resultspack_weather_get_active_session();

$shootingBearing = $activeSession['shooting_bearing'] ?? null;

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

        $currentFreshness = resultspack_weather_freshness(
            $obs['timestamp'] ?? null,
            $obs['report_interval'] ?? 1
        );

        echo '<tr><td class="Bold">Station</td><td>'
            . htmlspecialchars($station['name'] ?? 'Unnamed station')
            . '</td></tr>';
        
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

        if ($currentFreshness) {
            if ($currentFreshness['status'] === 'live') {
                $freshnessStyle = 'color:green';
            } elseif ($currentFreshness['status'] === 'delayed') {
                $freshnessStyle = 'color:#9a6700';
            } else {
                $freshnessStyle = 'color:#b00020';
            }

            echo '<tr>';
            echo '<td class="Bold">Data status</td>';
            echo '<td style="' . $freshnessStyle . '"><b>'
                . htmlspecialchars($currentFreshness['label'])
                . '</b>';

            echo ' — last observation '
                . htmlspecialchars($currentFreshness['age_text']);

            if ($currentFreshness['report_interval_minutes'] !== null) {
                echo ' (reporting every '
                    . htmlspecialchars((string) $currentFreshness['report_interval_minutes'])
                    . ' minute'
                    . ($currentFreshness['report_interval_minutes'] == 1 ? '' : 's')
                    . ')';
            }

            echo '</td>';
            echo '</tr>';
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

    //Lightning information must not be presented as current if the observation is delayed, stale or unavailable.
    if (!$currentFreshness || $currentFreshness['status'] !== 'live') {
        $freshnessLabel = $currentFreshness
            ? $currentFreshness['label']
            : 'UNKNOWN';

        echo '<tr style="background:#fff3cd">';
        echo '<td class="Bold">Lightning status</td>';
        echo '<td><b>Current lightning status unavailable</b>';

        echo ' — Tempest data is '
            . htmlspecialchars($freshnessLabel);

        if ($currentFreshness && !empty($currentFreshness['age_text'])) {
            echo ' (' . htmlspecialchars($currentFreshness['age_text']) . ')';
        }

        echo '.</td>';
        echo '</tr>';

    } elseif ($strikeCount > 0) {
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

        echo '</b></td>';
        echo '</tr>';

    } else {
        echo '<tr>';
        echo '<td class="Bold">Lightning</td>';
        echo '<td>No strikes detected during the current observation.</td>';
        echo '</tr>';
    }
    }

    echo '</table>';
}
}

//Weather research session controls.
if ($summary['configured']) {
    $tournamentList = resultspack_fetch_tournament_list();
    $latestSession = resultspack_weather_get_latest_session();

    echo '<br>';
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="2">Weather research session</th></tr>';

    if (!$activeSession && $latestSession) {
        echo '<tr>';
        echo '<td colspan="2" class="resultspack-muted">';
        echo 'Setup details have been carried forward from the previous weather session. '
            . 'Review them before starting a new session.';
        echo '</td>';
        echo '</tr>';
    }

    if (($_GET['session'] ?? '') === 'started') {
        echo '<tr><td colspan="2" style="color:green"><b>Weather session started.</b></td></tr>';
    } elseif (($_GET['session'] ?? '') === 'stopped') {
        echo '<tr><td colspan="2" style="color:green"><b>Weather session stopped.</b></td></tr>';
    }

    if ($activeSession) {
        $tournamentName = 'Competition ' . $activeSession['tournament_id'];

        foreach ($tournamentList as $tournament) {
            if ((int) $tournament['id'] === (int) $activeSession['tournament_id']) {
                $tournamentName =
                    ($tournament['code'] !== '' ? $tournament['code'] . ' — ' : '')
                    . $tournament['name'];
                break;
            }
        }

        try {
            $startedTime = new DateTime('@' . $activeSession['started_epoch']);
            $startedTime->setTimezone(
                new DateTimeZone($activeSession['timezone'] ?: 'UTC')
            );
            $startedLabel = $startedTime->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            $startedLabel = date('d/m/Y H:i:s', $activeSession['started_epoch']);
        }

        echo '<tr><td class="Bold">Status</td><td><b style="color:green">Active</b></td></tr>';

        echo '<tr><td class="Bold">Competition</td><td>'
            . htmlspecialchars($tournamentName)
            . '</td></tr>';

        echo '<tr><td class="Bold">Station</td><td>'
            . htmlspecialchars($activeSession['station_name'])
            . ' (' . (int) $activeSession['station_id'] . ')'
            . '</td></tr>';

        echo '<tr><td class="Bold">Started</td><td>'
            . htmlspecialchars($startedLabel)
            . '</td></tr>';

        echo '<tr><td class="Bold">Shooting bearing</td><td>'
            . ($activeSession['shooting_bearing'] !== null
                ? htmlspecialchars((string) $activeSession['shooting_bearing']) . '°'
                : 'Not recorded')
            . '</td></tr>';

        echo '<tr><td class="Bold">Sensor height</td><td>'
            . ($activeSession['sensor_height'] !== null
                ? htmlspecialchars((string) $activeSession['sensor_height']) . ' m'
                : 'Not recorded')
            . '</td></tr>';

        echo '<tr><td class="Bold">Position notes</td><td>'
            . ($activeSession['position_notes'] !== ''
                ? nl2br(htmlspecialchars($activeSession['position_notes']))
                : 'None')
            . '</td></tr>';

        echo '<tr><td class="Bold">Judge decision log</td><td>';

        echo '<form method="post" action="WeatherEventAction.php">';

        echo '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(resultspack_csrf_token())
            . '">';

        echo '<div style="margin-bottom:8px">';

        echo '<button type="submit" name="event_action" value="delay">Delay</button> ';
        echo '<button type="submit" name="event_action" value="suspend">Suspend</button> ';
        echo '<button type="submit" name="event_action" value="resume">Resume</button> ';
        echo '<button type="submit" name="event_action" value="abandon">Abandon</button>';

        echo '</div>';

        echo '<label>Reason ';
        echo '<select name="event_reason">';
        echo '<option value="">Not specified</option>';
        echo '<option value="wind">Wind</option>';
        echo '<option value="lightning">Lightning / thunder</option>';
        echo '<option value="rain">Rain / flooding</option>';
        echo '<option value="heat">Heat</option>';
        echo '<option value="cold">Cold</option>';
        echo '<option value="visibility">Visibility</option>';
        echo '<option value="field_conditions">Field conditions</option>';
        echo '<option value="equipment">Equipment / infrastructure</option>';
        echo '<option value="other">Other</option>';
        echo '</select>';
        echo '</label>';

        echo '<br><br>';

        echo '<label>Optional note<br>';
        echo '<textarea name="event_note" rows="2" placeholder="For example: repeated strong gusts affecting target stability."></textarea>';
        echo '</label>';

        echo '</form>';

        echo '</td></tr>';

        $weatherEvents = resultspack_weather_get_events($activeSession['id']);

            if ($weatherEvents) {
                echo '<tr><td class="Bold">Recorded events</td><td>';

                echo '<table class="Tabella freeWidth">';

                echo '<tr>';
                echo '<th class="Title">Time</th>';
                echo '<th class="Title">Action</th>';
                echo '<th class="Title">Reason</th>';
                echo '<th class="Title">Note</th>';
                echo '</tr>';

                foreach ($weatherEvents as $event) {
                    try {
                        $eventTime = new DateTime('@' . $event['timestamp']);
                        $eventTime->setTimezone(
                            new DateTimeZone($activeSession['timezone'] ?: 'UTC')
                        );

                        $eventTimeLabel = $eventTime->format('H:i:s');
                    } catch (Exception $e) {
                        $eventTimeLabel = date(
                            'H:i:s',
                            $event['timestamp']
                        );
                    }

                    echo '<tr>';

                    echo '<td>'
                        . htmlspecialchars($eventTimeLabel)
                        . '</td>';

                    echo '<td>'
                        . htmlspecialchars(ucfirst($event['action']))
                        . '</td>';

                    echo '<td>'
                        . htmlspecialchars(
                            $event['reason'] !== ''
                                ? ucwords(str_replace('_', ' ', $event['reason']))
                                : 'Not specified'
                        )
                        . '</td>';

                    echo '<td>'
                        . htmlspecialchars($event['note'])
                        . '</td>';

                    echo '</tr>';
                }

                echo '</table>';

                echo '</td></tr>';
            }

        echo '<tr><td colspan="2">';

        echo '<form method="post" action="WeatherSessionAction.php" style="margin:0">';
        echo '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(resultspack_csrf_token())
            . '">';
        echo '<input type="hidden" name="weather_action" value="stop">';
        echo '<input type="submit" value="Stop weather session">';
        echo '</form>';

        echo '</td></tr>';
    } else {
        echo '<tr><td class="Bold">Competition</td><td>';

        echo '<form method="post" action="WeatherSessionAction.php">';

        echo '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(resultspack_csrf_token())
            . '">';

        echo '<input type="hidden" name="weather_action" value="start">';

        echo '<select name="tournament_id" required>';
        echo '<option value="">Choose competition...</option>';

        foreach ($tournamentList as $tournament) {
            $label =
                ($tournament['code'] !== '' ? $tournament['code'] . ' — ' : '')
                . $tournament['name'];

        $selected = (
            $latestSession
            && (int) $latestSession['tournament_id'] === (int) $tournament['id']
        ) ? ' selected' : '';

        echo '<option value="' . (int) $tournament['id'] . '"' . $selected . '>'
            . htmlspecialchars($label)
            . '</option>';
        }

        echo '</select>';

        echo '</td></tr>';

        echo '<tr><td class="Bold">Shooting bearing</td><td>';
        $previousBearing = $latestSession['shooting_bearing'] ?? null;

        echo '<input type="number" name="shooting_bearing" min="0" max="359" step="1" required'
            . ($previousBearing !== null
                ? ' value="' . htmlspecialchars((string) $previousBearing) . '"'
                : '')
            . '> °'; 
        echo '<div class="resultspack-muted">Direction from the shooting line towards the targets.</div>';
        echo '</td></tr>';

        echo '<tr><td class="Bold">Sensor height</td><td>';
        $previousHeight = $latestSession['sensor_height'] ?? null;

        echo '<input type="number" name="sensor_height" min="0.1" max="20" step="0.01"'
            . ($previousHeight !== null
                ? ' value="' . htmlspecialchars((string) $previousHeight) . '"'
                : '')
            . '> m';
        echo '<div class="resultspack-muted">Height of the Tempest sensor above ground level.</div>';
        echo '</td></tr>';

        echo '<tr><td class="Bold">Station position / obstructions</td><td>';
        $previousNotes = $latestSession['position_notes'] ?? '';

        echo '<textarea name="position_notes" rows="3" placeholder="For example: 10 m behind shooting line; open field; trees approximately 40 m west.">'
            . htmlspecialchars($previousNotes)
            . '</textarea>';
        echo '</td></tr>';

        if (!$currentFreshness || $currentFreshness['status'] !== 'live') {
            $freshnessLabel = $currentFreshness
                ? $currentFreshness['label']
                : 'UNKNOWN';

            echo '<tr>';
            echo '<td class="Bold">Weather data warning</td>';
            echo '<td style="background:#fff3cd">';

            echo '<b>Tempest data is currently '
                . htmlspecialchars($freshnessLabel)
                . '.</b> ';

            if ($currentFreshness && !empty($currentFreshness['age_text'])) {
                echo 'The latest observation is '
                    . htmlspecialchars($currentFreshness['age_text'])
                    . '. ';
            }

            echo 'The session can still be started, but the current weather data '
                . 'must not be assumed to describe present conditions.';

            echo '<div style="margin-top:8px">';
            echo '<label>';
            echo '<input type="checkbox" name="allow_nonlive_weather" value="1" required> ';
            echo 'Start this research session anyway';
            echo '</label>';
            echo '</div>';

            echo '</td>';
            echo '</tr>';
        }

        echo '<tr><td colspan="2">';
        echo '<input type="submit" value="Start weather session">';
        echo '</form>';
        echo '</td></tr>';
    }

    echo '</table>';
}

//Temporary historical-observation test. Choose a completed weather session and retrieve its observations from Tempest.
$completedSessions = resultspack_weather_get_completed_sessions();

echo '<br>';
echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Historical observation test</th></tr>';

if (($_GET['imported'] ?? '') === '1') {
    $received = (int) ($_GET['received'] ?? 0);
    $added = (int) ($_GET['added'] ?? 0);

    echo '<tr><td colspan="2" style="color:green"><b>'
        . 'Import complete.</b> '
        . $received . ' observation'
        . ($received === 1 ? '' : 's')
        . ' received from Tempest; '
        . $added . ' new observation'
        . ($added === 1 ? '' : 's')
        . ' stored locally.'
        . '</td></tr>';
}

if (!$completedSessions) {
    echo '<tr><td colspan="2">No completed weather sessions are available yet.</td></tr>';
} else {
    $selectedHistorySessionId = isset($_GET['history_session_id'])
        ? (int) $_GET['history_session_id']
        : 0;

    $selectedHistorySession = null;

    if ($selectedHistorySessionId > 0) {
        $selectedHistorySession =
            resultspack_weather_get_completed_session($selectedHistorySessionId);
    }

    echo '<tr>';
    echo '<td class="Bold">Completed session</td>';
    echo '<td>';

    echo '<form method="get" action="TempestTest.php" style="margin:0">';
    echo '<select name="history_session_id">';
    echo '<option value="">Choose completed session...</option>';

    $tournamentListForHistory = resultspack_fetch_tournament_list();

    foreach ($completedSessions as $session) {
        $competitionName = 'Competition ' . $session['tournament_id'];

        foreach ($tournamentListForHistory as $tournament) {
            if ((int) $tournament['id'] === (int) $session['tournament_id']) {
                $competitionName =
                    ($tournament['code'] !== ''
                        ? $tournament['code'] . ' — '
                        : '')
                    . $tournament['name'];
                break;
            }
        }

        try {
            $sessionStart = new DateTime('@' . $session['started_epoch']);
            $sessionStart->setTimezone(
                new DateTimeZone($session['timezone'] ?: 'UTC')
            );

            $startLabel = $sessionStart->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            $startLabel = date(
                'd/m/Y H:i:s',
                $session['started_epoch']
            );
        }

        $durationSeconds =
            max(0, $session['ended_epoch'] - $session['started_epoch']);

        $durationMinutes = round($durationSeconds / 60, 1);

        $label =
            '#' . $session['id']
            . ' — ' . $competitionName
            . ' — ' . $startLabel
            . ' — ' . $durationMinutes . ' min';

        $selected =
            ((int) $session['id'] === $selectedHistorySessionId)
                ? ' selected'
                : '';

        echo '<option value="' . (int) $session['id'] . '"' . $selected . '>'
            . htmlspecialchars($label)
            . '</option>';
    }

    echo '</select> ';
    echo '<input type="submit" value="Load historical observations">';
    echo '</form>';

    echo '</td>';
    echo '</tr>';

    if ($selectedHistorySession) {
        $durationSeconds =
            max(
                0,
                $selectedHistorySession['ended_epoch']
                - $selectedHistorySession['started_epoch']
            );

        echo '<tr><td class="Bold">Session ID</td><td>'
            . (int) $selectedHistorySession['id']
            . '</td></tr>';

        echo '<tr><td class="Bold">Station</td><td>'
            . htmlspecialchars($selectedHistorySession['station_name'])
            . ' (' . (int) $selectedHistorySession['station_id'] . ')'
            . '</td></tr>';

        echo '<tr><td class="Bold">Session duration</td><td>'
            . htmlspecialchars(
                number_format($durationSeconds / 60, 1)
            )
            . ' minutes</td></tr>';

        $historyResponse = resultspack_weather_fetch_observations(
            $selectedHistorySession['station_id'],
            $selectedHistorySession['started_epoch'],
            $selectedHistorySession['ended_epoch']
        );

        if (!$historyResponse['ok']) {
            echo '<tr><td colspan="2"><b>Historical request failed:</b> '
                . htmlspecialchars($historyResponse['error'])
                . '</td></tr>';
        } else {
            $historyData = $historyResponse['data'];

            $historyFields = $historyData['ob_fields'] ?? array();
            $historyRows = $historyData['obs'] ?? array();

            echo '<tr><td class="Bold">Fields returned</td><td>'
                . htmlspecialchars((string) count($historyFields))
                . '</td></tr>';

            echo '<tr><td class="Bold">Observations returned</td><td>'
                . htmlspecialchars((string) count($historyRows))
                . '</td></tr>';

            $storedObservationCount =
            resultspack_weather_count_observations($selectedHistorySession['id']);

            echo '<tr><td class="Bold">Stored locally</td><td>'
                . (int) $storedObservationCount
                . '</td></tr>';

            $quality =
                resultspack_weather_session_quality($selectedHistorySession['id']);

            if ($quality && $quality['expected'] > 0) {
                echo '<tr><td class="Bold">Expected observations</td><td>'
                    . (int) $quality['expected']
                    . '</td></tr>';

                echo '<tr><td class="Bold">Data coverage</td><td>'
                    . htmlspecialchars(
                        number_format($quality['coverage_percent'], 1)
                    )
                    . '%</td></tr>';

                echo '<tr><td class="Bold">Missing observations</td><td>'
                    . (int) $quality['missing']
                    . '</td></tr>';

                echo '<tr><td class="Bold">Longest data gap</td><td>'
                    . (int) $quality['longest_gap_minutes']
                    . ' minute'
                    . ($quality['longest_gap_minutes'] === 1 ? '' : 's')
                    . '</td></tr>';
            }

            echo '<tr><td class="Bold">Import</td><td>';

            echo '<form method="post" action="WeatherImportAction.php" style="margin:0">';

            echo '<input type="hidden" name="csrf_token" value="'
                . htmlspecialchars(resultspack_csrf_token())
                . '">';

            echo '<input type="hidden" name="session_id" value="'
                . (int) $selectedHistorySession['id']
                . '">';

            echo '<input type="submit" value="Import observations into IANSEO">';

            echo '</form>';

            echo '</td></tr>';

            if ($historyRows) {
                $firstValues = reset($historyRows);
                $lastValues = end($historyRows);

                $firstObservation = array();
                $lastObservation = array();

                if (
                    count($historyFields) === count($firstValues)
                    && count($historyFields) === count($lastValues)
                ) {
                    $firstObservation =
                        array_combine($historyFields, $firstValues);

                    $lastObservation =
                        array_combine($historyFields, $lastValues);
                }

                if (!empty($firstObservation['timestamp'])) {
                    echo '<tr><td class="Bold">First observation</td><td>'
                        . htmlspecialchars(
                            date(
                                'Y-m-d H:i:s',
                                (int) $firstObservation['timestamp']
                            )
                        )
                        . '</td></tr>';
                }

                if (!empty($lastObservation['timestamp'])) {
                    echo '<tr><td class="Bold">Last observation</td><td>'
                        . htmlspecialchars(
                            date(
                                'Y-m-d H:i:s',
                                (int) $lastObservation['timestamp']
                            )
                        )
                        . '</td></tr>';
                }
            } else {
                echo '<tr><td colspan="2">'
                    . '<b>No observations fell inside this session window.</b> '
                    . 'Try a longer completed session.'
                    . '</td></tr>';
            }
        }
    } else {
        echo '<tr><td colspan="2">'
            . 'Choose one of the completed sessions above to test its '
            . 'historical Tempest data.'
            . '</td></tr>';
    }
}

echo '</table>';

//Weather session history.
$allWeatherSessions = resultspack_weather_get_sessions();

echo '<br>';
echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="8">Weather session history</th></tr>';

if (($_GET['session_updated'] ?? '') === '1') {
    echo '<tr><td colspan="8" style="color:green"><b>'
        . 'Weather session details updated.'
        . '</b></td></tr>';
}

if (!$allWeatherSessions) {
    echo '<tr><td colspan="8">No weather sessions recorded yet.</td></tr>';
} else {
    echo '<tr>';
    echo '<th class="Title">ID</th>';
    echo '<th class="Title">Started</th>';
    echo '<th class="Title">Session</th>';
    echo '<th class="Title">Research status</th>';
    echo '<th class="Title">Bearing</th>';
    echo '<th class="Title">Height</th>';
    echo '<th class="Title">Notes</th>';
    echo '<th class="Title">Edit</th>';
    echo '</tr>';

    foreach ($allWeatherSessions as $session) {
        try {
            $started = new DateTime('@' . $session['started_epoch']);
            $started->setTimezone(
                new DateTimeZone($session['timezone'] ?: 'UTC')
            );

            $startedLabel = $started->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            $startedLabel = date(
                'd/m/Y H:i:s',
                $session['started_epoch']
            );
        }

        $sessionState = $session['ended_epoch'] === null
            ? 'Active'
            : 'Completed';

        echo '<tr>';

        echo '<td>'
            . (int) $session['id']
            . '</td>';

        echo '<td>'
            . htmlspecialchars($startedLabel)
            . '</td>';

        echo '<td>'
            . htmlspecialchars($sessionState)
            . '</td>';

        echo '<td>'
            . htmlspecialchars(ucfirst($session['research_status']))
            . '</td>';

        echo '<td>'
            . ($session['shooting_bearing'] !== null
                ? htmlspecialchars((string) $session['shooting_bearing']) . '°'
                : 'Not recorded')
            . '</td>';

        echo '<td>'
            . ($session['sensor_height'] !== null
                ? htmlspecialchars((string) $session['sensor_height']) . ' m'
                : 'Not recorded')
            . '</td>';

        echo '<td>'
            . ($session['position_notes'] !== ''
                ? htmlspecialchars($session['position_notes'])
                : 'None')
            . '</td>';

        echo '<td>';

        echo '<form method="post" action="WeatherSessionEditAction.php">';

        echo '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(resultspack_csrf_token())
            . '">';

        echo '<input type="hidden" name="session_id" value="'
            . (int) $session['id']
            . '">';

        echo '<label>Bearing<br>';
        echo '<input type="number" name="shooting_bearing" min="0" max="359" step="1" value="'
            . htmlspecialchars((string) ($session['shooting_bearing'] ?? ''))
            . '">°';
        echo '</label>';

        echo '<br><br>';

        echo '<label>Height<br>';
        echo '<input type="number" name="sensor_height" min="0.1" max="20" step="0.01" value="'
            . htmlspecialchars((string) ($session['sensor_height'] ?? ''))
            . '"> m';
        echo '</label>';

        echo '<br><br>';

        echo '<label>Status<br>';
        echo '<select name="research_status">';

        foreach (
            array(
                'real' => 'Real',
                'test' => 'Test',
                'excluded' => 'Excluded',
            )
            as $value => $label
        ) {
            $selected =
                $session['research_status'] === $value
                    ? ' selected'
                    : '';

            echo '<option value="' . $value . '"' . $selected . '>'
                . $label
                . '</option>';
        }

        echo '</select>';
        echo '</label>';

        echo '<br><br>';

        echo '<label>Notes<br>';
        echo '<textarea name="position_notes" rows="2">'
            . htmlspecialchars($session['position_notes'])
            . '</textarea>';
        echo '</label>';

        echo '<br>';

        echo '<input type="submit" value="Save changes">';

        echo '</form>';

        echo '</td>';

        echo '</tr>';
    }
}

include('Common/Templates/tail.php');