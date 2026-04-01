<?php
// ============================================================
// Helper para envio de emails usando PHPMailer + SMTP
// ============================================================

require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Enviar email usando PHPMailer com SMTP
 * 
 * @param string $to       Email do destinatário
 * @param string $subject  Assunto
 * @param string $htmlBody Corpo do email em HTML
 * @param string $altBody  Corpo alternativo em texto puro (opcional)
 * @return array ['success' => bool, 'message' => string]
 */
function sendMail($to, $subject, $htmlBody, $altBody = '') {
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        return ['success' => false, 'message' => 'Envio de email desativado.'];
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Configurações do servidor
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
/*         
        // Timeout mais generoso para hospedagem lenta
        $mail->Timeout    = 30;
        $mail->SMTPKeepAlive = false;
        
        // Verificação SSL flexível para hosts com certificados problemáticos
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];
         */
        // Debug
        $mail->SMTPDebug  = defined('MAIL_DEBUG') ? MAIL_DEBUG : 0;
        
        // Remetente e destinatário
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to);
        
        // Conteúdo
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        
        $mail->send();
        
        return ['success' => true, 'message' => 'Email enviado com sucesso.'];
        
    } catch (Exception $e) {
        error_log("Erro ao enviar email: " . $mail->ErrorInfo);
        return ['success' => false, 'message' => 'Erro ao enviar email: ' . $mail->ErrorInfo];
    }
}
