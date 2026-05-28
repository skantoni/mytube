<?php
/**
 * API de Rankings do MyTube — OTIMIZADO
 * 
 * Mudanças vs original:
 * - Cache server-side (file-based, TTL 5-10 min)
 * - my_rank usa ranking_points pré-calculado (1 indexed query vs full scan)
 * - top_creators period=all usa ranking_points direto (sem JOIN videos)
 * - initial_load devolve tudo num único request
 * - trending_videos usa trend_score pré-calculado (indexed)
 * - top_schools cacheado 10 min
 * - dominant_school cacheado 10 min
 */
require_once '../includes/config.php';
require_once '../includes/ranking_cache.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
$period = $_GET['period'] ?? 'all'; // all, week, month

try {
    switch ($action) {

        // ═══════════════════════════════════════════
        // INITIAL LOAD — tudo numa resposta só
        // ═══════════════════════════════════════════
        case 'initial_load':
            $userId = isLoggedIn() ? $_SESSION['user_id'] : 0;
            $userSchoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

            // my_rank — sem cache (dados pessoais, mas agora é barato)
            $myRank = get_my_rank($pdo, $userId);

            // best_mytuber — cache 5 min
            $bestMytuber = get_best_mytuber_current($pdo);

            // dominant + top_videos — cache 10 min
            $dominant = get_dominant_school($pdo);

            // trending — cache 5 min
            $trending = get_trending_videos($pdo, 6);

            // top_creators — cache 5 min
            $creators = get_top_creators($pdo, 'all', 30);

            // top_schools — cache 10 min
            $schools = get_top_schools($pdo, 20);

            // my_school — cache 5 min per school
            $mySchool = null;
            if ($userSchoolId > 0) {
                $mySchool = get_school_creators($pdo, $userSchoolId, 20);
            }

            echo json_encode([
                'success' => true,
                'my_rank' => $myRank,
                'best_mytuber' => $bestMytuber,
                'dominant' => $dominant,
                'trending' => $trending,
                'creators' => $creators,
                'schools' => $schools,
                'my_school' => $mySchool,
            ]);
            break;

        // ═══════════════════════════════════════════
        // Top Criadores Global
        // ═══════════════════════════════════════════
        case 'top_creators':
            $result = get_top_creators($pdo, $period, $limit);
            echo json_encode(['success' => true, 'creators' => $result]);
            break;

        // ═══════════════════════════════════════════
        // Top Criadores de uma Escola
        // ═══════════════════════════════════════════
        case 'school_creators':
            if (!$school_id) {
                echo json_encode(['success' => false, 'error' => 'school_id é obrigatório']);
                exit;
            }
            $result = get_school_creators($pdo, $school_id, $limit);
            echo json_encode([
                'success' => true,
                'school' => $result['school'] ?? null,
                'creators' => $result['creators'] ?? []
            ]);
            break;

        // ═══════════════════════════════════════════
        // Top Escolas
        // ═══════════════════════════════════════════
        case 'top_schools':
            $result = get_top_schools($pdo, $limit);
            echo json_encode(['success' => true, 'schools' => $result]);
            break;

        // ═══════════════════════════════════════════
        // Escola Dominante da Semana
        // ═══════════════════════════════════════════
        case 'dominant_school':
            $result = get_dominant_school($pdo);
            echo json_encode([
                'success' => true,
                'dominant' => $result['dominant'] ?? null,
                'top_videos' => $result['top_videos'] ?? []
            ]);
            break;

        // ═══════════════════════════════════════════
        // Lista escolas (dropdown)
        // ═══════════════════════════════════════════
        case 'list_schools':
            $cache_key = 'list_schools';
            $cached = ranking_cache_get($cache_key, 600);
            if ($cached !== null) {
                echo json_encode(['success' => true, 'schools' => $cached]);
                break;
            }
            $stmt = $pdo->query("SELECT id, name, short_name, city FROM schools WHERE is_active = 1 ORDER BY name");
            $schools = $stmt->fetchAll();
            ranking_cache_set($cache_key, $schools);
            echo json_encode(['success' => true, 'schools' => $schools]);
            break;

        // ═══════════════════════════════════════════
        // Ranking do usuário logado
        // ═══════════════════════════════════════════
        case 'my_rank':
            if (!isLoggedIn()) {
                echo json_encode(['success' => false, 'error' => 'Não autenticado']);
                exit;
            }
            $result = get_my_rank($pdo, $_SESSION['user_id']);
            echo json_encode($result);
            break;

        // ═══════════════════════════════════════════
        // Vídeos em alta
        // ═══════════════════════════════════════════
        case 'trending_videos':
            $result = get_trending_videos($pdo, $limit);
            echo json_encode(['success' => true, 'videos' => $result]);
            break;

        // ═══════════════════════════════════════════
        // Atualizar escola do usuário
        // ═══════════════════════════════════════════
        case 'update_school':
            if (!isLoggedIn()) {
                echo json_encode(['success' => false, 'error' => 'Não autenticado']);
                exit;
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Método inválido']);
                exit;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $newSchoolId = isset($input['school_id']) ? (int)$input['school_id'] : 0;
            if ($newSchoolId > 0) {
                $stmtCheck = $pdo->prepare("SELECT id, name FROM schools WHERE id = ? AND is_active = 1");
                $stmtCheck->execute([$newSchoolId]);
                $schoolData = $stmtCheck->fetch();
                if (!$schoolData) {
                    echo json_encode(['success' => false, 'error' => 'Escola não encontrada']);
                    exit;
                }
                $schoolName = $schoolData['name'];
            } else {
                $schoolName = null;
            }
            $stmt = $pdo->prepare("UPDATE users SET school_id = ?, instituicao = ? WHERE id = ?");
            $stmt->execute([
                $newSchoolId > 0 ? $newSchoolId : null,
                $schoolName,
                $_SESSION['user_id']
            ]);
            // Invalidar caches de escolas
            ranking_cache_invalidate('top_schools');
            ranking_cache_invalidate('dominant_school');
            echo json_encode(['success' => true, 'message' => 'Escola atualizada!']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}

// ═══════════════════════════════════════════════════
// FUNÇÕES OTIMIZADAS
// ═══════════════════════════════════════════════════

/**
 * MY RANK — usa ranking_points pré-calculado
 * Antes: 3 full aggregations. Agora: 3 queries indexadas simples.
 */
function get_my_rank($pdo, int $userId): array {
    if (!$userId) return ['success' => false, 'error' => 'Não autenticado'];

    // Dados do utilizador com pontos calculados dinamicamente (últimos 3 meses)
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.profile_picture, u.school_id,
               s.name AS school_name, s.short_name AS school_short,
               (SELECT COUNT(*) FROM videos WHERE user_id = u.id AND is_public = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)) AS total_videos,
               (SELECT COALESCE(SUM(likes_count), 0) FROM videos WHERE user_id = u.id AND is_public = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)) AS total_likes,
               (SELECT COALESCE(SUM(views_count), 0) FROM videos WHERE user_id = u.id AND is_public = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)) AS total_views,
               (SELECT COALESCE(SUM(comments_count), 0) FROM videos WHERE user_id = u.id AND is_public = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)) AS total_comments,
               COALESCE((
                   SELECT COUNT(*) * 10 + COALESCE(SUM(v.likes_count), 0) * 2 + COALESCE(SUM(v.comments_count), 0) * 3 + COALESCE(SUM(v.views_count), 0) * 1
                   FROM videos v WHERE v.user_id = u.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
               ), 0) AS calculated_points
        FROM users u
        LEFT JOIN schools s ON u.school_id = s.id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $myData = $stmt->fetch();
    if (!$myData) return ['success' => false, 'error' => 'Utilizador não encontrado'];

    $myPoints = (int)$myData['calculated_points'];
    $myData['points'] = $myPoints;
    $myData['profile_picture_url'] = avatar_url($myData['profile_picture'] ?? null);

    // Posição global — baseada em pontos dinâmicos dos últimos 3 meses
    $stmtRank = $pdo->prepare("
        SELECT COUNT(*) + 1 AS global_rank FROM users u2
        WHERE (u2.role != 'admin' OR u2.role IS NULL) AND COALESCE((
            SELECT COUNT(*) * 10 + COALESCE(SUM(v.likes_count), 0) * 2 + COALESCE(SUM(v.comments_count), 0) * 3 + COALESCE(SUM(v.views_count), 0) * 1
            FROM videos v WHERE v.user_id = u2.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        ), 0) > ?
    ");
    $stmtRank->execute([$myPoints]);
    $globalRank = (int)$stmtRank->fetchColumn();

    // Posição na escola — dinâmica
    $schoolRank = null;
    if ($myData['school_id']) {
        $stmtSR = $pdo->prepare("
            SELECT COUNT(*) + 1 AS school_rank FROM users u2
            WHERE u2.school_id = ? AND (u2.role != 'admin' OR u2.role IS NULL) AND COALESCE((
                SELECT COUNT(*) * 10 + COALESCE(SUM(v.likes_count), 0) * 2 + COALESCE(SUM(v.comments_count), 0) * 3 + COALESCE(SUM(v.views_count), 0) * 1
                FROM videos v WHERE v.user_id = u2.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
            ), 0) > ?
        ");
        $stmtSR->execute([$myData['school_id'], $myPoints]);
        $schoolRank = (int)$stmtSR->fetchColumn();
    }

    return [
        'success' => true,
        'user' => $myData,
        'global_rank' => $globalRank,
        'school_rank' => $schoolRank,
        'points' => $myPoints
    ];
}

/**
 * TOP CREATORS — cache 5 min.
 * period=all usa ranking_points (sem JOIN videos).
 * period=week/month faz aggregation mas cacheado.
 */
function get_top_creators($pdo, string $period, int $limit): array {
    $cache_key = "top_creators_{$period}_{$limit}";
    $cached = ranking_cache_get($cache_key, 300);
    if ($cached !== null) return $cached;

    if ($period === 'all') {
        $where_period = "AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
    } elseif ($period === 'week') {
        $where_period = "AND v.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } else {
        $where_period = "AND v.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }

    $sql = "
            SELECT 
                u.id, u.username, u.full_name, u.profile_picture, u.is_verified,
                u.ranking_points,
                s.name AS school_name, s.short_name AS school_short, s.id AS school_id,
                COUNT(DISTINCT v.id) AS total_videos,
                COALESCE(SUM(v.likes_count), 0) AS total_likes,
                COALESCE(SUM(v.views_count), 0) AS total_views,
                COALESCE(SUM(v.comments_count), 0) AS total_comments,
                (
                    COUNT(DISTINCT v.id) * 10 +
                    COALESCE(SUM(v.likes_count), 0) * 2 +
                    COALESCE(SUM(v.comments_count), 0) * 3 +
                    COALESCE(SUM(v.views_count), 0) * 1
                ) AS points
            FROM users u
            LEFT JOIN schools s ON u.school_id = s.id
            LEFT JOIN videos v ON v.user_id = u.id AND v.is_public = 1 $where_period
            WHERE (u.role != 'admin' OR u.role IS NULL)
            GROUP BY u.id
            HAVING total_videos > 0
            ORDER BY points DESC
            LIMIT ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$limit]);

    $creators = $stmt->fetchAll();
    foreach ($creators as $i => &$c) {
        $c['position'] = $i + 1;
        $c['profile_picture_url'] = avatar_url($c['profile_picture'] ?? null);
        $c['points'] = (int)$c['points'];
    }

    ranking_cache_set($cache_key, $creators);
    return $creators;
}

/**
 * SCHOOL CREATORS — cache 5 min por escola
 */
function get_school_creators($pdo, int $school_id, int $limit): array {
    $cache_key = "school_creators_{$school_id}_{$limit}";
    $cached = ranking_cache_get($cache_key, 300);
    if ($cached !== null) return $cached;

    // Calcula pontos da semana atual (Segunda-feira até agora)
    $sql = "
        SELECT 
            u.id, u.username, u.full_name, u.profile_picture, u.is_verified,
            COUNT(DISTINCT v.id) AS total_videos,
            COALESCE(SUM(v.likes_count), 0) AS total_likes,
            COALESCE(SUM(v.views_count), 0) AS total_views,
            (
                COUNT(DISTINCT v.id) * 10 +
                COALESCE(SUM(v.likes_count), 0) * 2 +
                COALESCE(SUM(v.comments_count), 0) * 3 +
                COALESCE(SUM(v.views_count), 0) * 1
            ) AS points
        FROM users u
        LEFT JOIN videos v ON v.user_id = u.id AND v.is_public = 1 
            AND v.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
        WHERE u.school_id = ? AND (u.role != 'admin' OR u.role IS NULL)
        GROUP BY u.id
        HAVING total_videos > 0
        ORDER BY points DESC
        LIMIT ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$school_id, $limit]);
    $creators = $stmt->fetchAll();

    $stmtSchool = $pdo->prepare("SELECT name, short_name FROM schools WHERE id = ?");
    $stmtSchool->execute([$school_id]);
    $school = $stmtSchool->fetch();

    foreach ($creators as $i => &$c) {
        $c['position'] = $i + 1;
        $c['profile_picture_url'] = avatar_url($c['profile_picture'] ?? null);
        $c['points'] = (int)$c['points'];
    }

    $result = ['school' => $school, 'creators' => $creators];
    ranking_cache_set($cache_key, $result);
    return $result;
}

/**
 * TOP SCHOOLS — cache 10 min
 */
function get_top_schools($pdo, int $limit): array {
    $cache_key = "top_schools_{$limit}";
    $cached = ranking_cache_get($cache_key, 600);
    if ($cached !== null) return $cached;

    $sql = "
        SELECT 
            s.id, s.name, s.short_name, s.logo_path, s.city,
            (
                SELECT COUNT(DISTINCT au.id)
                FROM users au
                WHERE au.school_id = s.id AND (
                    EXISTS (SELECT 1 FROM videos v2 WHERE v2.user_id = au.id AND v2.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH))
                    OR EXISTS (SELECT 1 FROM comments c2 WHERE c2.user_id = au.id AND c2.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH))
                    OR EXISTS (SELECT 1 FROM video_likes vl WHERE vl.user_id = au.id AND vl.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH))
                )
            ) AS total_students,
            COUNT(DISTINCT v.id) AS total_videos,
            COALESCE(SUM(v.likes_count), 0) AS total_likes,
            COALESCE(SUM(v.views_count), 0) AS total_views,
            (
                COUNT(DISTINCT v.id) * 10 +
                COALESCE(SUM(v.likes_count), 0) * 2 +
                COALESCE(SUM(v.comments_count), 0) * 3 +
                COALESCE(SUM(v.views_count), 0) * 1
            ) AS points
        FROM schools s
        LEFT JOIN users u ON u.school_id = s.id
        LEFT JOIN videos v ON v.user_id = u.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        WHERE s.is_active = 1
        GROUP BY s.id
        HAVING total_videos > 0
        ORDER BY points DESC
        LIMIT ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit]);
    $schools = $stmt->fetchAll();

    foreach ($schools as $i => &$s) {
        $s['position'] = $i + 1;
        $s['points'] = (int)$s['points'];
    }

    ranking_cache_set($cache_key, $schools);
    return $schools;
}

/**
 * DOMINANT SCHOOL — cache 10 min
 */
function get_dominant_school($pdo): array {
    $cache_key = 'dominant_school';
    $cached = ranking_cache_get($cache_key, 600);
    if ($cached !== null) return $cached;

    $sql = "
        SELECT *, 
               (total_videos * 10 + total_likes * 2 + total_comments * 3 + total_views * 1) AS points
        FROM (
            SELECT 
                s.id, s.name, s.short_name, s.logo_path, s.city,
                (
                    SELECT COUNT(DISTINCT au.id)
                    FROM users au
                    WHERE au.school_id = s.id AND (
                        EXISTS (SELECT 1 FROM videos v2 WHERE v2.user_id = au.id AND v2.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY))
                        OR EXISTS (SELECT 1 FROM comments c2 WHERE c2.user_id = au.id AND c2.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY))
                        OR EXISTS (SELECT 1 FROM video_likes vl WHERE vl.user_id = au.id AND vl.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY))
                    )
                ) AS total_students,
                (
                    SELECT COUNT(DISTINCT v.id) FROM videos v 
                    JOIN users u ON v.user_id = u.id 
                    WHERE u.school_id = s.id AND v.is_public = 1 
                    AND v.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
                ) AS total_videos,
                (
                    SELECT COUNT(DISTINCT vl.id) FROM video_likes vl
                    JOIN videos v ON vl.video_id = v.id
                    JOIN users u ON v.user_id = u.id
                    WHERE u.school_id = s.id 
                    AND vl.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
                ) AS total_likes,
                (
                    SELECT COUNT(DISTINCT vv.id) FROM video_views vv
                    JOIN videos v ON vv.video_id = v.id
                    JOIN users u ON v.user_id = u.id
                    WHERE u.school_id = s.id 
                    AND vv.viewed_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
                ) AS total_views,
                (
                    SELECT COUNT(DISTINCT c.id) FROM comments c
                    JOIN videos v ON c.video_id = v.id
                    JOIN users u ON v.user_id = u.id
                    WHERE u.school_id = s.id 
                    AND c.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
                ) AS total_comments
            FROM schools s
            WHERE s.is_active = 1
        ) AS school_stats
        WHERE total_videos > 0 OR total_likes > 0 OR total_comments > 0 OR total_views > 0
        ORDER BY points DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $dominant = $stmt->fetch();

    $top_videos = [];
    if ($dominant) {
        $sqlV = "
            SELECT v.id, v.title, v.thumbnail_path, v.video_path, v.views_count, v.likes_count,
                   v.comments_count, v.created_at,
                   u.username, u.full_name, u.profile_picture,
                   ROUND(
                       (COALESCE(rv.recent_views, 0) + COALESCE(rl.recent_likes, 0) * 3 + COALESCE(rc.recent_comments, 0) * 5)
                       * (1.0 / (1.0 + TIMESTAMPDIFF(HOUR, v.created_at, NOW()) / 24.0))
                       * 100
                   ) AS trend_score
            FROM videos v
            JOIN users u ON v.user_id = u.id
            LEFT JOIN (
                SELECT video_id, COUNT(*) AS recent_views
                FROM video_views WHERE viewed_at >= NOW() - INTERVAL 48 HOUR
                GROUP BY video_id
            ) rv ON rv.video_id = v.id
            LEFT JOIN (
                SELECT video_id, COUNT(*) AS recent_likes
                FROM video_likes WHERE created_at >= NOW() - INTERVAL 48 HOUR
                GROUP BY video_id
            ) rl ON rl.video_id = v.id
            LEFT JOIN (
                SELECT video_id, COUNT(*) AS recent_comments
                FROM comments WHERE created_at >= NOW() - INTERVAL 48 HOUR
                GROUP BY video_id
            ) rc ON rc.video_id = v.id
            WHERE u.school_id = ? AND v.is_public = 1
                AND v.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
            ORDER BY trend_score DESC
            LIMIT 6
        ";
        $stmtV = $pdo->prepare($sqlV);
        $stmtV->execute([$dominant['id']]);
        $top_videos = $stmtV->fetchAll();
        foreach ($top_videos as &$vid) {
            $vid['profile_picture_url'] = avatar_url($vid['profile_picture'] ?? null);
        }
    }

    $result = ['dominant' => $dominant, 'top_videos' => $top_videos];
    ranking_cache_set($cache_key, $result);
    return $result;
}

/**
 * TRENDING VIDEOS — cache 5 min
 * Algoritmo temporal: interações recentes (48h) + decaimento por idade
 * Score = (views_48h + likes_48h*3 + comments_48h*5) * freshness_multiplier
 * freshness_multiplier = 1 / (1 + age_in_hours/24) → vídeos novos pesam mais
 */
function get_trending_videos($pdo, int $limit): array {
    $limit = min($limit, 10); // Máximo 10 vídeos em alta
    $cache_key = "trending_videos_{$limit}";
    $cached = ranking_cache_get($cache_key, 300);
    if ($cached !== null) return $cached;

    $sql = "
        SELECT 
            v.id, v.title, v.video_path, v.thumbnail_path,
            v.views_count, v.likes_count, v.comments_count, v.created_at,
            u.id AS user_id, u.username, u.full_name, u.profile_picture, u.is_verified,
            s.name AS school_name, s.short_name AS school_short,
            
            -- Interações nas últimas 48h
            COALESCE(rv.recent_views, 0) AS recent_views,
            COALESCE(rl.recent_likes, 0) AS recent_likes,
            COALESCE(rc.recent_comments, 0) AS recent_comments,
            
            -- Score temporal: interações recentes * frescura
            ROUND(
                (COALESCE(rv.recent_views, 0) + COALESCE(rl.recent_likes, 0) * 3 + COALESCE(rc.recent_comments, 0) * 5)
                * (1.0 / (1.0 + TIMESTAMPDIFF(HOUR, v.created_at, NOW()) / 24.0))
                * 100
            ) AS score
            
        FROM videos v
        JOIN users u ON v.user_id = u.id
        LEFT JOIN schools s ON u.school_id = s.id
        LEFT JOIN (
            SELECT video_id, COUNT(*) AS recent_views
            FROM video_views
            WHERE viewed_at >= NOW() - INTERVAL 48 HOUR
            GROUP BY video_id
        ) rv ON rv.video_id = v.id
        LEFT JOIN (
            SELECT video_id, COUNT(*) AS recent_likes
            FROM video_likes
            WHERE created_at >= NOW() - INTERVAL 48 HOUR
            GROUP BY video_id
        ) rl ON rl.video_id = v.id
        LEFT JOIN (
            SELECT video_id, COUNT(*) AS recent_comments
            FROM comments
            WHERE created_at >= NOW() - INTERVAL 48 HOUR
            GROUP BY video_id
        ) rc ON rc.video_id = v.id
        WHERE v.is_public = 1 AND (u.role != 'admin' OR u.role IS NULL)
        ORDER BY score DESC, v.created_at DESC
        LIMIT ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit]);
    $videos = $stmt->fetchAll();

    foreach ($videos as $i => &$vid) {
        $vid['position'] = $i + 1;
        $vid['profile_picture_url'] = avatar_url($vid['profile_picture'] ?? null);
        $vid['score'] = (int)$vid['score'];
    }

    ranking_cache_set($cache_key, $videos);
    return $videos;
}

/**
 * BEST MYTUBER — cache 5 min
 */
function get_best_mytuber_current($pdo): array {
    $cache_key = 'best_mytuber_current';
    $cached = ranking_cache_get($cache_key, 300);
    if ($cached !== null) return $cached;

    $now = new DateTime('now', new DateTimeZone('Africa/Luanda'));
    $now_str = $now->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT 
            bm.*, u.username, u.full_name, u.profile_picture, u.is_verified,
            u.school_id AS user_school_id,
            s.name AS school_name, s.short_name AS school_short
        FROM best_mytuber_weekly bm
        INNER JOIN users u ON bm.user_id = u.id
        LEFT JOIN schools s ON bm.school_id = s.id
        WHERE bm.badge_visible_from <= ? AND bm.badge_visible_until >= ?
        ORDER BY bm.scope = 'global' DESC, bm.total_score DESC
    ");
    $stmt->execute([$now_str, $now_str]);
    $winners = $stmt->fetchAll();

    foreach ($winners as &$w) {
        $w['profile_picture_url'] = avatar_url($w['profile_picture'] ?? null);
        $w['total_score'] = (float)$w['total_score'];
        $w['is_badge_active'] = true;
    }

    $result = [
        'success' => true,
        'badge_active' => !empty($winners),
        'now' => $now_str,
        'winners' => $winners
    ];

    ranking_cache_set($cache_key, $result);
    return $result;
}
?>
