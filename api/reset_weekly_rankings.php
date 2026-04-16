<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * API: Snapshot Semanal dos Rankings
 * ═══════════════════════════════════════════════════════════════
 * 
 * Pode ser executado todo DOMINGO às 23:59 (via cron ou admin)
 * 
 * O que faz:
 * 1. Guarda snapshot dos pontos atuais na tabela ranking_weekly_history
 * 2. Limpa o cache de rankings
 * 
 * NOTA: Os ranking_points NÃO são zerados. Os rankings são calculados
 * dinamicamente por período (semana, mês, 3 meses) com base na data
 * de criação dos vídeos.
 * 
 * Execução:
 *   CLI:   php api/reset_weekly_rankings.php
 *   HTTP:  api/reset_weekly_rankings.php?secret=CRON_SECRET
 *   Admin: api/reset_weekly_rankings.php (logado como Admin)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ranking_cache.php';

$is_cli  = (php_sapi_name() === 'cli');
$is_cron = $is_cli || (
    isset($_GET['secret']) &&
    defined('CRON_SECRET') &&
    hash_equals(CRON_SECRET, $_GET['secret'])
);

if (!$is_cli) {
    header('Content-Type: application/json');
}

// Autorização: CLI, secret token ou admin logado
if (!$is_cron) {
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        exit;
    }
    $admin_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $admin_stmt->execute([$_SESSION['user_id']]);
    $admin_user = $admin_stmt->fetch();
    if ($admin_user['username'] !== 'Admin') {
        echo json_encode(['success' => false, 'error' => 'Apenas admin pode executar o reset']);
        exit;
    }
}

try {
    $now = new DateTime('now', new DateTimeZone('Africa/Luanda'));
    $week_label = $now->format('Y-W'); // ex: 2026-16

    // ═══════════════════════════════════════
    // 1. Criar tabela de histórico se não existir
    // ═══════════════════════════════════════
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ranking_weekly_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            week_label VARCHAR(10) NOT NULL COMMENT 'Formato: YYYY-WW',
            ranking_points INT NOT NULL DEFAULT 0,
            global_position INT DEFAULT NULL,
            snapshot_at DATETIME NOT NULL,
            INDEX idx_week (week_label),
            INDEX idx_user_week (user_id, week_label),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->beginTransaction();

    // ═══════════════════════════════════════
    // 2. Verificar se já foi resetado esta semana
    // ═══════════════════════════════════════
    $check = $pdo->prepare("SELECT COUNT(*) AS cnt FROM ranking_weekly_history WHERE week_label = ?");
    $check->execute([$week_label]);
    $already_done = (int)$check->fetch()['cnt'];

    if ($already_done > 0) {
        $pdo->rollBack();
        $msg = "Reset já foi executado para a semana $week_label.";
        if ($is_cli) {
            echo "[SKIP] $msg\n";
        } else {
            echo json_encode(['success' => false, 'error' => $msg, 'week' => $week_label]);
        }
        exit;
    }

    // ═══════════════════════════════════════
    // 3. Guardar snapshot dos pontos atuais
    // ═══════════════════════════════════════
    $snapshot_time = $now->format('Y-m-d H:i:s');

    $pdo->prepare("
        INSERT INTO ranking_weekly_history (user_id, week_label, ranking_points, global_position, snapshot_at)
        SELECT 
            u.id,
            ?,
            u.ranking_points,
            RANK() OVER (ORDER BY u.ranking_points DESC),
            ?
        FROM users u
        WHERE u.username != 'Admin' AND u.ranking_points > 0
    ")->execute([$week_label, $snapshot_time]);

    $saved_count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

    $pdo->commit();

    // ═══════════════════════════════════════
    // 5. Limpar cache de rankings
    // ═══════════════════════════════════════
    ranking_cache_clear_all();

    // ═══════════════════════════════════════
    // RESULTADO
    // ═══════════════════════════════════════
    $result = [
        'success' => true,
        'message' => 'Snapshot semanal guardado com sucesso!',
        'week' => $week_label,
        'snapshot_at' => $snapshot_time,
        'users_saved' => (int)$saved_count,
    ];

    if ($is_cli) {
        echo "[OK] Snapshot semanal concluído — Semana: $week_label\n";
        echo "     Utilizadores guardados: $saved_count\n";
        echo "     Hora: $snapshot_time\n";
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = [
        'success' => false,
        'error' => $e->getMessage(),
    ];

    if ($is_cli) {
        echo "[ERRO] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        echo json_encode($error);
    }
}
