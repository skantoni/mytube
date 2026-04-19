<?php
/**
 * API para gerenciar follows de usuários
 * Suporta tanto usuários logados quanto visitantes (via localStorage)
 */

session_start();
require_once '../includes/config.php';

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

if (!isset($input['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'user_id é obrigatório']);
    exit;
}

$target_user_id = (int)$input['user_id'];
$action = $input['action'] ?? 'toggle'; // toggle, get_status

try {
    // Verificar se o usuário alvo existe
    $stmt = $pdo->prepare("SELECT id, username, followers_count FROM users WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $target_user = $stmt->fetch();
    
    if (!$target_user) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }
    
    if ($action === 'get_status') {
        // Recuperar status do follow
        $isFollowing = false;
        
        if (isLoggedIn()) {
            $current_user_id = $_SESSION['user_id'];
            
            // Não pode seguir a si mesmo
            if ($current_user_id != $target_user_id) {
                $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
                $stmt->execute([$current_user_id, $target_user_id]);
                $isFollowing = $stmt->rowCount() > 0;
            }
        }
        
        echo json_encode([
            'success' => true,
            'following' => $isFollowing,
            'followers_count' => (int)$target_user['followers_count'],
            'user_logged_in' => isLoggedIn(),
            'can_follow' => isLoggedIn() && $_SESSION['user_id'] != $target_user_id
        ]);
        
    } elseif ($action === 'toggle') {
        // Toggle do follow
        if (isLoggedIn()) {
            $current_user_id = $_SESSION['user_id'];
            
            // Verificar se não está tentando seguir a si mesmo
            if ($current_user_id == $target_user_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Não é possível seguir a si mesmo']);
                exit;
            }
            
            // Verificar se já está seguindo
            $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$current_user_id, $target_user_id]);
            $existing_follow = $stmt->fetch();
            
            if ($existing_follow) {
                // Deixar de seguir
                $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
                $stmt->execute([$current_user_id, $target_user_id]);
                $following = false;
            } else {
                // Seguir
                $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)");
                $stmt->execute([$current_user_id, $target_user_id]);
                $following = true;
            }
            
            // Atualizar contadores na tabela users (+1 ou -1)
            $delta = $following ? 1 : -1;
            $pdo->prepare("
                UPDATE users 
                SET followers_count = GREATEST(0, followers_count + ?)
                WHERE id = ?
            ")->execute([$delta, $target_user_id]);
            
            $pdo->prepare("
                UPDATE users 
                SET following_count = GREATEST(0, following_count + ?)
                WHERE id = ?
            ")->execute([$delta, $current_user_id]);
            
            // Buscar contagem atualizada
            $stmt = $pdo->prepare("SELECT followers_count FROM users WHERE id = ?");
            $stmt->execute([$target_user_id]);
            $updated_user = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'following' => $following,
                'followers_count' => (int)$updated_user['followers_count'],
                'user_logged_in' => true
            ]);
            
        } else {
            // Usuario não logado - retornar status para localStorage
            echo json_encode([
                'success' => true,
                'following' => null, // Frontend gerencia via localStorage
                'followers_count' => (int)$target_user['followers_count'],
                'user_logged_in' => false,
                'message' => 'Follow salvo localmente'
            ]);
        }
    }
    
} catch (PDOException $e) {
    error_log("Erro na API de follows: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}