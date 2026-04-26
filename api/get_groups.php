<?php
/**
 * API: Listar grupos do utilizador atual
 * GET /api/get_groups.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

try {
    // Buscar grupos onde o utilizador é participante
    $stmt = $pdo->prepare("
        SELECT
            c.id AS group_id,
            c.name,
            c.group_picture,
            c.created_by,
            c.created_at,
            c.updated_at,
            (SELECT COUNT(*) FROM chat_participants cp2 WHERE cp2.chat_id = c.id) AS member_count,
            (SELECT gm.message
               FROM group_messages gm
              WHERE gm.group_id = c.id AND gm.is_deleted = 0
              ORDER BY gm.created_at DESC LIMIT 1) AS last_message,
            (SELECT gm2.created_at
               FROM group_messages gm2
              WHERE gm2.group_id = c.id AND gm2.is_deleted = 0
              ORDER BY gm2.created_at DESC LIMIT 1) AS last_message_time,
            (SELECT u2.username
               FROM group_messages gm3
               JOIN users u2 ON gm3.sender_id = u2.id
              WHERE gm3.group_id = c.id AND gm3.is_deleted = 0
              ORDER BY gm3.created_at DESC LIMIT 1) AS last_sender_username
        FROM chats c
        INNER JOIN chat_participants cp ON cp.chat_id = c.id AND cp.user_id = ?
        WHERE c.is_group = 1
        ORDER BY c.updated_at DESC
    ");
    $stmt->execute([$user_id]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Para cada grupo, buscar membros resumidos
    foreach ($groups as &$group) {
        $mem_stmt = $pdo->prepare("
            SELECT u.id, u.username, u.full_name, u.profile_picture, u.is_verified
            FROM chat_participants cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.chat_id = ?
            ORDER BY cp.joined_at ASC
            LIMIT 5
        ");
        $mem_stmt->execute([$group['group_id']]);
        $group['members_preview'] = $mem_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($group);

    echo json_encode(['success' => true, 'groups' => $groups]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar grupos: ' . $e->getMessage()]);
}
