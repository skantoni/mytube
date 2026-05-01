<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$is_online = isset($_POST['is_online']) ? (bool)$_POST['is_online'] : true;

try {
    // Atualizar ou inserir status online
    $stmt = $pdo->prepare("
        INSERT INTO user_online_status (user_id, is_online, last_seen)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            is_online = VALUES(is_online),
            last_seen = NOW()
    ");
    $stmt->execute([$current_user_id, (int)$is_online]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao atualizar status: ' . $e->getMessage()
    ]);
}
?>
