<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

// Pegar todas informações relevantes
$debug_info = [
    'session_started' => session_status() === PHP_SESSION_ACTIVE,
    'session_id' => session_id(),
    'csrf_token_in_session' => isset($_SESSION['csrf_token']) ? 'SIM' : 'NÃO',
    'csrf_token_value' => $_SESSION['csrf_token'] ?? null,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => $_POST,
    'get_data' => $_GET,
    'server_headers' => [
        'HTTP_X_CSRF_TOKEN' => $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 'não encontrado',
        'HTTP_X_XSRF_TOKEN' => $_SERVER['HTTP_X_XSRF_TOKEN'] ?? 'não encontrado',
    ],
    'all_headers' => function_exists('getallheaders') ? getallheaders() : 'getallheaders não disponível',
    'csrf_verify_result' => null
];

// Testar csrf_verify()
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debug_info['csrf_verify_result'] = csrf_verify() ? 'VÁLIDO' : 'INVÁLIDO';
}

echo json_encode($debug_info, JSON_PRETTY_PRINT);
