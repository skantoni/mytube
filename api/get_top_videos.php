<?php
/**
 * API: Get Top 10 Videos for a user
 * Sorted by interaction score: likes and comments worth more than views
 * Score = (likes_count * 3) + (comments_count * 2) + (views_count * 0.5)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/r2_storage.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'user_id inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            v.id,
            v.title,
            v.video_path,
            v.thumbnail_path,
            v.likes_count,
            v.comments_count,
            v.views_count,
            v.created_at,
            (v.likes_count * 3 + v.comments_count * 2 + v.views_count * 0.5) AS interaction_score
        FROM videos v
        WHERE v.user_id = ? AND v.is_public = 1 AND v.moderation_status = 'approved'
        ORDER BY interaction_score DESC, v.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($videos as $i => $v) {
        $result[] = [
            'rank'            => $i + 1,
            'id'              => (int)$v['id'],
            'title'           => $v['title'],
            'video_path'      => $v['video_path'],
            'video_url'       => resolve_video_url($v['video_path']),
            'thumbnail_path'  => $v['thumbnail_path'],
            'likes_count'     => (int)$v['likes_count'],
            'comments_count'  => (int)$v['comments_count'],
            'views_count'     => (int)$v['views_count'],
            'created_at'      => $v['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'videos' => $result]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar vídeos']);
}
