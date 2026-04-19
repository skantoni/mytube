<?php
ob_start(); // Capturar output para evitar problemas com headers
session_start();
require_once '../includes/config.php';
require_once '../includes/ranking_cache.php';
require_once '../includes/push_helper.php';

// Limpar qualquer output anterior
ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
error_log("add_comment.php: Iniciando...");

if (!isLoggedIn()) {
    error_log("add_comment.php: Usuário não autenticado");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("add_comment.php: Método não permitido - " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    error_log("add_comment.php: CSRF token inválido");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de segurança inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
error_log("add_comment.php: Input recebido - " . json_encode($input));

if (!isset($input['video_id']) || !isset($input['comment_text'])) {
    error_log("add_comment.php: Dados obrigatórios não fornecidos");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados obrigatórios não fornecidos']);
    exit;
}

$video_id = (int)$input['video_id'];
$comment_text = (string)($input['comment_text'] ?? '');
$comment_text = str_replace(["\r\n", "\r"], "\n", $comment_text);
$comment_text = preg_replace("/\n{3,}/", "\n\n", $comment_text) ?? $comment_text;
$comment_text = trim($comment_text);
$parent_comment_id = isset($input['parent_comment_id']) ? (int)$input['parent_comment_id'] : null;
$user_id = $_SESSION['user_id'];

if (empty($comment_text)) {
    http_response_code(400);
    echo json_encode(['error' => 'Comentário não pode estar vazio']);
    exit;
}

if (strlen($comment_text) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Comentário muito longo (máximo 500 caracteres)']);
    exit;
}

// Rate limiting: máx 1 comentário a cada 3s por utilizador
$rate_key = 'comment_last_' . $user_id;
if (isset($_SESSION[$rate_key]) && (microtime(true) - $_SESSION[$rate_key]) < 3.0) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Aguarde alguns segundos antes de comentar novamente']);
    exit;
}
$_SESSION[$rate_key] = microtime(true);

try {
    // Verificar se o vídeo existe
    $stmt = $pdo->prepare("SELECT id FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Vídeo não encontrado']);
        exit;
    }
    
    // Se for resposta, verificar se o comentário pai existe
    if ($parent_comment_id) {
        $stmt = $pdo->prepare("SELECT id, video_id FROM comments WHERE id = ?");
        $stmt->execute([$parent_comment_id]);
        $parent_comment = $stmt->fetch();
        
        if (!$parent_comment) {
            http_response_code(404);
            echo json_encode(['error' => 'Comentário pai não encontrado']);
            exit;
        }
        
        // Verificar se o comentário pai é do mesmo vídeo
        if ($parent_comment['video_id'] != $video_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Comentário pai não pertence a este vídeo']);
            exit;
        }
    }
    
    // Inserir comentário em transação atómica (insert + counter)
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO comments (user_id, video_id, comment_text, parent_comment_id) 
        VALUES (?, ?, ?, ?)
    ");
    
    if ($stmt->execute([$user_id, $video_id, $comment_text, $parent_comment_id])) {
        $comment_id = $pdo->lastInsertId();
        
        // Atualizar contador de comentários do vídeo (+1) e trend_score
        $updateCountStmt = $pdo->prepare("
            UPDATE videos 
            SET comments_count = comments_count + 1,
                trend_score = (likes_count * 2) + views_count + ((comments_count + 1) * 3)
            WHERE id = ?
        ");
        $updateCountStmt->execute([$video_id]);
        
        $pdo->commit(); // Transação concluída: insert + counter atómicos

        // +3 ranking_points para o dono do vídeo (comentário vale 3)
        $vidOwnerStmt = $pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
        $vidOwnerStmt->execute([$video_id]);
        $vid_owner = $vidOwnerStmt->fetchColumn();
        if ($vid_owner) {
            ranking_points_increment($pdo, (int)$vid_owner, 3);
        }
        
        // === CRIAR NOTIFICAÇÃO DE COMENTÁRIO ===
        // Buscar o dono do vídeo
        $ownerStmt = $pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
        $ownerStmt->execute([$video_id]);
        $video_owner_id = $ownerStmt->fetchColumn();
        
        // Notificar o dono do vídeo (se não for o próprio usuário)
        if ($video_owner_id && $video_owner_id != $user_id) {
            try {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, actor_id, type, reference_id, comment_id) 
                    VALUES (?, ?, 'comment', ?, ?)
                ");
                $notifStmt->execute([$video_owner_id, $user_id, $video_id, $comment_id]);
                
                // Push notification
                $actorName = $_SESSION['username'] ?? 'Alguém';
                sendPushNotification($pdo, (int)$video_owner_id, 'Novo comentário 💬', "$actorName comentou no teu vídeo", "/my/index.php?v=$video_id");
            } catch (Exception $e) {
                error_log("add_comment.php: Erro ao criar notificação de comentário - " . $e->getMessage());
            }
        }
        
        // Se for uma resposta, notificar o autor do comentário pai
        if ($parent_comment_id) {
            try {
                $parentStmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
                $parentStmt->execute([$parent_comment_id]);
                $parent_comment_owner_id = $parentStmt->fetchColumn();
                
                // Notificar se não for o próprio usuário e se não for o dono do vídeo (já notificado)
                if ($parent_comment_owner_id && 
                    $parent_comment_owner_id != $user_id && 
                    $parent_comment_owner_id != $video_owner_id) {
                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, actor_id, type, reference_id, comment_id) 
                        VALUES (?, ?, 'reply', ?, ?)
                    ");
                    $notifStmt->execute([$parent_comment_owner_id, $user_id, $video_id, $comment_id]);
                    
                    // Push notification de resposta
                    $actorName = $_SESSION['username'] ?? 'Alguém';
                    sendPushNotification($pdo, (int)$parent_comment_owner_id, 'Nova resposta 💬', "$actorName respondeu ao teu comentário", "/my/index.php?v=$video_id");
                }
            } catch (Exception $e) {
                error_log("add_comment.php: Erro ao criar notificação de resposta - " . $e->getMessage());
            }
        }
        // === NOTIFICAÇÕES DE MENÇÃO (@username) ===
        // Extrair menções do texto (máx 5 por comentário — validado no front)
        preg_match_all('/@(\w+)/', $comment_text, $mentionMatches);
        if (!empty($mentionMatches[1])) {
            // Limitar a 5 menções
            $mentionedUsernames = array_unique(array_slice($mentionMatches[1], 0, 5));
            
            if (count($mentionedUsernames) > 0) {
                // Buscar IDs de todos os mencionados numa única query
                $placeholders = str_repeat('?,', count($mentionedUsernames) - 1) . '?';
                $mentionStmt = $pdo->prepare("SELECT id, username FROM users WHERE username IN ($placeholders)");
                $mentionStmt->execute($mentionedUsernames);
                $mentionedUsers = $mentionStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // IDs já notificados (dono do vídeo e autor do comentário pai) para evitar duplicação
                $alreadyNotified = [$user_id]; // Não notificar a si próprio
                if (!empty($video_owner_id)) $alreadyNotified[] = (int)$video_owner_id;
                if (isset($parent_comment_owner_id) && $parent_comment_owner_id) $alreadyNotified[] = (int)$parent_comment_owner_id;
                
                // Preparar insert de menção uma vez, executar para cada usuário válido
                $mentionNotifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, actor_id, type, reference_id, comment_id) 
                    VALUES (?, ?, 'mention', ?, ?)
                ");
                
                foreach ($mentionedUsers as $mu) {
                    $mentionedId = (int)$mu['id'];
                    // Evitar notificar quem já foi notificado por comment/reply
                    if (!in_array($mentionedId, $alreadyNotified)) {
                        try {
                            $mentionNotifStmt->execute([$mentionedId, $user_id, $video_id, $comment_id]);
                            $alreadyNotified[] = $mentionedId; // Evitar duplicatas entre menções
                            
                            // Push notification de menção
                            $actorName = $_SESSION['username'] ?? 'Alguém';
                            sendPushNotification($pdo, $mentionedId, 'Mencionado 📢', "$actorName mencionou-te num comentário", "/my/index.php?v=$video_id");
                        } catch (Exception $e) {
                            error_log("add_comment.php: Erro ao criar notificação de menção para user {$mentionedId} - " . $e->getMessage());
                        }
                    }
                }
            }
        }
        // === FIM NOTIFICAÇÕES ===
        
        // Buscar dados do comentário recém criado
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                u.username,
                u.full_name,
                u.profile_picture,
                u.is_verified
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        
        $stmt->execute([$comment_id]);
        $comment = $stmt->fetch();
        
        // Calcular informações de edição para o comentário recém-criado
        $is_author = ($comment['user_id'] == $user_id);
        $time_since_created = time() - strtotime($comment['created_at']);
        $within_edit_window = $time_since_created <= 120; // 2 minutos
        
        // Encontrar o comentário raiz (para respostas a respostas)
        $root_comment_id = null;
        if ($parent_comment_id) {
            // Verificar se o parent é um comentário raiz ou outra resposta
            $parentCheckStmt = $pdo->prepare("SELECT parent_comment_id FROM comments WHERE id = ?");
            $parentCheckStmt->execute([$parent_comment_id]);
            $parent_parent_id = $parentCheckStmt->fetchColumn();
            
            if ($parent_parent_id) {
                // O parent é uma resposta, então o root é o parent do parent
                $root_comment_id = (int)$parent_parent_id;
            } else {
                // O parent é um comentário raiz
                $root_comment_id = (int)$parent_comment_id;
            }
        }
        
        $formatted_comment = [
            'id' => $comment['id'],
            'comment_text' => $comment['comment_text'],
            'likes_count' => $comment['likes_count'],
            'created_at' => $comment['created_at'],
            'time_ago' => timeAgo($comment['created_at']),
            'user_id' => $comment['user_id'],
            'username' => $comment['username'],
            'full_name' => $comment['full_name'],
            'profile_picture' => $comment['profile_picture'] ?? 'default.webp',
            'is_verified' => (bool)$comment['is_verified'],
            'user_liked' => false,
            'replies' => [],
            'replies_count' => 0,
            'parent_comment_id' => $comment['parent_comment_id'],
            'root_comment_id' => $root_comment_id, // ID do comentário raiz
            'can_edit' => $is_author && $within_edit_window,
            'can_delete' => $is_author,
            'edit_time_left' => $within_edit_window ? (120 - $time_since_created) : 0
        ];
        
        echo json_encode([
            'success' => true,
            'comment' => $formatted_comment
        ]);
    } else {
        throw new Exception('Erro ao inserir comentário');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("add_comment.php: Erro - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>