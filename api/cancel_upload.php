<?php
/**
 * cancel_upload.php
 * Apaga um vídeo que foi inserido no servidor mas cujo upload foi cancelado pelo utilizador.
 * Chamado pelo cliente quando o utilizador clica no X depois do upload já ter chegado ao servidor.
 */
require_once '../includes/config.php';
require_once '../includes/r2_storage.php';

header('Content-Type: application/json');

// Apenas utilizadores autenticados
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Validar CSRF
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de segurança inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$video_id = isset($input['video_id']) ? (int)$input['video_id'] : 0;

if (!$video_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID do vídeo inválido']);
    exit;
}

try {
    // Buscar o vídeo — só pode apagar o próprio utilizador e o vídeo tem de ser recente (< 10 min)
    $stmt = $pdo->prepare("
        SELECT id, user_id, video_path 
        FROM videos 
        WHERE id = ? 
          AND user_id = ? 
          AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmt->execute([$video_id, $_SESSION['user_id']]);
    $video = $stmt->fetch();

    if (!$video) {
        // Vídeo não encontrado, não pertence ao utilizador, ou já passou do prazo — ignorar silenciosamente
        echo json_encode(['success' => true, 'message' => 'Nada a cancelar']);
        exit;
    }

    $pdo->beginTransaction();

    // Apagar dados relacionados
    $pdo->prepare("DELETE FROM comment_likes WHERE comment_id IN (SELECT id FROM comments WHERE video_id = ?)")
        ->execute([$video_id]);
    $pdo->prepare("DELETE FROM comments WHERE video_id = ?")
        ->execute([$video_id]);
    $pdo->prepare("DELETE FROM video_likes WHERE video_id = ?")
        ->execute([$video_id]);
    $pdo->prepare("DELETE FROM video_hashtags WHERE video_id = ?")
        ->execute([$video_id]);

    // Apagar o registo do vídeo
    $pdo->prepare("DELETE FROM videos WHERE id = ? AND user_id = ?")
        ->execute([$video_id, $_SESSION['user_id']]);

    // Corrigir contador do utilizador (não deixar ir abaixo de 0)
    $pdo->prepare("UPDATE users SET videos_count = GREATEST(0, videos_count - 1) WHERE id = ?")
        ->execute([$video['user_id']]);

    $pdo->commit();

    // Apagar ficheiro físico (R2 ou local) — fora da transação para não bloquear
    if (!empty($video['video_path'])) {
        if (r2_is_r2_path($video['video_path'])) {
            r2_delete_video($video['video_path']);
        } else {
            $local_path = __DIR__ . '/../uploads/videos/' . $video['video_path'];
            if (file_exists($local_path)) {
                @unlink($local_path);
            }
        }
    }

    error_log("cancel_upload: vídeo #{$video_id} cancelado pelo utilizador #{$_SESSION['user_id']}");

    echo json_encode(['success' => true, 'message' => 'Upload cancelado e vídeo removido']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("cancel_upload erro: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao cancelar upload']);
}
?>
