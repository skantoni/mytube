<?php
require_once 'includes/config.php';

// Só permite logout se estiver logado
if (!isLoggedIn()) {
    redirect('login.php');
}

// Marcar como offline no banco antes de destruir a sessão
try {
    $stmt = $pdo->prepare("
        UPDATE user_online_status 
        SET is_online = 0, last_seen = NOW() 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
} catch (Exception $e) {
    // Silenciar erro — o logout deve sempre funcionar
}

// Destruir sessão
session_unset();
session_destroy();

// Redirecionar para login
redirect('login.php');
?>