<?php
/**
 * Script de diagnóstico de sessão/CSRF para produção
 * REMOVER APÓS DIAGNÓSTICO!
 * 
 * Aceder via: https://mytube.social/api/debug_session.php
 */

// Não carregar config.php para ver o estado RAW primeiro
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo "=== DIAGNÓSTICO DE SESSÃO/CSRF ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Info do PHP
echo "--- PHP INFO ---\n";
echo "PHP Version: " . phpversion() . "\n";
echo "SAPI: " . php_sapi_name() . "\n\n";

// 2. Estado da sessão ANTES do config.php
echo "--- SESSÃO (ANTES do config.php) ---\n";
echo "session_status(): " . session_status() . " (1=DISABLED, 2=ACTIVE, 0=NONE?)\n";
echo "session_id(): '" . session_id() . "'\n";
echo "session_name(): " . session_name() . "\n\n";

// 3. Cookies recebidos
echo "--- COOKIES RECEBIDOS ---\n";
if (empty($_COOKIE)) {
    echo "⚠️ NENHUM COOKIE RECEBIDO!\n";
    echo "Isso significa que o browser não enviou cookies neste request.\n";
    echo "Causa provável: cookie Secure=true mas request HTTP, ou domínio/path errado.\n";
} else {
    foreach ($_COOKIE as $name => $value) {
        echo "$name = " . substr($value, 0, 20) . "...\n";
    }
}
echo "\n";

// 4. Headers recebidos
echo "--- HEADERS RECEBIDOS ---\n";
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        // Não mostrar valores sensíveis completos
        if (stripos($name, 'cookie') !== false || stripos($name, 'csrf') !== false || stripos($name, 'auth') !== false) {
            echo "$name: " . substr($value, 0, 30) . "...\n";
        } else {
            echo "$name: $value\n";
        }
    }
} else {
    echo "getallheaders() NÃO disponível!\n";
    echo "Verificando \$_SERVER para headers:\n";
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            echo "$key: $value\n";
        }
    }
}
echo "\n";

// 5. Variáveis de servidor relevantes
echo "--- SERVIDOR ---\n";
echo "SERVER_NAME: " . ($_SERVER['SERVER_NAME'] ?? 'N/A') . "\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
echo "HTTPS: " . ($_SERVER['HTTPS'] ?? 'NOT SET') . "\n";
echo "HTTP_X_FORWARDED_PROTO: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'NOT SET') . "\n";
echo "HTTP_CF_CONNECTING_IP: " . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? 'NOT SET') . "\n";
echo "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . "\n";
echo "CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'NOT SET') . "\n";
echo "HTTP_CONTENT_TYPE: " . ($_SERVER['HTTP_CONTENT_TYPE'] ?? 'NOT SET') . "\n";
echo "\n";

// 6. Configuração de sessão do PHP
echo "--- CONFIGURAÇÃO DE SESSÃO PHP ---\n";
$sessionSettings = [
    'session.save_handler',
    'session.save_path',
    'session.name',
    'session.cookie_lifetime',
    'session.cookie_path',
    'session.cookie_domain',
    'session.cookie_secure',
    'session.cookie_httponly',
    'session.cookie_samesite',
    'session.use_cookies',
    'session.use_only_cookies',
    'session.use_strict_mode',
    'session.gc_maxlifetime',
    'session.gc_probability',
    'session.gc_divisor',
];
foreach ($sessionSettings as $setting) {
    echo "$setting = " . ini_get($setting) . "\n";
}
echo "\n";

// 7. Verificar se session save path é gravável
echo "--- SESSION SAVE PATH ---\n";
$savePath = ini_get('session.save_path') ?: sys_get_temp_dir();
echo "Path: $savePath\n";
echo "Existe: " . (is_dir($savePath) ? 'SIM' : 'NÃO') . "\n";
echo "Gravável: " . (is_writable($savePath) ? 'SIM' : 'NÃO') . "\n";
if (is_dir($savePath)) {
    $files = glob($savePath . '/sess_*');
    echo "Nº de ficheiros de sessão: " . count($files) . "\n";
}
echo "\n";

// 8. Agora carregar config.php e ver o estado
echo "--- CARREGAR config.php ---\n";
require_once __DIR__ . '/../includes/config.php';
echo "Config carregado com sucesso!\n";
echo "SITE_URL: " . (defined('SITE_URL') ? SITE_URL : 'NOT DEFINED') . "\n";
echo "BASE_PATH: " . (defined('BASE_PATH') ? "'" . BASE_PATH . "'" : 'NOT DEFINED') . "\n";
echo "\n";

// 9. Estado da sessão APÓS config.php
echo "--- SESSÃO (APÓS config.php) ---\n";
echo "session_status(): " . session_status() . "\n";
echo "session_id(): " . session_id() . "\n";
echo "session_name(): " . session_name() . "\n";
echo "\n";

// 10. Configuração de sessão APÓS config.php (pode ter mudado)
echo "--- CONFIGURAÇÃO DE SESSÃO (APÓS config.php) ---\n";
foreach ($sessionSettings as $setting) {
    echo "$setting = " . ini_get($setting) . "\n";
}
echo "\n";

// 11. Conteúdo da sessão
echo "--- CONTEÚDO DA SESSÃO ---\n";
if (empty($_SESSION)) {
    echo "⚠️ SESSÃO VAZIA! (user não logado ou sessão perdida)\n";
} else {
    foreach ($_SESSION as $key => $value) {
        if ($key === 'csrf_token') {
            echo "$key = " . substr($value, 0, 16) . "... (length=" . strlen($value) . ")\n";
        } else {
            echo "$key = " . (is_string($value) ? substr($value, 0, 50) : gettype($value)) . "\n";
        }
    }
}
echo "\n";

// 12. CSRF Meta Tag vs Session
echo "--- CSRF CHECK ---\n";
$sessionToken = $_SESSION['csrf_token'] ?? 'NOT SET';
echo "Token na sessão: " . (strlen($sessionToken) > 16 ? substr($sessionToken, 0, 16) . "..." : $sessionToken) . "\n";
echo "isLoggedIn(): " . (function_exists('isLoggedIn') ? (isLoggedIn() ? 'TRUE' : 'FALSE') : 'FUNCTION NOT FOUND') . "\n";
echo "\n";

// 13. Testar se X-CSRF-Token header é recebido
echo "--- TESTE X-CSRF-TOKEN HEADER ---\n";
echo "Via \$_SERVER['HTTP_X_CSRF_TOKEN']: " . ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? 'NOT FOUND') . "\n";
if (function_exists('getallheaders')) {
    $h = getallheaders();
    echo "Via getallheaders()['X-CSRF-Token']: " . ($h['X-CSRF-Token'] ?? 'NOT FOUND') . "\n";
    echo "Via getallheaders()['X-Csrf-Token']: " . ($h['X-Csrf-Token'] ?? 'NOT FOUND') . "\n";
    echo "Via getallheaders()['x-csrf-token']: " . ($h['x-csrf-token'] ?? 'NOT FOUND') . "\n";
}
echo "\n";

// 14. Response headers que vão ser enviados
echo "--- RESPONSE HEADERS ---\n";
$responseHeaders = headers_list();
foreach ($responseHeaders as $h) {
    echo "$h\n";
}
echo "\n";

// 15. .env check
echo "--- .ENV CHECK ---\n";
$envFile = __DIR__ . '/../.env';
echo ".env existe: " . (file_exists($envFile) ? 'SIM' : 'NÃO') . "\n";
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    // Mostrar apenas chaves, não valores sensíveis
    $lines = explode("\n", $envContent);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        $key = $parts[0] ?? '';
        $val = $parts[1] ?? '';
        // Mascarar valores sensíveis
        if (stripos($key, 'SECRET') !== false || stripos($key, 'PASSWORD') !== false || stripos($key, 'KEY') !== false) {
            echo "$key = ***MASKED***\n";
        } else {
            echo "$key = $val\n";
        }
    }
}
echo "\n";

echo "=== FIM DO DIAGNÓSTICO ===\n";
echo "⚠️ REMOVER ESTE FICHEIRO APÓS DIAGNÓSTICO!\n";
