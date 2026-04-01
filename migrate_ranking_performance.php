<?php
/**
 * Migração: Otimização de Performance para Rankings
 * 
 * Adiciona:
 * 1. Coluna trend_score em videos (pré-calculado, indexado)
 * 2. Índices compostos para videos
 * 3. Índice para school_id + ranking_points em users
 * 4. Diretório de cache
 * 5. Recalcula ranking_points e trend_score para dados existentes
 * 
 * Executar UMA VEZ: http://localhost/my/migrate_ranking_performance.php
 */
require_once 'includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== MyTube: Migração de Performance para Rankings ===\n\n";

$success = 0;
$skipped = 0;
$errors = 0;

// ─────────────────────────────────────
// 1. Coluna trend_score em videos
// ─────────────────────────────────────
echo "[1/6] Adicionando coluna trend_score em videos...\n";
try {
    $check = $pdo->query("SHOW COLUMNS FROM videos LIKE 'trend_score'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN trend_score INT UNSIGNED NOT NULL DEFAULT 0 AFTER comments_count");
        echo "  [OK] Coluna trend_score criada\n";
        $success++;
    } else {
        echo "  [SKIP] Coluna trend_score já existe\n";
        $skipped++;
    }
} catch (Exception $e) {
    echo "  [ERRO] " . $e->getMessage() . "\n";
    $errors++;
}

// ─────────────────────────────────────
// 2. Índices em videos
// ─────────────────────────────────────
echo "\n[2/6] Adicionando índices em videos...\n";
$video_indexes = [
    "ALTER TABLE videos ADD KEY idx_user_public (user_id, is_public)",
    "ALTER TABLE videos ADD KEY idx_user_public_created (user_id, is_public, created_at)",
    "ALTER TABLE videos ADD KEY idx_public_trend (is_public, trend_score DESC)",
    "ALTER TABLE videos ADD KEY idx_public_created (is_public, created_at)",
];

foreach ($video_indexes as $sql) {
    try {
        $pdo->exec($sql);
        echo "  [OK] $sql\n";
        $success++;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "  [SKIP] Já existe: " . substr($sql, 0, 60) . "...\n";
            $skipped++;
        } else {
            echo "  [ERRO] " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}

// ─────────────────────────────────────
// 3. Índice em users para ranking escola
// ─────────────────────────────────────
echo "\n[3/6] Adicionando índice school_id + ranking_points em users...\n";
$user_indexes = [
    "ALTER TABLE users ADD KEY idx_school_ranking (school_id, ranking_points DESC)",
];

foreach ($user_indexes as $sql) {
    try {
        $pdo->exec($sql);
        echo "  [OK] $sql\n";
        $success++;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "  [SKIP] Já existe\n";
            $skipped++;
        } else {
            echo "  [ERRO] " . $e->getMessage() . "\n";
            $errors++;
        }
    }
}

// ─────────────────────────────────────
// 4. Criar diretório de cache
// ─────────────────────────────────────
echo "\n[4/6] Criando diretório de cache...\n";
$cacheDir = __DIR__ . '/cache/rankings';
if (!is_dir($cacheDir)) {
    if (mkdir($cacheDir, 0755, true)) {
        echo "  [OK] Diretório cache/rankings/ criado\n";
        $success++;
    } else {
        echo "  [ERRO] Não foi possível criar cache/rankings/\n";
        $errors++;
    }
} else {
    echo "  [SKIP] Diretório já existe\n";
    $skipped++;
}

// .htaccess para proteger o cache
$htaccess = $cacheDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
    echo "  [OK] .htaccess de proteção criado\n";
}

// ─────────────────────────────────────
// 5. Recalcular trend_score para todos os vídeos
// ─────────────────────────────────────
echo "\n[5/6] Calculando trend_score para vídeos existentes...\n";
try {
    $affected = $pdo->exec("
        UPDATE videos 
        SET trend_score = (likes_count * 2 + views_count + comments_count * 3)
        WHERE is_public = 1
    ");
    echo "  [OK] trend_score calculado para $affected vídeos\n";
    $success++;
} catch (Exception $e) {
    echo "  [ERRO] " . $e->getMessage() . "\n";
    $errors++;
}

// ─────────────────────────────────────
// 6. Recalcular ranking_points para todos os users
// ─────────────────────────────────────
echo "\n[6/6] Recalculando ranking_points para todos os users...\n";
try {
    $affected = $pdo->exec("
        UPDATE users u SET ranking_points = COALESCE((
            SELECT 
                COUNT(*) * 10 +
                COALESCE(SUM(v.likes_count), 0) * 2 +
                COALESCE(SUM(v.comments_count), 0) * 3 +
                COALESCE(SUM(v.views_count), 0) * 1
            FROM videos v
            WHERE v.user_id = u.id AND v.is_public = 1
        ), 0)
    ");
    echo "  [OK] ranking_points recalculado para $affected users\n";
    $success++;
} catch (Exception $e) {
    echo "  [ERRO] " . $e->getMessage() . "\n";
    $errors++;
}

echo "\n========================================\n";
echo "Resultado: $success criados, $skipped ignorados, $errors erros\n";
echo "========================================\n";
if ($errors === 0) {
    echo "\n✅ Migração concluída com sucesso!\n";
    echo "O sistema de rankings está agora otimizado para 1000+ utilizadores.\n";
} else {
    echo "\n⚠️ Migração concluída com $errors erro(s). Verifique os detalhes acima.\n";
}
echo "\nPode apagar este ficheiro após executar.\n";
