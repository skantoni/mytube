<?php
/**
 * Migration: Create group_messages table and add added_by to chat_participants
 * Run once: http://localhost/my/migrations/create_group_messages.php?run=yes
 * Delete afterwards.
 */

// Allow CLI execution
if (php_sapi_name() === 'cli') {
    $_GET['run'] = 'yes';
}

if (!isset($_GET['run']) || $_GET['run'] !== 'yes') {
    die('Add ?run=yes to execute.');
}

require_once __DIR__ . '/../includes/config.php';

$log = [];
try {
    // 1. Criar tabela group_messages
    $exists = $pdo->query("SHOW TABLES LIKE 'group_messages'")->rowCount();
    if ($exists) {
        $log[] = "✓ Tabela group_messages já existe.";
    } else {
        $pdo->exec("
            CREATE TABLE group_messages (
                id INT(11) NOT NULL AUTO_INCREMENT,
                group_id INT(11) NOT NULL,
                sender_id INT(11) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('text','audio','file','image','video') DEFAULT 'text',
                file_url VARCHAR(500) DEFAULT NULL,
                reply_to_message_id INT(11) DEFAULT NULL,
                is_deleted TINYINT(1) DEFAULT 0,
                is_edited TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_group_id (group_id),
                KEY idx_sender_id (sender_id),
                KEY idx_created_at (created_at),
                CONSTRAINT group_messages_ibfk_1 FOREIGN KEY (group_id) REFERENCES chats (id) ON DELETE CASCADE,
                CONSTRAINT group_messages_ibfk_2 FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log[] = "✓ Tabela group_messages criada.";
    }

    // 2. Adicionar coluna added_by a chat_participants (se não existir)
    $col = $pdo->query("SHOW COLUMNS FROM chat_participants LIKE 'added_by'")->rowCount();
    if ($col) {
        $log[] = "✓ Coluna added_by já existe em chat_participants.";
    } else {
        $pdo->exec("ALTER TABLE chat_participants ADD COLUMN added_by INT(11) DEFAULT NULL AFTER user_id");
        $log[] = "✓ Coluna added_by adicionada a chat_participants.";
    }

    // 3. Garantir que a tabela chats tem a coluna group_picture (opcional)
    $col2 = $pdo->query("SHOW COLUMNS FROM chats LIKE 'group_picture'")->rowCount();
    if ($col2) {
        $log[] = "✓ Coluna group_picture já existe em chats.";
    } else {
        $pdo->exec("ALTER TABLE chats ADD COLUMN group_picture VARCHAR(255) DEFAULT NULL AFTER name");
        $log[] = "✓ Coluna group_picture adicionada a chats.";
    }

    $log[] = "\n✅ Migração concluída!";
} catch (Exception $e) {
    $log[] = "❌ Erro: " . $e->getMessage();
}
echo '<pre>' . implode("\n", $log) . '</pre>';
