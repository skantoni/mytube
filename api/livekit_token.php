<?php
/**
 * api/livekit_token.php — Endpoint para gerar tokens LiveKit
 *
 * POST {stream_id, role: 'publisher'|'subscriber'}
 * Retorna: { token: "eyJ...", livekit_url: "wss://..." }
 */
require_once '../includes/config.php';
require_once '../includes/livekit_jwt.php';

header('Content-Type: application/json');

// 1. Autenticação obrigatória para publishers; viewers podem ser anónimos
if (!isLoggedIn() && ($_POST['role'] ?? '') === 'publisher') {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

// 2. Método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// 3. CSRF (só para utilizadores autenticados)
if (isLoggedIn() && !csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido']);
    exit;
}

// 4. Input
$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$streamId  = (int)($input['stream_id'] ?? 0);
$role      = trim($input['role'] ?? 'subscriber'); // 'publisher' ou 'subscriber'

if ($streamId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'stream_id inválido']);
    exit;
}

// 5. Verificar que a stream existe e está ativa
try {
    $stmt = $pdo->prepare("SELECT id, user_id, status FROM livestreams WHERE id = ? LIMIT 1");
    $stmt->execute([$streamId]);
    $stream = $stmt->fetch();
} catch (Exception $e) {
    error_log('❌ livekit_token.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
    exit;
}

if (!$stream) {
    http_response_code(404);
    echo json_encode(['error' => 'Stream não encontrada']);
    exit;
}

// 6. Definir identidade e permissões
if (isLoggedIn()) {
    $userId      = (int)$_SESSION['user_id'];
    $username    = $_SESSION['username'] ?? 'user_' . $userId;
    $identity    = 'user_' . $userId;
    $displayName = $username;
} else {
    // Viewer anónimo
    $identity    = 'anon_' . substr(md5(uniqid()), 0, 8);
    $displayName = 'Visitante';
}

// Só o dono da stream pode ser publisher
$isOwner     = isLoggedIn() && ((int)$_SESSION['user_id'] === (int)$stream['user_id']);
$canPublish  = $isOwner && $role === 'publisher';
$canSubscribe = !$canPublish; // Publisher não precisa de subscrever a si próprio

// 7. Nome da sala no LiveKit (prefixo "stream_" + ID)
$roomName = 'stream_' . $streamId;

// 8. Gerar token
try {
    $token = livekit_generate_token(
        roomName:     $roomName,
        identity:     $identity,
        displayName:  $displayName,
        canPublish:   $canPublish,
        canSubscribe: $canSubscribe,
    );
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// 9. URL pública do LiveKit (via Nginx proxy)
$livekitPublicUrl = env('LIVEKIT_URL', 'wss://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/livekit/');

echo json_encode([
    'success'     => true,
    'token'       => $token,
    'livekit_url' => $livekitPublicUrl,
    'room'        => $roomName,
    'identity'    => $identity,
    'can_publish' => $canPublish,
]);
