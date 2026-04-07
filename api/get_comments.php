<?php
require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$video_id = isset($_GET['video_id']) ? (int)$_GET['video_id'] : 0;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 8;

// Endpoint auxiliar: encontrar o comentário raiz (pai) de uma resposta
if (isset($_GET['find_parent'])) {
    $comment_id = (int)$_GET['find_parent'];
    try {
        // Buscar o comentário e seguir a cadeia até o comentário raiz
        $stmt = $pdo->prepare("SELECT id, parent_comment_id FROM comments WHERE id = ?");
        $stmt->execute([$comment_id]);
        $comment = $stmt->fetch();
        
        if (!$comment) {
            echo json_encode(['success' => false, 'error' => 'Comentário não encontrado']);
            exit;
        }
        
        // Se não tem pai, é um comentário raiz
        if (!$comment['parent_comment_id']) {
            echo json_encode(['success' => true, 'parent_comment_id' => null, 'is_root' => true]);
            exit;
        }
        
        // Seguir a cadeia até o comentário raiz
        $current_parent = $comment['parent_comment_id'];
        for ($i = 0; $i < 10; $i++) {
            $stmt->execute([$current_parent]);
            $parent = $stmt->fetch();
            if (!$parent || !$parent['parent_comment_id']) break;
            $current_parent = $parent['parent_comment_id'];
        }
        
        echo json_encode(['success' => true, 'parent_comment_id' => (int)$current_parent]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erro interno']);
        exit;
    }
}

if (!$video_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID do vídeo é obrigatório']);
    exit;
}

try {
    // Contar total de comentários principais
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE video_id = ? AND parent_comment_id IS NULL");
    $countStmt->execute([$video_id]);
    $total_comments = (int)$countStmt->fetchColumn();

    // Query 1: Buscar comentários principais (sem self-join pesado)
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.comment_text,
            c.likes_count,
            c.created_at,
            c.parent_comment_id,
            u.id as user_id,
            u.username,
            u.full_name,
            u.profile_picture,
            u.is_verified
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.video_id = :video_id AND c.parent_comment_id IS NULL
        ORDER BY c.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':video_id', $video_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Query 2: Contar replies apenas dos root comments retornados (targeted, não full-scan)
    $replyCounts = [];
    if (!empty($comments)) {
        $rootIds = array_map('intval', array_column($comments, 'id'));
        $ph = implode(',', array_fill(0, count($rootIds), '?'));
        $rcStmt = $pdo->prepare("
            SELECT root_id, SUM(cnt) AS total FROM (
                SELECT parent_comment_id AS root_id, COUNT(*) AS cnt
                FROM comments WHERE parent_comment_id IN ($ph)
                GROUP BY parent_comment_id
                UNION ALL
                SELECT p.parent_comment_id AS root_id, COUNT(*) AS cnt
                FROM comments c
                INNER JOIN comments p ON c.parent_comment_id = p.id
                WHERE p.parent_comment_id IN ($ph)
                GROUP BY p.parent_comment_id
            ) sub GROUP BY root_id
        ");
        $rcStmt->execute(array_merge($rootIds, $rootIds));
        foreach ($rcStmt->fetchAll(PDO::FETCH_ASSOC) as $rc) {
            $replyCounts[(int)$rc['root_id']] = (int)$rc['total'];
        }
    }
    foreach ($comments as &$c) {
        $c['replies_count'] = $replyCounts[(int)$c['id']] ?? 0;
    }
    unset($c);

    // Verificar likes do usuário (se estiver logado)
    $user_likes = [];
    if (isLoggedIn() && !empty($comments)) {
        $comment_ids = array_column($comments, 'id');
        $placeholders = str_repeat('?,', count($comment_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT comment_id 
            FROM comment_likes 
            WHERE user_id = ? AND comment_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$_SESSION['user_id']], $comment_ids));
        $user_likes = array_column($stmt->fetchAll(), 'comment_id');
    }
    
    // Formatar dados
    foreach ($comments as &$comment) {
        $comment['profile_picture_url'] = avatar_url($comment['profile_picture'] ?? null);
        $comment['user_liked'] = in_array($comment['id'], $user_likes);
        $comment['is_verified'] = (bool)$comment['is_verified'];
        $comment['time_ago'] = timeAgo($comment['created_at']);
        $comment['replies_count'] = (int)$comment['replies_count'];
        
        $is_author = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $comment['user_id']);
        $time_since_created = time() - strtotime($comment['created_at']);
        $within_edit_window = $time_since_created <= 120;
        
        $comment['can_edit'] = $is_author && $within_edit_window;
        $comment['can_delete'] = $is_author;
        $comment['edit_time_left'] = $within_edit_window ? (120 - $time_since_created) : 0;
    }
    
    echo json_encode([
        'success' => true,
        'comments' => $comments,
        'total' => $total_comments,
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => ($offset + $limit) < $total_comments
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao buscar comentários: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>