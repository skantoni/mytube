<?php
/**
 * Diagnóstico completo do servidor para uploads de vídeo.
 * Correr via SSH: php debug_server.php
 * APAGAR APÓS USO.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$isCLI = php_sapi_name() === 'cli';
$nl = $isCLI ? "\n" : "<br>\n";
$sep = $isCLI ? str_repeat('─', 60) : "<hr>";

function h($title) {
    global $nl, $sep, $isCLI;
    echo $sep . $nl;
    echo ($isCLI ? "  " : "<b>") . "=== $title ===" . ($isCLI ? "" : "</b>") . $nl;
}
function ok($msg)  { global $nl, $isCLI; echo ($isCLI ? "  ✓ " : "✅ ") . $msg . $nl; }
function err($msg) { global $nl, $isCLI; echo ($isCLI ? "  ✗ " : "❌ ") . $msg . $nl; }
function info($msg){ global $nl, $isCLI; echo ($isCLI ? "    " : "&nbsp;&nbsp;") . $msg . $nl; }

if (!$isCLI) echo "<pre style='font-family:monospace; font-size:13px; background:#111; color:#eee; padding:20px'>\n";

echo "DIAGNÓSTICO DO SERVIDOR — " . date('Y-m-d H:i:s') . $nl;

// ── 1. PHP ───────────────────────────────────────────────────────
h("1. PHP");
info("Versão: " . PHP_VERSION);
info("SAPI: " . php_sapi_name());
info("OS: " . PHP_OS . " " . php_uname('m'));
info("max_execution_time (ini): " . ini_get('max_execution_time') . "s");
info("memory_limit: " . ini_get('memory_limit'));
info("upload_max_filesize: " . ini_get('upload_max_filesize'));
info("post_max_size: " . ini_get('post_max_size'));

// ── 2. Extensões críticas ─────────────────────────────────────────
h("2. Extensões PHP críticas");
$exts = ['mbstring' => 'mb_strlen', 'curl' => 'curl_init', 'openssl' => 'openssl_encrypt', 'json' => 'json_encode', 'pdo' => 'PDO', 'pdo_mysql' => null];
foreach ($exts as $ext => $func) {
    if (extension_loaded($ext)) ok($ext); else err($ext . " NÃO carregada");
}

// ── 3. exec() / shell ─────────────────────────────────────────────
h("3. exec() e funções de shell");
$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$disabled = array_filter($disabled);

if (in_array('exec', $disabled)) {
    err("exec() está em disable_functions — FFmpeg NÃO vai funcionar");
} elseif (!function_exists('exec')) {
    err("exec() não existe");
} else {
    ok("exec() disponível");
}

$shell_fns = ['exec', 'shell_exec', 'passthru', 'proc_open', 'system'];
foreach ($shell_fns as $fn) {
    $state = in_array($fn, $disabled) ? "DESABILITADO" : "OK";
    info("$fn: $state");
}

if ($disabled) {
    info("disable_functions: " . implode(', ', $disabled));
}

// ── 4. FFmpeg ─────────────────────────────────────────────────────
h("4. FFmpeg");
$ffmpeg_paths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/ffmpeg/bin/ffmpeg'];
$ffmpeg = null;
foreach ($ffmpeg_paths as $p) {
    if (file_exists($p)) { $ffmpeg = $p; break; }
}

// Também tentar via which
if (!$ffmpeg && function_exists('exec') && !in_array('exec', $disabled)) {
    $out = []; $exit = 1;
    exec('which ffmpeg 2>/dev/null', $out, $exit);
    if ($exit === 0 && !empty($out[0])) $ffmpeg = trim($out[0]);
}

if ($ffmpeg) {
    ok("FFmpeg encontrado: $ffmpeg");
    // Testar versão
    $out = []; $exit = 1;
    exec($ffmpeg . ' -version 2>&1 | head -1', $out, $exit);
    info("Versão: " . ($out[0] ?? 'desconhecida'));

    // Testar se consegue executar (criar vídeo de 1s)
    $test_out = sys_get_temp_dir() . '/mytube_test_' . time() . '.mp4';
    $cmd = sprintf('%s -y -f lavfi -i color=c=black:s=320x240:d=1 -c:v libx264 -t 1 %s 2>&1', escapeshellarg($ffmpeg), escapeshellarg($test_out));
    $out2 = []; $exit2 = 1;
    exec($cmd, $out2, $exit2);
    if ($exit2 === 0 && file_exists($test_out) && filesize($test_out) > 0) {
        ok("FFmpeg consegue criar vídeo de teste (" . filesize($test_out) . " bytes)");
        unlink($test_out);
    } else {
        err("FFmpeg falhou ao criar vídeo de teste (exit=$exit2)");
        info("Output: " . implode(' | ', array_slice($out2, -3)));
        if (file_exists($test_out)) unlink($test_out);
    }

    // Testar codecs disponíveis
    $out3 = []; exec($ffmpeg . ' -codecs 2>/dev/null | grep -E "libx264|aac"', $out3);
    if ($out3) ok("Codecs libx264/aac: OK");
    else err("libx264 ou aac não disponíveis — FFmpeg instalado mas sem codecs necessários");
} else {
    err("FFmpeg NÃO encontrado — uploads vão falhar");
    info("Instalar: sudo apt install ffmpeg");
}

// ── 5. Diretório /tmp ─────────────────────────────────────────────
h("5. Diretório temporário (para FFmpeg)");
$tmp = sys_get_temp_dir();
info("sys_get_temp_dir(): $tmp");
if (is_writable($tmp)) {
    ok("$tmp é gravável");
    // Testar criação real
    $testFile = $tmp . '/mytube_write_test_' . time() . '.tmp';
    if (file_put_contents($testFile, 'test') !== false) {
        ok("Escrita em $tmp: OK");
        unlink($testFile);
    } else {
        err("Não consigo criar ficheiros em $tmp");
    }
} else {
    err("$tmp NÃO é gravável — FFmpeg não consegue criar ficheiros temporários");
}

// ── 6. Diretórios da aplicação ────────────────────────────────────
h("6. Permissões dos diretórios");
$dirs = [
    __DIR__ => 'raiz do projeto',
    __DIR__ . '/uploads' => 'uploads/',
    __DIR__ . '/uploads/videos' => 'uploads/videos/',
    __DIR__ . '/logs' => 'logs/',
    __DIR__ . '/cache' => 'cache/',
];
foreach ($dirs as $dir => $label) {
    if (!is_dir($dir)) {
        info("$label: não existe (OK se usar R2)");
    } elseif (is_writable($dir)) {
        ok("$label: gravável");
    } else {
        err("$label: NÃO gravável");
    }
}

// ── 7. R2 / AWS ───────────────────────────────────────────────────
h("7. Cloudflare R2");
$r2_config = __DIR__ . '/includes/r2_config.php';
if (!file_exists($r2_config)) {
    err("includes/r2_config.php não existe");
} else {
    require_once __DIR__ . '/includes/r2_config.php';
    info("R2_ENABLED: " . (R2_ENABLED ? 'true' : 'false'));
    info("R2_BUCKET_NAME: " . R2_BUCKET_NAME);
    info("R2_ENDPOINT: " . R2_ENDPOINT);
    info("R2_ACCESS_KEY_ID: " . (R2_ACCESS_KEY_ID ? substr(R2_ACCESS_KEY_ID, 0, 8) . '...' : 'VAZIO'));
    info("R2_SECRET_ACCESS_KEY: " . (R2_SECRET_ACCESS_KEY ? '***definido***' : 'VAZIO'));

    $aws_phar = __DIR__ . '/aws.phar';
    if (!file_exists($aws_phar)) {
        err("aws.phar não existe — R2 NÃO vai funcionar");
    } else {
        ok("aws.phar existe (" . round(filesize($aws_phar)/1024/1024, 1) . " MB)");
        if (!extension_loaded('mbstring')) {
            err("mbstring não carregada — aws.phar falha sem ela");
        }

        // Testar conectividade ao R2
        try {
            require_once __DIR__ . '/includes/r2_storage.php';
            $client = r2_get_client();
            $result = $client->listObjectsV2(['Bucket' => R2_BUCKET_NAME, 'MaxKeys' => 1]);
            ok("Ligação ao R2: OK");
        } catch (Throwable $e) {
            err("Ligação ao R2 FALHOU: " . $e->getMessage());
        }
    }
}

// ── 8. Resolvendo o binário FFmpeg via código da app ──────────────
h("8. Deteção de FFmpeg pelo código da app");
if (!isset($disabled)) $disabled = [];
if (!in_array('exec', $disabled)) {
    require_once __DIR__ . '/includes/video_processing.php';
    $app_ffmpeg = video_get_ffmpeg_binary();
    if ($app_ffmpeg) ok("video_get_ffmpeg_binary() => $app_ffmpeg");
    else err("video_get_ffmpeg_binary() retornou NULL — ffmpeg não detetado pelo código");
    $can_exec = video_exec_available();
    info("video_exec_available(): " . ($can_exec ? 'true' : 'false'));
}

// ── 9. Nginx / configuração web ───────────────────────────────────
h("9. Timeouts (Nginx e PHP-FPM)");
// Verificar ficheiros de config do Nginx
$nginx_sites = glob('/etc/nginx/sites-enabled/*') ?: [];
$nginx_conf   = glob('/etc/nginx/conf.d/*.conf') ?: [];
$all_nginx = array_merge($nginx_sites, $nginx_conf);
foreach ($all_nginx as $conf_file) {
    $content = @file_get_contents($conf_file);
    if (!$content) continue;
    preg_match('/fastcgi_read_timeout\s+(\d+)/', $content, $m);
    if ($m) info("$conf_file → fastcgi_read_timeout: {$m[1]}s");
    else info("$conf_file → fastcgi_read_timeout: NÃO definido (padrão 60s)");

    preg_match('/client_max_body_size\s+(\S+)/', $content, $mb);
    if ($mb) info("$conf_file → client_max_body_size: {$mb[1]}");
    else info("$conf_file → client_max_body_size: NÃO definido (padrão 1MB) ← pode bloquear uploads");
}

// Verificar PHP-FPM pool config
$fpm_pools = glob('/etc/php/*/fpm/pool.d/*.conf') ?: [];
foreach ($fpm_pools as $pool) {
    $content = @file_get_contents($pool);
    if (!$content) continue;
    preg_match('/request_terminate_timeout\s*=\s*(\S+)/', $content, $rm);
    if ($rm) info("$pool → request_terminate_timeout: {$rm[1]}");
}

// ── 10. Logs de erro PHP recentes ─────────────────────────────────
h("10. Logs de erro PHP (últimas 30 linhas relacionadas a upload)");
$log_paths = [
    ini_get('error_log'),
    '/var/log/php/error.log',
    '/var/log/php-fpm/error.log',
    '/var/log/nginx/error.log',
    __DIR__ . '/logs/error.log',
];
$found_log = false;
foreach ($log_paths as $log) {
    if ($log && file_exists($log) && is_readable($log)) {
        ok("Log encontrado: $log");
        $lines = [];
        exec("grep -i 'upload\\|ffmpeg\\|r2\\|video\\|music\\|fatal\\|error' " . escapeshellarg($log) . " 2>/dev/null | tail -30", $lines);
        if ($lines) {
            foreach (array_slice($lines, -20) as $line) {
                info(htmlspecialchars(substr($line, 0, 200)));
            }
        } else {
            info("(sem entradas relacionadas)");
        }
        $found_log = true;
        break;
    }
}
if (!$found_log) {
    err("Não encontrei ficheiro de log de erros PHP");
    info("Verifica: php -i | grep error_log");
}

// ── 11. Tabela videos — colunas existentes ────────────────────────
h("11. Base de dados — estrutura da tabela videos");
try {
    require_once __DIR__ . '/includes/config.php';
    $cols = $pdo->query("DESCRIBE videos")->fetchAll(PDO::FETCH_COLUMN, 0);
    info("Colunas: " . implode(', ', $cols));
    $music_ok = in_array('music_name', $cols) && in_array('music_artist', $cols);
    if ($music_ok) ok("Colunas music_name e music_artist: OK");
    else err("Colunas music_name / music_artist em falta → INSERT vai falhar");
} catch (Throwable $e) {
    err("Erro BD: " . $e->getMessage());
}

// ── 12. Espaço em disco ───────────────────────────────────────────
h("12. Espaço em disco");
$free = disk_free_space('/');
$total = disk_total_space('/');
$used_pct = round((($total - $free) / $total) * 100);
info("Disco /: " . round($free/1024/1024/1024, 1) . " GB livres / " . round($total/1024/1024/1024, 1) . " GB total ($used_pct% usado)");
if ($used_pct > 90) err("Disco quase cheio — uploads podem falhar");
else ok("Espaço em disco OK");

// ── 13. Teste completo do fluxo de upload sem ficheiro real ───────
h("13. Teste de fluxo real (FFmpeg + R2)");
if (isset($app_ffmpeg) && $app_ffmpeg && isset($can_exec) && $can_exec && R2_ENABLED) {
    // Criar vídeo de teste
    $test_in  = sys_get_temp_dir() . '/mytube_diag_in_'  . time() . '.mp4';
    $test_out = sys_get_temp_dir() . '/mytube_diag_out_' . time() . '.mp4';
    $cmd = sprintf('%s -y -f lavfi -i color=c=black:s=320x240:d=2 -f lavfi -i sine=frequency=440:duration=2 -c:v libx264 -c:a aac -t 2 %s 2>&1', escapeshellarg($app_ffmpeg), escapeshellarg($test_in));
    exec($cmd, $o, $e);
    if ($e === 0 && file_exists($test_in) && filesize($test_in) > 0) {
        ok("Criação de vídeo de teste: OK (" . filesize($test_in) . " bytes)");
        // Testar upload para R2
        $test_name = 'diag_test_' . time() . '.mp4';
        try {
            $r2 = r2_upload_video($test_in, $test_name, 'video/mp4');
            if ($r2['success']) {
                ok("Upload R2 de vídeo de teste: OK → " . R2_PATH_PREFIX . $test_name);
                r2_delete_video(R2_PATH_PREFIX . $test_name);
                ok("Limpeza R2: OK");
            } else {
                err("Upload R2 FALHOU: " . $r2['error']);
            }
        } catch (Throwable $e2) {
            err("Exceção no R2: " . $e2->getMessage());
        }
        unlink($test_in);
    } else {
        err("Falha ao criar vídeo de teste com FFmpeg (exit=$e)");
    }
} else {
    info("IGNORADO (FFmpeg ou R2 não disponíveis — ver secções acima)");
}

// ── Resumo ────────────────────────────────────────────────────────
h("RESUMO — O que verificar na VPS");
echo ($isCLI ? "\n" : "") . $nl;
$checks = [
    'exec() disponível' => !in_array('exec', $disabled) && function_exists('exec'),
    'FFmpeg instalado'  => $ffmpeg !== null,
    'R2 configurado'    => defined('R2_ENABLED') && R2_ENABLED && R2_ACCESS_KEY_ID,
    '/tmp gravável'     => is_writable(sys_get_temp_dir()),
    'colunas music_name' => isset($music_ok) ? $music_ok : false,
];
foreach ($checks as $label => $pass) {
    if ($pass) ok($label);
    else err("$label ← RESOLVER");
}

if (!$isCLI) echo "</pre>\n";
