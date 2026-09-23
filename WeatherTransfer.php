<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

$PAGE_TITLE = 'Weather Data Transfer';

include('Common/Templates/head.php');

$completedSessions = resultspack_weather_get_completed_sessions();
$tournaments = resultspack_fetch_tournament_list();

function resultspack_weather_transfer_competition_label($session, $tournaments)
{
    $competitionName = 'Competition ' . (int) $session['tournament_id'];

    foreach ($tournaments as $tournament) {
        if ((int) $tournament['id'] === (int) $session['tournament_id']) {
            $competitionName =
                ($tournament['code'] !== ''
                    ? $tournament['code'] . ' — '
                    : '')
                . $tournament['name'];
            break;
        }
    }

    return $competitionName;
}

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Weather Data Transfer</th></tr>';
echo '<tr><td colspan="2">'
    . 'Export one completed weather session as a portable ResultsPack package, '
    . 'or import a package from another IANSEO installation. '
    . '<b>Tempest API tokens and local configuration are never included.</b>'
    . '</td></tr>';
echo '<tr><td colspan="2">'
    . '<a href="WeatherSessionView.php">Return to Weather Session Viewer</a>'
    . '</td></tr>';
echo '</table>';

echo '<br>';
echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="7">Export weather sessions</th></tr>';
echo '<tr><td colspan="7">'
    . '<b>JSON package</b> is the complete, re-importable research archive: session metadata, '
    . 'all minute observations, judge events, timing-correction audit history and data-quality summary. '
    . '<b>CSV data</b> is a spreadsheet-friendly observation table for analysis and sharing. '
    . 'Tempest API tokens and local configuration are never exported.'
    . '</td></tr>';

if (!$completedSessions) {
    echo '<tr><td colspan="7">No completed weather sessions are available to export.</td></tr>';
} else {
    echo '<tr>';
    echo '<th>Competition</th>';
    echo '<th>Date / start</th>';
    echo '<th>Status</th>';
    echo '<th>Observations</th>';
    echo '<th>Coverage</th>';
    echo '<th>Export</th>';
    echo '<th>Open</th>';
    echo '</tr>';

    foreach ($completedSessions as $session) {
        $sessionId = (int) $session['id'];
        $quality = resultspack_weather_session_quality($sessionId);
        $competitionLabel = resultspack_weather_transfer_competition_label(
            $session,
            $tournaments
        );
        $startLabel = resultspack_weather_format_timestamp(
            $session['started_epoch'],
            $session['timezone'],
            'd/m/Y H:i T'
        );
        $coverageLabel = $quality['coverage_percent'] !== null
            ? number_format((float) $quality['coverage_percent'], 1) . '%'
            : 'n/a';

        echo '<tr>';
        echo '<td>' . htmlspecialchars($competitionLabel) . '</td>';
        echo '<td>' . htmlspecialchars($startLabel) . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst((string) $session['research_status'])) . '</td>';
        echo '<td>' . (int) ($quality['stored_total'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars($coverageLabel) . '</td>';
        echo '<td>';
        echo '<form method="get" action="WeatherSessionExport.php" style="display:inline;margin:0">';
        echo '<input type="hidden" name="session_id" value="' . $sessionId . '">';
        echo '<input type="hidden" name="format" value="json">';
        echo '<input type="submit" value="JSON package">';
        echo '</form> ';
        echo '<form method="get" action="WeatherSessionExport.php" style="display:inline;margin:0">';
        echo '<input type="hidden" name="session_id" value="' . $sessionId . '">';
        echo '<input type="hidden" name="format" value="csv">';
        echo '<input type="submit" value="CSV data">';
        echo '</form>';
        echo '</td>';
        echo '<td><a href="WeatherSessionView.php?session_id=' . $sessionId . '">View</a></td>';
        echo '</tr>';
    }
}

echo '</table>';

echo '<br>';
echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Import a weather session</th></tr>';

if (!empty($_GET['import_error'])) {
    echo '<tr><td colspan="2" style="color:#b00020"><b>Import not completed.</b> '
        . htmlspecialchars((string) $_GET['import_error'])
        . '</td></tr>';
}

if (($_GET['imported'] ?? '') === '1') {
    $sessionId = (int) ($_GET['session_id'] ?? 0);
    $existing = (int) ($_GET['existing'] ?? 0) === 1;
    $obsReceived = (int) ($_GET['obs_received'] ?? 0);
    $obsAdded = (int) ($_GET['obs_added'] ?? 0);
    $eventsReceived = (int) ($_GET['events_received'] ?? 0);
    $eventsAdded = (int) ($_GET['events_added'] ?? 0);
    $correctionsReceived = (int) ($_GET['corrections_received'] ?? 0);
    $correctionsAdded = (int) ($_GET['corrections_added'] ?? 0);

    echo '<tr><td colspan="2" style="color:green"><b>Weather package imported successfully.</b> ';

    if ($existing) {
        echo 'ResultsPack recognised an existing copy of this session and merged only missing child records. ';
    } else {
        echo 'A new local weather session was created. ';
    }

    echo $obsReceived . ' observation'
        . ($obsReceived === 1 ? '' : 's')
        . ' received; ' . $obsAdded . ' added. ';

    echo $eventsReceived . ' judge event'
        . ($eventsReceived === 1 ? '' : 's')
        . ' received; ' . $eventsAdded . ' added. ';

    echo $correctionsReceived . ' timing correction'
        . ($correctionsReceived === 1 ? '' : 's')
        . ' received; ' . $correctionsAdded . ' added.';

    if (isset($_GET['coverage'])) {
        echo ' Local coverage: '
            . htmlspecialchars((string) $_GET['coverage'])
            . '%';

        if (isset($_GET['missing'])) {
            echo ' (' . (int) $_GET['missing'] . ' missing).';
        }
    }

    if (!empty($_GET['match'])) {
        echo ' Competition matched by '
            . htmlspecialchars((string) $_GET['match'])
            . '.';
    }

    if ($sessionId > 0) {
        echo ' <a href="WeatherSessionView.php?session_id=' . $sessionId . '">View imported session</a>.';
    }

    echo '</td></tr>';

    if (!empty($_GET['warning'])) {
        echo '<tr><td colspan="2" style="color:#9a6700"><b>Note:</b> '
            . htmlspecialchars((string) $_GET['warning'])
            . '</td></tr>';
    }
}

echo '<tr><td colspan="2">'
    . 'ResultsPack will first try to match the package to a local competition by '
    . 'competition code, then by name/date. If you already know which local competition '
    . 'it belongs to, you can select it explicitly below.'
    . '</td></tr>';

echo '<form method="post" action="WeatherSessionImportAction.php" enctype="multipart/form-data">';
echo '<input type="hidden" name="csrf_token" value="'
    . htmlspecialchars(resultspack_csrf_token())
    . '">';
echo '<input type="hidden" name="MAX_FILE_SIZE" value="20971520">';

echo '<tr><td class="Bold">Weather package</td><td>';
echo '<input type="file" name="weather_package" accept=".json,application/json" required>';
echo '</td></tr>';

echo '<tr><td class="Bold">Local competition</td><td>';
echo '<select name="tournament_id">';
echo '<option value="0">Auto-match from package</option>';

foreach ($tournaments as $tournament) {
    $label =
        ($tournament['code'] !== '' ? $tournament['code'] . ' — ' : '')
        . $tournament['name'];

    if (!empty($tournament['date_from'])) {
        $label .= ' — ' . $tournament['date_from'];
    }

    echo '<option value="' . (int) $tournament['id'] . '">'
        . htmlspecialchars($label)
        . '</option>';
}

echo '</select>';
echo '</td></tr>';

echo '<tr><td colspan="2">';
echo '<input type="submit" value="Import weather package">';
echo '</td></tr>';
echo '</form>';

echo '</table>';

include('Common/Templates/tail.php');
