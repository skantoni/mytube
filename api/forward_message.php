<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$sender_id = (int) $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
$receiver_ids_raw = isset($_POST['receiver_ids']) ? $_POST['receiver_ids'] : '';

// Suportar tanto receiver_ids (múltiplos) quanto receiver_id (único)
if (!empty($receiver_ids_raw)) {
    $receiver_ids = array_map('intval', explode(',', $receiver_ids_raw));
} elseif (isset($_POST['receiver_id'])) {
    $receiver_ids = [(int) $_POST['receiver_id']];
} else {
    $receiver_ids = [];
}

// Validações
if ($message_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Mensagem inválida']);
    exit;
}

if (empty($receiver_ids)) {
    echo json_encode(['success' => false, 'error' => 'Destinatário(s) inválido(s)']);
    exit;
}

// Remover IDs inválidos e o próprio utilizador
$receiver_ids = array_filter($receiver_ids, function($id) use ($sender_id) {
    return $id > 0 && $id !== $sender_id;
});

if (empty($receiver_ids)) {
    echo json_encode(['success' => false, 'error' => 'Nenhum destinatário válido']);
    exit;
}

try {
    // Buscar a mensagem original
    $stmt = $pdo->prepare("
        SELECT m.id, m.message, m.type, m.file_url, m.sender_id, 
               u.username as original_username
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.id = ? AND m.deleted_for_all = 0
    ");
    $stmt->execute([$message_id]);
    $original_message = $stmt->fetch();
    
    if (!$original_message) {
        echo json_encode(['success' => false, 'error' => 'Mensagem não encontrada']);
        exit;
    }
    
    // Buscar dados do remetente
    $stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
    $stmt->execute([$sender_id]);
    $sender = $stmt->fetch();
    
    $forwarded_messages = [];
    $errors = [];
    
    foreach ($receiver_ids as $receiver_id) {
        // Verificar se o destinatário existe
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$receiver_id]);
        if (!$stmt->fetch()) {
            $errors[] = "Utilizador $receiver_id não encontrado";
            continue;
        }
        
        // Buscar ou criar conversa
        $stmt = $pdo->prepare("
            SELECT id FROM conversations 
            WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)
        ");
        $stmt->execute([$sender_id, $receiver_id, $receiver_id, $sender_id]);
        $conversation = $stmt->fetch();
        
        if ($conversation) {
            $conversation_id = $conversation['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO conversations (user1_id, user2_id, created_at, updated_at) 
                VALUES (?, ?, NOW(), NOW())
            ");
            $stmt->execute([$sender_id, $receiver_id]);
            $conversation_id = $pdo->lastInsertId();
        }
        
        // Inserir mensagem reencaminhada
        $stmt = $pdo->prepare("
            INSERT INTO messages (
                conversation_id, sender_id, receiver_id, message, type, file_url,
                forwarded_from_message_id, forwarded_from_user_id, forwarded_from_username,
                status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW())
        ");
        $stmt->execute([
            $conversation_id,
            $sender_id,
            $receiver_id,
            $original_message['message'],
            $original_message['type'],
            $original_message['file_url'],
            $original_message['id'],
            $original_message['sender_id'],
            $original_message['original_username']
        ]);
        $new_message_id = $pdo->lastInsertId();
        
        // Atualizar timestamp da conversa
        $stmt = $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$conversation_id]);
        
        // Remover conversa de ocultas
        $stmt = $pdo->prepare("DELETE FROM hidden_conversations WHERE conversation_id = ?");
        $stmt->execute([$conversation_id]);
        
        $forwarded_messages[] = [
            'message_id' => $new_message_id,
            'conversation_id' => $conversation_id,
            'receiver_id' => $receiver_id
        ];
        
        // Notificar Node.js para entrega em tempo real
        $node_url = 'http://localhost:3001/api/notify-forward';
        $payload = json_encode([
            'message_id' => $new_message_id,
            'conversation_id' => $conversation_id,
            'sender_id' => $sender_id,
            'receiver_id' => $receiver_id,
            'message' => $original_message['message'],
            'type' => $original_message['type'],
            'file_url' => $original_message['file_url'],
            'forwarded_from_message_id' => $original_message['id'],
            'forwarded_from_user_id' => $original_message['sender_id'],
            'forwarded_from_username' => $original_message['original_username'],
            'sender_username' => $sender['username'] ?? '',
            'sender_avatar' => $sender['profile_picture'] ?? ''
        ]);
        
        $ch = curl_init($node_url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    $count = count($forwarded_messages);
    echo json_encode([
        'success' => true,
        'forwarded_count' => $count,
        'forwarded_messages' => $forwarded_messages,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao reencaminhar mensagem']);
}
?>
