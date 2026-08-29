<?php
require_once(__DIR__ . '/Lib/bootstrap.php');
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false));
    exit;
}
if (!resultspack_validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(array('ok' => false));
    exit;
}
$key = resultspack_normalise_whitespace($_POST['key'] ?? '');
$payload = (string) ($_POST['payload'] ?? '');
if (!preg_match('/^tournaments:\d+(?:,\d+)*$/', $key) || $payload === '' || strlen($payload) > 262144) {
    http_response_code(400);
    echo json_encode(array('ok' => false));
    exit;
}
$decoded = json_decode($payload, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(array('ok' => false));
    exit;
}
$ok = resultspack_save_settings($key, $decoded);
echo json_encode(array('ok' => (bool) $ok));
