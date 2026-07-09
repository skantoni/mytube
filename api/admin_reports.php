<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/r2_storage.php';
require_once __DIR__ . '/../includes/content_moderation.php';

// ── Autenticação ──────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

try {
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $role_stmt->execute([$_SESSION['user_id']]);
    $role_row = $role_stmt->fetch(PDO::FETCH_ASSOC);
    $user_role = $role_row['role'] ?? 'user';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao verificar permissões']);
    exit;
}

if (!in_array($user_role, ['admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

$action = $_POST['action'] ?? '';
$video_id = (int)($_POST['video_id'] ?? 0);

if ($video_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'video_id inválido']);
    exit;
}

try {
    if ($action === 'resolve') {
        // Reset reports to 0
        $stmt = $pdo->prepare("UPDATE videos SET reports_count = 0 WHERE id = ?");
        $stmt->execute([$video_id]);
        
        echo json_encode(['success' => true, 'message' => 'Denúncia marcada como resolvida']);
        exit;
    }

    if ($action === 'hide') {
        $stmt = $pdo->prepare("UPDATE videos SET is_hidden = 1 WHERE id = ?");
        $stmt->execute([$video_id]);
        
        echo json_encode(['success' => true, 'message' => 'Vídeo ocultado com sucesso']);
        exit;
    }

    if ($action === 'unhide') {
        $stmt = $pdo->prepare("UPDATE videos SET is_hidden = 0 WHERE id = ?");
        $stmt->execute([$video_id]);
        
        echo json_encode(['success' => true, 'message' => 'Vídeo visível novamente']);
        exit;
    }

    if ($action === 'delete') {
        // We can reuse moderation logic if we want, or do a direct delete
        $v_stmt = $pdo->prepare("SELECT id, video_path FROM videos WHERE id = ? LIMIT 1");
        $v_stmt->execute([$video_id]);
        $video = $v_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($video) {
            $video_path = (string)$video['video_path'];
            moderation_update_video_status($pdo, $video_id, 'rejected', null);
            
            if (r2_is_r2_path($video_path)) {
                r2_delete_video($video_path);
            } else {
                $local = UPLOAD_DIR . 'videos/' . basename($video_path);
                if (file_exists($local)) {
                    @unlink($local);
                }
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Vídeo eliminado com sucesso']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro na base de dados']);
}
