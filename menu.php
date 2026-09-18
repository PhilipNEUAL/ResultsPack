<?php
if (!isset($ret['RESULTSPACK'])) {
    $ret['RESULTSPACK'][] = 'Results Pack';
}

$ret['RESULTSPACK'][] =
    'Build results sheet|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/';

$ret['RESULTSPACK'][] =
    'Weather monitor / session control|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/TempestTest.php';

$ret['RESULTSPACK'][] =
    'Weather session viewer|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/WeatherSessionView.php';
