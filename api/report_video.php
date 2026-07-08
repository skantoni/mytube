<?php
/**
 * api/report_video.php
 * Endpoint para denunciar um vídeo.
 * POST { video_id: int, reason: string, details?: string }
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

// Autenticação obrigatória
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Precisas de estar autenticado para denunciar.']);
    exit;
}

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

// Verificar CSRF
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$video_id   = isset($input['video_id']) ? (int)$input['video_id'] : 0;
$reason     = isset($input['reason'])   ? trim($input['reason'])   : '';
$details    = isset($input['details'])  ? trim(mb_substr($input['details'], 0, 500)) : '';

// Validar video_id
if ($video_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do vídeo inválido.']);
    exit;
}

// Validar reason
$valid_reasons = ['nudity', 'violence', 'copyright', 'drugs', 'hate_speech', 'spam', 'other'];
if (!in_array($reason, $valid_reasons, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Motivo de denúncia inválido.']);
    exit;
}

$reporter_id = (int)$_SESSION['user_id'];

try {
    // Verificar se o vídeo existe e não é do próprio utilizador
    $stmt = $pdo->prepare("SELECT id, user_id, title, reports_count FROM videos WHERE id = ? AND is_public = 1");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();

    if (!$video) {
        http_response_code(404);
        echo json_encode(['error' => 'Vídeo não encontrado.']);
        exit;
    }

    // Não podes denunciar o teu próprio vídeo
    if ((int)$video['user_id'] === $reporter_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Não podes denunciar o teu próprio vídeo.']);
        exit;
    }

    // Verificar se já denunciou este vídeo
    $stmt = $pdo->prepare("SELECT id FROM video_reports WHERE video_id = ? AND reporter_id = ?");
    $stmt->execute([$video_id, $reporter_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Já denunciaste este vídeo anteriormente.']);
        exit;
    }

    // Iniciar transação
    $pdo->beginTransaction();

    try {
        // Inserir denúncia
        $stmt = $pdo->prepare("
            INSERT INTO video_reports (video_id, reporter_id, reason, details)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$video_id, $reporter_id, $reason, $details ?: null]);

        // Incrementar contador de denúncias
        $stmt = $pdo->prepare("
            UPDATE videos
            SET reports_count = reports_count + 1
            WHERE id = ?
        ");
        $stmt->execute([$video_id]);

        // Buscar novo total de denúncias
        $stmt = $pdo->prepare("SELECT reports_count FROM videos WHERE id = ?");
        $stmt->execute([$video_id]);
        $new_count = (int)$stmt->fetchColumn();

        // AUTO-OCULTAR se atingir 3 denúncias
        $auto_hidden = false;
        if ($new_count >= 3) {
            $stmt = $pdo->prepare("UPDATE videos SET is_hidden = 1 WHERE id = ? AND is_hidden = 0");
            $stmt->execute([$video_id]);
            $auto_hidden = $stmt->rowCount() > 0;

            // Criar notificação para admins
            if ($auto_hidden) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE is_admin = 1 LIMIT 10");
                $stmt->execute();
                $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($admins as $admin_id) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, actor_id, type, reference_id, message)
                            VALUES (?, ?, 'report', ?, ?)
                        ");
                        $msg = "Vídeo #$video_id foi ocultado automaticamente por atingir $new_count denúncias.";
                        $stmt->execute([$admin_id, $reporter_id, $video_id, $msg]);
                    } catch (Exception $e) {
                        // Ignorar erros de notificação
                    }
                }
            }
        }

        $pdo->commit();

        echo json_encode([
            'success'     => true,
            'message'     => 'Denúncia enviada com sucesso. A nossa equipa irá analisar o conteúdo.',
            'auto_hidden' => $auto_hidden,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log("report_video.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno. Tenta novamente mais tarde.']);
}
?>
