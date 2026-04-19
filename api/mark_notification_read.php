<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$notification_id = isset($input['notification_id']) ? (int)$input['notification_id'] : 0;
$notif_scope = isset($input['notif_scope']) ? $input['notif_scope'] : 'personal';

if ($notification_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID da notificação é obrigatório']);
    exit;
}

try {
    if ($notif_scope === 'global') {
        // Marcar notificação global como lida pelo usuário atual
        $stmt = $pdo->prepare("INSERT IGNORE INTO user_global_reads (user_id, global_notification_id) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $notification_id]);
    } else {
        // Marcar como lida (apenas se pertence ao usuário)
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $_SESSION['user_id']]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
?>
