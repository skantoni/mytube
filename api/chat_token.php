<?php
require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$secret = getenv('CHAT_JWT_SECRET');
if (!$secret) {
    $secret = defined('CHAT_JWT_SECRET') ? CHAT_JWT_SECRET : 'CHANGE_ME_IN_PRODUCTION';
}

$userId   = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$exp      = time() + 7200; // 2 horas

$header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$payload = base64url_encode(json_encode(['userId' => $userId, 'username' => $username, 'exp' => $exp]));
$sig     = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));

echo json_encode(['token' => "$header.$payload.$sig"]);
