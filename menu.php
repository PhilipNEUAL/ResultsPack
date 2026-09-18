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
    'TWIT|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/TempestTest.php';

$weatherViewer =
    'Weather session viewer|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/WeatherSessionView.php';

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