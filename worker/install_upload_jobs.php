<?php
/**
 * install_upload_jobs.php
 *
 * Migração: cria a tabela `upload_jobs` e a pasta `uploads/raw_queue/`.
 * Executa uma vez no servidor:
 *   php /var/www/mytube.social/worker/install_upload_jobs.php
 */

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/includes/config.php';

echo "=== MyTube — Instalação do sistema de upload em background ===\n\n";

// ── 1. Criar tabela upload_jobs ──────────────────────────────────────────────
echo "[1/3] Criando tabela upload_jobs...";

$pdo->exec("
    CREATE TABLE IF NOT EXISTS upload_jobs (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        video_id         INT DEFAULT NULL COMMENT 'Preenchido após INSERT inicial na tabela videos',
        tmp_video_path   VARCHAR(700) NOT NULL COMMENT 'Caminho absoluto do ficheiro raw temporário',
        original_name    VARCHAR(255) NOT NULL,
        title            VARCHAR(255) NOT NULL,
        description      TEXT,
        hashtags         VARCHAR(500),
        is_public        TINYINT(1) NOT NULL DEFAULT 1,
        music_track_data TEXT    COMMENT 'JSON da faixa de música selecionada',
        music_mode       VARCHAR(10) NOT NULL DEFAULT 'mix',
        music_volume     FLOAT NOT NULL DEFAULT 0.25,
        music_start      FLOAT NOT NULL DEFAULT 0.0,
        ad_flow          TINYINT(1) NOT NULL DEFAULT 0,
        status           ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
        progress_msg     VARCHAR(255) DEFAULT NULL COMMENT 'Mensagem de progresso para o frontend',
        error_message    TEXT DEFAULT NULL,
        attempts         INT NOT NULL DEFAULT 0,
        created_at       DATETIME NOT NULL DEFAULT NOW(),
        started_at       DATETIME DEFAULT NULL,
        finished_at      DATETIME DEFAULT NULL,
        INDEX idx_status    (status),
        INDEX idx_user_id   (user_id),
        INDEX idx_video_id  (video_id),
        INDEX idx_created   (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Fila de jobs de processamento de vídeo em background'
");

echo " OK\n";

// ── 2. Adicionar coluna moderation_status ao videos se não existir ───────────
echo "[2/3] Verificando colunas de moderação na tabela videos...";

$cols = [];
$stmt = $pdo->query("SHOW COLUMNS FROM videos");
while ($row = $stmt->fetch()) {
    $cols[] = $row['Field'];
}

if (!in_array('moderation_status', $cols, true)) {
    $pdo->exec("ALTER TABLE videos
        ADD COLUMN moderation_status ENUM('processing','pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_public,
        ADD COLUMN moderation_score  FLOAT DEFAULT NULL AFTER moderation_status,
        ADD COLUMN moderation_checked_at DATETIME DEFAULT NULL AFTER moderation_score
    ");
    echo " colunas adicionadas OK\n";
} else {
    // Garantir que o valor 'processing' existe no ENUM
    try {
        $pdo->exec("ALTER TABLE videos
            MODIFY COLUMN moderation_status ENUM('processing','pending','approved','rejected') NOT NULL DEFAULT 'approved'
        ");
    } catch (Throwable $e) {
        // Já existe — ignorar
    }
    echo " já existem OK\n";
}

// ── 3. Criar directório raw_queue ────────────────────────────────────────────
echo "[3/3] Criando pasta uploads/raw_queue/...";

$raw_queue_dir = ROOT_DIR . '/uploads/raw_queue';
if (!is_dir($raw_queue_dir)) {
    if (mkdir($raw_queue_dir, 0750, true)) {
        echo " criada OK\n";
    } else {
        echo " ERRO ao criar. Cria manualmente:\n";
        echo "       mkdir -p /var/www/mytube.social/uploads/raw_queue\n";
        echo "       chown www-data:www-data /var/www/mytube.social/uploads/raw_queue\n";
        echo "       chmod 750 /var/www/mytube.social/uploads/raw_queue\n";
    }
} else {
    echo " já existe OK\n";
}

// Proteger o directório raw_queue com .htaccess (nega acesso HTTP directo)
$htaccess = $raw_queue_dir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
    echo "    .htaccess de protecção criado.\n";
}

echo "\n=== Instalação concluída! ===\n\n";
echo "Próximos passos:\n";
echo "  1. Adicionar ao crontab do servidor:\n";
echo "     * * * * * php /var/www/mytube.social/worker/process_upload_job.php >> /var/log/mytube_worker.log 2>&1\n";
echo "  2. Verificar permissões:\n";
echo "     chown www-data:www-data /var/www/mytube.social/uploads/raw_queue\n";
echo "     chmod 750 /var/www/mytube.social/uploads/raw_queue\n";
echo "\n";
