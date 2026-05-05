<?php
/**
 * Processador de fila de moderação — MyTube
 *
 * Analisa vídeos com status 'pending' usando NudeNet.
 * Destinado a ser chamado por cron job ou manualmente.
 *
 * Autenticação: header X-Cron-Secret ou parâmetro GET ?secret=...
 *
 * Exemplo cron (a cada 5 minutos):
 *   * /5 * * * * curl -s -H "X-Cron-Secret: SEU_SECRET" https://seusite.com/api/run_moderation.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/r2_storage.php';
require_once __DIR__ . '/../includes/content_moderation.php';

// ── Autenticação ──────────────────────────────────────────────
$cron_secret = defined('CRON_SECRET') ? CRON_SECRET : '';
if (!empty($cron_secret)) {
    $provided = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['secret'] ?? '');
    if (!hash_equals($cron_secret, (string)$provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso não autorizado']);
        exit;
    }
} else {
    // CRON_SECRET não definido — apenas admin autenticado pode executar
    if (!isLoggedIn() || !isAdminUser()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso não autorizado']);
        exit;
    }
}

// ── Verificar disponibilidade de NudeNet ──────────────────────
if (!moderation_is_nudenet_available()) {
    echo json_encode([
        'success' => false,
        'error'   => 'NudeNet não está disponível. Execute: bash moderation/install.sh',
    ]);
    exit;
}

// ── Parâmetros ────────────────────────────────────────────────
$batch_limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

// ── Buscar vídeos pendentes ───────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, video_path
        FROM videos
        WHERE moderation_status = 'pending'
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $batch_limit, PDO::PARAM_INT);
    $stmt->execute();
    $pending_videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao consultar a base de dados: ' . $e->getMessage()]);
    exit;
}

if (empty($pending_videos)) {
    echo json_encode(['success' => true, 'message' => 'Sem vídeos pendentes na fila.', 'processed' => 0]);
    exit;
}

// ── Processar cada vídeo ──────────────────────────────────────
$results = [
    'processed' => 0,
    'approved'  => 0,
    'rejected'  => 0,
    'errors'    => 0,
    'details'   => [],
];

foreach ($pending_videos as $video) {
    $video_id   = (int)$video['id'];
    $video_path = (string)$video['video_path'];

    // Resolver caminho local: para análise precisamos do ficheiro local
    // Se estiver em R2, descarregar temporariamente
    $local_path    = null;
    $is_temp       = false;
    $delete_after  = false;

    if (r2_is_r2_path($video_path)) {
        // Descarregar do R2 para um ficheiro temporário
        $temp_file = tempnam(sys_get_temp_dir(), 'mytube_mod_') . '.mp4';
        $download  = r2_download_to_file($video_path, $temp_file);
        if ($download) {
            $local_path   = $temp_file;
            $is_temp      = true;
            $delete_after = true;
        } else {
            // Não foi possível descarregar — marcar como erro e continuar
            error_log("run_moderation: falha ao descarregar R2 path=$video_path para vídeo ID=$video_id");
            moderation_update_video_status($pdo, $video_id, 'pending', null);
            $results['errors']++;
            $results['details'][] = ['id' => $video_id, 'status' => 'download_error'];
            continue;
        }
    } else {
        // Armazenamento local
        $local_path = UPLOAD_DIR . 'videos/' . basename($video_path);
        if (!file_exists($local_path)) {
            // Tentar caminho directo
            $local_path = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($video_path, '/');
        }
        if (!file_exists($local_path)) { 
            error_log("run_moderation: ficheiro não encontrado para vídeo ID=$video_id path=$video_path — mantém pending");
            $results['errors']++;
            $results['details'][] = ['id' => $video_id, 'status' => 'file_not_found'];
            continue;
        }
    }

    // Analisar com NudeNet
    $decision = moderation_decide_status($local_path);
    error_log("run_moderation: vídeo ID=$video_id → {$decision['db_status']} ({$decision['log']})");

    // Atualizar base de dados — respeitar a decisão do NudeNet
    moderation_update_video_status($pdo, $video_id, $decision['db_status'], $decision['score']);
    error_log("run_moderation: vídeo ID=$video_id → {$decision['db_status']} (score={$decision['score']})");

    if ($decision['db_status'] === 'rejected') {
        $results['rejected']++;
    } elseif ($decision['db_status'] === 'approved') {
        $results['approved']++;
    } else {
        $results['pending'] = ($results['pending'] ?? 0) + 1;
    }

    if ($delete_after && $local_path && file_exists($local_path)) {
        @unlink($local_path);
    }

    $results['processed']++;
    $results['details'][] = [
        'id'     => $video_id,
        'status' => $decision['db_status'],
        'score'  => $decision['score'],
    ];
}

$results['success'] = true;
echo json_encode($results);
