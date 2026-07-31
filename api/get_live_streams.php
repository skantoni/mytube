<?php
/**
 * API: Listar streams ao vivo
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("
        SELECT 
            ls.id,
            ls.title,
            ls.description,
            ls.category,
            ls.thumbnail_path,
            ls.viewers_count,
            ls.peak_viewers,
            ls.started_at,
            u.id AS user_id,
            u.username,
            u.full_name,
            u.profile_picture,
            u.is_verified
        FROM livestreams ls
        JOIN users u ON u.id = ls.user_id
        WHERE ls.status = 'live'
        ORDER BY ls.viewers_count DESC, ls.started_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $streams = $stmt->fetchAll();

    $result = array_map(function($s) {
        return [
            'id'             => (int)$s['id'],
            'title'          => $s['title'],
            'description'    => $s['description'],
            'category'       => $s['category'],
            'thumbnail_path' => $s['thumbnail_path'],
            'viewers_count'  => (int)$s['viewers_count'],
            'peak_viewers'   => (int)$s['peak_viewers'],
            'started_at'     => $s['started_at'],
            'streamer'       => [
                'id'              => (int)$s['user_id'],
                'username'        => $s['username'],
                'full_name'       => $s['full_name'],
                'profile_picture' => $s['profile_picture'],
                'is_verified'     => (bool)$s['is_verified']
            ]
        ];
    }, $streams);

    echo json_encode([
        'success' => true,
        'streams' => $result,
        'count'   => count($result)
    ]);
} catch (Exception $e) {
    error_log('❌ get_live_streams.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao buscar streams']);
}
