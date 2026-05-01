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

$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// ✅ SEGURANÇA: Validar apenas token da sessão (servidor)
// Token nunca é enviado pelo cliente (previne interceptação)
if (!isset($_SESSION['reset_token']) || !isset($_SESSION['reset_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão de redefinição inválida. Reinicie o processo.']);
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

// Atualizar senha apenas da conta específica (usando user_id)
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
$result = $stmt->execute([$hashedPassword, $userId]);

if ($result) {
    // Marcar código como usado
    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
    $stmt->execute([$codeId]);
    
    // Invalidar todos os códigos pendentes deste usuário
    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
    $stmt->execute([$userId]);
    
    // Limpar sessão de reset
    unset($_SESSION['reset_token']);
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_code_id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Senha redefinida com sucesso! Faça login com sua nova senha.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao redefinir senha. Tente novamente.']);
}
