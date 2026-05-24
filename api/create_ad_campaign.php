<?php
/**
 * API: Criar campanha de anúncio
 * POST /api/create_ad_campaign.php
 */
require_once '../includes/config.php';
require_once '../includes/rate_limit.php';

header('Content-Type: application/json');

// Auth
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// CSRF
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

// Rate limit: máx 5 campanhas por hora
$rl = rate_limit_check($pdo, 'create_ad', 'user_' . $_SESSION['user_id'], 5, 60);
if ($rl['blocked']) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Muitas tentativas. Aguarda uns minutos.']);
    exit;
}
rate_limit_record($pdo, 'create_ad', 'user_' . $_SESSION['user_id'], false);

$user_id = (int)$_SESSION['user_id'];

// ── Input validation ───────────────────────────────────────────────
$video_id   = isset($_POST['video_id'])   ? (int)$_POST['video_id']   : 0;
$plan_id    = isset($_POST['plan_id'])    ? (int)$_POST['plan_id']    : 0;
$gender     = in_array($_POST['target_gender'] ?? '', ['all','male','female'])
              ? $_POST['target_gender'] : 'all';
$age_min    = isset($_POST['target_age_min']) && $_POST['target_age_min'] !== ''
              ? max(13, min(99, (int)$_POST['target_age_min'])) : null;
$age_max    = isset($_POST['target_age_max']) && $_POST['target_age_max'] !== ''
              ? max(13, min(99, (int)$_POST['target_age_max'])) : null;
$location   = isset($_POST['target_location'])
              ? mb_substr(trim($_POST['target_location']), 0, 120) : null;
$location   = ($location === '') ? null : $location;

if ($video_id <= 0 || $plan_id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

try {
    // Verify video belongs to user and is approved/public
    $stmt = $pdo->prepare("
        SELECT id, title FROM videos
        WHERE id = ? AND user_id = ? AND is_public = 1 AND moderation_status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$video_id, $user_id]);
    $video = $stmt->fetch();

    if (!$video) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Vídeo não encontrado ou não aprovado']);
        exit;
    }

    // Verify plan exists and is active
    $stmt = $pdo->prepare("SELECT * FROM ad_plans WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch();

    if (!$plan) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Plano inválido']);
        exit;
    }

    // Check if there is already an active/pending campaign for this video
    $stmt = $pdo->prepare("
        SELECT id FROM ad_campaigns
        WHERE user_id = ? AND video_id = ? AND status IN ('pending','active')
        LIMIT 1
    ");
    $stmt->execute([$user_id, $video_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Já existe uma campanha ativa ou pendente para este vídeo.']);
        exit;
    }

    // Insert campaign
    $stmt = $pdo->prepare("
        INSERT INTO ad_campaigns
            (user_id, video_id, plan_id, plan_name, plan_days, plan_price_kz,
             target_gender, target_age_min, target_age_max, target_location,
             status, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?,
             'pending', NOW(), NOW())
    ");
    $stmt->execute([
        $user_id,
        $video_id,
        $plan_id,
        $plan['name'],
        (int)$plan['days'],
        (int)$plan['price_kz'],
        $gender,
        $age_min,
        $age_max,
        $location,
    ]);

    $campaign_id = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'     => true,
        'campaign_id' => $campaign_id,
        'message'     => 'Campanha submetida com sucesso! Aguarda aprovação.',
    ]);

} catch (Exception $e) {
    error_log('create_ad_campaign error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
