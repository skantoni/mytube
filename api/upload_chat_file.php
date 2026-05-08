<?php
require_once '../includes/config.php';
require_once '../includes/chat_upload_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
    exit;
}

$type = isset($_POST['type']) ? $_POST['type'] : 'file';
$receiver_id = isset($_POST['receiver_id']) ? intval($_POST['receiver_id']) : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($receiver_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Destinatário inválido']);
    exit;
}

// Verificar se são amigos (admin/vip ou destinatário com caixa aberta ignoram restrição)
if (!canMessageAnyone()) {
    $inbox_check = $pdo->prepare("SELECT open_inbox FROM users WHERE id = ? LIMIT 1");
    $inbox_check->execute([$receiver_id]);
    $receiver_row = $inbox_check->fetch();
    if (!$receiver_row || !$receiver_row['open_inbox']) {
        $fr_check = $pdo->prepare("
            SELECT id FROM friend_requests 
            WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
              AND status = 'accepted'
            LIMIT 1
        ");
        $fr_check->execute([$_SESSION['user_id'], $receiver_id, $receiver_id, $_SESSION['user_id']]);
        if (!$fr_check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Precisas ser amigo deste utilizador para enviar ficheiros.']);
            exit;
        }
    }
}

try {
    // Upload do arquivo
    $upload_result = uploadChatFile($_FILES['file'], $type);
    
    if (!$upload_result['success']) {
        echo json_encode(['success' => false, 'error' => $upload_result['error']]);
        exit;
    }
    
    $current_user_id = $_SESSION['user_id'];
    $file_url = $upload_result['url'];
    
    // Criar ou buscar conversa
    $stmt = $pdo->prepare("
        SELECT id FROM conversations 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$current_user_id, $receiver_id, $receiver_id, $current_user_id]);
    $conversation = $stmt->fetch();
    
    if ($conversation) {
        $conversation_id = $conversation['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO conversations (user1_id, user2_id) VALUES (?, ?)");
        $stmt->execute([$current_user_id, $receiver_id]);
        $conversation_id = $pdo->lastInsertId();
    }
    
    // Inserir mensagem com arquivo
    $stmt = $pdo->prepare("
        INSERT INTO messages 
        (conversation_id, sender_id, receiver_id, message, type, file_url, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'sent')
    ");
    $stmt->execute([
        $conversation_id, 
        $current_user_id, 
        $receiver_id, 
        $message, 
        $type, 
        $file_url
    ]);
    $message_id = $pdo->lastInsertId();
    
    // Atualizar conversa
    $stmt = $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$conversation_id]);

    // Remover conversa da lista de escondidas para ambos (nova mensagem = conversa volta à superfície)
    $stmt = $pdo->prepare("DELETE FROM hidden_conversations WHERE conversation_id = ?");
    $stmt->execute([$conversation_id]);

    // Buscar mensagem completa
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            u.username as sender_username,
            u.profile_picture as sender_picture
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.id = ?
    ");
    $stmt->execute([$message_id]);
    $message_data = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'message' => $message_data,
        'file_info' => [
            'url' => $file_url,
            'filename' => $upload_result['filename'],
            'size' => $upload_result['size'],
            'type' => $type
        ]
    ]);
    
} catch (Exception $e) {
    // Se houver erro, tentar deletar o arquivo enviado
    if (isset($file_url)) {
        deleteChatFile($file_url);
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao enviar arquivo: ' . $e->getMessage()
    ]);
}
?>
