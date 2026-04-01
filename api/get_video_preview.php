<?php
/**
 * API para obter preview de vídeo (leve, para compartilhamento no chat)
 * Retorna: thumbnail, título, autor, duração
 */
require_once '../includes/config.php';
require_once '../includes/r2_storage.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600'); // Cache de 1 hora

$video_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($video_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            v.id,
            v.title,
            v.thumbnail_path,
            v.video_path,
            v.views_count,
            u.username,
            u.full_name
        FROM videos v
        JOIN users u ON v.user_id = u.id
        WHERE v.id = ?
        LIMIT 1
    ");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();

    if (!$video) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Vídeo não encontrado']);
        exit;
    }

    // Construir URL da thumbnail ou vídeo
    $thumbnail_url = null;
    $video_url = null;
    
    if ($video['thumbnail_path'] && file_exists('../uploads/thumbnails/' . $video['thumbnail_path'])) {
        $thumbnail_url = 'uploads/thumbnails/' . $video['thumbnail_path'];
    }
    
    if ($video['video_path']) {
        $video_url = resolve_video_url($video['video_path']);
    }

    echo json_encode([
        'success' => true,
        'video' => [
            'id' => (int) $video['id'],
            'title' => $video['title'] ?: 'Vídeo sem título',
            'thumbnail' => $thumbnail_url,
            'video_src' => $video_url,
            'views' => (int) $video['views_count'],
            'author' => $video['full_name'] ?: $video['username']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
?>
