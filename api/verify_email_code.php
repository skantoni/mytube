<?php
/**
 * api/verify_email_code.php
 *
 * Valida o código de 6 dígitos enviado por e-mail e marca-o como usado.
 *
 * POST /api/verify_email_code.php
 * Body (form): email=user@example.com & code=123456
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

    $email = trim($_POST['email'] ?? '');
    $code  = trim($_POST['code']  ?? '');

    if (empty($email) || empty($code)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
        exit;
    }

    // Código deve ser apenas 6 dígitos
    if (!preg_match('/^\d{6}$/', $code)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Código inválido. Deve ter 6 dígitos.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'E-mail inválido.']);
        exit;
    }

    // ── Procurar código válido no banco ───────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT id FROM email_verifications
         WHERE email = ? AND code = ? AND used = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$email, $code]);
    $row = $stmt->fetch();

    if (!$row) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Código incorreto ou expirado. Solicite um novo código.',
        ]);
        exit;
    }

    // ── Marcar código como usado ──────────────────────────────────────────────
    $stmt = $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?");
    $stmt->execute([$row['id']]);

    // Guardar na sessão que este email foi verificado (útil se precisarmos no registo)
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['email_verified'] = $email;
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'E-mail verificado com sucesso!']);

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log('Erro em verify_email_code.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
} catch (\Throwable $t) {
    if (ob_get_length()) ob_clean();
    error_log('Erro fatal em verify_email_code.php: ' . $t->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
}
