<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

$PAGE_TITLE = 'Weather Session Viewer';

include('Common/Templates/head.php');

$completedSessions = resultspack_weather_get_completed_sessions();
$tournamentList = resultspack_fetch_tournament_list();

$sessionId = isset($_GET['session_id'])
    ? (int) $_GET['session_id']
    : 0;

if ($sessionId <= 0 && $completedSessions) {
    $sessionId = (int) $completedSessions[0]['id'];
}

$session = $sessionId > 0
    ? resultspack_weather_get_completed_session($sessionId)
    : null;

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Weather Session Viewer</th></tr>';

if (!$completedSessions) {
    echo '<tr><td colspan="2">No completed weather sessions are available yet.</td></tr>';
    echo '</table>';
    include('Common/Templates/tail.php');
    exit;
}

echo '<tr>';
echo '<td class="Bold">Session</td>';
echo '<td>';

echo '<form method="get" action="WeatherSessionView.php" style="margin:0">';
echo '<select name="session_id">';

foreach ($completedSessions as $candidate) {
    $competitionName = 'Competition ' . $candidate['tournament_id'];

    foreach ($tournamentList as $tournament) {
        if ((int) $tournament['id'] === (int) $candidate['tournament_id']) {
            $competitionName =
                ($tournament['code'] !== ''
                    ? $tournament['code'] . ' — '
                    : '')
                . $tournament['name'];

            break;
        }
    }

    $startLabel = resultspack_weather_format_timestamp(
        $candidate['started_epoch'],
        $candidate['timezone'],
        'd/m/Y H:i:s T'
    );

    $selected =
        (int) $candidate['id'] === $sessionId
            ? ' selected'
            : '';

    echo '<option value="' . (int) $candidate['id'] . '"' . $selected . '>'
        . htmlspecialchars(
            '#' . $candidate['id']
            . ' — ' . $competitionName
            . ' — ' . $startLabel
        )
        . '</option>';
}

echo '</select> ';
echo '<input type="submit" value="View session">';
echo '</form>';

echo '</td>';
echo '</tr>';

echo '</table>';

if (!$session) {
    echo '<p>Weather session not found.</p>';
    include('Common/Templates/tail.php');
    exit;
}

$observations = resultspack_weather_get_observations($session['id']);
$quality = resultspack_weather_session_quality($session['id']);
$events = resultspack_weather_get_events($session['id']);
$timingCorrections = resultspack_weather_get_timing_corrections($session['id']);

$competitionName = 'Competition ' . $session['tournament_id'];

foreach ($tournamentList as $tournament) {
    if ((int) $tournament['id'] === (int) $session['tournament_id']) {
        $competitionName =
            ($tournament['code'] !== ''
                ? $tournament['code'] . ' — '
                : '')
            . $tournament['name'];

        break;
    }
}

$startLabel = resultspack_weather_format_timestamp(
    $session['started_epoch'],
    $session['timezone'],
    'd/m/Y H:i:s T'
);

$endLabel = resultspack_weather_format_timestamp(
    $session['ended_epoch'],
    $session['timezone'],
    'd/m/Y H:i:s T'
);

$durationMinutes = round(
    max(
        0,
        $session['ended_epoch'] - $session['started_epoch']
    ) / 60,
    1
);

$minTemp = null;
$maxTemp = null;
$maxGust = null;
$maxGustTimestamp = null;
$totalLightning = 0;

foreach ($observations as $observation) {
    if ($observation['air_temp'] !== null) {
        if ($minTemp === null || $observation['air_temp'] < $minTemp) {
            $minTemp = $observation['air_temp'];
        }

        if ($maxTemp === null || $observation['air_temp'] > $maxTemp) {
            $maxTemp = $observation['air_temp'];
        }
    }

    if (
        $observation['wind_gust'] !== null
        && ($maxGust === null || $observation['wind_gust'] > $maxGust)
    ) {
        $maxGust = $observation['wind_gust'];
        $maxGustTimestamp = $observation['timestamp'];
    }

    if ($observation['strike_count'] !== null) {
        $totalLightning += (int) $observation['strike_count'];
    }
}

//Timing correction and audit trail.
echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Session timing correction</th></tr>';

if (($_GET['timing_corrected'] ?? '') === '1') {
    echo '<tr><td colspan="2" style="color:green"><b>';

    if (($_GET['timing_changed'] ?? '') === '1') {
        echo 'Session timing corrected.';
    } else {
        echo 'Session timing was unchanged.';
    }

    if (($_GET['timing_import'] ?? '') === 'ok') {
        $addedAfterCorrection = (int) ($_GET['added'] ?? 0);
        $coverageAfterCorrection = $_GET['coverage'] ?? null;
        $missingAfterCorrection = (int) ($_GET['missing'] ?? 0);

        echo ' Historical weather was re-imported automatically; '
            . $addedAfterCorrection . ' new observation'
            . ($addedAfterCorrection === 1 ? '' : 's')
            . ' added.';

        if ($coverageAfterCorrection !== null) {
            echo ' Coverage is now '
                . htmlspecialchars((string) $coverageAfterCorrection)
                . '%.';

            if ($missingAfterCorrection > 0) {
                echo ' ' . $missingAfterCorrection . ' observation'
                    . ($missingAfterCorrection === 1 ? '' : 's')
                    . ' remain pending/missing; the import can safely be retried later.';
            }
        }
    }

    echo '</b></td></tr>';

    if (($_GET['timing_import'] ?? '') === 'failed') {
        echo '<tr><td colspan="2" style="background:#fff3cd">'
            . '<b>The timing correction was saved, but the automatic Tempest re-import failed.</b> '
            . htmlspecialchars((string) ($_GET['import_error'] ?? 'Unknown import error.'))
            . ' The import can be retried later without duplicating observations.'
            . '</td></tr>';
    }
}

$startInputValue = resultspack_weather_format_timestamp(
    $session['started_epoch'],
    $session['timezone'],
    'Y-m-d\\TH:i:s'
);

$endInputValue = resultspack_weather_format_timestamp(
    $session['ended_epoch'],
    $session['timezone'],
    'Y-m-d\\TH:i:s'
);

echo '<tr><td colspan="2">'
    . 'Use this if Start or Stop was pressed late. Times are entered in '
    . '<b>' . htmlspecialchars($session['timezone']) . '</b>. '
    . 'The original values are kept in the audit trail, and saving automatically '
    . 're-runs the historical Tempest import.'
    . '</td></tr>';

echo '<form method="post" action="WeatherSessionTimingAction.php">';
echo '<input type="hidden" name="csrf_token" value="'
    . htmlspecialchars(resultspack_csrf_token())
    . '">';
echo '<input type="hidden" name="session_id" value="'
    . (int) $session['id']
    . '">';

echo '<tr><td class="Bold">Corrected start</td><td>'
    . '<input type="datetime-local" name="started_local" step="1" required value="'
    . htmlspecialchars($startInputValue)
    . '"></td></tr>';

echo '<tr><td class="Bold">Corrected end</td><td>'
    . '<input type="datetime-local" name="ended_local" step="1" required value="'
    . htmlspecialchars($endInputValue)
    . '"></td></tr>';

echo '<tr><td class="Bold">Reason for correction</td><td>'
    . '<textarea name="correction_reason" rows="2" required '
    . 'placeholder="For example: forgot to start the weather session until 09:47; shooting began at 09:30."></textarea>'
    . '</td></tr>';

echo '<tr><td colspan="2">'
    . '<input type="submit" value="Save timing correction and re-import weather">'
    . '</td></tr>';
echo '</form>';

echo '</table>';

if ($timingCorrections) {
    echo '<br>';
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main" colspan="6">Timing correction audit trail</th></tr>';
    echo '<tr>';
    echo '<th class="Title">Recorded</th>';
    echo '<th class="Title">Old start</th>';
    echo '<th class="Title">Old end</th>';
    echo '<th class="Title">New start</th>';
    echo '<th class="Title">New end</th>';
    echo '<th class="Title">Reason</th>';
    echo '</tr>';

    foreach ($timingCorrections as $correction) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($correction['created']) . '</td>';
        echo '<td>' . htmlspecialchars(
            resultspack_weather_format_timestamp(
                $correction['old_started_epoch'],
                $correction['timezone'],
                'd/m/Y H:i:s T'
            )
        ) . '</td>';
        echo '<td>' . htmlspecialchars(
            resultspack_weather_format_timestamp(
                $correction['old_ended_epoch'],
                $correction['timezone'],
                'd/m/Y H:i:s T'
            )
        ) . '</td>';
        echo '<td>' . htmlspecialchars(
            resultspack_weather_format_timestamp(
                $correction['new_started_epoch'],
                $correction['timezone'],
                'd/m/Y H:i:s T'
            )
        ) . '</td>';
        echo '<td>' . htmlspecialchars(
            resultspack_weather_format_timestamp(
                $correction['new_ended_epoch'],
                $correction['timezone'],
                'd/m/Y H:i:s T'
            )
        ) . '</td>';
        echo '<td>' . nl2br(htmlspecialchars($correction['reason'])) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
}

//Wind graph.
echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main">Wind across the session</th></tr>';
echo '<tr><td>';

$windObservations = array();

foreach ($observations as $observation) {
    if (
        $observation['timestamp'] > 0
        && (
            $observation['wind_avg'] !== null
            || $observation['wind_gust'] !== null
        )
    ) {
        $windObservations[] = $observation;
    }
}

if (count($windObservations) < 2) {
    echo 'Not enough locally stored wind observations to draw a graph.';
} else {
    //SVG dimensions
    $graphWidth = 1000;
    $graphHeight = 360;

    $marginLeft = 70;
    $marginRight = 25;
    $marginTop = 30;
    $marginBottom = 55;

    $plotWidth =
        $graphWidth - $marginLeft - $marginRight;

    $plotHeight =
        $graphHeight - $marginTop - $marginBottom;

    //Work out the time range
    $firstTimestamp =
        (int) $windObservations[0]['timestamp'];

    $lastTimestamp =
        (int) $windObservations[count($windObservations) - 1]['timestamp'];

    $timeRange =
        max(1, $lastTimestamp - $firstTimestamp);

    //Find the largest wind value so that both average and gust fit on the same scale
    $maximumWind = 0;

    foreach ($windObservations as $observation) {
        if (
            $observation['wind_avg'] !== null
            && $observation['wind_avg'] > $maximumWind
        ) {
            $maximumWind = $observation['wind_avg'];
        }

        if (
            $observation['wind_gust'] !== null
            && $observation['wind_gust'] > $maximumWind
        ) {
            $maximumWind = $observation['wind_gust'];
        }
    }

    //Give the graph a sensible upper limit rather than putting the highest gust exactly against the top edge
    $yMaximum =
        max(
            5,
            ceil(($maximumWind * 1.10) / 5) * 5
        );

    $averagePoints = array();
    $gustPoints = array();

    foreach ($windObservations as $observation) {
        $x =
            $marginLeft
            + (
                (
                    $observation['timestamp']
                    - $firstTimestamp
                )
                / $timeRange
            )
            * $plotWidth;

        if ($observation['wind_avg'] !== null) {
            $y =
                $marginTop
                + $plotHeight
                - (
                    ($observation['wind_avg'] / $yMaximum)
                    * $plotHeight
                );

            $averagePoints[] =
                round($x, 2) . ',' . round($y, 2);
        }

        if ($observation['wind_gust'] !== null) {
            $y =
                $marginTop
                + $plotHeight
                - (
                    ($observation['wind_gust'] / $yMaximum)
                    * $plotHeight
                );

            $gustPoints[] =
                round($x, 2) . ',' . round($y, 2);
        }
    }

    echo '<div style="max-width:1100px">';

    echo '<svg '
        . 'viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:1px solid #ccc" '
        . 'role="img" '
        . 'aria-label="Average wind and gust speed across the weather session">';

    //Horizontal grid lines and Y-axis labels
    for ($step = 0; $step <= 4; $step++) {
        $value =
            $yMaximum * ($step / 4);

        $y =
            $marginTop
            + $plotHeight
            - (($value / $yMaximum) * $plotHeight);

        echo '<line '
            . 'x1="' . $marginLeft . '" '
            . 'y1="' . round($y, 2) . '" '
            . 'x2="' . ($graphWidth - $marginRight) . '" '
            . 'y2="' . round($y, 2) . '" '
            . 'stroke="#dddddd" '
            . 'stroke-width="1" />';

        echo '<text '
            . 'x="' . ($marginLeft - 10) . '" '
            . 'y="' . (round($y, 2) + 5) . '" '
            . 'text-anchor="end" '
            . 'font-size="14">'
            . htmlspecialchars(
                number_format($value, 1)
            )
            . '</text>';
    }

    //Y axis title
    echo '<text '
        . 'x="18" '
        . 'y="' . ($marginTop + ($plotHeight / 2)) . '" '
        . 'transform="rotate(-90 18 '
        . ($marginTop + ($plotHeight / 2))
        . ')" '
        . 'text-anchor="middle" '
        . 'font-size="14">'
        . 'Wind speed (mph)'
        . '</text>';

    //Start, middle and end time labels
    $timeLabels = array(
        $firstTimestamp,
        (int) round(
            $firstTimestamp + ($timeRange / 2)
        ),
        $lastTimestamp,
    );

    foreach ($timeLabels as $index => $timestamp) {
        $x =
            $marginLeft
            + (
                (($timestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        $anchor =
            $index === 0
                ? 'start'
                : ($index === 2 ? 'end' : 'middle');

        echo '<text '
            . 'x="' . round($x, 2) . '" '
            . 'y="' . ($graphHeight - 20) . '" '
            . 'text-anchor="' . $anchor . '" '
            . 'font-size="14">'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $timestamp,
                    $session['timezone'],
                    'H:i'
                )
            )
            . '</text>';
    }

    //Plot lines
    if ($averagePoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $averagePoints) . '" '
            . 'fill="none" '
            . 'stroke="#1565c0" '
            . 'stroke-width="3" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    if ($gustPoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $gustPoints) . '" '
            . 'fill="none" '
            . 'stroke="#c62828" '
            . 'stroke-width="3" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    //Judge-decision markers
    $eventMarkerNumber = 0;

    foreach ($events as $event) {
        $eventTimestamp = (int) $event['timestamp'];

        //Only plot events which actually fall inside the observation window shown by this graph
        if (
            $eventTimestamp < $firstTimestamp
            || $eventTimestamp > $lastTimestamp
        ) {
            continue;
        }

        $eventX =
            $marginLeft
            + (
                (($eventTimestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        //Give different event types distinct marker colours.
        switch ($event['action']) {
            case 'suspend':
                $eventColour = '#c62828';
                break;

            case 'resume':
                $eventColour = '#2e7d32';
                break;

            case 'abandon':
                $eventColour = '#6a1b9a';
                break;

            case 'delay':
                $eventColour = '#ef6c00';
                break;

            default:
                $eventColour = '#555555';
                break;
        }

        //Alternate label height slightly so closely spaced events are less likely to sit directly on top of one another
        $labelY =
            $marginTop
            + 18
            + (($eventMarkerNumber % 3) * 18);

        //Vertical event marker
        echo '<line '
            . 'x1="' . round($eventX, 2) . '" '
            . 'y1="' . $marginTop . '" '
            . 'x2="' . round($eventX, 2) . '" '
            . 'y2="' . ($marginTop + $plotHeight) . '" '
            . 'stroke="' . $eventColour . '" '
            . 'stroke-width="2" '
            . 'stroke-dasharray="6,4" />';

        //Event label
        $eventLabel =
            strtoupper($event['action'])
            . ' '
            . resultspack_weather_format_timestamp(
                $eventTimestamp,
                $session['timezone'],
                'H:i:s'
            );

        echo '<text '
            . 'x="' . (round($eventX, 2) + 5) . '" '
            . 'y="' . $labelY . '" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="' . $eventColour . '">'
            . htmlspecialchars($eventLabel)
            . '</text>';

        $eventMarkerNumber++;
    }

    echo '</svg>';

    //Legend.
    echo '<div style="margin-top:8px">';

    echo '<span style="margin-right:20px">'
        . '<span style="display:inline-block;width:24px;'
        . 'border-top:3px solid #1565c0;vertical-align:middle;'
        . 'margin-right:6px"></span>'
        . 'Average wind'
        . '</span>';

    echo '<span>'
        . '<span style="display:inline-block;width:24px;'
        . 'border-top:3px solid #c62828;vertical-align:middle;'
        . 'margin-right:6px"></span>'
        . 'Gust'
        . '</span>';

    echo '</div>';

    echo '</div>';
}

echo '</td></tr>';
echo '</table>';

//Temperature graph.
echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main">Temperature across the session</th></tr>';
echo '<tr><td>';

$temperatureObservations = array();

foreach ($observations as $observation) {
    if (
        $observation['timestamp'] > 0
        && $observation['air_temp'] !== null
    ) {
        $temperatureObservations[] = $observation;
    }
}

if (count($temperatureObservations) < 2) {
    echo 'Not enough locally stored temperature observations to draw a graph.';
} else {
    $graphWidth = 1000;
    $graphHeight = 360;

    $marginLeft = 70;
    $marginRight = 25;
    $marginTop = 30;
    $marginBottom = 55;

    $plotWidth =
        $graphWidth - $marginLeft - $marginRight;

    $plotHeight =
        $graphHeight - $marginTop - $marginBottom;

    $firstTimestamp =
        (int) $temperatureObservations[0]['timestamp'];

    $lastTimestamp =
        (int) $temperatureObservations[count($temperatureObservations) - 1]['timestamp'];

    $timeRange =
        max(1, $lastTimestamp - $firstTimestamp);

    $minimumTemperature = null;
    $maximumTemperature = null;

    foreach ($temperatureObservations as $observation) {
        $temperature = (float) $observation['air_temp'];

        if (
            $minimumTemperature === null
            || $temperature < $minimumTemperature
        ) {
            $minimumTemperature = $temperature;
        }

        if (
            $maximumTemperature === null
            || $temperature > $maximumTemperature
        ) {
            $maximumTemperature = $temperature;
        }
    }

    /*
     * Temperature does not have a meaningful zero baseline for this purpose,
     * so use a padded local scale. Keep at least a 5 C span so tiny changes
     * are not visually exaggerated.
     */
    $temperatureSpan =
        max(0.1, $maximumTemperature - $minimumTemperature);

    $temperaturePadding =
        max(0.5, $temperatureSpan * 0.15);

    $yMinimum =
        floor(($minimumTemperature - $temperaturePadding) * 2) / 2;

    $yMaximum =
        ceil(($maximumTemperature + $temperaturePadding) * 2) / 2;

    if (($yMaximum - $yMinimum) < 5) {
        $midpoint = ($yMaximum + $yMinimum) / 2;
        $yMinimum = floor(($midpoint - 2.5) * 2) / 2;
        $yMaximum = ceil(($midpoint + 2.5) * 2) / 2;
    }

    $yRange = max(0.1, $yMaximum - $yMinimum);

    $temperaturePoints = array();

    foreach ($temperatureObservations as $observation) {
        $x =
            $marginLeft
            + (
                (($observation['timestamp'] - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        $y =
            $marginTop
            + $plotHeight
            - (
                (($observation['air_temp'] - $yMinimum) / $yRange)
                * $plotHeight
            );

        $temperaturePoints[] =
            round($x, 2) . ',' . round($y, 2);
    }

    echo '<div style="max-width:1100px">';

    echo '<svg '
        . 'viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:1px solid #ccc" '
        . 'role="img" '
        . 'aria-label="Temperature across the weather session">';

    for ($step = 0; $step <= 4; $step++) {
        $value =
            $yMinimum
            + (($yMaximum - $yMinimum) * ($step / 4));

        $y =
            $marginTop
            + $plotHeight
            - ((($value - $yMinimum) / $yRange) * $plotHeight);

        echo '<line '
            . 'x1="' . $marginLeft . '" '
            . 'y1="' . round($y, 2) . '" '
            . 'x2="' . ($graphWidth - $marginRight) . '" '
            . 'y2="' . round($y, 2) . '" '
            . 'stroke="#dddddd" '
            . 'stroke-width="1" />';

        echo '<text '
            . 'x="' . ($marginLeft - 10) . '" '
            . 'y="' . (round($y, 2) + 5) . '" '
            . 'text-anchor="end" '
            . 'font-size="14">'
            . htmlspecialchars(number_format($value, 1))
            . '</text>';
    }

    echo '<text '
        . 'x="18" '
        . 'y="' . ($marginTop + ($plotHeight / 2)) . '" '
        . 'transform="rotate(-90 18 '
        . ($marginTop + ($plotHeight / 2))
        . ')" '
        . 'text-anchor="middle" '
        . 'font-size="14">'
        . 'Temperature (°C)'
        . '</text>';

    $timeLabels = array(
        $firstTimestamp,
        (int) round($firstTimestamp + ($timeRange / 2)),
        $lastTimestamp,
    );

    foreach ($timeLabels as $index => $timestamp) {
        $x =
            $marginLeft
            + (
                (($timestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        $anchor =
            $index === 0
                ? 'start'
                : ($index === 2 ? 'end' : 'middle');

        echo '<text '
            . 'x="' . round($x, 2) . '" '
            . 'y="' . ($graphHeight - 20) . '" '
            . 'text-anchor="' . $anchor . '" '
            . 'font-size="14">'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $timestamp,
                    $session['timezone'],
                    'H:i'
                )
            )
            . '</text>';
    }

    echo '<polyline '
        . 'points="' . implode(' ', $temperaturePoints) . '" '
        . 'fill="none" '
        . 'stroke="#ad1457" '
        . 'stroke-width="3" '
        . 'stroke-linejoin="round" '
        . 'stroke-linecap="round" />';

    //Judge-decision markers.
    $eventMarkerNumber = 0;

    foreach ($events as $event) {
        $eventTimestamp = (int) $event['timestamp'];

        if (
            $eventTimestamp < $firstTimestamp
            || $eventTimestamp > $lastTimestamp
        ) {
            continue;
        }

        $eventX =
            $marginLeft
            + (
                (($eventTimestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        switch ($event['action']) {
            case 'suspend':
                $eventColour = '#c62828';
                break;

            case 'resume':
                $eventColour = '#2e7d32';
                break;

            case 'abandon':
                $eventColour = '#6a1b9a';
                break;

            case 'delay':
                $eventColour = '#ef6c00';
                break;

            default:
                $eventColour = '#555555';
                break;
        }

        $labelY =
            $marginTop
            + 18
            + (($eventMarkerNumber % 3) * 18);

        echo '<line '
            . 'x1="' . round($eventX, 2) . '" '
            . 'y1="' . $marginTop . '" '
            . 'x2="' . round($eventX, 2) . '" '
            . 'y2="' . ($marginTop + $plotHeight) . '" '
            . 'stroke="' . $eventColour . '" '
            . 'stroke-width="2" '
            . 'stroke-dasharray="6,4" />';

        $eventLabel =
            strtoupper($event['action'])
            . ' '
            . resultspack_weather_format_timestamp(
                $eventTimestamp,
                $session['timezone'],
                'H:i:s'
            );

        echo '<text '
            . 'x="' . (round($eventX, 2) + 5) . '" '
            . 'y="' . $labelY . '" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="' . $eventColour . '">'
            . htmlspecialchars($eventLabel)
            . '</text>';

        $eventMarkerNumber++;
    }

    echo '</svg>';

    echo '<div style="margin-top:8px">';
    echo '<span>'
        . '<span style="display:inline-block;width:24px;'
        . 'border-top:3px solid #ad1457;vertical-align:middle;'
        . 'margin-right:6px"></span>'
        . 'Temperature'
        . '</span>';
    echo '</div>';

    echo '</div>';
}

echo '</td></tr>';
echo '</table>';

//Humidity graph.
echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main">Humidity across the session</th></tr>';
echo '<tr><td>';

$humidityObservations = array();

foreach ($observations as $observation) {
    if (
        $observation['timestamp'] > 0
        && $observation['humidity'] !== null
    ) {
        $humidityObservations[] = $observation;
    }
}

if (count($humidityObservations) < 2) {
    echo 'Not enough locally stored humidity observations to draw a graph.';
} else {
    $graphWidth = 1000;
    $graphHeight = 360;

    $marginLeft = 70;
    $marginRight = 25;
    $marginTop = 30;
    $marginBottom = 55;

    $plotWidth =
        $graphWidth - $marginLeft - $marginRight;

    $plotHeight =
        $graphHeight - $marginTop - $marginBottom;

    $firstTimestamp =
        (int) $humidityObservations[0]['timestamp'];

    $lastTimestamp =
        (int) $humidityObservations[count($humidityObservations) - 1]['timestamp'];

    $timeRange =
        max(1, $lastTimestamp - $firstTimestamp);

    $minimumHumidity = null;
    $maximumHumidity = null;

    foreach ($humidityObservations as $observation) {
        $humidity = (float) $observation['humidity'];

        if (
            $minimumHumidity === null
            || $humidity < $minimumHumidity
        ) {
            $minimumHumidity = $humidity;
        }

        if (
            $maximumHumidity === null
            || $humidity > $maximumHumidity
        ) {
            $maximumHumidity = $humidity;
        }
    }

    /*
     * Use a readable local scale but keep at least a 20 percentage-point
     * window so small fluctuations are not visually exaggerated.
     */
    $humiditySpan =
        max(1, $maximumHumidity - $minimumHumidity);

    $humidityPadding =
        max(5, $humiditySpan * 0.15);

    $yMinimum =
        max(0, floor(($minimumHumidity - $humidityPadding) / 5) * 5);

    $yMaximum =
        min(100, ceil(($maximumHumidity + $humidityPadding) / 5) * 5);

    if (($yMaximum - $yMinimum) < 20) {
        $midpoint = ($maximumHumidity + $minimumHumidity) / 2;
        $yMinimum = max(0, floor(($midpoint - 10) / 5) * 5);
        $yMaximum = min(100, ceil(($midpoint + 10) / 5) * 5);

        if (($yMaximum - $yMinimum) < 20) {
            if ($yMinimum <= 0) {
                $yMaximum = min(100, 20);
            } elseif ($yMaximum >= 100) {
                $yMinimum = max(0, 80);
            }
        }
    }

    $yRange = max(1, $yMaximum - $yMinimum);

    $humidityPoints = array();

    foreach ($humidityObservations as $observation) {
        $x =
            $marginLeft
            + (
                (($observation['timestamp'] - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        $y =
            $marginTop
            + $plotHeight
            - (
                (($observation['humidity'] - $yMinimum) / $yRange)
                * $plotHeight
            );

        $humidityPoints[] =
            round($x, 2) . ',' . round($y, 2);
    }

    echo '<div style="max-width:1100px">';

    echo '<svg '
        . 'viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:1px solid #ccc" '
        . 'role="img" '
        . 'aria-label="Relative humidity across the weather session">';

    for ($step = 0; $step <= 4; $step++) {
        $value =
            $yMinimum
            + (($yMaximum - $yMinimum) * ($step / 4));

        $y =
            $marginTop
            + $plotHeight
            - ((($value - $yMinimum) / $yRange) * $plotHeight);

        echo '<line '
            . 'x1="' . $marginLeft . '" '
            . 'y1="' . round($y, 2) . '" '
            . 'x2="' . ($graphWidth - $marginRight) . '" '
            . 'y2="' . round($y, 2) . '" '
            . 'stroke="#dddddd" '
            . 'stroke-width="1" />';

        echo '<text '
            . 'x="' . ($marginLeft - 10) . '" '
            . 'y="' . (round($y, 2) + 5) . '" '
            . 'text-anchor="end" '
            . 'font-size="14">'
            . htmlspecialchars(number_format($value, 0))
            . '</text>';
    }

    echo '<text '
        . 'x="18" '
        . 'y="' . ($marginTop + ($plotHeight / 2)) . '" '
        . 'transform="rotate(-90 18 '
        . ($marginTop + ($plotHeight / 2))
        . ')" '
        . 'text-anchor="middle" '
        . 'font-size="14">'
        . 'Relative humidity (%)'
        . '</text>';

    $timeLabels = array(
        $firstTimestamp,
        (int) round($firstTimestamp + ($timeRange / 2)),
        $lastTimestamp,
    );

    foreach ($timeLabels as $index => $timestamp) {
        $x =
            $marginLeft
            + (
                (($timestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        $anchor =
            $index === 0
                ? 'start'
                : ($index === 2 ? 'end' : 'middle');

        echo '<text '
            . 'x="' . round($x, 2) . '" '
            . 'y="' . ($graphHeight - 20) . '" '
            . 'text-anchor="' . $anchor . '" '
            . 'font-size="14">'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $timestamp,
                    $session['timezone'],
                    'H:i'
                )
            )
            . '</text>';
    }

    echo '<polyline '
        . 'points="' . implode(' ', $humidityPoints) . '" '
        . 'fill="none" '
        . 'stroke="#00838f" '
        . 'stroke-width="3" '
        . 'stroke-linejoin="round" '
        . 'stroke-linecap="round" />';

    //Judge-decision markers.
    $eventMarkerNumber = 0;

    foreach ($events as $event) {
        $eventTimestamp = (int) $event['timestamp'];

        if (
            $eventTimestamp < $firstTimestamp
            || $eventTimestamp > $lastTimestamp
        ) {
            continue;
        }

        $eventX =
            $marginLeft
            + (
                (($eventTimestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        switch ($event['action']) {
            case 'suspend':
                $eventColour = '#c62828';
                break;

            case 'resume':
                $eventColour = '#2e7d32';
                break;

            case 'abandon':
                $eventColour = '#6a1b9a';
                break;

            case 'delay':
                $eventColour = '#ef6c00';
                break;

            default:
                $eventColour = '#555555';
                break;
        }

        $labelY =
            $marginTop
            + 18
            + (($eventMarkerNumber % 3) * 18);

        echo '<line '
            . 'x1="' . round($eventX, 2) . '" '
            . 'y1="' . $marginTop . '" '
            . 'x2="' . round($eventX, 2) . '" '
            . 'y2="' . ($marginTop + $plotHeight) . '" '
            . 'stroke="' . $eventColour . '" '
            . 'stroke-width="2" '
            . 'stroke-dasharray="6,4" />';

        $eventLabel =
            strtoupper($event['action'])
            . ' '
            . resultspack_weather_format_timestamp(
                $eventTimestamp,
                $session['timezone'],
                'H:i:s'
            );

        echo '<text '
            . 'x="' . (round($eventX, 2) + 5) . '" '
            . 'y="' . $labelY . '" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="' . $eventColour . '">'
            . htmlspecialchars($eventLabel)
            . '</text>';

        $eventMarkerNumber++;
    }

    echo '</svg>';

    echo '<div style="margin-top:8px">';
    echo '<span>'
        . '<span style="display:inline-block;width:24px;'
        . 'border-top:3px solid #00838f;vertical-align:middle;'
        . 'margin-right:6px"></span>'
        . 'Relative humidity'
        . '</span>';
    echo '</div>';

    echo '</div>';
}

echo '</td></tr>';
echo '</table>';

echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Session summary</th></tr>';

echo '<tr><td class="Bold">Competition</td><td>'
    . htmlspecialchars($competitionName)
    . '</td></tr>';

echo '<tr><td class="Bold">Research status</td><td>'
    . htmlspecialchars(ucfirst($session['research_status']))
    . '</td></tr>';

echo '<tr><td class="Bold">Station</td><td>'
    . htmlspecialchars($session['station_name'])
    . ' (' . (int) $session['station_id'] . ')'
    . '</td></tr>';

echo '<tr><td class="Bold">Started</td><td>'
    . htmlspecialchars($startLabel)
    . '</td></tr>';

echo '<tr><td class="Bold">Ended</td><td>'
    . htmlspecialchars($endLabel)
    . '</td></tr>';

echo '<tr><td class="Bold">Duration</td><td>'
    . htmlspecialchars((string) $durationMinutes)
    . ' minutes</td></tr>';

echo '<tr><td class="Bold">Timezone</td><td>'
    . htmlspecialchars($session['timezone'])
    . '</td></tr>';

echo '<tr><td class="Bold">Shooting bearing</td><td>'
    . ($session['shooting_bearing'] !== null
        ? htmlspecialchars((string) $session['shooting_bearing']) . '°'
        : 'Not recorded')
    . '</td></tr>';

echo '<tr><td class="Bold">Sensor height</td><td>'
    . ($session['sensor_height'] !== null
        ? htmlspecialchars((string) $session['sensor_height']) . ' m'
        : 'Not recorded')
    . '</td></tr>';

echo '<tr><td class="Bold">Position notes</td><td>'
    . ($session['position_notes'] !== ''
        ? nl2br(htmlspecialchars($session['position_notes']))
        : 'None')
    . '</td></tr>';

echo '<tr><td class="Bold">Observations stored</td><td>'
    . count($observations)
    . '</td></tr>';

if ($quality) {
    echo '<tr><td class="Bold">Data coverage</td><td>'
        . htmlspecialchars(
            number_format($quality['coverage_percent'], 1)
        )
        . '%</td></tr>';

    echo '<tr><td class="Bold">Missing observations</td><td>'
        . (int) $quality['missing']
        . '</td></tr>';
}

echo '<tr><td class="Bold">Temperature range</td><td>';

if ($minTemp !== null && $maxTemp !== null) {
    echo resultspack_weather_format_number($minTemp, 1)
        . '–'
        . resultspack_weather_format_number($maxTemp, 1)
        . ' °C';
} else {
    echo 'Not available';
}

echo '</td></tr>';

echo '<tr><td class="Bold">Maximum gust</td><td>';

if ($maxGust !== null) {
    echo resultspack_weather_format_number($maxGust, 1)
        . ' mph';

    if ($maxGustTimestamp !== null) {
        echo ' at '
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $maxGustTimestamp,
                    $session['timezone'],
                    'H:i:s T'
                )
            );
    }
} else {
    echo 'Not available';
}

echo '</td></tr>';

echo '<tr><td class="Bold">Lightning strikes recorded</td><td>'
    . (int) $totalLightning
    . '</td></tr>';

echo '</table>';

echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Find weather at a specific time</th></tr>';

echo '<tr>';
echo '<td class="Bold">Date and time</td>';
echo '<td>';

echo '<form method="get" action="WeatherSessionView.php" style="margin:0">';

echo '<input type="hidden" name="session_id" value="'
    . (int) $session['id']
    . '">';

echo '<input type="datetime-local" name="at" value="'
    . htmlspecialchars((string) ($_GET['at'] ?? ''))
    . '"> ';

echo '<input type="submit" value="Find nearest observation">';

echo '</form>';

echo '</td>';
echo '</tr>';

$requestedTime = trim((string) ($_GET['at'] ?? ''));

if ($requestedTime !== '' && $observations) {
    try {
        $timezone = new DateTimeZone(
            $session['timezone'] ?: resultspack_weather_timezone()
        );

        $requestedDate = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $requestedTime,
            $timezone
        );

        if ($requestedDate) {
            $requestedEpoch = $requestedDate->getTimestamp();

            $nearestObservation = null;
            $nearestDifference = null;

            foreach ($observations as $observation) {
                $difference = abs(
                    $observation['timestamp'] - $requestedEpoch
                );

                if (
                    $nearestDifference === null
                    || $difference < $nearestDifference
                ) {
                    $nearestDifference = $difference;
                    $nearestObservation = $observation;
                }
            }

            if ($nearestObservation) {
                echo '<tr><td class="Bold">Nearest observation</td><td>';

                echo '<b>'
                    . htmlspecialchars(
                        resultspack_weather_format_timestamp(
                            $nearestObservation['timestamp'],
                            $session['timezone'],
                            'd/m/Y H:i:s T'
                        )
                    )
                    . '</b>';

                echo ' — '
                    . (int) $nearestDifference
                    . ' second'
                    . ($nearestDifference === 1 ? '' : 's')
                    . ' from requested time';

                echo '</td></tr>';

                echo '<tr><td class="Bold">Temperature</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['air_temp'],
                        1
                    )
                    . ' °C</td></tr>';

                echo '<tr><td class="Bold">Humidity</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['humidity'],
                        0
                    )
                    . ' %</td></tr>';

                echo '<tr><td class="Bold">Wind average</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['wind_avg'],
                        1
                    )
                    . ' mph</td></tr>';

                echo '<tr><td class="Bold">Wind gust</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['wind_gust'],
                        1
                    )
                    . ' mph</td></tr>';

                echo '<tr><td class="Bold">Wind direction</td><td>';

                if ($nearestObservation['wind_dir'] !== null) {
                    echo htmlspecialchars(
                        resultspack_weather_compass_direction(
                            $nearestObservation['wind_dir']
                        )
                    )
                    . ' ('
                    . resultspack_weather_format_number(
                        $nearestObservation['wind_dir'],
                        0
                    )
                    . '°)';

                    if ($session['shooting_bearing'] !== null) {
                        $relativeWind =
                            resultspack_weather_relative_wind(
                                $nearestObservation['wind_dir'],
                                $session['shooting_bearing']
                            );

                        echo ' — '
                            . htmlspecialchars($relativeWind['label']);
                    }
                } else {
                    echo 'Not available';
                }

                echo '</td></tr>';

                echo '<tr><td class="Bold">Rain during observation</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['precip_accumulation'],
                        2
                    )
                    . ' mm</td></tr>';

                echo '<tr><td class="Bold">Lightning strikes</td><td>'
                    . htmlspecialchars(
                        (string) (
                            $nearestObservation['strike_count'] ?? 0
                        )
                    )
                    . '</td></tr>';
            }
        }
    } catch (Exception $e) {
        echo '<tr><td colspan="2">Could not interpret that date and time.</td></tr>';
    }
}

echo '</table>';

echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="9">Minute-by-minute observations</th></tr>';

echo '<tr>';
echo '<th class="Title">Time</th>';
echo '<th class="Title">Temp</th>';
echo '<th class="Title">Humidity</th>';
echo '<th class="Title">Wind lull</th>';
echo '<th class="Title">Wind avg</th>';
echo '<th class="Title">Wind gust</th>';
echo '<th class="Title">Direction</th>';
echo '<th class="Title">Rain</th>';
echo '<th class="Title">Lightning</th>';
echo '</tr>';

if (!$observations) {
    echo '<tr><td colspan="9">No locally stored observations are available for this session.</td></tr>';
} else {
    foreach ($observations as $observation) {
        echo '<tr>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $observation['timestamp'],
                    $session['timezone'],
                    'H:i:s'
                )
            )
            . '</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['air_temp'],
                1
            )
            . ' °C</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['humidity'],
                0
            )
            . ' %</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['wind_lull'],
                1
            )
            . ' mph</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['wind_avg'],
                1
            )
            . ' mph</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['wind_gust'],
                1
            )
            . ' mph</td>';

        echo '<td>';

        if ($observation['wind_dir'] !== null) {
            echo htmlspecialchars(
                resultspack_weather_compass_direction(
                    $observation['wind_dir']
                )
            )
            . ' '
            . resultspack_weather_format_number(
                $observation['wind_dir'],
                0
            )
            . '°';
        } else {
            echo 'Not available';
        }

        echo '</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['precip_accumulation'],
                2
            )
            . ' mm</td>';

        echo '<td>'
            . htmlspecialchars(
                (string) ($observation['strike_count'] ?? 0)
            )
            . '</td>';

        echo '</tr>';
    }
}

echo '</table>';

echo '<br>';

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="4">Judge decisions</th></tr>';

echo '<tr>';
echo '<th class="Title">Time</th>';
echo '<th class="Title">Action</th>';
echo '<th class="Title">Reason</th>';
echo '<th class="Title">Note</th>';
echo '</tr>';

if (!$events) {
    echo '<tr><td colspan="4">No judge decisions were recorded during this session.</td></tr>';
} else {
    foreach ($events as $event) {
        echo '<tr>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $event['timestamp'],
                    $session['timezone'],
                    'H:i:s'
                )
            )
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
}

echo '</table>';

include('Common/Templates/tail.php');