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

$action = strtolower(resultspack_normalise_whitespace($_POST['action'] ?? ''));
$key = resultspack_normalise_whitespace($_POST['key'] ?? '');
$ok = false;

try {
    if ($action === 'add') {
        $key = resultspack_add_award_library_item($_POST['kind'] ?? 'award');
        $ok = $key !== '';
    } elseif ($action === 'save') {
        $ok = resultspack_update_award_library_item($key, array(
            'name' => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
            'category' => $_POST['category'] ?? '',
        ));
    } elseif ($action === 'delete') {
        $ok = resultspack_delete_award_library_item($key);
    } elseif ($action === 'move') {
        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
        $ok = resultspack_move_award_library_item($key, $direction);
    }
} catch (Exception $e) {
    $ok = false;
}

echo json_encode(array('ok' => (bool) $ok, 'key' => $key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
