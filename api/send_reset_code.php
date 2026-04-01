<?php
// Garantir que SEMPRE retornamos JSON, mesmo em caso de erro fatal
header('Content-Type: application/json');

// Capturar erros fatais para retornar JSON em vez de 500
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Limpar qualquer output anterior
        if (ob_get_length()) ob_clean();
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            'debug_error' => $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        ]);
    }
});

// Buffer de saída para capturar erros inesperados
ob_start();

try {
    require_once '../includes/config.php';
    require_once '../includes/mail_helper.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
        exit;
    }

    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Por favor, insira um e-mail válido.']);
        exit;
    }

    // Verificar se o email existe no sistema
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Por segurança, não revelamos se o email existe ou não
        echo json_encode(['success' => true, 'message' => 'Se o e-mail estiver cadastrado, você receberá um código de verificação.']);
        exit;
    }

    // Verificar/criar tabela password_resets se não existir
    try {
        $pdo->query("SELECT 1 FROM password_resets LIMIT 1");
    } catch (Exception $tableErr) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            reset_code VARCHAR(10) NOT NULL,
            used TINYINT(1) DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_code (reset_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Rate limiting: máximo 3 códigos por hora para o mesmo email
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM password_resets WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$email]);
    $count = $stmt->fetch()['cnt'];

    if ($count >= 3) {
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 hora antes de tentar novamente.']);
        exit;
    }

    // Invalidar códigos anteriores
    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0");
    $stmt->execute([$email]);

    // Gerar código de 6 dígitos
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Salvar no banco (expira em 15 minutos)
    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, email, reset_code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $stmt->execute([$user['id'], $email, $code]);

    // Montar email HTML
    $username = htmlspecialchars($user['username']);
    $subject = "MyTube - Código de Recuperação de Senha";

    $htmlMessage = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f4f8; margin: 0; padding: 20px; }
            .container { max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #1e40af, #3b82f6, #06b6d4); padding: 30px; text-align: center; }
            .header h1 { color: #ffffff; margin: 0; font-size: 28px; }
            .content { padding: 30px; text-align: center; }
            .code { display: inline-block; background: linear-gradient(135deg, #1e40af, #3b82f6); color: #ffffff; font-size: 32px; font-weight: bold; letter-spacing: 8px; padding: 15px 30px; border-radius: 10px; margin: 20px 0; }
            .info { color: #64748b; font-size: 14px; line-height: 1.6; }
            .warning { color: #ef4444; font-size: 13px; margin-top: 15px; }
            .footer { background: #f8fafc; padding: 20px; text-align: center; color: #94a3b8; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>MyTube</h1>
            </div>
            <div class='content'>
                <p style='color: #334155; font-size: 16px;'>Olá, <strong>@{$username}</strong>!</p>
                <p class='info'>Recebemos uma solicitação para redefinir sua senha. Use o código abaixo:</p>
                <div class='code'>{$code}</div>
                <p class='info'>Este código expira em <strong>15 minutos</strong>.</p>
                <p class='warning'>Se você não solicitou esta alteração, ignore este e-mail.</p>
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " MyTube. Todos os direitos reservados.
            </div>
        </div>
    </body>
    </html>
    ";

    // Enviar via PHPMailer SMTP
    $result = sendMail($email, $subject, $htmlMessage);

    if ($result['success']) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Código de verificação enviado para o seu e-mail!'
        ]);
    } else {
        // Log do erro para debug
        error_log("Falha ao enviar email de reset para {$email}: " . $result['message']);
        
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível enviar o e-mail. Verifique a configuração SMTP.',
            'debug_smtp' => $result['message']
        ]);
    }

} catch (Exception $e) {
    // Capturar QUALQUER exceção e retornar JSON válido
    if (ob_get_length()) ob_clean();
    error_log("Erro em send_reset_code.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
        'debug_error' => $e->getMessage()
    ]);
} catch (\Throwable $t) {
    // Capturar erros do PHP 7+ (TypeError, etc.)
    if (ob_get_length()) ob_clean();
    error_log("Erro fatal em send_reset_code.php: " . $t->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
        'debug_error' => $t->getMessage()
    ]);
}
