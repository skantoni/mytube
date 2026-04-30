<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/push_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de segurança inválido']);
    exit;
}

$sender_id = (int) $_SESSION['user_id'];
$receiver_id = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($receiver_id <= 0 || $receiver_id === $sender_id) {
    echo json_encode(['success' => false, 'error' => 'Destinatário inválido']);
    exit;
}

if (empty($message) || mb_strlen($message) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Mensagem inválida']);
    exit;
}

try {
    // Buscar dados do remetente
    $stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
    $stmt->execute([$sender_id]);
    $sender = $stmt->fetch();
    
    // Verificar se o destinatário existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$receiver_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Utilizador não encontrado']);
        exit;
    }

    // Verificar se são amigos (admin e vip podem enviar a qualquer utilizador)
    if (!canMessageAnyone()) {
        $stmt = $pdo->prepare("
            SELECT id FROM friend_requests 
            WHERE status = 'accepted' 
            AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
        ");
        $stmt->execute([$sender_id, $receiver_id, $receiver_id, $sender_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Só podes enviar mensagens a amigos']);
            exit;
        }
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

    // Inserir mensagem
    $stmt = $pdo->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, message, type, status, created_at) 
        VALUES (?, ?, ?, ?, 'text', 'sent', NOW())
    ");
    $stmt->execute([$conversation_id, $sender_id, $receiver_id, $message]);
    $message_id = $pdo->lastInsertId();

    // Atualizar timestamp da conversa
    $stmt = $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$conversation_id]);

    // Remover conversa da lista de escondidas para ambos (nova mensagem = conversa volta à superfície)
    $stmt = $pdo->prepare("DELETE FROM hidden_conversations WHERE conversation_id = ?");
    $stmt->execute([$conversation_id]);

    // Notificar Node.js para entrega em tempo real
    $node_url = 'http://localhost:3001/api/notify-message';
    $payload = json_encode([
        'message_id' => $message_id,
        'conversation_id' => $conversation_id,
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'message' => $message,
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

    // Push notification para o destinatário
    $senderName = $sender['username'] ?? 'Alguém';
    $msgPreview = mb_substr($message, 0, 80);
    sendPushNotification($pdo, $receiver_id, "💬 $senderName", $msgPreview, "/chat.php");

    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'conversation_id' => $conversation_id
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao enviar mensagem']);
}
?>
