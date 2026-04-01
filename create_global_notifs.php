<?php
require 'includes/config.php';

$pdo->exec("
CREATE TABLE IF NOT EXISTS global_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    message VARCHAR(255) NOT NULL,
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_global_reads (
    user_id INT NOT NULL,
    global_notification_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, global_notification_id)
);
");
echo "Tabelas criadas com sucesso!\n";
