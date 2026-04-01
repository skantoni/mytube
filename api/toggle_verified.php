<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!isAdminUser()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas o admin pode alterar verificados']);
    exit;
}

$user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
$verified = isset($_POST['verified']) ? (int) $_POST['verified'] : null;

if ($user_id <= 0 || ($verified !== 0 && $verified !== 1)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

if ($user_id === (int) $_SESSION['user_id']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Use outro perfil para gerir verificados']);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE users SET is_verified = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
    $stmt->execute([$verified, $user_id]);

    if ($stmt->rowCount() === 0) {
        $check_stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $check_stmt->execute([$user_id]);

        if (!$check_stmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Utilizador não encontrado']);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'user_id' => $user_id,
        'is_verified' => (bool) $verified,
        'message' => $verified ? 'Utilizador marcado como verificado' : 'Selo de verificado removido'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao atualizar verificado']);
}