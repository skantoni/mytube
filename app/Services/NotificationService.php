<?php
declare(strict_types=1);
namespace MyTube\Services;

class NotificationService
{
    public function __construct(private readonly mixed $pdo) {}

    /**
     * Create a single notification row.
     *
     * @param string $type        e.g. 'comment', 'reply', 'mention', 'like', 'follow', 'friend_request'
     */
    public function notify(
        int $toUserId,
        int $fromUserId,
        string $type,
        ?int $referenceId = null,
        ?int $commentId   = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO notifications (user_id, actor_id, type, reference_id, comment_id)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$toUserId, $fromUserId, $type, $referenceId, $commentId]);
    }
}
