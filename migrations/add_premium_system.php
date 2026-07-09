<?php
/**
 * Migration: Sistema Premium Completo
 * - Adiciona coluna stream_key à tabela users
 * - Adiciona coluna premium_since e premium_expires
 * - Cria tabela premium_requests (pedidos de subscrição via Express)
 *
 * Run once: https://yourdomain/migrations/add_premium_system.php?run=yes
 * Delete afterwards.
 */
if (!isset($_GET['run']) || $_GET['run'] !== 'yes') {
    die('Add ?run=yes to execute.');
}

require_once __DIR__ . '/../includes/config.php';

$log = [];

try {
    // ── 1. Coluna stream_key na tabela users ──────────────────
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'stream_key'")->rowCount();
    if ($col) {
        $log[] = "✓ Coluna stream_key já existe.";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN stream_key VARCHAR(64) NULL DEFAULT NULL AFTER is_premium");
        $pdo->exec("ALTER TABLE users ADD UNIQUE INDEX idx_stream_key (stream_key)");
        $log[] = "✓ Coluna stream_key adicionada.";
    }

    // ── 2. Coluna premium_since ───────────────────────────────
    $col2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'premium_since'")->rowCount();
    if ($col2) {
        $log[] = "✓ Coluna premium_since já existe.";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN premium_since DATETIME NULL DEFAULT NULL AFTER stream_key");
        $log[] = "✓ Coluna premium_since adicionada.";
    }

    // ── 3. Coluna premium_expires ─────────────────────────────
    $col3 = $pdo->query("SHOW COLUMNS FROM users LIKE 'premium_expires'")->rowCount();
    if ($col3) {
        $log[] = "✓ Coluna premium_expires já existe.";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN premium_expires DATETIME NULL DEFAULT NULL AFTER premium_since");
        $log[] = "✓ Coluna premium_expires adicionada.";
    }

    // ── 4. Tabela premium_requests ────────────────────────────
    $tbl = $pdo->query("SHOW TABLES LIKE 'premium_requests'")->rowCount();
    if ($tbl) {
        $log[] = "✓ Tabela premium_requests já existe.";
    } else {
        $pdo->exec("
            CREATE TABLE premium_requests (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id       INT UNSIGNED NOT NULL,
                plan_months   TINYINT UNSIGNED NOT NULL DEFAULT 1,
                amount_kz     DECIMAL(10,2) NOT NULL,
                express_ref   VARCHAR(100) NOT NULL COMMENT 'Referência/comprovativo informado pelo user',
                phone         VARCHAR(30) NOT NULL COMMENT 'Telemóvel do utilizador para contacto',
                status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                notes         TEXT NULL COMMENT 'Notas internas (admin)',
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at   DATETIME NULL DEFAULT NULL,
                reviewed_by   INT UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_pr_user   (user_id),
                INDEX idx_pr_status (status),
                INDEX idx_pr_date   (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log[] = "✓ Tabela premium_requests criada.";
    }

    $log[] = "\n✅ Migração concluída!";
} catch (Exception $e) {
    $log[] = "❌ Erro: " . $e->getMessage();
}

echo '<pre>' . implode("\n", $log) . '</pre>';
