<?php
declare(strict_types=1);
namespace MyTube\Repositories;

class ChatRepository
{
    public function __construct(private readonly mixed $pdo) {}

    /**
     * Find an existing conversation between two users.
     *
     * @return array<string,mixed>|null
     */
    public function findConversation(int $userA, int $userB): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM conversations
             WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)
             LIMIT 1"
        );
        $stmt->execute([$userA, $userB, $userB, $userA]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Create a new conversation between two users and return the conversation ID.
     */
    public function createConversation(int $userA, int $userB): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO conversations (user1_id, user2_id, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())"
        );
        $stmt->execute([$userA, $userB]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Persist a message and update the conversation timestamp. Returns the new message ID.
     */
    public function saveMessage(int $conversationId, int $senderId, string $message): int
    {
        $receiverStmt = $this->pdo->prepare(
            "SELECT user1_id, user2_id FROM conversations WHERE id = ?"
        );
        $receiverStmt->execute([$conversationId]);
        $conv = $receiverStmt->fetch();

        $receiverId = ((int)$conv['user1_id'] === $senderId)
            ? (int)$conv['user2_id']
            : (int)$conv['user1_id'];

        $stmt = $this->pdo->prepare(
            "INSERT INTO messages (conversation_id, sender_id, receiver_id, message, type, status, created_at)
             VALUES (?, ?, ?, ?, 'text', 'sent', NOW())"
        );
        $stmt->execute([$conversationId, $senderId, $receiverId, $message]);
        $messageId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")
                  ->execute([$conversationId]);

        return $messageId;
    }

    /**
     * Return the most recent messages in a conversation.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getMessages(int $conversationId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, sender_id, receiver_id, message, type, status, created_at
             FROM messages
             WHERE conversation_id = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$conversationId, $limit]);
        return array_reverse($stmt->fetchAll());
    }
}
