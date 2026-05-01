<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$user_ids = isset($_POST['user_ids']) ? json_decode($_POST['user_ids'], true) : [];

if (empty($user_ids)) {
    echo json_encode(['success' => false, 'error' => 'Nenhum usuário especificado']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    
    $stmt = $pdo->prepare("
        SELECT 
            user_id,
            is_online,
            last_seen
        FROM user_online_status
        WHERE user_id IN ($placeholders)
    ");
    
    $stmt->execute($user_ids);
    
    $statuses = [];
    while ($row = $stmt->fetch()) {
        // Só considerar online se is_online=1 E last_seen recente (< 2 min)
        // Isto previne falsos positivos de status stale preso no DB
        $isReallyOnline = (bool)$row['is_online'];
        if ($isReallyOnline && !empty($row['last_seen'])) {
            $lastSeenTime = strtotime($row['last_seen']);
            $twoMinutesAgo = time() - 120;
            if ($lastSeenTime < $twoMinutesAgo) {
                $isReallyOnline = false;
            }
        }
        
        $statuses[$row['user_id']] = [
            'is_online' => $isReallyOnline,
            'last_seen' => $row['last_seen']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'statuses' => $statuses
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao verificar status: ' . $e->getMessage()
    ]);
}
?>
