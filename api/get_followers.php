<?php
/**
 * API para buscar seguidores / seguindo de um usuário
 * 
 * Parâmetros GET:
 *   user_id  - ID do usuário
 *   type     - 'followers' ou 'following'
 *   page     - Página (default 1)
 *   limit    - Itens por página (default 20, max 50)
 */

require_once __DIR__ . '/../includes/config.php';

//header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = min(50, max(1, isset($_GET['limit']) ? (int)$_GET['limit'] : 20));
$offset = ($page - 1) * $limit;
$logged_user_id = $_SESSION['user_id'];

if ($user_id <= 0 || !in_array($type, ['followers', 'following'])) {
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

try {
    if ($type === 'followers') {
        // Quem segue este usuário
        $sql = "
            SELECT 
                u.id,
                u.username,
                u.full_name,
                u.profile_picture,
                u.is_verified,
                EXISTS(SELECT 1 FROM follows f2 WHERE f2.follower_id = ? AND f2.following_id = u.id) AS is_followed_by_me,
                EXISTS(SELECT 1 FROM follows f3 WHERE f3.follower_id = u.id AND f3.following_id = ?) AS follows_me
            FROM follows f
            INNER JOIN users u ON u.id = f.follower_id
            WHERE f.following_id = ?
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$logged_user_id, $logged_user_id, $user_id, $limit, $offset]);

        // Total count
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
        $count_stmt->execute([$user_id]);
    } else {
        // Quem este usuário segue
        $sql = "
            SELECT 
                u.id,
                u.username,
                u.full_name,
                u.profile_picture,
                u.is_verified,
                EXISTS(SELECT 1 FROM follows f2 WHERE f2.follower_id = ? AND f2.following_id = u.id) AS is_followed_by_me,
                EXISTS(SELECT 1 FROM follows f3 WHERE f3.follower_id = u.id AND f3.following_id = ?) AS follows_me
            FROM follows f
            INNER JOIN users u ON u.id = f.following_id
            WHERE f.follower_id = ?
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$logged_user_id, $logged_user_id, $user_id, $limit, $offset]);

        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
        $count_stmt->execute([$user_id]);
    }

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = (int)$count_stmt->fetchColumn();

    // Sanitizar dados
    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
        $u['is_verified'] = (bool)$u['is_verified'];
        $u['is_followed_by_me'] = (bool)$u['is_followed_by_me'];
        $u['follows_me'] = (bool)$u['follows_me'];
        $u['is_me'] = ($u['id'] === $logged_user_id);
        $avatar = $u['profile_picture'] ?? 'default.webp';
        if (empty($avatar) || !file_exists(__DIR__ . '/../assets/images/avatars/' . $avatar)) {
            $avatar = 'default.webp';
        }
        $u['profile_picture'] = $avatar;
    }
    unset($u);

    echo json_encode([
        'success' => true,
        'users' => $users,
        'total' => $total,
        'page' => $page,
        'has_more' => ($offset + $limit) < $total
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
