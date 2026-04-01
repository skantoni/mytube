<?php
ob_start();
session_start();
require_once '../includes/config.php';

// Limpar qualquer output anterior
ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Verificar se usuário está logado
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Obter dados do POST
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $comment_id = $_POST['comment_id'] ?? null;
    $new_text = $_POST['comment_text'] ?? null;
} else {
    $comment_id = $input['comment_id'] ?? null;
    $new_text = $input['comment_text'] ?? null;
}

// Validar dados
if (empty($comment_id) || empty($new_text)) {
    echo json_encode(['success' => false, 'message' => 'ID do comentário e texto são obrigatórios']);
    exit;
}

// Normalizar texto e limitar quebras de linha consecutivas (máx 2)
$new_text = (string)$new_text;
$new_text = str_replace(["\r\n", "\r"], "\n", $new_text);
$new_text = preg_replace("/\n{3,}/", "\n\n", $new_text) ?? $new_text;
$new_text = trim($new_text);

// Validar tamanho do texto
if (strlen($new_text) < 1 || strlen($new_text) > 500) {
    echo json_encode(['success' => false, 'message' => 'Comentário deve ter entre 1 e 500 caracteres']);
    exit;
}

try {
    // Verificar se o comentário existe e pertence ao usuário
    $stmt = $pdo->prepare("
        SELECT id, user_id, comment_text, created_at 
        FROM comments 
        WHERE id = ?
    ");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch();
    
    if (!$comment) {
        echo json_encode(['success' => false, 'message' => 'Comentário não encontrado']);
        exit;
    }
    
    // Verificar se o usuário é o autor do comentário
    if ($comment['user_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Você só pode editar seus próprios comentários']);
        exit;
    }
    
    // Verificar se ainda está dentro da janela de edição (2 minutos)
    $time_since_created = time() - strtotime($comment['created_at']);
    if ($time_since_created > 120) { // 2 minutos = 120 segundos
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Tempo para edição expirado. Você só pode editar comentários nos primeiros 2 minutos.']);
        exit;
    }
    
    // Atualizar o comentário
    $stmt = $pdo->prepare("
        UPDATE comments 
        SET comment_text = ?, updated_at = NOW() 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$new_text, $comment_id, $user_id]);
    
    if ($stmt->rowCount() > 0) {
        // Buscar informações atualizadas do comentário
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.comment_text,
                c.created_at,
                c.updated_at,
                u.username,
                u.profile_picture,
                u.is_verified
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$comment_id]);
        $updated_comment = $stmt->fetch();
        
        // Calcular tempo restante para edição
        $time_since_created = time() - strtotime($updated_comment['created_at']);
        $edit_time_left = max(0, 120 - $time_since_created);
        
        echo json_encode([
            'success' => true,
            'message' => 'Comentário atualizado com sucesso',
            'edit_time_left' => $edit_time_left,
            'comment' => [
                'id' => $updated_comment['id'],
                'comment_text' => $updated_comment['comment_text'],
                'time_ago' => timeAgo($updated_comment['created_at']),
                'updated_at' => $updated_comment['updated_at'] ? timeAgo($updated_comment['updated_at']) : null,
                'username' => $updated_comment['username'],
                'profile_picture' => $updated_comment['profile_picture'],
                'is_verified' => (bool)$updated_comment['is_verified']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar comentário']);
    }
    
} catch(Exception $e) {
    error_log("Erro ao editar comentário: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>