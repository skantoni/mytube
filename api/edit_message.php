<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
$new_content = isset($_POST['content']) ? trim($_POST['content']) : '';

if ($message_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Mensagem inválida']);
    exit;
}

if (empty($new_content)) {
    echo json_encode(['success' => false, 'error' => 'Conteúdo da mensagem não pode ser vazio']);
    exit;
}

try {
    // Verificar se a mensagem pertence ao usuário e é do tipo texto
    $stmt = $pdo->prepare("
        SELECT id, sender_id, conversation_id, type, created_at 
        FROM messages 
        WHERE id = ? AND sender_id = ?
    ");
    $stmt->execute([$message_id, $current_user_id]);
    $message = $stmt->fetch();

    if (!$message) {
        echo json_encode(['success' => false, 'error' => 'Mensagem não encontrada ou sem permissão']);
        exit;
    }

    // Permite editar texto e legenda de imagens e vídeos
    if (!in_array($message['type'], ['text', 'image', 'video'])) {
        echo json_encode(['success' => false, 'error' => 'Apenas mensagens de texto podem ser editadas']);
        exit;
    }

    // Limite de 5 minutos para editar
    $created_time = strtotime($message['created_at']);
    $current_time = time();
    $time_diff = $current_time - $created_time;

    if ($time_diff > 300) { // 5 min = 300 segundos
        echo json_encode(['success' => false, 'error' => 'Tempo limite excedido para editar (5 minutos)']);
        exit;
    }

    // Atualizar a mensagem
    $stmt = $pdo->prepare("UPDATE messages SET message = ?, is_edited = 1 WHERE id = ?");
    $stmt->execute([$new_content, $message_id]);

    echo json_encode([
        'success' => true, 
        'message_id' => $message_id,
        'content' => $new_content,
        'conversation_id' => $message['conversation_id']
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao editar mensagem: ' . $e->getMessage()
    ]);
}
