<?php
declare(strict_types=1);
namespace MyTube\Repositories;

class UserRepository
{
    public function __construct(private readonly mixed $pdo) {}

    /**
     * Find a user by username (case-sensitive), email, or WhatsApp number.
     * Accepts any of the three identifiers — used on the login page.
     *
     * @return array<string,mixed>|null
     */
    public function findByUsernameOrEmail(string $value): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, email, whatsapp_number, full_name, password,
                    profile_picture, role, is_verified, whatsapp_verified
             FROM users
             WHERE BINARY username = ? OR email = ? OR whatsapp_number = ?"
        );
        $stmt->execute([$value, $value, $value]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find a user by WhatsApp number.
     *
     * @return array<string,mixed>|null
     */
    public function findByWhatsappNumber(string $phone): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, email, whatsapp_number, full_name, password,
                    profile_picture, role, is_verified, whatsapp_verified
             FROM users WHERE whatsapp_number = ?"
        );
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find a single user by username (exact, case-sensitive).
     *
     * @return array<string,mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, email, whatsapp_number, full_name, password,
                    profile_picture, role, is_verified, whatsapp_verified
             FROM users WHERE BINARY username = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find a user by email address.
     *
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, email, whatsapp_number, full_name, password,
                    profile_picture, role, is_verified, whatsapp_verified
             FROM users WHERE email = ?"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Return true when the email is already registered.
     */
    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return (bool)$stmt->fetch();
    }

    /**
     * Return true when the username is already taken.
     */
    public function usernameExists(string $username): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return (bool)$stmt->fetch();
    }

    /**
     * Return true when the WhatsApp number is already registered.
     */
    public function whatsappNumberExists(string $phone): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE whatsapp_number = ?");
        $stmt->execute([$phone]);
        return (bool)$stmt->fetch();
    }

    /**
     * Insert a new user and return the new user ID.
     */
    public function create(
        string $username,
        ?string $email,
        string $fullName,
        string $hashedPassword,
        string $instituicao = '',
        ?string $whatsappNumber = null
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, full_name, password, instituicao, whatsapp_number)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$username, $email, $fullName, $hashedPassword, $instituicao, $whatsappNumber]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Associate (or update) the WhatsApp number of an existing user.
     */
    public function setWhatsappNumber(int $userId, string $phone): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET whatsapp_number = ?, whatsapp_verified = 0 WHERE id = ?"
        );
        $stmt->execute([$phone, $userId]);
    }

    /**
     * Update the hashed password for a user.
     */
    public function updatePassword(int $userId, string $hashedPassword): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);
    }

    /**
     * Persist an email-verification token and its expiry for a user.
     */
    public function setVerifyToken(int $userId, string $token, string $expires): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET verify_token = ?, verify_token_expires = ? WHERE id = ?"
        );
        $stmt->execute([$token, $expires, $userId]);
    }

    /**
     * Mark a user as verified via token. Returns true when a user was found and updated.
     */
    public function activateByToken(string $token): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users
             SET is_verified = 1, verify_token = NULL, verify_token_expires = NULL
             WHERE verify_token = ? AND verify_token_expires > NOW()"
        );
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reset the stale online status for a user after login.
     */
    public function resetOnlineStatus(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE user_online_status SET is_online = 0, last_seen = NOW() WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
    }

    /**
     * Expose the underlying PDO connection (e.g., for cross-cutting concerns).
     */
    public function getPdo(): mixed
    {
        return $this->pdo;
    }
}
