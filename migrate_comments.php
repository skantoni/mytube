<?php
/**
 * Script de migração para adicionar parent_comment_id à tabela comments
 * Base de dados: social_network
 */

// Conectar ao banco mytube_db (altere se necessário)
$host = 'localhost';
$dbname = 'mytube_db';
$user = 'root';
$pass = '';

echo "=== Migração da Tabela Comments ===\n";
echo "Base de dados: {$dbname}\n\n";

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✓ Conexão estabelecida\n\n";
    
    // Verificar se a coluna parent_comment_id já existe
    $stmt = $pdo->query("SHOW COLUMNS FROM comments LIKE 'parent_comment_id'");
    $columnExists = $stmt->rowCount() > 0;
    
    if ($columnExists) {
        echo "✓ A coluna parent_comment_id já existe\n";
    } else {
        echo "→ Adicionando coluna parent_comment_id...\n";
        $pdo->exec("ALTER TABLE comments ADD COLUMN parent_comment_id BIGINT UNSIGNED NULL AFTER user_id");
        echo "✓ Coluna parent_comment_id adicionada\n";
    }
    
    // Verificar e adicionar FK fk_comments_parent
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints 
                           WHERE constraint_schema = DATABASE() 
                           AND table_name = 'comments' 
                           AND constraint_name = 'fk_comments_parent'");
    $stmt->execute();
    $fkExists = $stmt->fetchColumn();
    
    if ($fkExists) {
        echo "✓ FK fk_comments_parent já existe\n";
    } else {
        echo "→ Adicionando FK fk_comments_parent...\n";
        
        // Verificar se há FK dependentes e ajustar tipos
        try {
            // Remover FK temporariamente de comment_likes
            $pdo->exec("ALTER TABLE comment_likes DROP FOREIGN KEY comment_likes_ibfk_2");
            echo "  → FK comment_likes_ibfk_2 removida temporariamente\n";
            
            // Ajustar tipos das colunas
            $pdo->exec("ALTER TABLE comments MODIFY COLUMN id BIGINT UNSIGNED AUTO_INCREMENT");
            $pdo->exec("ALTER TABLE comment_likes MODIFY COLUMN comment_id BIGINT UNSIGNED");
            echo "  → Tipos de coluna ajustados para BIGINT UNSIGNED\n";
            
            // Recriar FK de comment_likes
            $pdo->exec("ALTER TABLE comment_likes 
                        ADD CONSTRAINT comment_likes_ibfk_2 
                        FOREIGN KEY (comment_id) 
                        REFERENCES comments(id) 
                        ON DELETE CASCADE");
            echo "  → FK comment_likes_ibfk_2 recriada\n";
            
            // Criar FK para parent_comment_id
            $pdo->exec("ALTER TABLE comments 
                        ADD CONSTRAINT fk_comments_parent 
                        FOREIGN KEY (parent_comment_id) 
                        REFERENCES comments(id) 
                        ON DELETE CASCADE");
            echo "✓ FK fk_comments_parent adicionada\n";
            
        } catch (PDOException $e) {
            // Se parent_comment_id não for BIGINT UNSIGNED, converter
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                echo "  → Ajustando tipos e criando FK sem conversão completa...\n";
                // Tentar abordagem mais simples: usar INT ao invés de BIGINT
                $pdo->exec("ALTER TABLE comments MODIFY COLUMN parent_comment_id INT NULL");
                $pdo->exec("ALTER TABLE comments 
                            ADD CONSTRAINT fk_comments_parent 
                            FOREIGN KEY (parent_comment_id) 
                            REFERENCES comments(id) 
                            ON DELETE CASCADE");
                echo "✓ FK fk_comments_parent adicionada (INT)\n";
            } else {
                throw $e;
            }
        }
    }
    
    // Verificar e adicionar índice idx_parent
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics 
                           WHERE table_schema = DATABASE() 
                           AND table_name = 'comments' 
                           AND index_name = 'idx_parent'");
    $stmt->execute();
    $idxParentExists = $stmt->fetchColumn();
    
    if ($idxParentExists) {
        echo "✓ Índice idx_parent já existe\n";
    } else {
        echo "→ Adicionando índice idx_parent...\n";
        $pdo->exec("ALTER TABLE comments ADD INDEX idx_parent (parent_comment_id)");
        echo "✓ Índice idx_parent adicionado\n";
    }
    
    // Verificar e adicionar índice composto idx_video_created
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics 
                           WHERE table_schema = DATABASE() 
                           AND table_name = 'comments' 
                           AND index_name = 'idx_video_created'");
    $stmt->execute();
    $idxVideoCreatedExists = $stmt->fetchColumn();
    
    if ($idxVideoCreatedExists) {
        echo "✓ Índice idx_video_created já existe\n";
    } else {
        echo "→ Adicionando índice idx_video_created...\n";
        $pdo->exec("ALTER TABLE comments ADD INDEX idx_video_created (video_id, created_at)");
        echo "✓ Índice idx_video_created adicionado\n";
    }
    
    echo "\n=== Estrutura Atualizada ===\n";
    $stmt = $pdo->query("DESCRIBE comments");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-25s %-20s %-8s %-5s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key']
        );
    }
    
    echo "\n✓ Migração concluída com sucesso!\n";
    echo "→ Agora você pode usar comentários hierárquicos (replies)\n";
    
} catch (PDOException $e) {
    echo "\n✗ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
