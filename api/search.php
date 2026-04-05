<?php
require_once '../includes/config.php';
require_once '../includes/hashtag_helper.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$context = isset($_GET['context']) ? trim((string)$_GET['context']) : '';
$is_hashtag_query = $query !== '' && mb_substr($query, 0, 1, 'UTF-8') === '#';
$min_hashtag_chars = 2;

if (mb_strlen($query, 'UTF-8') < 2 && !$is_hashtag_query) {
    echo json_encode([
        'success' => true,
        'users' => [],
        'videos' => [],
        'hashtags' => [],
    ]);
    exit;
}

try {
    $users = [];
    $videos = [];
    $hashtags = [];

    if ($is_hashtag_query && hashtag_tables_available($pdo)) {
        $raw_hashtag_term = trim((string)ltrim($query, '#'));
        $hashtag_term = hashtag_build_slug($raw_hashtag_term);

        if (mb_strlen($hashtag_term, 'UTF-8') >= $min_hashtag_chars) {
            $prefix_term = $hashtag_term . '%';

            $hashtag_stmt = $pdo->prepare("\n                SELECT id, name, slug, posts_count\n                FROM hashtags\n                WHERE slug LIKE ?\n                ORDER BY posts_count DESC, name ASC\n                LIMIT 10\n            ");
            $hashtag_stmt->execute([$prefix_term]);
        } else {
            $hashtags = [];
        }

        if (isset($hashtag_stmt)) {
            $hashtags = $hashtag_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $can_search_users_and_videos = mb_strlen($query, 'UTF-8') >= 2 && !$is_hashtag_query;

    if ($can_search_users_and_videos) {
        $searchTerm = '%' . $query . '%';

        if ($context === 'ranking') {
            $userStmt = $pdo->prepare("\n                SELECT \n                    u.id,\n                    u.username,\n                    u.full_name,\n                    u.profile_picture,\n                    u.is_verified,\n                    u.followers_count,\n                    u.videos_count,\n                    u.ranking_points,\n                    u.school_id,\n                    s.name AS school_name,\n                    s.short_name AS school_short\n                FROM users u\n                LEFT JOIN schools s ON u.school_id = s.id\n                WHERE u.username LIKE ? OR u.full_name LIKE ?\n                ORDER BY \n                    CASE WHEN u.username LIKE ? THEN 0 ELSE 1 END,\n                    u.ranking_points DESC\n                LIMIT 10\n            ");
        } else {
            $userStmt = $pdo->prepare("\n                SELECT \n                    u.id,\n                    u.username,\n                    u.full_name,\n                    u.profile_picture,\n                    u.is_verified,\n                    u.followers_count,\n                    u.videos_count\n                FROM users u\n                WHERE u.username LIKE ? OR u.full_name LIKE ?\n                ORDER BY \n                    CASE WHEN u.username LIKE ? THEN 0 ELSE 1 END,\n                    u.followers_count DESC\n                LIMIT 10\n            ");
        }

        $userStmt->execute([$searchTerm, $searchTerm, $query . '%']);
        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        $videoStmt = $pdo->prepare("\n            SELECT \n                v.id,\n                v.title,\n                v.description,\n                v.video_path,\n                v.views_count,\n                v.likes_count,\n                v.created_at,\n                u.id as user_id,\n                u.username,\n                u.profile_picture,\n                u.is_verified\n            FROM videos v\n            JOIN users u ON v.user_id = u.id\n            WHERE v.is_public = 1 \n                AND (v.title LIKE ? OR v.description LIKE ? OR u.username LIKE ?)\n            ORDER BY v.views_count DESC, v.created_at DESC\n            LIMIT 10\n        ");
        $videoStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $videos = $videoStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $formattedUsers = array_map(function($user) use ($context) {
        $data = [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'profile_picture' => $user['profile_picture'] ?? 'default.webp',
            'profile_picture_url' => avatar_url($user['profile_picture'] ?? null),
            'is_verified' => (bool)$user['is_verified'],
            'followers_count' => (int)$user['followers_count'],
            'videos_count' => (int)$user['videos_count'],
        ];

        if ($context === 'ranking') {
            $data['ranking_points'] = (int)($user['ranking_points'] ?? 0);
            $data['school_name'] = $user['school_name'] ?? null;
            $data['school_short'] = $user['school_short'] ?? null;
        }

        return $data;
    }, $users);

    $formattedVideos = array_map(function($video) {
        return [
            'id' => $video['id'],
            'title' => $video['title'],
            'description' => $video['description'],
            'video_path' => $video['video_path'],
            'views_count' => (int)$video['views_count'],
            'likes_count' => (int)$video['likes_count'],
            'user' => [
                'id' => $video['user_id'],
                'username' => $video['username'],
                'profile_picture' => $video['profile_picture'] ?? 'default.webp',
                'profile_picture_url' => avatar_url($video['profile_picture'] ?? null),
                'is_verified' => (bool)$video['is_verified'],
            ],
        ];
    }, $videos);

    $formattedHashtags = array_map(function($hashtag) {
        return [
            'id' => (int)$hashtag['id'],
            'name' => $hashtag['name'],
            'slug' => $hashtag['slug'],
            'posts_count' => (int)($hashtag['posts_count'] ?? 0),
        ];
    }, $hashtags);

    echo json_encode([
        'success' => true,
        'users' => $formattedUsers,
        'videos' => $formattedVideos,
        'hashtags' => $formattedHashtags,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor', 'msg' => $e->getMessage()]);
}
?>
