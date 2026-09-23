<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

function resultspack_weather_viewer_wind_kmh($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value * 1.609344;
}

//This draws graphs
function resultspack_weather_viewer_event_colour($action)
{
    switch ((string) $action) {
        case 'suspend':
            return '#c62828';
        case 'resume':
            return '#2e7d32';
        case 'abandon':
            return '#6a1b9a';
        case 'delay':
            return '#ef6c00';
        default:
            return '#555555';
    }
}

function resultspack_weather_viewer_render_single_graph(
    $title,
    $ariaLabel,
    $observations,
    $valueKey,
    $events,
    $session,
    $yAxisLabel,
    $legendLabel,
    $colour,
    $decimals,
    $zeroBaseline,
    $minimumSpan,
    $roundTo
) {
    echo '<br>';
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main">' . htmlspecialchars($title) . '</th></tr>';
    echo '<tr><td>';

    $series = array();

    foreach ($observations as $observation) {
        if (
            $observation['timestamp'] > 0
            && isset($observation[$valueKey])
            && $observation[$valueKey] !== null
        ) {
            $series[] = $observation;
        }
    }

    if (count($series) < 2) {
        echo 'Not enough observations to draw this graph.';
        echo '</td></tr></table>';
        return;
    }

    $graphWidth = 1000;
    $graphHeight = 360;
    $marginLeft = 75;
    $marginRight = 25;
    $marginTop = 30;
    $marginBottom = 55;
    $plotWidth = $graphWidth - $marginLeft - $marginRight;
    $plotHeight = $graphHeight - $marginTop - $marginBottom;

    $firstTimestamp = (int) $series[0]['timestamp'];
    $lastTimestamp = (int) $series[count($series) - 1]['timestamp'];
    $timeRange = max(1, $lastTimestamp - $firstTimestamp);

    $minimum = null;
    $maximum = null;

    foreach ($series as $observation) {
        $value = (float) $observation[$valueKey];
        if ($minimum === null || $value < $minimum) {
            $minimum = $value;
        }
        if ($maximum === null || $value > $maximum) {
            $maximum = $value;
        }
    }

    $roundTo = max(0.0001, (float) $roundTo);

    if ($zeroBaseline) {
        $yMinimum = 0;
        $upper = max($roundTo, $maximum * 1.10);
        $yMaximum = ceil($upper / $roundTo) * $roundTo;
    } else {
        $span = max(0.0001, $maximum - $minimum);
        $padding = max($roundTo, $span * 0.15);
        $yMinimum = floor(($minimum - $padding) / $roundTo) * $roundTo;
        $yMaximum = ceil(($maximum + $padding) / $roundTo) * $roundTo;

        if (($yMaximum - $yMinimum) < $minimumSpan) {
            $midpoint = ($maximum + $minimum) / 2;
            $half = $minimumSpan / 2;
            $yMinimum = floor(($midpoint - $half) / $roundTo) * $roundTo;
            $yMaximum = ceil(($midpoint + $half) / $roundTo) * $roundTo;
        }
    }

    $yRange = max(0.0001, $yMaximum - $yMinimum);
    $points = array();

    foreach ($series as $observation) {
        $x = $marginLeft
            + ((($observation['timestamp'] - $firstTimestamp) / $timeRange) * $plotWidth);

        $y = $marginTop
            + $plotHeight
            - (((((float) $observation[$valueKey]) - $yMinimum) / $yRange) * $plotHeight);

        $points[] = round($x, 2) . ',' . round($y, 2);
    }

    echo '<div style="max-width:1100px">';
    echo '<svg viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:0.7px solid #ccc" '
        . 'role="img" aria-label="' . htmlspecialchars($ariaLabel) . '">';

    for ($step = 0; $step <= 4; $step++) {
        $value = $yMinimum + (($yMaximum - $yMinimum) * ($step / 4));
        $y = $marginTop + $plotHeight - ((($value - $yMinimum) / $yRange) * $plotHeight);

        echo '<line x1="' . $marginLeft . '" y1="' . round($y, 2) . '" '
            . 'x2="' . ($graphWidth - $marginRight) . '" y2="' . round($y, 2) . '" '
            . 'stroke="#dddddd" stroke-width="0.7" />';

        echo '<text x="' . ($marginLeft - 10) . '" y="' . (round($y, 2) + 5) . '" '
            . 'text-anchor="end" font-size="14">'
            . htmlspecialchars(number_format($value, (int) $decimals))
            . '</text>';
    }

    echo '<text x="18" y="' . ($marginTop + ($plotHeight / 2)) . '" '
        . 'transform="rotate(-90 18 ' . ($marginTop + ($plotHeight / 2)) . ')" '
        . 'text-anchor="middle" font-size="14">'
        . htmlspecialchars($yAxisLabel)
        . '</text>';

    $timeLabels = array(
        $firstTimestamp,
        (int) round($firstTimestamp + ($timeRange / 2)),
        $lastTimestamp,
    );

    foreach ($timeLabels as $index => $timestamp) {
        $x = $marginLeft + ((($timestamp - $firstTimestamp) / $timeRange) * $plotWidth);
        $anchor = $index === 0 ? 'start' : ($index === 2 ? 'end' : 'middle');

        echo '<text x="' . round($x, 2) . '" y="' . ($graphHeight - 20) . '" '
            . 'text-anchor="' . $anchor . '" font-size="14">'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $timestamp,
                    $session['timezone'],
                    'H:i'
                )
            )
            . '</text>';
    }

    echo '<polyline points="' . implode(' ', $points) . '" fill="none" '
        . 'stroke="' . htmlspecialchars($colour) . '" stroke-width="3" '
        . 'stroke-linejoin="round" stroke-linecap="round" />';

    $eventMarkerNumber = 0;

    foreach ($events as $event) {
        $eventTimestamp = (int) $event['timestamp'];
        if ($eventTimestamp < $firstTimestamp || $eventTimestamp > $lastTimestamp) {
            continue;
        }

        $eventX = $marginLeft
            + ((($eventTimestamp - $firstTimestamp) / $timeRange) * $plotWidth);
        $eventColour = resultspack_weather_viewer_event_colour($event['action']);
        $labelY = $marginTop + 18 + (($eventMarkerNumber % 3) * 18);

        echo '<line x1="' . round($eventX, 2) . '" y1="' . $marginTop . '" '
            . 'x2="' . round($eventX, 2) . '" y2="' . ($marginTop + $plotHeight) . '" '
            . 'stroke="' . $eventColour . '" stroke-width="2" stroke-dasharray="6,4" />';

        $eventLabel = strtoupper($event['action']) . ' '
            . resultspack_weather_format_timestamp(
                $eventTimestamp,
                $session['timezone'],
                'H:i:s'
            );

        echo '<text x="' . (round($eventX, 2) + 5) . '" y="' . $labelY . '" '
            . 'font-size="12" font-weight="bold" fill="' . $eventColour . '">'
            . htmlspecialchars($eventLabel)
            . '</text>';

        $eventMarkerNumber++;
    }

    echo '</svg>';
    echo '<div style="margin-top:8px">'
        . '<span><span style="display:inline-block;width:24px;border-top:3px solid '
        . htmlspecialchars($colour)
        . ';vertical-align:middle;margin-right:6px"></span>'
        . htmlspecialchars($legendLabel)
        . '</span></div>';
    echo '</div>';
    echo '</td></tr>';
    echo '</table>';
}

function resultspack_weather_viewer_render_wind_rose($observations, $session)
{
    echo '<br>';
    echo '<table class="Tabella freeWidth">';
    echo '<tr><th class="Main">Wind direction rose</th></tr>';
    echo '<tr><td>';

    $labels = array(
        'N', 'NNE', 'NE', 'ENE',
        'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW',
        'W', 'WNW', 'NW', 'NNW'
    );

    $sectors = array();

    for ($i = 0; $i < 16; $i++) {
        $sectors[$i] = array(
            'count' => 0,
            'speed_sum_kmh' => 0.0,
            'speed_count' => 0,
        );
    }

    $directionObservationCount = 0;
    $directionVectorX = 0.0;
    $directionVectorY = 0.0;

    foreach ($observations as $observation) {
        if (
            !isset($observation['wind_dir'])
            || $observation['wind_dir'] === null
            || !is_numeric($observation['wind_dir'])
        ) {
            continue;
        }

        $direction = fmod(
            ((float) $observation['wind_dir'] + 360),
            360
        );

        //Build a circular mean of the recorded FROM directions
        $directionRadians = deg2rad($direction);

        $directionVectorX += sin($directionRadians);
        $directionVectorY += cos($directionRadians);

        $sectorIndex =
            ((int) floor(($direction + 11.25) / 22.5)) % 16;

        $sectors[$sectorIndex]['count']++;
        $directionObservationCount++;

        if (
            isset($observation['wind_avg'])
            && $observation['wind_avg'] !== null
            && is_numeric($observation['wind_avg'])
        ) {
            // Raw Tempest values are stored in mph.
            // Convert only for display.
            $sectors[$sectorIndex]['speed_sum_kmh'] +=
                ((float) $observation['wind_avg']) * 1.609344;

            $sectors[$sectorIndex]['speed_count']++;
        }

        $prevailingWindFrom = null;

        if (
            $directionObservationCount > 0
            && (
                abs($directionVectorX) > 0.000001
                || abs($directionVectorY) > 0.000001
            )
        ) {
            $prevailingWindFrom =
                rad2deg(
                    atan2(
                        $directionVectorX,
                        $directionVectorY
                    )
                );

            if ($prevailingWindFrom < 0) {
                $prevailingWindFrom += 360;
            }
        }
    }

    if ($directionObservationCount === 0) {
        echo 'No recorded wind-direction observations are available for this session.';
        echo '</td></tr></table>';
        return;
    }

    $maximumSectorPercent = 0.0;

    foreach ($sectors as $sector) {
        $percent =
            ($sector['count'] / $directionObservationCount) * 100;

        if ($percent > $maximumSectorPercent) {
            $maximumSectorPercent = $percent;
        }
    }

    // Round the outer scale to 5 percentage points
    $scaleMaximum =
        max(5, ceil($maximumSectorPercent / 5) * 5);

    $graphWidth = 680;
    $graphHeight = 680;

    $centreX = 340;
    $centreY = 340;

    $maximumRadius = 220;
    $labelRadius = 260;

    echo '<div style="max-width:760px">';

    echo '<svg '
        . 'viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:1px solid #ccc" '
        . 'role="img" '
        . 'aria-label="Wind direction frequency rose with shooting direction">';

    // Arrow for the shooting-direction marker
    echo '<defs>';

    echo '<marker '
        . 'id="resultspack-target-arrow" '
        . 'markerWidth="10" '
        . 'markerHeight="10" '
        . 'refX="8" '
        . 'refY="3" '
        . 'orient="auto" '
        . 'markerUnits="strokeWidth">';

    echo '<path d="M0,0 L0,6 L9,3 z" fill="#222222" />';

    echo '</marker>';

    echo '<marker '
        . 'id="resultspack-wind-flow-arrow" '
        . 'markerWidth="10" '
        . 'markerHeight="10" '
        . 'refX="8" '
        . 'refY="3" '
        . 'orient="auto" '
        . 'markerUnits="strokeWidth">';

    echo '<path d="M0,0 L0,6 L9,3 z" fill="#0d47a1" />';

    echo '</marker>';

    echo '</defs>';

    /* Concentric frequency rings
    for ($step = 1; $step <= 4; $step++) {
        $radius =
            $maximumRadius * ($step / 4);

        $ringPercent =
            $scaleMaximum * ($step / 4);

        echo '<circle '
            . 'cx="' . $centreX . '" '
            . 'cy="' . $centreY . '" '
            . 'r="' . round($radius, 2) . '" '
            . 'fill="none" '
            . 'stroke="#dddddd" '
            . 'stroke-width="1" />';

        echo '<text '
            . 'x="' . ($centreX + 6) . '" '
            . 'y="' . round($centreY - $radius + 15, 2) . '" '
            . 'font-size="12" '
            . 'fill="#666666">'
            . htmlspecialchars(number_format($ringPercent, 1))
            . '%</text>';
    }
    */

    // N / E / S / W guide lines
    foreach (array(0, 90, 180, 270) as $axisDirection) {
        $axisRadians =
            deg2rad($axisDirection - 90);

        $axisX =
            $centreX
            + ($maximumRadius * cos($axisRadians));

        $axisY =
            $centreY
            + ($maximumRadius * sin($axisRadians));

        echo '<line '
            . 'x1="' . $centreX . '" '
            . 'y1="' . $centreY . '" '
            . 'x2="' . round($axisX, 2) . '" '
            . 'y2="' . round($axisY, 2) . '" '
            . 'stroke="#eeeeee" '
            . 'stroke-width="1" />';
    }

    //Show the prevailing direction of travel. Tempest gives the direction the wind came FROM. The arrow therefore begins on that side of the compass, passes through the centre, and points towards where the air travelled TO.
    if ($prevailingWindFrom !== null) {
        $prevailingWindTo =
            fmod($prevailingWindFrom + 180, 360);

        $flowRadius = $maximumRadius * 0.88;

        $fromRadians =
            deg2rad($prevailingWindFrom - 90);

        $toRadians =
            deg2rad($prevailingWindTo - 90);

        $fromX =
            $centreX + ($flowRadius * cos($fromRadians));

        $fromY =
            $centreY + ($flowRadius * sin($fromRadians));

        $toX =
            $centreX + ($flowRadius * cos($toRadians));

        $toY =
            $centreY + ($flowRadius * sin($toRadians));

        echo '<line '
            . 'x1="' . round($fromX, 2) . '" '
            . 'y1="' . round($fromY, 2) . '" '
            . 'x2="' . round($toX, 2) . '" '
            . 'y2="' . round($toY, 2) . '" '
            . 'stroke="#0d47a1" '
            . 'stroke-width="4" '
            . 'opacity="0.9" '
            . 'marker-end="url(#resultspack-wind-flow-arrow)" />';

        $labelRadius = $flowRadius + 24;

        $fromLabelX =
            $centreX + ($labelRadius * cos($fromRadians));

        $fromLabelY =
            $centreY + ($labelRadius * sin($fromRadians));

        $toLabelX =
            $centreX + ($labelRadius * cos($toRadians));

        $toLabelY =
            $centreY + ($labelRadius * sin($toRadians));

        /* echo '<text '
            . 'x="' . round($fromLabelX, 2) . '" '
            . 'y="' . round($fromLabelY + 5, 2) . '" '
            . 'text-anchor="middle" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="#0d47a1">FROM</text>';

        echo '<text '
            . 'x="' . round($toLabelX, 2) . '" '
            . 'y="' . round($toLabelY + 5, 2) . '" '
            . 'text-anchor="middle" '
            . 'font-size="12" '
            . 'font-weight="bold" '
            . 'fill="#0d47a1">TO</text>'; */
    }

    // Compass label
    foreach ($labels as $index => $label) {
        $direction =
            $index * 22.5;

        $radians =
            deg2rad($direction - 90);

        $labelX =
            $centreX
            + ($labelRadius * cos($radians));

        $labelY =
            $centreY
            + ($labelRadius * sin($radians))
            + 5;

        echo '<text '
            . 'x="' . round($labelX, 2) . '" '
            . 'y="' . round($labelY, 2) . '" '
            . 'text-anchor="middle" '
            . 'font-size="13"'
            . (
                in_array(
                    $label,
                    array('N', 'E', 'S', 'W'),
                    true
                )
                    ? ' font-weight="bold"'
                    : ''
            )
            . '>'
            . htmlspecialchars($label)
            . '</text>';
    }

    // Shooting direction towards the targets
    if (
        isset($session['shooting_bearing'])
        && $session['shooting_bearing'] !== null
        && is_numeric($session['shooting_bearing'])
    ) {
        $bearing =
            fmod(
                ((float) $session['shooting_bearing'] + 360),
                360
            );

        $bearingRadians =
            deg2rad($bearing - 90);

        $arrowRadius =
            $maximumRadius + 18;
        
        // Thin line showing the shooting line
        $shootingLineDirectionA = fmod($bearing + 90, 360);
        $shootingLineDirectionB = fmod($bearing + 270, 360);

        $shootingLineRadiansA = deg2rad($shootingLineDirectionA - 90);
        $shootingLineRadiansB = deg2rad($shootingLineDirectionB - 90);

        $shootingLineRadius = $maximumRadius + 10;

        $shootingLineX1 =
            $centreX + ($shootingLineRadius * cos($shootingLineRadiansA));
        $shootingLineY1 =
            $centreY + ($shootingLineRadius * sin($shootingLineRadiansA));

        $shootingLineX2 =
            $centreX + ($shootingLineRadius * cos($shootingLineRadiansB));
        $shootingLineY2 =
            $centreY + ($shootingLineRadius * sin($shootingLineRadiansB));

        echo '<line '
            . 'x1="' . round($shootingLineX1, 2) . '" '
            . 'y1="' . round($shootingLineY1, 2) . '" '
            . 'x2="' . round($shootingLineX2, 2) . '" '
            . 'y2="' . round($shootingLineY2, 2) . '" '
            . 'stroke="#333333" '
            . 'stroke-width="1.0" '
            . 'opacity="0.5" />';

        $arrowX =
            $centreX
            + ($arrowRadius * cos($bearingRadians));

        $arrowY =
            $centreY
            + ($arrowRadius * sin($bearingRadians));

        echo '<line '
            . 'x1="' . $centreX . '" '
            . 'y1="' . $centreY . '" '
            . 'x2="' . round($arrowX, 2) . '" '
            . 'y2="' . round($arrowY, 2) . '" '
            . 'stroke="#222222" '
            . 'stroke-width="3" '
            . 'stroke-dasharray="7,5" '
            . 'marker-end="url(#resultspack-target-arrow)" />';
    }

    echo '<circle '
        . 'cx="' . $centreX . '" '
        . 'cy="' . $centreY . '" '
        . 'r="5" '
        . 'fill="#222222" />';

    echo '</svg>';

    echo '<div style="margin-top:8px">';

    echo '<span style="margin-right:20px">'
    . '<span style="display:inline-block;width:24px;'
    . 'border-top:3px solid #0d47a1;vertical-align:middle;'
    . 'margin-right:6px"></span>'
    . 'Prevailing wind flow (FROM → TO)'
    . '</span>';

    if (
        isset($session['shooting_bearing'])
        && $session['shooting_bearing'] !== null
        && is_numeric($session['shooting_bearing'])
    ) {
        echo '<span>'
            . '<span style="display:inline-block;width:24px;'
            . 'border-top:3px dashed #222222;'
            . 'vertical-align:middle;margin-right:6px"></span>'
            . 'Shooting direction towards targets ('
            . htmlspecialchars(
                number_format(
                    (float) $session['shooting_bearing'],
                    0
                )
            )
            . '°)'
            . '</span>';
    }

    echo '</div>';

    echo '<div style="margin-top:10px">';
    echo 'The blue arrow shows the prevailing wind flow from FROM to TO. ';
    echo 'The dashed black arrow shows the shooting direction towards the targets, ';
    echo 'and the thin black line shows the shooting line.';
    echo '</div>';

    echo '</div>';

    echo '</td></tr>';
    echo '</table>';
}

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

echo '<tr>';
echo '<td class="Bold">Data transfer</td>';
echo '<td>';
echo '<a href="WeatherTransfer.php">Weather data export / import</a>';
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
$tempSum = 0.0;
$tempCount = 0;

$minHumidity = null;
$maxHumidity = null;
$humiditySum = 0.0;
$humidityCount = 0;

$windAverageSum = 0.0;
$windAverageCount = 0;
$maxGust = null;
$maxGustTimestamp = null;
$windDirectionSin = 0.0;
$windDirectionCos = 0.0;
$windDirectionCount = 0;
$relativeWindCounts = array();

$minStationPressure = null;
$maxStationPressure = null;
$firstStationPressure = null;
$lastStationPressure = null;
$minSeaLevelPressure = null;
$maxSeaLevelPressure = null;

$minSolarRadiation = null;
$maxSolarRadiation = null;
$solarRadiationSum = 0.0;
$solarRadiationCount = 0;
$minIlluminance = null;
$maxIlluminance = null;
$maxUv = null;

$totalRain = 0.0;
$totalLightning = 0;
$nearestStrikeDistance = null;

foreach ($observations as $observation) {
    if ($observation['air_temp'] !== null) {
        $temperature = (float) $observation['air_temp'];
        $tempSum += $temperature;
        $tempCount++;

        if ($minTemp === null || $temperature < $minTemp) {
            $minTemp = $temperature;
        }

        if ($maxTemp === null || $temperature > $maxTemp) {
            $maxTemp = $temperature;
        }
    }

    if ($observation['humidity'] !== null) {
        $humidity = (float) $observation['humidity'];
        $humiditySum += $humidity;
        $humidityCount++;

        if ($minHumidity === null || $humidity < $minHumidity) {
            $minHumidity = $humidity;
        }

        if ($maxHumidity === null || $humidity > $maxHumidity) {
            $maxHumidity = $humidity;
        }
    }

    if ($observation['wind_avg'] !== null) {
        $windAverageSum += (float) $observation['wind_avg'];
        $windAverageCount++;
    }

    if (
        $observation['wind_gust'] !== null
        && ($maxGust === null || $observation['wind_gust'] > $maxGust)
    ) {
        $maxGust = (float) $observation['wind_gust'];
        $maxGustTimestamp = $observation['timestamp'];
    }

    if ($observation['wind_dir'] !== null) {
        $windDirection = fmod(((float) $observation['wind_dir'] + 360), 360);
        $radians = deg2rad($windDirection);
        $windDirectionSin += sin($radians);
        $windDirectionCos += cos($radians);
        $windDirectionCount++;

        if ($session['shooting_bearing'] !== null) {
            $relativeWind = resultspack_weather_relative_wind(
                $windDirection,
                $session['shooting_bearing']
            );

            if ($relativeWind) {
                $relativeLabel = $relativeWind['label'];
                if (!isset($relativeWindCounts[$relativeLabel])) {
                    $relativeWindCounts[$relativeLabel] = 0;
                }
                $relativeWindCounts[$relativeLabel]++;
            }
        }
    }

    if ($observation['station_pressure'] !== null) {
        $pressure = (float) $observation['station_pressure'];

        if ($firstStationPressure === null) {
            $firstStationPressure = $pressure;
        }
        $lastStationPressure = $pressure;

        if ($minStationPressure === null || $pressure < $minStationPressure) {
            $minStationPressure = $pressure;
        }
        if ($maxStationPressure === null || $pressure > $maxStationPressure) {
            $maxStationPressure = $pressure;
        }
    }

    if ($observation['sea_level_pressure'] !== null) {
        $pressure = (float) $observation['sea_level_pressure'];
        if ($minSeaLevelPressure === null || $pressure < $minSeaLevelPressure) {
            $minSeaLevelPressure = $pressure;
        }
        if ($maxSeaLevelPressure === null || $pressure > $maxSeaLevelPressure) {
            $maxSeaLevelPressure = $pressure;
        }
    }

    if ($observation['solar_radiation'] !== null) {
        $solar = (float) $observation['solar_radiation'];
        $solarRadiationSum += $solar;
        $solarRadiationCount++;
        if ($minSolarRadiation === null || $solar < $minSolarRadiation) {
            $minSolarRadiation = $solar;
        }
        if ($maxSolarRadiation === null || $solar > $maxSolarRadiation) {
            $maxSolarRadiation = $solar;
        }
    }

    if ($observation['illuminance'] !== null) {
        $illuminance = (float) $observation['illuminance'];
        if ($minIlluminance === null || $illuminance < $minIlluminance) {
            $minIlluminance = $illuminance;
        }
        if ($maxIlluminance === null || $illuminance > $maxIlluminance) {
            $maxIlluminance = $illuminance;
        }
    }

    if ($observation['uv'] !== null) {
        $uv = (float) $observation['uv'];
        if ($maxUv === null || $uv > $maxUv) {
            $maxUv = $uv;
        }
    }

    if ($observation['precip_accumulation'] !== null) {
        $totalRain += (float) $observation['precip_accumulation'];
    }

    if ($observation['strike_count'] !== null) {
        $strikeCount = (int) $observation['strike_count'];
        $totalLightning += $strikeCount;

        if (
            $strikeCount > 0
            && $observation['strike_distance'] !== null
            && (
                $nearestStrikeDistance === null
                || $observation['strike_distance'] < $nearestStrikeDistance
            )
        ) {
            $nearestStrikeDistance = (float) $observation['strike_distance'];
        }
    }
}

$averageTemp = $tempCount > 0 ? $tempSum / $tempCount : null;
$averageHumidity = $humidityCount > 0 ? $humiditySum / $humidityCount : null;
$averageWind = $windAverageCount > 0 ? $windAverageSum / $windAverageCount : null;
$averageSolarRadiation = $solarRadiationCount > 0
    ? $solarRadiationSum / $solarRadiationCount
    : null;

$prevailingWindDirection = null;
$windDirectionConcentration = null;

if ($windDirectionCount > 0) {
    $meanSin = $windDirectionSin / $windDirectionCount;
    $meanCos = $windDirectionCos / $windDirectionCount;
    $windDirectionConcentration = sqrt(($meanSin * $meanSin) + ($meanCos * $meanCos));

    if ($windDirectionConcentration >= 0.10) {
        $prevailingWindDirection = fmod(
            rad2deg(atan2($meanSin, $meanCos)) + 360,
            360
        );
    }
}

$dominantRelativeWindLabel = null;
$dominantRelativeWindPercent = null;

if ($relativeWindCounts) {
    arsort($relativeWindCounts);
    $dominantRelativeWindLabel = (string) array_key_first($relativeWindCounts);
    $dominantCount = (int) $relativeWindCounts[$dominantRelativeWindLabel];
    $relativeTotal = array_sum($relativeWindCounts);

    if ($relativeTotal > 0) {
        $dominantRelativeWindPercent = ($dominantCount / $relativeTotal) * 100;
    }
}

$stationPressureChange = (
    $firstStationPressure !== null
    && $lastStationPressure !== null
)
    ? $lastStationPressure - $firstStationPressure
    : null;

$conciseSummaryParts = array();

if ($averageTemp !== null && $minTemp !== null && $maxTemp !== null) {
    $conciseSummaryParts[] = resultspack_weather_format_number($averageTemp, 1)
        . ' °C average ('
        . resultspack_weather_format_number($minTemp, 1)
        . '–'
        . resultspack_weather_format_number($maxTemp, 1)
        . ' °C)';
}

if ($averageWind !== null) {
    $windSummary = 'wind '
        . resultspack_weather_format_number(
            resultspack_weather_viewer_wind_kmh($averageWind),
            1
        )
        . ' km/h average';

    if ($maxGust !== null) {
        $windSummary .= ', gusting to '
            . resultspack_weather_format_number(
                resultspack_weather_viewer_wind_kmh($maxGust),
                1
            )
            . ' km/h';
    }

    $conciseSummaryParts[] = $windSummary;
}

if ($prevailingWindDirection !== null) {
    $conciseSummaryParts[] = 'prevailing '
        . resultspack_weather_compass_direction($prevailingWindDirection)
        . ' ('
        . resultspack_weather_format_number($prevailingWindDirection, 0)
        . '°)';
}

if ($totalRain <= 0.0001) {
    $conciseSummaryParts[] = 'no rain recorded';
} else {
    $conciseSummaryParts[] = resultspack_weather_format_number($totalRain, 2)
        . ' mm rain recorded';
}

$conciseWeatherSummary = implode('; ', $conciseSummaryParts);

//Environmental summary
echo '<br>';
echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Environmental summary</th></tr>';
echo '<tr><td colspan="2">Summary derived from tempest weather station readings</td></tr>';

if ($conciseWeatherSummary !== '') {
    echo '<tr><td class="Bold">Concise weather summary</td><td><b>'
        . htmlspecialchars($conciseWeatherSummary)
        . '</b></td></tr>';
}

echo '<tr><td class="Bold">Average wind</td><td>'
    . ($averageWind !== null
        ? resultspack_weather_format_number(
            resultspack_weather_viewer_wind_kmh($averageWind),
            1
        ) . ' km/h'
        : 'Not available')
    . '</td></tr>';

echo '<tr><td class="Bold">Maximum gust</td><td>';
if ($maxGust !== null) {
    echo resultspack_weather_format_number(
        resultspack_weather_viewer_wind_kmh($maxGust),
        1
    ) . ' km/h';
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

echo '<tr><td class="Bold">Prevailing wind direction</td><td>';
if ($prevailingWindDirection !== null) {
    echo htmlspecialchars(
        resultspack_weather_compass_direction($prevailingWindDirection)
    )
    . ' ('
    . resultspack_weather_format_number($prevailingWindDirection, 0)
    . '°)';

    if ($windDirectionConcentration !== null) {
        echo ' - circular mean of recorded directions';
    }
} elseif ($windDirectionCount > 0) {
    echo 'Highly variable; no clear prevailing direction';
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Wind relative to the range</td><td>';
if (
    $dominantRelativeWindLabel !== null
    && $dominantRelativeWindPercent !== null
) {
    echo htmlspecialchars($dominantRelativeWindLabel)
        . ' for '
        . resultspack_weather_format_number($dominantRelativeWindPercent, 1)
        . '% of observed time';
} elseif ($session['shooting_bearing'] === null) {
    echo 'Shooting bearing not recorded';
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Temperature</td><td>';
if ($averageTemp !== null && $minTemp !== null && $maxTemp !== null) {
    echo resultspack_weather_format_number($averageTemp, 1)
        . ' °C average; '
        . resultspack_weather_format_number($minTemp, 1)
        . '–'
        . resultspack_weather_format_number($maxTemp, 1)
        . ' °C range';
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Relative humidity</td><td>';
if ($averageHumidity !== null && $minHumidity !== null && $maxHumidity !== null) {
    echo resultspack_weather_format_number($averageHumidity, 1)
        . '% average; '
        . resultspack_weather_format_number($minHumidity, 0)
        . '–'
        . resultspack_weather_format_number($maxHumidity, 0)
        . '% range';
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Station pressure</td><td>';
if ($minStationPressure !== null && $maxStationPressure !== null) {
    echo resultspack_weather_format_number($minStationPressure, 1)
        . '–'
        . resultspack_weather_format_number($maxStationPressure, 1)
        . ' hPa';

    if ($stationPressureChange !== null) {
        echo '; '
            . ($stationPressureChange >= 0 ? '+' : '')
            . resultspack_weather_format_number($stationPressureChange, 1)
            . ' hPa from first to last observation';
    }
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Sea-level pressure</td><td>';
if ($minSeaLevelPressure !== null && $maxSeaLevelPressure !== null) {
    echo resultspack_weather_format_number($minSeaLevelPressure, 1)
        . '–'
        . resultspack_weather_format_number($maxSeaLevelPressure, 1)
        . ' hPa';
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Solar radiation</td><td>';
if (
    $averageSolarRadiation !== null
    && $minSolarRadiation !== null
    && $maxSolarRadiation !== null
) {
    echo resultspack_weather_format_number($averageSolarRadiation, 0)
        . ' W/m² average; '
        . resultspack_weather_format_number($minSolarRadiation, 0)
        . '–'
        . resultspack_weather_format_number($maxSolarRadiation, 0)
        . ' W/m² range';
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Illuminance</td><td>';
if ($minIlluminance !== null && $maxIlluminance !== null) {
    echo number_format($minIlluminance, 0)
        . '–'
        . number_format($maxIlluminance, 0)
        . ' lux';
} else {
    echo 'Not available';
}
echo '</td></tr>';

echo '<tr><td class="Bold">Maximum UV index</td><td>'
    . ($maxUv !== null
        ? resultspack_weather_format_number($maxUv, 2)
        : 'Not available')
    . '</td></tr>';

echo '<tr><td class="Bold">Rain during session</td><td>'
    . resultspack_weather_format_number($totalRain, 2)
    . ' mm</td></tr>';

echo '<tr><td class="Bold">Lightning strikes recorded</td><td>'
    . (int) $totalLightning;
if ($totalLightning > 0 && $nearestStrikeDistance !== null) {
    echo ' — nearest recorded strike distance '
        . resultspack_weather_format_number($nearestStrikeDistance, 1)
        . ' km';
}
echo '</td></tr>';

echo '</table>';

//Timing correction
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

echo '<br>';
echo '<table class="Tabella freeWidth">';

echo '<tr><th class="Main" colspan="6">'
    . 'Timing correction audit trail'
    . ($timingCorrections
        ? ' (' . count($timingCorrections) . ')'
        : '')
    . '</th></tr>';

echo '<tr>';
echo '<th class="Title">Recorded</th>';
echo '<th class="Title">Old start</th>';
echo '<th class="Title">Old end</th>';
echo '<th class="Title">New start</th>';
echo '<th class="Title">New end</th>';
echo '<th class="Title">Reason</th>';
echo '</tr>';

if (!$timingCorrections) {
    echo '<tr>';

    echo '<td colspan="6" class="resultspack-muted">'
        . 'No timing corrections have been recorded for this session.'
        . '</td>';

    echo '</tr>';
} else {
    foreach ($timingCorrections as $correction) {
        echo '<tr>';

        echo '<td>'
            . htmlspecialchars($correction['created'])
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['old_started_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
                )
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['old_ended_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
                )
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['new_started_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
                )
            )
            . '</td>';

        echo '<td>'
            . htmlspecialchars(
                resultspack_weather_format_timestamp(
                    $correction['new_ended_epoch'],
                    $correction['timezone'],
                    'd/m/Y H:i:s T'
                )
            )
            . '</td>';

        echo '<td>'
            . nl2br(
                htmlspecialchars(
                    $correction['reason']
                )
            )
            . '</td>';

        echo '</tr>';
    }
}

echo '</table>';

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
            $observation['wind_lull'] !== null
            || $observation['wind_avg'] !== null
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

    //Work out the time range.
    $firstTimestamp =
        (int) $windObservations[0]['timestamp'];

    $lastTimestamp =
        (int) $windObservations[count($windObservations) - 1]['timestamp'];

    $timeRange =
        max(1, $lastTimestamp - $firstTimestamp);

    //Raw Tempest wind observations are stored in mph.
    //Convert them to km/h for presentation in the viewer.
    $windMphToKmh = 1.609344;

    /*
     * Build one clean local series in km/h. Keeping the conversion here means
     * the stored research data remains untouched while every part of this graph
     * works in the same display unit.
     */
    $windSeries = array();

    $maximumWind = 0;
    $maximumGustKmh = null;
    $maximumGustTimestamp = null;

    foreach ($windObservations as $observation) {
        $lullKmh =
            $observation['wind_lull'] !== null
                ? (float) $observation['wind_lull'] * $windMphToKmh
                : null;

        $averageKmh =
            $observation['wind_avg'] !== null
                ? (float) $observation['wind_avg'] * $windMphToKmh
                : null;

        $gustKmh =
            $observation['wind_gust'] !== null
                ? (float) $observation['wind_gust'] * $windMphToKmh
                : null;

        foreach (array($lullKmh, $averageKmh, $gustKmh) as $candidate) {
            if ($candidate !== null && $candidate > $maximumWind) {
                $maximumWind = $candidate;
            }
        }

        if (
            $gustKmh !== null
            && (
                $maximumGustKmh === null
                || $gustKmh > $maximumGustKmh
            )
        ) {
            $maximumGustKmh = $gustKmh;
            $maximumGustTimestamp =
                (int) $observation['timestamp'];
        }

        $windSeries[] = array(
            'timestamp' => (int) $observation['timestamp'],
            'lull_kmh' => $lullKmh,
            'average_kmh' => $averageKmh,
            'gust_kmh' => $gustKmh,
        );
    }

    //Choose a reader-friendly Y-axis interval.
    if ($maximumWind <= 25) {
        $yTickInterval = 5;
    } elseif ($maximumWind <= 60) {
        $yTickInterval = 10;
    } else {
        $yTickInterval = 20;
    }

    //Round the top of the graph up to the next complete tick.
    $yMaximum =
        max(
            $yTickInterval,
            ceil($maximumWind / $yTickInterval)
            * $yTickInterval
        );

    /*
     * Build:
     * - the upper and lower edges of the lull-to-gust envelope;
     * - a centred five-observation moving average of the minute averages.
     *
     * With the Tempest one-minute data this is effectively a five-minute
     * smoothed average for normal sessions.
     */
    $gustBoundaryPoints = array();
    $lullBoundaryPoints = array();
    $bandUpperPoints = array();
    $bandLowerPoints = array();
    $smoothedAveragePoints = array();

    foreach ($windSeries as $index => $point) {
        $x =
            $marginLeft
            + (
                (($point['timestamp'] - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        if ($point['gust_kmh'] !== null) {
            $gustY =
                $marginTop
                + $plotHeight
                - (
                    ($point['gust_kmh'] / $yMaximum)
                    * $plotHeight
                );

            $gustPoint =
                round($x, 2) . ',' . round($gustY, 2);

            $gustBoundaryPoints[] =
                $gustPoint;

            if ($point['lull_kmh'] !== null) {
                $bandUpperPoints[] =
                    $gustPoint;
            }
        }

        if ($point['lull_kmh'] !== null) {
            $lullY =
                $marginTop
                + $plotHeight
                - (
                    ($point['lull_kmh'] / $yMaximum)
                    * $plotHeight
                );

            $lullPoint =
                round($x, 2) . ',' . round($lullY, 2);

            $lullBoundaryPoints[] =
                $lullPoint;

            if ($point['gust_kmh'] !== null) {
                $bandLowerPoints[] =
                    $lullPoint;
            }
        }

        //Centred five-point moving average: two readings either side.
        if ($point['average_kmh'] !== null) {
            $windowStart =
                max(0, $index - 2);

            $windowEnd =
                min(count($windSeries) - 1, $index + 2);

            $windowTotal = 0.0;
            $windowCount = 0;

            for (
                $windowIndex = $windowStart;
                $windowIndex <= $windowEnd;
                $windowIndex++
            ) {
                if ($windSeries[$windowIndex]['average_kmh'] !== null) {
                    $windowTotal +=
                        $windSeries[$windowIndex]['average_kmh'];

                    $windowCount++;
                }
            }

            if ($windowCount > 0) {
                $smoothedAverage =
                    $windowTotal / $windowCount;

                $smoothedY =
                    $marginTop
                    + $plotHeight
                    - (
                        ($smoothedAverage / $yMaximum)
                        * $plotHeight
                    );

                $smoothedAveragePoints[] =
                    round($x, 2)
                    . ','
                    . round($smoothedY, 2);
            }
        }
    }

    echo '<div style="max-width:1100px">';

    echo '<svg '
        . 'viewBox="0 0 ' . $graphWidth . ' ' . $graphHeight . '" '
        . 'style="width:100%;height:auto;background:white;border:1px solid #ccc" '
        . 'role="img" '
        . 'aria-label="Lull to gust wind range with a five-minute smoothed average across the weather session">';

    //Horizontal grid lines and Y-axis labels.
    for (
        $value = 0;
        $value <= $yMaximum;
        $value += $yTickInterval
    ) {
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
                number_format($value, 0)
            )
            . '</text>';
    }

    //Y axis title.
    echo '<text '
        . 'x="18" '
        . 'y="' . ($marginTop + ($plotHeight / 2)) . '" '
        . 'transform="rotate(-90 18 '
        . ($marginTop + ($plotHeight / 2))
        . ')" '
        . 'text-anchor="middle" '
        . 'font-size="14">'
        . 'Wind speed (km/h)'
        . '</text>';

    //Create useful clock-time markers.
    if ($timeRange <= (3 * 3600)) {
        $timeTickMinutes = 30;
    } elseif ($timeRange <= (8 * 3600)) {
        $timeTickMinutes = 60;
    } else {
        $timeTickMinutes = 120;
    }

    $timeLabels = array(
        $firstTimestamp
    );

    try {
        $graphTimezone = new DateTimeZone(
            $session['timezone']
                ?: resultspack_weather_timezone()
        );

        $firstLocal =
            (new DateTimeImmutable('@' . $firstTimestamp))
            ->setTimezone($graphTimezone);

        $hourStart =
            $firstLocal->setTime(
                (int) $firstLocal->format('H'),
                0,
                0
            );

        $minutesIntoHour =
            (int) $firstLocal->format('i');

        $stepsIntoNextTick =
            intdiv(
                $minutesIntoHour,
                $timeTickMinutes
            ) + 1;

        $minutesToNextTick =
            $stepsIntoNextTick
            * $timeTickMinutes;

        $nextTick =
            $hourStart->modify(
                '+' . $minutesToNextTick . ' minutes'
            );

        //Avoid round-time labels extremely close to either endpoint.
        $minimumLabelGap =
            10 * 60;

        while ($nextTick->getTimestamp() < $lastTimestamp) {
            $tickTimestamp =
                $nextTick->getTimestamp();

            if (
                ($tickTimestamp - $firstTimestamp)
                    >= $minimumLabelGap
                && ($lastTimestamp - $tickTimestamp)
                    >= $minimumLabelGap
            ) {
                $timeLabels[] =
                    $tickTimestamp;
            }

            $nextTick =
                $nextTick->modify(
                    '+' . $timeTickMinutes . ' minutes'
                );
        }
    } catch (Exception $e) {
        //Start and end labels are still sufficient if timezone parsing fails.
    }

    $timeLabels[] =
        $lastTimestamp;

    foreach ($timeLabels as $index => $timestamp) {
        $x =
            $marginLeft
            + (
                (($timestamp - $firstTimestamp) / $timeRange)
                * $plotWidth
            );

        $isFirst =
            $index === 0;

        $isLast =
            $index === count($timeLabels) - 1;

        //Add a light vertical guide for regular clock-time markers.
        if (!$isFirst && !$isLast) {
            echo '<line '
                . 'x1="' . round($x, 2) . '" '
                . 'y1="' . $marginTop . '" '
                . 'x2="' . round($x, 2) . '" '
                . 'y2="' . ($marginTop + $plotHeight) . '" '
                . 'stroke="#eeeeee" '
                . 'stroke-width="1" />';
        }

        $anchor =
            $isFirst
                ? 'start'
                : ($isLast ? 'end' : 'middle');

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

    /*
     * Draw the lull-to-gust envelope first so the smoothed average remains
     * clearly visible on top.
     */
    if (
        count($bandUpperPoints) >= 2
        && count($bandLowerPoints) >= 2
    ) {
        $bandPoints =
            array_merge(
                $bandUpperPoints,
                array_reverse($bandLowerPoints)
            );

        echo '<polygon '
            . 'points="' . implode(' ', $bandPoints) . '" '
            . 'fill="#90caf9" '
            . 'fill-opacity="0.30" '
            . 'stroke="none" />';
    }

    //Subtle boundary lines make the top (gust) and bottom (lull) of the band clear.
    if ($gustBoundaryPoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $gustBoundaryPoints) . '" '
            . 'fill="none" '
            . 'stroke="#c62828" '
            . 'stroke-width="1.5" '
            . 'stroke-opacity="0.75" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    if ($lullBoundaryPoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $lullBoundaryPoints) . '" '
            . 'fill="none" '
            . 'stroke="#607d8b" '
            . 'stroke-width="1.5" '
            . 'stroke-opacity="0.75" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    if ($smoothedAveragePoints) {
        echo '<polyline '
            . 'points="' . implode(' ', $smoothedAveragePoints) . '" '
            . 'fill="none" '
            . 'stroke="#1565c0" '
            . 'stroke-width="3.5" '
            . 'stroke-linejoin="round" '
            . 'stroke-linecap="round" />';
    }

    //Mark the maximum recorded gust directly on the graph.
    if (
        $maximumGustKmh !== null
        && $maximumGustTimestamp !== null
    ) {
        $maximumGustX =
            $marginLeft
            + (
                (
                    $maximumGustTimestamp
                    - $firstTimestamp
                )
                / $timeRange
            )
            * $plotWidth;

        $maximumGustY =
            $marginTop
            + $plotHeight
            - (
                ($maximumGustKmh / $yMaximum)
                * $plotHeight
            );

        echo '<circle '
            . 'cx="' . round($maximumGustX, 2) . '" '
            . 'cy="' . round($maximumGustY, 2) . '" '
            . 'r="5" '
            . 'fill="#c62828" />';

        echo '<text '
            . 'x="' . (round($maximumGustX, 2) + 9) . '" '
            . 'y="' . (round($maximumGustY, 2) - 9) . '" '
            . 'font-size="13" '
            . 'font-weight="bold" '
            . 'fill="#c62828">'
            . htmlspecialchars(
                number_format($maximumGustKmh, 1)
                . ' km/h'
            )
            . '</text>';
    }

    //Judge-decision markers.
    $eventMarkerNumber = 0;

    foreach ($events as $event) {
        $eventTimestamp = (int) $event['timestamp'];

        //Only plot events which fall inside the observation window shown.
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

    //Legend.
    echo '<div style="margin-top:8px">';

    echo '<span style="margin-right:20px">'
        . '<span style="display:inline-block;width:24px;height:10px;'
        . 'background:#90caf9;border:1px solid #78909c;'
        . 'vertical-align:middle;margin-right:6px"></span>'
        . 'Wind range (bottom = lull, top = gust)'
        . '</span>';

    echo '<span>'
        . '<span style="display:inline-block;width:24px;'
        . 'border-top:4px solid #1565c0;vertical-align:middle;'
        . 'margin-right:6px"></span>'
        . '5-minute smoothed average'
        . '</span>';

    echo '</div>';

    echo '<div class="resultspack-muted" style="margin-top:6px">'
        . 'The shaded band shows the minute-by-minute range between lull and gust. '
        . 'The blue line smooths the recorded average across five observations to show the underlying trend.'
        . '</div>';

    echo '</div>';
}

echo '</td></tr>';
echo '</table>';

//Wind direction rose
resultspack_weather_viewer_render_wind_rose(
    $observations,
    $session
);

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

//Station pressure graph.
resultspack_weather_viewer_render_single_graph(
    'Station pressure across the session',
    'Station pressure across the weather session',
    $observations,
    'station_pressure',
    $events,
    $session,
    'Station pressure (hPa)',
    'Station pressure',
    '#5d4037',
    1,
    false,
    5,
    0.5
);

//Solar-radiation graph.
resultspack_weather_viewer_render_single_graph(
    'Solar radiation across the session',
    'Solar radiation across the weather session',
    $observations,
    'solar_radiation',
    $events,
    $session,
    'Solar radiation (W/m²)',
    'Solar radiation',
    '#f9a825',
    0,
    true,
    0,
    100
);

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
echo '<tr><th class="Main" colspan="2">Session metadata and data quality</th></tr>';

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
                        resultspack_weather_viewer_wind_kmh(
                            $nearestObservation['wind_avg']
                        ),
                        1
                    )
                    . ' km/h</td></tr>';

                echo '<tr><td class="Bold">Wind gust</td><td>'
                    . resultspack_weather_format_number(
                        resultspack_weather_viewer_wind_kmh(
                            $nearestObservation['wind_gust']
                        ),
                        1
                    )
                    . ' km/h</td></tr>';

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

                echo '<tr><td class="Bold">Station pressure</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['station_pressure'],
                        1
                    )
                    . ' hPa</td></tr>';

                echo '<tr><td class="Bold">Sea-level pressure</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['sea_level_pressure'],
                        1
                    )
                    . ' hPa</td></tr>';

                echo '<tr><td class="Bold">Solar radiation</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['solar_radiation'],
                        0
                    )
                    . ' W/m²</td></tr>';

                echo '<tr><td class="Bold">Illuminance</td><td>';
                if ($nearestObservation['illuminance'] !== null) {
                    echo number_format($nearestObservation['illuminance'], 0) . ' lux';
                } else {
                    echo 'Not available';
                }
                echo '</td></tr>';

                echo '<tr><td class="Bold">UV index</td><td>'
                    . resultspack_weather_format_number(
                        $nearestObservation['uv'],
                        2
                    )
                    . '</td></tr>';

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

echo '<div style="overflow-x:auto;max-width:100%;">';
echo '<table class="Tabella freeWidth" style="min-width:1750px;">';
echo '<tr><th class="Main" colspan="13">Minute-by-minute observations</th></tr>';

echo '<tr>';
echo '<th class="Title">Time</th>';
echo '<th class="Title">Temp</th>';
echo '<th class="Title">Humidity</th>';
echo '<th class="Title">Wind lull</th>';
echo '<th class="Title">Wind avg</th>';
echo '<th class="Title">Wind gust</th>';
echo '<th class="Title">Direction</th>';
echo '<th class="Title">Pressure</th>';
echo '<th class="Title">Solar</th>';
echo '<th class="Title">Light</th>';
echo '<th class="Title">UV</th>';
echo '<th class="Title">Rain</th>';
echo '<th class="Title">Lightning</th>';
echo '</tr>';

if (!$observations) {
    echo '<tr><td colspan="13">No locally stored observations are available for this session.</td></tr>';
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
                resultspack_weather_viewer_wind_kmh(
                    $observation['wind_lull']
                ),
                1
            )
            . ' km/h</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                resultspack_weather_viewer_wind_kmh(
                    $observation['wind_avg']
                ),
                1
            )
            . ' km/h</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                resultspack_weather_viewer_wind_kmh(
                    $observation['wind_gust']
                ),
                1
            )
            . ' km/h</td>';

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
                $observation['station_pressure'],
                1
            )
            . ' hPa</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['solar_radiation'],
                0
            )
            . ' W/m²</td>';

        echo '<td>';
        if ($observation['illuminance'] !== null) {
            echo number_format($observation['illuminance'], 0) . ' lux';
        } else {
            echo '—';
        }
        echo '</td>';

        echo '<td>'
            . resultspack_weather_format_number(
                $observation['uv'],
                2
            )
            . '</td>';

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
echo '</div>';

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