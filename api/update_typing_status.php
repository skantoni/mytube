<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$other_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
$is_typing = isset($_POST['is_typing']) ? (bool)$_POST['is_typing'] : false;

if ($other_user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Usuário inválido']);
    exit;
}

try {
    // Buscar ou criar conversa
    $stmt = $pdo->prepare("
        SELECT id FROM conversations 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'error' => 'Conversa não encontrada']);
        exit;
    }
    
    $conversation_id = $conversation['id'];
    
    // Atualizar ou inserir status de digitação
    $stmt = $pdo->prepare("
        INSERT INTO typing_status (conversation_id, user_id, is_typing, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            is_typing = VALUES(is_typing),
            updated_at = NOW()
    ");
    $stmt->execute([$conversation_id, $current_user_id, (int)$is_typing]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao atualizar status: ' . $e->getMessage()
    ]);
}
?>
