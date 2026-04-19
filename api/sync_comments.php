<?php
/**
 * API de Sincronização de Likes em Comentários (Tempo Real)
 * Retorna contadores atualizados de likes para comentários visíveis
 */

session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['comment_ids']) || !is_array($input['comment_ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'comment_ids (array) é obrigatório']);
    exit;
}

$comment_ids = array_map('intval', $input['comment_ids']);

if (empty($comment_ids)) {
    echo json_encode([
        'success' => true,
        'comments' => [],
        'timestamp' => time()
    ]);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($comment_ids), '?'));
    
    // Buscar contadores atualizados dos comentários
    $stmt = $pdo->prepare("
        SELECT 
            id,
            likes_count
        FROM comments 
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($comment_ids);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Se usuário está logado, buscar seus likes
    $user_likes = [];
    if (isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("
            SELECT comment_id 
            FROM comment_likes 
            WHERE user_id = ? AND comment_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$user_id], $comment_ids));
        $user_likes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Formatar resposta
    $result = [];
    foreach ($comments as $comment) {
        $comment_id = (int)$comment['id'];
        $result[$comment_id] = [
            'comment_id' => $comment_id,
            'likes_count' => (int)$comment['likes_count'],
            'user_liked' => in_array($comment_id, $user_likes)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'comments' => $result,
        'timestamp' => time(),
        'user_logged_in' => isLoggedIn()
    ]);
    
} catch (Exception $e) {
    error_log("sync_comments.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro ao sincronizar comentários',
        'message' => $e->getMessage()
    ]);
}
