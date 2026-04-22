<?php
require_once '../includes/config.php';
require_once '../includes/ranking_cache.php';
require_once '../includes/r2_storage.php';

header('Content-Type: application/json');

// Verificar se está logado
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

// Verificar se é uma requisição POST
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

// Validar dados de entrada
$input = json_decode(file_get_contents('php://input'), true);
$video_id = isset($input['video_id']) ? (int)$input['video_id'] : 0;

if (!$video_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID do vídeo é obrigatório']);
    exit;
}

try {
    // Buscar informações do vídeo
    $video_stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
    $video_stmt->execute([$video_id]);
    $video = $video_stmt->fetch();

    if (!$video) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Vídeo não encontrado']);
        exit;
    }

    // Verificar permissões: deve ser o dono do vídeo OU administrador (via RBAC)
    $is_owner = ($video['user_id'] == $_SESSION['user_id']);
    $is_admin = isAdminUser();

    if (!$is_owner && !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Você não tem permissão para apagar este vídeo']);
        exit;
    }

    // Iniciar transação
    $pdo->beginTransaction();

    // Apagar dados relacionados ao vídeo (as foreign keys já fazem CASCADE, mas vamos ser explícitos)
    
    // 1. Apagar likes dos comentários do vídeo
    $pdo->prepare("DELETE FROM comment_likes WHERE comment_id IN (SELECT id FROM comments WHERE video_id = ?)")
        ->execute([$video_id]);
    
    // 2. Apagar comentários do vídeo
    $pdo->prepare("DELETE FROM comments WHERE video_id = ?")
        ->execute([$video_id]);
    
    // 3. Apagar likes do vídeo
    $pdo->prepare("DELETE FROM video_likes WHERE video_id = ?")
        ->execute([$video_id]);

    // 4. Deletar arquivos físicos (R2 ou local)
    if (r2_is_r2_path($video['video_path'])) {
        // Vídeo no Cloudflare R2
        r2_delete_video($video['video_path']);
    } else {
        // Vídeo local
        $video_path = '../uploads/videos/' . $video['video_path'];
        if (file_exists($video_path)) {
            unlink($video_path);
        }
    }

    if ($video['thumbnail_path']) {
        $thumbnail_path = '../uploads/thumbnails/' . $video['thumbnail_path'];
        if (file_exists($thumbnail_path)) {
            unlink($thumbnail_path);
        }
    }

    // 5. Apagar o vídeo da tabela
    $delete_stmt = $pdo->prepare("DELETE FROM videos WHERE id = ?");
    $delete_stmt->execute([$video_id]);

    // Atualizar contadores do usuário
    $pdo->prepare("UPDATE users SET videos_count = videos_count - 1 WHERE id = ?")
       ->execute([$video['user_id']]);

    // Commit da transação
    $pdo->commit();

    // Recalcular ranking_points (vídeo apagado, recalcular tudo)
    ranking_points_recalc($pdo, (int)$video['user_id']);
    ranking_cache_clear_all();

    echo json_encode([
        'success' => true, 
        'message' => 'Vídeo apagado com sucesso',
        'deleted_by' => $is_admin ? 'admin' : 'owner'
    ]);

} catch (\Throwable $e) {
    // Rollback em caso de erro
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("Erro ao apagar vídeo: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
?>