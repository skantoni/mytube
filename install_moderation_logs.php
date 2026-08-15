<?php
require_once __DIR__ . '/includes/config.php';

echo "A criar tabela moderation_logs...\n";

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS `moderation_logs` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `video_id` INT NOT NULL,
      `user_id` INT NOT NULL,
      `moderator_id` INT DEFAULT NULL,
      `action` VARCHAR(50) NOT NULL,
      `details` TEXT DEFAULT NULL,
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_modlogs_video_id` (`video_id`),
      INDEX `idx_modlogs_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $pdo->exec($sql);
    echo "Tabela moderation_logs criada com sucesso!\n";
} catch (PDOException $e) {
    echo "ERRO ao criar a tabela: " . $e->getMessage() . "\n";
}
