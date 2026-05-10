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

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado.']);
    exit;
}

$user_id      = $_SESSION['user_id'];
$new_email    = trim($_POST['new_email'] ?? '');
$password     = $_POST['password'] ?? '';

// Campos obrigatórios
if (empty($new_email)) {
    echo json_encode(['success' => false, 'message' => 'Preencha o novo email.']);
    exit;
}

// Comprimento máximo
if (strlen($new_email) > 255) {
    echo json_encode(['success' => false, 'message' => 'Email demasiado longo.']);
    exit;
}

// Validação de formato
if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Formato de email inválido.']);
    exit;
}

// Normalizar (lowercase)
$new_email = strtolower($new_email);

// Buscar dados atuais do utilizador
$stmt = $pdo->prepare("SELECT email, password, google_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Utilizador não encontrado.']);
    exit;
}

$isGoogleUser = !empty($user['google_id']);

// Confirmar senha apenas para utilizadores sem conta Google
if (!$isGoogleUser) {
    if (empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Insira a sua senha para confirmar.']);
        exit;
    }
    if (!password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Senha incorreta.']);
        exit;
    }
}

// Verificar se é o mesmo email
if (strtolower($user['email']) === $new_email) {
    echo json_encode(['success' => false, 'message' => 'O novo email é igual ao atual.']);
    exit;
}

// Verificar se o email já está em uso
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
$stmt->execute([$new_email, $user_id]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Este email já está a ser usado por outra conta.']);
    exit;
}

// Atualizar email
$stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
if ($stmt->execute([$new_email, $user_id])) {
    echo json_encode(['success' => true, 'message' => 'Email alterado com sucesso!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Erro ao alterar email. Tente novamente.']);
}
