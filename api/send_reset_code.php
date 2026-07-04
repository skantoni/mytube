<?php
// Garantir que SEMPRE retornamos JSON, mesmo em caso de erro fatal
header('Content-Type: application/json');

// Capturar erros fatais para retornar JSON em vez de 500
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            'debug_error' => $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        ]);
    }
});

ob_start();

try {
    $request_started_at = microtime(true);

    require_once '../includes/config.php';
    require_once '../includes/mail_helper.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
        exit;
    }

    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token de segurança inválido.']);
        exit;
    }

    $identifier = trim($_POST['email'] ?? '');

    if (empty($identifier)) {
        echo json_encode(['success' => false, 'message' => 'Por favor, insira um e-mail ou número de WhatsApp.']);
        exit;
    }

    $isPhone = false;
    // Verifica se é número de telefone (só digitos ou +, etc.)
    if (preg_match('/^[0-9\+\s]+$/', $identifier)) {
        $isPhone = true;
        $cleanPhone = preg_replace('/\D/', '', $identifier);
        if (strlen($cleanPhone) === 9) {
            $cleanPhone = '244' . $cleanPhone;
        }
        $stmt = $pdo->prepare("SELECT id, username, email, whatsapp_number FROM users WHERE whatsapp_number = ?");
        $stmt->execute([$cleanPhone]);
    } else {
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Formato de e-mail ou número inválido.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, username, email, whatsapp_number FROM users WHERE email = ?");
        $stmt->execute([$identifier]);
    }

    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => true, 'message' => 'Se os dados estiverem cadastrados, você receberá um código de verificação.']);
        exit;
    }

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

    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM password_resets WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$user['id']]);
    $count = $stmt->fetch()['cnt'];

    if ($count >= 3) {
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 hora antes de tentar novamente.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
    $stmt->execute([$user['id']]);

    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Guardamos o identificador no campo 'email' por compatibilidade
    $savedIdentifier = $isPhone ? $user['whatsapp_number'] : $user['email'];
    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, email, reset_code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $stmt->execute([$user['id'], $savedIdentifier, $code]);

    if ($isPhone) {
        require_once '../includes/whatsapp_helper.php';
        $message = "🔒 *MyTube* - Recuperação de Senha\n\n";
        $message .= "O seu código de verificação é: *$code*\n\n";
        $message .= "Este código é válido por 15 minutos.\nSe não solicitou a alteração, ignore esta mensagem.";
        
        $sent = sendWhatsappMessage($user['whatsapp_number'], $message);
        
        if ($sent) {
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Código de verificação enviado via WhatsApp!'
            ]);
        } else {
            error_log('Falha ao enviar whatsapp de reset.');
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Não foi possível enviar o código via WhatsApp. Tente mais tarde.'
            ]);
        }
        exit;
    }

    // Se não for phone (é e-mail):
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

    $result = sendMail($identifier, $subject, $htmlMessage);
    $elapsed_ms = (int) round((microtime(true) - $request_started_at) * 1000);

    if ($elapsed_ms > 5000) {
        error_log("Reset email envio lento ({$elapsed_ms}ms)");
    }

    if ($result['success']) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Código de verificação enviado para o seu e-mail!'
        ]);
    } else {
        error_log('Falha ao enviar email de reset: ' . $result['message']);
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível enviar o e-mail. Contacte o suporte mytubeao@gmail.com.',
            'debug_smtp' => $result['message']
        ]);
    }

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log("Erro em send_reset_code.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
        'debug_error' => $e->getMessage()
    ]);
} catch (\Throwable $t) {
    if (ob_get_length()) ob_clean();
    error_log("Erro fatal em send_reset_code.php: " . $t->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
        'debug_error' => $t->getMessage()
    ]);
}
