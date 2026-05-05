<?php
/**
 * Script único para sincronizar users.instituicao com schools.name
 * 
 * Problema: Após deduplicação, users.school_id foi migrado mas users.instituicao
 * ficou com o nome da escola antiga/removida. Este script corrige isso.
 * 
 * Também limpa o cache do ranking.
 * 
 * USO: php sync_school_names.php (CLI ou browser, requer admin)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ranking_cache.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    if (!isLoggedIn() || !isAdminUser()) {
        http_response_code(403);
        die('Acesso negado. Apenas administradores.');
    }
    echo "<pre>";
}

echo "🔄 Sincronizando users.instituicao com schools.name...\n\n";

// 1. Actualizar instituicao para todos os users que têm school_id válido
$stmt = $pdo->prepare("
    UPDATE users u
    INNER JOIN schools s ON u.school_id = s.id AND s.is_active = 1
    SET u.instituicao = s.name
    WHERE u.school_id IS NOT NULL
      AND (u.instituicao IS NULL OR u.instituicao != s.name)
");
$stmt->execute();
$synced = $stmt->rowCount();
echo "✅ $synced utilizadores actualizados (instituicao → nome da escola activa)\n";

// 2. Limpar instituicao de users que apontam para escola inactiva/inexistente
$stmt2 = $pdo->prepare("
    UPDATE users u
    LEFT JOIN schools s ON u.school_id = s.id AND s.is_active = 1
    SET u.school_id = NULL, u.instituicao = NULL
    WHERE u.school_id IS NOT NULL AND s.id IS NULL
");
$stmt2->execute();
$cleared = $stmt2->rowCount();
if ($cleared > 0) {
    echo "⚠️  $cleared utilizadores tinham school_id apontando para escola inactiva/inexistente — limpos.\n";
}

// 3. Recalcular totais de todas as escolas activas
echo "\n📊 Recalculando totais das escolas...\n";
$schools = $pdo->query("SELECT id, name FROM schools WHERE is_active = 1")->fetchAll();
$recalc_stmt = $pdo->prepare("
    UPDATE schools SET
        total_students = (SELECT COUNT(*) FROM users WHERE school_id = ?),
        total_points = (SELECT COALESCE(SUM(ranking_points), 0) FROM users WHERE school_id = ?)
    WHERE id = ?
");
$vid_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM videos v
    INNER JOIN users u ON v.user_id = u.id
    WHERE u.school_id = ? AND v.is_public = 1
");
$vid_update = $pdo->prepare("UPDATE schools SET total_videos = ? WHERE id = ?");

foreach ($schools as $s) {
    $recalc_stmt->execute([$s['id'], $s['id'], $s['id']]);
    $vid_stmt->execute([$s['id']]);
    $vid_update->execute([(int)$vid_stmt->fetchColumn(), $s['id']]);
}
echo "✅ " . count($schools) . " escolas recalculadas.\n";

// 4. Limpar todo o cache do ranking
ranking_cache_clear_all();
echo "✅ Cache do ranking limpo.\n";

// 5. Mostrar resumo
echo "\n========================================\n";
echo "🎉 Sincronização concluída!\n";
echo "   $synced utilizadores corrigidos\n";
echo "   $cleared utilizadores com escola inválida limpos\n";
echo "   " . count($schools) . " escolas recalculadas\n";
echo "========================================\n";

if (!$is_cli) echo "</pre>";
