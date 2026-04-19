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

try {
    // Marcar todas as notificações do usuário como lidas
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $affected_personal = $stmt->rowCount();
    
    // Marcar todas as notificações globais como lidas
    $stmt_global = $pdo->prepare("
        INSERT IGNORE INTO user_global_reads (user_id, global_notification_id)
        SELECT ?, id FROM global_notifications WHERE created_at >= (SELECT created_at FROM users WHERE id = ?)
    ");
    $stmt_global->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $affected_global = $stmt_global->rowCount();
    
    $affected = $affected_personal + $affected_global;
    
    echo json_encode([
        'success' => true,
        'marked_count' => $affected
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
?>
