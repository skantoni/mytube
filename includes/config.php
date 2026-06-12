<?php
// Carregar variáveis de ambiente
require_once __DIR__ . '/env_loader.php';

// PSR-4 autoloader for MyTube\ namespace → app/
require_once __DIR__ . '/../autoload.php';

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
$raw_site_url = env('SITE_URL', 'http://localhost/my');

// ✅ SANITIZAR SITE_URL: corrigir erros comuns no .env
// Exemplo: "http:https://..." → "https://..."
if (preg_match('#^https?:https?://#', $raw_site_url)) {
    // URL malformada como "http:https://domain" — extrair a parte correcta
    if (strpos($raw_site_url, 'https://') !== false) {
        $raw_site_url = 'https://' . preg_replace('#^https?:https?://#', '', $raw_site_url);
    } else {
        $raw_site_url = 'http://' . preg_replace('#^https?:https?://#', '', $raw_site_url);
    }
}
define('SITE_URL', $raw_site_url);

// Extrair base path do SITE_URL para URLs relativas (ex: /my ou vazio)
$parsed_url = parse_url(SITE_URL);
$extracted_path = isset($parsed_url['path']) ? rtrim($parsed_url['path'], '/') : '';

// ✅ VALIDAÇÃO CRÍTICA: BASE_PATH deve ser um path relativo (ex: '/my' ou '')
// NUNCA deve ser um URL completo (ex: 'https://mytube.social')
// Se contém '://' ou não começa com '/', é inválido → usar ''
if (!empty($extracted_path) && (strpos($extracted_path, '://') !== false || $extracted_path[0] !== '/')) {
    error_log("⚠️ BASE_PATH inválido detectado: '$extracted_path'. SITE_URL no .env pode estar errado. Usando '/' como fallback.");
    $extracted_path = '';
}
define('BASE_PATH', $extracted_path);

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
    $is_https = false;
    
    // 1. Cloudflare: HTTP_CF_CONNECTING_IP indica que está atrás do CF
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $_SERVER['HTTPS'] = 'on';
        $is_https = true;
    }
    // 2. Proxy/Load Balancer: X-Forwarded-Proto
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $_SERVER['HTTPS'] = 'on';
        $is_https = true;
    }
    // 3. Nginx direto: HTTPS setado via fastcgi_param
    elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $is_https = true;
    }
    
    // ⚠️ DESABILITADO: Redirecionamento HTTP→HTTPS feito no Nginx (mais eficiente)
    // O Nginx já redireciona na porta 80, não precisamos fazer aqui
    /*
    if (!$is_https) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        
        if ($host) {
            $redirect_url = 'https://' . $host . $uri;
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . $redirect_url);
            exit();
        }
    }
    */
}

// Iniciar sessão com configuração segura (apenas se não for CLI)
if (!$is_cli && session_status() === PHP_SESSION_NONE) {
    $session_lifetime = (int)env('SESSION_LIFETIME', 2592000); // Padrão: 30 dias (2592000 segs)

    // ✅ ISOLAR SESSÕES: Evitar que o Lixeiro global do servidor (ex: 24 mins) apague as nossas sessões
    $session_dir = __DIR__ . '/../sessions';
    if (!is_dir($session_dir)) {
        @mkdir($session_dir, 0755, true);
    }
    ini_set('session.save_path', $session_dir);
    
    // ⚠️ ATENÇÃO: GC do PHP DESATIVADO para não bloquear requests (como o crawler do WhatsApp)
    // O lixo deve ser limpo por um Cron Job: `php clean_sessions.php` ou via bash
    ini_set('session.gc_probability', 0);
    ini_set('session.gc_divisor', 100);

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

    // ✅ RENOVAÇÃO AUTOMÁTICA (Session Touch):
    // Garante que o ficheiro de sessão no servidor atualiza a sua data de modificação 
    // enquanto o utilizador navega, impedindo o lixeiro de achar que está inativo.
    $now = time();
    $touch_interval = 300; // Atualiza a cada 5 minutos
    if (!isset($_SESSION['last_activity']) || ($now - $_SESSION['last_activity']) > $touch_interval) {
        $_SESSION['last_activity'] = $now;
        
        // Opcional para manter o cookie vivo no navegador estendendo a sua data (Remember Me constante)
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), session_id(), $now + $session_lifetime, BASE_PATH ? BASE_PATH . '/' : '/');
        }
    }
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

    // Permissions-Policy: bloqueia geolocalização mas permite microfone e câmera para o próprio site
    // IMPORTANTE: microphone=(self) é necessário para gravação de voz no chat
    // microphone=() bloquearia completamente e o browser NUNCA pediria permissão ao utilizador
    header('Permissions-Policy: geolocation=(), microphone=(self), camera=(self)');

    // ✅ COOP: Necessário para Google Identity Services (popup OAuth)
    // 'same-origin-allow-popups' permite que popups de accounts.google.com
    // enviem postMessage de volta para a janela que os abriu
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

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

// ✅ Gerar token CSRF automaticamente para todas as sessões
// Isso garante que o token sempre existe, mesmo que o usuário não acesse uma página que gera o meta tag
if (!$is_cli && session_status() === PHP_SESSION_ACTIVE) {
    csrf_token();
}

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
            
            $stmt = $pdo->prepare("
                SELECT u.profile_picture, u.username, u.full_name, s.short_name as school_short, s.name as school_name 
                FROM users u 
                LEFT JOIN schools s ON u.school_id = s.id 
                WHERE u.id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                $_SESSION['profile_picture'] = $user['profile_picture'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['school_short'] = $user['school_short'];
                $_SESSION['school_name'] = $user['school_name'];
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
        echo "<script>window.location.href = " .json_encode($url) . ";</script>";
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