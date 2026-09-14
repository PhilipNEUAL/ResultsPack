<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

$PAGE_TITLE = 'Tempest Weather Test';

include('Common/Templates/head.php');

$summary = resultspack_weather_config_summary();

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

include('Common/Templates/tail.php');