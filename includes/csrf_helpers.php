<?php
/**
 * CSRF Protection Helpers
 * 
 * Funções para proteção contra Cross-Site Request Forgery (CSRF)
 * 
 * Uso:
 * - Em formulários HTML: <?php echo csrf_field(); ?>
 * - Em requisições POST/API: csrf_verify() ou csrf_verify_or_die()
 * - Em AJAX: adicionar header X-CSRF-Token com valor de csrf_token()
 */

/**
 * Gerar ou obter o token CSRF da sessão
 * 
 * @return string Token CSRF de 64 caracteres
 */
function csrf_token(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Gerar campo hidden HTML com token CSRF
 * 
 * @return string HTML input hidden
 */
function csrf_field(): string {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Gerar meta tag para uso em AJAX
 * 
 * @return string HTML meta tag
 */
function csrf_meta(): string {
    $token = csrf_token();
    return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verificar se o token CSRF é válido
 * 
 * Aceita token de:
 * 1. POST/GET parameter 'csrf_token'
 * 2. HTTP Header 'X-CSRF-Token'
 * 3. HTTP Header 'X-XSRF-TOKEN' (Laravel/Angular compatibility)
 * 
 * @return bool True se válido, False se inválido
 */
function csrf_verify(): bool {
    // Obter token da requisição
    $request_token = null;
    
    // 1. Tentar obter de POST/GET (FormData ou form-urlencoded)
    if (isset($_POST['csrf_token'])) {
        $request_token = $_POST['csrf_token'];
    } elseif (isset($_GET['csrf_token'])) {
        $request_token = $_GET['csrf_token'];
    }
    
    // 2. Tentar obter de headers HTTP (múltiplas variações)
    if (!$request_token) {
        // Verificar $_SERVER primeiro (mais confiável que getallheaders)
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $request_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        } elseif (isset($_SERVER['HTTP_X_XSRF_TOKEN'])) {
            $request_token = $_SERVER['HTTP_X_XSRF_TOKEN'];
        }
        // Fallback para getallheaders se disponível
        elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['X-CSRF-Token'])) {
                $request_token = $headers['X-CSRF-Token'];
            } elseif (isset($headers['X-Csrf-Token'])) {
                $request_token = $headers['X-Csrf-Token'];
            } elseif (isset($headers['x-csrf-token'])) {
                $request_token = $headers['x-csrf-token'];
            } elseif (isset($headers['X-XSRF-TOKEN'])) {
                $request_token = $headers['X-XSRF-TOKEN'];
            }
        }
    }
    
    // 3. Tentar obter do body JSON (requests com Content-Type: application/json)
    // PHP não popula $_POST para JSON — precisamos ler php://input
    if (!$request_token) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $rawBody = file_get_contents('php://input');
            $jsonData = json_decode($rawBody, true);
            if (is_array($jsonData) && isset($jsonData['csrf_token'])) {
                $request_token = $jsonData['csrf_token'];
            }
        }
    }
    
    // Se não encontrou token na requisição
    if (!$request_token) {
        error_log('CSRF verify failed: no token in request. Method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' URI=' . ($_SERVER['REQUEST_URI'] ?? '?'));
        return false;
    }
    
    // Obter token da sessão
    $session_token = $_SESSION['csrf_token'] ?? '';
    
    if (!$session_token) {
        error_log('CSRF verify failed: no token in session');
        return false;
    }
    
    // Comparação segura contra timing attacks
    $valid = hash_equals($session_token, $request_token);
    
    if (!$valid) {
        error_log('CSRF verify failed: token mismatch');
    }
    
    return $valid;
}

/**
 * Verificar CSRF ou matar a requisição com erro 403
 * 
 * @param string $message Mensagem de erro customizada (opcional)
 * @return void
 */
function csrf_verify_or_die(string $message = 'CSRF token inválido'): void {
    if (!csrf_verify()) {
        http_response_code(403);
        
        // Se for requisição AJAX/API, retornar JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $message,
                'code' => 'CSRF_TOKEN_INVALID'
            ]);
        } else {
            // Se for formulário HTML, mostrar erro
            echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Erro de Segurança</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 50px; text-align: center; }
        .error-box { background: white; border: 2px solid #ef4444; border-radius: 8px; padding: 30px; max-width: 500px; margin: 0 auto; }
        h1 { color: #ef4444; margin: 0 0 20px 0; }
        p { color: #666; margin: 0 0 20px 0; }
        a { display: inline-block; background: #3b82f6; color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; }
        a:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>⚠️ Erro de Segurança</h1>
        <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
        <p>Esta ação não pôde ser completada por questões de segurança.</p>
        <a href="javascript:history.back()">← Voltar</a>
    </div>
</body>
</html>';
        }
        exit;
    }
}

/**
 * Regenerar token CSRF (útil após login/logout)
 * 
 * @return string Novo token gerado
 */
function csrf_regenerate(): string {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * Verificar se a requisição é POST/PUT/DELETE/PATCH
 * 
 * @return bool
 */
function is_state_changing_request(): bool {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    return in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH']);
}
