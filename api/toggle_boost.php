<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!isAdminUser()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas o admin pode dar boost em vídeos']);
    exit;
}

$video_id  = isset($_POST['video_id'])  ? (int) $_POST['video_id']  : 0;
$boosted   = isset($_POST['boosted'])   ? (int) $_POST['boosted']   : null;

if ($video_id <= 0 || ($boosted !== 0 && $boosted !== 1)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

try {
    // Confirm video exists
    $stmt = $pdo->prepare("SELECT id, title FROM videos WHERE id = ? AND is_public = 1");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();

    if (!$video) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Vídeo não encontrado']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE videos SET is_boosted = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$boosted, $video_id]);

    echo json_encode([
        'success'    => true,
        'is_boosted' => (bool) $boosted,
        'video_id'   => $video_id
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
