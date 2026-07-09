<?php
/**
 * API: Submeter pedido de subscrição Premium
 *
 * POST /api/submit_premium_request.php
 * Body JSON: { plan_months, express_ref, phone }
 *
 * Retorna JSON { success, message }
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Precisas de estar autenticado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

csrf_verify_or_die('Token de segurança inválido.');

$user_id = (int)$_SESSION['user_id'];

// Rate limit: máximo 3 pedidos por hora por utilizador
$rl_key = 'premium_request_' . $user_id;
if (!checkRateLimit($rl_key, 3, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Demasiados pedidos. Tenta novamente mais tarde.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$plan_months = (int)($body['plan_months'] ?? 1);
$express_ref = trim($body['express_ref'] ?? '');
$phone       = trim($body['phone'] ?? '');

// Validações
if (!in_array($plan_months, [1, 3, 6, 12], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Plano inválido.']);
    exit;
}

if (strlen($express_ref) < 5 || strlen($express_ref) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Referência/comprovativo inválido. Indica o número de referência do pagamento Express.']);
    exit;
}

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Indica o teu número de telemóvel para contacto.']);
    exit;
}

// Preços por plano (em AOA - Kwanzas)
$prices = [
    1  => 500,
    3  => 1300,
    6  => 2400,
    12 => 4500,
];
$amount_kz = $prices[$plan_months];

// Verificar se o utilizador já tem um pedido pendente
$existing = $pdo->prepare("
    SELECT id FROM premium_requests
    WHERE user_id = ? AND status = 'pending'
    LIMIT 1
");
$existing->execute([$user_id]);
if ($existing->fetch()) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Já tens um pedido pendente. Aguarda a aprovação ou contacta-nos se fizeste o pagamento.',
    ]);
    exit;
}

// Verificar se já é premium ativo
$current = $pdo->prepare("SELECT is_premium, premium_expires FROM users WHERE id = ? LIMIT 1");
$current->execute([$user_id]);
$user_row = $current->fetch();
if ($user_row && $user_row['is_premium'] && $user_row['premium_expires'] && strtotime($user_row['premium_expires']) > time()) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'A tua conta já é Premium. Podes renovar 7 dias antes de expirar.',
    ]);
    exit;
}

// Inserir pedido
$ins = $pdo->prepare("
    INSERT INTO premium_requests (user_id, plan_months, amount_kz, express_ref, phone, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', NOW())
");
$ins->execute([$user_id, $plan_months, $amount_kz, $express_ref, $phone]);

// Tentar enviar notificação WhatsApp ao admin (não crítico - não falha o request)
try {
    require_once __DIR__ . '/../includes/whatsapp_helper.php';

    $stmt_user = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ? LIMIT 1");
    $stmt_user->execute([$user_id]);
    $u = $stmt_user->fetch();

    // Número do admin (alterar conforme necessário)
    $admin_phone = '244938282223'; // número do Skeny para receber notificações

    $msg = "🌟 *Novo Pedido Premium*\n\n";
    $msg .= "👤 *Utilizador:* @{$u['username']} ({$u['full_name']})\n";
    $msg .= "📦 *Plano:* {$plan_months} mês(es)\n";
    $msg .= "💰 *Valor:* " . number_format($amount_kz, 0, ',', '.') . " AOA\n";
    $msg .= "📲 *Contacto:* {$phone}\n";
    $msg .= "🧾 *Ref. Express:* {$express_ref}\n\n";
    $msg .= "Acede ao painel de admin para aprovar ou rejeitar.";

    sendWhatsappMessage($admin_phone, $msg);
} catch (Exception $e) {
    error_log('[Premium] Falha ao enviar notificação WhatsApp: ' . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'message' => 'Pedido enviado com sucesso! Vamos verificar o teu pagamento e ativar o Premium em até 24h.',
]);
