<?php
/**
 * Migration: Adicionar índices para suportar polling otimizado com muitos utilizadores
 * 
 * Executar uma vez: http://localhost/my/update_indexes.php
 */
require_once 'includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

$indexes = [
    // Índice composto para contagem de notificações não lidas (polling cada 45s por utilizador)
    "ALTER TABLE notifications ADD KEY idx_user_read (user_id, is_read)",
    
    // Covering index para listagem de notificações (user + is_read + created_at)
    "ALTER TABLE notifications ADD KEY idx_user_read_created (user_id, is_read, created_at)",
    
    // Índice para updated_at em comments (usado pelo sync de comentários editados)
    "ALTER TABLE comments ADD KEY idx_video_updated (video_id, updated_at)",
];

echo "=== MyTube: Migração de Índices ===\n\n";

$success = 0;
$skipped = 0;

foreach ($indexes as $sql) {
    try {
        $pdo->exec($sql);
        echo "[OK] $sql\n";
        $success++;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate key name') !== false) {
            echo "[SKIP] Já existe: $sql\n";
        } else {
            echo "[ERRO] $msg\n";
        }
        $skipped++;
    }
}

echo "\n--- Resultado: $success criados, $skipped ignorados ---\n";
echo "Pode apagar este ficheiro após executar.\n";
