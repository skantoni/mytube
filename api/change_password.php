<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
    echo json_encode(['success' => false, 'message' => 'Preencha todos os campos.']);
    exit;
}

if (strlen($new_password) < 6) {
    echo json_encode(['success' => false, 'message' => 'A nova senha deve ter pelo menos 6 caracteres.']);
    exit;
}

if ($new_password !== $confirm_password) {
    echo json_encode(['success' => false, 'message' => 'As senhas não coincidem.']);
    exit;
}

// Buscar senha atual do banco
$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
    exit;
}

if (!password_verify($current_password, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Senha atual incorreta.']);
    exit;
}

if ($current_password === $new_password) {
    echo json_encode(['success' => false, 'message' => 'A nova senha deve ser diferente da atual.']);
    exit;
}

// Atualizar senha
$hashed = password_hash($new_password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");

if ($stmt->execute([$hashed, $user_id])) {
    echo json_encode(['success' => true, 'message' => 'Senha alterada com sucesso!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao alterar senha. Tente novamente.']);
}
