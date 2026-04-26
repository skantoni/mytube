<?php
/**
 * API: Enviar mensagem para um grupo
 * POST {group_id, message, reply_to_message_id?}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

csrf_verify_or_die('Token CSRF inválido.');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$group_id   = (int) ($body['group_id'] ?? 0);
$message    = trim($body['message'] ?? '');
$reply_to   = isset($body['reply_to_message_id']) ? (int) $body['reply_to_message_id'] : null;

$user_id = (int) $_SESSION['user_id'];

if ($group_id <= 0 || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos.']);
    exit;
}

if (mb_strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem demasiado longa (máx. 5000 caracteres).']);
    exit;
}

try {
    // Verificar membership
    $chk = $pdo->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
    $chk->execute([$group_id, $user_id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Não és membro deste grupo.']);
        exit;
    }

    // Inserir mensagem
    $ins = $pdo->prepare("
        INSERT INTO group_messages (group_id, sender_id, message, type, reply_to_message_id, created_at, updated_at)
        VALUES (?, ?, ?, 'text', ?, NOW(), NOW())
    ");
    $ins->execute([$group_id, $user_id, $message, $reply_to]);
    $message_id = (int) $pdo->lastInsertId();

    // Atualizar updated_at do grupo
    $pdo->prepare("UPDATE chats SET updated_at = NOW() WHERE id = ?")->execute([$group_id]);

    // Buscar mensagem completa para resposta
    $sel = $pdo->prepare("
        SELECT
            gm.id, gm.group_id, gm.sender_id, gm.message, gm.type, gm.file_url,
            gm.reply_to_message_id, gm.is_deleted, gm.is_edited, gm.created_at,
            u.username AS sender_username,
            u.full_name AS sender_full_name,
            u.profile_picture AS sender_avatar,
            u.is_verified AS sender_is_verified,
            rm.message AS reply_content,
            ru.username AS reply_username
        FROM group_messages gm
        JOIN users u ON gm.sender_id = u.id
        LEFT JOIN group_messages rm ON gm.reply_to_message_id = rm.id
        LEFT JOIN users ru ON rm.sender_id = ru.id
        WHERE gm.id = ?
    ");
    $sel->execute([$message_id]);
    $msg = $sel->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao enviar mensagem: ' . $e->getMessage()]);
}
