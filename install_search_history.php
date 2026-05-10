<?php
require_once __DIR__ . '/includes/config.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_history (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            query       VARCHAR(255) NOT NULL,
            searched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_searched (user_id, searched_at),
            INDEX idx_cleanup       (searched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK: tabela search_history criada/verificada\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
