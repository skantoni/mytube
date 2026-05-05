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
}

// Capture output buffer
ob_start();

echo "🔄 Sincronizando users.instituicao com schools.name...\n\n";

try {
    $pdo->beginTransaction();

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
    // Antes de limpar, guardar log dos afectados
    $afectados_stmt = $pdo->prepare("
        SELECT u.id, u.username, u.instituicao
        FROM users u
        LEFT JOIN schools s ON u.school_id = s.id AND s.is_active = 1
        WHERE u.school_id IS NOT NULL AND s.id IS NULL
    ");
    $afectados_stmt->execute();
    $lista_afectados = $afectados_stmt->fetchAll();

    if (count($lista_afectados) > 0) {
        echo "⚠️  " . count($lista_afectados) . " utilizadores perderão associação a escola inactiva/inexistente:\n";
        foreach ($lista_afectados as $u) {
            echo "     • @{$u['username']} (ID: {$u['id']}) — escola: '{$u['instituicao']}'\n";
            error_log("sync_school_names: user {$u['id']} ({$u['username']}) perdeu escola '{$u['instituicao']}'");
        }
        echo "\n";
    }

    $stmt2 = $pdo->prepare("
        UPDATE users u
        LEFT JOIN schools s ON u.school_id = s.id AND s.is_active = 1
        SET u.school_id = NULL, u.instituicao = NULL
        WHERE u.school_id IS NOT NULL AND s.id IS NULL
    ");
    $stmt2->execute();
    $cleared = $stmt2->rowCount();

    // 3. Recalcular totais de todas as escolas activas (batch)
    echo "\n📊 Recalculando totais das escolas...\n";

    // Update total_students e total_points para todas as escolas
    $pdo->exec("
        UPDATE schools s
        SET
            total_students = (SELECT COUNT(*) FROM users WHERE school_id = s.id),
            total_points = (SELECT COALESCE(SUM(ranking_points), 0) FROM users WHERE school_id = s.id)
        WHERE s.is_active = 1
    ");

    // Update total_videos para todas as escolas
    $pdo->exec("
        UPDATE schools s
        SET total_videos = (
            SELECT COUNT(*) FROM videos v
            INNER JOIN users u ON v.user_id = u.id
            WHERE u.school_id = s.id AND v.is_public = 1
        )
        WHERE s.is_active = 1
    ");

    $schools_count = $pdo->query("SELECT COUNT(*) FROM schools WHERE is_active = 1")->fetchColumn();
    echo "✅ $schools_count escolas recalculadas.\n";

    $pdo->commit();

    // 4. Limpar todo o cache do ranking (após commit bem-sucedido)
    // Cache clearing é separado — falha não desfaz a sincronização
    try {
        ranking_cache_clear_all();
        echo "✅ Cache do ranking limpo.\n";
    } catch (Exception $e) {
        echo "⚠️  Aviso: Falha ao limpar cache: " . $e->getMessage() . "\n";
        error_log("sync_school_names: cache clear failed: " . $e->getMessage());
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Erro durante sincronização: " . $e->getMessage() . "\n";
    exit(1);
}

// 5. Mostrar resumo
echo "\n========================================\n";
echo "🎉 Sincronização concluída!\n";
echo "   $synced utilizadores corrigidos\n";
echo "   $cleared utilizadores com escola inválida limpos\n";
echo "   " . count($lista_afectados) . " utilizadores perderam associação a escola\n";
echo "========================================\n";

// Flush output buffer com HTML seguro
$output = ob_get_clean();
if (!$is_cli) {
    echo "<pre>";
    echo htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    echo "</pre>";
} else {
    echo $output;
}
