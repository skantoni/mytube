<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    die('Você precisa estar logado para instalar.');
}

$status = [];
$errors = [];

function executeSql($pdo, $sql, $description) {
    global $status, $errors;
    try {
        $pdo->exec($sql);
        $status[] = "✅ $description";
        return true;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
            $status[] = "⚠️ $description (já existe)";
            return true;
        }
        $errors[] = "❌ $description: " . $e->getMessage();
        return false;
    }
}

if (isset($_POST['install'])) {
    // Tabela de pedidos de amizade
    executeSql($pdo, "
        CREATE TABLE IF NOT EXISTS friend_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_request (sender_id, receiver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "Tabela 'friend_requests' criada");

    // Índices para performance
    executeSql($pdo, "
        ALTER TABLE friend_requests ADD INDEX idx_receiver_status (receiver_id, status)
    ", "Índice idx_receiver_status criado");

    executeSql($pdo, "
        ALTER TABLE friend_requests ADD INDEX idx_sender_status (sender_id, status)
    ", "Índice idx_sender_status criado");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Instalar Sistema de Pedidos de Amizade</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; background: #1a1a2e; color: #fff; padding: 20px; }
        .status { padding: 8px 12px; margin: 4px 0; background: rgba(255,255,255,0.1); border-radius: 6px; }
        .error { background: rgba(255,0,0,0.2); }
        form { margin: 20px 0; }
        button { padding: 12px 24px; background: #6c63ff; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
        button:hover { background: #5a52d5; }
    </style>
</head>
<body>
    <h1>🤝 Sistema de Pedidos de Amizade</h1>
    
    <?php if (empty($status) && empty($errors)): ?>
        <p>Este script cria a tabela necessária para o sistema de pedidos de amizade.</p>
        <form method="post">
            <button type="submit" name="install">Instalar</button>
        </form>
    <?php else: ?>
        <h2>Resultado:</h2>
        <?php foreach ($status as $s): ?>
            <div class="status"><?php echo $s; ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="status error"><?php echo $e; ?></div>
        <?php endforeach; ?>
        <?php if (empty($errors)): ?>
            <p>✅ Instalação concluída com sucesso!</p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
