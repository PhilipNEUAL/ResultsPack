<?php
require_once(__DIR__ . '/Lib/bootstrap.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function resultspack_settings_reply($status, array $data)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    resultspack_settings_reply(405, array('ok' => false, 'error' => 'Please use the results-pack form.'));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!resultspack_validate_csrf($_POST['csrf_token'] ?? '')) {
    resultspack_settings_reply(403, array('ok' => false, 'error' => 'Your session has expired. Copy any unsaved text before reloading the builder.'));
}

$key = $_POST['key'] ?? '';
$action = $_POST['action'] ?? 'save';
$generation = $_POST['generation'] ?? '';

if (
    !is_string($key)
    || strlen($key) > 190
    || !preg_match('/\Atournaments:[1-9][0-9]*(?:,[1-9][0-9]*)*\z/', $key)
    || !in_array($action, array('save', 'reset'), true)
    || !is_string($generation)
    || !preg_match('/\A[0-9]{1,10}\z/', $generation)
) {
    resultspack_settings_reply(400, array('ok' => false, 'error' => 'The settings request is invalid. Copy unsaved text and reload the builder.'));
}

// The PHP session lock serialises writes from this browser session.
// Reset advances the generation so older tabs should not be able to restore discarded data.
$currentGeneration = (int) ($_SESSION['ResultsPackSettingsGeneration'][$key] ?? 0);

if ((int) $generation !== $currentGeneration) {
    resultspack_settings_reply(409, array('ok' => false, 'error' => 'This form was reset in another tab. Copy anything you want to keep, then reload this tab before saving again.'));
}

if ($action === 'reset') {
    // Keep an empty saved form so old browser drafts are not restored.
    $json = '{"version":2,"fields":{}}';
} else {
    $payload = $_POST['payload'] ?? '';

    if (!is_string($payload) || $payload === '') {
        resultspack_settings_reply(400, array('ok' => false, 'error' => 'No form settings were received.'));
    }

    if (strlen($payload) > 262144) {
        resultspack_settings_reply(413, array('ok' => false, 'error' => 'The form exceeds the 256 KiB saved-settings limit. Shorten large pasted text, or use Advanced → Reset this form.'));
    }

    try {
        $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 2 || !isset($decoded['fields']) || !is_array($decoded['fields'])) {
            throw new InvalidArgumentException();
        }
        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        resultspack_settings_reply(400, array('ok' => false, 'error' => 'The form settings could not be read. Copy unsaved text and reload the builder.'));
    }

    if (strlen($json) > 262144) {
        resultspack_settings_reply(413, array('ok' => false, 'error' => 'The form exceeds the 256 KiB saved-settings limit. Shorten large pasted text or reset this form.'));
    }
}

try {
    global $WRIT_CON;
    if (!$WRIT_CON) {
        $WRIT_CON = safe_w_con();
    }

    // The builder creates this table when it loads settings.
    $keySql = "'" . mysqli_real_escape_string($WRIT_CON, $key) . "'";
    $jsonSql = "'" . mysqli_real_escape_string($WRIT_CON, $json) . "'";
    $saved = mysqli_query(
        $WRIT_CON,
        'INSERT INTO CustomResultsPackSettings (CrpsKey, CrpsJson, CrpsUpdated) VALUES ('
        . $keySql . ',' . $jsonSql . ',NOW()) '
        . 'ON DUPLICATE KEY UPDATE CrpsJson=VALUES(CrpsJson), CrpsUpdated=VALUES(CrpsUpdated)'
    );

    if ($saved === false) {
        throw new RuntimeException('Settings write failed', mysqli_errno($WRIT_CON));
    }
} catch (Throwable $exception) {
    $code = (int) $exception->getCode();
    $message = 'The IANSEO database could not confirm the save. Please try again.';
    if (in_array($code, array(1044, 1045, 1142, 1143), true)) {
        $message = 'The database account cannot write ResultsPack settings. Ask the IANSEO administrator to check its permissions.';
    } elseif ($code === 1146) {
        $message = 'The ResultsPack settings table is missing. Reload the builder to initialise it.';
    } elseif (in_array($code, array(2002, 2003, 2006, 2013), true)) {
        $message = 'The database connection was unavailable or interrupted. Check that MySQL is running, then try again.';
    }
    resultspack_settings_reply(500, array('ok' => false, 'error' => $message));
}

if ($action === 'reset') {
    $currentGeneration++;
    $_SESSION['ResultsPackSettingsGeneration'][$key] = $currentGeneration;
}

resultspack_settings_reply(200, array('ok' => true, 'generation' => $currentGeneration));
