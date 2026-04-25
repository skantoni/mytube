<?php
/**
 * API: Admin — Gerir utilizadores premium
 *
 * Actions:
 *   search  → GET  ?q=texto          → lista users matching
 *   set     → POST {user_id, value}  → set/unset premium
 *   list    → GET  ?action=list      → all premium users
 */
require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn() || !isAdminUser()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: search or list ───────────────────────────────────────
if ($method === 'GET') {
    $action = trim($_GET['action'] ?? 'search');

    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT id, username, full_name, profile_picture, is_verified, is_premium
            FROM users
            WHERE is_premium = 1
            ORDER BY full_name ASC
        ");
        echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
        exit;
    }

    // search
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'users' => []]);
        exit;
    }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT id, username, full_name, profile_picture, is_verified, is_premium
        FROM users
        WHERE (username LIKE ? OR full_name LIKE ?)
          AND COALESCE(role,'user') != 'admin'
        ORDER BY full_name ASC
        LIMIT 10
    ");
    $stmt->execute([$like, $like]);
    echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
    exit;
}

// ── POST: set/unset premium ───────────────────────────────────
if ($method === 'POST') {
    csrf_verify_or_die('Token inválido.');

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $user_id = (int)($body['user_id'] ?? 0);
    $value   = isset($body['value']) ? ((int)$body['value'] ? 1 : 0) : null;

    if (!$user_id || $value === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Parâmetros inválidos.']);
        exit;
    }

    // Garantir que o utilizador existe
    $chk = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? LIMIT 1");
    $chk->execute([$user_id]);
    $target = $chk->fetch();
    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Utilizador não encontrado.']);
        exit;
    }

    $upd = $pdo->prepare("UPDATE users SET is_premium = ? WHERE id = ?");
    $upd->execute([$value, $user_id]);

    echo json_encode([
        'success'   => true,
        'user_id'   => $user_id,
        'is_premium'=> $value,
        'username'  => $target['username'],
        'full_name' => $target['full_name'],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
