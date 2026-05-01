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

// Destruir sessão completamente
session_unset();
session_destroy();

// ✅ CRÍTICO: Deletar o cookie de sessão do navegador
// Sem isso, o cookie antigo pode persistir e causar conflitos
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Redirecionar para login
redirect('login.php');
?>