<?php
/**
 * API de Moderação Manual — MyTube
 *
 * GET  ?action=list&status=pending   → listar vídeos pendentes/rejeitados
 * POST ?action=approve&video_id=N    → aprovar um vídeo
 * POST ?action=reject&video_id=N     → rejeitar um vídeo
 * POST ?action=reanalyze&video_id=N  → re-analisar com NudeNet
 *
 * Acesso: admin ou moderador apenas.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/r2_storage.php';
require_once __DIR__ . '/../includes/content_moderation.php';

// ── Autenticação ──────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

// Verificar role admin ou moderator
try {
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $role_stmt->execute([$_SESSION['user_id']]);
    $role_row = $role_stmt->fetch(PDO::FETCH_ASSOC);
    $user_role = $role_row['role'] ?? 'user';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao verificar permissões']);
    exit;
}

if (!in_array($user_role, ['admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Requer role admin ou moderator.']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

// ── LIST: listar vídeos para revisão ─────────────────────────
if ($action === 'list') {
    $status   = in_array($_GET['status'] ?? 'pending', ['pending', 'rejected', 'approved'], true)
        ? ($_GET['status'] ?? 'pending')
        : 'pending';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;

    try {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM videos WHERE moderation_status = ?");
        $count_stmt->execute([$status]);
        $total = (int)$count_stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                v.id,
                v.title,
                v.video_path,
                v.thumbnail_path,
                v.moderation_status,
                v.moderation_score,
                v.moderation_checked_at,
                v.created_at,
                u.username,
                u.full_name
            FROM videos v
            INNER JOIN users u ON v.user_id = u.id
            WHERE v.moderation_status = ?
            ORDER BY v.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $status);
        $stmt->bindValue(2, $per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($videos as &$v) {
            $v['video_url']     = resolve_video_url($v['video_path']);
            $v['moderation_score'] = $v['moderation_score'] !== null ? (float)$v['moderation_score'] : null;
        }
        unset($v);

        echo json_encode([
            'success'  => true,
            'status'   => $status,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'videos'   => $videos,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro na base de dados: ' . $e->getMessage()]);
    }
    exit;
}

// ── Ações POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

$video_id = (int)($_POST['video_id'] ?? 0);
if ($video_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'video_id inválido']);
    exit;
}

// Verificar que o vídeo existe
try {
    $v_stmt = $pdo->prepare("SELECT id, video_path, moderation_status FROM videos WHERE id = ? LIMIT 1");
    $v_stmt->execute([$video_id]);
    $video = $v_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro na base de dados']);
    exit;
}

if (!$video) {
    http_response_code(404);
    echo json_encode(['error' => 'Vídeo não encontrado']);
    exit;
}

// ── APPROVE ───────────────────────────────────────────────────
if ($action === 'approve') {
    moderation_update_video_status($pdo, $video_id, 'approved', null);
    error_log("admin_moderate: vídeo ID=$video_id aprovado manualmente por user={$_SESSION['user_id']}");
    echo json_encode(['success' => true, 'message' => 'Vídeo aprovado.', 'video_id' => $video_id]);
    exit;
}

// ── REJECT ────────────────────────────────────────────────────
if ($action === 'reject') {
    moderation_update_video_status($pdo, $video_id, 'rejected', null);
    error_log("admin_moderate: vídeo ID=$video_id rejeitado manualmente por user={$_SESSION['user_id']}");
    echo json_encode(['success' => true, 'message' => 'Vídeo rejeitado.', 'video_id' => $video_id]);
    exit;
}

// ── REJECT + DELETE (remove ficheiro também) ─────────────────
if ($action === 'reject_delete') {
    $video_path = (string)$video['video_path'];

    moderation_update_video_status($pdo, $video_id, 'rejected', null);

    if (r2_is_r2_path($video_path)) {
        r2_delete_video($video_path);
    } else {
        $local = UPLOAD_DIR . 'videos/' . basename($video_path);
        if (file_exists($local)) {
            @unlink($local);
        }
    }

    error_log("admin_moderate: vídeo ID=$video_id rejeitado+eliminado por user={$_SESSION['user_id']}");
    echo json_encode(['success' => true, 'message' => 'Vídeo rejeitado e eliminado.', 'video_id' => $video_id]);
    exit;
}

// ── REANALYZE ─────────────────────────────────────────────────
if ($action === 'reanalyze') {
    if (!moderation_is_nudenet_available()) {
        echo json_encode(['success' => false, 'error' => 'NudeNet não disponível no servidor.']);
        exit;
    }

    $video_path = (string)$video['video_path'];
    $local_path = null;
    $is_temp    = false;

    if (r2_is_r2_path($video_path)) {
        $temp_file = tempnam(sys_get_temp_dir(), 'mytube_mod_') . '.mp4';
        if (r2_download_to_file($video_path, $temp_file)) {
            $local_path = $temp_file;
            $is_temp    = true;
        } else {
            echo json_encode(['success' => false, 'error' => 'Falha ao descarregar vídeo do R2.']);
            exit;
        }
    } else {
        $local_path = UPLOAD_DIR . 'videos/' . basename($video_path);
        if (!file_exists($local_path)) {
            echo json_encode(['success' => false, 'error' => 'Ficheiro de vídeo não encontrado localmente.']);
            exit;
        }
    }

    $decision = moderation_decide_status($local_path);
    moderation_update_video_status($pdo, $video_id, $decision['db_status'], $decision['score']);

    if ($is_temp && $local_path && file_exists($local_path)) {
        @unlink($local_path);
    }

    error_log("admin_moderate: re-análise vídeo ID=$video_id → {$decision['db_status']} por user={$_SESSION['user_id']}");
    echo json_encode([
        'success'    => true,
        'video_id'   => $video_id,
        'new_status' => $decision['db_status'],
        'score'      => $decision['score'],
        'log'        => $decision['log'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => "Ação '$action' desconhecida. Use: list, approve, reject, reject_delete, reanalyze"]);
