<?php
declare(strict_types=1);
ob_start();
require_once '../includes/config.php';
require_once '../includes/ranking_cache.php';
require_once '../includes/push_helper.php';
ob_end_clean();

use MyTube\Core\{Auth, Response};
use MyTube\Repositories\{CommentRepository, VideoRepository}; // VideoRepository used inside CommentService
use MyTube\Services\{CommentService, NotificationService};

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$userId = Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Método não permitido', 405);
}

if (!csrf_verify()) {
    Response::error('Token de segurança inválido', 403);
}

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$videoId  = (int)($input['video_id'] ?? 0);
$text     = (string)($input['comment_text'] ?? '');
$parentId = isset($input['parent_comment_id']) ? (int)$input['parent_comment_id'] : null;

if ($videoId === 0) {
    Response::error('Dados obrigatórios não fornecidos', 400);
}

// Rate limiting: máx 1 comentário a cada 3s por utilizador
$rateKey = 'comment_last_' . $userId;
if (isset($_SESSION[$rateKey]) && (microtime(true) - $_SESSION[$rateKey]) < 3.0) {
    Response::error('Aguarde alguns segundos antes de comentar novamente', 429);
}
$_SESSION[$rateKey] = microtime(true);

$service = new CommentService(
    new CommentRepository($pdo),
    new VideoRepository($pdo),
    new NotificationService($pdo),
    $pdo,
);

$result = $service->createComment($userId, $videoId, $text, $parentId);

if (!$result['success']) {
    $code = match($result['error']) {
        'Vídeo não encontrado', 'Comentário pai não encontrado' => 404,
        default => 400,
    };
    Response::error($result['error'], $code);
}

// +3 ranking_points para o dono do vídeo (owner_id já vem do service, sem query extra)
if (!empty($result['video_owner_id'])) {
    ranking_points_increment($pdo, (int)$result['video_owner_id'], 3);
}

Response::success(['comment' => $result['comment']], 'Comment created', 201);
