<?php
/**
 * API: Histórico de Pesquisa Persistente
 * 
 * GET  ?action=get                  → devolve histórico do utilizador (max 10)
 * POST action=save   query=...      → guarda/actualiza entrada no histórico
 * POST action=delete query=...      → remove entrada específica
 * POST action=clear                 → limpa todo o histórico
 *
 * Limpeza automática:
 *   - Máx 10 entradas por utilizador (as mais antigas são apagadas)
 *   - Entradas com mais de 90 dias são eliminadas
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (trim($_POST['action'] ?? ''))
    : (trim($_GET['action']  ?? 'get'));

// ── Garantir que a tabela existe (auto-install) ─────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_history (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            query       VARCHAR(255) NOT NULL,
            searched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_searched (user_id, searched_at),
            INDEX idx_cleanup       (searched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    // tabela já existe ou sem permissão — continuar
}

// ── Limpeza global de entradas antigas (>90 dias) – 1% de probabilidade ─────
if (random_int(1, 100) === 1) {
    try {
        $pdo->exec("DELETE FROM search_history WHERE searched_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    } catch (Throwable $e) { /* silenciar */ }
}

try {
    // ── GET: listar histórico ────────────────────────────────────────────────
    if ($action === 'get') {
        $stmt = $pdo->prepare("
            SELECT query, searched_at
            FROM search_history
            WHERE user_id = ?
            ORDER BY searched_at DESC
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $history = array_map(fn($r) => [
            'query'     => $r['query'],
            'timestamp' => (int)(strtotime($r['searched_at']) * 1000), // ms para JS
        ], $rows);

        echo json_encode(['success' => true, 'history' => $history]);
        exit;
    }

    // ── Verificar CSRF para mutações ─────────────────────────────────────────
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }

    // ── POST save: guardar pesquisa ──────────────────────────────────────────
    if ($action === 'save') {
        $query = trim($_POST['query'] ?? '');
        if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 255) {
            echo json_encode(['success' => false, 'error' => 'Query inválida']);
            exit;
        }

        $pdo->beginTransaction();

        // Remover entrada duplicada anterior (case-insensitive)
        $del = $pdo->prepare("
            DELETE FROM search_history
            WHERE user_id = ? AND LOWER(query) = LOWER(?)
        ");
        $del->execute([$user_id, $query]);

        // Inserir nova entrada no topo
        $ins = $pdo->prepare("
            INSERT INTO search_history (user_id, query, searched_at)
            VALUES (?, ?, NOW())
        ");
        $ins->execute([$user_id, $query]);

        // Manter apenas as 10 mais recentes — apagar excedentes
        $pdo->prepare("
            DELETE FROM search_history
            WHERE user_id = ?
              AND id NOT IN (
                  SELECT id FROM (
                      SELECT id FROM search_history
                      WHERE user_id = ?
                      ORDER BY searched_at DESC
                      LIMIT 10
                  ) AS keep
              )
        ")->execute([$user_id, $user_id]);

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    // ── POST delete: remover entrada específica ──────────────────────────────
    if ($action === 'delete') {
        $query = trim($_POST['query'] ?? '');
        $stmt = $pdo->prepare("
            DELETE FROM search_history WHERE user_id = ? AND query = ?
        ");
        $stmt->execute([$user_id, $query]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── POST clear: limpar todo o histórico ──────────────────────────────────
    if ($action === 'clear') {
        $stmt = $pdo->prepare("DELETE FROM search_history WHERE user_id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acção desconhecida']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('search_history.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
