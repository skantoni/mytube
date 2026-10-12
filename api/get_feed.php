<?php
/**
 * API de Feed Inteligente - MyTube
 * v3.2 - Delega busca e ordenação para includes/feed_helper.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/hashtag_helper.php';
require_once __DIR__ . '/../includes/r2_storage.php';
require_once __DIR__ . '/../includes/feed_helper.php';

// Limpeza periódica de boost_impressions > 90 dias
if (empty($_SESSION['_boost_cleanup_done']) && mt_rand(1, 100) === 1) {
    $_SESSION['_boost_cleanup_done'] = true;
    try {
        $pdo->exec("DELETE FROM boost_impressions WHERE impression_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
    } catch (Exception $e) {
    }
}

// Limpeza periódica de user_feed_seen > 30 dias (~0.5% dos requests, 1x por sessão)
if (empty($_SESSION['_seen_cleanup_done']) && mt_rand(1, 200) === 1) {
    $_SESSION['_seen_cleanup_done'] = true;
    try {
        $pdo->exec("DELETE FROM user_feed_seen WHERE seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Exception $e) {
    }
}

// === ENDPOINT RÁPIDO: validar IDs de vídeos (para restauração do sessionStorage) ===
if (isset($_GET['validate_ids'])) {
    $raw_ids = array_filter(array_map('intval', explode(',', $_GET['validate_ids'])));
    if (empty($raw_ids)) {
        echo json_encode(['valid_ids' => []]);
        exit;
    }
    $ph = implode(',', array_fill(0, count($raw_ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM videos WHERE id IN ($ph) AND is_public = 1 AND moderation_status = 'approved'");
    $stmt->execute($raw_ids);
    $valid_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['valid_ids' => array_map('intval', $valid_ids)]);
    exit;
}

// Detectar modo convidado
$guest_mode = !isLoggedIn();

// Se não está logado e não é modo guest, bloquear
if ($guest_mode && !isset($_GET['guest'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$offset          = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit           = 4;
$feed_session_id = $_GET['feed_session'] ?? null;
$profile_user_id = isset($_GET['profile_user']) ? (int)$_GET['profile_user'] : 0;
$start_video_id  = isset($_GET['start_video']) ? (int)$_GET['start_video'] : 0;
$user_id         = (int)($_SESSION['user_id'] ?? 0);

try {
    $result = fetchFeedData(
        $pdo,
        $user_id,
        $feed_session_id,
        $offset,
        $limit,
        $guest_mode,
        $profile_user_id,
        $start_video_id
    );

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor',
        'message' => $e->getMessage(),
    ]);
}
exit;