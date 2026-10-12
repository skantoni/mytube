<?php
/**
 * includes/feed_helper.php — Lógica compartilhada do Feed Inteligente & Server-Side Rendering (SSR)
 * MyTube v3.2
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hashtag_helper.php';
require_once __DIR__ . '/r2_storage.php';

function feedBaseWeightSql(): string
{
    return "
        (
            LEAST(25, LOG10(v.likes_count + 1) * 10)
            + LEAST(15, LOG10(v.views_count + 1) * 5)
            + LEAST(15, LOG10(v.comments_count + 1) * 8)
        ) * EXP(-0.0005 * TIMESTAMPDIFF(HOUR, v.created_at, NOW()))
        + CASE
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 24  THEN 20
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 72  THEN 12
            WHEN TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 168 THEN 5
            ELSE 0
          END
        + CASE WHEN v.views_count < 15 AND TIMESTAMPDIFF(HOUR, v.created_at, NOW()) <= 48 THEN 8 ELSE 0 END
        + 5
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

function loadSeenVideoIds($pdo, int $user_id, int $days = 21): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT video_id FROM user_feed_seen
             WHERE user_id = ? AND seen_at > DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$user_id, $days]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

function saveSeenVideoIds($pdo, int $user_id, array $video_ids): void
{
    if ($user_id <= 0 || empty($video_ids)) {
        return;
    }
    try {
        $ph     = implode(',', array_fill(0, count($video_ids), '(?, ?)'));
        $params = [];
        foreach ($video_ids as $vid) {
            $params[] = $user_id;
            $params[] = (int)$vid;
        }
        $pdo->prepare(
            "INSERT INTO user_feed_seen (user_id, video_id) VALUES $ph
             ON DUPLICATE KEY UPDATE seen_at = NOW()"
        )->execute($params);
    } catch (Exception $e) {
    }
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

function fetchCandidateRows($pdo, string $where_sql, array $params, int $candidate_limit, int $seen_user_id = 0, int $seen_days = 21): array
{
    $weight_sql = feedBaseWeightSql();

    $seen_join  = '';
    $seen_where = '';
    $all_params = $params;

    if ($seen_user_id > 0) {
        $seen_join  = "LEFT JOIN user_feed_seen ufs
                          ON ufs.video_id = v.id
                         AND ufs.user_id = ?
                         AND ufs.seen_at > DATE_SUB(NOW(), INTERVAL ? DAY)";
        $seen_where = "AND ufs.video_id IS NULL";
        $all_params = array_merge([$seen_user_id, $seen_days], $params);
    }

    $sql = "
        SELECT v.id, v.is_boosted, ($weight_sql) AS base_weight
        FROM videos v
        $seen_join
        WHERE v.is_public = 1 AND v.is_hidden = 0 AND v.moderation_status = 'approved'
        $seen_where
        $where_sql
        ORDER BY base_weight DESC
        LIMIT $candidate_limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($all_params);
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
        $stmt = $pdo->prepare("
            SELECT v.id
            FROM videos v
            WHERE v.is_public = 1 AND v.is_hidden = 0 AND v.moderation_status = 'approved' AND v.user_id = ?
            ORDER BY v.created_at DESC, v.id DESC
        ");
        $stmt->execute([$profile_user_id]);
        $all_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $all_ids = array_map('intval', $all_ids);

        if ($start_video_id > 0 && empty($exclude_ids)) {
            $start_index = array_search($start_video_id, $all_ids);
            if ($start_index !== false) {
                $all_ids = array_slice($all_ids, 0, $start_index + $batch_size);
            } else {
                $all_ids = array_slice($all_ids, 0, $batch_size);
            }
        } else {
            if (!empty($exclude_ids)) {
                $all_ids = array_values(array_diff($all_ids, $exclude_ids));
            }
            $all_ids = array_slice($all_ids, 0, $batch_size);
        }

        return $all_ids;
    }

    $policy = getBoostPolicy();
    $boost_state = loadBoostState('boost_feed_state_user_' . $user_id, $policy);
    $distance_since_last_boost = getDistanceSinceLastBoost($pdo, $exclude_ids);
    $capped_ids = getDailyCappedVideoIds($pdo, $user_id, (int)$policy['daily_cap_per_user']);

    if ($start_video_id > 0 && empty($exclude_ids)) {
        $ordered_ids = [];

        $stmt = $pdo->prepare("SELECT id, is_boosted FROM videos WHERE id = ? AND is_public = 1 AND is_hidden = 0 AND moderation_status = 'approved' LIMIT 1");
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
            $candidate_limit,
            $user_id
        );
        if (count($rows) < $batch_size) {
            $rows = fetchCandidateRows($pdo, "AND v.user_id != ? AND v.id != ?", [$user_id, $start_video_id], $candidate_limit);
        }

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
        $candidate_limit,
        $user_id
    );
    if (count($rows) < $batch_size) {
        $rows = fetchCandidateRows($pdo, "AND v.user_id != ? $exclude_clause", $params, $candidate_limit);
    }

    return buildBoostAwareOrder(
        $rows,
        $batch_size,
        $policy,
        $boost_state,
        $distance_since_last_boost,
        $capped_ids
    );
}

/**
 * Busca dados completos de um lote de feed (Guest ou Logged-In), gerenciando o cache de sessão.
 */
function fetchFeedData(
    $pdo,
    int $user_id,
    ?string $feed_session_id,
    int $offset,
    int $limit,
    bool $guest_mode,
    int $profile_user_id = 0,
    int $start_video_id = 0
): array {
    $batch_size = 50;
    $max_session_ids = 2000;

    if ($guest_mode) {
        $feed_session_id = $feed_session_id ?: ('guest_' . time() . '_' . bin2hex(random_bytes(4)));
        $cache_key = 'guest_feed_cache';
        $seed = crc32($feed_session_id);

        if ($offset === 0
            && (!isset($_SESSION[$cache_key])
                || ($_SESSION[$cache_key]['session_id'] ?? '') !== $feed_session_id)
        ) {
            unset($_SESSION[$cache_key]);
        }

        $cache = $_SESSION[$cache_key] ?? null;
        $cache_valid = $cache && ($cache['session_id'] ?? '') === $feed_session_id;

        if (!$cache_valid) {
            if ($profile_user_id > 0) {
                $initial_ids = fetchBatch($pdo, 0, $seed, [], $batch_size, $profile_user_id, $start_video_id);
            } else {
                $initial_ids = fetchGuestBatch($pdo, $seed, [], $batch_size);
                if ($start_video_id > 0) {
                    $initial_ids = array_values(array_diff($initial_ids, [$start_video_id]));
                    array_unshift($initial_ids, $start_video_id);
                }
            }

            if (empty($initial_ids)) {
                return [
                    'success' => true,
                    'videos' => [],
                    'has_more' => false,
                    'next_offset' => 0,
                    'feed_session' => $feed_session_id,
                    'total_available' => 0,
                ];
            }

            $_SESSION[$cache_key] = [
                'session_id' => $feed_session_id,
                'video_ids' => $initial_ids,
                'seed' => $seed,
                'profile' => $profile_user_id,
                'start_video' => $start_video_id,
                'exhausted' => count($initial_ids) < $batch_size,
            ];
        }

        $cached_ids = $_SESSION[$cache_key]['video_ids'];
        $exhausted  = $_SESSION[$cache_key]['exhausted'] ?? false;

        if (!$exhausted && ($offset + $limit) >= count($cached_ids)) {
            $p_uid = $_SESSION[$cache_key]['profile'] ?? 0;
            if ($p_uid > 0) {
                $new_ids = fetchBatch($pdo, 0, $seed, $cached_ids, $batch_size, $p_uid, $_SESSION[$cache_key]['start_video'] ?? 0);
            } else {
                $new_ids = fetchGuestBatch($pdo, $seed, $cached_ids, $batch_size);
            }

            if (!empty($new_ids)) {
                $cached_ids = array_merge($cached_ids, $new_ids);
                $_SESSION[$cache_key]['video_ids'] = $cached_ids;
            }
            if (count($new_ids) < $batch_size) {
                $_SESSION[$cache_key]['exhausted'] = true;
                $exhausted = true;
            }
        }

        if ($offset === 0) {
            $s_vid = $_SESSION[$cache_key]['start_video'] ?? 0;
            if ($s_vid > 0) {
                $s_idx = array_search($s_vid, $cached_ids);
                if ($s_idx !== false) {
                    $limit = max($limit, $s_idx + 4);
                }
            }
        }

        $slice_ids = array_slice($cached_ids, $offset, $limit);
        if (empty($slice_ids)) {
            return [
                'success' => true,
                'videos' => [],
                'has_more' => false,
                'next_offset' => $offset,
                'feed_session' => $feed_session_id,
                'total_available' => count($cached_ids),
            ];
        }

        $placeholders = implode(',', array_fill(0, count($slice_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT
                v.id, v.title, v.description, v.video_path, v.thumbnail_path,
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
            WHERE v.id IN ($placeholders) AND v.is_public = 1 AND v.is_hidden = 0 AND v.moderation_status = 'approved'
        ");
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

        $processed_videos = formatFeedVideos($videos, [], [], []);

        return [
            'success' => true,
            'videos' => $processed_videos,
            'has_more' => $has_more,
            'next_offset' => $offset + count($processed_videos),
            'feed_session' => $feed_session_id,
            'total_available' => count($cached_ids),
        ];

    } else {
        // Logged-in mode
        $feed_session_id = $feed_session_id ?: ('feed_' . time() . '_' . bin2hex(random_bytes(4)));
        $cache_key = 'feed_cache_' . $user_id;

        if ($offset === 0 && $feed_session_id) {
            if (!isset($_SESSION[$cache_key])
                || ($_SESSION[$cache_key]['session_id'] ?? '') !== $feed_session_id
            ) {
                unset($_SESSION[$cache_key]);
            }
        }

        $cache = $_SESSION[$cache_key] ?? null;
        $cache_valid = $cache && ($cache['session_id'] ?? '') === $feed_session_id;

        if (!$cache_valid) {
            $seed = crc32($feed_session_id);
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
                return [
                    'success' => true,
                    'videos' => [],
                    'has_more' => false,
                    'next_offset' => 0,
                    'feed_session' => $feed_session_id,
                    'total_available' => 0,
                ];
            }

            $_SESSION[$cache_key] = [
                'session_id' => $feed_session_id,
                'video_ids' => $initial_ids,
                'seed' => $seed,
                'profile' => $profile_user_id,
                'start_video' => $start_video_id,
                'exhausted' => count($initial_ids) < $batch_size,
                'created_at' => time(),
            ];
        }

        $cached_ids = $_SESSION[$cache_key]['video_ids'];
        $exhausted  = $_SESSION[$cache_key]['exhausted'] ?? false;

        if (!$exhausted
            && ($offset + $limit) >= count($cached_ids)
            && count($cached_ids) < $max_session_ids
        ) {
            $new_ids = fetchBatch(
                $pdo,
                $user_id,
                $_SESSION[$cache_key]['seed'],
                $cached_ids,
                $batch_size,
                $_SESSION[$cache_key]['profile'] ?? 0,
                $_SESSION[$cache_key]['start_video'] ?? 0
            );

            if (!empty($new_ids)) {
                $cached_ids = array_merge($cached_ids, $new_ids);
                $_SESSION[$cache_key]['video_ids'] = $cached_ids;
            }

            if (count($new_ids) < $batch_size || count($cached_ids) >= $max_session_ids) {
                $exhausted = true;
                $_SESSION[$cache_key]['exhausted'] = true;
            }
        }

        if ($offset === 0) {
            $start_vid = $_SESSION[$cache_key]['start_video'] ?? 0;
            if ($start_vid > 0) {
                $start_index_in_cache = array_search($start_vid, $cached_ids);
                if ($start_index_in_cache !== false) {
                    $limit = max($limit, $start_index_in_cache + 4);
                }
            }
        }

        $slice_ids = array_slice($cached_ids, $offset, $limit);
        if (empty($slice_ids)) {
            return [
                'success' => true,
                'videos' => [],
                'has_more' => false,
                'next_offset' => $offset,
                'feed_session' => $feed_session_id,
                'total_available' => count($cached_ids),
            ];
        }

        $placeholders = implode(',', array_fill(0, count($slice_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT
                v.id, v.title, v.description, v.video_path, v.thumbnail_path,
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
            WHERE v.id IN ($placeholders) AND v.is_public = 1 AND v.moderation_status = 'approved'
        ");
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

        if (!empty($stale_ids) && isset($_SESSION[$cache_key])) {
            $_SESSION[$cache_key]['video_ids'] = array_values(
                array_diff($_SESSION[$cache_key]['video_ids'], $stale_ids)
            );
        }

        if ($profile_user_id <= 0) {
            $policy = getBoostPolicy();
            trackBoostImpressions('boost_feed_state_user_' . $user_id, $videos, $offset, $policy);
            
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
                }
            }

            if (!empty($videos)) {
                saveSeenVideoIds($pdo, $user_id, array_column($videos, 'id'));
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

        $processed_videos = formatFeedVideos($videos, $user_likes, $user_follows, $author_follows_user);
        $total_cached = count($cached_ids);
        $has_more = ($offset + $limit < $total_cached) || !$exhausted;

        return [
            'success' => true,
            'videos' => $processed_videos,
            'has_more' => $has_more,
            'next_offset' => $offset + count($processed_videos),
            'feed_session' => $feed_session_id,
            'total_available' => $total_cached,
        ];
    }
}

function formatFeedVideos(array $videos, array $user_likes, array $user_follows, array $author_follows_user): array
{
    $processed = [];
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

        $processed[] = [
            'id' => (int)$video['id'],
            'title' => $video['title'] ?? '',
            'description' => $video['description'] ?? '',
            'video_path' => $video['video_path'],
            'video_url' => resolve_video_url($video['video_path']),
            'thumbnail_path' => $video['thumbnail_path'],
            'views_count' => (int)$video['views_count'],
            'likes_count' => (int)($video['likes_count'] ?? 0),
            'comments_count' => (int)($video['comments_count'] ?? 0),
            'shares_count' => (int)($video['shares_count'] ?? 0),
            'created_at' => $video['created_at'],
            'time_ago' => $time_ago,
            'is_boosted' => (bool)($video['is_boosted'] ?? false),
            'hashtags' => feedHashtagsFromStorage($video['hashtags'] ?? ''),
            'user_liked' => in_array($video['id'], $user_likes),
            'user_following' => in_array($video['video_user_id'], $user_follows),
            'author_follows_you' => in_array($video['video_user_id'], $author_follows_user),
            'school_name' => $video['school_name'] ?? null,
            'school_short' => $video['school_short'] ?? null,
            'ranking_points' => (int)($video['weekly_points'] ?? 0),
            'user' => [
                'id' => (int)$video['video_user_id'],
                'username' => $video['username'] ?? '',
                'full_name' => $video['full_name'] ?? $video['username'] ?? '',
                'profile_picture' => $video['profile_picture'] ?? 'default.webp',
                'profile_picture_url' => avatar_url($video['profile_picture'] ?? null),
                'is_verified' => (bool)$video['is_verified'],
                'school_name' => $video['school_name'] ?? null,
                'school_short' => $video['school_short'] ?? null,
                'ranking_points' => (int)($video['weekly_points'] ?? 0),
            ],
        ];
    }
    return $processed;
}

function feedFormatNumber(int $num): string
{
    if ($num >= 1000000000) {
        $val = round($num / 1000000000, 1);
        return (fmod($val, 1.0) == 0.0 ? (int)$val : $val) . 'B';
    } elseif ($num >= 1000000) {
        $val = round($num / 1000000, 1);
        return (fmod($val, 1.0) == 0.0 ? (int)$val : $val) . 'M';
    } elseif ($num >= 1000) {
        $val = round($num / 1000, 1);
        return (fmod($val, 1.0) == 0.0 ? (int)$val : $val) . 'k';
    }
    return (string)$num;
}

/**
 * Renderiza o elemento HTML (.video-item) no padrão do FeedManager JS
 */
function renderFeedVideoItemHtml(array $video, int $index, bool $is_guest, int $current_user_id, bool $is_admin): string
{
    $vid_id = (int)$video['id'];
    $author_id = (int)($video['user']['id'] ?? 0);
    $school_name = htmlspecialchars($video['school_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $school_short = htmlspecialchars($video['school_short'] ?? $video['school_name'] ?? '', ENT_QUOTES, 'UTF-8');
    
    $school_attrs = '';
    if (!empty($video['school_name'])) {
        $school_attrs = ' data-school-name="' . $school_name . '" data-school-short="' . $school_short . '"';
    }

    $raw_desc = (string)($video['description'] ?? '');
    $full_desc = trim(preg_replace('/\s+/', ' ', $raw_desc));
    $is_collapsible = false;
    $collapsed_desc = $full_desc;

    if (mb_strlen($full_desc) > 120) {
        $is_collapsible = true;
        $candidate = mb_substr($full_desc, 0, 121);
        $cutIndex = mb_strrpos($candidate, ' ');
        if ($cutIndex === false || $cutIndex < (int)floor(120 * 0.55)) {
            $cutIndex = max(1, 120 - 6);
        }
        $collapsed_desc = rtrim(mb_substr($full_desc, 0, $cutIndex));
    }

    $esc_full = htmlspecialchars($full_desc, ENT_QUOTES, 'UTF-8');
    $esc_collapsed = htmlspecialchars($collapsed_desc, ENT_QUOTES, 'UTF-8');

    $linkify = function(string $text): string {
        return preg_replace(
            '/(https?:\/\/(?:[^\s<>"\'&]|&amp;)*)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="description-link" onclick="event.stopPropagation()">$1</a>',
            $text
        );
    };

    $caption_html = '';
    if (!empty($full_desc)) {
        $shown_text = $linkify($is_collapsible ? $esc_collapsed : $esc_full);
        $toggle_btn = $is_collapsible ? '<button type="button" class="caption-toggle-btn" aria-expanded="false">...Ver mais</button>' : '';
        $caption_html = '
            <div class="video-caption-block ' . ($is_collapsible ? 'is-collapsed' : '') . '">
                <p class="video-caption" data-caption-full="' . $esc_full . '" data-caption-collapsed="' . $esc_collapsed . '">' . $shown_text . '</p>
                ' . $toggle_btn . '
            </div>';
    }

    $hashtags_html = '';
    if (!empty($video['hashtags']) && is_array($video['hashtags'])) {
        $chips = [];
        foreach ($video['hashtags'] as $ht) {
            $h_slug = rawurlencode($ht['slug'] ?? '');
            $h_name = htmlspecialchars($ht['name'] ?? '', ENT_QUOTES, 'UTF-8');
            if ($h_slug && $h_name) {
                $chips[] = '<a class="video-hashtag-link" href="hashtags.php?tag=' . $h_slug . '">#' . $h_name . '</a>';
            }
        }
        if (!empty($chips)) {
            $hashtags_html = '<div class="video-hashtags">' . implode('', $chips) . '</div>';
        }
    }

    $author_username = htmlspecialchars($video['user']['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $author_fullname = htmlspecialchars($video['user']['full_name'] ?? $video['user']['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $author_avatar = htmlspecialchars($video['user']['profile_picture_url'] ?? avatar_url($video['user']['profile_picture'] ?? null), ENT_QUOTES, 'UTF-8');
    $verified_badge = !empty($video['user']['is_verified']) ? '<i class="fas fa-check-circle verified-badge"></i>' : '';

    $ranking_badge_html = '';
    if (!empty($video['school_name']) || !empty($video['school_short'])) {
        $pts = (int)($video['ranking_points'] ?? 0);
        $fmt_pts = $pts >= 1000 ? rtrim(rtrim(number_format($pts / 1000, 1), '0'), '.') . 'k' : (string)$pts;
        $ranking_badge_html = '
            <a href="ranking.php" class="ranking-badge" title="Ver Ranking">
                <span class="ranking-badge-icon"><svg class="mytube-rank-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:1.2em; height:1.2em; vertical-align:middle; display:inline-block; filter:drop-shadow(0 2px 4px rgba(0,123,255,0.4));"><defs><linearGradient id="mytubeFlame' . $vid_id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4facfe"/><stop offset="100%" stop-color="#00f2fe"/></linearGradient><linearGradient id="mytubeBar' . $vid_id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#007BFF"/><stop offset="100%" stop-color="#003D82"/></linearGradient></defs><path d="M12 2C12 2 15 5.5 15 8C15 9.65 13.65 11 12 11C10.35 11 9 9.65 9 8C9 5.5 12 2 12 2Z" fill="url(#mytubeFlame' . $vid_id . ')"/><rect x="7" y="13" width="10" height="4" rx="1" fill="url(#mytubeBar' . $vid_id . ')"/><rect x="3" y="19" width="18" height="4" rx="1" fill="url(#mytubeBar' . $vid_id . ')"/></svg></span>
                <span class="ranking-badge-text">' . $fmt_pts . ' pts&nbsp;•&nbsp;' . ($school_short ?: $school_name) . '</span>
            </a>';
    }

    $boosted_badge = !empty($video['is_boosted']) ? '<div class="boosted-badge"> Patrocinado</div>' : '';

    $video_url = htmlspecialchars($video['video_url'] ?? resolve_video_url($video['video_path'] ?? ''), ENT_QUOTES, 'UTF-8');
    $video_path = htmlspecialchars($video['video_path'] ?? '', ENT_QUOTES, 'UTF-8');
    $poster_attr = !empty($video['thumbnail_path']) ? ' poster="' . htmlspecialchars(resolve_video_url($video['thumbnail_path']), ENT_QUOTES, 'UTF-8') . '"' : '';

    // Apenas o primeiro vídeo carrega metadata/buffer imediatamente
    $preload_attr = ($index === 0) ? 'preload="auto"' : 'preload="none"';

    // Ações laterais
    $likes_count_str = feedFormatNumber((int)($video['likes_count'] ?? 0));
    $comments_count_str = feedFormatNumber((int)($video['comments_count'] ?? 0));

    if ($is_guest) {
        $action_buttons = '
            <button class="action-btn like-btn" onclick="showGuestLoginModal(); return false;">
                <svg class="icon-heart" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <span class="like-count">' . $likes_count_str . '</span>
            </button>
            <button class="action-btn comment-btn" data-video-id="' . $vid_id . '">
                <svg class="icon-comment" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                <span class="action-count">' . $comments_count_str . '</span>
            </button>
            <button class="action-btn follow-btn" onclick="showGuestLoginModal(); return false;">
                <svg class="icon-plus" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span class="action-count"></span>
            </button>
            <div class="more-btn-wrapper">
                <button class="action-btn more-btn" data-video-id="' . $vid_id . '" title="Mais opções">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                    <span class="action-count"></span>
                </button>
                <div class="more-menu" id="more-menu-' . $vid_id . '">
                    <button class="more-menu-item" data-action="share" data-video-id="' . $vid_id . '">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4l5 4-5 4"/><path d="M18 8H8a4 4 0 0 0-4 4v1"/><path d="M4 18h12a2 2 0 0 0 2-2v-1"/></svg>
                        Partilhar
                    </button>
                </div>
            </div>';
    } else {
        $follow_btn = '';
        if ($author_id !== $current_user_id) {
            $is_following = !empty($video['user_following']);
            $follow_icon = $is_following 
                ? '<svg class="icon-check" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
                : '<svg class="icon-plus" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            $follow_btn = '
                <button class="action-btn follow-btn ' . ($is_following ? 'following' : '') . '"
                        data-user-follow="' . $author_id . '"
                        data-user-id="' . $author_id . '"
                        data-follows-you="' . (!empty($video['author_follows_you']) ? '1' : '0') . '">
                    ' . $follow_icon . '
                    <span class="action-count"></span>
                </button>';
        }

        $is_owner = ($author_id === $current_user_id);
        $report_item = !$is_owner ? '
            <button class="more-menu-item" data-action="report" data-video-id="' . $vid_id . '">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                Denunciar
            </button>' : '';

        $delete_item = ($is_owner || $is_admin) ? '
            <hr class="more-menu-divider">
            <button class="more-menu-item danger delete-btn" data-video-id="' . $vid_id . '">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Apagar vídeo
            </button>' : '';

        $is_boosted = !empty($video['is_boosted']);
        $boost_item = $is_admin ? '
            <button class="more-menu-item boost-btn ' . ($is_boosted ? 'boosted' : '') . '" data-video-id="' . $vid_id . '" data-boosted="' . ($is_boosted ? '1' : '0') . '">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="' . ($is_boosted ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                ' . ($is_boosted ? 'Remover Boost' : 'Dar Boost') . '
            </button>' : '';

        $is_liked = !empty($video['user_liked']);
        $action_buttons = '
            <button class="action-btn like-btn ' . ($is_liked ? 'liked' : '') . '" 
                    data-video-like="' . $vid_id . '"
                    data-video-id="' . $vid_id . '">
                <svg class="icon-heart" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <span class="like-count">' . $likes_count_str . '</span>
            </button>
            <button class="action-btn comment-btn" data-video-id="' . $vid_id . '">
                <svg class="icon-comment" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                <span class="action-count">' . $comments_count_str . '</span>
            </button>
            ' . $follow_btn . '
            <div class="more-btn-wrapper">
                <button class="action-btn more-btn" data-video-id="' . $vid_id . '" title="Mais opções">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                    <span class="action-count"></span>
                </button>
                <div class="more-menu" id="more-menu-' . $vid_id . '">
                    <button class="more-menu-item" data-action="share" data-video-id="' . $vid_id . '">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4l5 4-5 4"/><path d="M18 8H8a4 4 0 0 0-4 4v1"/><path d="M4 18h12a2 2 0 0 0 2-2v-1"/></svg>
                        Partilhar
                    </button>
                    ' . $report_item . '
                    ' . $delete_item . '
                    ' . $boost_item . '
                </div>
            </div>';
    }

    return '
    <div class="video-item" data-video-id="' . $vid_id . '"' . $school_attrs . ' data-author-id="' . $author_id . '" data-index="' . $index . '">
        <div class="video-player">
            <video 
                id="video-' . $vid_id . '"
                loop
                muted
                playsinline
                ' . $preload_attr . '
                ' . $poster_attr . '
                data-has-audio="true"
                data-video-id="' . $vid_id . '"
                data-video-path="' . $video_path . '"
                data-video-url="' . $video_url . '">
                Seu navegador não suporta o elemento de vídeo.
            </video>
            
            <div class="video-controls">
                <button class="audio-toggle" data-video-id="' . $vid_id . '" title="Ativar/Desativar Som">
                    <i class="fas fa-volume-up"></i>
                </button>
            </div>
            
            <div class="audio-prompt-overlay" id="audio-prompt-' . $vid_id . '" style="display: none;">
                <div class="audio-prompt-content">
                    <i class="fas fa-volume-up"></i>
                    <h3>Toque para ativar o som</h3>
                    <p>Clique aqui para reproduzir com áudio</p>
                </div>
            </div>
            
            <div class="video-info-overlay">
                <div class="video-author-row">
                    <a href="perfil.php?id=' . $author_id . '" class="video-author-link">
                        <img src="' . $author_avatar . '" 
                             alt="' . $author_username . '" 
                             class="video-author-avatar"
                             loading="lazy">
                        <span class="video-author-name">
                            ' . $author_fullname . '
                            ' . $verified_badge . '
                        </span>
                    </a>
                    ' . $ranking_badge_html . '
                </div>
                ' . $boosted_badge . '
                ' . $caption_html . '
                ' . $hashtags_html . '
            </div>
            
            <div class="progress-container" data-video-id="' . $vid_id . '">
                <div class="progress-track">
                    <div class="progress-bar" id="progress-' . $vid_id . '"></div>
                </div>
                <div class="progress-time" id="progress-time-' . $vid_id . '">0:00</div>
            </div>
        </div>
        
        <div class="action-buttons">
            ' . $action_buttons . '
        </div>
    </div>';
}
