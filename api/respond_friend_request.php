<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($request_id <= 0 || !in_array($action, ['accept', 'reject'])) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

try {
    // Verificar se o pedido existe e é para este utilizador
    $stmt = $pdo->prepare("SELECT * FROM friend_requests WHERE id = ? AND receiver_id = ? AND status = 'pending'");
    $stmt->execute([$request_id, $current_user_id]);
    $request = $stmt->fetch();

    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }

    $new_status = ($action === 'accept') ? 'accepted' : 'rejected';
    $stmt = $pdo->prepare("UPDATE friend_requests SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$new_status, $request_id]);

    $msg = ($action === 'accept') ? 'Pedido aceite! Agora podem conversar.' : 'Pedido rejeitado.';
    echo json_encode(['success' => true, 'message' => $msg, 'action' => $action]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao processar pedido']);
}
