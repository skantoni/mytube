<?php
declare(strict_types=1);
namespace MyTube\Core;

class Response
{
    /**
     * Send a successful JSON response and halt execution.
     */
    public static function success(mixed $data = null, string $message = 'OK', int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
        exit;
    }

    /**
     * Send an error JSON response and halt execution.
     *
     * @param array<string> $errors
     */
    public static function error(string $message, int $code = 400, array $errors = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message, 'errors' => $errors]);
        exit;
    }
}
