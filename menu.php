<?php

if (!isset($ret['RESULTSPACK']) || !is_array($ret['RESULTSPACK'])) {
    $ret['RESULTSPACK'] = array();
}

$resultsPackTitle = 'Results Pack';

$buildResults =
    'Build results sheet|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/';

$weatherMonitor =
    'Weather monitor / session control|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/TempestTest.php';

$weatherViewer =
    'Weather session viewer|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/WeatherSessionView.php';

$weatherTransfer =
    'Weather data export / import|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/WeatherTransfer.php';

if (!in_array($resultsPackTitle, $ret['RESULTSPACK'], true)) {
    array_unshift($ret['RESULTSPACK'], $resultsPackTitle);
}
if (!in_array($buildResults, $ret['RESULTSPACK'], true)) {
    $ret['RESULTSPACK'][] = $buildResults;
}
if (!in_array($weatherMonitor, $ret['RESULTSPACK'], true)) {
    $ret['RESULTSPACK'][] = $weatherMonitor;
}
if (!in_array($weatherViewer, $ret['RESULTSPACK'], true)) {
    $ret['RESULTSPACK'][] = $weatherViewer;
}
if (!in_array($weatherTransfer, $ret['RESULTSPACK'], true)) {
    $ret['RESULTSPACK'][] = $weatherTransfer;
}
