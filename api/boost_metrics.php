<?php
/**
 * API: Métricas de CTR para vídeos boosted (Admin only)
 * GET: Retorna métricas de impressões, alcance e engagement para cada vídeo boosted
 */
header('Content-Type: application/json');
require_once '../includes/config.php';

if (!isLoggedIn() || !isAdminUser()) {
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

try {
    // Período: últimos 30 dias
    $period_days = isset($_GET['days']) ? min(90, max(1, (int)$_GET['days'])) : 30;
    $since_date = date('Y-m-d', strtotime("-{$period_days} days"));

    // Buscar todos os vídeos boosted
    $stmt = $pdo->query("SELECT id, title, views_count, likes_count, comments_count FROM videos WHERE is_boosted = 1 AND is_public = 1");
    $boosted_videos = $stmt->fetchAll();

    if (empty($boosted_videos)) {
        echo json_encode(['success' => true, 'metrics' => [], 'summary' => []]);
        exit;
    }

    $video_ids = array_column($boosted_videos, 'id');
    $ph = implode(',', array_fill(0, count($video_ids), '?'));

    // Total de impressões por vídeo (últimos X dias)
    $stmt = $pdo->prepare("
        SELECT 
            video_id,
            SUM(impressions) as total_impressions,
            COUNT(DISTINCT user_id) as unique_users,
            COUNT(DISTINCT impression_date) as active_days
        FROM boost_impressions
        WHERE video_id IN ($ph) AND impression_date >= ?
        GROUP BY video_id
    ");
    $stmt->execute(array_merge($video_ids, [$since_date]));
    $impression_data = [];
    while ($row = $stmt->fetch()) {
        $impression_data[(int)$row['video_id']] = $row;
    }

    // Impressões de hoje
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT 
            video_id,
            SUM(impressions) as today_impressions,
            COUNT(DISTINCT user_id) as today_users
        FROM boost_impressions
        WHERE video_id IN ($ph) AND impression_date = ?
        GROUP BY video_id
    ");
    $stmt->execute(array_merge($video_ids, [$today]));
    $today_data = [];
    while ($row = $stmt->fetch()) {
        $today_data[(int)$row['video_id']] = $row;
    }

    // Data da primeira impressão por vídeo (quando o tracking começou)
    $stmt = $pdo->prepare("
        SELECT video_id, MIN(created_at) as tracking_started
        FROM boost_impressions
        WHERE video_id IN ($ph)
        GROUP BY video_id
    ");
    $stmt->execute($video_ids);
    $tracking_start = [];
    while ($row = $stmt->fetch()) {
        $tracking_start[(int)$row['video_id']] = $row['tracking_started'];
    }

    // Construir métricas por vídeo
    $metrics = [];
    $total_impressions_all = 0;
    $total_unique_users_all = 0;

    foreach ($boosted_videos as $video) {
        $vid = (int)$video['id'];
        $imp = $impression_data[$vid] ?? null;
        $tod = $today_data[$vid] ?? null;

        $total_impressions = $imp ? (int)$imp['total_impressions'] : 0;
        $unique_users = $imp ? (int)$imp['unique_users'] : 0;
        $active_days = $imp ? (int)$imp['active_days'] : 0;
        $today_impressions = $tod ? (int)$tod['today_impressions'] : 0;
        $today_users = $tod ? (int)$tod['today_users'] : 0;

        // CTR: contar APENAS likes e comments desde que o tracking começou
        $engagements = 0;
        $likes_since = 0;
        $comments_since = 0;

        if (isset($tracking_start[$vid])) {
            $started = $tracking_start[$vid];

            // Likes: users únicos que deram like desde o tracking
            $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM video_likes WHERE video_id = ? AND created_at >= ?");
            $stmt2->execute([$vid, $started]);
            $likes_since = (int)$stmt2->fetchColumn();

            // Comments: users únicos que comentaram desde o tracking
            $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM comments WHERE video_id = ? AND created_at >= ?");
            $stmt2->execute([$vid, $started]);
            $comments_since = (int)$stmt2->fetchColumn();

            $engagements = $likes_since + $comments_since;
        }

        $ctr = $total_impressions > 0 ? round(($engagements / $total_impressions) * 100, 2) : 0;

        $total_impressions_all += $total_impressions;
        $total_unique_users_all += $unique_users;

        $metrics[$vid] = [
            'video_id' => $vid,
            'title' => $video['title'],
            'total_impressions' => $total_impressions,
            'unique_users' => $unique_users,
            'active_days' => $active_days,
            'today_impressions' => $today_impressions,
            'today_users' => $today_users,
            'views' => (int)$video['views_count'],
            'likes_since_tracking' => $likes_since,
            'comments_since_tracking' => $comments_since,
            'engagements' => $engagements,
            'ctr' => $ctr,
        ];
    }

    // Resumo global
    $summary = [
        'total_boosted' => count($boosted_videos),
        'total_impressions' => $total_impressions_all,
        'total_unique_users' => $total_unique_users_all,
        'period_days' => $period_days,
        'avg_impressions_per_video' => count($boosted_videos) > 0 
            ? round($total_impressions_all / count($boosted_videos)) 
            : 0,
    ];

    echo json_encode([
        'success' => true,
        'metrics' => array_values($metrics),
        'summary' => $summary,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>
