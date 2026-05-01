<?php
/**
 * API para gerenciar likes em vídeos
 * Suporta tanto usuários logados quanto visitantes (via localStorage)
 */

require_once '../includes/config.php';
require_once '../includes/ranking_cache.php';
require_once '../includes/push_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

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
    echo json_encode(['error' => 'video_id é obrigatório']);
    exit;
}

$video_id = (int)$input['video_id'];
$action = $input['action'] ?? 'toggle'; // toggle, get_status

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
    
    if ($action === 'get_status') {
        // Recuperar status do like
        $isLiked = false;
        
        if (isLoggedIn()) {
            // Usuario logado - verificar no banco
            $user_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM video_likes WHERE user_id = ? AND video_id = ?");
            $stmt->execute([$user_id, $video_id]);
            $isLiked = $stmt->rowCount() > 0;
        }
        
        echo json_encode([
            'success' => true,
            'liked' => $isLiked,
            'likes_count' => (int)$video['likes_count'],
            'user_logged_in' => isLoggedIn()
        ]);
        
    } elseif ($action === 'toggle') {
        // Toggle do like
        if (isLoggedIn()) {
            // Usuario logado - salvar no banco
            $user_id = $_SESSION['user_id'];
            
            // Iniciar transação
            $pdo->beginTransaction();
            
            try {
                // Verificar se já existe like
                $stmt = $pdo->prepare("SELECT id FROM video_likes WHERE user_id = ? AND video_id = ?");
                $stmt->execute([$user_id, $video_id]);
                $existing_like = $stmt->fetch();
                
                if ($existing_like) {
                    // Remover like
                    $stmt = $pdo->prepare("DELETE FROM video_likes WHERE user_id = ? AND video_id = ?");
                    $stmt->execute([$user_id, $video_id]);
                    $liked = false;
                } else {
                    // Adicionar like
                    $stmt = $pdo->prepare("INSERT INTO video_likes (user_id, video_id) VALUES (?, ?)");
                    $stmt->execute([$user_id, $video_id]);
                    $liked = true;
                }
                
                // IMPORTANTE: Atualizar contador na tabela videos (+1 ou -1) e trend_score
                $stmt = $pdo->prepare("
                    UPDATE videos 
                    SET likes_count = GREATEST(0, likes_count + ?),
                        trend_score = GREATEST(0, (GREATEST(0, likes_count + ?) * 2) + views_count + comments_count * 3)
                    WHERE id = ?
                ");
                $delta = $liked ? 1 : -1;
                $stmt->execute([$delta, $delta, $video_id]);
                
                // Commit
                $pdo->commit();

                // Atualizar ranking_points do dono do vídeo (+2 like, -2 unlike)
                $ownerStmt = $pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
                $ownerStmt->execute([$video_id]);
                $vid_owner = $ownerStmt->fetchColumn();
                if ($vid_owner) {
                    ranking_points_increment($pdo, (int)$vid_owner, $liked ? 2 : -2);
                    
                    // Push notification de like (só quando é like, não unlike)
                    if ($liked && (int)$vid_owner !== $user_id) {
                        $actorName = $_SESSION['username'] ?? 'Alguém';
                        sendPushNotification($pdo, (int)$vid_owner, 'Novo like ❤️', "$actorName curtiu o teu vídeo", "/index.php?v=$video_id");
                    }
                }
                
                // Buscar contagem atualizada
                $stmt = $pdo->prepare("SELECT likes_count FROM videos WHERE id = ?");
                $stmt->execute([$video_id]);
                $updated_video = $stmt->fetch();
                
                echo json_encode([
                    'success' => true,
                    'liked' => $liked,
                    'likes_count' => (int)$updated_video['likes_count'],
                    'user_logged_in' => true
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            
        } else {
            // Usuario não logado - retornar apenas status para localStorage
            echo json_encode([
                'success' => true,
                'liked' => null, // Frontend gerencia via localStorage
                'likes_count' => (int)$video['likes_count'],
                'user_logged_in' => false,
                'message' => 'Like salvo localmente'
            ]);
        }
    }
    
} catch (PDOException $e) {
    error_log("Erro na API de likes: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}