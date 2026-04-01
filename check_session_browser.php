<?php
require_once 'includes/config.php';

header('Content-Type: application/json');

echo json_encode([
    'logged_in' => isLoggedIn(),
    'session_id' => session_id(),
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null,
    'profile_picture' => $_SESSION['profile_picture'] ?? null,
    'full_name' => $_SESSION['full_name'] ?? null,
    'all_session_keys' => array_keys($_SESSION)
], JSON_PRETTY_PRINT);
