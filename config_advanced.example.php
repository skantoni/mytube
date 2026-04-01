<?php
/**
 * Configurações Avançadas do MyTube
 * Renomeie para config_advanced.php e inclua no config.php principal
 */

// ===========================
// CONFIGURAÇÕES DE UPLOAD
// ===========================

// Tamanhos máximos de arquivo
define('MAX_VIDEO_SIZE', 50 * 1024 * 1024); // 50MB
define('MAX_THUMBNAIL_SIZE', 5 * 1024 * 1024); // 5MB
define('MAX_PROFILE_PICTURE_SIZE', 2 * 1024 * 1024); // 2MB

// Tipos de arquivo permitidos
define('ALLOWED_VIDEO_TYPES', ['mp4', 'webm', 'mov', 'avi']);
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'webp']);

// Qualidade de compressão
define('THUMBNAIL_QUALITY', 85);
define('PROFILE_PICTURE_QUALITY', 90);

// ===========================
// CONFIGURAÇÕES DE PERFORMANCE
// ===========================

// Cache
define('ENABLE_CACHE', true);
define('CACHE_DURATION', 3600); // 1 hora

// Paginação
define('VIDEOS_PER_PAGE', 10);
define('COMMENTS_PER_PAGE', 20);
define('USERS_PER_PAGE', 24);

// Limites de rate limiting
define('MAX_UPLOADS_PER_HOUR', 5);
define('MAX_COMMENTS_PER_MINUTE', 10);
define('MAX_LIKES_PER_MINUTE', 30);

// ===========================
// CONFIGURAÇÕES DE SEGURANÇA
// ===========================

// Senhas
define('MIN_PASSWORD_LENGTH', 6);
define('REQUIRE_STRONG_PASSWORD', false);
define('PASSWORD_RESET_EXPIRES', 3600); // 1 hora

// Sessões
define('SESSION_TIMEOUT', 7200); // 2 horas
define('REMEMBER_ME_DURATION', 2592000); // 30 dias

// Tentativas de login
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutos

// ===========================
// CONFIGURAÇÕES DE EMAIL
// ===========================

// SMTP (descomente e configure se necessário)
/*
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'seu-email@gmail.com');
define('SMTP_PASSWORD', 'sua-senha-app');
define('SMTP_ENCRYPTION', 'tls');
define('EMAIL_FROM_NAME', 'MyTube');
define('EMAIL_FROM_ADDRESS', 'noreply@mytube.com');
*/

// ===========================
// CONFIGURAÇÕES DE MÍDIA
// ===========================

// FFmpeg (para processamento de vídeo - opcional)
define('FFMPEG_PATH', '/usr/bin/ffmpeg');
define('ENABLE_VIDEO_PROCESSING', false);
define('GENERATE_THUMBNAILS', true);

// Dimensões de redimensionamento
define('THUMBNAIL_WIDTH', 480);
define('THUMBNAIL_HEIGHT', 270);
define('PROFILE_PICTURE_SIZE', 200);

// ===========================
// CONFIGURAÇÕES DE API
// ===========================

// Rate limiting para API
define('API_RATE_LIMIT', 100); // requisições por minuto
define('API_ENABLE_CORS', true);

// Chaves de API (gere chaves únicas)
define('JWT_SECRET_KEY', 'seu-jwt-secret-muito-seguro-aqui');
define('API_VERSION', 'v1');

// ===========================
// CONFIGURAÇÕES DE DESENVOLVIMENTO
// ===========================

// Debug
define('DEBUG_MODE', false);
define('LOG_ERRORS', true);
define('LOG_FILE_PATH', __DIR__ . '/logs/mytube.log');

// Banco de dados
define('DB_DEBUG', false);
define('DB_SLOW_QUERY_LOG', false);

// ===========================
// CONFIGURAÇÕES DE RECURSOS
// ===========================

// CDN (configure se usar CDN)
define('USE_CDN', false);
define('CDN_URL', 'https://cdn.mytube.com');

// Compressão de assets
define('MINIFY_CSS', false);
define('MINIFY_JS', false);

// ===========================
// CONFIGURAÇÕES SOCIAIS
// ===========================

// Limites de interação
define('MAX_FOLLOWS_PER_DAY', 100);
define('MAX_UNFOLLOWS_PER_DAY', 50);
define('MAX_HASHTAGS_PER_VIDEO', 10);

// Moderação
define('ENABLE_AUTO_MODERATION', false);
define('BLOCKED_WORDS', ['spam', 'fake', 'bot']);

// ===========================
// CONFIGURAÇÕES DE NOTIFICAÇÕES
// ===========================

// Web Push (configure se usar)
define('ENABLE_PUSH_NOTIFICATIONS', false);
define('VAPID_PUBLIC_KEY', '');
define('VAPID_PRIVATE_KEY', '');

// Email notifications
define('NOTIFY_NEW_FOLLOWER', true);
define('NOTIFY_NEW_COMMENT', true);
define('NOTIFY_NEW_LIKE', false);

// ===========================
// CONFIGURAÇÕES DE ANALYTICS
// ===========================

// Google Analytics
define('GA_TRACKING_ID', ''); // UA-XXXXXXXX-X

// Métricas internas
define('TRACK_USER_ACTIVITY', true);
define('TRACK_VIDEO_ANALYTICS', true);

// ===========================
// FUNÇÕES UTILITÁRIAS AVANÇADAS
// ===========================

/**
 * Gerar thumbnail de vídeo usando FFmpeg
 */
function generateVideoThumbnail($videoPath, $outputPath, $timeOffset = '00:00:01') {
    if (!ENABLE_VIDEO_PROCESSING || !file_exists(FFMPEG_PATH)) {
        return false;
    }
    
    $command = sprintf(
        '%s -i "%s" -ss %s -vframes 1 -vf "scale=%d:%d" -q:v %d "%s" 2>&1',
        FFMPEG_PATH,
        $videoPath,
        $timeOffset,
        THUMBNAIL_WIDTH,
        THUMBNAIL_HEIGHT,
        THUMBNAIL_QUALITY,
        $outputPath
    );
    
    exec($command, $output, $returnCode);
    
    return $returnCode === 0 && file_exists($outputPath);
}

/**
 * Validar força da senha
 */
function isStrongPassword($password) {
    if (!REQUIRE_STRONG_PASSWORD) {
        return strlen($password) >= MIN_PASSWORD_LENGTH;
    }
    
    // Pelo menos 8 caracteres, 1 maiúscula, 1 minúscula, 1 número, 1 especial
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password);
}

/**
 * Rate limiting simples
 */
function checkRateLimit($action, $limit, $timeframe = 60) {
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }
    
    $key = $action . '_' . ($_SESSION['user_id'] ?? session_id());
    $now = time();
    
    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [];
    }
    
    // Limpar timestamps antigos
    $_SESSION['rate_limits'][$key] = array_filter(
        $_SESSION['rate_limits'][$key],
        function($timestamp) use ($now, $timeframe) {
            return ($now - $timestamp) < $timeframe;
        }
    );
    
    // Verificar limite
    if (count($_SESSION['rate_limits'][$key]) >= $limit) {
        return false;
    }
    
    // Adicionar timestamp atual
    $_SESSION['rate_limits'][$key][] = $now;
    
    return true;
}

/**
 * Log de erros personalizado
 */
function logError($message, $context = []) {
    if (!LOG_ERRORS) return;
    
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'user_id' => $_SESSION['user_id'] ?? null
    ];
    
    if (defined('LOG_FILE_PATH') && is_writable(dirname(LOG_FILE_PATH))) {
        file_put_contents(
            LOG_FILE_PATH,
            json_encode($logEntry) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

/**
 * Sanitizar nome de arquivo
 */
function sanitizeFileName($filename) {
    $info = pathinfo($filename);
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $info['filename']);
    $extension = strtolower($info['extension']);
    
    return $name . '.' . $extension;
}

/**
 * Converter bytes para formato legível
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Validar e limpar URL
 */
function sanitizeUrl($url) {
    $url = filter_var($url, FILTER_SANITIZE_URL);
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
}

/**
 * Gerar slug para URLs amigáveis
 */
function generateSlug($text) {
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}
?>