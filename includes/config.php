<?php
// Carregar variáveis de ambiente
require_once __DIR__ . '/env_loader.php';

// Garantir charset UTF-8 em toda aplicação
ini_set('default_charset', 'utf-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
if (function_exists('mb_http_output')) {
    mb_http_output('UTF-8');
}

// Configurar timezone (Angola - Luanda, WAT UTC+1)
date_default_timezone_set('Africa/Luanda');

// Configurações do banco de dados (agora com variáveis de ambiente)
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'mytube_db'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// Configurações gerais
define('SITE_URL', env('SITE_URL', 'http://localhost/my'));

// Extrair base path do SITE_URL para URLs relativas (ex: /my ou vazio)
$parsed_url = parse_url(SITE_URL);
define('BASE_PATH', isset($parsed_url['path']) ? rtrim($parsed_url['path'], '/') : '');

// Secret para chamadas internas de cron (NUNCA VERSIONE ISTO!)
define('CRON_SECRET', env('CRON_SECRET', ''));
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_VIDEO_SIZE', 100 * 1024 * 1024); // 100MB
define('ALLOWED_VIDEO_TYPES', ['mp4', 'avi', 'mov', 'wmv']);

// ✅ FORÇA HTTPS EM PRODUÇÃO (previne man-in-the-middle)
$is_production = (env('APP_ENV', 'development') === 'production');
$is_cli = (php_sapi_name() === 'cli');

// ✅ DETECTAR HTTPS atrás de proxy/CDN (Cloudflare, Nginx)
// IMPORTANTE: Fazer ANTES de iniciar sessão para garantir cookie_secure funcione
if ($is_production && !$is_cli) {
    // Cloudflare: HTTP_CF_CONNECTING_IP indica que está atrás do CF
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $_SERVER['HTTPS'] = 'on';
    }
    // Proxy/Load Balancer: X-Forwarded-Proto
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $_SERVER['HTTPS'] = 'on';
    }
    // Nginx direto: pode setar HTTPS via fastcgi_param
    elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        // Já está setado, não fazer nada
    }
    // Se realmente for HTTP puro, redirecionar para HTTPS
    elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        
        if ($host) {
            $redirect_url = 'https://' . $host . $uri;
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . $redirect_url);
            exit();
        }
    }
}

// Iniciar sessão com configuração segura (apenas se não for CLI)
if (!$is_cli && session_status() === PHP_SESSION_NONE) {
    $session_lifetime = (int)env('SESSION_LIFETIME', 7200); // Padrão: 2 horas

    ini_set('session.cookie_httponly', 1); // Previne XSS via JavaScript
    ini_set('session.use_only_cookies', 1); // Previne session fixation via URL
    
    // ✅ SameSite=Lax: permite cookies em links externos (necessário para PWA iOS)
    // Strict bloqueava sessão quando usuário clicava em links de vídeos compartilhados
    ini_set('session.cookie_samesite', 'Lax'); // Previne CSRF mantendo compatibilidade PWA
    
    // ✅ COOKIE PATH: garante que o cookie funciona em todo o site (inclusive subdiretórios)
    // CRÍTICO: sem isso, cookies podem conflitar entre /my e /
    ini_set('session.cookie_path', BASE_PATH ? BASE_PATH . '/' : '/');
    
    // ✅ COOKIE DOMAIN: NÃO configurar explicitamente (usa default do PHP)
    // Com Nginx redirect (www → sem www), todos sempre usam mytube.social
    // O cookie é criado para o domínio correto automaticamente
    
    // ✅ COOKIE SECURE: só envia cookie via HTTPS (produção)
    if ($is_production) {
        ini_set('session.cookie_secure', 1);
    }
    
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);

    session_start();
    
    // ✅ Gerar token CSRF automaticamente para todas as sessões
    // Isso garante que o token sempre existe, mesmo que o usuário
    // não acesse uma página que gera o meta tag
    csrf_token();
}

// ✅ HEADERS DE SEGURANÇA HTTP (apenas se não for CLI)
if (!$is_cli) {
    if ($is_production) {
        // HSTS: força HTTPS por 1 ano (incluindo subdomínios)
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // X-Frame-Options: previne clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // X-Content-Type-Options: previne MIME sniffing
    header('X-Content-Type-Options: nosniff');

    // X-XSS-Protection: proteção adicional XSS (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer-Policy: controla envio de referrer
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions-Policy: desabilita features desnecessárias
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // Content-Security-Policy: previne XSS e injection attacks
    if ($is_production) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://cdn.socket.io https://static.cloudflareinsights.com https://accounts.google.com https://apis.google.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com https://accounts.google.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob: https:; media-src 'self' blob: https:; connect-src 'self' https: wss: https://oauth2.googleapis.com; frame-src https://accounts.google.com; frame-ancestors 'self';");
    } else {
        // CSP mais permissiva em desenvolvimento
        header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com https://cdn.socket.io https://static.cloudflareinsights.com https://accounts.google.com https://apis.google.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com https://accounts.google.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob: https: http:; media-src 'self' blob: https: http:; connect-src 'self' https: http: ws: wss: https://oauth2.googleapis.com; frame-src https://accounts.google.com;");
    }
}


// Carregar helpers de CSRF (proteção contra Cross-Site Request Forgery)
require_once __DIR__ . '/csrf_helpers.php';

// ============================================
// CONEXÃO LAZY PDO — só conecta quando usado
// ============================================
class LazyPDO {
    private ?PDO $instance = null;

    private function connect(): PDO {
        if ($this->instance === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
            ];
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'";
            }
            $this->instance = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                $options
            );
            $this->instance->exec("SET time_zone = '+01:00'");
        }
        return $this->instance;
    }

    public function __call(string $name, array $args): mixed {
        return $this->connect()->$name(...$args);
    }

    public function __get(string $name): mixed {
        return $this->connect()->$name;
    }
}

$pdo = new LazyPDO();

// Função para verificar se o usuário está logado
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Função para garantir que os dados do usuário estão na sessão
// Só recarrega do banco a cada 5 minutos (evita query em cada page load)
function ensureUserData() {
    global $pdo;
    if (isLoggedIn()) {
        $cache_ttl = 300; // 5 minutos
        $now = time();
        
        // Só recarregar se cache expirou ou dados não existem
        if (!isset($_SESSION['_user_cache_time']) || 
            ($now - $_SESSION['_user_cache_time']) > $cache_ttl ||
            !isset($_SESSION['username'])) {
            
            $stmt = $pdo->prepare("SELECT profile_picture, username, full_name FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                $_SESSION['profile_picture'] = $user['profile_picture'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['_user_cache_time'] = $now;
            }
        }
    }
}

// Forçar recarga dos dados do usuário (chamar após editar perfil)
function invalidateUserCache() {
    unset($_SESSION['_user_cache_time']);
}

/**
 * Verificação de admin via RBAC (coluna `role`).
 * Fallback automático por username caso a migration ainda não tenha sido aplicada.
 *
 * NUNCA verificar admin directamente por username fora desta função.
 */
function isAdminUser(): bool {
    static $isAdmin = null;

    if ($isAdmin !== null) {
        return $isAdmin;
    }

    if (!isLoggedIn()) {
        $isAdmin = false;
        return $isAdmin;
    }

    global $pdo;

    try {
        // RBAC primário: verificar coluna role (após migration)
        $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            $isAdmin = false;
            return $isAdmin;
        }

        // Verificar role='admin' (RBAC correcto)
        if (isset($user['role'])) {
            $isAdmin = ($user['role'] === 'admin');
        } else {
            // Fallback: coluna role ainda não existe (antes da migration)
            $isAdmin = isset($user['username']) && strtolower($user['username']) === 'admin';
        }
    } catch (Exception $e) {
        // Fallback seguro em caso de erro de BD
        $isAdmin = false;
    }

    return $isAdmin;
}

/**
 * Verifica se o utilizador atual pode enviar mensagens a qualquer utilizador
 * sem necessidade de amizade (roles: admin, vip).
 */
function canMessageAnyone(): bool {
    static $result = null;
    if ($result !== null) return $result;

    if (!isLoggedIn()) {
        $result = false;
        return $result;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        $result = $user && in_array($user['role'] ?? '', ['admin', 'vip'], true);
    } catch (Exception $e) {
        $result = false;
    }
    return $result;
}

// Função para redirecionar
function redirect($url) {
    if (!headers_sent()) {
        header("Location: " . $url);
        exit();
    } else {
        echo "<script>window.location.href = '$url';</script>";
        exit();
    }
}

// Função para limpar dados de entrada
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Função para formatar números de forma compacta (1k, 1.2M, etc.)
function formatNumberShort($num) {
    $num = (int)$num;
    if ($num >= 1000000000) {
        $val = $num / 1000000000;
        return ($val == floor($val) ? floor($val) : number_format($val, 1, '.', '')) . 'B';
    } elseif ($num >= 1000000) {
        $val = $num / 1000000;
        return ($val == floor($val) ? floor($val) : number_format($val, 1, '.', '')) . 'M';
    } elseif ($num >= 1000) {
        $val = $num / 1000;
        return ($val == floor($val) ? floor($val) : number_format($val, 1, '.', '')) . 'k';
    }
    return (string)$num;
}

// Função para formatar tempo
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    // Menos de 10 segundos
    if ($time < 10) return 'agora';
    
    // Menos de 1 hora - mostrar em minutos
    if ($time < 3600) {
        $minutes = floor($time / 60);
        // Se ainda não completou 1 minuto, mostrar 1min
        if ($minutes == 0) $minutes = 1;
        return $minutes . 'min';
    }
    
    // Menos de 24 horas - mostrar em horas
    if ($time < 86400) {
        $hours = floor($time / 3600);
        return $hours . 'h';
    }
    
    // 24 horas ou mais - mostrar data completa
    return date('d/m/Y', strtotime($datetime));
}

// Renderizar bio com menções @username clicáveis
function renderBioWithMentions($bio) {
    if (empty($bio)) return '';
    $safe = htmlspecialchars($bio);
    // Converter @username em links para o perfil
    $safe = preg_replace(
        '/@([\w]+)/',
        '<a href="perfil.php?username=$1" class="bio-mention">@$1</a>',
        $safe
    );
    return nl2br($safe);
}

// Função para versionamento automático de assets (Cache Busting)
function asset($path) {
    $realPath = __DIR__ . '/../' . ltrim($path, '/');
    if (file_exists($realPath)) {
        return $path . '?v=' . filemtime($realPath);
    }
    return $path;
}

/**
 * Gera URL de avatar com cache-busting baseado na data de modificação.
 * Garante que o browser não serve fotos antigas em cache após alteração.
 *
 * @param string|null $filename  Nome do ficheiro guardado na base de dados
 * @return string                URL relativa com ?v=mtime
 */
function avatar_url(?string $filename): string {
    $avatarsDir = __DIR__ . '/../assets/images/avatars/';
    $default    = 'assets/images/avatars/default.webp';
    $file       = !empty($filename) ? $filename : 'default.webp';
    $absPath    = $avatarsDir . $file;
    if (!is_file($absPath)) {
        $absPath = $avatarsDir . 'default.webp';
        $file    = 'default.webp';
    }
    $mtime = @filemtime($absPath);
    $url   = 'assets/images/avatars/' . $file;
    return $mtime ? $url . '?v=' . (int)$mtime : $url;
}
?>