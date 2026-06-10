<?php
/**
 * Script para limpar sessões expiradas.
 * Corre via cronjob para não bloquear requests normais.
 * 
 * Exemplo de cron (corre de hora a hora):
 * 0 * * * * php /var/www/mytube.social/clean_sessions.php
 */

require_once __DIR__ . '/includes/config.php';

// Apenas executar via CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

$session_dir = __DIR__ . '/sessions';
$lifetime = (int)env('SESSION_LIFETIME', 2592000); // 30 dias por padrão
$now = time();
$deleted = 0;
$total = 0;

if (is_dir($session_dir)) {
    $files = glob($session_dir . '/sess_*');
    $total = count($files);
    
    foreach ($files as $file) {
        if (is_file($file)) {
            // Verifica se o ficheiro expirou
            if ($now - filemtime($file) >= $lifetime) {
                @unlink($file);
                $deleted++;
            }
        }
    }
}

echo "Session cleanup complete. Checked $total sessions, deleted $deleted expired sessions.\n";
