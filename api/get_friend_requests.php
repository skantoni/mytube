<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$type = isset($_GET['type']) ? $_GET['type'] : 'received';

try {
    if ($type === 'received') {
        // Pedidos recebidos pendentes
        $stmt = $pdo->prepare("
            SELECT fr.id, fr.sender_id, fr.created_at, 
                   u.username, u.profile_picture, u.is_verified
            FROM friend_requests fr
            JOIN users u ON fr.sender_id = u.id
            WHERE fr.receiver_id = ? AND fr.status = 'pending'
            ORDER BY fr.created_at DESC
        ");
        $stmt->execute([$current_user_id]);
    } elseif ($type === 'sent') {
        // Pedidos enviados pendentes
        $stmt = $pdo->prepare("
            SELECT fr.id, fr.receiver_id, fr.status, fr.created_at,
                   u.username, u.profile_picture, u.is_verified
            FROM friend_requests fr
            JOIN users u ON fr.receiver_id = u.id
            WHERE fr.sender_id = ? AND fr.status = 'pending'
            ORDER BY fr.created_at DESC
        ");
        $stmt->execute([$current_user_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Tipo inválido']);
        exit;
    }

    $requests = $stmt->fetchAll();
    
    // Contar pedidos pendentes recebidos
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM friend_requests WHERE receiver_id = ? AND status = 'pending'");
    $countStmt->execute([$current_user_id]);
    $pending_count = $countStmt->fetchColumn();

    echo json_encode([
        'success' => true, 
        'requests' => $requests,
        'pending_count' => (int)$pending_count
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar pedidos']);
}
