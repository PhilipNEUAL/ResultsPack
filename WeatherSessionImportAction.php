<?php

require_once(__DIR__ . '/Lib/bootstrap.php');
require_once(__DIR__ . '/Lib/weather.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST request required.');
}

if (!resultspack_validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid request token.');
}

function resultspack_weather_transfer_redirect_error($message)
{
    header(
        'Location: WeatherTransfer.php?import_error=' .
        rawurlencode((string) $message)
    );
    exit;
}

if (!isset($_FILES['weather_package'])) {
    resultspack_weather_transfer_redirect_error(
        'No weather package was selected.'
    );
}

$file = $_FILES['weather_package'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

if ($error !== UPLOAD_ERR_OK) {
    $messages = array(
        UPLOAD_ERR_INI_SIZE => 'The weather package is larger than this PHP installation allows.',
        UPLOAD_ERR_FORM_SIZE => 'The weather package is larger than the form allows.',
        UPLOAD_ERR_PARTIAL => 'The weather package upload was interrupted.',
        UPLOAD_ERR_NO_FILE => 'No weather package was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded weather package.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the weather-package upload.',
    );

    resultspack_weather_transfer_redirect_error(
        $messages[$error] ?? 'The weather package upload failed.'
    );
}

$size = (int) ($file['size'] ?? 0);

if ($size <= 0) {
    resultspack_weather_transfer_redirect_error(
        'The selected weather package is empty.'
    );
}

if ($size > 20 * 1024 * 1024) {
    resultspack_weather_transfer_redirect_error(
        'The selected weather package is larger than the 20 MB safety limit.'
    );
}

$tmpName = (string) ($file['tmp_name'] ?? '');

if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    resultspack_weather_transfer_redirect_error(
        'The uploaded weather package could not be verified.'
    );
}

$json = file_get_contents($tmpName);

if ($json === false || trim($json) === '') {
    resultspack_weather_transfer_redirect_error(
        'The uploaded weather package could not be read.'
    );
}

$package = json_decode($json, true);

if (!is_array($package)) {
    resultspack_weather_transfer_redirect_error(
        'The selected file is not valid JSON weather-package data.'
    );
}

$tournamentId = (int) ($_POST['tournament_id'] ?? 0);

$result = resultspack_weather_import_transfer_package(
    $package,
    $tournamentId
);

if (!$result['ok']) {
    resultspack_weather_transfer_redirect_error(
        $result['error'] ?? 'The weather package could not be imported.'
    );
}

$query = array(
    'imported' => 1,
    'session_id' => (int) $result['session_id'],
    'existing' => !empty($result['existing_session']) ? 1 : 0,
    'obs_received' => (int) ($result['observations_received'] ?? 0),
    'obs_added' => (int) ($result['observations_added'] ?? 0),
    'events_received' => (int) ($result['events_received'] ?? 0),
    'events_added' => (int) ($result['events_added'] ?? 0),
    'corrections_received' => (int) ($result['corrections_received'] ?? 0),
    'corrections_added' => (int) ($result['corrections_added'] ?? 0),
    'match' => (string) ($result['tournament_match'] ?? ''),
);

$quality = $result['quality'] ?? null;

if ($quality && $quality['coverage_percent'] !== null) {
    $query['coverage'] = number_format(
        (float) $quality['coverage_percent'],
        1,
        '.',
        ''
    );
    $query['missing'] = (int) ($quality['missing'] ?? 0);
}

if (!empty($result['warning'])) {
    $query['warning'] = (string) $result['warning'];
}

header('Location: WeatherTransfer.php?' . http_build_query($query));
exit;
