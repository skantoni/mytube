<?php
/**
 * Script para atualizar a estrutura da tabela comments
 * Adiciona a coluna parent_comment_id para suporte a respostas de comentários
 */

require_once 'includes/config.php';

echo "Iniciando atualização da base de dados...\n";

try {
    // Verificar se a coluna parent_comment_id já existe
    $stmt = $pdo->query("SHOW COLUMNS FROM comments LIKE 'parent_comment_id'");
    $column_exists = $stmt->rowCount() > 0;
    
    if ($column_exists) {
        echo "✓ A coluna parent_comment_id já existe na tabela comments.\n";
    } else {
        echo "Adicionando coluna parent_comment_id à tabela comments...\n";
        $pdo->exec("ALTER TABLE comments ADD COLUMN parent_comment_id INT NULL AFTER comment_text");
    }

    // Garantir chave estrangeira para parent_comment_id
    $fkExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints 
                               WHERE constraint_schema = DATABASE() 
                               AND table_name = 'comments' 
                               AND constraint_name = 'fk_comments_parent'");
    $fkExists->execute();
    if (!$fkExists->fetchColumn()) {
        echo "Adicionando FK fk_comments_parent...\n";
        $pdo->exec("ALTER TABLE comments 
                     ADD CONSTRAINT fk_comments_parent 
                     FOREIGN KEY (parent_comment_id) REFERENCES comments(id) ON DELETE CASCADE");
    }

    // Garantir índice em parent_comment_id
    $idxParent = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics 
                                WHERE table_schema = DATABASE() 
                                AND table_name = 'comments' 
                                AND index_name = 'idx_parent'");
    $idxParent->execute();
    if (!$idxParent->fetchColumn()) {
        echo "Adicionando índice idx_parent...\n";
        $pdo->exec("ALTER TABLE comments ADD INDEX idx_parent (parent_comment_id)");
    }

    // Garantir índice composto video_id + created_at
    $idxVideoCreated = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics 
                                      WHERE table_schema = DATABASE() 
                                      AND table_name = 'comments' 
                                      AND index_name = 'idx_video_created'");
    $idxVideoCreated->execute();
    if (!$idxVideoCreated->fetchColumn()) {
        echo "Adicionando índice idx_video_created...\n";
        $pdo->exec("ALTER TABLE comments ADD INDEX idx_video_created (video_id, created_at)");
    }

    // Garantir suporte a boost de vídeos
    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'is_boosted'");
    $boostColumnExists = $stmt->rowCount() > 0;

    if ($boostColumnExists) {
        echo "✓ A coluna is_boosted já existe na tabela videos.\n";
    } else {
        echo "Adicionando coluna is_boosted à tabela videos...\n";
        $pdo->exec("ALTER TABLE videos ADD COLUMN is_boosted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public");
    }

    // Verificar a estrutura atual da tabela
    echo "\nEstrutura atual da tabela comments:\n";
    $stmt = $pdo->query("DESCRIBE comments");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$row['Field']}: {$row['Type']} " . 
             ($row['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . 
             ($row['Key'] ? " ({$row['Key']})" : '') . "\n";
    }
    
    echo "\n✓ Atualização da base de dados concluída com sucesso!\n";
    echo "Agora você pode usar a funcionalidade de comentários aninhados.\n";
    
} catch (PDOException $e) {
    echo "✗ Erro ao atualizar a base de dados: " . $e->getMessage() . "\n";
    echo "Detalhes do erro: " . $e->getTraceAsString() . "\n";
}