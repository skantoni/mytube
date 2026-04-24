<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    if (empty($search)) {
        // Retornar usuários que já tem conversa
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                u.id,
                u.username,
                u.full_name,
                u.profile_picture
            FROM users u
            JOIN conversations c ON (c.user1_id = u.id OR c.user2_id = u.id)
            WHERE (c.user1_id = ? OR c.user2_id = ?)
            AND u.id != ?
            LIMIT 20
        ");
        $stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
    } else {
        // Buscar usuários por nome
        $search_param = "%$search%";
        $stmt = $pdo->prepare("
            SELECT 
                id,
                username,
                full_name,
                profile_picture
            FROM users
            WHERE (username LIKE ? OR full_name LIKE ?)
            AND id != ?
            LIMIT 20
        ");
        $stmt->execute([$search_param, $search_param, $current_user_id]);
    }
    
    $users = $stmt->fetchAll();
    
    // Ajustar caminho das imagens
    foreach ($users as &$user) {
        $user['profile_picture'] = avatar_url($user['profile_picture'] ?? null);
        // Compatibilidade: tiktok.js usa profile_picture directamente como URL completa
    }
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar usuários: ' . $e->getMessage()
    ]);
}
?>
