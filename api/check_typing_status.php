<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$other_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($other_user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Usuário inválido']);
    exit;
}

try {
    // Buscar conversa
    $stmt = $pdo->prepare("
        SELECT id FROM conversations 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        echo json_encode(['success' => true, 'is_typing' => false]);
        exit;
    }
    
    $conversation_id = $conversation['id'];
    
    // Verificar se o outro usuário está digitando
    $stmt = $pdo->prepare("
        SELECT is_typing, updated_at
        FROM typing_status
        WHERE conversation_id = ? AND user_id = ?
        AND updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
    ");
    $stmt->execute([$conversation_id, $other_user_id]);
    $status = $stmt->fetch();
    
    $is_typing = $status ? (bool)$status['is_typing'] : false;
    
    echo json_encode([
        'success' => true,
        'is_typing' => $is_typing
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao verificar status: ' . $e->getMessage()
    ]);
}
?>
