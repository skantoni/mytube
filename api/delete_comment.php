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
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de segurança inválido']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autenticado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$comment_id = isset($input['comment_id']) ? (int)$input['comment_id'] : 0;

if (!$comment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de comentario invalido']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, user_id, video_id, parent_comment_id FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch();

    if (!$comment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Comentario nao encontrado']);
        exit;
    }

    if ((int)$comment['user_id'] !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sem permissao para eliminar este comentario']);
        exit;
    }

    $pdo->beginTransaction();

    if (!$comment['parent_comment_id']) {
        $replyStmt = $pdo->prepare("SELECT id FROM comments WHERE parent_comment_id = ?");
        $replyStmt->execute([$comment_id]);
        $ids = $replyStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM comment_likes WHERE comment_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM comments WHERE parent_comment_id = ?")->execute([$comment_id]);
        }
    }

    $pdo->prepare("DELETE FROM comment_likes WHERE comment_id = ?")->execute([$comment_id]);
    $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$comment_id]);
    $pdo->prepare("UPDATE videos SET comments_count = GREATEST(0, comments_count - 1) WHERE id = ?")->execute([$comment['video_id']]);

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("delete_comment.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
