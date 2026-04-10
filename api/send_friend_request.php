<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$receiver_id = isset($_POST['receiver_id']) ? intval($_POST['receiver_id']) : 0;

if ($receiver_id <= 0 || $receiver_id === $current_user_id) {
    echo json_encode(['success' => false, 'error' => 'Utilizador inválido']);
    exit;
}

try {
    // Verificar se já existe um pedido pendente em qualquer direção
    $stmt = $pdo->prepare("
        SELECT id, sender_id, status FROM friend_requests 
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$current_user_id, $receiver_id, $receiver_id, $current_user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'accepted') {
            echo json_encode(['success' => false, 'error' => 'Já são amigos']);
            exit;
        }
        if ($existing['status'] === 'pending') {
            if ($existing['sender_id'] == $current_user_id) {
                echo json_encode(['success' => false, 'error' => 'Pedido já enviado']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Este utilizador já te enviou um pedido. Aceita-o!']);
            }
            exit;
        }
        // Se foi rejeitado, permitir reenviar (atualizar)
        if ($existing['status'] === 'rejected') {
            $stmt = $pdo->prepare("
                UPDATE friend_requests SET sender_id = ?, receiver_id = ?, status = 'pending', updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$current_user_id, $receiver_id, $existing['id']]);

            // Notificar o receiver
            try {
                $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, actor_id, type, reference_id, created_at) VALUES (?, ?, 'friend_request', ?, NOW())");
                $notifStmt->execute([$receiver_id, $current_user_id, $current_user_id]);
            } catch (Exception $e) {
                error_log('⚠️ Erro notificação friend_request: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'message' => 'Pedido de amizade reenviado']);
            exit;
        }
    }

    // Criar novo pedido
    $stmt = $pdo->prepare("INSERT INTO friend_requests (sender_id, receiver_id, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$current_user_id, $receiver_id]);

    // Notificar o receiver
    try {
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, actor_id, type, reference_id, created_at) VALUES (?, ?, 'friend_request', ?, NOW())");
        $notifStmt->execute([$receiver_id, $current_user_id, $current_user_id]);
    } catch (Exception $e) {
        error_log('⚠️ Erro notificação friend_request: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Pedido de amizade enviado']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao enviar pedido']);
}
