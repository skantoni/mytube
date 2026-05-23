<?php
declare(strict_types=1);
namespace MyTube\Services;

class EmailVerificationService
{
    public function __construct(private readonly mixed $pdo) {}

    /**
     * Generate a 6-digit OTP, persist it, and send it to $email.
     * Rate-limited to 3 attempts per hour.
     *
     * @return array{success:bool, message:string}
     */
    public function sendVerificationEmail(int $userId, string $email): array
    {
        // Rate limit: 3 per hour per email
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM email_verifications
             WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$email]);
        if ((int)$stmt->fetch()['cnt'] >= 3) {
            return ['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 hora antes de tentar novamente.'];
        }

        // Invalidate previous codes
        $this->pdo->prepare("UPDATE email_verifications SET used = 1 WHERE email = ? AND used = 0")
                  ->execute([$email]);

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->pdo->prepare(
            "INSERT INTO email_verifications (email, code, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))"
        )->execute([$email, $code]);

        if (!function_exists('sendMail')) {
            require_once dirname(__DIR__, 2) . '/includes/mail_helper.php';
        }

        $subject  = 'MyTube - Código de Verificação de E-mail';
        $year     = date('Y');
        $html     = <<<HTML
        <html><body style="font-family:Arial,sans-serif;background:#f0f4f8;padding:20px">
        <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden">
          <div style="background:linear-gradient(135deg,#1e40af,#3b82f6,#06b6d4);padding:30px;text-align:center">
            <h1 style="color:#fff;margin:0">MyTube</h1>
          </div>
          <div style="padding:30px;text-align:center">
            <p style="color:#334155;font-size:16px"><strong>Verificação de E-mail</strong></p>
            <p style="color:#64748b;font-size:14px">Use o código abaixo para confirmar o seu endereço de e-mail:</p>
            <div style="display:inline-block;background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;font-size:32px;font-weight:bold;letter-spacing:8px;padding:15px 30px;border-radius:10px;margin:20px 0">{$code}</div>
            <p style="color:#64748b;font-size:14px">Este código expira em <strong>15 minutos</strong>.</p>
            <p style="color:#ef4444;font-size:13px">Se não solicitou o registo, ignore este e-mail.</p>
          </div>
          <div style="background:#f8fafc;padding:20px;text-align:center;color:#94a3b8;font-size:12px">
            &copy; {$year} MyTube. Todos os direitos reservados.
          </div>
        </div>
        </body></html>
        HTML;

        $result = sendMail($email, $subject, $html);

        if (!$result['success']) {
            error_log('EmailVerificationService: falha ao enviar email de verificação');
        }

        return $result['success']
            ? ['success' => true,  'message' => 'Código enviado para o seu e-mail!']
            : ['success' => false, 'message' => 'Não foi possível enviar o e-mail. Verifique a configuração SMTP.'];
    }

    /**
     * Verify a token stored in the users table (link-based verification).
     * Returns true when a user was activated.
     */
    public function verifyToken(string $token): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE users
             SET is_verified = 1, verify_token = NULL, verify_token_expires = NULL
             WHERE verify_token = ? AND verify_token_expires > NOW()"
        );
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }
}
