<?php
require_once '../includes/config.php';
require_once '../includes/push_helper.php';

header('Content-Type: application/json');

// Debug: log da requisição
error_log("Follow request: " . json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'input' => file_get_contents('php://input'),
    'session' => $_SESSION ?? [],
    'user_id' => $_SESSION['user_id'] ?? null
]));

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
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

if (!isset($input['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do usuário é obrigatório']);
    exit;
}

$following_id = (int)$input['user_id'];
$follower_id = $_SESSION['user_id'];

// Não pode seguir a si mesmo
if ($follower_id === $following_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Você não pode seguir a si mesmo']);
    exit;
}

try {
    // Verificar se o usuário existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$following_id]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuário não encontrado']);
        exit;
    }
    
    // Verificar se já está seguindo
    $stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$follower_id, $following_id]);
    $existing_follow = $stmt->fetch();
    
    if ($existing_follow) {
        // Deixar de seguir
        $stmt = $pdo->prepare("DELETE FROM follows WHERE id = ?");
        $stmt->execute([$existing_follow['id']]);
        $action = 'unfollowed';
        $is_following = false;
        
        error_log("✅ Unfollow realizado: follower=$follower_id, following=$following_id");
        
        // Criar notificação de unfollow
        try {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, actor_id, type, reference_id, created_at) 
                VALUES (?, ?, 'unfollow', ?, NOW())
            ");
            $notifStmt->execute([$following_id, $follower_id, $follower_id]);
        } catch (Exception $e) {
            error_log("⚠️ Erro ao criar notificação unfollow: " . $e->getMessage());
        }
    } else {
        // Seguir
        $stmt = $pdo->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())");
        $success = $stmt->execute([$follower_id, $following_id]);
        $action = 'followed';
        $is_following = true;
        
        error_log("✅ Follow realizado: follower=$follower_id, following=$following_id, success=" . ($success ? 'SIM' : 'NÃO'));
        
        // Criar notificação de follow
        try {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, actor_id, type, reference_id, created_at) 
                VALUES (?, ?, 'follow', ?, NOW())
            ");
            $notifStmt->execute([$following_id, $follower_id, $follower_id]);
            error_log("✅ Notificação criada");
            
            // Push notification
            $actorName = $_SESSION['username'] ?? 'Alguém';
            sendPushNotification($pdo, (int)$following_id, 'Novo seguidor 👤', "$actorName começou a seguir-te", "/profile.php?user=$actorName");
        } catch (Exception $e) {
            error_log("⚠️ Erro ao criar notificação: " . $e->getMessage());
            // Silently fail - não bloquear o follow se notificação falhar
        }
    }
    
    // Atualizar contadores na tabela users (+1 ou -1)
    $delta = $is_following ? 1 : -1;
    $pdo->prepare("
        UPDATE users 
        SET followers_count = GREATEST(0, followers_count + ?)
        WHERE id = ?
    ")->execute([$delta, $following_id]);
    
    $pdo->prepare("
        UPDATE users 
        SET following_count = GREATEST(0, following_count + ?)
        WHERE id = ?
    ")->execute([$delta, $follower_id]);
    
    // Buscar contadores atualizados
    $followers_stmt = $pdo->prepare("SELECT followers_count FROM users WHERE id = ?");
    $followers_stmt->execute([$following_id]);
    $followers_count = $followers_stmt->fetchColumn();
    
    // Verificar se o outro usuário segue de volta (follows_you)
    $follows_you_stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $follows_you_stmt->execute([$following_id, $follower_id]);
    $follows_you = $follows_you_stmt->fetch() !== false;
    
    error_log("✅ Contador atualizado: $followers_count seguidores, follows_you: " . ($follows_you ? 'SIM' : 'NÃO'));
    
    echo json_encode([
        'success' => true,
        'action' => $action,
        'is_following' => $is_following,
        'followers_count' => (int)$followers_count,
        'follows_you' => $follows_you
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
?>