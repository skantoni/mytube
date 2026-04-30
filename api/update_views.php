<?php
/**
 * update_views.php — Registar visualizações
 * 
 * A4-optimizado:
 * - Sem user_agent (poupava ~200 bytes/row para nada)
 * - Prune automático: apaga linhas >1h (só servem para dedup de 30 min)
 * - Sem SELECT prévio desnecessário
 * - views_count já é incrementado com +1, linhas antigas são descartáveis
 */
ob_start();
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once '../includes/config.php';
require_once '../includes/ranking_cache.php';

ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Validar CSRF token (permite requests sem autenticação, mas exige token)
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de segurança inválido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['video_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do vídeo é obrigatório']);
    exit;
}

$video_id   = (int)$input['video_id'];
$user_id    = isLoggedIn() ? $_SESSION['user_id'] : null;

try {
    // ✅ INICIAR TRANSAÇÃO: Garante atomicidade INSERT + UPDATE
    $pdo->beginTransaction();
    
    try {
        // Dedup: mesmo user + mesmo vídeo nos últimos 30 min?
        // Fallback para IP se não estiver logado
        if ($user_id) {
            $stmt = $pdo->prepare("
                SELECT 1 FROM video_views 
                WHERE video_id = ? AND user_id = ? AND viewed_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                LIMIT 1
            ");
            $stmt->execute([$video_id, $user_id]);
        } else {
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $stmt = $pdo->prepare("
                SELECT 1 FROM video_views 
                WHERE video_id = ? AND ip_address = ? AND viewed_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                LIMIT 1
            ");
            $stmt->execute([$video_id, $ip_address]);
        }
        
        if (!$stmt->fetchColumn()) {
            // Registrar visualização
            $stmt = $pdo->prepare("
                INSERT INTO video_views (video_id, user_id, ip_address) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$video_id, $user_id, $_SERVER['REMOTE_ADDR']]);
            
            // +1 no contador desnormalizado + trend_score
            $pdo->prepare("UPDATE videos SET views_count = views_count + 1, trend_score = (likes_count * 2) + (views_count + 1) + (comments_count * 3) WHERE id = ?")
                ->execute([$video_id]);

            // ✅ COMMIT DA TRANSAÇÃO: Confirma INSERT + UPDATE atomicamente
            $pdo->commit();

            // +1 ranking_points para o dono do vídeo
            $ownerStmt = $pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
            $ownerStmt->execute([$video_id]);
            $vid_owner = $ownerStmt->fetchColumn();
            if ($vid_owner) {
                ranking_points_increment($pdo, (int)$vid_owner, 1);
            }
            
            echo json_encode(['success' => true, 'message' => 'Visualização registrada']);
        } else {
            // Dedup: já foi contado
            $pdo->rollBack();
            echo json_encode(['success' => true, 'message' => 'Visualização já registrada']);
        }
        
    } catch (Exception $e) {
        // ✅ ROLLBACK EM CASO DE ERRO: Reverte INSERT e UPDATE
        $pdo->rollBack();
        error_log("❌ Erro em update_views (rollback executado): " . $e->getMessage());
        throw $e;
    }
    
    // ============================================
    // PRUNE: apagar linhas antigas (>1 hora)
    // Só servem para dedup de 30 min, depois são lixo.
    // DELETE com LIMIT para não bloquear a tabela.
    // ============================================
    $pdo->prepare("
        DELETE FROM video_views 
        WHERE viewed_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        LIMIT 500
    ")->execute();
    
} catch (Exception $e) {
    error_log("update_views.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
exit;