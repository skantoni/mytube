<?php
/**
 * cleanup_orphan_jobs.php
 * Remove jobs e vídeos que ficaram na BD sem ficheiro temporário associado.
 * Executar UMA VEZ via CLI ou browser (com autenticação de admin).
 *
 * Uso:
 *   php /var/www/mytube.social/worker/cleanup_orphan_jobs.php
 *   ou
 *   https://mytube.social/worker/cleanup_orphan_jobs.php?secret=TROCA_ESTE_SECRET
 */

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/includes/config.php';

// ── Autenticação básica para acesso via browser ───────────────────────────────
$is_cli = php_sapi_name() === 'cli';
if (!$is_cli) {
    $secret = 'TROCA_ESTE_SECRET'; // ← Alterar antes de usar via browser
    if (($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403);
        die('Acesso negado. Use: ?secret=SEU_SECRET');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function log_line(string $msg): void {
    echo date('[Y-m-d H:i:s] ') . $msg . "\n";
    flush();
}

log_line("=== Limpeza de Jobs/Vídeos Órfãos ===");

// ── Verificar se a tabela upload_jobs existe ──────────────────────────────────
$tableExists = $pdo->query("SHOW TABLES LIKE 'upload_jobs'")->fetchColumn();
if (!$tableExists) {
    log_line("Tabela upload_jobs não existe. Nada a limpar.");
    exit(0);
}

// ── 1. Encontrar jobs com ficheiro temporário em falta ───────────────────────
log_line("A verificar jobs com ficheiros em falta...");

$jobs = $pdo->query("
    SELECT id, video_id, tmp_video_path, status, created_at
    FROM upload_jobs
    WHERE status IN ('queued', 'processing', 'failed')
    ORDER BY id ASC
")->fetchAll();

$cleaned_jobs   = 0;
$cleaned_videos = 0;
$skipped        = 0;

foreach ($jobs as $job) {
    $job_id    = (int)$job['id'];
    $video_id  = (int)($job['video_id'] ?? 0);
    $tmp_path  = (string)$job['tmp_video_path'];
    $status    = $job['status'];

    $file_missing = !empty($tmp_path) && !file_exists($tmp_path);

    if ($file_missing || $status === 'failed') {
        log_line("Job #{$job_id} (vídeo #{$video_id}) status={$status} — ficheiro " . ($file_missing ? 'em falta' : 'falhou') . ": {$tmp_path}");

        try {
            $pdo->beginTransaction();

            // Marcar job como failed (ou já está)
            $pdo->prepare("
                UPDATE upload_jobs
                SET status='failed', error_message=COALESCE(error_message, 'Ficheiro temporário em falta — limpeza automática'), finished_at=COALESCE(finished_at, NOW())
                WHERE id=?
            ")->execute([$job_id]);

            // Remover vídeo órfão da BD se não tem video_path real
            if ($video_id > 0) {
                $video = $pdo->prepare("SELECT video_path, user_id FROM videos WHERE id=? LIMIT 1");
                $video->execute([$video_id]);
                $video_row = $video->fetch();

                if ($video_row && empty($video_row['video_path'])) {
                    // video_path vazio = nunca foi processado com sucesso
                    $pdo->prepare("DELETE FROM videos WHERE id=?")->execute([$video_id]);
                    // Decrementar contagem
                    $pdo->prepare("UPDATE users SET videos_count = GREATEST(0, videos_count - 1) WHERE id=?")
                        ->execute([$video_row['user_id']]);
                    log_line("  → Vídeo #{$video_id} removido da BD (sem video_path).");
                    $cleaned_videos++;
                } elseif ($video_row) {
                    log_line("  → Vídeo #{$video_id} mantido (tem video_path: '{$video_row['video_path']}').");
                    $skipped++;
                }
            }

            $pdo->commit();
            $cleaned_jobs++;

        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $e2) {}
            log_line("  ERRO ao limpar job #{$job_id}: " . $e->getMessage());
        }
    } else {
        log_line("Job #{$job_id} OK — ficheiro existe: {$tmp_path}");
        $skipped++;
    }
}

// ── 2. Verificar ficheiros órfãos em raw_queue (sem job correspondente) ───────
log_line("\nA verificar ficheiros raw_queue órfãos...");

$raw_queue_dir = ROOT_DIR . '/uploads/raw_queue';
$cleaned_files = 0;

if (is_dir($raw_queue_dir)) {
    $files = glob($raw_queue_dir . '/raw_*');
    foreach ((array)$files as $file) {
        if (!is_file($file)) continue;

        $basename = basename($file);
        // Verificar se existe um job que aponta para este ficheiro
        $has_job = $pdo->prepare("SELECT id FROM upload_jobs WHERE tmp_video_path=? AND status IN ('queued','processing') LIMIT 1");
        $has_job->execute([$file]);
        $job_row = $has_job->fetch();

        if (!$job_row) {
            // Ficheiro mais antigo que 2 horas sem job activo → eliminar
            $age_hours = (time() - filemtime($file)) / 3600;
            if ($age_hours > 2) {
                @unlink($file);
                log_line("Ficheiro órfão eliminado ({$age_hours:.1f}h): {$basename}");
                $cleaned_files++;
            } else {
                log_line("Ficheiro recente (< 2h), a ignorar: {$basename}");
            }
        }
    }
} else {
    log_line("Pasta raw_queue não existe: {$raw_queue_dir}");
}

// ── Resumo ────────────────────────────────────────────────────────────────────
log_line("\n=== Resumo ===");
log_line("Jobs actualizados: {$cleaned_jobs}");
log_line("Vídeos removidos da BD: {$cleaned_videos}");
log_line("Ficheiros órfãos eliminados: {$cleaned_files}");
log_line("Ignorados (OK ou com video_path): {$skipped}");
log_line("Concluído.");

// ── 3. Mostrar comando para verificar logs PHP ────────────────────────────────
log_line("\n=== Verificar logs do PHP para a causa raiz ===");
log_line("Corre no servidor:");
log_line("  grep 'upload.php ERROR' /var/log/php8.3-fpm.log | tail -20");
log_line("  ou:");
log_line("  tail -50 /var/log/nginx/mytube.social.error.log");
