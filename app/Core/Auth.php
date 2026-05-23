<?php
declare(strict_types=1);
namespace MyTube\Core;

class Auth
{
    /**
     * Return the currently authenticated user ID, or null if not logged in.
     */
    public static function getCurrentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Return true when a user session is active.
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * Halt with 401 if no user is authenticated; otherwise return the user ID.
     */
    public static function requireAuth(): int
    {
        $id = self::getCurrentUserId();
        if ($id === null) {
            Response::error('Utilizador não autenticado', 401);
        }
        return $id;
    }

    /**
     * Regenerate session ID and reset CSRF token (call after login).
     */
    public static function regenerateSession(): void
    {
        session_regenerate_id(true);
        if (function_exists('csrf_regenerate')) {
            csrf_regenerate();
        }
    }
}
