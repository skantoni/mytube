<?php
/**
 * api/send_whatsapp_verification.php
 *
 * Gera e envia um código de verificação de 6 dígitos via WhatsApp.
 *
 * POST /api/send_whatsapp_verification.php
 * Body (form): phone=244912345678
 * Headers: X-CSRF-Token: <token>
 *
 * Resposta JSON: { success: bool, message: string }
 */

header('Content-Type: application/json');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        http_response_code(200);
        echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
    }
});

ob_start();

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/whatsapp_helper.php';

    // ── Método ────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
        exit;
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────
    if (!csrf_verify()) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token de segurança inválido.']);
        exit;
    }

    // ── Validar número ────────────────────────────────────────────────────────
    $phone = trim($_POST['phone'] ?? '');

    if (empty($phone)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Por favor, insira o número de WhatsApp.']);
        exit;
    }

    if (!isValidAngolanPhone($phone)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Número de WhatsApp inválido. Use o formato angolano (ex: 912 345 678).']);
        exit;
    }

    $normalizedPhone = normalizeWhatsappNumber($phone);

    // ── Garantir que a tabela existe ──────────────────────────────────────────
    try {
        $pdo->query("SELECT 1 FROM whatsapp_verifications LIMIT 1");
    } catch (Exception $tableErr) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS whatsapp_verifications (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                phone      VARCHAR(25) NOT NULL,
                code       VARCHAR(6)  NOT NULL,
                used       TINYINT(1)  NOT NULL DEFAULT 0,
                expires_at DATETIME    NOT NULL,
                created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // ── Rate limiting: máximo 3 por hora por número ───────────────────────────
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) as cnt FROM whatsapp_verifications
         WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stmt->execute([$normalizedPhone]);
    $count = (int)$stmt->fetch()['cnt'];

    if ($count >= 3) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 1 hora antes de tentar novamente.']);
        exit;
    }

    // ── Invalidar códigos anteriores para este número ─────────────────────────
    $stmt = $pdo->prepare("UPDATE whatsapp_verifications SET used = 1 WHERE phone = ? AND used = 0");
    $stmt->execute([$normalizedPhone]);

    // ── Gerar código de 6 dígitos ─────────────────────────────────────────────
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // ── Guardar no banco (expira em 15 minutos) ───────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT INTO whatsapp_verifications (phone, code, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))"
    );
    $stmt->execute([$normalizedPhone, $code]);

    // ── Verificar se o bot está online ────────────────────────────────────────
    if (!isWhatsappBotOnline()) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Serviço de WhatsApp temporariamente indisponível. Tente novamente em alguns momentos.'
        ]);
        exit;
    }

    // ── Compor e enviar a mensagem ────────────────────────────────────────────
    $message = "🔐 *MyTube* - Código de Verificação\n\n"
             . "O seu código é: *{$code}*\n\n"
             . "⏳ Este código expira em *15 minutos*.\n"
             . "_Se não solicitou este código, ignore esta mensagem._";

    $sent = sendWhatsappMessage($normalizedPhone, $message);

    ob_end_clean();

    if ($sent) {
        echo json_encode(['success' => true, 'message' => 'Código enviado para o seu WhatsApp!']);
    } else {
        error_log("[WhatsApp] Falha ao enviar código para {$normalizedPhone}");
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível enviar a mensagem. Verifique o número e tente novamente.'
        ]);
    }

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log('Erro em send_whatsapp_verification.php: ' . $e->getCode());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
} catch (\Throwable $t) {
    if (ob_get_length()) ob_clean();
    error_log('Erro fatal em send_whatsapp_verification.php: ' . $t->getCode());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
}
