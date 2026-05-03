<?php
require_once '../includes/config.php';
require_once '../includes/ranking_cache.php';
require_once '../includes/push_helper.php';

header('Content-Type: application/json');

// Debug: log da sessão
error_log("Session debug: " . json_encode([
    'session_id' => session_id(),
    'user_id' => $_SESSION['user_id'] ?? 'not set',
    'isLoggedIn' => isLoggedIn(),
    'session_data' => $_SESSION
])); // Remover do servidor para evitar vazar as informações do user

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Usuário não autenticado',
        'debug' => [
            'session_id' => session_id(),
            'user_id_exists' => isset($_SESSION['user_id']),
            'user_id' => $_SESSION['user_id'] ?? null
        ]
    ]);
    exit;
}

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

if (!isset($input['video_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do vídeo é obrigatório']);
    exit;
}

$video_id = (int)$input['video_id'];
$user_id = $_SESSION['user_id'];

try {
    // Verificar se o vídeo existe
    $stmt = $pdo->prepare("SELECT id, likes_count FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();
    
    if (!$video) {
        http_response_code(404);
        echo json_encode(['error' => 'Vídeo não encontrado']);
        exit;
    }
    
    // Iniciar transação para garantir consistência
    $pdo->beginTransaction();
    
    try {
        // Verificar se já curtiu
        $stmt = $pdo->prepare("SELECT id FROM video_likes WHERE user_id = ? AND video_id = ?");
        $stmt->execute([$user_id, $video_id]);
        $existing_like = $stmt->fetch();
        
        if ($existing_like) {
            // Remover like
            $stmt = $pdo->prepare("DELETE FROM video_likes WHERE id = ?");
            $stmt->execute([$existing_like['id']]);
            $action = 'unliked';
        } else {
            // Adicionar like
            $stmt = $pdo->prepare("INSERT INTO video_likes (user_id, video_id) VALUES (?, ?)");
            $stmt->execute([$user_id, $video_id]);
            $action = 'liked';
            
            // Criar notificação de like (se não for o próprio vídeo)
            $ownerStmt = $pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
            $ownerStmt->execute([$video_id]);
            $video_owner_id = $ownerStmt->fetchColumn();
            
            if ($video_owner_id && $video_owner_id != $user_id) {
                try {
                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, actor_id, type, reference_id) 
                        VALUES (?, ?, 'like', ?)
                    ");
                    $notifStmt->execute([$video_owner_id, $user_id, $video_id]);
                    
                    // Push notification
                    $actorName = $_SESSION['username'] ?? 'Alguém';
                    sendPushNotification($pdo, (int)$video_owner_id, 'Novo like ❤️', "$actorName curtiu o teu vídeo", "/index.php?v=$video_id");
                } catch (Exception $e) {
                    // Silently fail
                }
            }
        }
        
        // IMPORTANTE: Atualizar o contador na tabela videos (+1 ou -1) e trend_score
        $like_delta = $action === 'liked' ? 1 : -1;
        $stmt = $pdo->prepare("
            UPDATE videos 
            SET likes_count = GREATEST(0, likes_count + ?),
                trend_score = GREATEST(0, (GREATEST(0, likes_count + ?) * 2) + views_count + comments_count * 3)
            WHERE id = ?
        ");
        $stmt->execute([$like_delta, $like_delta, $video_id]);
        
        // Commit da transação
        $pdo->commit();

        // Atualizar ranking_points do dono do vídeo
        $vid_owner_id = $video_owner_id ?? null;
        if (!$vid_owner_id) {
            $ownerStmt2 = $pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
            $ownerStmt2->execute([$video_id]);
            $vid_owner_id = $ownerStmt2->fetchColumn();
        }
        if ($vid_owner_id) {
            ranking_points_increment($pdo, (int)$vid_owner_id, $action === 'liked' ? 2 : -2);
        }
        
        // Buscar contador atualizado
        $stmt = $pdo->prepare("SELECT likes_count FROM videos WHERE id = ?");
        $stmt->execute([$video_id]);
        $result = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'action' => $action,
            'liked' => ($action === 'liked'),
            'likes_count' => (int)$result['likes_count']
        ]);
        
    } catch (Exception $e) {
        // Rollback em caso de erro
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("toggle_like.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro interno do servidor',
        'message' => $e->getMessage()
    ]);
}
?>