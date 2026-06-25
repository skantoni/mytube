<?php
declare(strict_types=1);
namespace MyTube\Services;

use MyTube\Core\Auth;
use MyTube\Repositories\UserRepository;
use MyTube\Validators\UserValidator;

class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserValidator  $validator,
    ) {}

    /**
     * Register a new user.
     *
     * @param array<string,string> $data  Keys: username, email, full_name, password, confirm_password, instituicao
     * @return array{success:bool, errors:string[], user_id:int|null}
     */
    public function register(array $data): array
    {
        $errors = $this->validator->validateRegistration($data);

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'user_id' => null];
        }

        $username = trim($data['username']);
        $email    = trim(strtolower($data['email']));
        $fullName = trim($data['full_name']);
        $password = $data['password'];
        $instituicao = trim($data['instituicao'] ?? '');

        if ($this->users->usernameExists($username)) {
            return ['success' => false, 'errors' => ['Nome de usuário já existe.'], 'user_id' => null];
        }

        if ($this->users->emailExists($email)) {
            return ['success' => false, 'errors' => ['Este e-mail já está em uso.'], 'user_id' => null];
        }

        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $userId = $this->users->create($username, $email, $fullName, $hashedPassword, $instituicao);
            return ['success' => true, 'errors' => [], 'user_id' => $userId];
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                return ['success' => false, 'errors' => ['Nome de usuário ou e-mail já está em uso.'], 'user_id' => null];
            }
            error_log('AuthService::register PDOException: ' . $e->getCode());
            return ['success' => false, 'errors' => ['Erro ao criar conta. Tente novamente.'], 'user_id' => null];
        }
    }

    /**
     * Authenticate a user by username-or-email and password.
     *
     * @return array{success:bool, user:array<string,mixed>|null, error:string}
     */
    public function login(string $usernameOrEmail, string $password): array
    {
        $user = $this->users->findByUsernameOrEmail($usernameOrEmail);

        // Always run password_verify to prevent timing attacks
        if ($user === null) {
            password_verify($password, password_hash('dummy', PASSWORD_DEFAULT));
            return ['success' => false, 'user' => null, 'error' => 'Utilizador ou senha incorretos.'];
        }

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'user' => null, 'error' => 'Utilizador ou senha incorretos.'];
        }

        try {
            $this->users->resetOnlineStatus((int)$user['id']);
        } catch (\Exception) {
            // Login must never fail because of this
        }

        $_SESSION = [];
        Auth::regenerateSession();

        $_SESSION['user_id']         = $user['id'];
        $_SESSION['username']        = $user['username'];
        $_SESSION['full_name']       = $user['full_name'];
        $_SESSION['profile_picture'] = $user['profile_picture'];
        $_SESSION['auth_method']     = 'password';

        // ── Registar login no histórico (analytics de retenção) ──────────────
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $this->users->getPdo()->prepare(
                "INSERT IGNORE INTO user_login_history (user_id, logged_in_at, ip_address, user_agent)
                 VALUES (?, NOW(), ?, ?)"
            )->execute([(int)$user['id'], $ip, $ua]);
        } catch (\Exception) { /* login nunca falha por causa disto */ }

        // Never expose password hash to callers
        unset($user['password']);

        return ['success' => true, 'user' => $user, 'error' => ''];
    }
}
