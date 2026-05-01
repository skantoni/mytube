<?php
require_once 'includes/config.php';

// Verificar se é admin (você pode adicionar verificação de admin aqui)
if (!isset($_SESSION['user_id'])) {
    die('Você precisa estar logado como administrador para instalar o chat.');
}

$status = [];
$errors = [];

// Função para executar SQL
function executeSql($conn, $sql, $description) {
    global $status, $errors;
    try {
        $result = $conn->query($sql);
        if ($result) {
            $status[] = "✅ $description";
            return true;
        } else {
            $errors[] = "❌ $description: " . $conn->error;
            return false;
        }
    } catch (Exception $e) {
        $errors[] = "❌ $description: " . $e->getMessage();
        return false;
    }
}

if (isset($_POST['install'])) {
    // Inicializar conexão MySQLi
    $conn = getConn();
    
    // 1. Criar tabela de conversas
    $sql_conversations = "
    CREATE TABLE IF NOT EXISTS conversations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user1_id INT NOT NULL,
        user2_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_conversation (LEAST(user1_id, user2_id), GREATEST(user1_id, user2_id))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    executeSql($conn, $sql_conversations, "Tabela 'conversations' criada");
    
    // 2. Criar tabela de mensagens
    $sql_messages = "
    CREATE TABLE IF NOT EXISTS messages (
        id INT PRIMARY KEY AUTO_INCREMENT,
        conversation_id INT NOT NULL,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        reply_to_message_id INT DEFAULT NULL,
        type ENUM('text', 'audio', 'file', 'sticker') DEFAULT 'text',
        file_url VARCHAR(500) DEFAULT NULL,
        status ENUM('sent', 'delivered', 'read') DEFAULT 'sent',
        deleted_for_sender BOOLEAN DEFAULT FALSE,
        deleted_for_receiver BOOLEAN DEFAULT FALSE,
        deleted_for_all BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reply_to_message_id) REFERENCES messages(id) ON DELETE SET NULL,
        INDEX idx_conversation (conversation_id),
        INDEX idx_sender (sender_id),
        INDEX idx_receiver (receiver_id),
        INDEX idx_created (created_at),
        INDEX idx_status (status),
        INDEX idx_deleted (deleted_for_all)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    executeSql($conn, $sql_messages, "Tabela 'messages' criada");
    
    // 3. Criar tabela de status de digitação
    $sql_typing = "
    CREATE TABLE IF NOT EXISTS typing_status (
        id INT PRIMARY KEY AUTO_INCREMENT,
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        is_typing BOOLEAN DEFAULT FALSE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_typing (conversation_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    executeSql($conn, $sql_typing, "Tabela 'typing_status' criada");
    
    // 4. Criar tabela de status online
    $sql_online = "
    CREATE TABLE IF NOT EXISTS user_online_status (
        user_id INT PRIMARY KEY,
        is_online BOOLEAN DEFAULT FALSE,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_online (is_online, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    executeSql($conn, $sql_online, "Tabela 'user_online_status' criada");
    
    // 5. Inserir status online para usuários existentes
    $sql_insert_status = "
    INSERT IGNORE INTO user_online_status (user_id, is_online, last_seen)
    SELECT id, FALSE, NOW() FROM users";
    
    executeSql($conn, $sql_insert_status, "Status online inicializado para todos os usuários");
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalar Sistema de Chat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .features {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .features h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #333;
        }
        
        .features ul {
            list-style: none;
            padding: 0;
        }
        
        .features li {
            padding: 8px 0;
            color: #555;
            font-size: 14px;
        }
        
        .features li:before {
            content: "✅ ";
            margin-right: 8px;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .status-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 30px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .status-box h3 {
            margin-bottom: 15px;
            color: #333;
            font-size: 16px;
        }
        
        .status-item {
            padding: 8px 0;
            font-family: monospace;
            font-size: 13px;
        }
        
        .success {
            color: #28a745;
        }
        
        .error {
            color: #dc3545;
        }
        
        .complete-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            text-align: center;
        }
        
        .complete-box h3 {
            color: #155724;
            margin-bottom: 10px;
        }
        
        .complete-box a {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
        }
        
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .warning strong {
            color: #856404;
        }
    </style>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body>
    <div class="container">
        <h1>💬 Instalador do Sistema de Chat</h1>
        <p class="subtitle">Instale todas as funcionalidades de chat em tempo real</p>
        
        <?php if (!isset($_POST['install'])): ?>
            <div class="features">
                <h2>Funcionalidades que serão instaladas:</h2>
                <ul>
                    <li>Mensagens em tempo real</li>
                    <li>"Fulano está digitando..."</li>
                    <li>Status online/offline</li>
                    <li>Confirmações de mensagem (✔ Enviado, ✔✔ Entregue, ✔✔ Lido)</li>
                    <li>Responder mensagens (Reply)</li>
                    <li>Apagar para mim / para todos</li>
                    <li>Interface moderna estilo WhatsApp</li>
                    <li>Suporte para áudio, arquivos e stickers</li>
                </ul>
            </div>
            
            <div class="warning">
                <strong>⚠️ Atenção:</strong> Este script criará as tabelas necessárias no banco de dados. 
                É seguro executar mesmo se as tabelas já existirem (usa CREATE TABLE IF NOT EXISTS).
            </div>
            
            <form method="POST">
                <button type="submit" name="install" class="btn">
                    🚀 Instalar Agora
                </button>
            </form>
        <?php else: ?>
            <div class="status-box">
                <h3>📋 Status da Instalação:</h3>
                <?php foreach ($status as $msg): ?>
                    <div class="status-item success"><?php echo $msg; ?></div>
                <?php endforeach; ?>
                
                <?php foreach ($errors as $msg): ?>
                    <div class="status-item error"><?php echo $msg; ?></div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($errors)): ?>
                <div class="complete-box">
                    <h3>✅ Instalação Concluída com Sucesso!</h3>
                    <p>Todas as tabelas foram criadas e o sistema está pronto para uso.</p>
                    <a href="chat.php">Acessar o Chat</a>
                    <a href="index.php" style="background: #667eea; margin-left: 10px;">Voltar ao Início</a>
                </div>
            <?php else: ?>
                <div class="warning" style="background: #f8d7da; border-color: #f5c6cb; margin-top: 20px;">
                    <strong>⚠️ Alguns erros ocorreram durante a instalação.</strong><br>
                    Verifique os erros acima e tente novamente.
                </div>
                <form method="POST" style="margin-top: 20px;">
                    <button type="submit" name="install" class="btn">
                        🔄 Tentar Novamente
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
