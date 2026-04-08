<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;

if ($conversation_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Conversa inválida']);
    exit;
}

try {
    // Verificar se o utilizador faz parte da conversa
    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)");
    $stmt->execute([$conversation_id, $current_user_id, $current_user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Conversa não encontrada']);
        exit;
    }

    // Inserir ou ignorar se já escondida
    $stmt = $pdo->prepare("INSERT IGNORE INTO hidden_conversations (user_id, conversation_id) VALUES (?, ?)");
    $stmt->execute([$current_user_id, $conversation_id]);

    echo json_encode(['success' => true, 'message' => 'Conversa escondida']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao esconder conversa']);
}
