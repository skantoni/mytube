<?php
declare(strict_types=1);
namespace MyTube\Repositories;

class CommentRepository
{
    public function __construct(private readonly mixed $pdo) {}

    /**
     * Insert a comment and atomically increment the video's comment counter.
     * Returns the new comment ID.
     */
    public function create(int $userId, int $videoId, string $text, ?int $parentId): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO comments (user_id, video_id, comment_text, parent_comment_id)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $videoId, $text, $parentId]);
            $commentId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare(
                "UPDATE videos
                 SET comments_count = comments_count + 1,
                     trend_score = (likes_count * 2) + views_count + ((comments_count + 1) * 3)
                 WHERE id = ?"
            )->execute([$videoId]);

            $this->pdo->commit();
            return $commentId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fetch paginated top-level comments for a video, with author info.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByVideo(int $videoId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.comment_text, c.likes_count, c.created_at, c.parent_comment_id,
                    c.user_id, u.username, u.full_name, u.profile_picture, u.is_verified
             FROM comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.video_id = ? AND c.parent_comment_id IS NULL
             ORDER BY c.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$videoId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Find a comment by ID.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $commentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id, video_id, comment_text, parent_comment_id
             FROM comments WHERE id = ?"
        );
        $stmt->execute([$commentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find the parent comment ID of a given comment (null if it is a root comment).
     */
    public function getParentId(int $commentId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT parent_comment_id FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * Delete a comment owned by the given user. Returns true when a row was removed.
     */
    public function delete(int $commentId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM comments WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$commentId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Fetch a comment with its author info.
     *
     * @return array<string,mixed>|null
     */
    public function findWithAuthor(int $commentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.comment_text, c.likes_count, c.created_at,
                    c.user_id, c.parent_comment_id,
                    u.username, u.full_name, u.profile_picture, u.is_verified
             FROM comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.id = ?"
        );
        $stmt->execute([$commentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
