<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$comment_id = isset($input['comment_id']) ? (int)$input['comment_id'] : 0;

if (!$comment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de comentário inválido']);
    exit;
}

try {
    $pdo = getPDO();

    $stmt = $pdo->prepare("SELECT id, user_id, video_id, parent_comment_id FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch();

    if (!$comment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Comentário não encontrado']);
        exit;
    }

    if ($comment['user_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sem permissão para eliminar este comentário']);
        exit;
    }

    $pdo->beginTransaction();

    // Se for comentário raiz, eliminar respostas primeiro
    if (!$comment['parent_comment_id']) {
        $replyIds = $pdo->prepare("SELECT id FROM comments WHERE parent_comment_id = ?");
        $replyIds->execute([$comment_id]);
        $ids = $replyIds->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM comment_likes WHERE comment_id IN ($placeholders)")->execute($ids);
            $pdo->prepare("DELETE FROM comments WHERE parent_comment_id = ?")->execute([$comment_id]);
        }
    }

    // Eliminar likes e o comentário
    $pdo->prepare("DELETE FROM comment_likes WHERE comment_id = ?")->execute([$comment_id]);
    $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$comment_id]);

    // Actualizar contador
    $pdo->prepare("UPDATE videos SET comments_count = GREATEST(0, comments_count - 1) WHERE id = ?")->execute([$comment['video_id']]);

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("delete_comment.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>
