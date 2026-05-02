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
// Limpar cookies de TODOS os domínios possíveis para eliminar sessões órfãs
// (users que acederam via www.mytube.social antes do redirect)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $cookieName = session_name();
    
    // 1. Cookie com os parâmetros actuais (domain do PHP default)
    setcookie($cookieName, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    
    // 2. Cookie sem domain (cobre o caso default do PHP)
    setcookie($cookieName, '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
    
    // 3. Cookie para mytube.social (sem www)
    setcookie($cookieName, '', time() - 42000, '/', 'mytube.social', $params['secure'], $params['httponly']);
    
    // 4. Cookie para www.mytube.social (legado)
    setcookie($cookieName, '', time() - 42000, '/', 'www.mytube.social', $params['secure'], $params['httponly']);
    
    // 5. Cookie para .mytube.social (wildcard — cobre ambos)
    setcookie($cookieName, '', time() - 42000, '/', '.mytube.social', $params['secure'], $params['httponly']);
}

// Redirecionar para login
redirect('login.php');
?>