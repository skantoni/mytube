<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$other_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($other_user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Utilizador inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, sender_id, receiver_id, status FROM friend_requests 
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
    $request = $stmt->fetch();

    if (!$request) {
        echo json_encode(['success' => true, 'friendship_status' => 'none']);
    } elseif ($request['status'] === 'accepted') {
        echo json_encode(['success' => true, 'friendship_status' => 'friends']);
    } elseif ($request['status'] === 'pending') {
        $direction = ($request['sender_id'] == $current_user_id) ? 'sent' : 'received';
        echo json_encode([
            'success' => true, 
            'friendship_status' => 'pending',
            'direction' => $direction,
            'request_id' => $request['id']
        ]);
    } else {
        echo json_encode(['success' => true, 'friendship_status' => 'none']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao verificar amizade']);
}
