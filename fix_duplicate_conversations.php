<?php
/**
 * Script para limpar conversas duplicadas e aplicar constraint UNIQUE
 * Data: 01/05/2026
 * Problema: Race condition criava múltiplas conversas entre mesmos usuários
 */

require_once 'includes/config.php';

// Verificar se é admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die('❌ Apenas administradores podem executar este script');
}

echo "<h1>🔧 Limpeza de Conversas Duplicadas</h1>";
echo "<pre>";

try {
    $conn = getConn();
    
    // PASSO 1: Identificar conversas duplicadas
    echo "\n📊 PASSO 1: Identificando conversas duplicadas...\n";
    echo str_repeat("=", 60) . "\n";
    
    $sql = "
        SELECT 
            LEAST(user1_id, user2_id) as user_a,
            GREATEST(user1_id, user2_id) as user_b,
            GROUP_CONCAT(id ORDER BY id) as conversation_ids,
            COUNT(*) as duplicate_count
        FROM conversations
        GROUP BY LEAST(user1_id, user2_id), GREATEST(user1_id, user2_id)
        HAVING COUNT(*) > 1
    ";
    
    $result = $conn->query($sql);
    $duplicates = $result->fetch_all(MYSQLI_ASSOC);
    
    if (empty($duplicates)) {
        echo "✅ Nenhuma conversa duplicada encontrada!\n";
    } else {
        echo "⚠️  Encontradas " . count($duplicates) . " pares de usuários com conversas duplicadas:\n\n";
        
        foreach ($duplicates as $dup) {
            $ids = explode(',', $dup['conversation_ids']);
            echo "👥 Usuários {$dup['user_a']} ↔️ {$dup['user_b']}: {$dup['duplicate_count']} conversas\n";
            echo "   IDs: " . $dup['conversation_ids'] . "\n";
            echo "   ➡️  Mantendo conversa #{$ids[0]}, deletando: " . implode(', #', array_slice($ids, 1)) . "\n\n";
        }
        
        // PASSO 2: Consolidar mensagens antes de deletar
        echo "\n📦 PASSO 2: Consolidando mensagens nas conversas primárias...\n";
        echo str_repeat("=", 60) . "\n";
        
        $conn->begin_transaction();
        
        foreach ($duplicates as $dup) {
            $ids = explode(',', $dup['conversation_ids']);
            $primary_id = (int) $ids[0];
            $duplicate_ids = array_slice($ids, 1);
            
            foreach ($duplicate_ids as $dup_id) {
                $dup_id = (int) $dup_id;
                
                // Mover mensagens da conversa duplicada para a primária
                $update_sql = "
                    UPDATE messages 
                    SET conversation_id = ? 
                    WHERE conversation_id = ?
                ";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param('ii', $primary_id, $dup_id);
                $stmt->execute();
                $moved = $stmt->affected_rows;
                
                echo "   ✅ Movidas {$moved} mensagens da conversa #{$dup_id} → #{$primary_id}\n";
            }
        }
        
        // PASSO 3: Deletar conversas duplicadas
        echo "\n🗑️  PASSO 3: Deletando conversas duplicadas...\n";
        echo str_repeat("=", 60) . "\n";
        
        foreach ($duplicates as $dup) {
            $ids = explode(',', $dup['conversation_ids']);
            $duplicate_ids = array_slice($ids, 1);
            
            foreach ($duplicate_ids as $dup_id) {
                $dup_id = (int) $dup_id;
                $delete_sql = "DELETE FROM conversations WHERE id = ?";
                $stmt = $conn->prepare($delete_sql);
                $stmt->bind_param('i', $dup_id);
                $stmt->execute();
                
                echo "   ✅ Deletada conversa #{$dup_id}\n";
            }
        }
        
        $conn->commit();
        echo "\n✅ Conversas consolidadas com sucesso!\n";
    }
    
    // PASSO 4: Adicionar colunas e triggers
    echo "\n🔒 PASSO 4: Adicionando colunas user_min/user_max e triggers...\n";
    echo str_repeat("=", 60) . "\n";
    
    // Verificar se já existem as colunas
    $check_cols_sql = "
        SELECT COUNT(*) as count 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE table_schema = DATABASE() 
        AND table_name = 'conversations' 
        AND column_name IN ('user_min', 'user_max')
    ";
    $result = $conn->query($check_cols_sql);
    $cols_exist = $result->fetch_assoc()['count'] == 2;
    
    if (!$cols_exist) {
        echo "📝 Adicionando colunas user_min e user_max...\n";
        
        // Adicionar colunas
        $conn->query("ALTER TABLE conversations ADD COLUMN user_min INT DEFAULT NULL");
        $conn->query("ALTER TABLE conversations ADD COLUMN user_max INT DEFAULT NULL");
        
        // Popular colunas
        $conn->query("UPDATE conversations SET user_min = LEAST(user1_id, user2_id), user_max = GREATEST(user1_id, user2_id)");
        
        // Tornar NOT NULL
        $conn->query("ALTER TABLE conversations MODIFY COLUMN user_min INT NOT NULL");
        $conn->query("ALTER TABLE conversations MODIFY COLUMN user_max INT NOT NULL");
        
        echo "✅ Colunas adicionadas e populadas.\n";
    } else {
        echo "ℹ️  Colunas já existem.\n";
    }
    
    // Verificar se já existe a constraint
    $check_idx_sql = "
        SELECT COUNT(*) as count 
        FROM INFORMATION_SCHEMA.STATISTICS 
        WHERE table_schema = DATABASE() 
        AND table_name = 'conversations' 
        AND index_name = 'unique_conversation'
    ";
    $result = $conn->query($check_idx_sql);
    $idx_exists = $result->fetch_assoc()['count'] > 0;
    
    if (!$idx_exists) {
        echo "🔐 Adicionando constraint UNIQUE...\n";
        $conn->query("ALTER TABLE conversations ADD UNIQUE KEY unique_conversation (user_min, user_max)");
        echo "✅ Constraint UNIQUE adicionada!\n";
    } else {
        echo "ℹ️  Constraint UNIQUE já existe.\n";
    }
    
    // Criar triggers
    echo "🔧 Criando triggers...\n";
    
    // Dropar triggers se já existirem
    $conn->query("DROP TRIGGER IF EXISTS conversations_before_insert");
    $conn->query("DROP TRIGGER IF EXISTS conversations_before_update");
    
    // Criar trigger INSERT
    $trigger_insert = "
    CREATE TRIGGER conversations_before_insert 
    BEFORE INSERT ON conversations
    FOR EACH ROW
    BEGIN
        SET NEW.user_min = LEAST(NEW.user1_id, NEW.user2_id);
        SET NEW.user_max = GREATEST(NEW.user1_id, NEW.user2_id);
    END
    ";
    $conn->query($trigger_insert);
    
    // Criar trigger UPDATE
    $trigger_update = "
    CREATE TRIGGER conversations_before_update 
    BEFORE UPDATE ON conversations
    FOR EACH ROW
    BEGIN
        SET NEW.user_min = LEAST(NEW.user1_id, NEW.user2_id);
        SET NEW.user_max = GREATEST(NEW.user1_id, NEW.user2_id);
    END
    ";
    $conn->query($trigger_update);
    
    echo "✅ Triggers criados com sucesso!\n";
    echo "   Agora é IMPOSSÍVEL criar conversas duplicadas.\n";
    } else {
        echo "ℹ️  Constraint UNIQUE já existe.\n";
    }
    
    // PASSO 5: Verificação final
    echo "\n✅ PASSO 5: Verificação final...\n";
    echo str_repeat("=", 60) . "\n";
    
    $verify_sql = "
        SELECT 
            COUNT(*) as total_conversations,
            COUNT(DISTINCT CONCAT(user_min, '-', user_max)) as unique_pairs
        FROM conversations
    ";
    $result = $conn->query($verify_sql);
    $stats = $result->fetch_assoc();
    
    echo "📊 Estatísticas:\n";
    echo "   Total de conversas: {$stats['total_conversations']}\n";
    echo "   Pares únicos de usuários: {$stats['unique_pairs']}\n";
    
    if ($stats['total_conversations'] == $stats['unique_pairs']) {
        echo "\n✅✅✅ SUCESSO! Não há mais duplicatas!\n";
    } else {
        echo "\n⚠️  ATENÇÃO: Ainda existem duplicatas. Execute novamente.\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🎉 Processo concluído!\n";
    echo "</pre>";
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    echo "</pre>";
}
?>
