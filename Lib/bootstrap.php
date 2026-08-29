<?php
if (!defined('RESULTSPACK_MODULE_ROOT')) {
    define('RESULTSPACK_MODULE_ROOT', dirname(__DIR__));
}
require_once(dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/config.php');
require_once('Common/Fun_FormatText.inc.php');
require_once(__DIR__ . '/format.php');
require_once(__DIR__ . '/repository.php');
require_once(__DIR__ . '/combined.php');
require_once(__DIR__ . '/teams.php');

function resultspack_csrf_token()
{
    if (empty($_SESSION['ResultsPackCsrfToken']) || !is_string($_SESSION['ResultsPackCsrfToken'])) {
        $token = '';
        if (function_exists('random_bytes')) {
            try {
                $token = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $token = '';
            }
        }
        if ($token === '') {
            $token = sha1(uniqid('resultspack-', true) . mt_rand());
        }
        $_SESSION['ResultsPackCsrfToken'] = $token;
    }
    return $_SESSION['ResultsPackCsrfToken'];
}

function resultspack_validate_csrf($token)
{
    $expected = resultspack_csrf_token();
    $token = is_string($token) ? $token : '';
    if ($token === '') {
        return false;
    }
    if (function_exists('hash_equals')) {
        return hash_equals($expected, $token);
    }
    return $expected === $token;
}
