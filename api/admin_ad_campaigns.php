<?php
/**
 * API: Gestão de Campanhas (Admin only)
 * POST /api/admin_ad_campaigns.php
 * Actions: approve, reject, pause, activate, expire
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdminUser()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

$action      = $_POST['action']      ?? '';
$campaign_id = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;

if ($campaign_id <= 0 || !in_array($action, ['approve','reject','pause','activate','expire','delete'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

try {
    // Load campaign
    $stmt = $pdo->prepare("SELECT * FROM ad_campaigns WHERE id = ? LIMIT 1");
    $stmt->execute([$campaign_id]);
    $camp = $stmt->fetch();

    if (!$camp) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Campanha não encontrada']);
        exit;
    }

    switch ($action) {
        case 'approve':
            $days = (int)$camp['plan_days'];
            $starts = date('Y-m-d H:i:s');
            $ends   = date('Y-m-d H:i:s', strtotime("+{$days} days"));
            $stmt = $pdo->prepare("
                UPDATE ad_campaigns
                SET status='active', starts_at=?, ends_at=?, paid_at=NOW(), updated_at=NOW()
                WHERE id=?
            ");
            $stmt->execute([$starts, $ends, $campaign_id]);

            // Also boost the video
            $pdo->prepare("UPDATE videos SET is_boosted=1, updated_at=NOW() WHERE id=?")
                ->execute([$camp['video_id']]);

            echo json_encode(['success' => true, 'status' => 'active', 'ends_at' => $ends]);
            break;

        case 'reject':
            $reason = mb_substr(trim($_POST['reason'] ?? 'Violação das políticas de publicidade.'), 0, 255);
            $stmt = $pdo->prepare("
                UPDATE ad_campaigns
                SET status='rejected', rejection_reason=?, updated_at=NOW()
                WHERE id=?
            ");
            $stmt->execute([$reason, $campaign_id]);
            echo json_encode(['success' => true, 'status' => 'rejected']);
            break;

        case 'pause':
            $pdo->prepare("UPDATE ad_campaigns SET status='paused', updated_at=NOW() WHERE id=?")
                ->execute([$campaign_id]);
            // Pause the boost too
            $pdo->prepare("UPDATE videos SET is_boosted=0, updated_at=NOW() WHERE id=?")
                ->execute([$camp['video_id']]);
            echo json_encode(['success' => true, 'status' => 'paused']);
            break;

        case 'activate':
            $pdo->prepare("UPDATE ad_campaigns SET status='active', updated_at=NOW() WHERE id=?")
                ->execute([$campaign_id]);
            $pdo->prepare("UPDATE videos SET is_boosted=1, updated_at=NOW() WHERE id=?")
                ->execute([$camp['video_id']]);
            echo json_encode(['success' => true, 'status' => 'active']);
            break;

        case 'expire':
            $pdo->prepare("UPDATE ad_campaigns SET status='expired', ends_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$campaign_id]);
            $pdo->prepare("UPDATE videos SET is_boosted=0, updated_at=NOW() WHERE id=?")
                ->execute([$camp['video_id']]);
            echo json_encode(['success' => true, 'status' => 'expired']);
            break;

        case 'delete':
            $pdo->prepare("DELETE FROM ad_campaigns WHERE id=?")->execute([$campaign_id]);
            echo json_encode(['success' => true, 'deleted' => true]);
            break;
    }

} catch (Exception $e) {
    error_log('admin_ad_campaigns error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
