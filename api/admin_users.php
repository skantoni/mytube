<?php
/**
 * api/admin_users.php
 * API endpoint para a aba Utilizadores do painel admin.
 *
 * Ações (GET):
 *   action=stats      — cards de topo (totais, online, retenção)
 *   action=list       — lista paginada com filtros
 *   action=detail     — detalhes + histórico de logins de um utilizador
 *   action=retention  — métricas globais de retenção (cohorts, distribuição)
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isAdminUser()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? 'list';

// ─────────────────────────────────────────────────────────────────────────────
// Helper: verifica se a tabela user_login_history existe
// ─────────────────────────────────────────────────────────────────────────────
function historyTableExists($pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $r = $pdo->query("SHOW TABLES LIKE 'user_login_history'")->fetchColumn();
    return $exists = (bool)$r;
}

// ─────────────────────────────────────────────────────────────────────────────
// action=stats
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'stats') {
    $data = [];

    $data['total_users']   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(role,'user') != 'admin'")->fetchColumn();
    $data['online_now']    = (int)$pdo->query("SELECT COUNT(*) FROM user_online_status WHERE is_online = 1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)")->fetchColumn();
    $data['with_videos']   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE videos_count > 0 AND COALESCE(role,'user') != 'admin'")->fetchColumn();
    $data['new_today']     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND COALESCE(role,'user') != 'admin'")->fetchColumn();
    $data['new_this_week'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND COALESCE(role,'user') != 'admin'")->fetchColumn();

    if (historyTableExists($pdo)) {
        // Retenção: utilizadores que têm pelo menos 2 logins registados
        // (excluindo seeds de account_created) 
        $retained = (int)$pdo->query("
            SELECT COUNT(DISTINCT user_id) FROM user_login_history
            WHERE user_agent != 'seed:account_created'
              AND user_id IN (
                SELECT user_id FROM user_login_history
                WHERE user_agent != 'seed:account_created'
                GROUP BY user_id HAVING COUNT(*) >= 2
              )
        ")->fetchColumn();
        $total_with_real_login = (int)$pdo->query("
            SELECT COUNT(DISTINCT user_id) FROM user_login_history
            WHERE user_agent != 'seed:account_created'
        ")->fetchColumn();
        $data['retention_rate']      = $total_with_real_login > 0
            ? round($retained / $total_with_real_login * 100, 1)
            : null;
        $data['retained_users']      = $retained;

        // Tempo médio até 2.º login (em horas)
        $avg = $pdo->query("
            SELECT AVG(TIMESTAMPDIFF(HOUR, first_login, second_login)) AS gap_hours
            FROM (
                SELECT 
                    user_id,
                    MIN(logged_in_at) as first_login,
                    (
                        SELECT MIN(logged_in_at) 
                        FROM user_login_history t2 
                        WHERE t2.user_id = t1.user_id 
                          AND t2.logged_in_at > MIN(t1.logged_in_at)
                          AND t2.user_agent != 'seed:account_created'
                    ) as second_login
                FROM user_login_history t1
                WHERE user_agent != 'seed:account_created'
                GROUP BY user_id
            ) gaps
            WHERE second_login IS NOT NULL
        ")->fetchColumn();

        $data['avg_hours_to_second_login'] = $avg !== null ? round((float)$avg, 1) : null;
    } else {
        $data['retention_rate']             = null;
        $data['retained_users']             = null;
        $data['avg_hours_to_second_login']  = null;
    }

    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// action=list
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $search     = trim($_GET['search'] ?? '');
    $status     = $_GET['status']    ?? 'all';   // all | online | offline
    $role       = $_GET['role']      ?? 'all';   // all | user | admin | moderator | vip
    $has_videos = $_GET['videos']    ?? 'all';   // all | yes | no
    $verified   = $_GET['verified']  ?? 'all';   // all | yes | no
    $sort       = $_GET['sort']      ?? 'newest';// newest | oldest | most_videos | most_followers | last_seen
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $per_page   = 25;
    $offset     = ($page - 1) * $per_page;

    $where  = ["COALESCE(u.role,'user') != 'admin'"];
    $params = [];

    if ($search !== '') {
        $where[]  = "(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
        $like     = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($role !== 'all') {
        $where[]  = "COALESCE(u.role,'user') = ?";
        $params[] = $role;
    }
    if ($has_videos === 'yes')  { $where[] = "u.videos_count > 0"; }
    if ($has_videos === 'no')   { $where[] = "u.videos_count = 0"; }
    if ($verified === 'yes')    { $where[] = "u.is_verified = 1"; }
    if ($verified === 'no')     { $where[] = "u.is_verified = 0"; }
    if ($status === 'online')   { $where[] = "uos.is_online = 1 AND uos.last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)"; }
    if ($status === 'offline')  { $where[] = "(uos.is_online IS NULL OR uos.is_online = 0 OR uos.last_seen < DATE_SUB(NOW(), INTERVAL 2 MINUTE))"; }

    $order_map = [
        'newest'          => 'u.created_at DESC',
        'oldest'          => 'u.created_at ASC',
        'most_videos'     => 'u.videos_count DESC, u.created_at DESC',
        'most_followers'  => 'u.followers_count DESC, u.created_at DESC',
        'last_seen'       => 'uos.last_seen DESC',
    ];
    $order = $order_map[$sort] ?? 'u.created_at DESC';

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    // Count total
    $count_sql = "SELECT COUNT(*) FROM users u LEFT JOIN user_online_status uos ON uos.user_id = u.id $where_sql";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_count = (int)$count_stmt->fetchColumn();

    // Fetch page
    $has_history = historyTableExists($pdo);
    $last_login_join = $has_history
        ? "LEFT JOIN (SELECT user_id, MAX(logged_in_at) AS last_login_at FROM user_login_history WHERE user_agent != 'seed:account_created' GROUP BY user_id) ll ON ll.user_id = u.id"
        : '';
    $last_login_col = $has_history ? ', ll.last_login_at' : ', NULL AS last_login_at';

    $data_sql = "
        SELECT u.id, u.username, u.email, u.full_name, u.profile_picture,
               u.role, u.is_verified, u.videos_count, u.followers_count,
               u.following_count, u.ranking_points, u.created_at,
               uos.is_online, uos.last_seen
               $last_login_col
        FROM users u
        LEFT JOIN user_online_status uos ON uos.user_id = u.id
        $last_login_join
        $where_sql
        ORDER BY $order
        LIMIT $per_page OFFSET $offset
    ";
    $data_stmt = $pdo->prepare($data_sql);
    $data_stmt->execute($params);
    $users = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich: is_really_online flag
    $now = time();
    foreach ($users as &$u) {
        $last_seen_ts = $u['last_seen'] ? strtotime($u['last_seen']) : 0;
        $u['is_really_online'] = $u['is_online'] && ($now - $last_seen_ts < 120);
        $u['last_seen_ago']    = $u['last_seen'] ? timeAgo($u['last_seen']) : null;
        $u['last_login_ago']   = $u['last_login_at'] ? timeAgo($u['last_login_at']) : null;
        $u['avatar_url']       = avatar_url($u['profile_picture']);
        // Remove sensitive
        unset($u['profile_picture']);
    }
    unset($u);

    echo json_encode([
        'success'     => true,
        'users'       => $users,
        'total'       => $total_count,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => (int)ceil($total_count / $per_page),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// action=detail
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'detail') {
    $user_id = (int)($_GET['id'] ?? 0);
    if (!$user_id) { echo json_encode(['error' => 'ID inválido']); exit; }

    // User base info
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.full_name, u.bio, u.instituicao,
               u.profile_picture, u.role, u.is_verified, u.videos_count,
               u.followers_count, u.following_count, u.ranking_points, u.created_at,
               uos.is_online, uos.last_seen,
               (SELECT COALESCE(SUM(v.views_count),0)  FROM videos v WHERE v.user_id = u.id) AS total_views,
               (SELECT COALESCE(SUM(v.likes_count),0)  FROM videos v WHERE v.user_id = u.id) AS total_likes,
               (SELECT COALESCE(SUM(v.comments_count),0) FROM videos v WHERE v.user_id = u.id) AS total_comments
        FROM users u
        LEFT JOIN user_online_status uos ON uos.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['error' => 'Utilizador não encontrado']); exit; }

    $now = time();
    $last_seen_ts = $user['last_seen'] ? strtotime($user['last_seen']) : 0;
    $user['is_really_online'] = $user['is_online'] && ($now - $last_seen_ts < 120);
    $user['avatar_url'] = avatar_url($user['profile_picture']);

    // Login history
    $history = [];
    $login_metrics = [
        'total_logins'    => 0,
        'first_login'     => null,
        'last_login'      => null,
        'logins_per_week' => null,
        'days_since_last' => null,
        'time_to_second'  => null,  // hours
        'activity_class'  => 'unknown',
    ];

    if (historyTableExists($pdo)) {
        $hist_stmt = $pdo->prepare("
            SELECT id, logged_in_at, ip_address, user_agent
            FROM user_login_history
            WHERE user_id = ?
            ORDER BY logged_in_at DESC
            LIMIT 30
        ");
        $hist_stmt->execute([$user_id]);
        $history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM user_login_history
            WHERE user_id = ? AND user_agent != 'seed:account_created'
        ");
        $count_stmt->execute([$user_id]);
        $real_logins = (int)$count_stmt->fetchColumn();

        $login_metrics['total_logins'] = $real_logins;

        if (!empty($history)) {
            $all_stmt = $pdo->prepare("
                SELECT logged_in_at FROM user_login_history
                WHERE user_id = ?
                ORDER BY logged_in_at ASC
            ");
            $all_stmt->execute([$user_id]);
            $all_dates = $all_stmt->fetchAll(PDO::FETCH_COLUMN);

            $login_metrics['first_login'] = $all_dates[0] ?? null;
            $login_metrics['last_login']  = end($all_dates) ?: null;

            if ($login_metrics['last_login']) {
                $login_metrics['days_since_last'] = (int)floor(($now - strtotime($login_metrics['last_login'])) / 86400);
            }

            // Time to second login (first real gap)
            $real_dates = array_values(array_filter($history, fn($h) => $h['user_agent'] !== 'seed:account_created'));
            if (count($all_dates) >= 2) {
                $first  = strtotime($all_dates[0]);
                $second = strtotime($all_dates[1]);
                $login_metrics['time_to_second'] = round(($second - $first) / 3600, 1);
            }

            // Logins per week
            if ($login_metrics['first_login'] && $real_logins > 0) {
                $weeks = max(1, ($now - strtotime($login_metrics['first_login'])) / (7 * 86400));
                $login_metrics['logins_per_week'] = round($real_logins / $weeks, 2);
            }

            // Activity class
            $days = $login_metrics['days_since_last'] ?? 999;
            if ($days <= 3)       $login_metrics['activity_class'] = 'active';
            elseif ($days <= 14)  $login_metrics['activity_class'] = 'casual';
            elseif ($days <= 60)  $login_metrics['activity_class'] = 'inactive';
            else                  $login_metrics['activity_class'] = 'churned';
        }
    }

    echo json_encode([
        'success'        => true,
        'user'           => $user,
        'login_metrics'  => $login_metrics,
        'login_history'  => $history,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// action=retention
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'retention') {
    if (!historyTableExists($pdo)) {
        echo json_encode(['success' => true, 'table_missing' => true]);
        exit;
    }

    // Distribuição do tempo até 2.º login
    $dist_stmt = $pdo->query("
        SELECT
            SUM(CASE WHEN gap_hours < 1     THEN 1 ELSE 0 END) AS lt_1h,
            SUM(CASE WHEN gap_hours BETWEEN 1 AND 23   THEN 1 ELSE 0 END) AS lt_24h,
            SUM(CASE WHEN gap_hours BETWEEN 24 AND 71  THEN 1 ELSE 0 END) AS lt_3d,
            SUM(CASE WHEN gap_hours BETWEEN 72 AND 167 THEN 1 ELSE 0 END) AS lt_7d,
            SUM(CASE WHEN gap_hours BETWEEN 168 AND 719 THEN 1 ELSE 0 END) AS lt_30d,
            SUM(CASE WHEN gap_hours >= 720  THEN 1 ELSE 0 END) AS gt_30d
        FROM (
            SELECT user_id, TIMESTAMPDIFF(HOUR, first_login, second_login) AS gap_hours
            FROM (
                SELECT user_id,
                       MIN(logged_in_at) AS first_login,
                       MIN(CASE WHEN logged_in_at > (
                           SELECT MIN(l2.logged_in_at) FROM user_login_history l2
                           WHERE l2.user_id = ulh.user_id AND l2.user_agent != 'seed:account_created'
                       ) THEN logged_in_at END) AS second_login
                FROM user_login_history ulh
                WHERE user_agent != 'seed:account_created'
                GROUP BY user_id
                HAVING second_login IS NOT NULL
            ) pairs
        ) dist
    ");
    $distribution = $dist_stmt->fetch(PDO::FETCH_ASSOC);

    // Utilizadores que NUNCA voltaram (apenas têm o seed)
    $never_returned = (int)$pdo->query("
        SELECT COUNT(DISTINCT user_id) FROM user_login_history
        WHERE user_id NOT IN (
            SELECT user_id FROM user_login_history
            WHERE user_agent != 'seed:account_created'
        )
    ")->fetchColumn();

    // Total utilizadores com seed
    $total_seeded = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_login_history")->fetchColumn();

    // Cohorts semanais (últimas 8 semanas): semana de criação → % que voltou
    $cohorts = $pdo->query("
        SELECT
            DATE(DATE_SUB(u.created_at, INTERVAL WEEKDAY(u.created_at) DAY)) AS week_start,
            COUNT(DISTINCT u.id) AS cohort_size,
            COUNT(DISTINCT lh.user_id) AS returned_users
        FROM users u
        LEFT JOIN user_login_history lh
            ON lh.user_id = u.id
            AND lh.user_agent != 'seed:account_created'
        WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
          AND COALESCE(u.role,'user') != 'admin'
        GROUP BY week_start
        ORDER BY week_start DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cohorts as &$c) {
        $c['retention_pct'] = $c['cohort_size'] > 0
            ? round($c['returned_users'] / $c['cohort_size'] * 100, 1)
            : 0;
    }
    unset($c);

    // Top utilizadores em risco (activos antes, não voltam há >30 dias)
    $at_risk = $pdo->query("
        SELECT u.id, u.username, u.full_name, u.profile_picture, u.videos_count,
               MAX(lh.logged_in_at) AS last_login,
               DATEDIFF(NOW(), MAX(lh.logged_in_at)) AS days_away
        FROM users u
        INNER JOIN user_login_history lh ON lh.user_id = u.id
          AND lh.user_agent != 'seed:account_created'
        WHERE COALESCE(u.role,'user') != 'admin'
        GROUP BY u.id
        HAVING days_away BETWEEN 30 AND 180
        ORDER BY days_away DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($at_risk as &$r) {
        $r['avatar_url'] = avatar_url($r['profile_picture']);
        unset($r['profile_picture']);
    }
    unset($r);

    $churn_rate = $total_seeded > 0 ? round($never_returned / $total_seeded * 100, 1) : null;

    echo json_encode([
        'success'        => true,
        'distribution'   => $distribution,
        'never_returned' => $never_returned,
        'total_seeded'   => $total_seeded,
        'churn_rate'     => $churn_rate,
        'cohorts'        => $cohorts,
        'at_risk'        => $at_risk,
    ]);
    exit;
}

echo json_encode(['error' => 'Ação desconhecida']);
