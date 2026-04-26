<?php
/**
 * API: Buscar membros de um grupo
 * GET /api/get_group_members.php?group_id=N
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

if ($group_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'group_id inválido.']);
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

    // Buscar info do grupo
    $grp = $pdo->prepare("SELECT id, name, group_picture, created_by, created_at FROM chats WHERE id = ? AND is_group = 1 LIMIT 1");
    $grp->execute([$group_id]);
    $group = $grp->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Grupo não encontrado.']);
        exit;
    }

    // Buscar membros
    $mem = $pdo->prepare("
        SELECT
            u.id, u.username, u.full_name, u.profile_picture, u.is_verified,
            cp.joined_at, cp.added_by,
            ab.username AS added_by_username
        FROM chat_participants cp
        JOIN users u ON cp.user_id = u.id
        LEFT JOIN users ab ON cp.added_by = ab.id
        WHERE cp.chat_id = ?
        ORDER BY cp.joined_at ASC
    ");
    $mem->execute([$group_id]);
    $members = $mem->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'group'   => $group,
        'members' => $members,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}
