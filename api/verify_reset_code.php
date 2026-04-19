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

// Gerar token temporário para a próxima etapa (redefinir senha)
$resetToken = bin2hex(random_bytes(32));

// Guardar token na sessão para validar na etapa de reset
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['reset_token'] = $resetToken;
$_SESSION['reset_user_id'] = $reset['user_id'];
$_SESSION['reset_code_id'] = $reset['id'];

echo json_encode([
    'success' => true,
    'message' => 'Código verificado com sucesso!',
    'reset_token' => $resetToken
]);
