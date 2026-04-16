<?php
/**
 * API para gerir subscrições de Push Notifications
 * POST com action=subscribe → salva subscrição
 * POST com action=unsubscribe → remove subscrição
 * GET → retorna se o utilizador tem subscrição ativa + chave pública VAPID
 */

session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// GET — retorna config push (chave VAPID pública + estado da subscrição)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $configFile = __DIR__ . '/../includes/push_config.php';
    if (!file_exists($configFile)) {
        echo json_encode(['success' => true, 'enabled' => false]);
        exit;
    }
    
    require_once $configFile;
    
    if (!defined('PUSH_ENABLED') || !PUSH_ENABLED || !defined('VAPID_PUBLIC_KEY') || empty(VAPID_PUBLIC_KEY)) {
        echo json_encode(['success' => true, 'enabled' => false]);
        exit;
    }
    
    // Verificar se o utilizador já tem subscrição
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $hasSubscription = $stmt->fetchColumn() > 0;
    
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'vapid_public_key' => VAPID_PUBLIC_KEY,
        'subscribed' => $hasSubscription
    ]);
    exit;
}

// POST — subscribe/unsubscribe
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'subscribe') {
    $subscription = $input['subscription'] ?? null;
    
    if (!$subscription || empty($subscription['endpoint']) || empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados de subscrição inválidos']);
        exit;
    }
    
    $endpoint = $subscription['endpoint'];
    $p256dh = $subscription['keys']['p256dh'];
    $auth = $subscription['keys']['auth'];
    
    // Validar tamanhos
    if (strlen($endpoint) > 500 || strlen($p256dh) > 500 || strlen($auth) > 255) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados demasiado longos']);
        exit;
    }
    
    try {
        // Upsert: se o endpoint já existe, atualizar; senão, inserir
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                user_id = VALUES(user_id),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                updated_at = NOW()
        ");
        $stmt->execute([$user_id, $endpoint, $p256dh, $auth]);
        
        echo json_encode(['success' => true, 'message' => 'Subscrição ativada']);
    } catch (Exception $e) {
        error_log("push_subscribe: Erro ao salvar subscrição: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar subscrição']);
    }
    
} elseif ($action === 'unsubscribe') {
    $endpoint = $input['endpoint'] ?? '';
    
    try {
        if ($endpoint) {
            // Remover subscrição específica
            $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
            $stmt->execute([$user_id, $endpoint]);
        } else {
            // Remover todas as subscrições do utilizador
            $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ?");
            $stmt->execute([$user_id]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Subscrição removida']);
    } catch (Exception $e) {
        error_log("push_subscribe: Erro ao remover subscrição: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao remover subscrição']);
    }
    
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Acção inválida. Use subscribe ou unsubscribe']);
}
