<?php
/**
 * Script para corrigir updated_at das conversas
 * Problema: Após consolidar conversas duplicadas, a coluna updated_at ficou desatualizada
 * Solução: Atualizar updated_at com base na última mensagem de cada conversa
 */

require_once 'includes/config.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
        die('❌ Apenas administradores podem executar este script');
    }
}

echo "\n🔄 Corrigindo updated_at das conversas...\n";
echo str_repeat("=", 60) . "\n";

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    
    $pdo_conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, 
        DB_PASS,
        $options
    );
    
    // Buscar todas as conversas e atualizar updated_at com base na última mensagem
    $sql = "
        UPDATE conversations c
        SET c.updated_at = (
            SELECT MAX(m.created_at)
            FROM messages m
            WHERE m.conversation_id = c.id
        )
        WHERE EXISTS (
            SELECT 1 FROM messages m WHERE m.conversation_id = c.id
        )
    ";
    
    $result = $pdo_conn->exec($sql);
    
    echo "✅ Atualizadas {$result} conversas!\n";
    
    // Verificar conversas sem mensagens (órfãs)
    $orphan_sql = "
        SELECT COUNT(*) as count
        FROM conversations c
        WHERE NOT EXISTS (
            SELECT 1 FROM messages m WHERE m.conversation_id = c.id
        )
    ";
    
    $stmt = $pdo_conn->query($orphan_sql);
    $orphan_count = $stmt->fetch()['count'];
    
    if ($orphan_count > 0) {
        echo "\n⚠️  Encontradas {$orphan_count} conversas sem mensagens (órfãs).\n";
        echo "   Estas conversas não foram atualizadas.\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🎉 Processo concluído!\n";
    echo "As conversas agora devem aparecer na ordem correta.\n\n";
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
?>
