<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
$delete_type = isset($_POST['delete_type']) ? $_POST['delete_type'] : 'for_me'; // for_me ou for_all

if ($message_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Mensagem inválida']);
    exit;
}

try {
    // Verificar se a mensagem pertence ao usuário
    $stmt = $pdo->prepare("
        SELECT sender_id, receiver_id, created_at 
        FROM messages 
        WHERE id = ?
    ");
    $stmt->execute([$message_id]);
    $message = $stmt->fetch();
    
    if (!$message) {
        echo json_encode(['success' => false, 'error' => 'Mensagem não encontrada']);
        exit;
    }
    
    if ($delete_type == 'for_all') {
        // Só pode apagar para todos se for o remetente e dentro de 1 hora
        if ($message['sender_id'] != $current_user_id) {
            echo json_encode(['success' => false, 'error' => 'Você não pode apagar esta mensagem para todos']);
            exit;
        }
        
        $created_time = strtotime($message['created_at']);
        $current_time = time();
        $time_diff = $current_time - $created_time;
        
        if ($time_diff > 3600) { // 1 hora = 3600 segundos
            echo json_encode(['success' => false, 'error' => 'Tempo limite excedido para apagar para todos (1 hora)']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE messages SET deleted_for_all = TRUE WHERE id = ?");
        $stmt->execute([$message_id]);
        
        echo json_encode(['success' => true, 'deleted_for' => 'all']);
        
    } else {
        // Apagar para mim
        if ($message['sender_id'] == $current_user_id) {
            $stmt = $pdo->prepare("UPDATE messages SET deleted_for_sender = TRUE WHERE id = ?");
        } elseif ($message['receiver_id'] == $current_user_id) {
            $stmt = $pdo->prepare("UPDATE messages SET deleted_for_receiver = TRUE WHERE id = ?");
        } else {
            echo json_encode(['success' => false, 'error' => 'Você não tem permissão para apagar esta mensagem']);
            exit;
        }
        
        $stmt->execute([$message_id]);
        
        echo json_encode(['success' => true, 'deleted_for' => 'me']);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao apagar mensagem: ' . $e->getMessage()
    ]);
}
?>
