<?php
// Garantir que SEMPRE retornamos JSON, mesmo em caso de erro fatal
header('Content-Type: application/json');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        http_response_code(200);
        echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
    }
});

ob_start();

try {
    require_once '../includes/config.php';
    require_once '../includes/mail_helper.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
        exit;
    }

    // Validar CSRF token
    if (!csrf_verify()) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token de segurança inválido.']);
        exit;
    }

    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Por favor, insira um e-mail válido.']);
        exit;
    }

    // Garantir que a tabela existe
    try {
        $pdo->query("SELECT 1 FROM email_verifications LIMIT 1");
    } catch (Exception $tableErr) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            code VARCHAR(6) NOT NULL,
            used TINYINT(1) DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Rate limiting: máximo 3 por hora por e-mail
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM email_verifications WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$email]);
    $count = (int)$stmt->fetch()['cnt'];

    if ($count >= 3) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 hora antes de tentar novamente.']);
        exit;
    }

    // Invalidar códigos anteriores para este e-mail
    $stmt = $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE email = ? AND used = 0");
    $stmt->execute([$email]);

    // Gerar código de 6 dígitos
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Salvar no banco (expira em 15 minutos)
    $stmt = $pdo->prepare("INSERT INTO email_verifications (email, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $stmt->execute([$email, $code]);

    // Montar e-mail HTML
    $subject = "MyTube - Código de Verificação de E-mail";

    $htmlMessage = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f4f8; margin: 0; padding: 20px; }
            .container { max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #1e40af, #3b82f6, #06b6d4); padding: 30px; text-align: center; }
            .header h1 { color: #ffffff; margin: 0; font-size: 28px; letter-spacing: 2px; }
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
                <p style='color: #334155; font-size: 16px;'><strong>Verificação de E-mail</strong></p>
                <p class='info'>Use o código abaixo para confirmar o seu endereço de e-mail e criar a sua conta:</p>
                <div class='code'>{$code}</div>
                <p class='info'>Este código expira em <strong>15 minutos</strong>.</p>
                <p class='warning'>Se você não solicitou o registo no MyTube, ignore este e-mail.</p>
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " MyTube. Todos os direitos reservados.
            </div>
        </div>
    </body>
    </html>
    ";

    $result = sendMail($email, $subject, $htmlMessage);

    ob_end_clean();

    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Código enviado para o seu e-mail!']);
    } else {
        error_log('Falha ao enviar e-mail de verificação: ' . $result['message']);
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível enviar o e-mail. Verifique a configuração SMTP.',
        ]);
    }

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log('Erro em send_email_verification.php: ' . $e->getCode());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
} catch (\Throwable $t) {
    if (ob_get_length()) ob_clean();
    error_log('Erro fatal em send_email_verification.php: ' . $t->getCode());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
}
