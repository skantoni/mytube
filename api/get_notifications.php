<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit;
}

$user_id = $_SESSION['user_id'];
$count_only = isset($_GET['count_only']) && $_GET['count_only'] === '1';
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = min(20, max(1, (int)($_GET['limit'] ?? 20)));

try {
    // Contar não lidas
    $countStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0) +
            (SELECT COUNT(*) FROM global_notifications gn 
             WHERE gn.created_at >= (SELECT created_at FROM users WHERE id = ?) 
               AND NOT EXISTS (SELECT 1 FROM user_global_reads ugr WHERE ugr.global_notification_id = gn.id AND ugr.user_id = ?))
    ");
    $countStmt->execute([$user_id, $user_id, $user_id]);
    $unread_count = (int)$countStmt->fetchColumn();
    
    if ($count_only) {
        // Para polling: resposta leve, apenas contagem
        echo json_encode([
            'success' => true,
            'unread_count' => $unread_count
        ]);
        exit;
    }
    
    // Buscar notificações com paginação e colunas específicas
    $stmt = $pdo->prepare("
        SELECT 
            id, type, reference_id, comment_id, is_read, created_at, actor_username, actor_avatar, notif_scope, custom_message,
            CASE 
                WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 1 THEN 'agora'
                WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, created_at, NOW()), ' min')
                WHEN TIMESTAMPDIFF(HOUR, created_at, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, created_at, NOW()), ' h')
                WHEN TIMESTAMPDIFF(DAY, created_at, NOW()) < 7 THEN CONCAT(TIMESTAMPDIFF(DAY, created_at, NOW()), ' d')
                ELSE DATE_FORMAT(created_at, '%d/%m/%Y')
            END as time_ago
        FROM (
            SELECT 
                n.id, n.type, n.reference_id, n.comment_id, n.is_read, n.created_at,
                u.username as actor_username, u.profile_picture as actor_avatar,
                'personal' as notif_scope, NULL as custom_message
            FROM notifications n
            JOIN users u ON n.actor_id = u.id
            WHERE n.user_id = ?
            
            UNION ALL
            
            SELECT 
                gn.id, gn.type, gn.reference_id, NULL as comment_id,
                IF(ugr.user_id IS NOT NULL, 1, 0) as is_read, gn.created_at,
                'Sistema' as actor_username, 'default.webp' as actor_avatar,
                'global' as notif_scope, gn.message as custom_message
            FROM global_notifications gn
            LEFT JOIN user_global_reads ugr ON gn.id = ugr.global_notification_id AND ugr.user_id = ?
            WHERE gn.created_at >= (SELECT created_at FROM users WHERE id = ?)
        ) matched_notifs
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $limit + 1, $offset]);
    $notifications = $stmt->fetchAll();
    
    // Verificar se há mais resultados
    $has_more = count($notifications) > $limit;
    if ($has_more) {
        array_pop($notifications); // Remover o item extra
    }
    
    // Formatar mensagens
    $formattedNotifs = array_map(function($notif) {
        $message = '';
        switch ($notif['type']) {
            case 'like':
                $message = 'curtiu seu vídeo';
                break;
            case 'comment':
                $message = 'comentou no seu vídeo';
                break;
            case 'reply':
                $message = 'respondeu ao seu comentário';
                break;
            case 'comment_like':
                $message = 'curtiu seu comentário';
                break;
            case 'follow':
                $message = 'começou a seguir você';
                break;
            case 'unfollow':
                $message = 'parou de seguir você';
                break;
            case 'friend_request':
                $message = 'enviou-te um pedido de amizade';
                break;
            case 'friend_accept':
                $message = 'aceitou o seu pedido de amizade';
                break;
            case 'mention':
                $message = 'mencionou você em um comentário';
                break;
            case 'bio_mention':
                $message = 'mencionou você na bio';
                break;
            case 'best_mytuber_global':
                $message = $notif['custom_message'] ?? 'Novo Best MyTuber anunciado!';
                break;
            default:
                $message = '';
        }
        
        return [
            'id' => $notif['id'],
            'type' => $notif['type'],
            'actor_username' => $notif['actor_username'],
            'actor_avatar' => $notif['actor_avatar'] ?? 'default.webp',
            'message' => $message,
            'reference_id' => $notif['reference_id'],
            'comment_id' => $notif['comment_id'] ?? null,
            'is_read' => (bool)$notif['is_read'],
            'time_ago' => $notif['time_ago'],
            'notif_scope' => $notif['notif_scope'] ?? 'personal'
        ];
    }, $notifications);
    
    echo json_encode([
        'success' => true,
        'notifications' => $formattedNotifs,
        'unread_count' => $unread_count,
        'has_more' => $has_more,
        'offset' => $offset + count($formattedNotifs)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
?>
