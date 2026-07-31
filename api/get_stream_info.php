<?php
/**
 * API: Informação de uma livestream específica
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

$stream_id = (int)($_GET['id'] ?? 0);

if ($stream_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            ls.id,
            ls.user_id,
            ls.title,
            ls.description,
            ls.category,
            ls.thumbnail_path,
            ls.status,
            ls.viewers_count,
            ls.peak_viewers,
            ls.total_views,
            ls.started_at,
            ls.ended_at,
            u.username,
            u.full_name,
            u.profile_picture,
            u.is_verified,
            u.followers_count
        FROM livestreams ls
        JOIN users u ON u.id = ls.user_id
        WHERE ls.id = ? LIMIT 1
    ");
    $stmt->execute([$stream_id]);
    $stream = $stmt->fetch();

    if (!$stream) {
        http_response_code(404);
        echo json_encode(['error' => 'Stream não encontrada']);
        exit;
    }

    // Verificar se o utilizador actual segue o streamer
    $is_following = false;
    if (isLoggedIn()) {
        $current_user_id = (int)$_SESSION['user_id'];
        $stmt2 = $pdo->prepare("
            SELECT id FROM follows 
            WHERE follower_id = ? AND following_id = ? LIMIT 1
        ");
        $stmt2->execute([$current_user_id, $stream['user_id']]);
        $is_following = (bool)$stmt2->fetch();
    }

    echo json_encode([
        'success' => true,
        'stream'  => [
            'id'             => (int)$stream['id'],
            'title'          => $stream['title'],
            'description'    => $stream['description'],
            'category'       => $stream['category'],
            'thumbnail_path' => $stream['thumbnail_path'],
            'status'         => $stream['status'],
            'viewers_count'  => (int)$stream['viewers_count'],
            'peak_viewers'   => (int)$stream['peak_viewers'],
            'total_views'    => (int)$stream['total_views'],
            'started_at'     => $stream['started_at'],
            'ended_at'       => $stream['ended_at'],
            'is_following'   => $is_following,
            'streamer'       => [
                'id'              => (int)$stream['user_id'],
                'username'        => $stream['username'],
                'full_name'       => $stream['full_name'],
                'profile_picture' => $stream['profile_picture'],
                'is_verified'     => (bool)$stream['is_verified'],
                'followers_count' => (int)$stream['followers_count']
            ]
        ]
    ]);
} catch (Exception $e) {
    error_log('❌ get_stream_info.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
}
