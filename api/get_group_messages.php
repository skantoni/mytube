<?php
/**
 * API: Buscar mensagens de um grupo
 * GET /api/get_group_messages.php?group_id=N&before_id=N&limit=50
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user_id  = (int) $_SESSION['user_id'];
$group_id = (int) ($_GET['group_id'] ?? 0);
$before_id = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
$limit    = min(100, max(1, (int) ($_GET['limit'] ?? 50)));

if ($group_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'group_id inválido.']);
    exit;
}

try {
    // Verificar que o utilizador é membro do grupo
    $chk = $pdo->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
    $chk->execute([$group_id, $user_id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Não és membro deste grupo.']);
        exit;
    }

    $params = [$group_id];
    $before_clause = '';
    if ($before_id !== null && $before_id > 0) {
        $before_clause = 'AND gm.id < ?';
        $params[] = $before_id;
    }

    $stmt = $pdo->prepare("
        SELECT
            gm.id,
            gm.group_id,
            gm.sender_id,
            gm.message,
            gm.type,
            gm.file_url,
            gm.reply_to_message_id,
            gm.is_deleted,
            gm.is_edited,
            gm.created_at,
            gm.updated_at,
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
        WHERE gm.group_id = ? AND gm.is_deleted = 0
          {$before_clause}
        ORDER BY gm.id DESC
        LIMIT ?
    ");
    $params[] = $limit;
    $stmt->execute($params);
    $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['success' => true, 'messages' => $messages, 'group_id' => $group_id]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar mensagens: ' . $e->getMessage()]);
}
