<?php
/**
 * API: Criar grupo de chat
 * Acesso: apenas admins
 *
 * POST {name, member_ids: [int, ...]}
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
    echo json_encode(['error' => 'Acesso negado. Apenas administradores podem criar grupos.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

csrf_verify_or_die('Token CSRF inválido.');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim($body['name'] ?? '');
$member_ids = $body['member_ids'] ?? [];

if ($name === '' || strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Nome do grupo inválido (1–100 caracteres).']);
    exit;
}

if (!is_array($member_ids)) {
    $member_ids = [];
}

// Sanitizar IDs dos membros
$member_ids = array_filter(array_map('intval', $member_ids), fn($id) => $id > 0);
$member_ids = array_values(array_unique($member_ids));

$creator_id = (int) $_SESSION['user_id'];

// Garantir que o criador não está duplicado na lista
$member_ids = array_filter($member_ids, fn($id) => $id !== $creator_id);

try {
    $pdo->beginTransaction();

    // Inserir grupo na tabela chats
    $stmt = $pdo->prepare("
        INSERT INTO chats (name, is_group, created_by, created_at, updated_at)
        VALUES (?, 1, ?, NOW(), NOW())
    ");
    $stmt->execute([$name, $creator_id]);
    $group_id = (int) $pdo->lastInsertId();

    // Adicionar o criador como participante
    $stmt_part = $pdo->prepare("
        INSERT INTO chat_participants (chat_id, user_id, added_by, joined_at, last_seen)
        VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE joined_at = joined_at
    ");
    $stmt_part->execute([$group_id, $creator_id, $creator_id]);

    // Adicionar os membros
    $added_members = [];
    foreach ($member_ids as $uid) {
        // Verificar se o utilizador existe
        $check = $pdo->prepare("SELECT id, username, full_name, profile_picture, is_verified FROM users WHERE id = ? LIMIT 1");
        $check->execute([$uid]);
        $user = $check->fetch(PDO::FETCH_ASSOC);
        if (!$user) continue;

        $stmt_part->execute([$group_id, $uid, $creator_id]);
        $added_members[] = $user;
    }

    $pdo->commit();

    // Buscar dados do criador
    $creator_stmt = $pdo->prepare("SELECT id, username, full_name, profile_picture, is_verified FROM users WHERE id = ? LIMIT 1");
    $creator_stmt->execute([$creator_id]);
    $creator = $creator_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'group' => [
            'id'          => $group_id,
            'name'        => $name,
            'created_by'  => $creator_id,
            'created_at'  => date('Y-m-d H:i:s'),
            'members'     => array_merge([$creator], $added_members),
            'member_count'=> count($added_members) + 1,
        ],
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao criar grupo: ' . $e->getMessage()]);
}
