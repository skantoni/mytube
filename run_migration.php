<?php
/**
 * run_migration.php — Script CLI para criar a tabela user_login_history
 * Uso: php run_migration.php
 */
if (php_sapi_name() !== 'cli') {
    die("Só pode ser executado via CLI\n");
}

require_once 'includes/config.php';

echo "=== Migração: user_login_history ===\n\n";

// 1. Criar tabela
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_login_history (
            id           INT          NOT NULL AUTO_INCREMENT,
            user_id      INT          NOT NULL,
            logged_in_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address   VARCHAR(45)  NULL,
            user_agent   VARCHAR(500) NULL,
            PRIMARY KEY (id),
            KEY idx_user_logged (user_id, logged_in_at),
            KEY idx_logged_at   (logged_in_at),
            CONSTRAINT fk_ulh_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] Tabela user_login_history criada (ou ja existia).\n";
} catch (PDOException $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Semente: 1 registo por utilizador com created_at como 1.º login
try {
    $inserted = $pdo->exec("
        INSERT IGNORE INTO user_login_history (user_id, logged_in_at, ip_address, user_agent)
        SELECT id, created_at, NULL, 'seed:account_created'
        FROM   users
        WHERE  id NOT IN (SELECT DISTINCT user_id FROM user_login_history)
    ");
    echo "[OK] Semente: {$inserted} registos iniciais inseridos.\n";
} catch (PDOException $e) {
    echo "[ERRO semente] " . $e->getMessage() . "\n";
}

// 3. Relatório
$total = (int) $pdo->query("SELECT COUNT(*) FROM user_login_history")->fetchColumn();
$users = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_login_history")->fetchColumn();

echo "\n--- Resultado ---\n";
echo "Total de registos : {$total}\n";
echo "Utilizadores cobertos: {$users}\n";
echo "\n[CONCLUIDO] Pode ir a boosted_videos.php#users\n";
