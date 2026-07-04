<?php
/**
 * api/verify_whatsapp_code.php
 *
 * Valida o código de 6 dígitos enviado via WhatsApp e marca o número como verificado.
 *
 * POST /api/verify_whatsapp_code.php
 * Body (form): phone=244912345678 & code=123456
 * Headers: X-CSRF-Token: <token>
 *
 * Resposta JSON: { success: bool, message: string }
 */

header('Content-Type: application/json');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
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

    $phone = trim($_POST['phone'] ?? '');
    $code  = trim($_POST['code']  ?? '');

    if (empty($phone) || empty($code)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
        exit;
    }

    // Sanitizar: código deve ser apenas 6 dígitos
    if (!preg_match('/^\d{6}$/', $code)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Código inválido.']);
        exit;
    }

    $normalizedPhone = normalizeWhatsappNumber($phone);

    // ── Procurar código válido no banco ───────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT id FROM whatsapp_verifications
         WHERE phone = ? AND code = ? AND used = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$normalizedPhone, $code]);
    $row = $stmt->fetch();

    if (!$row) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Código incorreto ou expirado. Tente solicitar um novo código.']);
        exit;
    }

    // ── Marcar código como usado ──────────────────────────────────────────────
    $stmt = $pdo->prepare("UPDATE whatsapp_verifications SET used = 1 WHERE id = ?");
    $stmt->execute([$row['id']]);

    // ── Marcar número como verificado na tabela users ─────────────────────────
    // (só se o número já estiver associado a um utilizador)
    $stmt = $pdo->prepare(
        "UPDATE users SET whatsapp_verified = 1
         WHERE whatsapp_number = ?"
    );
    $stmt->execute([$normalizedPhone]);

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Número verificado com sucesso!']);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log('Erro em verify_whatsapp_code.php: ' . $e->getCode());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
} catch (\Throwable $t) {
    if (ob_get_length()) ob_clean();
    error_log('Erro fatal em verify_whatsapp_code.php: ' . $t->getCode());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
}
