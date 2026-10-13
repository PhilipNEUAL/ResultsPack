<?php

if (!isset($ret['RESULTSPACK']) || !is_array($ret['RESULTSPACK'])) {
    $ret['RESULTSPACK'] = array();
}

$resultsPackTitle = 'Results Pack';

$buildResults =
    'Build results sheet|'
    . $CFG->ROOT_DIR
    . 'Modules/Custom/ResultsPack/';

if (!in_array($resultsPackTitle, $ret['RESULTSPACK'], true)) {
    array_unshift($ret['RESULTSPACK'], $resultsPackTitle);
}

if (!in_array($buildResults, $ret['RESULTSPACK'], true)) {
    $ret['RESULTSPACK'][] = $buildResults;
}