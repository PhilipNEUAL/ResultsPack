<?php
function resultspack_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function resultspack_normalise_whitespace($value)
{
    return trim(preg_replace('/\s+/u', ' ', (string) $value));
}

function resultspack_normalise_key($value)
{
    $value = resultspack_normalise_whitespace($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    return (string) $value;
}

function resultspack_normalise_class_description($value)
{
    $value = resultspack_normalise_whitespace($value);
    return $value !== '' ? $value : 'Unclassified';
}

function resultspack_format_date($value)
{
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d-m-Y', $timestamp) : resultspack_normalise_whitespace($value);
}

function resultspack_human_date($value)
{
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return resultspack_normalise_whitespace($value);
    }
    return date('j F Y', $timestamp);
}

function resultspack_event_date($value, $style = 'short')
{
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return resultspack_normalise_whitespace($value);
    }
    if (strtolower((string) $style) === 'long') {
        return date('l \t\h\e jS \o\f F Y', $timestamp);
    }
    return date('d-m-Y', $timestamp);
}

function resultspack_slug($value)
{
    $value = resultspack_normalise_key($value);
    return $value !== '' ? $value : 'results-pack';
}

function resultspack_compare_score_tuple(array $left, array $right)
{
    foreach (array('score', 'golds', 'xs', 'hits') as $field) {
        $comparison = ((int) ($left[$field] ?? 0)) <=> ((int) ($right[$field] ?? 0));
        if ($comparison !== 0) {
            return $comparison;
        }
    }
    return 0;
}

function resultspack_record_status_text($value, array $source = array())
{
    $status = strtolower(resultspack_normalise_whitespace($value));
    if ($status === 'uk') {
        $lines = array('UK Record Status.');
        if (!empty($source['record_status_h2h'])) {
            $lines[] = 'Head-to-Head (H2H).';
        }
        if (!empty($source['record_status_arrowhead'])) {
            $lines[] = 'Arrowhead.';
        }
        if (!empty($source['record_status_tassel'])) {
            $lines[] = 'Tassel.';
        }
        return implode("\n", $lines);
    }
    if ($status === 'world') {
        $lines = array('World Record Status.');
        if (!empty($source['record_status_h2h'])) {
            $lines[] = 'Head-to-Head (H2H).';
        }
        if (!empty($source['record_status_target_awards'])) {
            $lines[] = 'World Archery Target Awards.';
        }
        if (!empty($source['record_status_star_awards'])) {
            $lines[] = 'World Archery Star Awards.';
        }
        return implode("\n", $lines);
    }
    $lines = array('No record status.');
    if (!empty($source['record_status_h2h'])) {
        $lines[] = 'Head-to-Head (H2H).';
    }
    return implode("\n", $lines);
}

function resultspack_issue_date($value, $fallback = '')
{
    $value = resultspack_normalise_whitespace($value);
    if ($value !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $value);
        if ($parsed && $parsed->format('Y-m-d') === $value) {
            return $value;
        }
    }
    return $fallback !== '' ? $fallback : date('Y-m-d');
}

function resultspack_issue_label($date, $revision)
{
    $date = resultspack_issue_date($date);
    $revision = max(1, (int) $revision);
    return 'Version: ' . $date . ' · Revision ' . $revision;
}

function resultspack_organiser_text($name, $email)
{
    $name = resultspack_normalise_whitespace($name);
    $email = resultspack_normalise_whitespace($email);
    if ($name !== '' && $email !== '') {
        return $name . "\nE-mail: " . $email;
    }
    if ($email !== '') {
        return 'E-mail: ' . $email;
    }
    return $name;
}

function resultspack_weather_text($source)
{
    if (!empty($source['weather_indoor'])) {
        return 'Indoor event. N/A - indoors.';
    }

    $allowed = array('Sunny', 'Overcast', 'Windy', 'Rain', 'Snow');
    $conditions = isset($source['weather_conditions']) ? $source['weather_conditions'] : array();
    if (!is_array($conditions)) {
        $conditions = array($conditions);
    }
    $parts = array();
    foreach ($allowed as $condition) {
        if (in_array($condition, $conditions, true)) {
            $parts[] = $condition;
        }
    }

    $temperature = resultspack_normalise_whitespace($source['weather_temperature'] ?? '');
    if ($temperature !== '' && is_numeric($temperature)) {
        $parts[] = 'Temperature: ' . (0 + $temperature) . ' °C';
    }

    $humidity = resultspack_normalise_whitespace($source['weather_humidity'] ?? '');
    if ($humidity !== '' && is_numeric($humidity)) {
        $humidityValue = max(0, min(100, (float) $humidity));
        $parts[] = 'Humidity: ' . (0 + $humidityValue) . '%';
    }

    $windSpeed = resultspack_normalise_whitespace($source['weather_wind_speed'] ?? '');
    if ($windSpeed !== '' && is_numeric($windSpeed)) {
        $units = array('mph' => 'mph', 'kmh' => 'km/h', 'ms' => 'm/s');
        $unitKey = strtolower(resultspack_normalise_whitespace($source['weather_wind_unit'] ?? 'mph'));
        $parts[] = 'Wind speed: ' . (0 + $windSpeed) . ' ' . ($units[$unitKey] ?? 'mph');
    }

    $notes = resultspack_normalise_whitespace($source['weather_notes'] ?? '');
    if ($notes !== '') {
        $parts[] = $notes;
    }

    if (!$parts) {
        return '';
    }
    $lines = array();
    foreach ($parts as $part) {
        $lines[] = rtrim($part, '. ') . '.';
    }
    return implode("\n", $lines);
}
