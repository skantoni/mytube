<?php
declare(strict_types=1);
namespace MyTube\Validators;

class UserValidator
{
    /** @var string[] */
    private const RESERVED_USERNAMES = [
        'admin', 'root', 'mytube', 'suporte', 'support',
        'moderador', 'staff', 'sistema',
    ];

    /**
     * Validate a username.
     *
     * @return string[]
     */
    public function validateUsername(string $username): array
    {
        $errors = [];

        if (strlen($username) < 3 || strlen($username) > 12) {
            $errors[] = 'Nome de usuário deve ter entre 3 e 12 caracteres.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            $errors[] = 'Nome de usuário pode conter apenas letras, números, - e _';
        }

        if (in_array(strtolower($username), self::RESERVED_USERNAMES, true)) {
            $errors[] = 'Este nome de usuário não está disponível.';
        }

        return $errors;
    }

    /**
     * Validate an email address, including an optional DNS check.
     * If the email is empty, returns no errors (email is optional).
     *
     * @return string[]
     */
    public function validateEmail(string $email): array
    {
        $errors = [];

        if ($email === '') {
            return []; // e-mail é opcional
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail inválido. Verifica o formato.';
            return $errors;
        }

        $domain = substr(strrchr($email, '@'), 1);
        try {
            if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
                $errors[] = 'Domínio do e-mail não existe ou é inválido.';
            }
        } catch (\Throwable) {
            // DNS check failed — do not block registration (false positive is worse)
        }

        return $errors;
    }

    /**
     * Validate an Angolan WhatsApp number.
     * If the number is empty, returns no errors (also optional).
     *
     * @return string[]
     */
    public function validateWhatsappNumber(string $phone): array
    {
        $errors = [];

        if ($phone === '') {
            return []; // WhatsApp é opcional se o e-mail foi fornecido
        }

        $digits = preg_replace('/\D/', '', $phone);

        $valid = false;
        if (strlen($digits) === 9) {
            $valid = preg_match('/^9[0-9]{8}$/', $digits) === 1;
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '244')) {
            $local = substr($digits, 3);
            $valid = preg_match('/^9[0-9]{8}$/', $local) === 1;
        }

        if (!$valid) {
            $errors[] = 'Número de WhatsApp inválido. Use o formato angolano (ex: 912 345 678).';
        }

        return $errors;
    }

    /**
     * Validate a password.
     *
     * @return string[]
     */
    public function validatePassword(string $password, string $confirm = ''): array
    {
        $errors = [];

        if (strlen($password) < 6) {
            $errors[] = 'Senha deve ter pelo menos 6 caracteres.';
        }

        if ($confirm !== '' && $password !== $confirm) {
            $errors[] = 'Senhas não conferem.';
        }

        return $errors;
    }

    /**
     * Run all validations for a registration payload.
     *
     * @param array<string,string> $data  Keys: username, email (optional), full_name, password, confirm_password, whatsapp_number (optional)
     * @return string[]
     */
    public function validateRegistration(array $data): array
    {
        $errors = [];

        $username  = trim($data['username']      ?? '');
        $email     = trim(strtolower($data['email'] ?? ''));
        $fullName  = trim($data['full_name']     ?? '');
        $password  = $data['password']            ?? '';
        $confirm   = $data['confirm_password']    ?? '';
        $whatsapp  = trim($data['whatsapp_number'] ?? '');

        // Campos obrigatórios base
        if (empty($username) || empty($fullName) || empty($password)) {
            $errors[] = 'Por favor, preencha todos os campos obrigatórios.';
        }

        // Deve ter pelo menos email OU número de WhatsApp
        if (empty($email) && empty($whatsapp)) {
            $errors[] = 'Indique pelo menos um e-mail ou um número de WhatsApp.';
        }

        if (strlen($fullName) < 3) {
            $errors[] = 'Nome completo muito curto.';
        }

        array_push($errors, ...$this->validateUsername($username));
        array_push($errors, ...$this->validateEmail($email));
        array_push($errors, ...$this->validateWhatsappNumber($whatsapp));
        array_push($errors, ...$this->validatePassword($password, $confirm));

        return $errors;
    }
}
