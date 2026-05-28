<?php
/**
 * api/upload_status.php
 * Endpoint de polling — retorna o estado de um job de upload.
 *
 * GET /api/upload_status.php?job_id=123
 *
 * Resposta JSON:
 *   { "status": "queued"|"processing"|"done"|"failed",
 *     "progress": "A processar vídeo...",
 *     "video_id": 42,       // só quando done
 *     "moderation": "approved"|"pending",  // só quando done
 *     "error": "..." }      // só quando failed
 */

require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

// Apenas utilizadores autenticados
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$job_id = (int)($_GET['job_id'] ?? 0);
if ($job_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'job_id inválido']);
    exit;
}

try {
    // Verificar que a tabela existe
    $tableExists = $pdo->query("SHOW TABLES LIKE 'upload_jobs'")->fetchColumn();
    if (!$tableExists) {
        echo json_encode(['status' => 'failed', 'error' => 'Sistema de fila não instalado.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT j.id, j.status, j.progress_msg, j.error_message,
               j.video_id, j.user_id, j.ad_flow,
               v.moderation_status
        FROM upload_jobs j
        LEFT JOIN videos v ON v.id = j.video_id
        WHERE j.id = ?
        LIMIT 1
    ");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Job não encontrado']);
        exit;
    }

    // Segurança: só o dono do job pode consultá-lo
    if ((int)$job['user_id'] !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado']);
        exit;
    }

    $response = [
        'status'   => $job['status'],
        'progress' => $job['progress_msg'] ?: match($job['status']) {
            'queued'     => 'Na fila de espera...',
            'processing' => 'A processar vídeo...',
            'done'       => 'Publicado com sucesso!',
            'failed'     => 'Erro no processamento.',
            default      => 'A processar...',
        },
    ];

    if ($job['status'] === 'done') {
        $response['video_id']   = (int)$job['video_id'];
        $response['moderation'] = $job['moderation_status'] ?? 'approved';
        $response['ad_flow']    = (bool)$job['ad_flow'];
        $response['redirect']   = ((int)$job['ad_flow'] && $job['video_id'])
            ? 'anuncios.php?new_video_id=' . $job['video_id']
            : 'index.php';
    }

    if ($job['status'] === 'failed') {
        $response['error'] = $job['error_message'] ?: 'Erro desconhecido no processamento.';
    }

    echo json_encode($response);

} catch (Throwable $e) {
    error_log('upload_status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
