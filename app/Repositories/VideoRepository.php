<?php
declare(strict_types=1);
namespace MyTube\Repositories;

class VideoRepository
{
    public function __construct(private readonly mixed $pdo) {}

    /**
     * Insert a new video record and return the new video ID.
     */
    public function create(
        int $userId,
        string $title,
        string $description,
        string $filename,
        string $hashtags = '',
        bool $isPublic = true
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO videos (user_id, title, description, video_path, hashtags, is_public, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$userId, $title, $description, $filename, $hashtags, (int)$isPublic]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Find a video by ID.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $videoId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id, title, description, video_path, thumbnail_path,
                    hashtags, is_public, views_count, likes_count, comments_count,
                    trend_score, moderation_status, created_at
             FROM videos WHERE id = ?"
        );
        $stmt->execute([$videoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Return all videos belonging to a user.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, description, video_path, thumbnail_path,
                    views_count, likes_count, comments_count, created_at
             FROM videos WHERE user_id = ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Update the thumbnail path for a video.
     */
    public function updateThumbnail(int $videoId, string $path): void
    {
        $this->pdo->prepare("UPDATE videos SET thumbnail_path = ? WHERE id = ?")->execute([$path, $videoId]);
    }

    /**
     * Delete a video owned by a specific user. Returns true when a row was removed.
     */
    public function delete(int $videoId, int $userId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM videos WHERE id = ? AND user_id = ?");
        $stmt->execute([$videoId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return the owner user_id of a video, or null when the video does not exist.
     */
    public function getOwnerId(int $videoId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
        $stmt->execute([$videoId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }
}
