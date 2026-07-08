<?php
/**
 * install_reports.php
 * Cria a tabela video_reports na base de dados.
 * Executar UMA VEZ na VPS: https://mytube.social/install_reports.php
 * Depois APAGAR ou bloquear o acesso.
 */
require_once 'includes/config.php';

// Apenas admin, localhost, ou linha de comandos (CLI) pode executar
$is_cli = (php_sapi_name() === 'cli');
$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
$is_localhost = ($remote_addr === '127.0.0.1' || $remote_addr === '::1');

if (!$is_cli && !$is_localhost && (!isLoggedIn() || !isAdminUser())) {
    http_response_code(403);
    die('Acesso negado.');
}

$results = [];

try {
    // Tabela de denúncias de vídeos
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `video_reports` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `video_id`    INT UNSIGNED NOT NULL,
            `reporter_id` INT UNSIGNED NOT NULL,
            `reason`      ENUM(
                'nudity',
                'violence',
                'copyright',
                'drugs',
                'hate_speech',
                'spam',
                'other'
            ) NOT NULL DEFAULT 'other',
            `details`     TEXT NULL,
            `status`      ENUM('pending','reviewed','dismissed') NOT NULL DEFAULT 'pending',
            `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_report` (`video_id`, `reporter_id`),
            KEY `idx_video_id` (`video_id`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = '✅ Tabela `video_reports` criada com sucesso.';

    // Adicionar coluna reports_count na tabela videos (se não existir)
    try {
        $pdo->exec("ALTER TABLE `videos` ADD COLUMN `reports_count` INT UNSIGNED NOT NULL DEFAULT 0");
        $results[] = '✅ Coluna `reports_count` adicionada à tabela `videos`.';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = 'ℹ️ Coluna `reports_count` já existe (nenhuma ação necessária).';
        } else {
            throw $e;
        }
    }

    // Adicionar coluna is_hidden na tabela videos (se não existir)
    // Usada para ocultar automaticamente vídeos com 3+ denúncias
    try {
        $pdo->exec("ALTER TABLE `videos` ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = '✅ Coluna `is_hidden` adicionada à tabela `videos`.';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = 'ℹ️ Coluna `is_hidden` já existe (nenhuma ação necessária).';
        } else {
            throw $e;
        }
    }

    $results[] = '<br><strong>🎉 Instalação concluída! Podes apagar este ficheiro da VPS.</strong>';

} catch (PDOException $e) {
    $results[] = '❌ Erro: ' . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Instalar Relatórios — MyTube</title>
    <style>
        body { font-family: monospace; background: #111; color: #eee; padding: 40px; }
        p { margin: 8px 0; font-size: 15px; }
    </style>
</head>
<body>
    <h2>📦 Instalação do Sistema de Denúncias</h2>
    <?php foreach ($results as $r) echo "<p>$r</p>"; ?>
</body>
</html>
