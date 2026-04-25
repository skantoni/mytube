<?php
/**
 * Migration: add is_premium column to users
 * Run once: https://yourdomain/migrations/add_is_premium.php?run=yes
 * Delete afterwards.
 */
if (!isset($_GET['run']) || $_GET['run'] !== 'yes') {
    die('Add ?run=yes to execute.');
}

require_once __DIR__ . '/../includes/config.php';

$log = [];
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_premium'")->rowCount();
    if ($col) {
        $log[] = "✓ Coluna is_premium já existe.";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_premium TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified");
        $log[] = "✓ Coluna is_premium adicionada à tabela users.";
    }

    $idx = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_premium'")->rowCount();
    if (!$idx) {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_premium (is_premium)");
        $log[] = "✓ Índice idx_premium criado.";
    }

    $log[] = "\n✅ Migração concluída!";
} catch (Exception $e) {
    $log[] = "❌ Erro: " . $e->getMessage();
}
echo '<pre>' . implode("\n", $log) . '</pre>';
