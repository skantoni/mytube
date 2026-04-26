<?php
/**
 * API: Adicionar membro a um grupo
 * Acesso: apenas admins
 *
 * POST {group_id, user_id}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!isAdminUser()) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Apenas administradores podem adicionar membros.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

csrf_verify_or_die('Token CSRF inválido.');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$group_id = (int) ($body['group_id'] ?? 0);
$user_id  = (int) ($body['user_id'] ?? 0);

if ($group_id <= 0 || $user_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos.']);
    exit;
}

$admin_id = (int) $_SESSION['user_id'];

try {
    // Verificar que o grupo existe e é um grupo
    $grp_stmt = $pdo->prepare("SELECT id, name FROM chats WHERE id = ? AND is_group = 1 LIMIT 1");
    $grp_stmt->execute([$group_id]);
    $group = $grp_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Grupo não encontrado.']);
        exit;
    }

    // Verificar que o utilizador existe
    $usr_stmt = $pdo->prepare("SELECT id, username, full_name, profile_picture, is_verified FROM users WHERE id = ? LIMIT 1");
    $usr_stmt->execute([$user_id]);
    $user = $usr_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Utilizador não encontrado.']);
        exit;
    }

    // Verificar se já é membro
    $chk = $pdo->prepare("SELECT id FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
    $chk->execute([$group_id, $user_id]);
    if ($chk->fetch()) {
        echo json_encode(['success' => true, 'already_member' => true, 'user' => $user]);
        exit;
    }

    // Adicionar membro
    $ins = $pdo->prepare("
        INSERT INTO chat_participants (chat_id, user_id, added_by, joined_at, last_seen)
        VALUES (?, ?, ?, NOW(), NOW())
    ");
    $ins->execute([$group_id, $user_id, $admin_id]);

    echo json_encode([
        'success' => true,
        'already_member' => false,
        'user' => $user,
        'group_id' => $group_id,
        'group_name' => $group['name'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao adicionar membro: ' . $e->getMessage()]);
}
