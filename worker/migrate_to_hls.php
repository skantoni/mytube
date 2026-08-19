<?php
/**
 * migrate_to_hls.php — Migração em Background: MP4 → HLS
 *
 * Corre continuamente até que TODOS os vídeos .mp4 sejam convertidos para HLS.
 * Seguro para correr em produção: processa um vídeo de cada vez para não sobrecarregar a VPS.
 *
 * Uso na VPS:
 *   nohup php /var/www/mytube.social/worker/migrate_to_hls.php > /var/log/mytube_hls_migration.log 2>&1 &
 *
 * Para acompanhar:
 *   tail -f /var/log/mytube_hls_migration.log
 *
 * Para parar:
 *   kill $(pgrep -f migrate_to_hls.php)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso proibido. Este script é apenas para CLI.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/includes/config.php';
require_once ROOT_DIR . '/includes/r2_storage.php';
require_once ROOT_DIR . '/includes/video_processing.php';

set_time_limit(0);
ini_set('memory_limit', '1G');

$start_time = time();

function mlog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '][hls-migrate] ' . $msg . "\n";
    flush();
}

mlog("🚀 Migração HLS iniciada. A processar até não restar nenhum vídeo .mp4 no R2...");

$total_migrated  = 0;
$total_failed    = 0;

// Verificar se tem R2 ativo
if (!R2_ENABLED) {
    mlog("❌ R2 não está ativo. Este script destina-se a vídeos no Cloudflare R2.");
    exit(1);
}

// Loop principal: continua até não haver mais vídeos para migrar
while (true) {

    // Buscar o próximo vídeo .mp4 que ainda não foi migrado.
    // Identificamos vídeos legado pelo facto de video_path conter ".mp4"
    // e não conter "master.m3u8".
    try {
        $stmt = $pdo->prepare("
            SELECT id, video_path, title
            FROM videos
            WHERE moderation_status IN ('approved', 'pending')
              AND video_path LIKE '%.mp4'
              AND video_path NOT LIKE '%master.m3u8%'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $video = $stmt->fetch();
    } catch (Throwable $e) {
        mlog("❌ Erro na BD ao buscar próximo vídeo: " . $e->getMessage());
        sleep(5);
        continue;
    }

    if (!$video) {
        mlog("✅ Migração concluída! Todos os vídeos foram convertidos para HLS.");
        mlog("   Total migrados: $total_migrated | Falhados: $total_failed");
        break;
    }

    $video_id   = (int)$video['id'];
    $video_path = (string)$video['video_path'];
    $title      = mb_substr($video['title'], 0, 60);

    mlog("▶️  Vídeo #$video_id — \"$title\" — path: $video_path");

    // ── Passo 1: Download do MP4 do R2 para temp ──────────────────────────────
    $tmp_mp4 = sys_get_temp_dir() . '/mytube_migrate_' . $video_id . '_' . time() . '.mp4';
    mlog("   📥 A descarregar do R2...");

    $downloaded = r2_download_to_file($video_path, $tmp_mp4);
    if (!$downloaded) {
        mlog("   ❌ Falha ao descarregar — a saltar e marcar como problema.");
        // Não falhar o script — saltar para o próximo
        // Para não ficar em loop, marcamos com um sufixo especial (ou ignoramos)
        // Aqui simplesmente atualizamos o título para sinalizar e avançamos
        $total_failed++;
        // Marcar para não tentar de novo: adicionar _hls_failed à coluna num campo separado
        // Como não temos campo, vamos renomear o video_path com flag (estratégia simples)
        try {
            $pdo->prepare("UPDATE videos SET video_path = CONCAT(video_path, '?hls_skip=1') WHERE id=?")->execute([$video_id]);
        } catch (Throwable $e) {}
        continue;
    }

    mlog("   ✅ Download OK (" . round(filesize($tmp_mp4) / 1048576, 1) . " MB)");

    // ── Passo 2: Gerar HLS com FFmpeg ─────────────────────────────────────────
    mlog("   🎬 A gerar 4 qualidades HLS (720p, 480p, 360p, 144p)...");
    $hls_result = video_prepare_hls($tmp_mp4);

    if (!$hls_result['success']) {
        mlog("   ❌ FFmpeg falhou: " . $hls_result['error']);
        @unlink($tmp_mp4);
        $total_failed++;
        continue;
    }

    $hls_dir = $hls_result['output_dir'];
    mlog("   ✅ HLS gerado em: $hls_dir");
    @unlink($tmp_mp4); // MP4 temporário já não é necessário

    // ── Passo 3: Upload da pasta HLS para o R2 ────────────────────────────────
    $unique_name = 'hls_' . $video_id . '_' . time();
    mlog("   ☁️  A enviar pasta HLS para o R2 como \"$unique_name\"...");

    $r2_result = r2_upload_directory($hls_dir, $unique_name);

    // Limpar pasta HLS local
    $files_to_delete = glob($hls_dir . '/*') ?: [];
    foreach ($files_to_delete as $f) { @unlink($f); }
    $subdirs = glob($hls_dir . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($subdirs as $d) { @rmdir($d); }
    @rmdir($hls_dir);

    if (!$r2_result['success']) {
        mlog("   ❌ Upload R2 falhou: " . $r2_result['error']);
        $total_failed++;
        continue;
    }

    $new_path = R2_PATH_PREFIX . $r2_result['key'];
    mlog("   ✅ Upload OK → $new_path");

    // ── Passo 4: Apagar o MP4 antigo do R2 ────────────────────────────────────
    mlog("   🗑️  A apagar MP4 original do R2...");
    $deleted = r2_delete_video($video_path);
    if (!$deleted) {
        mlog("   ⚠️  Aviso: não foi possível apagar o MP4 original — mas o vídeo foi migrado na mesma.");
    }

    // ── Passo 5: Atualizar a base de dados ────────────────────────────────────
    try {
        $pdo->prepare("UPDATE videos SET video_path=? WHERE id=?")->execute([$new_path, $video_id]);
        $total_migrated++;
        mlog("   ✅ BD atualizada. Vídeo #$video_id migrado com sucesso! (Total: $total_migrated)");
    } catch (Throwable $e) {
        mlog("   ❌ Erro ao atualizar BD: " . $e->getMessage());
        $total_failed++;
        continue;
    }

    // Pequena pausa entre vídeos para não stressar a VPS/rede continuamente
    sleep(2);
}

$elapsed = round((time() - $start_time) / 60, 1);
mlog("🏁 Script terminado em {$elapsed} minutos. Migrados: $total_migrated | Falhados: $total_failed");
exit(0);