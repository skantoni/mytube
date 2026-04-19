<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

// Verificar autenticação
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de segurança inválido']);
    exit;
}

// Ler input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['comment_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID do comentário obrigatório']);
    exit;
}

$comment_id = (int)$input['comment_id'];
$user_id = $_SESSION['user_id'];

try {
    // Verificar se comentário existe
    $stmt = $pdo->prepare("SELECT id, likes_count FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch();
    
    if (!$comment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Comentário não encontrado']);
        exit;
    }
    
    // Iniciar transação para garantir consistência
    $pdo->beginTransaction();
    
    try {
        // Verificar se já curtiu
        $stmt = $pdo->prepare("SELECT id FROM comment_likes WHERE user_id = ? AND comment_id = ?");
        $stmt->execute([$user_id, $comment_id]);
        $existing_like = $stmt->fetch();
        
        if ($existing_like) {
            // Remover curtida
            $stmt = $pdo->prepare("DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?");
            $stmt->execute([$user_id, $comment_id]);
            $liked = false;
        } else {
            // Adicionar curtida
            $stmt = $pdo->prepare("INSERT INTO comment_likes (user_id, comment_id) VALUES (?, ?)");
            $stmt->execute([$user_id, $comment_id]);
            $liked = true;
            
            // Criar notificação de like no comentário
            // Buscar o dono do comentário e o vídeo associado
            $ownerStmt = $pdo->prepare("SELECT user_id, video_id FROM comments WHERE id = ?");
            $ownerStmt->execute([$comment_id]);
            $commentData = $ownerStmt->fetch();
            
            // Notificar o dono do comentário (se não for o próprio usuário)
            if ($commentData && $commentData['user_id'] != $user_id) {
                try {
                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, actor_id, type, reference_id, comment_id) 
                        VALUES (?, ?, 'comment_like', ?, ?)
                    ");
                    $notifStmt->execute([$commentData['user_id'], $user_id, $commentData['video_id'], $comment_id]);
                } catch (Exception $e) {
                    // Silently fail - não impedir o like por causa de notificação
                    error_log("Erro ao criar notificação de comment_like: " . $e->getMessage());
                }
            }
        }
        
        // IMPORTANTE: Atualizar contador na tabela comments (+1 ou -1)
        $stmt = $pdo->prepare("
            UPDATE comments 
            SET likes_count = GREATEST(0, likes_count + ?)
            WHERE id = ?
        ");
        $stmt->execute([$liked ? 1 : -1, $comment_id]);
        
        // Commit da transação
        $pdo->commit();
        
        // Buscar contador atualizado
        $stmt = $pdo->prepare("SELECT likes_count FROM comments WHERE id = ?");
        $stmt->execute([$comment_id]);
        $updated_comment = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'liked' => $liked,
            'likes_count' => (int)$updated_comment['likes_count']
        ]);
        
    } catch (Exception $e) {
        // Rollback em caso de erro
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("toggle_comment_like.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>