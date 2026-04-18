<?php
/**
 * Script para corrigir emails duplicados
 * 
 * Este script:
 * 1. Identifica emails duplicados na tabela users
 * 2. Para cada email duplicado, mantém apenas a conta mais antiga (menor ID)
 * 3. Move dados relevantes (vídeos, seguidores, etc) para a conta principal
 * 4. Deleta as contas duplicadas
 * 5. Adiciona constraint UNIQUE no email se não existir
 */

require_once 'includes/config.php';

echo "<pre>";
echo "==========================================\n";
echo "CORREÇÃO DE EMAILS DUPLICADOS\n";
echo "==========================================\n\n";

try {
    // PASSO 1: Identificar emails duplicados
    echo "PASSO 1: Identificando emails duplicados...\n";
    $stmt = $pdo->query("
        SELECT email, COUNT(*) as count, GROUP_CONCAT(id ORDER BY id) as user_ids
        FROM users
        GROUP BY email
        HAVING count > 1
        ORDER BY count DESC
    ");
    
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($duplicates)) {
        echo "✓ Não foram encontrados emails duplicados!\n\n";
    } else {
        echo "✗ Encontrados " . count($duplicates) . " emails duplicados:\n";
        foreach ($duplicates as $dup) {
            echo "  - {$dup['email']}: {$dup['count']} contas (IDs: {$dup['user_ids']})\n";
        }
        echo "\n";
        
        // PASSO 2: Processar cada email duplicado
        echo "PASSO 2: Processando emails duplicados...\n";
        $pdo->beginTransaction();
        
        foreach ($duplicates as $dup) {
            $email = $dup['email'];
            $user_ids = explode(',', $dup['user_ids']);
            $keep_id = (int)$user_ids[0]; // Mantém o ID mais antigo
            $delete_ids = array_slice($user_ids, 1); // IDs para deletar
            
            echo "\nProcessando email: {$email}\n";
            echo "  → Mantendo conta ID: {$keep_id}\n";
            echo "  → Removendo contas IDs: " . implode(', ', $delete_ids) . "\n";
            
            // Para cada conta duplicada
            foreach ($delete_ids as $delete_id) {
                $delete_id = (int)$delete_id;
                
                // Migrar vídeos
                $stmt = $pdo->prepare("UPDATE videos SET user_id = ? WHERE user_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
                $videos_moved = $stmt->rowCount();
                if ($videos_moved > 0) {
                    echo "    • Migrados {$videos_moved} vídeos\n";
                }
                
                // Migrar comentários
                $stmt = $pdo->prepare("UPDATE comments SET user_id = ? WHERE user_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
                $comments_moved = $stmt->rowCount();
                if ($comments_moved > 0) {
                    echo "    • Migrados {$comments_moved} comentários\n";
                }
                
                // Migrar likes de vídeos (evitar duplicados)
                $stmt = $pdo->prepare("
                    DELETE vl1 FROM video_likes vl1
                    INNER JOIN video_likes vl2 
                    WHERE vl1.user_id = ? 
                    AND vl2.user_id = ? 
                    AND vl1.video_id = vl2.video_id
                ");
                $stmt->execute([$delete_id, $keep_id]);
                
                $stmt = $pdo->prepare("UPDATE video_likes SET user_id = ? WHERE user_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
                $likes_moved = $stmt->rowCount();
                if ($likes_moved > 0) {
                    echo "    • Migrados {$likes_moved} likes de vídeos\n";
                }
                
                // Migrar seguidores (evitar duplicados)
                $stmt = $pdo->prepare("
                    DELETE f1 FROM follows f1
                    INNER JOIN follows f2 
                    WHERE f1.follower_id = ? 
                    AND f2.follower_id = ? 
                    AND f1.followed_id = f2.followed_id
                ");
                $stmt->execute([$delete_id, $keep_id]);
                
                $stmt = $pdo->prepare("UPDATE follows SET follower_id = ? WHERE follower_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
                $followers_moved = $stmt->rowCount();
                if ($followers_moved > 0) {
                    echo "    • Migrados {$followers_moved} seguidores\n";
                }
                
                // Migrar seguindo (evitar duplicados)
                $stmt = $pdo->prepare("
                    DELETE f1 FROM follows f1
                    INNER JOIN follows f2 
                    WHERE f1.followed_id = ? 
                    AND f2.followed_id = ? 
                    AND f1.follower_id = f2.follower_id
                ");
                $stmt->execute([$delete_id, $keep_id]);
                
                $stmt = $pdo->prepare("UPDATE follows SET followed_id = ? WHERE followed_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
                $following_moved = $stmt->rowCount();
                if ($following_moved > 0) {
                    echo "    • Migrados {$following_moved} seguindo\n";
                }
                
                // Migrar notificações
                $stmt = $pdo->prepare("UPDATE notifications SET user_id = ? WHERE user_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
                
                $stmt = $pdo->prepare("UPDATE notifications SET from_user_id = ? WHERE from_user_id = ?");
                $stmt->execute([$keep_id, $delete_id]);
                
                // Deletar conta duplicada
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$delete_id]);
                echo "    ✓ Conta ID {$delete_id} removida\n";
            }
            
            // Atualizar contadores da conta principal
            // Contar vídeos
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM videos WHERE user_id = ?");
            $stmt->execute([$keep_id]);
            $videos_count = $stmt->fetchColumn();
            
            // Contar seguidores
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE followed_id = ?");
            $stmt->execute([$keep_id]);
            $followers_count = $stmt->fetchColumn();
            
            // Contar seguindo
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
            $stmt->execute([$keep_id]);
            $following_count = $stmt->fetchColumn();
            
            // Atualizar contadores
            $stmt = $pdo->prepare("
                UPDATE users 
                SET videos_count = ?, 
                    followers_count = ?, 
                    following_count = ? 
                WHERE id = ?
            ");
            $stmt->execute([$videos_count, $followers_count, $following_count, $keep_id]);
            
            echo "  ✓ Contadores atualizados: {$videos_count} vídeos, {$followers_count} seguidores, {$following_count} seguindo\n";
        }
        
        $pdo->commit();
        echo "\n✓ Todos os emails duplicados foram processados com sucesso!\n\n";
    }
    
    // PASSO 3: Verificar e adicionar constraint UNIQUE se necessário
    echo "PASSO 3: Verificando constraint UNIQUE no email...\n";
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM information_schema.table_constraints 
        WHERE constraint_schema = DATABASE() 
        AND table_name = 'users' 
        AND constraint_name = 'email'
        AND constraint_type = 'UNIQUE'
    ");
    
    $unique_exists = $stmt->fetchColumn() > 0;
    
    if ($unique_exists) {
        echo "✓ Constraint UNIQUE no email já existe!\n";
    } else {
        echo "Adicionando constraint UNIQUE no email...\n";
        try {
            $pdo->exec("ALTER TABLE users ADD UNIQUE KEY email (email)");
            echo "✓ Constraint UNIQUE adicionado com sucesso!\n";
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                echo "✗ Ainda existem emails duplicados! Execute o script novamente.\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n==========================================\n";
    echo "MIGRAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo "==========================================\n";
    echo "\nPróximos passos:\n";
    echo "1. Verificar se as contas mantidas estão corretas\n";
    echo "2. Atualizar o código de reset de senha para usar user_id\n";
    echo "3. Remover a lógica de múltiplos emails do login.php\n\n";
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n✗ ERRO: " . $e->getMessage() . "\n";
    echo "Detalhes: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";
