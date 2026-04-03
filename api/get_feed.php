<?php
/**
 * API de Feed Inteligente - MyTube
 * v3.1 - Cursor-based pagination + política de boost regulada
 *
 * - Busca dinâmica por lotes de 50 IDs (cursor-based)
 * - IDs já vistos excluídos via NOT IN (zero repetições)
 * - Sessão armazena apenas IDs vistos + seed (leve)
 * - Feed continua enquanto houver vídeos na plataforma
 * - Prefetch automático quando lote actual se esgota
 * - Boosted com limite de exposição, cooldown e fadiga
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once '../includes/config.php';
require_once '../includes/hashtag_helper.php';
require_once '../includes/r2_storage.php';

// Limpeza periódica de boost_impressions > 90 dias
// Corre em ~1% dos requests e só 1x por sessão — custo quase zero
if (empty($_SESSION['_boost_cleanup_done']) && mt_rand(1, 100) === 1) {
    $_SESSION['_boost_cleanup_done'] = true;
    try {
        $pdo->exec("DELETE FROM boost_impressions WHERE impression_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
    } catch (Exception $e) {
        // Silenciar — tabela pode não existir
    }
}

function feedBaseWeightSql(): string
{
    return "
        CASE
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 6 THEN 30
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 24 THEN 25
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 72 THEN 15
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 168 THEN 8
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 720 THEN 3
            ELSE 0
        END
        + LEAST(25, LOG10(v.likes_count + 1) * 10)
        + LEAST(15, LOG10(v.views_count + 1) * 5)
        + LEAST(15, LOG10(v.comments_count + 1) * 8)
        + CASE WHEN v.views_count < 20 AND TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 72 THEN 10 ELSE 0 END
        + 10
    ";
}

function feedHashtagsFromStorage(?string $raw_hashtags): array
{
    $parsed = hashtag_extract_from_storage($raw_hashtags);
    if (empty($parsed)) {
        return [];
    }

    $hashtags = [];
    foreach ($parsed as $item) {
        $name = (string)($item['name'] ?? '');
        $slug = (string)($item['slug'] ?? '');
        if ($name === '' || $slug === '') {
            continue;
        }

        $hashtags[] = [
            'name' => $name,
            'slug' => $slug,
        ];
    }

    return $hashtags;
}

function getBoostPolicy(): array
{
    return [
        'top_boost_probability' => 0.15,
        'boost_pick_probability_after_gap' => 0.45,
        'spacing_min_items' => 6,
        'spacing_max_items' => 8,
        'top_cooldown_seconds' => 7200,
        'boost_bonus_multiplier' => 1.25,
        'repeat_penalty_base' => 0.45,
        'recent_hard_seconds' => 900,
        'recent_hard_multiplier' => 0.15,
        'recent_soft_seconds' => 3600,
        'recent_soft_multiplier' => 0.45,
        'history_max_age_seconds' => 172800,
        'daily_cap_per_user' => 8,
        'now' => time(),
    ];
}

function loadBoostState(string $state_key, ?array $policy = null): array
{
    $policy = $policy ?? getBoostPolicy();
    $state = $_SESSION[$state_key] ?? [];

    if (!is_array($state)) {
        $state = [];
    }

    $state['shown_count'] = isset($state['shown_count']) && is_array($state['shown_count']) ? $state['shown_count'] : [];
    $state['last_seen'] = isset($state['last_seen']) && is_array($state['last_seen']) ? $state['last_seen'] : [];
    $state['last_top_seen'] = isset($state['last_top_seen']) && is_array($state['last_top_seen']) ? $state['last_top_seen'] : [];

    $now = (int)$policy['now'];
    $max_age = (int)$policy['history_max_age_seconds'];

    foreach ($state['last_seen'] as $video_id => $timestamp) {
        if (($now - (int)$timestamp) > $max_age) {
            unset($state['last_seen'][$video_id], $state['shown_count'][$video_id], $state['last_top_seen'][$video_id]);
        }
    }

    return $state;
}

function saveBoostState(string $state_key, array $state): void
{
    $_SESSION[$state_key] = $state;
}

function trackBoostImpressions(string $state_key, array $videos, int $offset, ?array $policy = null): void
{
    if (empty($videos)) {
        return;
    }

    $policy = $policy ?? getBoostPolicy();
    $state = loadBoostState($state_key, $policy);
    $now = (int)$policy['now'];

    foreach ($videos as $index => $video) {
        $video_id = (int)($video['id'] ?? 0);
        $is_boosted = (int)($video['is_boosted'] ?? 0) === 1;

        if ($video_id <= 0 || !$is_boosted) {
            continue;
        }

        $state['shown_count'][$video_id] = (int)($state['shown_count'][$video_id] ?? 0) + 1;
        $state['last_seen'][$video_id] = $now;

        if ($offset === 0 && $index === 0) {
            $state['last_top_seen'][$video_id] = $now;
        }
    }

    saveBoostState($state_key, $state);
}

function weightedPickFromPool(array &$pool): ?array
{
    if (empty($pool)) {
        return null;
    }

    $total = 0.0;
    foreach ($pool as $item) {
        $total += max(0.0001, (float)($item['score'] ?? 0.0001));
    }

    if ($total <= 0) {
        $key = array_key_last($pool);
        if ($key === null) {
            return null;
        }
        $item = $pool[$key];
        unset($pool[$key]);
        return $item;
    }

    $roll = (mt_rand() / mt_getrandmax()) * $total;
    $acc = 0.0;

    foreach ($pool as $key => $item) {
        $acc += max(0.0001, (float)($item['score'] ?? 0.0001));
        if ($roll <= $acc) {
            unset($pool[$key]);
            return $item;
        }
    }

    $key = array_key_last($pool);
    if ($key === null) {
        return null;
    }

    $item = $pool[$key];
    unset($pool[$key]);
    return $item;
}

function getDistanceSinceLastBoost($pdo, array $exclude_ids): int
{
    if (empty($exclude_ids)) {
        return 9999;
    }

    $tail_ids = array_slice($exclude_ids, -30);
    if (empty($tail_ids)) {
        return 9999;
    }

    $placeholders = implode(',', array_fill(0, count($tail_ids), '?'));
    $stmt = $pdo->prepare("SELECT id, is_boosted FROM videos WHERE id IN ($placeholders)");
    $stmt->execute($tail_ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $boost_map = [];
    foreach ($rows as $row) {
        $boost_map[(int)$row['id']] = ((int)$row['is_boosted'] === 1);
    }

    $distance = 0;
    for ($i = count($tail_ids) - 1; $i >= 0; $i--) {
        $id = (int)$tail_ids[$i];
        if (!empty($boost_map[$id])) {
            return $distance;
        }
        $distance++;
    }

    return 9999;
}

function fetchCandidateRows($pdo, string $where_sql, array $params, int $candidate_limit): array
{
    $weight_sql = feedBaseWeightSql();
    $sql = "
        SELECT v.id, v.is_boosted, ($weight_sql) AS base_weight
        FROM videos v
        WHERE v.is_public = 1 $where_sql
        ORDER BY base_weight DESC
        LIMIT $candidate_limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildBoostAwareOrder(
    array $candidate_rows,
    int $take,
    array $policy,
    array $boost_state,
    int $distance_since_last_boost,
    array $capped_video_ids = []
): array {
    if ($take <= 0 || empty($candidate_rows)) {
        return [];
    }

    $organic_pool = [];
    $boosted_pool = [];

    foreach ($candidate_rows as $row) {
        $video_id = (int)($row['id'] ?? 0);
        if ($video_id <= 0) {
            continue;
        }

        $base_weight = max(0.01, (float)($row['base_weight'] ?? 0.01));
        $is_boosted = ((int)($row['is_boosted'] ?? 0) === 1);

        if ($is_boosted) {
            // Cap diário: se o user já viu este boosted X vezes hoje, tratar como orgânico
            if (in_array($video_id, $capped_video_ids, true)) {
                $organic_pool[$video_id] = [
                    'id' => $video_id,
                    'score' => $base_weight,
                    'is_boosted' => false,
                    'top_ok' => false,
                ];
                continue;
            }
            $seen_count = (int)($boost_state['shown_count'][$video_id] ?? 0);
            $last_seen = (int)($boost_state['last_seen'][$video_id] ?? 0);
            $last_top_seen = (int)($boost_state['last_top_seen'][$video_id] ?? 0);

            $fatigue = 1.0;
            if ($seen_count > 0) {
                $fatigue *= pow((float)$policy['repeat_penalty_base'], min($seen_count, 6));
            }

            if ($last_seen > 0) {
                $age = (int)$policy['now'] - $last_seen;
                if ($age < (int)$policy['recent_hard_seconds']) {
                    $fatigue *= (float)$policy['recent_hard_multiplier'];
                } elseif ($age < (int)$policy['recent_soft_seconds']) {
                    $fatigue *= (float)$policy['recent_soft_multiplier'];
                }
            }

            $score = $base_weight * (float)$policy['boost_bonus_multiplier'] * max(0.03, $fatigue);

            $boosted_pool[$video_id] = [
                'id' => $video_id,
                'score' => $score,
                'is_boosted' => true,
                'top_ok' => ((int)$policy['now'] - $last_top_seen) >= (int)$policy['top_cooldown_seconds'],
            ];
        } else {
            $organic_pool[$video_id] = [
                'id' => $video_id,
                'score' => $base_weight,
                'is_boosted' => false,
                'top_ok' => false,
            ];
        }
    }

    $ordered_ids = [];
    $distance = max(0, $distance_since_last_boost);
    $spacing_min = (int)$policy['spacing_min_items'];
    $spacing_max = max($spacing_min, (int)$policy['spacing_max_items']);

    $can_try_top_boost = $distance >= $spacing_min;
    if ($can_try_top_boost && !empty($boosted_pool)) {
        $eligible_top_pool = [];
        foreach ($boosted_pool as $id => $item) {
            if (!empty($item['top_ok'])) {
                $eligible_top_pool[$id] = $item;
            }
        }

        $roll = mt_rand() / mt_getrandmax();
        if (!empty($eligible_top_pool) && $roll < (float)$policy['top_boost_probability']) {
            $picked = weightedPickFromPool($eligible_top_pool);
            if ($picked) {
                $ordered_ids[] = (int)$picked['id'];
                unset($boosted_pool[(int)$picked['id']]);
                $distance = 0;
            }
        }
    }

    if (empty($ordered_ids)) {
        $picked = !empty($organic_pool)
            ? weightedPickFromPool($organic_pool)
            : weightedPickFromPool($boosted_pool);

        if ($picked) {
            $ordered_ids[] = (int)$picked['id'];
            $distance = !empty($picked['is_boosted']) ? 0 : ($distance + 1);
        }
    }

    while (count($ordered_ids) < $take && (!empty($organic_pool) || !empty($boosted_pool))) {
        $picked = null;
        $can_place_boost = $distance >= $spacing_min;

        if ($can_place_boost && !empty($boosted_pool)) {
            $roll = mt_rand() / mt_getrandmax();
            $range = max(1, ($spacing_max - $spacing_min + 1));
            $spacing_pressure = min(1.0, max(0.0, ($distance - $spacing_min + 1) / $range));
            $base_prob = (float)$policy['boost_pick_probability_after_gap'];
            $dynamic_prob = max($base_prob, $spacing_pressure);

            $should_pick_boost = empty($organic_pool) || $roll < $dynamic_prob;
            if ($should_pick_boost) {
                $picked = weightedPickFromPool($boosted_pool);
            }
        }

        if ($picked === null && !empty($organic_pool)) {
            $picked = weightedPickFromPool($organic_pool);
        }

        if ($picked === null && !empty($boosted_pool)) {
            $picked = weightedPickFromPool($boosted_pool);
        }

        if ($picked === null) {
            break;
        }

        $ordered_ids[] = (int)$picked['id'];
        $distance = !empty($picked['is_boosted']) ? 0 : ($distance + 1);
    }

    return array_slice($ordered_ids, 0, $take);
}

function getDailyCappedVideoIds($pdo, int $user_id, int $daily_cap): array
{
    if ($user_id <= 0) {
        return [];
    }

    try {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT video_id
            FROM boost_impressions
            WHERE user_id = ? AND impression_date = ? AND impressions >= ?
        ");
        $stmt->execute([$user_id, $today, $daily_cap]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        // Tabela pode não existir ainda — retornar vazio
        return [];
    }
}

function fetchGuestBatch($pdo, int $seed, array $exclude_ids, int $batch_size): array
{
    $exclude_clause = '';
    $params = [];

    if (!empty($exclude_ids)) {
        $ph = implode(',', array_fill(0, count($exclude_ids), '?'));
        $exclude_clause = "AND v.id NOT IN ($ph)";
        $params = $exclude_ids;
    }

    $candidate_limit = max($batch_size * 6, 120);
    $rows = fetchCandidateRows($pdo, $exclude_clause, $params, $candidate_limit);
    if (empty($rows)) {
        return [];
    }

    $policy = getBoostPolicy();
    $state = loadBoostState('boost_feed_state_guest', $policy);
    $distance = getDistanceSinceLastBoost($pdo, $exclude_ids);

    return buildBoostAwareOrder($rows, $batch_size, $policy, $state, $distance, []);
}

function fetchBatch(
    $pdo,
    int $user_id,
    int $seed,
    array $exclude_ids,
    int $batch_size,
    int $profile_user_id = 0,
    int $start_video_id = 0
): array {
    if ($profile_user_id > 0) {
        $exclude_clause = '';
        $params = [];

        if ($start_video_id > 0 && empty($exclude_ids)) {
            $limit_rest = $batch_size - 1;
            $params_start = [$start_video_id, $profile_user_id];
            $stmt = $pdo->prepare("\n                (SELECT v.id, 1 AS sort_order\n                 FROM videos v\n                 WHERE v.id = ? AND v.is_public = 1)\n                UNION ALL\n                (SELECT v.id, 2 AS sort_order\n                 FROM videos v\n                 WHERE v.is_public = 1 AND v.user_id = ? AND v.id != " . (int)$start_video_id . "\n                 ORDER BY v.created_at DESC\n                 LIMIT $limit_rest)\n            ");
            $stmt->execute($params_start);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $params[] = $profile_user_id;
        if (!empty($exclude_ids)) {
            $ph = implode(',', array_fill(0, count($exclude_ids), '?'));
            $exclude_clause = "AND v.id NOT IN ($ph)";
            $params = array_merge($params, $exclude_ids);
        }

        $stmt = $pdo->prepare("\n            SELECT v.id\n            FROM videos v\n            WHERE v.is_public = 1 AND v.user_id = ? $exclude_clause\n            ORDER BY v.created_at DESC\n            LIMIT $batch_size\n        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $policy = getBoostPolicy();
    $boost_state = loadBoostState('boost_feed_state_user_' . $user_id, $policy);
    $distance_since_last_boost = getDistanceSinceLastBoost($pdo, $exclude_ids);
    $capped_ids = getDailyCappedVideoIds($pdo, $user_id, (int)$policy['daily_cap_per_user']);

    if ($start_video_id > 0 && empty($exclude_ids)) {
        $ordered_ids = [];

        $stmt = $pdo->prepare("SELECT id, is_boosted FROM videos WHERE id = ? AND is_public = 1 LIMIT 1");
        $stmt->execute([$start_video_id]);
        $start_video = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($start_video) {
            $ordered_ids[] = (int)$start_video['id'];

            if ((int)$start_video['is_boosted'] === 1) {
                $distance_since_last_boost = 0;
            } else {
                $distance_since_last_boost++;
            }
        }

        $limit_rest = max(0, $batch_size - count($ordered_ids));
        if ($limit_rest <= 0) {
            return $ordered_ids;
        }

        $candidate_limit = max($limit_rest * 6, 120);
        $rows = fetchCandidateRows(
            $pdo,
            "AND v.user_id != ? AND v.id != ?",
            [$user_id, $start_video_id],
            $candidate_limit
        );

        $rest_ids = buildBoostAwareOrder(
            $rows,
            $limit_rest,
            $policy,
            $boost_state,
            $distance_since_last_boost,
            $capped_ids
        );

        return array_merge($ordered_ids, $rest_ids);
    }

    $exclude_clause = '';
    $params = [$user_id];
    if (!empty($exclude_ids)) {
        $ph = implode(',', array_fill(0, count($exclude_ids), '?'));
        $exclude_clause = "AND v.id NOT IN ($ph)";
        $params = array_merge($params, $exclude_ids);
    }

    $candidate_limit = max($batch_size * 6, 120);
    $rows = fetchCandidateRows(
        $pdo,
        "AND v.user_id != ? $exclude_clause",
        $params,
        $candidate_limit
    );

    return buildBoostAwareOrder(
        $rows,
        $batch_size,
        $policy,
        $boost_state,
        $distance_since_last_boost,
        $capped_ids
    );
}

// Detectar modo convidado
$guest_mode = !isLoggedIn();

// Se não está logado e não é modo guest, bloquear
if ($guest_mode && !isset($_GET['guest'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// =========================================
// MODO GUEST: feed com algoritmo de popularidade
// =========================================
if ($guest_mode) {
    $offset          = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit           = 4;
    $feed_session_id = $_GET['feed_session'] ?? ('guest_' . time());
    $guest_cache_key = 'guest_feed_cache';
    $batch_size      = 50;

    $seed = crc32($feed_session_id);

    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($offset === 0
            && (!isset($_SESSION[$guest_cache_key])
                || ($_SESSION[$guest_cache_key]['session_id'] ?? '') !== $feed_session_id)
        ) {
            unset($_SESSION[$guest_cache_key]);
        }

        $cache       = $_SESSION[$guest_cache_key] ?? null;
        $cache_valid = $cache && ($cache['session_id'] ?? '') === $feed_session_id;

        if (!$cache_valid) {
            $initial_ids = fetchGuestBatch($pdo, $seed, [], $batch_size);

            if (empty($initial_ids)) {
                echo json_encode([
                    'success' => true,
                    'videos' => [],
                    'has_more' => false,
                    'next_offset' => 0,
                    'feed_session' => $feed_session_id,
                ]);
                exit;
            }

            $_SESSION[$guest_cache_key] = [
                'session_id' => $feed_session_id,
                'video_ids' => $initial_ids,
                'seed' => $seed,
                'exhausted' => count($initial_ids) < $batch_size,
            ];
        }

        $cached_ids = $_SESSION[$guest_cache_key]['video_ids'];
        $exhausted  = $_SESSION[$guest_cache_key]['exhausted'] ?? false;

        if (!$exhausted && ($offset + $limit) >= count($cached_ids)) {
            $new_ids = fetchGuestBatch($pdo, $seed, $cached_ids, $batch_size);

            if (!empty($new_ids)) {
                $cached_ids = array_merge($cached_ids, $new_ids);
                $_SESSION[$guest_cache_key]['video_ids'] = $cached_ids;
            }
            if (count($new_ids) < $batch_size) {
                $_SESSION[$guest_cache_key]['exhausted'] = true;
                $exhausted = true;
            }
        }

        $slice_ids = array_slice($cached_ids, $offset, $limit);

        if (empty($slice_ids)) {
            echo json_encode([
                'success' => true,
                'videos' => [],
                'has_more' => false,
                'next_offset' => $offset,
                'feed_session' => $feed_session_id,
            ]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($slice_ids), '?'));
        $stmt = $pdo->prepare("\n            SELECT\n                v.id, v.title, v.description, v.video_path, v.thumbnail_path,\n                v.views_count, v.likes_count, v.comments_count,\n                v.created_at, v.user_id as video_user_id, v.is_boosted, v.hashtags,\n                u.username, u.full_name, u.profile_picture, u.is_verified\n            FROM videos v\n            INNER JOIN users u ON v.user_id = u.id\n            WHERE v.id IN ($placeholders) AND v.is_public = 1\n        ");
        $stmt->execute($slice_ids);
        $videos_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexed = [];
        foreach ($videos_data as $video_row) {
            $indexed[(int)$video_row['id']] = $video_row;
        }

        $videos = [];
        foreach ($slice_ids as $id) {
            if (isset($indexed[(int)$id])) {
                $videos[] = $indexed[(int)$id];
            }
        }

        $policy = getBoostPolicy();
        trackBoostImpressions('boost_feed_state_guest', $videos, $offset, $policy);

        $has_more = ($offset + $limit < count($cached_ids)) || !$exhausted;

        $processed_videos = [];
        foreach ($videos as $video) {
            $time_diff = time() - strtotime($video['created_at']);
            if ($time_diff < 60) {
                $time_ago = 'agora';
            } elseif ($time_diff < 3600) {
                $time_ago = floor($time_diff / 60) . 'min';
            } elseif ($time_diff < 86400) {
                $time_ago = floor($time_diff / 3600) . 'h';
            } else {
                $time_ago = floor($time_diff / 86400) . 'd';
            }

            $processed_videos[] = [
                'id' => (int)$video['id'],
                'title' => htmlspecialchars($video['title']),
                'description' => htmlspecialchars($video['description'] ?? ''),
                'video_path' => $video['video_path'],
                'video_url' => resolve_video_url($video['video_path']),
                'thumbnail_path' => $video['thumbnail_path'],
                'views_count' => (int)$video['views_count'],
                'likes_count' => (int)($video['likes_count'] ?? 0),
                'comments_count' => (int)($video['comments_count'] ?? 0),
                'created_at' => $video['created_at'],
                'time_ago' => $time_ago,
                'is_boosted' => (bool)($video['is_boosted'] ?? false),
                'hashtags' => feedHashtagsFromStorage($video['hashtags'] ?? ''),
                'user_liked' => false,
                'user_following' => false,
                'author_follows_you' => false,
                'user' => [
                    'id' => (int)$video['video_user_id'],
                    'username' => htmlspecialchars($video['username']),
                    'full_name' => htmlspecialchars($video['full_name'] ?? $video['username']),
                    'profile_picture' => $video['profile_picture'] ?? 'default.webp',
                    'is_verified' => (bool)$video['is_verified'],
                ],
            ];
        }

        echo json_encode([
            'success' => true,
            'videos' => $processed_videos,
            'has_more' => $has_more,
            'next_offset' => $offset + count($processed_videos),
            'feed_session' => $feed_session_id,
            'total_available' => count($cached_ids),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    }
    exit;
}

// =========================================
// MODO LOGGED-IN (código original)
// =========================================
$user_id = $_SESSION['user_id'];

// === ENDPOINT RÁPIDO: validar IDs de vídeos (para restauração do sessionStorage) ===
if (isset($_GET['validate_ids'])) {
    $raw_ids = array_filter(array_map('intval', explode(',', $_GET['validate_ids'])));
    if (empty($raw_ids)) {
        echo json_encode(['valid_ids' => []]);
        exit;
    }
    $ph = implode(',', array_fill(0, count($raw_ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM videos WHERE id IN ($ph) AND is_public = 1");
    $stmt->execute($raw_ids);
    $valid_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['valid_ids' => array_map('intval', $valid_ids)]);
    exit;
}

$offset          = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit           = 4;
$feed_session_id = $_GET['feed_session'] ?? null;
$profile_user_id = isset($_GET['profile_user']) ? (int)$_GET['profile_user'] : 0;
$start_video_id  = isset($_GET['start_video']) ? (int)$_GET['start_video'] : 0;

$feed_cache_key  = 'feed_cache_' . $user_id;
$batch_size      = 50;
$max_session_ids = 2000;

if ($offset === 0 && $feed_session_id) {
    if (!isset($_SESSION[$feed_cache_key])
        || ($_SESSION[$feed_cache_key]['session_id'] ?? '') !== $feed_session_id
    ) {
        unset($_SESSION[$feed_cache_key]);
    }
}

try {
    $cache       = $_SESSION[$feed_cache_key] ?? null;
    $cache_valid = $cache && ($cache['session_id'] ?? '') === $feed_session_id;

    if (!$cache_valid) {
        $seed = $feed_session_id ? crc32($feed_session_id) : crc32($user_id . time());

        $initial_ids = fetchBatch(
            $pdo,
            $user_id,
            $seed,
            [],
            $batch_size,
            $profile_user_id,
            $start_video_id
        );

        if (empty($initial_ids)) {
            echo json_encode([
                'success' => true,
                'videos' => [],
                'has_more' => false,
                'next_offset' => 0,
                'feed_session' => $feed_session_id,
            ]);
            exit;
        }

        $_SESSION[$feed_cache_key] = [
            'session_id' => $feed_session_id,
            'video_ids' => $initial_ids,
            'seed' => $seed,
            'profile' => $profile_user_id,
            'exhausted' => count($initial_ids) < $batch_size,
            'created_at' => time(),
        ];
    }

    $cached_ids = $_SESSION[$feed_cache_key]['video_ids'];
    $exhausted  = $_SESSION[$feed_cache_key]['exhausted'] ?? false;

    if (!$exhausted
        && ($offset + $limit) >= count($cached_ids)
        && count($cached_ids) < $max_session_ids
    ) {
        $new_ids = fetchBatch(
            $pdo,
            $user_id,
            $_SESSION[$feed_cache_key]['seed'],
            $cached_ids,
            $batch_size,
            $_SESSION[$feed_cache_key]['profile'] ?? 0,
            0
        );

        if (!empty($new_ids)) {
            $cached_ids = array_merge($cached_ids, $new_ids);
            $_SESSION[$feed_cache_key]['video_ids'] = $cached_ids;
        }

        if (count($new_ids) < $batch_size || count($cached_ids) >= $max_session_ids) {
            $exhausted = true;
            $_SESSION[$feed_cache_key]['exhausted'] = true;
        }
    }

    $slice_ids = array_slice($cached_ids, $offset, $limit);

    if (empty($slice_ids)) {
        echo json_encode([
            'success' => true,
            'videos' => [],
            'has_more' => false,
            'next_offset' => $offset,
            'feed_session' => $feed_session_id,
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($slice_ids), '?'));
    $stmt = $pdo->prepare("\n        SELECT\n            v.id, v.title, v.description, v.video_path, v.thumbnail_path,\n            v.views_count, v.likes_count, v.comments_count,\n            v.created_at, v.user_id as video_user_id, v.is_boosted, v.hashtags,\n            u.username, u.full_name, u.profile_picture, u.is_verified\n        FROM videos v\n        INNER JOIN users u ON v.user_id = u.id\n        WHERE v.id IN ($placeholders) AND v.is_public = 1\n    ");
    $stmt->execute($slice_ids);
    $videos_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $indexed = [];
    foreach ($videos_data as $video_row) {
        $indexed[(int)$video_row['id']] = $video_row;
    }

    $videos = [];
    $stale_ids = [];
    foreach ($slice_ids as $id) {
        if (isset($indexed[(int)$id])) {
            $videos[] = $indexed[(int)$id];
        } else {
            $stale_ids[] = (int)$id;
        }
    }

    if (!empty($stale_ids) && isset($_SESSION[$feed_cache_key])) {
        $_SESSION[$feed_cache_key]['video_ids'] = array_values(
            array_diff($_SESSION[$feed_cache_key]['video_ids'], $stale_ids)
        );
    }

    if ($profile_user_id <= 0) {
        $policy = getBoostPolicy();
        trackBoostImpressions('boost_feed_state_user_' . $user_id, $videos, $offset, $policy);
        
        // Tracking de impressões no banco (para cap diário + métricas CTR)
        $boosted_in_slice = array_filter($videos, fn($v) => (int)($v['is_boosted'] ?? 0) === 1);
        if (!empty($boosted_in_slice)) {
            $today = date('Y-m-d');
            try {
                $imp_stmt = $pdo->prepare("
                    INSERT INTO boost_impressions (video_id, user_id, impression_date, impressions)
                    VALUES (?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE impressions = impressions + 1, updated_at = NOW()
                ");
                foreach ($boosted_in_slice as $bv) {
                    $imp_stmt->execute([(int)$bv['id'], $user_id, $today]);
                }
            } catch (Exception $e) {
                // Tabela pode não existir ainda — silenciar
            }
        }
    }

    $user_likes = [];
    $user_follows = [];
    $author_follows_user = [];

    if (!empty($videos)) {
        $vid_arr = array_column($videos, 'id');
        $ph = implode(',', array_fill(0, count($vid_arr), '?'));

        $stmt = $pdo->prepare("SELECT video_id FROM video_likes WHERE user_id = ? AND video_id IN ($ph)");
        $stmt->execute(array_merge([$user_id], $vid_arr));
        $user_likes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $author_ids = array_unique(array_column($videos, 'video_user_id'));
        if (!empty($author_ids)) {
            $aph = implode(',', array_fill(0, count($author_ids), '?'));
            $stmt = $pdo->prepare("SELECT following_id FROM follows WHERE follower_id = ? AND following_id IN ($aph)");
            $stmt->execute(array_merge([$user_id], $author_ids));
            $user_follows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $pdo->prepare("SELECT follower_id FROM follows WHERE following_id = ? AND follower_id IN ($aph)");
            $stmt->execute(array_merge([$user_id], $author_ids));
            $author_follows_user = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $processed_videos = [];
    foreach ($videos as $video) {
        $time_diff = time() - strtotime($video['created_at']);
        if ($time_diff < 60) {
            $time_ago = 'agora';
        } elseif ($time_diff < 3600) {
            $time_ago = floor($time_diff / 60) . 'min';
        } elseif ($time_diff < 86400) {
            $time_ago = floor($time_diff / 3600) . 'h';
        } else {
            $time_ago = floor($time_diff / 86400) . 'd';
        }

        $processed_videos[] = [
            'id' => (int)$video['id'],
            'title' => htmlspecialchars($video['title']),
            'description' => htmlspecialchars($video['description'] ?? ''),
            'video_path' => $video['video_path'],
            'video_url' => resolve_video_url($video['video_path']),
            'thumbnail_path' => $video['thumbnail_path'],
            'views_count' => (int)$video['views_count'],
            'likes_count' => (int)($video['likes_count'] ?? 0),
            'comments_count' => (int)($video['comments_count'] ?? 0),
            'created_at' => $video['created_at'],
            'time_ago' => $time_ago,
            'is_boosted' => (bool)($video['is_boosted'] ?? false),
            'hashtags' => feedHashtagsFromStorage($video['hashtags'] ?? ''),
            'user_liked' => in_array($video['id'], $user_likes),
            'user_following' => in_array($video['video_user_id'], $user_follows),
            'author_follows_you' => in_array($video['video_user_id'], $author_follows_user),
            'user' => [
                'id' => (int)$video['video_user_id'],
                'username' => htmlspecialchars($video['username']),
                'full_name' => htmlspecialchars($video['full_name'] ?? $video['username']),
                'profile_picture' => $video['profile_picture'] ?? 'default.webp',
                'is_verified' => (bool)$video['is_verified'],
            ],
        ];
    }

    $total_cached = count($cached_ids);
    $has_more = ($offset + $limit < $total_cached) || !$exhausted;

    echo json_encode([
        'success' => true,
        'videos' => $processed_videos,
        'has_more' => $has_more,
        'next_offset' => $offset + count($processed_videos),
        'feed_session' => $feed_session_id,
        'total_available' => $total_cached,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor',
        'message' => $e->getMessage(),
    ]);
}
?>