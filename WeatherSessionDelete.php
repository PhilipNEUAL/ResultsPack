<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

$PAGE_TITLE = 'Delete Weather Session';

include('Common/Templates/head.php');

$sessionId = (int) ($_GET['session_id'] ?? 0);

$preview =
    resultspack_weather_delete_preview($sessionId);

echo '<table class="Tabella freeWidth">';
echo '<tr><th class="Main" colspan="2">Delete Test Weather Session</th></tr>';

if (!$preview['ok']) {
    echo '<tr><td colspan="2">'
        . htmlspecialchars($preview['error'])
        . '</td></tr>';

    echo '<tr><td colspan="2">'
        . '<a href="WeatherControl.php">Return to weather session control</a>'
        . '</td></tr>';

    echo '</table>';

    include('Common/Templates/tail.php');
    exit;
}

$session = $preview['session'];

if ($session['research_status'] !== 'test') {
    echo '<tr><td colspan="2"><b>This session is protected.</b> '
        . 'Only sessions marked Test may be permanently deleted.'
        . '</td></tr>';

    echo '<tr><td colspan="2">'
        . '<a href="WeatherControl.php">Return to weather session control</a>'
        . '</td></tr>';

    echo '</table>';

    include('Common/Templates/tail.php');
    exit;
}

if ($session['ended_epoch'] === null) {
    echo '<tr><td colspan="2"><b>This session is still active.</b> '
        . 'Stop it before attempting deletion.'
        . '</td></tr>';

    echo '<tr><td colspan="2">'
        . '<a href="WeatherControl.php">Return to weather session control</a>'
        . '</td></tr>';

    echo '</table>';

    include('Common/Templates/tail.php');
    exit;
}

try {
    $started = new DateTime(
        '@' . $session['started_epoch']
    );

    $started->setTimezone(
        new DateTimeZone(
            $session['timezone'] ?: 'UTC'
        )
    );

    $startedLabel =
        $started->format('d/m/Y H:i:s T');

} catch (Exception $e) {
    $startedLabel =
        date(
            'd/m/Y H:i:s',
            $session['started_epoch']
        );
}

echo '<tr><td colspan="2" style="background:#fff3cd">';
echo '<b>This permanently removes this Test session and all data attached to it.</b>';
echo '</td></tr>';

echo '<tr><td class="Bold">Session ID</td><td>'
    . (int) $session['id']
    . '</td></tr>';

echo '<tr><td class="Bold">Started</td><td>'
    . htmlspecialchars($startedLabel)
    . '</td></tr>';

echo '<tr><td class="Bold">Research status</td><td>'
    . htmlspecialchars(
        ucfirst($session['research_status'])
    )
    . '</td></tr>';

echo '<tr><td class="Bold">Minute observations</td><td>'
    . (int) $preview['observations']
    . '</td></tr>';

echo '<tr><td class="Bold">Judge events</td><td>'
    . (int) $preview['events']
    . '</td></tr>';

echo '<tr><td class="Bold">Timing-correction audit rows</td><td>'
    . (int) $preview['timing_corrections']
    . '</td></tr>';

echo '<tr><td colspan="2">';

echo '<form method="post" action="WeatherSessionDeleteAction.php">';

echo '<input type="hidden" name="csrf_token" value="'
    . htmlspecialchars(resultspack_csrf_token())
    . '">';

echo '<input type="hidden" name="session_id" value="'
    . (int) $session['id']
    . '">';

echo '<p>To confirm, type <b>DELETE '
    . (int) $session['id']
    . '</b> below:</p>';

echo '<input type="text" name="confirm_delete" '
    . 'autocomplete="off" required>';

echo '<br><br>';

echo '<input type="submit" value="Permanently delete Test session">';

echo ' &nbsp; ';

echo '<a href="WeatherControl.php">Cancel</a>';

echo '</form>';

echo '</td></tr>';

echo '</table>';

include('Common/Templates/tail.php');