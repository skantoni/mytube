<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/r2_storage.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 18;
$limit = min(36, max(6, $limit));

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

$offset = ($page - 1) * $limit;
$fetch_limit = $limit + 1;

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
            v.created_at
        FROM videos v
        WHERE v.user_id = ? AND v.is_public = 1
        ORDER BY v.created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $fetch_limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $has_more = count($rows) > $limit;

    if ($has_more) {
        array_pop($rows);
    }

    $videos = [];
    foreach ($rows as $video) {
        $videos[] = [
            'id' => (int)$video['id'],
            'title' => (string)($video['title'] ?? ''),
            'video_path' => (string)($video['video_path'] ?? ''),
            'video_url' => resolve_video_url($video['video_path'] ?? ''),
            'thumbnail_path' => (string)($video['thumbnail_path'] ?? ''),
            'likes_count' => (int)$video['likes_count'],
            'comments_count' => (int)$video['comments_count'],
            'views_count' => (int)$video['views_count'],
            'created_at' => (string)$video['created_at'],
            'date_label' => date('d/m/Y', strtotime($video['created_at'])),
            'time_ago' => timeAgo($video['created_at'])
        ];
    }

    echo json_encode([
        'success' => true,
        'page' => $page,
        'limit' => $limit,
        'has_more' => $has_more,
        'videos' => $videos
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
