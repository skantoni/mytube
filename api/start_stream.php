<?php
/**
 * API: Iniciar uma livestream
 * Cria o registo na DB e retorna stream_id e stream_key
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

// 1. Autenticação
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Utilizador não autenticado']);
    exit;
}

// 2. Método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// 3. CSRF
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido']);
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];

// 4. Input
$input       = json_decode(file_get_contents('php://input'), true);
$title       = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$category    = trim($input['category'] ?? '');

// 5. Validação
if (empty($title)) {
    http_response_code(400);
    echo json_encode(['error' => 'O título é obrigatório']);
    exit;
}

if (mb_strlen($title) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'O título é demasiado longo (máx. 255 caracteres)']);
    exit;
}

// 6. Verificar se já tem uma stream ativa
try {
    $stmt = $pdo->prepare("SELECT id FROM livestreams WHERE user_id = ? AND status IN ('waiting','live') LIMIT 1");
    $stmt->execute([$current_user_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Já tens uma stream ativa. Termina-a primeiro.']);
        exit;
    }
} catch (Exception $e) {
    error_log('❌ start_stream.php: verificação de stream ativa: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
    exit;
}

// 7. Gerar stream_key único (64 chars hex)
$stream_key = bin2hex(random_bytes(32));

// 8. Inserir na DB
try {
    $stmt = $pdo->prepare("
        INSERT INTO livestreams (user_id, title, description, category, stream_key, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'waiting', NOW())
    ");
    $stmt->execute([
        $current_user_id,
        sanitize($title),
        sanitize($description),
        sanitize($category),
        $stream_key
    ]);
    $stream_id = $pdo->lastInsertId();

    echo json_encode([
        'success'    => true,
        'stream_id'  => (int)$stream_id,
        'stream_key' => $stream_key
    ]);
} catch (Exception $e) {
    error_log('❌ start_stream.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao criar stream']);
}
