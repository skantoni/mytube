<?php
/**
 * API: Buscar Respostas de um Comentário (paginado)
 * 
 * GET /api/get_replies.php?comment_id=1&offset=0&limit=6
 * 
 * Retorna respostas directas + respostas-a-respostas, todas mapeadas ao comentário raiz.
 */

require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$comment_id = isset($_GET['comment_id']) ? (int)$_GET['comment_id'] : 0;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 20) : 6;

if (!$comment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'comment_id é obrigatório']);
    exit;
}

try {
    // Buscar respostas directas + respostas-a-respostas (2 níveis), paginadas por data
    // Nível 1: parent_comment_id = comment_id (respostas directas)
    // Nível 2: parent_comment_id IN (respostas directas) (respostas a respostas)
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.user_id,
            c.video_id,
            c.comment_text,
            c.parent_comment_id,
            c.likes_count,
            c.created_at,
            c.updated_at,
            u.username,
            u.full_name,
            u.profile_picture,
            u.is_verified
        FROM comments c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.parent_comment_id = :comment_id
           OR c.parent_comment_id IN (SELECT r.id FROM comments r WHERE r.parent_comment_id = :comment_id2)
        ORDER BY c.created_at ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':comment_id', $comment_id, PDO::PARAM_INT);
    $stmt->bindValue(':comment_id2', $comment_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buscar likes do utilizador (se autenticado)
    $user_likes = [];
    if (isLoggedIn() && !empty($replies)) {
        $reply_ids = array_column($replies, 'id');
        $placeholders = implode(',', array_fill(0, count($reply_ids), '?'));
        $likeStmt = $pdo->prepare("
            SELECT comment_id FROM comment_likes
            WHERE user_id = ? AND comment_id IN ($placeholders)
        ");
        $likeStmt->execute(array_merge([$_SESSION['user_id']], $reply_ids));
        $user_likes = array_column($likeStmt->fetchAll(), 'comment_id');
    }

    // Formatar
    $formatted = [];
    foreach ($replies as $reply) {
        $is_author = isLoggedIn() && $_SESSION['user_id'] == $reply['user_id'];
        $time_since = time() - strtotime($reply['created_at']);
        $within_edit = $time_since <= 120;

        $formatted[] = [
            'id'                => (int)$reply['id'],
            'user_id'           => (int)$reply['user_id'],
            'video_id'          => (int)$reply['video_id'],
            'comment_text'      => htmlspecialchars($reply['comment_text'] ?? '', ENT_QUOTES, 'UTF-8'),
            'parent_comment_id' => (int)$reply['parent_comment_id'],
            'likes_count'       => (int)$reply['likes_count'],
            'created_at'        => $reply['created_at'],
            'updated_at'        => $reply['updated_at'],
            'time_ago'          => timeAgo($reply['created_at']),
            'username'          => htmlspecialchars($reply['username'] ?? '', ENT_QUOTES, 'UTF-8'),
            'full_name'         => htmlspecialchars($reply['full_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            'profile_picture'       => $reply['profile_picture'] ?? 'default.webp',
            'profile_picture_url'   => avatar_url($reply['profile_picture'] ?? null),
            'is_verified'       => (bool)$reply['is_verified'],
            'user_liked'        => in_array($reply['id'], $user_likes),
            'can_edit'          => $is_author && $within_edit,
            'can_delete'        => $is_author,
            'edit_time_left'    => $within_edit ? (120 - $time_since) : 0,
        ];
    }

    echo json_encode([
        'success' => true,
        'replies' => $formatted,
        'offset'  => $offset,
        'limit'   => $limit,
        'has_more' => count($replies) === $limit,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("get_replies.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar respostas']);
}
