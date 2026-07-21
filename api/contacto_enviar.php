<?php
// ============================================================
// API: Envio do formulário de contacto
// POST /api/contacto_enviar.php
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mail_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

// Verificar CSRF
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token inválido. Recarrega a página e tenta novamente.']);
    exit;
}

// Rate limiting simples: máx 3 mensagens por IP por hora
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_key = 'contacto_' . md5($ip);
if (!isset($_SESSION[$rate_key])) {
    $_SESSION[$rate_key] = ['count' => 0, 'reset' => time() + 3600];
}
if (time() > $_SESSION[$rate_key]['reset']) {
    $_SESSION[$rate_key] = ['count' => 0, 'reset' => time() + 3600];
}
if ($_SESSION[$rate_key]['count'] >= 3) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Demasiadas mensagens enviadas. Tenta novamente em 1 hora.']);
    exit;
}

// Recolher e sanitizar campos
$nome    = trim(strip_tags($_POST['nome']    ?? ''));
$email   = trim(strip_tags($_POST['email']   ?? ''));
$assunto = trim(strip_tags($_POST['assunto'] ?? ''));
$mensagem = trim(strip_tags($_POST['mensagem'] ?? ''));

// Validações
$erros = [];
if (empty($nome) || mb_strlen($nome) < 2) {
    $erros[] = 'O nome deve ter pelo menos 2 caracteres.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros[] = 'Endereço de email inválido.';
}
if (empty($assunto) || mb_strlen($assunto) < 3) {
    $erros[] = 'O assunto deve ter pelo menos 3 caracteres.';
}
if (empty($mensagem) || mb_strlen($mensagem) < 10) {
    $erros[] = 'A mensagem deve ter pelo menos 10 caracteres.';
}
if (mb_strlen($mensagem) > 3000) {
    $erros[] = 'A mensagem não pode ter mais de 3000 caracteres.';
}

if (!empty($erros)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $erros)]);
    exit;
}

// Sanitizar para HTML no email
$nome_safe     = htmlspecialchars($nome,     ENT_QUOTES, 'UTF-8');
$email_safe    = htmlspecialchars($email,    ENT_QUOTES, 'UTF-8');
$assunto_safe  = htmlspecialchars($assunto,  ENT_QUOTES, 'UTF-8');
$msg_safe      = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
$data_hora     = date('d/m/Y \à\s H:i');

// Corpo do email em HTML
$html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f0f4ff;font-family:Inter,sans-serif;">
  <div style="max-width:580px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(30,64,175,.1);">
    <div style="background:linear-gradient(135deg,#1e40af,#3b82f6,#06b6d4);padding:28px 32px;text-align:center;">
      <div style="font-size:28px;font-weight:800;color:#fff;letter-spacing:-0.5px;">📩 MyTube</div>
      <p style="color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px;">Nova mensagem do formulário de contacto</p>
    </div>
    <div style="padding:32px;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <tr>
          <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#64748b;width:100px;font-weight:600;">Nome</td>
          <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">{$nome_safe}</td>
        </tr>
        <tr>
          <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600;">Email</td>
          <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;"><a href="mailto:{$email_safe}" style="color:#3b82f6;">{$email_safe}</a></td>
        </tr>
        <tr>
          <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600;">Assunto</td>
          <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">{$assunto_safe}</td>
        </tr>
        <tr>
          <td style="padding:10px 0;color:#64748b;font-weight:600;vertical-align:top;">Data</td>
          <td style="padding:10px 0;color:#64748b;">{$data_hora}</td>
        </tr>
      </table>
      <div style="margin-top:24px;">
        <p style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">Mensagem</p>
        <div style="background:#f8fafc;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:0 10px 10px 0;color:#334155;line-height:1.7;font-size:14.5px;">
          {$msg_safe}
        </div>
      </div>
      <div style="margin-top:28px;padding:16px;background:#eff6ff;border-radius:10px;font-size:13px;color:#3b82f6;">
        💡 Para responder, utiliza o email do utilizador acima: <strong>{$email_safe}</strong>
      </div>
    </div>
    <div style="background:#f8fafc;padding:16px 32px;text-align:center;font-size:12px;color:#94a3b8;">
      &copy; <?= date('Y') ?> MyTube &bull; mytube.social
    </div>
  </div>
</body>
</html>
HTML;

// Enviar para o email de suporte
$resultado = sendMail(
    'mytubeao@gmail.com',
    "[MyTube Contacto] {$assunto}",
    $html,
    "Nova mensagem de {$nome} ({$email}):\n\nAssunto: {$assunto}\n\n{$mensagem}"
);

if ($resultado['success']) {
    // Incrementar contador de rate limiting
    $_SESSION[$rate_key]['count']++;
    error_log("📩 contacto_enviar.php: mensagem de {$email} enviada com sucesso.");
    echo json_encode(['success' => true, 'message' => 'Mensagem enviada com sucesso! Respondemos em até 48 horas.']);
} else {
    error_log("❌ contacto_enviar.php: falha ao enviar de {$email}: " . $resultado['message']);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao enviar a mensagem. Tenta novamente ou envia directamente para mytubeao@gmail.com']);
}
