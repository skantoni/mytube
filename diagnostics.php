<?php
/**
 * ============================================================
 * DIAGNÓSTICO DE PERFORMANCE — mytube.social
 * ============================================================
 * ATENÇÃO: Apaga este ficheiro da VPS após usar!
 * Acesso: https://mytube.social/diagnostics.php?secret=MUDAR_ISTO
 * ============================================================
 */

define('DIAG_SECRET', 'mytube_diag_2026'); // ← muda antes de usar

// Protecção básica
if (($_GET['secret'] ?? '') !== DIAG_SECRET) {
    http_response_code(403);
    die('403 Forbidden');
}

require_once __DIR__ . '/includes/config.php';

// ── Helpers ─────────────────────────────────────────────────

function ms(float $start): string {
    return number_format((microtime(true) - $start) * 1000, 2) . ' ms';
}

function badge(float $ms): string {
    if ($ms < 50)  return '<span style="background:#22c55e;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">RÁPIDO</span>';
    if ($ms < 200) return '<span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">LENTO</span>';
    return '<span style="background:#ef4444;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">CRÍTICO</span>';
}

function runQuery($pdo, string $sql, array $params = []): array {
    $t = microtime(true);
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $elapsed = (microtime(true) - $t) * 1000;
        return ['ok' => true, 'ms' => $elapsed, 'rows' => count($rows), 'data' => $rows];
    } catch (Exception $e) {
        $elapsed = (microtime(true) - $t) * 1000;
        return ['ok' => false, 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

function section(string $title): void {
    echo "<h2 style='margin-top:32px;border-bottom:2px solid #334155;padding-bottom:8px;color:#38bdf8'>$title</h2>\n";
}

function row(string $label, array $result, string $note = ''): void {
    $ms = $result['ms'];
    $b  = badge($ms);
    $info = $result['ok']
        ? "({$result['rows']} linhas)"
        : "<span style='color:#ef4444'>ERRO: " . htmlspecialchars($result['error'] ?? '') . "</span>";
    $noteHtml = $note ? "<br><small style='color:#94a3b8'>$note</small>" : '';
    echo "<tr>
        <td style='padding:8px 12px;border-bottom:1px solid #1e293b;min-width:280px'>$label $b</td>
        <td style='padding:8px 12px;border-bottom:1px solid #1e293b;font-family:monospace;font-weight:bold;color:" . ($ms > 200 ? '#ef4444' : ($ms > 50 ? '#f59e0b' : '#22c55e')) . "'>" . number_format($ms, 1) . " ms</td>
        <td style='padding:8px 12px;border-bottom:1px solid #1e293b;color:#94a3b8'>$info$noteHtml</td>
    </tr>\n";
}

// ── Início ───────────────────────────────────────────────────
$page_start = microtime(true);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Diagnóstico Performance — MyTube</title>
<style>
  body { font-family: 'Segoe UI', sans-serif; background:#0f172a; color:#e2e8f0; padding:24px; }
  h1   { color:#f8fafc; }
  h2   { color:#38bdf8; }
  table { width:100%; border-collapse:collapse; background:#1e293b; border-radius:8px; overflow:hidden; margin-bottom:16px; }
  th   { background:#0f172a; padding:10px 12px; text-align:left; color:#94a3b8; font-size:13px; }
  pre  { background:#0f172a; padding:16px; border-radius:8px; overflow:auto; font-size:13px; }
  .card { background:#1e293b; border-radius:8px; padding:16px; margin-bottom:16px; }
  .warn { color:#f59e0b; }
  .bad  { color:#ef4444; }
  .good { color:#22c55e; }
  a    { color:#38bdf8; }
</style>
</head>
<body>
<h1>🔍 Diagnóstico de Performance — mytube.social</h1>
<p style="color:#94a3b8">Gerado em: <?= date('Y-m-d H:i:s') ?> | PHP <?= PHP_VERSION ?></p>

<?php

// ════════════════════════════════════════════════════════════
// 1. INFORMAÇÕES DO SISTEMA
// ════════════════════════════════════════════════════════════
section('1. Informações do Sistema');
echo '<div class="card"><pre>';

// MySQL version & variables
$mysqlVersion = $pdo->query("SELECT VERSION() AS v")->fetchColumn();
$innodb_buffer = $pdo->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'")->fetch()['Value'] ?? 'N/A';
$query_cache   = $pdo->query("SHOW VARIABLES LIKE 'query_cache_type'")->fetch()['Value'] ?? 'N/A';
$slow_log      = $pdo->query("SHOW VARIABLES LIKE 'slow_query_log'")->fetch()['Value'] ?? 'N/A';
$slow_log_time = $pdo->query("SHOW VARIABLES LIKE 'long_query_time'")->fetch()['Value'] ?? 'N/A';
$slow_log_file = $pdo->query("SHOW VARIABLES LIKE 'slow_query_log_file'")->fetch()['Value'] ?? 'N/A';
$max_conn      = $pdo->query("SHOW VARIABLES LIKE 'max_connections'")->fetch()['Value'] ?? 'N/A';
$wait_timeout  = $pdo->query("SHOW VARIABLES LIKE 'wait_timeout'")->fetch()['Value'] ?? 'N/A';

echo "MySQL Version:          $mysqlVersion\n";
echo "InnoDB Buffer Pool:     " . number_format((int)$innodb_buffer / 1024 / 1024) . " MB\n";
echo "Query Cache Type:       $query_cache\n";
echo "Slow Query Log:         $slow_log (ficheiro: $slow_log_file)\n";
echo "Long Query Time:        {$slow_log_time}s\n";
echo "Max Connections:        $max_conn\n";
echo "Wait Timeout:           {$wait_timeout}s\n";
echo "PHP Version:            " . PHP_VERSION . "\n";
echo "OPcache:                " . (function_exists('opcache_get_status') ? 'Disponível' : 'DESATIVADO') . "\n";

if (function_exists('opcache_get_status')) {
    $oc = opcache_get_status(false);
    $hit_rate = isset($oc['opcache_statistics']['opcache_hit_rate'])
        ? number_format($oc['opcache_statistics']['opcache_hit_rate'], 1) . '%'
        : 'N/A';
    echo "OPcache Hit Rate:       $hit_rate\n";
    echo "OPcache Enabled:        " . ($oc['opcache_enabled'] ? 'Sim' : 'NÃO') . "\n";
}

$session_path = ini_get('session.save_path');
$session_count = is_dir($session_path) ? count(glob($session_path . '/sess_*')) : 0;
echo "Session Count (disco):  $session_count ficheiros em $session_path\n";

echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// 2. CONTAGENS DE TABELAS
// ════════════════════════════════════════════════════════════
section('2. Contagens de Tabelas');
echo '<table><tr><th>Tabela</th><th>Tempo</th><th>Info</th></tr>';

$tables = [
    ['videos',          'SELECT COUNT(*) FROM videos',         'Total vídeos'],
    ['users',           'SELECT COUNT(*) FROM users',          'Total utilizadores'],
    ['video_likes',     'SELECT COUNT(*) FROM video_likes',    'Total likes'],
    ['video_views',     'SELECT COUNT(*) FROM video_views',    'Total views'],
    ['comments',        'SELECT COUNT(*) FROM comments',       'Total comentários'],
    ['user_feed_seen',  'SELECT COUNT(*) FROM user_feed_seen', 'Histórico de feed'],
    ['boost_impressions','SELECT COUNT(*) FROM boost_impressions','Impressões boost'],
];
foreach ($tables as [$label, $sql, $note]) {
    $r = runQuery($pdo, $sql);
    if ($r['ok'] && !empty($r['data'])) {
        $count = current($r['data'][0]);
        $r['rows'] = (int)$count;
        // Rewrite row count to show actual count
        echo "<tr>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;min-width:280px'>$label " . badge($r['ms']) . "</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;font-family:monospace;font-weight:bold;color:" . ($r['ms'] > 200 ? '#ef4444' : ($r['ms'] > 50 ? '#f59e0b' : '#22c55e')) . "'>" . number_format($r['ms'], 1) . " ms</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;color:#94a3b8'>" . number_format((int)$count) . " registos — $note</td>
        </tr>\n";
    } else {
        row($label, $r, $note . ' (tabela pode não existir)');
    }
}
echo '</table>';

// ════════════════════════════════════════════════════════════
// 3. ÍNDICES — VERIFICAR SE EXISTEM
// ════════════════════════════════════════════════════════════
section('3. Índices Críticos');
echo '<table><tr><th>Tabela.Coluna</th><th>Índice?</th><th>Impacto</th></tr>';

$indexes_to_check = [
    ['videos',          'user_id',          'Feed por utilizador'],
    ['videos',          'is_public',        'Filtro público no feed'],
    ['videos',          'created_at',       'Ordenação temporal no feed'],
    ['videos',          'moderation_status','Filtro de moderação'],
    ['video_likes',     'video_id',         'Contagem de likes'],
    ['video_likes',     'user_id',          'Likes do utilizador'],
    ['video_views',     'video_id',         'Contagem de views'],
    ['video_views',     'viewed_at',        'Views recentes (trending)'],
    ['comments',        'video_id',         'Comentários por vídeo'],
    ['user_feed_seen',  'user_id',          'Histórico feed'],
    ['user_feed_seen',  'seen_at',          'Limpeza de feed'],
    ['boost_impressions','user_id',         'Cap diário boost'],
];

foreach ($indexes_to_check as [$table, $col, $impact]) {
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Column_name = ?");
        $stmt->execute([$col]);
        $found = $stmt->fetchAll();
        $has_idx = !empty($found);
        $idx_names = array_column($found, 'Key_name');
        $label = "$table.$col";
        $status = $has_idx
            ? "<span class='good'>✔ " . implode(', ', $idx_names) . "</span>"
            : "<span class='bad'>✘ SEM ÍNDICE</span>";
        echo "<tr>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b'>$label</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b'>$status</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;color:#94a3b8'>$impact</td>
        </tr>\n";
    } catch (Exception $e) {
        echo "<tr><td style='padding:8px 12px;border-bottom:1px solid #1e293b'>$table.$col</td><td colspan=2 style='color:#ef4444;padding:8px 12px;border-bottom:1px solid #1e293b'>Tabela não encontrada</td></tr>\n";
    }
}
echo '</table>';

// ════════════════════════════════════════════════════════════
// 4. QUERIES DO FEED — AS MAIS CRÍTICAS
// ════════════════════════════════════════════════════════════
section('4. Queries do Feed (get_feed.php)');
echo '<table><tr><th>Query</th><th>Tempo</th><th>Info</th></tr>';

// 4a. Candidate rows (feed principal) — simula fetchCandidateRows
$r = runQuery($pdo, "
    SELECT v.id, v.is_boosted,
        (
            LEAST(25, LOG10(v.likes_count + 1) * 10)
            + LEAST(15, LOG10(v.views_count + 1) * 5)
            + LEAST(15, LOG10(v.comments_count + 1) * 8)
        ) * EXP(-0.0005 * TIMESTAMPDIFF(HOUR, v.created_at, NOW())) AS base_weight
    FROM videos v
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
    ORDER BY base_weight DESC
    LIMIT 300
");
row('fetchCandidateRows (feed, 300 cands)', $r, 'Base do algoritmo de feed — deve ser <50ms');

// 4b. Feed com LEFT JOIN user_feed_seen
$r = runQuery($pdo, "
    SELECT v.id, v.is_boosted
    FROM videos v
    LEFT JOIN user_feed_seen ufs ON ufs.video_id = v.id AND ufs.user_id = 1 AND ufs.seen_at > DATE_SUB(NOW(), INTERVAL 21 DAY)
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
      AND ufs.video_id IS NULL
    ORDER BY v.created_at DESC
    LIMIT 300
", []);
row('Feed com LEFT JOIN user_feed_seen', $r, 'Deduplicação cross-sessão — deve ser <80ms');

// 4c. Subquery weekly_points por vídeo (CRÍTICO — corre para CADA vídeo no feed)
$r = runQuery($pdo, "
    SELECT v.id,
        COALESCE((
            SELECT COUNT(DISTINCT v2.id) * 10 + COALESCE(SUM(v2.likes_count), 0) * 2 + COALESCE(SUM(v2.comments_count), 0) * 3 + COALESCE(SUM(v2.views_count), 0) * 1
            FROM videos v2
            WHERE v2.user_id = v.user_id AND v2.is_public = 1
            AND v2.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
        ), 0) AS weekly_points
    FROM videos v
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
    LIMIT 10
");
row('Subquery weekly_points (por 10 vídeos)', $r, '⚠️ CORRELATED SUBQUERY — escala O(n). Se >100ms aqui, vai degradar o feed inteiro');

// 4d. Fetch final dos 4 vídeos do feed (com a subquery weekly_points)
$r = runQuery($pdo, "
    SELECT v.id, v.title, v.description, v.video_path, v.thumbnail_path,
        v.views_count, v.likes_count, v.comments_count,
        v.created_at, v.user_id as video_user_id, v.is_boosted, v.hashtags,
        u.username, u.full_name, u.profile_picture, u.is_verified,
        COALESCE((
            SELECT COUNT(DISTINCT v2.id) * 10 + COALESCE(SUM(v2.likes_count), 0) * 2 + COALESCE(SUM(v2.comments_count), 0) * 3 + COALESCE(SUM(v2.views_count), 0) * 1
            FROM videos v2
            WHERE v2.user_id = u.id AND v2.is_public = 1
            AND v2.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
        ), 0) AS weekly_points,
        s.name as school_name, s.short_name as school_short
    FROM videos v
    INNER JOIN users u ON v.user_id = u.id
    LEFT JOIN schools s ON u.school_id = s.id
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
    LIMIT 4
");
row('Fetch final 4 vídeos (com weekly_points)', $r, '⚠️ Esta query é feita a CADA scroll do feed');

echo '</table>';

// ════════════════════════════════════════════════════════════
// 5. QUERIES DE RANKINGS (get_rankings.php)
// ════════════════════════════════════════════════════════════
section('5. Queries de Rankings');
echo '<table><tr><th>Query</th><th>Tempo</th><th>Info</th></tr>';

// 5a. get_my_rank — correlated subqueries
$r = runQuery($pdo, "
    SELECT COUNT(*) + 1 AS global_rank FROM users u2
    WHERE (u2.role != 'admin' OR u2.role IS NULL) AND COALESCE((
        SELECT COUNT(*) * 10 + COALESCE(SUM(v.likes_count), 0) * 2 + COALESCE(SUM(v.comments_count), 0) * 3 + COALESCE(SUM(v.views_count), 0) * 1
        FROM videos v WHERE v.user_id = u2.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    ), 0) > 100
");
row('get_my_rank — global_rank correlated', $r, '⚠️ Correlated subquery sobre TODOS os utilizadores — O(n×m)');

// 5b. get_top_creators — GROUP BY + aggregate
$r = runQuery($pdo, "
    SELECT u.id, u.username,
        COUNT(DISTINCT v.id) AS total_videos,
        COALESCE(SUM(v.likes_count), 0) AS total_likes,
        (COUNT(DISTINCT v.id) * 10 + COALESCE(SUM(v.likes_count), 0) * 2 + COALESCE(SUM(v.comments_count), 0) * 3 + COALESCE(SUM(v.views_count), 0) * 1) AS points
    FROM users u
    LEFT JOIN videos v ON v.user_id = u.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    WHERE (u.role != 'admin' OR u.role IS NULL)
    GROUP BY u.id
    HAVING total_videos > 0
    ORDER BY points DESC
    LIMIT 30
");
row('get_top_creators (3 meses)', $r, 'Cacheada 5min mas a 1ª vez pode ser lenta');

// 5c. Trending videos — 3 subqueries de 48h
$r = runQuery($pdo, "
    SELECT v.id, v.title,
        COALESCE(rv.recent_views, 0) AS recent_views,
        COALESCE(rl.recent_likes, 0) AS recent_likes,
        ROUND(
            (COALESCE(rv.recent_views, 0) + COALESCE(rl.recent_likes, 0) * 3)
            * (1.0 / (1.0 + TIMESTAMPDIFF(HOUR, v.created_at, NOW()) / 24.0))
            * 100
        ) AS score
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
    WHERE v.is_public = 1
    ORDER BY score DESC, v.created_at DESC
    LIMIT 6
");
row('get_trending_videos (48h)', $r, 'Subconsultas em video_views e video_likes');

// 5d. get_dominant_school — múltiplos correlated
$r = runQuery($pdo, "
    SELECT s.id, s.name,
        (SELECT COUNT(DISTINCT v.id) FROM videos v JOIN users u ON v.user_id = u.id WHERE u.school_id = s.id AND v.is_public = 1 AND v.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)) AS total_videos,
        (SELECT COALESCE(SUM(v.views_count), 0) FROM videos v JOIN users u ON v.user_id = u.id WHERE u.school_id = s.id AND v.is_public = 1 AND v.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)) AS total_views
    FROM schools s
    WHERE s.is_active = 1
    LIMIT 20
");
row('get_dominant_school (correlated)', $r, '⚠️ Múltiplos subselects por escola — pode ser muito lento com muitas escolas');

echo '</table>';

// ════════════════════════════════════════════════════════════
// 6. PERFIL (profile.php) — tipicamente a página mais lenta
// ════════════════════════════════════════════════════════════
section('6. Queries de Perfil (profile.php)');
echo '<table><tr><th>Query</th><th>Tempo</th><th>Info</th></tr>';

// Pegar um user_id real para testar
$sample = $pdo->query("SELECT id FROM users WHERE role != 'admin' LIMIT 1")->fetch();
$test_user_id = $sample ? (int)$sample['id'] : 1;

$r = runQuery($pdo, "
    SELECT u.*, s.name as school_name, s.short_name as school_short
    FROM users u
    LEFT JOIN schools s ON u.school_id = s.id
    WHERE u.id = ?
", [$test_user_id]);
row('Dados base do perfil (user + school)', $r, "user_id=$test_user_id");

$r = runQuery($pdo, "SELECT COUNT(*) FROM follows WHERE following_id = ?", [$test_user_id]);
row('Contagem de seguidores', $r, "follows.following_id=$test_user_id");

$r = runQuery($pdo, "SELECT COUNT(*) FROM follows WHERE follower_id = ?", [$test_user_id]);
row('Contagem a seguir', $r, "follows.follower_id=$test_user_id");

$r = runQuery($pdo, "
    SELECT v.*, v.views_count, v.likes_count
    FROM videos v
    WHERE v.user_id = ? AND v.is_public = 1 AND v.moderation_status = 'approved'
    ORDER BY v.created_at DESC
    LIMIT 12
", [$test_user_id]);
row('Vídeos do perfil (12 recentes)', $r);

echo '</table>';

// ════════════════════════════════════════════════════════════
// 7. SLOW QUERIES — últimas do MySQL
// ════════════════════════════════════════════════════════════
section('7. Estado de Queries Lentas no MySQL');
echo '<div class="card"><pre>';

try {
    // Processlist atual
    $pl = $pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
    $slow = array_filter($pl, fn($p) => ($p['Time'] ?? 0) > 1);
    if (empty($slow)) {
        echo "✔ Nenhuma query a correr há mais de 1s no momento\n\n";
    } else {
        echo "⚠️ QUERIES LENTAS A CORRER AGORA:\n";
        foreach ($slow as $p) {
            echo "  [{$p['Id']}] {$p['Time']}s | {$p['State']} | " . substr($p['Info'] ?? '', 0, 120) . "\n";
        }
    }

    // Global status úteis
    $status = [];
    $rows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Slow_queries','Questions','Uptime','Threads_connected','Threads_running','Table_locks_waited','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests')")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $uptime    = (int)($rows['Uptime'] ?? 0);
    $questions = (int)($rows['Questions'] ?? 0);
    $slow_q    = (int)($rows['Slow_queries'] ?? 0);
    $qps       = $uptime > 0 ? number_format($questions / $uptime, 1) : '?';
    $bp_reads  = (int)($rows['Innodb_buffer_pool_reads'] ?? 0);
    $bp_reqs   = (int)($rows['Innodb_buffer_pool_read_requests'] ?? 0);
    $bp_hit    = $bp_reqs > 0 ? number_format((1 - $bp_reads / $bp_reqs) * 100, 2) . '%' : 'N/A';

    echo "Uptime:                    " . gmdate('H\h i\m', $uptime) . "\n";
    echo "Queries/segundo (médio):   $qps\n";
    echo "Slow queries (desde boot): $slow_q\n";
    echo "Threads connectadas:       {$rows['Threads_connected']}\n";
    echo "Threads a correr agora:    {$rows['Threads_running']}\n";
    echo "Table locks waited:        {$rows['Table_locks_waited']}\n";
    echo "InnoDB Buffer Pool Hit:    $bp_hit" . ($bp_hit !== 'N/A' && (float)$bp_hit < 95 ? " ⚠️ BAIXO (ideal >99%)" : " ✔") . "\n";

} catch (Exception $e) {
    echo "Erro: " . htmlspecialchars($e->getMessage());
}

echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// 8. EXPLAIN — QUERIES MAIS PESADAS
// ════════════════════════════════════════════════════════════
section('8. EXPLAIN das Queries Mais Pesadas');

$queries_to_explain = [
    'Feed candidatos (sem seen)' => [
        "EXPLAIN SELECT v.id, v.is_boosted,
            (LEAST(25, LOG10(v.likes_count + 1) * 10) + LEAST(15, LOG10(v.views_count + 1) * 5)) * EXP(-0.0005 * TIMESTAMPDIFF(HOUR, v.created_at, NOW())) AS base_weight
         FROM videos v
         WHERE v.is_public = 1 AND v.moderation_status = 'approved'
         ORDER BY base_weight DESC LIMIT 300",
        []
    ],
    'Feed candidatos (com LEFT JOIN user_feed_seen)' => [
        "EXPLAIN SELECT v.id FROM videos v
         LEFT JOIN user_feed_seen ufs ON ufs.video_id = v.id AND ufs.user_id = ? AND ufs.seen_at > DATE_SUB(NOW(), INTERVAL 21 DAY)
         WHERE v.is_public = 1 AND v.moderation_status = 'approved' AND ufs.video_id IS NULL
         ORDER BY v.created_at DESC LIMIT 300",
        [1]
    ],
    'Trending videos (48h joins)' => [
        "EXPLAIN SELECT v.id FROM videos v
         JOIN users u ON v.user_id = u.id
         LEFT JOIN (SELECT video_id, COUNT(*) AS cnt FROM video_views WHERE viewed_at >= NOW() - INTERVAL 48 HOUR GROUP BY video_id) rv ON rv.video_id = v.id
         WHERE v.is_public = 1
         LIMIT 10",
        []
    ],
];

foreach ($queries_to_explain as $label => $item) {
    [$sql, $params] = $item;
    echo "<h3 style='color:#94a3b8;margin-top:20px'>$label</h3>";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<table><tr>';
        foreach (array_keys($rows[0] ?? []) as $k) echo "<th>$k</th>";
        echo '</tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $k => $v) {
                $v = $v ?? 'NULL';
                $class = '';
                if ($k === 'type' && in_array($v, ['ALL', 'index'])) $class = 'class="bad"';
                if ($k === 'Extra' && str_contains($v, 'filesort')) $class = 'class="warn"';
                if ($k === 'rows') $class = (int)$v > 1000 ? 'class="warn"' : '';
                echo "<td $class style='padding:6px 10px;border-bottom:1px solid #0f172a'>" . htmlspecialchars((string)$v) . "</td>";
            }
            echo '</tr>';
        }
        echo '</table>';
    } catch (Exception $e) {
        echo "<p class='bad'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// ════════════════════════════════════════════════════════════
// 9. CACHE DE FICHEIROS (ranking_cache)
// ════════════════════════════════════════════════════════════
section('9. Cache de Ficheiros (ranking_cache)');
echo '<div class="card"><pre>';
$cache_dir = __DIR__ . '/cache';
if (is_dir($cache_dir)) {
    $files = glob($cache_dir . '/*.json');
    if (empty($files)) {
        echo "⚠️ Directório de cache existe mas está vazio — a 1ª chamada às rankings será sempre lenta\n";
    } else {
        echo count($files) . " ficheiros de cache encontrados:\n";
        foreach ($files as $f) {
            $age = time() - filemtime($f);
            echo "  " . basename($f) . " — " . number_format(filesize($f)/1024, 1) . " KB — idade: " . ($age < 60 ? $age . 's' : floor($age/60) . 'min') . "\n";
        }
    }
} else {
    echo "⚠️ Directório de cache NÃO existe: $cache_dir\n";
}
echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// 10. SESSÕES
// ════════════════════════════════════════════════════════════
section('10. Sessões no Disco');
echo '<div class="card"><pre>';
$sess_dir = __DIR__ . '/sessions';
if (is_dir($sess_dir)) {
    $sess_files = glob($sess_dir . '/sess_*');
    $count = count($sess_files);
    echo "Total de sessões: $count ficheiros\n";
    if ($count > 1000) {
        echo "⚠️ MUITAS SESSÕES — pode causar lentidão na leitura do disco\n";
        echo "   Recomendado: usar Redis ou sessões em BD para sites com muito tráfego\n";
    } elseif ($count > 500) {
        echo "⚠️ Sessões a crescer — monitorizar\n";
    } else {
        echo "✔ Número de sessões normal\n";
    }
    
    // Tamanho total
    $total_size = 0;
    foreach ($sess_files as $f) { $total_size += filesize($f); }
    echo "Tamanho total: " . number_format($total_size / 1024, 1) . " KB\n";
    
    // Sessão mais antiga
    if (!empty($sess_files)) {
        $oldest = min(array_map('filemtime', $sess_files));
        echo "Sessão mais antiga: " . date('Y-m-d H:i:s', $oldest) . " (" . floor((time()-$oldest)/86400) . " dias)\n";
    }
} else {
    echo "Directório de sessões não encontrado: $sess_dir\n";
}
echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// SUMÁRIO FINAL
// ════════════════════════════════════════════════════════════
$total_time = (microtime(true) - $page_start) * 1000;
section('Sumário');
echo "<div class='card'>";
echo "<p>⏱️ Este diagnóstico correu em " . number_format($total_time, 0) . " ms</p>";
echo "<h3>Problemas mais comuns de lentidão nesta app:</h3>
<ol style='line-height:2'>
    <li><strong class='warn'>Correlated subquery <code>weekly_points</code> no feed</strong> — executa uma subquery por vídeo retornado. Se o feed retornar 4 vídeos, são 4 subqueries extras. Solução: pré-calcular e guardar em coluna <code>users.weekly_points</code>.</li>
    <li><strong class='warn'>get_my_rank — correlated sobre todos os utilizadores</strong> — para calcular a posição global, faz uma subquery para <em>cada</em> utilizador na tabela. Com 1000 users = 1000 subqueries. Solução: usar coluna <code>ranking_points</code> pré-calculada.</li>
    <li><strong class='warn'>Trending videos sem índice em video_views.viewed_at</strong> — table scan em potencialmente milhões de linhas.</li>
    <li><strong class='warn'>dominant_school — múltiplos correlated por escola</strong> — 5 subqueries por escola activa. Cacheado mas a 1ª chamada é cara.</li>
    <li><strong class='bad'>OPcache desativado</strong> — se o OPcache estiver desativado, cada request recompila todos os ficheiros PHP (verifique item 1 acima).</li>
    <li><strong class='bad'>Sessões em disco sem Redis</strong> — com muitos utilizadores, as sessões em ficheiro podem ser um gargalo de I/O.</li>
</ol>
<p style='margin-top:16px'>📋 Vê o EXPLAIN na secção 8 — linhas com <code class='bad'>type=ALL</code> indicam table scans completos.</p>
<p style='color:#ef4444;margin-top:16px'><strong>⚠️ Apaga este ficheiro após usar: <code>rm /var/www/mytube.social/diagnostics.php</code></strong></p>";
echo "</div>";
?>
</body>
</html>
<?php
/**
 * ============================================================
 * DIAGNÓSTICO DE PERFORMANCE — mytube.social
 * ============================================================
 * ATENÇÃO: Apaga este ficheiro da VPS após usar!
 * Acesso: https://mytube.social/diagnostics.php?secret=MUDAR_ISTO
 * ============================================================
 */

require_once __DIR__ . '/includes/env_loader.php';
$diag_secret = env('DIAG_SECRET', '');
if ($diag_secret === '' || strlen($diag_secret) < 16) {
    http_response_code(503);
    die('DIAG_SECRET not configured in .env');
}

if (!hash_equals($diag_secret, ($_GET['secret'] ?? ''))) {
    http_response_code(403);
    die('403 Forbidden');
}

require_once __DIR__ . '/includes/config.php';

// ── Helpers ─────────────────────────────────────────────────

function ms(float $start): string {
    return number_format((microtime(true) - $start) * 1000, 2) . ' ms';
}

function badge(float $ms): string {
    if ($ms < 50)  return '<span style="background:#22c55e;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">RÁPIDO</span>';
    if ($ms < 200) return '<span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">LENTO</span>';
    return '<span style="background:#ef4444;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">CRÍTICO</span>';
}

function runQuery($pdo, string $sql, array $params = []): array {
    $t = microtime(true);
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $elapsed = (microtime(true) - $t) * 1000;
        return ['ok' => true, 'ms' => $elapsed, 'rows' => count($rows), 'data' => $rows];
    } catch (Exception $e) {
        $elapsed = (microtime(true) - $t) * 1000;
        return ['ok' => false, 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

function section(string $title): void {
    echo "<h2 style='margin-top:32px;border-bottom:2px solid #334155;padding-bottom:8px;color:#38bdf8'>$title</h2>\n";
}

function row(string $label, array $result, string $note = ''): void {
    $ms = $result['ms'];
    $b  = badge($ms);
    $info = $result['ok']
        ? "({$result['rows']} linhas)"
        : "<span style='color:#ef4444'>ERRO: " . htmlspecialchars($result['error'] ?? '') . "</span>";
    $noteHtml = $note ? "<br><small style='color:#94a3b8'>$note</small>" : '';
    echo "<tr>
        <td style='padding:8px 12px;border-bottom:1px solid #1e293b;min-width:280px'>$label $b</td>
        <td style='padding:8px 12px;border-bottom:1px solid #1e293b;font-family:monospace;font-weight:bold;color:" . ($ms > 200 ? '#ef4444' : ($ms > 50 ? '#f59e0b' : '#22c55e')) . "'>" . number_format($ms, 1) . " ms</td>
        <td style='padding:8px 12px;border-bottom:1px solid #1e293b;color:#94a3b8'>$info$noteHtml</td>
    </tr>\n";
}

// ── Início ───────────────────────────────────────────────────
$page_start = microtime(true);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Diagnóstico Performance — MyTube</title>
<style>
  body { font-family: 'Segoe UI', sans-serif; background:#0f172a; color:#e2e8f0; padding:24px; }
  h1   { color:#f8fafc; }
  h2   { color:#38bdf8; }
  table { width:100%; border-collapse:collapse; background:#1e293b; border-radius:8px; overflow:hidden; margin-bottom:16px; }
  th   { background:#0f172a; padding:10px 12px; text-align:left; color:#94a3b8; font-size:13px; }
  pre  { background:#0f172a; padding:16px; border-radius:8px; overflow:auto; font-size:13px; }
  .card { background:#1e293b; border-radius:8px; padding:16px; margin-bottom:16px; }
  .warn { color:#f59e0b; }
  .bad  { color:#ef4444; }
  .good { color:#22c55e; }
  a    { color:#38bdf8; }
</style>
</head>
<body>
<h1>🔍 Diagnóstico de Performance — mytube.social</h1>
<p style="color:#94a3b8">Gerado em: <?= date('Y-m-d H:i:s') ?> | PHP <?= PHP_VERSION ?></p>

<?php

// ════════════════════════════════════════════════════════════
// 1. INFORMAÇÕES DO SISTEMA
// ════════════════════════════════════════════════════════════
section('1. Informações do Sistema');
echo '<div class="card"><pre>';

// MySQL version & variables
$mysqlVersion = $pdo->query("SELECT VERSION() AS v")->fetchColumn();
$innodb_buffer = $pdo->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'")->fetch()['Value'] ?? 'N/A';
$query_cache   = $pdo->query("SHOW VARIABLES LIKE 'query_cache_type'")->fetch()['Value'] ?? 'N/A';
$slow_log      = $pdo->query("SHOW VARIABLES LIKE 'slow_query_log'")->fetch()['Value'] ?? 'N/A';
$slow_log_time = $pdo->query("SHOW VARIABLES LIKE 'long_query_time'")->fetch()['Value'] ?? 'N/A';
$slow_log_file = $pdo->query("SHOW VARIABLES LIKE 'slow_query_log_file'")->fetch()['Value'] ?? 'N/A';
$max_conn      = $pdo->query("SHOW VARIABLES LIKE 'max_connections'")->fetch()['Value'] ?? 'N/A';
$wait_timeout  = $pdo->query("SHOW VARIABLES LIKE 'wait_timeout'")->fetch()['Value'] ?? 'N/A';

echo "MySQL Version:          $mysqlVersion\n";
echo "InnoDB Buffer Pool:     " . number_format((int)$innodb_buffer / 1024 / 1024) . " MB\n";
echo "Query Cache Type:       $query_cache\n";
echo "Slow Query Log:         $slow_log (ficheiro: $slow_log_file)\n";
echo "Long Query Time:        {$slow_log_time}s\n";
echo "Max Connections:        $max_conn\n";
echo "Wait Timeout:           {$wait_timeout}s\n";
echo "PHP Version:            " . PHP_VERSION . "\n";
echo "OPcache:                " . (function_exists('opcache_get_status') ? 'Disponível' : 'DESATIVADO') . "\n";

if (function_exists('opcache_get_status')) {
    $oc = opcache_get_status(false);
    $hit_rate = isset($oc['opcache_statistics']['opcache_hit_rate'])
        ? number_format($oc['opcache_statistics']['opcache_hit_rate'], 1) . '%'
        : 'N/A';
    echo "OPcache Hit Rate:       $hit_rate\n";
    echo "OPcache Enabled:        " . ($oc['opcache_enabled'] ? 'Sim' : 'NÃO') . "\n";
}

$session_path = ini_get('session.save_path');
$session_count = is_dir($session_path) ? count(glob($session_path . '/sess_*')) : 0;
echo "Session Count (disco):  $session_count ficheiros em $session_path\n";

echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// 2. CONTAGENS DE TABELAS
// ════════════════════════════════════════════════════════════
section('2. Contagens de Tabelas');
echo '<table><tr><th>Tabela</th><th>Tempo</th><th>Info</th></tr>';

$tables = [
    ['videos',          'SELECT COUNT(*) FROM videos',         'Total vídeos'],
    ['users',           'SELECT COUNT(*) FROM users',          'Total utilizadores'],
    ['video_likes',     'SELECT COUNT(*) FROM video_likes',    'Total likes'],
    ['video_views',     'SELECT COUNT(*) FROM video_views',    'Total views'],
    ['comments',        'SELECT COUNT(*) FROM comments',       'Total comentários'],
    ['user_feed_seen',  'SELECT COUNT(*) FROM user_feed_seen', 'Histórico de feed'],
    ['boost_impressions','SELECT COUNT(*) FROM boost_impressions','Impressões boost'],
];
foreach ($tables as [$label, $sql, $note]) {
    $r = runQuery($pdo, $sql);
    if ($r['ok'] && !empty($r['data'])) {
        $count = current($r['data'][0]);
        $r['rows'] = (int)$count;
        // Rewrite row count to show actual count
        echo "<tr>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;min-width:280px'>$label " . badge($r['ms']) . "</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;font-family:monospace;font-weight:bold;color:" . ($r['ms'] > 200 ? '#ef4444' : ($r['ms'] > 50 ? '#f59e0b' : '#22c55e')) . "'>" . number_format($r['ms'], 1) . " ms</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;color:#94a3b8'>" . number_format((int)$count) . " registos — $note</td>
        </tr>\n";
    } else {
        row($label, $r, $note . ' (tabela pode não existir)');
    }
}
echo '</table>';

// ════════════════════════════════════════════════════════════
// 3. ÍNDICES — VERIFICAR SE EXISTEM
// ════════════════════════════════════════════════════════════
section('3. Índices Críticos');
echo '<table><tr><th>Tabela.Coluna</th><th>Índice?</th><th>Impacto</th></tr>';

$indexes_to_check = [
    ['videos',          'user_id',          'Feed por utilizador'],
    ['videos',          'is_public',        'Filtro público no feed'],
    ['videos',          'created_at',       'Ordenação temporal no feed'],
    ['videos',          'moderation_status','Filtro de moderação'],
    ['video_likes',     'video_id',         'Contagem de likes'],
    ['video_likes',     'user_id',          'Likes do utilizador'],
    ['video_views',     'video_id',         'Contagem de views'],
    ['video_views',     'viewed_at',        'Views recentes (trending)'],
    ['comments',        'video_id',         'Comentários por vídeo'],
    ['user_feed_seen',  'user_id',          'Histórico feed'],
    ['user_feed_seen',  'seen_at',          'Limpeza de feed'],
    ['boost_impressions','user_id',         'Cap diário boost'],
];

foreach ($indexes_to_check as [$table, $col, $impact]) {
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Column_name = ?");
        $stmt->execute([$col]);
        $found = $stmt->fetchAll();
        $has_idx = !empty($found);
        $idx_names = array_column($found, 'Key_name');
        $label = "$table.$col";
        $status = $has_idx
            ? "<span class='good'>✔ " . implode(', ', $idx_names) . "</span>"
            : "<span class='bad'>✘ SEM ÍNDICE</span>";
        echo "<tr>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b'>$label</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b'>$status</td>
            <td style='padding:8px 12px;border-bottom:1px solid #1e293b;color:#94a3b8'>$impact</td>
        </tr>\n";
    } catch (Exception $e) {
        echo "<tr><td style='padding:8px 12px;border-bottom:1px solid #1e293b'>$table.$col</td><td colspan=2 style='color:#ef4444;padding:8px 12px;border-bottom:1px solid #1e293b'>Tabela não encontrada</td></tr>\n";
    }
}
echo '</table>';

// ════════════════════════════════════════════════════════════
// 4. QUERIES DO FEED — AS MAIS CRÍTICAS
// ════════════════════════════════════════════════════════════
section('4. Queries do Feed (get_feed.php)');
echo '<table><tr><th>Query</th><th>Tempo</th><th>Info</th></tr>';

// 4a. Candidate rows (feed principal) — simula fetchCandidateRows
$r = runQuery($pdo, "
    SELECT v.id, v.is_boosted,
        (
            LEAST(25, LOG10(v.likes_count + 1) * 10)
            + LEAST(15, LOG10(v.views_count + 1) * 5)
            + LEAST(15, LOG10(v.comments_count + 1) * 8)
        ) * EXP(-0.0005 * TIMESTAMPDIFF(HOUR, v.created_at, NOW())) AS base_weight
    FROM videos v
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
    ORDER BY base_weight DESC
    LIMIT 300
");
row('fetchCandidateRows (feed, 300 cands)', $r, 'Base do algoritmo de feed — deve ser <50ms');

// 4b. Feed com LEFT JOIN user_feed_seen
$r = runQuery($pdo, "
    SELECT v.id, v.is_boosted
    FROM videos v
    LEFT JOIN user_feed_seen ufs ON ufs.video_id = v.id AND ufs.user_id = 1 AND ufs.seen_at > DATE_SUB(NOW(), INTERVAL 21 DAY)
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
      AND ufs.video_id IS NULL
    ORDER BY v.created_at DESC
    LIMIT 300
", []);
row('Feed com LEFT JOIN user_feed_seen', $r, 'Deduplicação cross-sessão — deve ser <80ms');

// 4c. Subquery weekly_points por vídeo (CRÍTICO — corre para CADA vídeo no feed)
$r = runQuery($pdo, "
    SELECT v.id,
        COALESCE((
            SELECT COUNT(DISTINCT v2.id) * 10 + COALESCE(SUM(v2.likes_count), 0) * 2 + COALESCE(SUM(v2.comments_count), 0) * 3 + COALESCE(SUM(v2.views_count), 0) * 1
            FROM videos v2
            WHERE v2.user_id = v.user_id AND v2.is_public = 1
            AND v2.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
        ), 0) AS weekly_points
    FROM videos v
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
    LIMIT 10
");
row('Subquery weekly_points (por 10 vídeos)', $r, '⚠️ CORRELATED SUBQUERY — escala O(n). Se >100ms aqui, vai degradar o feed inteiro');

// 4d. Fetch final dos 4 vídeos do feed (com a subquery weekly_points)
$r = runQuery($pdo, "
    SELECT v.id, v.title, v.description, v.video_path, v.thumbnail_path,
        v.views_count, v.likes_count, v.comments_count,
        v.created_at, v.user_id as video_user_id, v.is_boosted, v.hashtags,
        u.username, u.full_name, u.profile_picture, u.is_verified,
        COALESCE((
            SELECT COUNT(DISTINCT v2.id) * 10 + COALESCE(SUM(v2.likes_count), 0) * 2 + COALESCE(SUM(v2.comments_count), 0) * 3 + COALESCE(SUM(v2.views_count), 0) * 1
            FROM videos v2
            WHERE v2.user_id = u.id AND v2.is_public = 1
            AND v2.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)
        ), 0) AS weekly_points,
        s.name as school_name, s.short_name as school_short
    FROM videos v
    INNER JOIN users u ON v.user_id = u.id
    LEFT JOIN schools s ON u.school_id = s.id
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
    LIMIT 4
");
row('Fetch final 4 vídeos (com weekly_points)', $r, '⚠️ Esta query é feita a CADA scroll do feed');

echo '</table>';

// ════════════════════════════════════════════════════════════
// 5. QUERIES DE RANKINGS (get_rankings.php)
// ════════════════════════════════════════════════════════════
section('5. Queries de Rankings');
echo '<table><tr><th>Query</th><th>Tempo</th><th>Info</th></tr>';

// 5a. get_my_rank — correlated subqueries
$r = runQuery($pdo, "
    SELECT COUNT(*) + 1 AS global_rank FROM users u2
    WHERE (u2.role != 'admin' OR u2.role IS NULL) AND COALESCE((
        SELECT COUNT(*) * 10 + COALESCE(SUM(v.likes_count), 0) * 2 + COALESCE(SUM(v.comments_count), 0) * 3 + COALESCE(SUM(v.views_count), 0) * 1
        FROM videos v WHERE v.user_id = u2.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    ), 0) > 100
");
row('get_my_rank — global_rank correlated', $r, '⚠️ Correlated subquery sobre TODOS os utilizadores — O(n×m)');

// 5b. get_top_creators — GROUP BY + aggregate
$r = runQuery($pdo, "
    SELECT u.id, u.username,
        COUNT(DISTINCT v.id) AS total_videos,
        COALESCE(SUM(v.likes_count), 0) AS total_likes,
        (COUNT(DISTINCT v.id) * 10 + COALESCE(SUM(v.likes_count), 0) * 2 + COALESCE(SUM(v.comments_count), 0) * 3 + COALESCE(SUM(v.views_count), 0) * 1) AS points
    FROM users u
    LEFT JOIN videos v ON v.user_id = u.id AND v.is_public = 1 AND v.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    WHERE (u.role != 'admin' OR u.role IS NULL)
    GROUP BY u.id
    HAVING total_videos > 0
    ORDER BY points DESC
    LIMIT 30
");
row('get_top_creators (3 meses)', $r, 'Cacheada 5min mas a 1ª vez pode ser lenta');

// 5c. Trending videos — 3 subqueries de 48h
$r = runQuery($pdo, "
    SELECT v.id, v.title,
        COALESCE(rv.recent_views, 0) AS recent_views,
        COALESCE(rl.recent_likes, 0) AS recent_likes,
        ROUND(
            (COALESCE(rv.recent_views, 0) + COALESCE(rl.recent_likes, 0) * 3)
            * (1.0 / (1.0 + TIMESTAMPDIFF(HOUR, v.created_at, NOW()) / 24.0))
            * 100
        ) AS score
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
    WHERE v.is_public = 1
    ORDER BY score DESC, v.created_at DESC
    LIMIT 6
");
row('get_trending_videos (48h)', $r, 'Subconsultas em video_views e video_likes');

// 5d. get_dominant_school — múltiplos correlated
$r = runQuery($pdo, "
    SELECT s.id, s.name,
        (SELECT COUNT(DISTINCT v.id) FROM videos v JOIN users u ON v.user_id = u.id WHERE u.school_id = s.id AND v.is_public = 1 AND v.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)) AS total_videos,
        (SELECT COALESCE(SUM(v.views_count), 0) FROM videos v JOIN users u ON v.user_id = u.id WHERE u.school_id = s.id AND v.is_public = 1 AND v.created_at >= DATE_ADD(DATE(NOW()), INTERVAL -WEEKDAY(NOW()) DAY)) AS total_views
    FROM schools s
    WHERE s.is_active = 1
    LIMIT 20
");
row('get_dominant_school (correlated)', $r, '⚠️ Múltiplos subselects por escola — pode ser muito lento com muitas escolas');

echo '</table>';

// ════════════════════════════════════════════════════════════
// 6. PERFIL (profile.php) — tipicamente a página mais lenta
// ════════════════════════════════════════════════════════════
section('6. Queries de Perfil (profile.php)');
echo '<table><tr><th>Query</th><th>Tempo</th><th>Info</th></tr>';

// Pegar um user_id real para testar
$sample = $pdo->query("SELECT id FROM users WHERE role != 'admin' LIMIT 1")->fetch();
$test_user_id = $sample ? (int)$sample['id'] : 1;

$r = runQuery($pdo, "
    SELECT u.*, s.name as school_name, s.short_name as school_short
    FROM users u
    LEFT JOIN schools s ON u.school_id = s.id
    WHERE u.id = ?
", [$test_user_id]);
row('Dados base do perfil (user + school)', $r, "user_id=$test_user_id");

$r = runQuery($pdo, "SELECT COUNT(*) FROM follows WHERE following_id = ?", [$test_user_id]);
row('Contagem de seguidores', $r, "follows.following_id=$test_user_id");

$r = runQuery($pdo, "SELECT COUNT(*) FROM follows WHERE follower_id = ?", [$test_user_id]);
row('Contagem a seguir', $r, "follows.follower_id=$test_user_id");

$r = runQuery($pdo, "
    SELECT v.*, v.views_count, v.likes_count
    FROM videos v
    WHERE v.user_id = ? AND v.is_public = 1 AND v.moderation_status = 'approved'
    ORDER BY v.created_at DESC
    LIMIT 12
", [$test_user_id]);
row('Vídeos do perfil (12 recentes)', $r);

echo '</table>';

// ════════════════════════════════════════════════════════════
// 7. SLOW QUERIES — últimas do MySQL
// ════════════════════════════════════════════════════════════
section('7. Estado de Queries Lentas no MySQL');
echo '<div class="card"><pre>';

try {
    // Processlist atual
    $pl = $pdo->query("SHOW FULL PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
    $slow = array_filter($pl, fn($p) => ($p['Time'] ?? 0) > 1);
    if (empty($slow)) {
        echo "✔ Nenhuma query a correr há mais de 1s no momento\n\n";
    } else {
        echo "⚠️ QUERIES LENTAS A CORRER AGORA:\n";
        foreach ($slow as $p) {
            echo "  [{$p['Id']}] {$p['Time']}s | {$p['State']} | " . substr($p['Info'] ?? '', 0, 120) . "\n";
        }
    }

    // Global status úteis
    $status = [];
    $rows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Slow_queries','Questions','Uptime','Threads_connected','Threads_running','Table_locks_waited','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests')")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $uptime    = (int)($rows['Uptime'] ?? 0);
    $questions = (int)($rows['Questions'] ?? 0);
    $slow_q    = (int)($rows['Slow_queries'] ?? 0);
    $qps       = $uptime > 0 ? number_format($questions / $uptime, 1) : '?';
    $bp_reads  = (int)($rows['Innodb_buffer_pool_reads'] ?? 0);
    $bp_reqs   = (int)($rows['Innodb_buffer_pool_read_requests'] ?? 0);
    $bp_hit    = $bp_reqs > 0 ? number_format((1 - $bp_reads / $bp_reqs) * 100, 2) . '%' : 'N/A';

    echo "Uptime:                    " . gmdate('H\h i\m', $uptime) . "\n";
    echo "Queries/segundo (médio):   $qps\n";
    echo "Slow queries (desde boot): $slow_q\n";
    echo "Threads connectadas:       {$rows['Threads_connected']}\n";
    echo "Threads a correr agora:    {$rows['Threads_running']}\n";
    echo "Table locks waited:        {$rows['Table_locks_waited']}\n";
    echo "InnoDB Buffer Pool Hit:    $bp_hit" . ($bp_hit !== 'N/A' && (float)$bp_hit < 95 ? " ⚠️ BAIXO (ideal >99%)" : " ✔") . "\n";

} catch (Exception $e) {
    echo "Erro: " . htmlspecialchars($e->getMessage());
}

echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// 8. EXPLAIN — QUERIES MAIS PESADAS
// ════════════════════════════════════════════════════════════
section('8. EXPLAIN das Queries Mais Pesadas');

$queries_to_explain = [
    'Feed candidatos (sem seen)' => [
        "EXPLAIN SELECT v.id, v.is_boosted,
            (LEAST(25, LOG10(v.likes_count + 1) * 10) + LEAST(15, LOG10(v.views_count + 1) * 5)) * EXP(-0.0005 * TIMESTAMPDIFF(HOUR, v.created_at, NOW())) AS base_weight
         FROM videos v
         WHERE v.is_public = 1 AND v.moderation_status = 'approved'
         ORDER BY base_weight DESC LIMIT 300",
        []
    ],
    'Feed candidatos (com LEFT JOIN user_feed_seen)' => [
        "EXPLAIN SELECT v.id FROM videos v
         LEFT JOIN user_feed_seen ufs ON ufs.video_id = v.id AND ufs.user_id = ? AND ufs.seen_at > DATE_SUB(NOW(), INTERVAL 21 DAY)
         WHERE v.is_public = 1 AND v.moderation_status = 'approved' AND ufs.video_id IS NULL
         ORDER BY v.created_at DESC LIMIT 300",
        [1]
    ],
    'Trending videos (48h joins)' => [
        "EXPLAIN SELECT v.id FROM videos v
         JOIN users u ON v.user_id = u.id
         LEFT JOIN (SELECT video_id, COUNT(*) AS cnt FROM video_views WHERE viewed_at >= NOW() - INTERVAL 48 HOUR GROUP BY video_id) rv ON rv.video_id = v.id
         WHERE v.is_public = 1
         LIMIT 10",
        []
    ],
];

foreach ($queries_to_explain as $label => $item) {
    [$sql, $params] = $item;
    echo "<h3 style='color:#94a3b8;margin-top:20px'>$label</h3>";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<table><tr>';
        foreach (array_keys($rows[0] ?? []) as $k) echo "<th>$k</th>";
        echo '</tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $k => $v) {
                $v = $v ?? 'NULL';
                $class = '';
                if ($k === 'type' && in_array($v, ['ALL', 'index'])) $class = 'class="bad"';
                if ($k === 'Extra' && str_contains($v, 'filesort')) $class = 'class="warn"';
                if ($k === 'rows') $class = (int)$v > 1000 ? 'class="warn"' : '';
                echo "<td $class style='padding:6px 10px;border-bottom:1px solid #0f172a'>" . htmlspecialchars((string)$v) . "</td>";
            }
            echo '</tr>';
        }
        echo '</table>';
    } catch (Exception $e) {
        echo "<p class='bad'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// ════════════════════════════════════════════════════════════
// 9. CACHE DE FICHEIROS (ranking_cache)
// ════════════════════════════════════════════════════════════
section('9. Cache de Ficheiros (ranking_cache)');
echo '<div class="card"><pre>';
$cache_dir = __DIR__ . '/cache';
if (is_dir($cache_dir)) {
    $files = glob($cache_dir . '/*.json');
    if (empty($files)) {
        echo "⚠️ Directório de cache existe mas está vazio — a 1ª chamada às rankings será sempre lenta\n";
    } else {
        echo count($files) . " ficheiros de cache encontrados:\n";
        foreach ($files as $f) {
            $age = time() - filemtime($f);
            echo "  " . basename($f) . " — " . number_format(filesize($f)/1024, 1) . " KB — idade: " . ($age < 60 ? $age . 's' : floor($age/60) . 'min') . "\n";
        }
    }
} else {
    echo "⚠️ Directório de cache NÃO existe: $cache_dir\n";
}
echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// 10. SESSÕES
// ════════════════════════════════════════════════════════════
section('10. Sessões no Disco');
echo '<div class="card"><pre>';
$sess_dir = __DIR__ . '/sessions';
if (is_dir($sess_dir)) {
    $sess_files = glob($sess_dir . '/sess_*');
    $count = count($sess_files);
    echo "Total de sessões: $count ficheiros\n";
    if ($count > 1000) {
        echo "⚠️ MUITAS SESSÕES — pode causar lentidão na leitura do disco\n";
        echo "   Recomendado: usar Redis ou sessões em BD para sites com muito tráfego\n";
    } elseif ($count > 500) {
        echo "⚠️ Sessões a crescer — monitorizar\n";
    } else {
        echo "✔ Número de sessões normal\n";
    }
    
    // Tamanho total
    $total_size = 0;
    foreach ($sess_files as $f) { $total_size += filesize($f); }
    echo "Tamanho total: " . number_format($total_size / 1024, 1) . " KB\n";
    
    // Sessão mais antiga
    if (!empty($sess_files)) {
        $oldest = min(array_map('filemtime', $sess_files));
        echo "Sessão mais antiga: " . date('Y-m-d H:i:s', $oldest) . " (" . floor((time()-$oldest)/86400) . " dias)\n";
    }
} else {
    echo "Directório de sessões não encontrado: $sess_dir\n";
}
echo '</pre></div>';

// ════════════════════════════════════════════════════════════
// SUMÁRIO FINAL
// ════════════════════════════════════════════════════════════
$total_time = (microtime(true) - $page_start) * 1000;
section('Sumário');
echo "<div class='card'>";
echo "<p>⏱️ Este diagnóstico correu em " . number_format($total_time, 0) . " ms</p>";
echo "<h3>Problemas mais comuns de lentidão nesta app:</h3>
<ol style='line-height:2'>
    <li><strong class='warn'>Correlated subquery <code>weekly_points</code> no feed</strong> — executa uma subquery por vídeo retornado. Se o feed retornar 4 vídeos, são 4 subqueries extras. Solução: pré-calcular e guardar em coluna <code>users.weekly_points</code>.</li>
    <li><strong class='warn'>get_my_rank — correlated sobre todos os utilizadores</strong> — para calcular a posição global, faz uma subquery para <em>cada</em> utilizador na tabela. Com 1000 users = 1000 subqueries. Solução: usar coluna <code>ranking_points</code> pré-calculada.</li>
    <li><strong class='warn'>Trending videos sem índice em video_views.viewed_at</strong> — table scan em potencialmente milhões de linhas.</li>
    <li><strong class='warn'>dominant_school — múltiplos correlated por escola</strong> — 5 subqueries por escola activa. Cacheado mas a 1ª chamada é cara.</li>
    <li><strong class='bad'>OPcache desativado</strong> — se o OPcache estiver desativado, cada request recompila todos os ficheiros PHP (verifique item 1 acima).</li>
    <li><strong class='bad'>Sessões em disco sem Redis</strong> — com muitos utilizadores, as sessões em ficheiro podem ser um gargalo de I/O.</li>
</ol>
<p style='margin-top:16px'>📋 Vê o EXPLAIN na secção 8 — linhas com <code class='bad'>type=ALL</code> indicam table scans completos.</p>
<p style='color:#ef4444;margin-top:16px'><strong>⚠️ Apaga este ficheiro após usar: <code>rm /var/www/mytube.social/diagnostics.php</code></strong></p>";
echo "</div>";
?>
</body>
</html>
