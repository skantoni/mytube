<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

$debug = [
    'session_active' => session_status() === PHP_SESSION_ACTIVE,
    'session_id' => session_id(),
    'user_logged_in' => isLoggedIn(),
    'user_id' => $_SESSION['user_id'] ?? null,
    'csrf_token_in_session' => isset($_SESSION['csrf_token']),
    'csrf_token_value' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 10) . '...' : null,
    'https_detected' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'server_https' => $_SERVER['HTTPS'] ?? 'not set',
    'headers_received' => [
        'HTTP_X_CSRF_TOKEN' => $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 'not set',
        'HTTP_X_XSRF_TOKEN' => $_SERVER['HTTP_X_XSRF_TOKEN'] ?? 'not set',
    ],
    'post_data' => $_POST,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'cookie_params' => session_get_cookie_params(),
    'all_headers' => function_exists('getallheaders') ? getallheaders() : 'getallheaders not available',
];

// Se for POST, testar CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debug['csrf_verify_result'] = csrf_verify();
    $debug['csrf_verify_details'] = [
        'token_from_post' => $_POST['csrf_token'] ?? 'not in POST',
        'token_from_get' => $_GET['csrf_token'] ?? 'not in GET',
        'tokens_match' => isset($_SESSION['csrf_token']) && isset($_POST['csrf_token']) ? 
            hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']) : false,
    ];
}

echo json_encode($debug, JSON_PRETTY_PRINT);
