<?php
/**
 * migrations/add_whatsapp_verification.php
 *
 * Migração para adicionar suporte a verificação via WhatsApp.
 * Executar UMA VEZ no terminal:
 *   php migrations/add_whatsapp_verification.php
 *
 * Ou aceder via browser: /migrations/add_whatsapp_verification.php
 * (protegido por CLI ou IP local)
 */

// Proteger contra execução pública
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        die('Acesso negado. Execute via CLI: php migrations/add_whatsapp_verification.php');
    }
}

require_once __DIR__ . '/../includes/config.php';

$steps = [];
$errors = [];

// ── Passo 1: Adicionar coluna whatsapp_number à tabela users ──────────────────
try {
    // Verificar se a coluna já existe
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'whatsapp_number'");
    if ($check->rowCount() === 0) {
        $pdo->exec("
            ALTER TABLE users
            ADD COLUMN whatsapp_number VARCHAR(25) UNIQUE DEFAULT NULL AFTER email,
            ADD COLUMN whatsapp_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_number
        ");
        $steps[] = '✅ Colunas whatsapp_number e whatsapp_verified adicionadas à tabela users.';
    } else {
        $steps[] = '⏭️  Colunas whatsapp já existem na tabela users. Pulando.';
    }
} catch (PDOException $e) {
    $errors[] = 'Erro ao alterar tabela users: ' . $e->getMessage();
}

// ── Passo 2: Criar tabela whatsapp_verifications ──────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_verifications (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            phone      VARCHAR(25) NOT NULL,
            code       VARCHAR(6)  NOT NULL,
            used       TINYINT(1)  NOT NULL DEFAULT 0,
            expires_at DATETIME    NOT NULL,
            created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone (phone),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $steps[] = '✅ Tabela whatsapp_verifications criada (ou já existia).';
} catch (PDOException $e) {
    $errors[] = 'Erro ao criar tabela whatsapp_verifications: ' . $e->getMessage();
}

// ── Passo 3: Tornar email opcional na tabela users ────────────────────────────
try {
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'");
    $col = $check->fetch();
    // Só modificar se email ainda for NOT NULL sem default
    if ($col && $col['Null'] === 'NO' && $col['Default'] === null) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN email VARCHAR(255) DEFAULT NULL");
        $steps[] = '✅ Coluna email tornada opcional (NULL permitido).';
    } else {
        $steps[] = '⏭️  Coluna email já aceita NULL. Pulando.';
    }
} catch (PDOException $e) {
    $errors[] = 'Erro ao modificar coluna email: ' . $e->getMessage();
}

// ── Relatório ──────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli') {
    echo "\n=== Migração WhatsApp ===\n\n";
    foreach ($steps as $s) echo $s . "\n";
    if ($errors) {
        echo "\n--- ERROS ---\n";
        foreach ($errors as $e) echo $e . "\n";
        exit(1);
    }
    echo "\n✅ Migração concluída com sucesso!\n\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== Migração WhatsApp ===\n\n";
    foreach ($steps as $s) echo $s . "\n";
    if ($errors) {
        echo "\n--- ERROS ---\n";
        foreach ($errors as $e) echo $e . "\n";
    } else {
        echo "\n✅ Migração concluída com sucesso!\n";
    }
}
