<?php
/**
 * API de Sincronização de Likes em Tempo Real
 * Retorna contadores atualizados de likes para vídeos visíveis no feed
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

if (!isset($input['video_ids']) || !is_array($input['video_ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'video_ids (array) é obrigatório']);
    exit;
}

$video_ids = array_map('intval', $input['video_ids']);

if (empty($video_ids)) {
    echo json_encode([
        'success' => true,
        'videos' => [],
        'timestamp' => time()
    ]);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($video_ids), '?'));
    
    // Buscar contadores atualizados dos vídeos
    $stmt = $pdo->prepare("
        SELECT 
            id,
            likes_count,
            comments_count,
            views_count
        FROM videos 
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($video_ids);
    $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Se usuário está logado, buscar seus likes
    $user_likes = [];
    if (isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("
            SELECT video_id 
            FROM video_likes 
            WHERE user_id = ? AND video_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$user_id], $video_ids));
        $user_likes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Formatar resposta
    $result = [];
    foreach ($videos as $video) {
        $video_id = (int)$video['id'];
        $result[$video_id] = [
            'video_id' => $video_id,
            'likes_count' => (int)$video['likes_count'],
            'comments_count' => (int)$video['comments_count'],
            'views_count' => (int)$video['views_count'],
            'user_liked' => in_array($video_id, $user_likes)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'videos' => $result,
        'timestamp' => time(),
        'user_logged_in' => isLoggedIn()
    ]);
    
} catch (Exception $e) {
    error_log("sync_likes.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro ao sincronizar likes',
        'message' => $e->getMessage()
    ]);
}
