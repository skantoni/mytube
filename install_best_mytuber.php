<?php
/**
 * Instalador do Sistema "Best MyTuber da Semana"
 * Cria a tabela best_mytuber_weekly para guardar histórico de vencedores
 */
require_once 'includes/config.php';

if (!isLoggedIn()) {
    die('Acesso negado. Faça login primeiro.');
}

// Verificar admin
$admin_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$admin_stmt->execute([$_SESSION['user_id']]);
$current_user = $admin_stmt->fetch();
if ($current_user['username'] !== 'Admin') {
    die('Apenas o admin pode executar esta instalação.');
}

$results = [];

try {
    // ═══════════════════════════════════════
    // Tabela principal: best_mytuber_weekly
    // Guarda histórico de vencedores semanais
    // ═══════════════════════════════════════
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `best_mytuber_weekly` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `week_start` date NOT NULL COMMENT 'Segunda-feira da semana',
            `week_end` date NOT NULL COMMENT 'Sexta-feira da semana',
            `scope` enum('global','school') NOT NULL DEFAULT 'global',
            `school_id` int(11) DEFAULT NULL COMMENT 'NULL para global, ID para escola',
            `total_score` decimal(10,2) NOT NULL DEFAULT 0,
            `consistency_score` decimal(8,2) DEFAULT 0 COMMENT 'Pontos por consistência (vídeos publicados)',
            `quality_score` decimal(8,2) DEFAULT 0 COMMENT 'Pontos por qualidade (likes/views ratio)',
            `engagement_score` decimal(8,2) DEFAULT 0 COMMENT 'Pontos por engajamento real',
            `impact_score` decimal(8,2) DEFAULT 0 COMMENT 'Pontos por impacto na escola',
            `behavior_score` decimal(8,2) DEFAULT 0 COMMENT 'Pontos por comportamento positivo',
            `cooldown_penalty` decimal(5,2) DEFAULT 0 COMMENT 'Penalidade aplicada (0.20 = 20%)',
            `rising_star_bonus` tinyint(1) DEFAULT 0 COMMENT 'Se recebeu bônus rising star',
            `videos_count` int(11) DEFAULT 0,
            `total_likes` int(11) DEFAULT 0,
            `total_views` int(11) DEFAULT 0,
            `total_comments` int(11) DEFAULT 0,
            `new_followers` int(11) DEFAULT 0,
            `badge_visible_from` datetime NOT NULL COMMENT 'Sexta 20:00',
            `badge_visible_until` datetime NOT NULL COMMENT 'Domingo 23:59',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user_week` (`user_id`, `week_start`),
            KEY `idx_scope_week` (`scope`, `week_start`),
            KEY `idx_school_week` (`school_id`, `week_start`),
            KEY `idx_badge_visibility` (`badge_visible_from`, `badge_visible_until`),
            UNIQUE KEY `uk_scope_school_week` (`scope`, `school_id`, `week_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = '✅ Tabela best_mytuber_weekly criada com sucesso!';

    // ═══════════════════════════════════════
    // Tabela de log: guarda todos os candidatos
    // e seus scores para transparência
    // ═══════════════════════════════════════
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `best_mytuber_candidates` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `week_start` date NOT NULL,
            `scope` enum('global','school') NOT NULL DEFAULT 'global',
            `school_id` int(11) DEFAULT NULL,
            `user_id` int(11) NOT NULL,
            `raw_score` decimal(10,2) DEFAULT 0 COMMENT 'Score antes de cooldown/bônus',
            `final_score` decimal(10,2) DEFAULT 0 COMMENT 'Score final com ajustes',
            `consistency_score` decimal(8,2) DEFAULT 0,
            `quality_score` decimal(8,2) DEFAULT 0,
            `engagement_score` decimal(8,2) DEFAULT 0,
            `impact_score` decimal(8,2) DEFAULT 0,
            `behavior_score` decimal(8,2) DEFAULT 0,
            `cooldown_penalty` decimal(5,2) DEFAULT 0,
            `rising_star_bonus` tinyint(1) DEFAULT 0,
            `videos_count` int(11) DEFAULT 0,
            `position` int(11) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_week_scope` (`week_start`, `scope`, `school_id`),
            KEY `idx_user_week` (`user_id`, `week_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = '✅ Tabela best_mytuber_candidates criada com sucesso!';

} catch (Exception $e) {
    $results[] = '❌ Erro: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalar Best MyTuber - MyTube</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; padding: 40px; max-width: 700px; margin: 0 auto; }
        h1 { color: #fbbf24; }
        .result { background: #1e293b; padding: 16px 20px; border-radius: 12px; margin: 12px 0; border-left: 4px solid #22c55e; }
        .result.error { border-left-color: #ef4444; }
        a { color: #60a5fa; text-decoration: none; }
        .info { background: #1e293b; padding: 20px; border-radius: 12px; margin-top: 24px; border: 1px solid rgba(59, 130, 246, 0.3); }
        .info h3 { color: #60a5fa; margin-top: 0; }
        .info p { font-size: 14px; line-height: 1.6; color: #94a3b8; }
        code { background: #334155; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
    </style>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body>
    <h1>🏆 Instalação - Best MyTuber da Semana</h1>
    
    <?php foreach ($results as $r): ?>
        <div class="result <?php echo str_contains($r, '❌') ? 'error' : ''; ?>">
            <?php echo $r; ?>
        </div>
    <?php endforeach; ?>

    <div class="info">
        <h3>📋 Como funciona?</h3>
        <p>
            <strong>Cálculo:</strong> Chame <code>api/calculate_best_mytuber.php</code> (via cron ou manualmente) toda sexta-feira às 20h.<br><br>
            <strong>Janela de dados:</strong> Segunda 00:00 → Sexta 19:59<br>
            <strong>Badge visível:</strong> Sexta 20:00 → Domingo 23:59<br><br>
            <strong>Anti-monopolio:</strong> Cooldown progressivo (-20%, -30%, -40%) + bônus Rising Star (+15%)<br><br>
            <strong>Consultar vencedores:</strong> <code>api/get_best_mytuber.php?action=current</code>
        </p>
    </div>
    
    <p style="margin-top: 24px;">
        <a href="ranking.php">← Voltar para Rankings</a>
    </p>
</body>
</html>
