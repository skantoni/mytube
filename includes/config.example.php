<?php
// Configurar timezone (Angola - Luanda, WAT UTC+1)
date_default_timezone_set('Africa/Luanda');

// ============================================
// CONFIGURAÇÕES DO BANCO DE DADOS
// ============================================
// Copie este ficheiro para config.php e preencha com os seus dados:
//   cp config.example.php config.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube_db');
define('DB_USER', 'root');
define('DB_PASS', '');          // ← Coloque a sua password aqui

// Configurações gerais
define('SITE_URL', 'http://localhost/mytube');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_VIDEO_SIZE', 100 * 1024 * 1024); // 100MB
define('ALLOWED_VIDEO_TYPES', ['mp4', 'avi', 'mov', 'wmv']);

// Iniciar sessão com configuração segura
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// ============================================
// CONEXÃO LAZY PDO — só conecta quando usado
// ============================================
class LazyPDO {
    private ?PDO $instance = null;

    private function connect(): PDO {
        if ($this->instance === null) {
            $this->instance = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES    => false,
                ]
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

function isAdminUser() {
    static $isAdmin = null;

    if ($isAdmin !== null) {
        return $isAdmin;
    }

    if (!isLoggedIn()) {
        $isAdmin = false;
        return $isAdmin;
    }

    global $pdo;

    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    $isAdmin = isset($user['username']) && strtolower($user['username']) === 'admin';
    return $isAdmin;
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
?>
