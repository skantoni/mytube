<?php
/**
 * API para buscar usuários para menção em comentários (@autocomplete)
 * 
 * Prioridade: seguidores primeiro, depois outros usuários.
 * Retorna no máximo 5 resultados para manter a UI limpa e o custo baixo.
 * 
 * GET params:
 *   q - Texto de busca (mínimo 1 caractere)
 */
require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Se query vazia, retornar os 5 seguidores mais recentes (custo mínimo)
if ($query === '') {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.full_name, u.profile_picture, u.is_verified, 1 AS is_follower
            FROM follows f
            INNER JOIN users u ON u.id = f.following_id
            WHERE f.follower_id = ?
            ORDER BY f.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as &$u) {
            $u['id'] = (int)$u['id'];
            $u['is_verified'] = (bool)$u['is_verified'];
            $u['is_follower'] = true;
            $u['profile_picture'] = $u['profile_picture'] ?? 'default.webp';
        }
        unset($u);

        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro interno']);
        exit;
    }
}

// Busca com termo — uma única query usando UNION para priorizar seguidores
// Seguidores que correspondem vêm primeiro (priority=0), depois não-seguidores (priority=1)
// Exclui o próprio usuário logado
try {
    $searchTerm = '%' . $query . '%';
    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT id, username, full_name, profile_picture, is_verified, is_follower
        FROM (
            SELECT u.id, u.username, u.full_name, u.profile_picture, u.is_verified, 
                   1 AS is_follower, 0 AS priority
            FROM follows f
            INNER JOIN users u ON u.id = f.following_id
            WHERE f.follower_id = :uid1
              AND u.id != :uid2
              AND (u.username LIKE :q1 OR u.full_name LIKE :q2)
            
            UNION
            
            SELECT u.id, u.username, u.full_name, u.profile_picture, u.is_verified,
                   0 AS is_follower, 1 AS priority
            FROM users u
            WHERE u.id != :uid3
              AND u.id NOT IN (
                  SELECT f.following_id FROM follows f WHERE f.follower_id = :uid4
              )
              AND (u.username LIKE :q3 OR u.full_name LIKE :q4)
        ) AS combined
        ORDER BY priority ASC, 
                 CASE WHEN username LIKE :q_prefix THEN 0 ELSE 1 END,
                 username ASC
        LIMIT 5
    ");

    $stmt->execute([
        ':uid1' => $userId,
        ':uid2' => $userId,
        ':uid3' => $userId,
        ':uid4' => $userId,
        ':q1' => $searchTerm,
        ':q2' => $searchTerm,
        ':q3' => $searchTerm,
        ':q4' => $searchTerm,
        ':q_prefix' => $query . '%'
    ]);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
        $u['is_verified'] = (bool)$u['is_verified'];
        $u['is_follower'] = (bool)$u['is_follower'];
        $u['profile_picture'] = $u['profile_picture'] ?? 'default.webp';
    }
    unset($u);

    echo json_encode(['success' => true, 'users' => $users]);

} catch (Exception $e) {
    error_log("search_mention_users.php: Erro - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
?>
