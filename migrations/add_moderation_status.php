<?php
/**
 * Migration: Add content moderation columns to videos table.
 *
 * moderation_status: 'pending'  = aguarda análise
 *                    'approved' = conteúdo limpo, visível publicamente
 *                    'rejected' = conteúdo 18+ detetado, oculto
 *
 * Executa via cron ou manualmente: php migrations/add_moderation_status.php
 */

require_once __DIR__ . '/../includes/config.php';

try {
    // Verificar se as colunas já existem
    $check = $pdo->query("SHOW COLUMNS FROM videos LIKE 'moderation_status'");
    if ($check->rowCount() > 0) {
        echo "As colunas de moderação já existem na tabela videos. Nada a fazer.\n";
        exit(0);
    }

    $pdo->exec("
        ALTER TABLE videos
            ADD COLUMN moderation_status  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_boosted,
            ADD COLUMN moderation_score   FLOAT          DEFAULT NULL            AFTER moderation_status,
            ADD COLUMN moderation_checked_at DATETIME   DEFAULT NULL            AFTER moderation_score,
            ADD INDEX idx_moderation_status (moderation_status)
    ");

    // Vídeos já existentes ficam como 'approved' (não queremos esconder o conteúdo existente)
    echo "Migration concluída: colunas moderation_status, moderation_score e moderation_checked_at adicionadas.\n";
    echo "Todos os vídeos existentes foram marcados como 'approved'.\n";
    echo "Novos uploads serão analisados pelo NudeNet antes de ficarem visíveis.\n";

} catch (PDOException $e) {
    echo "Erro na migration: " . $e->getMessage() . "\n";
    exit(1);
}
