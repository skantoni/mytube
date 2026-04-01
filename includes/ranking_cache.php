<?php
/**
 * Sistema de cache para Rankings — ficheiro-based
 * Evita milhares de queries pesadas agregando dados em cache.
 * 
 * Cache TTL:
 * - Rankings globais: 5 minutos
 * - Escola dominante: 10 minutos
 * - Trending videos: 5 minutos
 * - Top Schools: 10 minutos
 */

define('RANKING_CACHE_DIR', __DIR__ . '/../cache/rankings/');

/**
 * Buscar dados do cache. Retorna null se expirado ou inexistente.
 */
function ranking_cache_get(string $key, int $ttl_seconds = 300) {
    $file = RANKING_CACHE_DIR . md5($key) . '.json';
    if (!file_exists($file)) return null;
    
    $mtime = filemtime($file);
    if ((time() - $mtime) > $ttl_seconds) return null;
    
    $data = file_get_contents($file);
    if ($data === false) return null;
    
    return json_decode($data, true);
}

/**
 * Gravar dados no cache.
 */
function ranking_cache_set(string $key, $data): void {
    if (!is_dir(RANKING_CACHE_DIR)) {
        mkdir(RANKING_CACHE_DIR, 0755, true);
    }
    $file = RANKING_CACHE_DIR . md5($key) . '.json';
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, json_encode($data), LOCK_EX) !== false) {
        rename($tmp, $file);
    }
}

/**
 * Invalidar uma chave específica do cache.
 */
function ranking_cache_invalidate(string $key): void {
    $file = RANKING_CACHE_DIR . md5($key) . '.json';
    if (file_exists($file)) {
        @unlink($file);
    }
}

/**
 * Invalidar todo o cache de rankings (após ação que mude pontos).
 */
function ranking_cache_clear_all(): void {
    if (!is_dir(RANKING_CACHE_DIR)) return;
    $files = glob(RANKING_CACHE_DIR . '*.json');
    if ($files) {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
}

/**
 * Atualizar ranking_points de um utilizador.
 * Chamado após like/view/comment/upload/delete.
 * 
 * Formula: videos*10 + likes*2 + comments*3 + views*1
 */
function ranking_points_recalc($pdo, int $user_id): void {
    $stmt = $pdo->prepare("
        UPDATE users SET ranking_points = COALESCE((
            SELECT 
                COUNT(*) * 10 +
                COALESCE(SUM(v.likes_count), 0) * 2 +
                COALESCE(SUM(v.comments_count), 0) * 3 +
                COALESCE(SUM(v.views_count), 0) * 1
            FROM videos v
            WHERE v.user_id = ? AND v.is_public = 1
        ), 0)
        WHERE id = ?
    ");
    $stmt->execute([$user_id, $user_id]);
}

/**
 * Incrementar ranking_points rapidamente sem recalcular tudo.
 * Mais eficiente para ações frequentes (views, likes).
 */
function ranking_points_increment($pdo, int $user_id, int $delta): void {
    $stmt = $pdo->prepare("UPDATE users SET ranking_points = GREATEST(0, ranking_points + ?) WHERE id = ?");
    $stmt->execute([$delta, $user_id]);
}
