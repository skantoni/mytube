<?php
/**
 * Script de migração para adicionar suporte a reencaminhamento de mensagens
 * Adiciona colunas forwarded_from_message_id e forwarded_from_user_id à tabela messages
 */

require_once 'includes/config.php';

echo "<h2>🔄 Instalação - Reencaminhamento de Mensagens</h2>";

try {
    // Verificar se as colunas já existem
    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'forwarded_from_message_id'");
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "<p>✅ Colunas de reencaminhamento já existem. Nada a fazer.</p>";
    } else {
        // Adicionar coluna forwarded_from_message_id
        $pdo->exec("
            ALTER TABLE messages 
            ADD COLUMN forwarded_from_message_id INT(11) DEFAULT NULL AFTER is_edited,
            ADD COLUMN forwarded_from_user_id INT(11) DEFAULT NULL AFTER forwarded_from_message_id,
            ADD COLUMN forwarded_from_username VARCHAR(100) DEFAULT NULL AFTER forwarded_from_user_id
        ");
        echo "<p>✅ Colunas de reencaminhamento adicionadas com sucesso!</p>";
        
        // Adicionar índice
        $pdo->exec("
            ALTER TABLE messages 
            ADD KEY idx_forwarded (forwarded_from_message_id)
        ");
        echo "<p>✅ Índice adicionado!</p>";
    }
    
    echo "<p>🎉 <strong>Migração concluída com sucesso!</strong></p>";
    echo "<p><a href='chat.php'>← Voltar ao Chat</a></p>";
    
} catch (PDOException $e) {
    echo "<p>❌ Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
