<?php

function hashtag_max_per_video(): int
{
    return 4;
}

function hashtag_max_length(): int
{
    return 20;
}

function hashtag_build_slug(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    if ($value === '') {
        return '';
    }

    $ascii = $value;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false && $converted !== '') {
            $ascii = $converted;
        }
    }

    $ascii = strtolower($ascii);
    $slug = preg_replace('/[^a-z0-9]/', '', $ascii);

    if ($slug === '') {
        if (preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches)) {
            $slug = mb_strtolower(implode('', $matches[0]), 'UTF-8');
        }
    }

    if (mb_strlen($slug, 'UTF-8') > hashtag_max_length()) {
        $slug = mb_substr($slug, 0, hashtag_max_length(), 'UTF-8');
    }

    return $slug;
}

function hashtag_normalize_token(string $token): ?array
{
    $token = trim($token);

    while ($token !== '' && mb_substr($token, 0, 1, 'UTF-8') === '#') {
        $token = mb_substr($token, 1, null, 'UTF-8');
    }

    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $normalized_name = mb_strtolower($token, 'UTF-8');

    if (mb_strlen($normalized_name, 'UTF-8') > hashtag_max_length()) {
        throw new InvalidArgumentException('Cada hashtag deve ter no máximo ' . hashtag_max_length() . ' caracteres.');
    }

    if (!preg_match('/^[\p{L}\p{N}]+$/u', $normalized_name)) {
        throw new InvalidArgumentException('Hashtags devem conter apenas letras e números, sem espaços ou símbolos.');
    }

    $slug = hashtag_build_slug($normalized_name);
    if ($slug === '') {
        throw new InvalidArgumentException('Hashtag inválida.');
    }

    return [
        'name' => $normalized_name,
        'slug' => $slug,
    ];
}

function hashtag_parse_input(string $input): array
{
    $input = trim($input);
    if ($input === '') {
        return [];
    }

    $tokens = preg_split('/\s+/u', $input);
    $hashtags = [];
    $seen = [];

    foreach ($tokens as $token) {
        if ($token === '' || $token === null) {
            continue;
        }

        $normalized = hashtag_normalize_token($token);
        if ($normalized === null) {
            continue;
        }

        if (isset($seen[$normalized['slug']])) {
            continue;
        }

        $seen[$normalized['slug']] = true;
        $hashtags[] = $normalized;

        if (count($hashtags) > hashtag_max_per_video()) {
            throw new InvalidArgumentException('Máximo de ' . hashtag_max_per_video() . ' hashtags por vídeo.');
        }
    }

    return $hashtags;
}

function hashtag_extract_from_storage(?string $raw_hashtags): array
{
    $raw_hashtags = trim((string)$raw_hashtags);
    if ($raw_hashtags === '') {
        return [];
    }

    $tokens = preg_split('/\s+/u', $raw_hashtags);
    $hashtags = [];
    $seen = [];

    foreach ($tokens as $token) {
        try {
            $normalized = hashtag_normalize_token((string)$token);
        } catch (Throwable $e) {
            continue;
        }

        if (!$normalized) {
            continue;
        }

        if (isset($seen[$normalized['slug']])) {
            continue;
        }

        $seen[$normalized['slug']] = true;
        $hashtags[] = $normalized;
    }

    return $hashtags;
}

function hashtag_format_for_storage(array $hashtags): string
{
    if (empty($hashtags)) {
        return '';
    }

    $chunks = [];
    foreach ($hashtags as $hashtag) {
        $name = trim((string)($hashtag['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $chunks[] = '#' . $name;
    }

    return implode(' ', $chunks);
}

function hashtag_tables_available($pdo): bool
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    try {
        $has_hashtags = $pdo->query("SHOW TABLES LIKE 'hashtags'")->fetchColumn();
        $has_video_hashtags = $pdo->query("SHOW TABLES LIKE 'video_hashtags'")->fetchColumn();
        $cached = !empty($has_hashtags) && !empty($has_video_hashtags);
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

function hashtag_ensure_tables($pdo): void
{
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS hashtags (\n            id INT(11) NOT NULL AUTO_INCREMENT,\n            name VARCHAR(20) NOT NULL,\n            slug VARCHAR(20) NOT NULL,\n            posts_count INT(11) NOT NULL DEFAULT 0,\n            is_seed TINYINT(1) NOT NULL DEFAULT 0,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),\n            last_used_at TIMESTAMP NULL DEFAULT NULL,\n            PRIMARY KEY (id),\n            UNIQUE KEY uk_hashtag_name (name),\n            UNIQUE KEY uk_hashtag_slug (slug),\n            KEY idx_posts_count (posts_count),\n            KEY idx_last_used_at (last_used_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS video_hashtags (\n            video_id INT(11) NOT NULL,\n            hashtag_id INT(11) NOT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),\n            PRIMARY KEY (video_id, hashtag_id),\n            KEY idx_hashtag_video (hashtag_id, video_id),\n            CONSTRAINT fk_video_hashtags_video FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,\n            CONSTRAINT fk_video_hashtags_hashtag FOREIGN KEY (hashtag_id) REFERENCES hashtags(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");
}

function hashtag_recalculate_counts($pdo, array $hashtag_ids): void
{
    $hashtag_ids = array_values(array_unique(array_map('intval', $hashtag_ids)));
    if (empty($hashtag_ids)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($hashtag_ids), '?'));

    $sql = "\n        UPDATE hashtags h\n        SET h.posts_count = (\n            SELECT COUNT(*)\n            FROM video_hashtags vh\n            INNER JOIN videos v ON v.id = vh.video_id\n            WHERE vh.hashtag_id = h.id AND v.is_public = 1\n        ),\n        h.updated_at = NOW()\n        WHERE h.id IN ($placeholders)\n    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($hashtag_ids);
}

function hashtag_sync_video_relations($pdo, int $video_id, array $hashtags): void
{
    if ($video_id <= 0 || !hashtag_tables_available($pdo)) {
        return;
    }

    $existing_stmt = $pdo->prepare('SELECT hashtag_id FROM video_hashtags WHERE video_id = ?');
    $existing_stmt->execute([$video_id]);
    $existing_ids = array_map('intval', $existing_stmt->fetchAll(PDO::FETCH_COLUMN));

    $target_ids = [];

    if (!empty($hashtags)) {
        $upsert_stmt = $pdo->prepare("\n            INSERT INTO hashtags (name, slug, posts_count, is_seed, created_at, updated_at, last_used_at)\n            VALUES (?, ?, 0, 0, NOW(), NOW(), NOW())\n            ON DUPLICATE KEY UPDATE\n                name = VALUES(name),\n                updated_at = NOW(),\n                last_used_at = NOW(),\n                id = LAST_INSERT_ID(id)\n        ");

        $link_stmt = $pdo->prepare('INSERT IGNORE INTO video_hashtags (video_id, hashtag_id, created_at) VALUES (?, ?, NOW())');

        foreach ($hashtags as $hashtag) {
            $name = (string)($hashtag['name'] ?? '');
            $slug = (string)($hashtag['slug'] ?? '');

            if ($name === '' || $slug === '') {
                continue;
            }

            $upsert_stmt->execute([$name, $slug]);
            $hashtag_id = (int)$pdo->lastInsertId();

            if ($hashtag_id <= 0) {
                continue;
            }

            $target_ids[] = $hashtag_id;
            $link_stmt->execute([$video_id, $hashtag_id]);
        }
    }

    $target_ids = array_values(array_unique(array_map('intval', $target_ids)));

    $ids_to_remove = array_values(array_diff($existing_ids, $target_ids));
    if (!empty($ids_to_remove)) {
        $placeholders = implode(',', array_fill(0, count($ids_to_remove), '?'));
        $delete_sql = "DELETE FROM video_hashtags WHERE video_id = ? AND hashtag_id IN ($placeholders)";
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute(array_merge([$video_id], $ids_to_remove));
    }

    if (empty($target_ids)) {
        $pdo->prepare('DELETE FROM video_hashtags WHERE video_id = ?')->execute([$video_id]);
    }

    $ids_to_recalculate = array_values(array_unique(array_merge($existing_ids, $target_ids)));
    hashtag_recalculate_counts($pdo, $ids_to_recalculate);
}

function hashtag_seed_bulk($pdo, array $tags): int
{
    if (!hashtag_tables_available($pdo)) {
        return 0;
    }

    $upsert_stmt = $pdo->prepare("\n        INSERT INTO hashtags (name, slug, posts_count, is_seed, created_at, updated_at)\n        VALUES (?, ?, 0, 1, NOW(), NOW())\n        ON DUPLICATE KEY UPDATE\n            name = VALUES(name),\n            is_seed = 1,\n            updated_at = NOW(),\n            id = LAST_INSERT_ID(id)\n    ");

    $seeded = 0;
    $seen = [];

    foreach ($tags as $raw_tag) {
        try {
            $normalized = hashtag_normalize_token((string)$raw_tag);
        } catch (Throwable $e) {
            continue;
        }

        if (!$normalized || isset($seen[$normalized['slug']])) {
            continue;
        }

        $seen[$normalized['slug']] = true;
        $upsert_stmt->execute([$normalized['name'], $normalized['slug']]);
        $seeded++;
    }

    return $seeded;
}
