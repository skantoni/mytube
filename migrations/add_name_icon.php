<?php
/**
 * Migration: add name_icon column to users table
 * Run once on production: https://yourdomain/migrations/add_name_icon.php
 * Delete (or block access to) this file afterwards.
 */

// Basic protection
if (!isset($_GET['run']) || $_GET['run'] !== 'yes') {
    die('Add ?run=yes to execute this migration.');
}

require_once __DIR__ . '/../includes/config.php';

$log = [];

try {
    // 1. Add name_icon column if missing
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'name_icon'")->rowCount();
    if ($col) {
        $log[] = "✓ Coluna name_icon já existe.";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN name_icon VARCHAR(255) NULL DEFAULT NULL AFTER profile_picture");
        $log[] = "✓ Coluna name_icon adicionada à tabela users.";
    }

    // 2. Create icons upload directory
    $iconDir = __DIR__ . '/../assets/images/icons';
    if (!is_dir($iconDir)) {
        mkdir($iconDir, 0755, true);
        $log[] = "✓ Diretório assets/images/icons criado.";
    } else {
        $log[] = "✓ Diretório assets/images/icons já existe.";
    }

    $log[] = "\n✅ Migração concluída com sucesso!";
} catch (Exception $e) {
    $log[] = "❌ Erro: " . $e->getMessage();
}

echo '<pre>' . implode("\n", $log) . '</pre>';
