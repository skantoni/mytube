<?php
/**
 * API: Registar impressão de vídeo boosted
 * POST: video_ids[] — array de IDs de vídeos boosted que foram mostrados ao user
 * 
 * Usado pelo frontend para tracking de CTR e cap diário
 */
header('Content-Type: application/json');
require_once '../includes/config.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$video_ids = isset($_POST['video_ids']) ? json_decode($_POST['video_ids'], true) : [];

if (empty($video_ids) || !is_array($video_ids)) {
    echo json_encode(['success' => true, 'tracked' => 0]);
    exit;
}

$today = date('Y-m-d');
$tracked = 0;

try {
    $stmt = $pdo->prepare("
        INSERT INTO boost_impressions (video_id, user_id, impression_date, impressions)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE impressions = impressions + 1, updated_at = NOW()
    ");

    foreach ($video_ids as $video_id) {
        $video_id = (int) $video_id;
        if ($video_id > 0) {
            $stmt->execute([$video_id, $user_id, $today]);
            $tracked++;
        }
    }

    echo json_encode(['success' => true, 'tracked' => $tracked]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao registar impressão']);
}
?>
