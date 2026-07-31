<?php
/**
 * API: Terminar uma livestream
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Utilizador não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido']);
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$input     = json_decode(file_get_contents('php://input'), true);
$stream_id = (int)($input['stream_id'] ?? 0);

if ($stream_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'stream_id inválido']);
    exit;
}

try {
    // Verificar propriedade
    $stmt = $pdo->prepare("SELECT id, status FROM livestreams WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$stream_id, $current_user_id]);
    $stream = $stmt->fetch();

    if (!$stream) {
        http_response_code(404);
        echo json_encode(['error' => 'Stream não encontrada']);
        exit;
    }

    if ($stream['status'] === 'ended') {
        echo json_encode(['success' => true, 'message' => 'Stream já estava terminada']);
        exit;
    }

    // Marcar como terminada
    $stmt = $pdo->prepare("
        UPDATE livestreams 
        SET status = 'ended', ended_at = NOW(), viewers_count = 0 
        WHERE id = ?
    ");
    $stmt->execute([$stream_id]);

    echo json_encode(['success' => true, 'stream_id' => $stream_id]);
} catch (Exception $e) {
    error_log('❌ end_stream.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao terminar stream']);
}
