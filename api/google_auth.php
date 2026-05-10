<?php
/**
 * Google OAuth — Login / Registo automático
 *
 * Recebe um id_token assinado pelo Google (via Google Identity Services),
 * valida-o contra a API pública do Google, e:
 *   - Se o utilizador já existe (por google_id ou email), faz login.
 *   - Se não existe, cria a conta automaticamente e faz login.
 *
 * POST body (JSON ou FormData):
 *   credential  — JWT id_token retornado pelo Google
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=UTF-8');

// Só aceitar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

// ── 1. Ler o credential ───────────────────────────────────────────────────────
$credential = '';

// Pode vir como JSON ou FormData
$rawBody = file_get_contents('php://input');
$json    = json_decode($rawBody, true);
if (!empty($json['credential'])) {
    $credential = $json['credential'];
} elseif (!empty($_POST['credential'])) {
    $credential = $_POST['credential'];
}

if (empty($credential)) {
    echo json_encode(['success' => false, 'message' => 'Credencial Google em falta.']);
    exit;
}

// ── 2. Verificar o token junto ao Google ─────────────────────────────────────
$tokenInfoUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

$ch = curl_init($tokenInfoUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response   = curl_exec($ch);
$curlError  = curl_error($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
    error_log('[google_auth] cURL error: ' . $curlError . ' HTTP: ' . $httpCode);
    echo json_encode(['success' => false, 'message' => 'Erro ao validar token Google. Tente novamente.']);
    exit;
}

$googleData = json_decode($response, true);

if (empty($googleData) || !empty($googleData['error_description'])) {
    echo json_encode(['success' => false, 'message' => 'Token Google inválido.']);
    exit;
}

// ── 3. Validar audience (client_id) ─────────────────────────────────────────
$googleClientId = env('GOOGLE_CLIENT_ID', '');
if (!empty($googleClientId) && $googleData['aud'] !== $googleClientId) {
    error_log('[google_auth] aud mismatch: ' . $googleData['aud']);
    echo json_encode(['success' => false, 'message' => 'Token Google inválido (audience).']);
    exit;
}

// ── 4. Extrair dados do utilizador ───────────────────────────────────────────
$googleId  = $googleData['sub']             ?? '';
$email     = $googleData['email']           ?? '';
$name      = $googleData['name']            ?? '';
$picture   = $googleData['picture']         ?? '';
$verified  = ($googleData['email_verified'] ?? 'false') === 'true';

if (empty($googleId) || empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Dados insuficientes retornados pelo Google.']);
    exit;
}

if (!$verified) {
    echo json_encode(['success' => false, 'message' => 'O e-mail Google não está verificado.']);
    exit;
}

// ── 5. Verificar se já existe no BD ─────────────────────────────────────────
try {
    // Prioridade: google_id → email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR email = ? LIMIT 1");
    $stmt->execute([$googleId, $email]);
    $user = $stmt->fetch();

    if ($user) {
        // ── 5a. Utilizador existente ─────────────────────────────────────────
        // Garantir que o google_id está associado (caso tenha criado conta normal antes)
        if (empty($user['google_id'])) {
            $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?")->execute([$googleId, $user['id']]);
        }
    } else {
        // ── 5b. Criar nova conta ─────────────────────────────────────────────
        // Gerar username único a partir do nome
        $baseUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower(explode(' ', $name)[0]));
        $baseUsername = substr($baseUsername ?: 'user', 0, 10);
        $username     = $baseUsername;
        $suffix       = 1;

        while (true) {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->execute([$username]);
            if (!$checkStmt->fetch()) break;
            $username = $baseUsername . $suffix;
            $suffix++;
            if ($suffix > 9999) {
                $username = 'user' . substr(md5($googleId), 0, 8);
                break;
            }
        }

        // Senha aleatória (a conta Google não precisa de senha)
        $randomPassword = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

        $insert = $pdo->prepare("
            INSERT INTO users (username, email, full_name, password, google_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->execute([$username, $email, $name, $randomPassword, $googleId]);
        $newId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$newId]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Erro ao criar sessão. Tente novamente.']);
        exit;
    }

    // ── 6. Criar sessão ───────────────────────────────────────────────────────
    // Resetar status online stale
    try {
        $pdo->prepare("UPDATE user_online_status SET is_online = 0, last_seen = NOW() WHERE user_id = ?")
            ->execute([$user['id']]);
    } catch (Exception $ignored) {}

    // ✅ CRÍTICO: Limpar ANTES de regenerar (ordem inversa causava perda de sessão)
    $_SESSION = [];
    session_regenerate_id(true);

    $_SESSION['user_id']         = $user['id'];
    $_SESSION['username']        = $user['username'];
    $_SESSION['full_name']       = $user['full_name'];
    $_SESSION['profile_picture'] = $user['profile_picture'];
    $_SESSION['auth_method']     = 'google';

    // ✅ Regenerar token CSRF para a nova sessão
    csrf_regenerate();

    echo json_encode([
        'success'  => true,
        'redirect' => 'index.php?splash=1',
    ]);
    exit;

} catch (PDOException $e) {
    error_log('[google_auth] DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno. Tente novamente mais tarde.']);
    exit;
}
