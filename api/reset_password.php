<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$resetToken = trim($_POST['reset_token'] ?? '');
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Validar token da sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($resetToken) || !isset($_SESSION['reset_token']) || $resetToken !== $_SESSION['reset_token']) {
    echo json_encode(['success' => false, 'message' => 'Token de redefinição inválido. Reinicie o processo.']);
    exit;
}

if (empty($newPassword) || empty($confirmPassword)) {
    echo json_encode(['success' => false, 'message' => 'Por favor, preencha todos os campos.']);
    exit;
}

if (strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'A senha deve ter pelo menos 6 caracteres.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'As senhas não conferem.']);
    exit;
}

$userId = $_SESSION['reset_user_id'];
$codeId = $_SESSION['reset_code_id'];
$email = $_SESSION['reset_email'];

// Atualizar senha de todas as contas vinculadas a este e-mail
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
$result = $stmt->execute([$hashedPassword, $email]);

if ($result) {
    // Marcar código como usado
    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
    $stmt->execute([$codeId]);
    
    // Invalidar todos os códigos pendentes deste e-mail
    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0");
    $stmt->execute([$email]);
    
    // Limpar sessão de reset
    unset($_SESSION['reset_token']);
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_code_id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Senha redefinida com sucesso! Faça login com sua nova senha.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao redefinir senha. Tente novamente.']);
}
