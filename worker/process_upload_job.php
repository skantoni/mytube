<?php
/**
 * process_upload_job.php — Worker de processamento de uploads em background
 *
 * Deve ser executado via cron a cada minuto:
 *   * * * * * php /var/www/mytube.social/worker/process_upload_job.php >> /var/log/mytube_worker.log 2>&1
 *
 * Fluxo:
 *   1. Seleciona um job com status='queued' (lock atómico via UPDATE+SELECT)
 *   2. Executa pipeline: FFmpeg → Música → Moderação NudeNet → Upload R2
 *   3. Atualiza videos e upload_jobs com resultado final
 *   4. Em caso de erro, marca job como 'failed' e limpa o vídeo da BD
 */

// ── Ambiente CLI obrigatório ──────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso proibido. Este script é apenas para CLI.');
}

define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/includes/config.php';
require_once ROOT_DIR . '/includes/ranking_cache.php';
require_once ROOT_DIR . '/includes/hashtag_helper.php';
require_once ROOT_DIR . '/includes/r2_storage.php';
require_once ROOT_DIR . '/includes/video_processing.php';
require_once ROOT_DIR . '/includes/content_moderation.php';

// Sem limite de tempo — worker corre isolado
set_time_limit(0);
ini_set('memory_limit', '512M');

$worker_start = microtime(true);
$log_prefix   = '[' . date('Y-m-d H:i:s') . '][worker] ';

function wlog(string $msg): void {
    global $log_prefix;
    echo $log_prefix . $msg . "\n";
    flush();
}

// ── Verificar tabela upload_jobs ─────────────────────────────────────────────
try {
    $exists = $pdo->query("SHOW TABLES LIKE 'upload_jobs'")->fetchColumn();
    if (!$exists) {
        wlog("ERRO: Tabela upload_jobs não existe. Corre primeiro: php worker/install_upload_jobs.php");
        exit(1);
    }
} catch (Throwable $e) {
    wlog("ERRO BD: " . $e->getMessage());
    exit(1);
}

// ── Selecionar e bloquear um job (atómico) ───────────────────────────────────
// Usa UPDATE para garantir que dois workers paralelos nunca processam o mesmo job
$locked = false;
$job    = null;

try {
    // Selecionar o próximo job queued
    $select = $pdo->prepare("
        SELECT id FROM upload_jobs
        WHERE status = 'queued' AND attempts < 3
        ORDER BY created_at ASC
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    ");
    $pdo->beginTransaction();
    $select->execute();
    $row = $select->fetch();

    if ($row) {
        $job_id = (int)$row['id'];
        // Marcar como 'processing' atomicamente dentro da mesma transação
        $pdo->prepare("
            UPDATE upload_jobs
            SET status='processing', started_at=NOW(), attempts=attempts+1, progress_msg='A iniciar processamento...'
            WHERE id=? AND status='queued'
        ")->execute([$job_id]);
        $pdo->commit();
        $locked = true;

        // Carregar todos os dados do job
        $job = $pdo->prepare("SELECT * FROM upload_jobs WHERE id=?")->execute([$job_id])
            ? $pdo->prepare("SELECT * FROM upload_jobs WHERE id=?") // reload
            : null;
        // Reload limpo
        $stmt = $pdo->prepare("SELECT * FROM upload_jobs WHERE id=?");
        $stmt->execute([$job_id]);
        $job = $stmt->fetch();
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    wlog("ERRO ao selecionar job: " . $e->getMessage());
    exit(1);
}

if (!$job) {
    wlog("Nenhum job pendente.");
    exit(0);
}

$job_id   = (int)$job['id'];
$video_id = (int)($job['video_id'] ?? 0);
wlog("Job #$job_id iniciado (vídeo #$video_id, user #{$job['user_id']}, tentativa #{$job['attempts']})");

// ── Helper: marcar job como falhado ─────────────────────────────────────────
function fail_job($pdo, int $job_id, int $video_id, string $error, ?string $tmp_path = null): void {
    wlog("FALHA: $error");
    try {
        $pdo->prepare("
            UPDATE upload_jobs SET status='failed', error_message=?, finished_at=NOW(), progress_msg='Erro no processamento'
            WHERE id=?
        ")->execute([$error, $job_id]);

        if ($video_id > 0) {
            // Remover vídeo da BD — upload falhou, não deve ficar visível
            $pdo->prepare("DELETE FROM videos WHERE id=?")->execute([$video_id]);
            // Decrementar contagem (foi incrementada no upload.php)
            $pdo->prepare("
                UPDATE users SET videos_count = GREATEST(0, videos_count - 1) WHERE id = (
                    SELECT user_id FROM upload_jobs WHERE id=? LIMIT 1
                )
            ")->execute([$job_id]);
        }
    } catch (Throwable $e) {
        wlog("ERRO ao registar falha: " . $e->getMessage());
    }

    // Limpar ficheiro temporário
    if ($tmp_path && file_exists($tmp_path)) {
        @unlink($tmp_path);
        wlog("Temporário eliminado: $tmp_path");
    }
}

// ── Helper: actualizar mensagem de progresso ─────────────────────────────────
function update_progress($pdo, int $job_id, string $msg): void {
    wlog($msg);
    try {
        $pdo->prepare("UPDATE upload_jobs SET progress_msg=? WHERE id=?")->execute([$msg, $job_id]);
    } catch (Throwable $e) { /* não bloquear */ }
}

// ── Variáveis do job ─────────────────────────────────────────────────────────
$user_id       = (int)$job['user_id'];
$tmp_path      = (string)$job['tmp_video_path'];
$original_name = (string)$job['original_name'];
$title         = (string)$job['title'];
$description   = (string)$job['description'];
$hashtags_raw  = (string)$job['hashtags'];
$is_public     = (int)$job['is_public'];
$ad_flow       = (int)$job['ad_flow'];

$music_track_data = (string)$job['music_track_data'];
$music_mode       = (string)$job['music_mode'];
$music_volume     = (float)$job['music_volume'];
$music_start      = (float)$job['music_start'];

// ── Verificar ficheiro temporário ─────────────────────────────────────────────
if (!file_exists($tmp_path) || filesize($tmp_path) === 0) {
    fail_job($pdo, $job_id, $video_id, "Ficheiro temporário não encontrado: $tmp_path");
    exit(1);
}

// ── Auto-migração: garantir colunas na tabela videos ─────────────────────────
$has_music_cols = false;
try {
    $pdo->query("SELECT music_name FROM videos LIMIT 0");
    $has_music_cols = true;
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE videos ADD COLUMN music_name VARCHAR(255) NOT NULL DEFAULT '' AFTER hashtags");
        $pdo->exec("ALTER TABLE videos ADD COLUMN music_artist VARCHAR(255) NOT NULL DEFAULT '' AFTER music_name");
        $has_music_cols = true;
    } catch (Throwable $e2) { $has_music_cols = false; }
}

$has_moderation_cols = false;
try {
    $pdo->query("SELECT moderation_status FROM videos LIMIT 0");
    $has_moderation_cols = true;
} catch (Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE videos
            ADD COLUMN moderation_status ENUM('processing','pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_public,
            ADD COLUMN moderation_score FLOAT DEFAULT NULL AFTER moderation_status,
            ADD COLUMN moderation_checked_at DATETIME DEFAULT NULL AFTER moderation_score
        ");
        $has_moderation_cols = true;
    } catch (Throwable $e2) { $has_moderation_cols = false; }
}

// ── PASSO 1: Transcodificar com FFmpeg ───────────────────────────────────────
update_progress($pdo, $job_id, 'A processar vídeo com FFmpeg...');
require_once ROOT_DIR . '/includes/upload_validation.php';

$video_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

$validation = validate_video_upload($tmp_path, $original_name, ['mp4','avi','mov','wmv','webm'], 100);
if (!$validation['valid']) {
    fail_job($pdo, $job_id, $video_id, 'Validação: ' . $validation['error'], $tmp_path);
    exit(1);
}

$processing_result = video_prepare_for_storage($tmp_path, $validation['extension']);
if (!$processing_result['success']) {
    fail_job($pdo, $job_id, $video_id, 'FFmpeg: ' . ($processing_result['error'] ?? 'Erro desconhecido'), $tmp_path);
    exit(1);
}

$processed_video_path = (string)$processing_result['output_path'];
$processed_video_ext  = (string)$processing_result['extension'];
$is_transcoded        = !empty($processing_result['transcoded']);

wlog("FFmpeg OK — extensão: $processed_video_ext, transcoded: " . ($is_transcoded ? 'sim' : 'não'));

// Limpar ficheiro original se diferente do processado
if ($processed_video_path !== $tmp_path && file_exists($tmp_path)) {
    @unlink($tmp_path);
}

// ── PASSO 2: Música de fundo ─────────────────────────────────────────────────
$music_name   = '';
$music_artist = '';

if (!empty($music_track_data)) {
    $music_data = json_decode($music_track_data, true);
    if (is_array($music_data) && !empty($music_data['download_url'])) {
        update_progress($pdo, $job_id, 'A descarregar e adicionar música de fundo...');

        $music_tmp_path = video_download_music($music_data['download_url']);
        if ($music_tmp_path) {
            $merge_result = video_merge_music(
                $processed_video_path,
                $music_tmp_path,
                $music_mode,
                $music_volume,
                $music_start
            );

            if ($merge_result['success'] && $merge_result['output_path']) {
                if ($is_transcoded && file_exists($processed_video_path)) {
                    @unlink($processed_video_path);
                }
                $processed_video_path = $merge_result['output_path'];
                $processed_video_ext  = 'mp4';
                $is_transcoded        = true;
                $music_name   = sanitize($music_data['name']   ?? '');
                $music_artist = sanitize($music_data['artist'] ?? '');
                wlog("Música de fundo adicionada: $music_name — $music_artist");
            } else {
                wlog("Aviso: falha ao adicionar música (" . ($merge_result['error'] ?? '') . ") — continuando sem música");
            }
            @unlink($music_tmp_path);
        } else {
            wlog("Aviso: falha ao descarregar música — continuando sem música");
        }
    }
}

// ── PASSO 3: Moderação NudeNet ───────────────────────────────────────────────
$moderation_status = 'approved';
$moderation_score  = null;

if ($has_moderation_cols) {
    update_progress($pdo, $job_id, 'A verificar conteúdo com NudeNet...');
    $mod_decision = moderation_decide_status($processed_video_path);
    wlog('Moderação: ' . $mod_decision['log']);

    if ($mod_decision['db_status'] === 'rejected') {
        if ($is_transcoded && file_exists($processed_video_path)) {
            @unlink($processed_video_path);
        }
        // Enviar notificação ao utilizador (se sistema de notificações disponível)
        try {
            $pdo->prepare("
                INSERT INTO notifications (user_id, type, message, created_at)
                VALUES (?, 'video_rejected', ?, NOW())
            ")->execute([$user_id,
                'O teu vídeo "' . mb_substr($title, 0, 60) . '" foi rejeitado: conteúdo inapropriado.'
            ]);
        } catch (Throwable $e) { /* tabela pode não ter este tipo */ }

        fail_job($pdo, $job_id, $video_id,
            $mod_decision['reject_msg'] ?: 'Conteúdo inapropriado detectado.',
            null // ficheiro já eliminado acima
        );
        exit(0); // exit(0) — não é erro do sistema, é rejeição legítima
    }

    $moderation_status = $mod_decision['db_status'];
    $moderation_score  = $mod_decision['score'];
}

// ── PASSO 4: Upload para R2 ou local ────────────────────────────────────────
update_progress($pdo, $job_id, 'A enviar vídeo para armazenamento...');

$uniqueName    = uniqid() . '_' . time() . '.' . $processed_video_ext;
$upload_success = false;
$db_video_path  = $uniqueName;
$local_path     = null;

if (R2_ENABLED) {
    $mime_type = r2_get_mime_type($processed_video_ext);
    $r2_result = r2_upload_video($processed_video_path, $uniqueName, $mime_type);

    if ($r2_result['success']) {
        $upload_success = true;
        $db_video_path  = R2_PATH_PREFIX . $uniqueName;
        wlog("Upload R2 OK: $db_video_path");
    } else {
        wlog("R2 falhou: " . $r2_result['error'] . " — a usar armazenamento local");
        $local_path = ROOT_DIR . '/uploads/videos/' . $uniqueName;
        if (!is_dir(ROOT_DIR . '/uploads/videos/')) {
            mkdir(ROOT_DIR . '/uploads/videos/', 0755, true);
        }
        if (rename($processed_video_path, $local_path) || copy($processed_video_path, $local_path)) {
            $upload_success = true;
            $db_video_path  = $uniqueName;
            wlog("Armazenamento local OK: $local_path");
        }
    }
} else {
    $local_path = ROOT_DIR . '/uploads/videos/' . $uniqueName;
    if (!is_dir(ROOT_DIR . '/uploads/videos/')) {
        mkdir(ROOT_DIR . '/uploads/videos/', 0755, true);
    }
    if (rename($processed_video_path, $local_path) || copy($processed_video_path, $local_path)) {
        $upload_success = true;
        $db_video_path  = $uniqueName;
        wlog("Armazenamento local OK: $local_path");
    }
}

// Limpar ficheiro processado se ainda existir
if ($is_transcoded && file_exists($processed_video_path) && $processed_video_path !== ($local_path ?? '')) {
    @unlink($processed_video_path);
}

if (!$upload_success) {
    fail_job($pdo, $job_id, $video_id, 'Falha ao fazer upload do ficheiro para armazenamento.');
    exit(1);
}

// ── PASSO 5: Atualizar a base de dados ───────────────────────────────────────
update_progress($pdo, $job_id, 'A finalizar...');

try {
    $pdo->beginTransaction();

    // Parsear hashtags do campo raw
    $parsed_hashtags = [];
    try {
        $parsed_hashtags = hashtag_parse_input($hashtags_raw);
    } catch (Throwable $e) { /* ignorar erros de hashtag no worker */ }

    // Atualizar registo de vídeo com o caminho final e status de moderação
    if ($has_music_cols && $has_moderation_cols) {
        $pdo->prepare("
            UPDATE videos SET
                video_path=?, moderation_status=?, moderation_score=?,
                moderation_checked_at=NOW(), music_name=?, music_artist=?
            WHERE id=?
        ")->execute([$db_video_path, $moderation_status, $moderation_score,
                     $music_name, $music_artist, $video_id]);
    } elseif ($has_moderation_cols) {
        $pdo->prepare("
            UPDATE videos SET
                video_path=?, moderation_status=?, moderation_score=?, moderation_checked_at=NOW()
            WHERE id=?
        ")->execute([$db_video_path, $moderation_status, $moderation_score, $video_id]);
    } elseif ($has_music_cols) {
        $pdo->prepare("
            UPDATE videos SET video_path=?, music_name=?, music_artist=? WHERE id=?
        ")->execute([$db_video_path, $music_name, $music_artist, $video_id]);
    } else {
        $pdo->prepare("UPDATE videos SET video_path=? WHERE id=?")->execute([$db_video_path, $video_id]);
    }

    // Sincronizar hashtags
    if ($video_id > 0) {
        hashtag_sync_video_relations($pdo, $video_id, $parsed_hashtags);
    }

    // Incrementar pontos de ranking
    ranking_points_increment($pdo, $user_id, 10);
    ranking_cache_clear_all();

    // Enviar notificação de sucesso ao utilizador
    $notif_msg = ($moderation_status === 'pending')
        ? 'O teu vídeo "' . mb_substr($title, 0, 60) . '" está em revisão e será publicado em breve.'
        : 'O teu vídeo "' . mb_substr($title, 0, 60) . '" foi publicado com sucesso!';
    try {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, message, created_at)
            VALUES (?, 'video_published', ?, NOW())
        ")->execute([$user_id, $notif_msg]);
    } catch (Throwable $e) { /* notificações opcionais */ }

    // Marcar job como concluído
    $pdo->prepare("
        UPDATE upload_jobs SET status='done', finished_at=NOW(), progress_msg='Publicado com sucesso!'
        WHERE id=?
    ")->execute([$job_id]);

    $pdo->commit();

    $elapsed = round(microtime(true) - $worker_start, 1);
    wlog("Job #$job_id CONCLUÍDO em {$elapsed}s — vídeo #$video_id publicado ($moderation_status)");

} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}

    // Se upload já foi feito, tentar limpar
    if (r2_is_r2_path($db_video_path)) {
        r2_delete_video($db_video_path);
    } elseif ($local_path && file_exists($local_path)) {
        @unlink($local_path);
    }

    fail_job($pdo, $job_id, $video_id, 'Erro BD ao finalizar: ' . $e->getMessage());
    exit(1);
}

exit(0);
