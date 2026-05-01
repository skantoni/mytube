<?php
/**
 * API de Sincronização de Comentários em Tempo Real
 * Retorna novos comentários adicionados após um determinado timestamp
 */

require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['video_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'video_id é obrigatório']);
    exit;
}

$video_id = (int)$input['video_id'];
$last_check = $input['last_check'] ?? null; // timestamp da última verificação

if (!$last_check) {
    // Se não forneceu timestamp, usar 1 minuto atrás
    $last_check = date('Y-m-d H:i:s', time() - 60);
} else {
    // Converter timestamp para formato SQL
    $last_check = date('Y-m-d H:i:s', (int)$last_check);
}

$current_user_id = isLoggedIn() ? $_SESSION['user_id'] : null;

// IDs de comentários visíveis para sincronizar likes (combina 2 requests em 1)
$comment_ids = isset($input['comment_ids']) && is_array($input['comment_ids'])
    ? array_map('intval', array_slice($input['comment_ids'], 0, 100))
    : [];

try {
    // Buscar novos comentários desde o último check
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
        JOIN users u ON c.user_id = u.id
        WHERE c.video_id = ?
        AND c.created_at > ?
        ORDER BY c.created_at ASC
    ");
    
    $stmt->execute([$video_id, $last_check]);
    $new_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar likes do usuário atual nos novos comentários
    $user_likes = [];
    if ($current_user_id && !empty($new_comments)) {
        $comment_ids = array_column($new_comments, 'id');
        $placeholders = implode(',', array_fill(0, count($comment_ids), '?'));
        
        $stmt = $pdo->prepare("
            SELECT comment_id 
            FROM comment_likes 
            WHERE user_id = ? AND comment_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$current_user_id], $comment_ids));
        $user_likes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Batch: buscar parent_comment_id de todos os pais (elimina queries N+1)
    $parent_map = [];
    $parent_ids_needed = array_unique(array_filter(array_column($new_comments, 'parent_comment_id')));
    if (!empty($parent_ids_needed)) {
        $ph = implode(',', array_fill(0, count($parent_ids_needed), '?'));
        $pStmt = $pdo->prepare("SELECT id, parent_comment_id FROM comments WHERE id IN ($ph)");
        $pStmt->execute(array_values($parent_ids_needed));
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $parent_map[(int)$p['id']] = $p['parent_comment_id'] ? (int)$p['parent_comment_id'] : null;
        }
    }

    // Formatar comentários
    $formatted_comments = [];
    foreach ($new_comments as $comment) {
        $is_author = ($current_user_id && $comment['user_id'] == $current_user_id);
        $time_since_created = time() - strtotime($comment['created_at']);
        $within_edit_window = $time_since_created <= 120; // 2 minutos
        
        // Encontrar o comentário raiz (via batch map, sem query individual)
        $root_comment_id = null;
        if ($comment['parent_comment_id']) {
            $pid = (int)$comment['parent_comment_id'];
            $parent_parent_id = $parent_map[$pid] ?? null;
            $root_comment_id = $parent_parent_id ? (int)$parent_parent_id : $pid;
        }
        
        $formatted_comments[] = [
            'id' => (int)$comment['id'],
            'user_id' => (int)$comment['user_id'],
            'video_id' => (int)$comment['video_id'],
            'comment_text' => $comment['comment_text'],
            'parent_comment_id' => $comment['parent_comment_id'] ? (int)$comment['parent_comment_id'] : null,
            'root_comment_id' => $root_comment_id, // ID do comentário raiz (para respostas a respostas)
            'likes_count' => (int)$comment['likes_count'],
            'created_at' => $comment['created_at'],
            'updated_at' => $comment['updated_at'],
            'time_ago' => timeAgo($comment['created_at']),
            'username' => $comment['username'],
            'full_name' => $comment['full_name'],
            'profile_picture' => $comment['profile_picture'] ?? 'default.webp',
            'is_verified' => (bool)$comment['is_verified'],
            'user_liked' => in_array($comment['id'], $user_likes),
            'replies' => [],
            'replies_count' => 0,
            'can_edit' => $is_author && $within_edit_window,
            'edit_time_left' => $within_edit_window ? (120 - $time_since_created) : 0
        ];
    }
    
    // Buscar comentários atualizados (editados)
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.comment_text,
            c.updated_at,
            c.likes_count
        FROM comments c
        WHERE c.video_id = ?
        AND c.updated_at IS NOT NULL
        AND c.updated_at > ?
        ORDER BY c.updated_at ASC
    ");
    
    $stmt->execute([$video_id, $last_check]);
    $updated_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sincronizar likes dos comentários visíveis (se enviaram comment_ids)
    $likes_data = null;
    if (!empty($comment_ids)) {
        $ph = implode(',', array_fill(0, count($comment_ids), '?'));
        $lStmt = $pdo->prepare("SELECT id, likes_count FROM comments WHERE id IN ($ph)");
        $lStmt->execute($comment_ids);
        $likesRows = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        $visibleUserLikes = [];
        if ($current_user_id) {
            $ulStmt = $pdo->prepare("SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN ($ph)");
            $ulStmt->execute(array_merge([$current_user_id], $comment_ids));
            $visibleUserLikes = $ulStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $likes_data = [];
        foreach ($likesRows as $lc) {
            $cid = (int)$lc['id'];
            $likes_data[$cid] = [
                'comment_id' => $cid,
                'likes_count' => (int)$lc['likes_count'],
                'user_liked' => in_array($cid, $visibleUserLikes)
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'new_comments' => $formatted_comments,
        'updated_comments' => $updated_comments,
        'likes_data' => $likes_data,
        'timestamp' => time(),
        'user_logged_in' => (bool)$current_user_id
    ]);
    
} catch (Exception $e) {
    error_log("sync_comments_feed.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro ao sincronizar comentários',
        'message' => $e->getMessage()
    ]);
}
