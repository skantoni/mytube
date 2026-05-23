<?php
declare(strict_types=1);
namespace MyTube\Services;

use MyTube\Repositories\CommentRepository;
use MyTube\Repositories\VideoRepository;

class CommentService
{
    public function __construct(
        private readonly CommentRepository   $comments,
        private readonly VideoRepository     $videos,
        private readonly NotificationService $notifications,
        private readonly mixed               $pdo,
    ) {}

    /**
     * Create a comment, fire notifications, and return the formatted comment data.
     *
     * @return array{success:bool, comment:array<string,mixed>|null, error:string}
     */
    public function createComment(
        int $userId,
        int $videoId,
        string $text,
        ?int $parentId = null,
    ): array {
        $text = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text)) ?? $text);

        if ($text === '') {
            return ['success' => false, 'comment' => null, 'error' => 'Comentário não pode estar vazio'];
        }

        if (strlen($text) > 500) {
            return ['success' => false, 'comment' => null, 'error' => 'Comentário muito longo (máximo 500 caracteres)'];
        }

        // Verify video exists
        if ($this->videos->findById($videoId) === null) {
            return ['success' => false, 'comment' => null, 'error' => 'Vídeo não encontrado'];
        }

        // Verify parent comment when replying
        if ($parentId !== null) {
            $parent = $this->comments->findById($parentId);
            if ($parent === null) {
                return ['success' => false, 'comment' => null, 'error' => 'Comentário pai não encontrado'];
            }
            if ((int)$parent['video_id'] !== $videoId) {
                return ['success' => false, 'comment' => null, 'error' => 'Comentário pai não pertence a este vídeo'];
            }
        }

        $commentId    = $this->comments->create($userId, $videoId, $text, $parentId);
        $videoOwnerId = $this->fireNotifications($userId, $videoId, $commentId, $parentId, $text);

        $comment = $this->comments->findWithAuthor($commentId);
        if ($comment === null) {
            return ['success' => false, 'comment' => null, 'error' => 'Erro ao recuperar comentário criado'];
        }

        $isAuthor         = ((int)$comment['user_id'] === $userId);
        $timeSinceCreated = time() - strtotime($comment['created_at']);
        $withinEditWindow = $timeSinceCreated <= 120;

        $rootId = null;
        if ($parentId !== null) {
            $grandParentId = $this->comments->getParentId($parentId);
            $rootId = $grandParentId !== null ? $grandParentId : $parentId;
        }

        return [
            'success'        => true,
            'error'          => '',
            'video_owner_id' => $videoOwnerId,
            'comment'        => [
                'id'               => $comment['id'],
                'comment_text'     => $comment['comment_text'],
                'likes_count'      => $comment['likes_count'],
                'created_at'       => $comment['created_at'],
                'user_id'          => $comment['user_id'],
                'username'         => $comment['username'],
                'full_name'        => $comment['full_name'],
                'profile_picture'  => $comment['profile_picture'] ?? 'default.webp',
                'is_verified'      => (bool)$comment['is_verified'],
                'user_liked'       => false,
                'replies'          => [],
                'replies_count'    => 0,
                'parent_comment_id' => $comment['parent_comment_id'],
                'root_comment_id'  => $rootId,
                'can_edit'         => $isAuthor && $withinEditWindow,
                'can_delete'       => $isAuthor,
                'edit_time_left'   => $withinEditWindow ? (120 - $timeSinceCreated) : 0,
            ],
        ];
    }

    /** Returns the video owner ID so the caller can use it without a second query. */
    private function fireNotifications(
        int $userId,
        int $videoId,
        int $commentId,
        ?int $parentId,
        string $text,
    ): ?int {
        $videoOwnerId    = $this->videos->getOwnerId($videoId);
        $alreadyNotified = [$userId];
        $actor           = $_SESSION['username'] ?? 'Alguém';

        // Notify video owner
        if ($videoOwnerId && $videoOwnerId !== $userId) {
            try {
                $this->notifications->notify($videoOwnerId, $userId, 'comment', $videoId, $commentId);
                if (function_exists('sendPushNotification')) {
                    sendPushNotification($this->pdo, $videoOwnerId, 'Novo comentário 💬', "$actor comentou no teu vídeo", "/index.php?v=$videoId");
                }
            } catch (\Exception) {}
            $alreadyNotified[] = $videoOwnerId;
        }

        // Notify parent comment author when replying
        if ($parentId !== null) {
            $parentComment = $this->comments->findById($parentId);
            $parentOwnerId = $parentComment ? (int)$parentComment['user_id'] : null;

            if ($parentOwnerId && $parentOwnerId !== $userId && !in_array($parentOwnerId, $alreadyNotified, true)) {
                try {
                    $this->notifications->notify($parentOwnerId, $userId, 'reply', $videoId, $commentId);
                    if (function_exists('sendPushNotification')) {
                        sendPushNotification($this->pdo, $parentOwnerId, 'Nova resposta 💬', "$actor respondeu ao teu comentário", "/index.php?v=$videoId");
                    }
                } catch (\Exception) {}
                $alreadyNotified[] = $parentOwnerId;
            }
        }

        // Notify mentioned users (max 5, skip already-notified)
        preg_match_all('/@(\w+)/', $text, $matches);
        if (!empty($matches[1])) {
            $usernames    = array_unique(array_slice($matches[1], 0, 5));
            $placeholders = implode(',', array_fill(0, count($usernames), '?'));
            $stmt         = $this->pdo->prepare("SELECT id, username FROM users WHERE username IN ($placeholders)");
            $stmt->execute($usernames);

            foreach ($stmt->fetchAll() as $mu) {
                $mentionedId = (int)$mu['id'];
                if (in_array($mentionedId, $alreadyNotified, true)) continue;
                try {
                    $this->notifications->notify($mentionedId, $userId, 'mention', $videoId, $commentId);
                    if (function_exists('sendPushNotification')) {
                        sendPushNotification($this->pdo, $mentionedId, 'Mencionado 📢', "$actor mencionou-te num comentário", "/index.php?v=$videoId");
                    }
                    $alreadyNotified[] = $mentionedId;
                } catch (\Exception) {}
            }
        }

        return $videoOwnerId;
    }
}
