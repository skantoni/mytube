<?php
/**
 * API para registar compartilhamento de vídeo
 * Incrementa shares_count e insere na tabela shares
 * Previne duplicatas: 1 share por utilizador/vídeo/plataforma por hora
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Validar CSRF token (permite requests sem autenticação)
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de segurança inválido']);
    exit;
}

$video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
$platform = isset($_POST['platform']) ? trim($_POST['platform']) : 'unknown';

// Validar plataforma — copy_link não conta como partilha real
$allowed_platforms = ['whatsapp', 'facebook', 'chat'];
if (!in_array($platform, $allowed_platforms)) {
    $platform = 'unknown';
}

if ($video_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Vídeo inválido']);
    exit;
}

// User ID (pode ser 0 se guest — ainda contamos o share)
$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

try {
    // Verificar se vídeo existe
    $stmt = $pdo->prepare("SELECT id, shares_count FROM videos WHERE id = ?");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();

    if (!$video) {
        echo json_encode(['success' => false, 'error' => 'Vídeo não encontrado']);
        exit;
    }

    // Anti-spam: máx 1 share por user/vídeo/plataforma por hora
    if ($user_id > 0) {
        $stmt = $pdo->prepare("
            SELECT id FROM shares 
            WHERE video_id = ? AND user_id = ? AND platform = ? 
            AND shared_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 1
        ");
        $stmt->execute([$video_id, $user_id, $platform]);
        if ($stmt->fetch()) {
            // Já partilhou recentemente — retornar sucesso sem incrementar
            echo json_encode([
                'success' => true,
                'shares_count' => (int) $video['shares_count'],
                'duplicate' => true
            ]);
            exit;
        }
    }

    // Registar share e incrementar contador
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO shares (video_id, user_id, platform, shared_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$video_id, $user_id ?: null, $platform]);

    $stmt = $pdo->prepare("UPDATE videos SET shares_count = shares_count + 1 WHERE id = ?");
    $stmt->execute([$video_id]);

    $pdo->commit();

    $new_count = (int) $video['shares_count'] + 1;

    echo json_encode([
        'success' => true,
        'shares_count' => $new_count
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('record_share.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao registar partilha']);
}
