<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

// Validar CSRF token
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de segurança inválido.']);
    exit;
}

$email = trim($_POST['email'] ?? '');
$code = trim($_POST['code'] ?? '');

if (empty($email) || empty($code)) {
    echo json_encode(['success' => false, 'message' => 'E-mail e código são obrigatórios.']);
    exit;
}

if (!preg_match('/^\d{6}$/', $code)) {
    echo json_encode(['success' => false, 'message' => 'Código inválido. Deve conter 6 dígitos.']);
    exit;
}

// ✅ PROTEÇÃO RATE LIMITING (previne brute force no código de 6 dígitos)
require_once '../includes/rate_limit.php';
$client_ip = rate_limit_get_client_ip();

// Verificar rate limit por IP (10 tentativas em 15 minutos)
$rate_limit_ip = rate_limit_check($pdo, 'reset_code', $client_ip, 10, 15);

// Verificar rate limit por email (5 tentativas em 15 minutos)
$rate_limit_email = rate_limit_check($pdo, 'reset_code_email', strtolower($email), 5, 15);

if ($rate_limit_ip['blocked']) {
    $time_remaining = rate_limit_format_time_remaining($rate_limit_ip['reset_at']);
    echo json_encode([
        'success' => false,
        'message' => "Muitas tentativas. Tente novamente em $time_remaining."
    ]);
    exit;
}

if ($rate_limit_email['blocked']) {
    $time_remaining = rate_limit_format_time_remaining($rate_limit_email['reset_at']);
    echo json_encode([
        'success' => false,
        'message' => "Muitas tentativas para este email. Tente novamente em $time_remaining."
    ]);
    exit;
}

// Buscar código válido
$stmt = $pdo->prepare("
    SELECT pr.*, u.username 
    FROM password_resets pr 
    JOIN users u ON u.id = pr.user_id 
    WHERE pr.email = ? 
      AND pr.reset_code = ? 
      AND pr.used = 0 
      AND pr.expires_at > NOW()
    ORDER BY pr.created_at DESC 
    LIMIT 1
");
$stmt->execute([$email, $code]);
$reset = $stmt->fetch();

if (!$reset) {
    // ❌ Código inválido ou expirado - Registrar tentativa
    rate_limit_record($pdo, 'reset_code', $client_ip, false);
    rate_limit_record($pdo, 'reset_code_email', strtolower($email), false);
    
    // Verificar se o código expirou
    $stmt2 = $pdo->prepare("SELECT id FROM password_resets WHERE email = ? AND reset_code = ? AND used = 0 AND expires_at <= NOW()");
    $stmt2->execute([$email, $code]);
    
    if ($stmt2->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Código expirado. Solicite um novo código.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Código inválido. Verifique e tente novamente.']);
    }
    exit;
}

// ✅ Código válido - Limpar rate limit
rate_limit_record($pdo, 'reset_code', $client_ip, true);
rate_limit_record($pdo, 'reset_code_email', strtolower($email), true);

// Gerar token temporário para a próxima etapa (redefinir senha)
$resetToken = bin2hex(random_bytes(32));

// Guardar token na sessão para validar na etapa de reset
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['reset_token'] = $resetToken;
$_SESSION['reset_user_id'] = $reset['user_id'];
$_SESSION['reset_code_id'] = $reset['id'];

// ✅ SEGURANÇA: Token NÃO é retornado ao cliente (apenas em sessão)
// Previne: interceptação, XSS, logs, histórico do navegador
echo json_encode([
    'success' => true,
    'message' => 'Código verificado com sucesso!'
    // reset_token REMOVIDO - apenas em $_SESSION
]);
