<?php
require_once 'includes/config.php';

use MyTube\Repositories\UserRepository;
use MyTube\Services\AuthService;
use MyTube\Validators\UserValidator;

if (isLoggedIn()) {
    redirect('index.php');
}

$error      = '';
$success    = '';
$error_from = '';

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $success = 'Conta criada com sucesso! Faça login.';
}

$reg_username  = '';
$reg_email     = '';
$reg_full_name = '';
$reg_instituicao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authService = new AuthService(new UserRepository($pdo), new UserValidator());

    if (isset($_POST['login'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Por favor, preencha todos os campos.';
        } else {
            $result = $authService->login($username, $password);
            if ($result['success']) {
                echo "<script>window.location.href = 'index.php?splash=1';</script>";
                exit();
            } else {
                $error = $result['error'];
            }
        }
    } elseif (isset($_POST['register'])) {
        $reg_username    = trim($_POST['reg_username'] ?? '');
        $reg_email       = trim(strtolower($_POST['reg_email'] ?? ''));
        $reg_full_name   = trim($_POST['reg_full_name'] ?? '');
        $reg_instituicao = trim($_POST['reg_instituicao'] ?? '');
        $error_from      = 'register';

        $result = $authService->register([
            'username'         => $reg_username,
            'email'            => $reg_email,
            'full_name'        => $reg_full_name,
            'instituicao'      => $reg_instituicao,
            'password'         => $_POST['reg_password'] ?? '',
            'confirm_password' => $_POST['reg_confirm_password'] ?? '',
            'whatsapp_number'  => $_POST['reg_whatsapp'] ?? '',
        ]);

        if ($result['success']) {
            header('Location: login.php?registered=1');
            exit;
        } else {
            $error = $result['errors'][0] ?? 'Erro ao criar conta.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyTube - Sua rede social de vídeos</title>
    <meta name="description" content="MyTube é a rede social de vídeos onde criadores competem e se destacam. Partilhe os seus vídeos, ganhe seguidores e descubra talentos incríveis!">
    <meta name="keywords" content="mytube, rede social vídeos, partilhar vídeos, criadores de conteúdo, social media">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/login.php">

    <!-- Open Graph (Facebook, WhatsApp, etc.) -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.mytube.social/login.php">
    <meta property="og:title" content="MyTube - Aqui os criadores competem">
    <meta property="og:description" content="MyTube é a rede social de vídeos onde criadores competem e se destacam. Partilhe os seus vídeos, ganhe seguidores e descubra talentos incríveis!">
    <meta property="og:image" content="https://www.mytube.social/assets/images/pwa-icon-512.png">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:site_name" content="MyTube">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="MyTube - Aqui os criadores competem">
    <meta name="twitter:description" content="Partilhe os seus vídeos, ganhe seguidores e descubra talentos incríveis na MyTube!">
    <meta name="twitter:image" content="https://www.mytube.social/assets/images/pwa-icon-512.png">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "MyTube",
      "url": "https://www.mytube.social",
      "description": "Rede social de vídeos onde criadores competem e se destacam",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "https://www.mytube.social/index.php?search={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    }
    </script>

    <?php echo csrf_meta(); ?>
    <meta name="google-client-id" content="<?php echo htmlspecialchars(env('GOOGLE_CLIENT_ID', ''), ENT_QUOTES, 'UTF-8'); ?>">

    <link rel="stylesheet" href="<?php echo asset('assets/css/auth.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-card">
            <div class="logo">
                <h1>MyTube</h1>
                <p>Aquí os criadores competem</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <div class="auth-tabs">
                <button class="tab-btn <?php echo ($error_from !== 'register') ? 'active' : ''; ?>" onclick="showLogin()">Entrar</button>
                <button class="tab-btn <?php echo ($error_from === 'register') ? 'active' : ''; ?>" onclick="showRegister()">Cadastrar</button>
            </div>
            
            <!-- Formulário de Login -->
            <form id="loginForm" method="POST" class="auth-form <?php echo ($error_from !== 'register') ? 'active' : ''; ?>">
                <div class="input-group">
                    <input type="text" name="username" id="loginIdentifier" placeholder="Username, e-mail ou número de WhatsApp" required autocomplete="username">
                </div>
                <div class="input-group password-group">
                    <input type="password" name="password" placeholder="Senha" required>
                    <button type="button" class="toggle-password" onclick="togglePassword(this)" tabindex="-1">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="eye-off-icon" style="display:none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <button type="submit" name="login" class="btn btn-primary">Entrar</button>
                <p class="forgot-password">
                    <a href="#" onclick="showForgotPassword(); return false;">Esqueceu a senha?</a>
                </p>

                <div class="auth-divider"><span>ou</span></div>

                <div id="googleBtnLogin" class="google-signin-btn"></div>
            </form>

            <!-- Fluxo de Esqueceu a Senha -->
            <div id="forgotPasswordFlow" class="auth-form forgot-flow">
                <!-- Etapa 1: Inserir Email -->
                <div id="forgotStep1" class="forgot-step active">
                    <div class="forgot-header">
                        <button type="button" class="back-btn" onclick="backToLogin()">&larr;</button>
                        <h3>Recuperar Senha</h3>
                    </div>
                    <p class="forgot-description">Insira o e-mail da sua conta para receber um código de verificação.</p>
                    <div class="input-group">
                        <input type="email" id="resetEmail" placeholder="Seu e-mail" required>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="sendResetCode()" id="btnSendCode">Enviar Código</button>
                </div>

                <!-- Etapa 2: Inserir Código -->
                <div id="forgotStep2" class="forgot-step">
                    <div class="forgot-header">
                        <button type="button" class="back-btn" onclick="backToStep1()">&larr;</button>
                        <h3>Verificar Código</h3>
                    </div>
                    <p class="forgot-description">Insira o código de 6 dígitos enviado para <strong id="emailDisplay"></strong></p>
                    <div class="code-inputs" id="codeInputs">
                        <input type="text" maxlength="1" class="code-digit" data-index="0" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit" data-index="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit" data-index="2" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit" data-index="3" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit" data-index="4" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit" data-index="5" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="verifyResetCode()" id="btnVerifyCode">Verificar</button>
                    <p class="resend-code">
                        <span id="resendTimer">Reenviar código em <strong id="countdown">60</strong>s</span>
                        <a href="#" id="resendLink" style="display:none;" onclick="resendCode(); return false;">Reenviar código</a>
                    </p>
                </div>

                <!-- Etapa 3: Nova Senha -->
                <div id="forgotStep3" class="forgot-step">
                    <div class="forgot-header">
                        <h3>Nova Senha</h3>
                    </div>
                    <p class="forgot-description">Crie uma nova senha para a sua conta.</p>
                    <div class="input-group">
                        <input type="password" id="newPassword" placeholder="Nova senha (mín. 6 caracteres)" required>
                    </div>
                    <div class="input-group">
                        <input type="password" id="confirmNewPassword" placeholder="Confirmar nova senha" required>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="resetPassword()" id="btnResetPassword">Redefinir Senha</button>
                </div>

                <!-- Etapa 4: Sucesso -->
                <div id="forgotStep4" class="forgot-step">
                    <div class="success-icon">&#10003;</div>
                    <h3>Senha Redefinida!</h3>
                    <p class="forgot-description">Sua senha foi alterada com sucesso. Faça login com a nova senha.</p>
                    <button type="button" class="btn btn-primary" onclick="backToLogin()">Ir para Login</button>
                </div>

                <div id="forgotMessage" class="forgot-message" style="display:none;"></div>
            </div>
            
            <!-- Formulário de Cadastro -->
            <form id="registerForm" method="POST" class="auth-form <?php echo ($error_from === 'register') ? 'active' : ''; ?>">
                <div class="input-group username-group">
                    <span class="username-prefix">@</span>
                    <input type="text" name="reg_username" placeholder="Nome de usuário (Definitivo)" maxlength="14" pattern="[a-zA-Z0-9_\-]{3,14}" title="3 a 14 caracteres. Apenas letras, números, - e _" autocomplete="off" value="<?php echo htmlspecialchars($reg_username); ?>" required>
                    <small class="field-hint">3-14 caracteres (letras, números, - e _)</small>
                </div>
                <!-- WhatsApp -->
                <div class="input-group whatsapp-input-group">
                    <span class="whatsapp-prefix">🇦🇴 +244</span>
                    <input type="tel" name="reg_whatsapp" id="reg_whatsapp"
                           placeholder="9XX XXX XXX"
                           maxlength="13"
                           inputmode="numeric"
                           pattern="[0-9 ]{9,13}"
                           autocomplete="tel">
                    <button type="button" class="btn-send-code" id="btnSendWaCode" onclick="sendWhatsappCode()">
                        Enviar código
                    </button>
                </div>
                <small class="field-hint" style="margin-top:-8px;">Opcional se tiver e-mail. Obrigatório se não tiver.</small>

                <!-- Verificação do código WhatsApp -->
                <div id="waVerifyStep" style="display:none;">
                    <p class="wa-verify-label">✅ Código enviado! Insira os 6 dígitos recebidos no WhatsApp:</p>
                    <div class="code-inputs" id="waCodeInputs">
                        <input type="text" maxlength="1" class="code-digit wa-digit" data-index="0" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit wa-digit" data-index="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit wa-digit" data-index="2" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit wa-digit" data-index="3" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit wa-digit" data-index="4" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" maxlength="1" class="code-digit wa-digit" data-index="5" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    </div>
                    <button type="button" class="btn btn-secondary" id="btnVerifyWaCode" onclick="verifyWhatsappCode()">Verificar número</button>
                    <span id="waVerifiedBadge" style="display:none;color:#22c55e;font-weight:600;">✓ Número verificado</span>
                </div>
                <!-- Campo oculto que indica que o número foi verificado -->
                <input type="hidden" name="reg_whatsapp_verified" id="reg_whatsapp_verified" value="0">

                <div class="input-group">
                    <input type="text" name="reg_full_name" placeholder="Nome completo" value="<?php echo htmlspecialchars($reg_full_name); ?>" required>
                </div>
                <!-- [OCULTO] Campo e-mail — descomentar para reativar
                <div class="input-group">
                    <input type="email" name="reg_email" id="reg_email" placeholder="E-mail (opcional se tiver WhatsApp)" value="<?php echo htmlspecialchars($reg_email); ?>" autocomplete="email">
                </div>
                -->
                <!-- <div class="input-group">
                    <input type="text" name="reg_instituicao" placeholder="Instituição (opcional)" value="<?php echo htmlspecialchars($reg_instituicao); ?>">
                </div> -->
                <div class="input-group password-group">
                    <input type="password" name="reg_password" placeholder="Senha" required>
                    <button type="button" class="toggle-password" onclick="togglePassword(this)" tabindex="-1">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="eye-off-icon" style="display:none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <div class="input-group password-group">
                    <input type="password" name="reg_confirm_password" placeholder="Confirmar senha" required>
                    <button type="button" class="toggle-password" onclick="togglePassword(this)" tabindex="-1">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="eye-off-icon" style="display:none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <div id="passwordMatchError" class="inline-error" style="display:none;">Senhas não conferem.</div>
                <button type="submit" name="register" class="btn btn-primary">Criar Conta</button>
                <p class="terms">
                    Ao cadastrar-se, você concorda com nossos 
                    <a href="<?php echo SITE_URL; ?>/termos.php" target="_blank">Termos de Uso</a> e 
                    <a href="<?php echo SITE_URL; ?>/privacidade.php" target="_blank">Política de Privacidade</a>
                </p>

                <div class="auth-divider"><span>ou</span></div>

                <div id="googleBtnRegister" class="google-signin-btn"></div>
            </form>
            
            <div class="auth-footer">
                <a href="<?php echo SITE_URL; ?>/termos.php" target="_blank">Termos</a> &bull; 
                <a href="<?php echo SITE_URL; ?>/privacidade.php" target="_blank">Privacidade</a>
            </div>
        </div>
    </div>
    
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>
    <script>
    // ── Google Identity Services ────────────────────────────────────────────
    const GOOGLE_CLIENT_ID = document.querySelector('meta[name="google-client-id"]')?.content || '';

    function initGoogleAuth() {
        if (typeof google === 'undefined' || !GOOGLE_CLIENT_ID) return;
        
        google.accounts.id.initialize({
            client_id: GOOGLE_CLIENT_ID,
            callback: handleGoogleCredential,
            auto_select: false
        });

        // Renderizar o botão oficial do Google dentro das nossas divs
        const renderOptions = {
            theme: "outline",
            size: "large",
            width: 300, // Largura fixa garantida para evitar colapso em containers ocultos
            text: "continue_with",
            shape: "rectangular",
            logo_alignment: "left"
        };

        const loginBtn = document.getElementById("googleBtnLogin");
        if (loginBtn) google.accounts.id.renderButton(loginBtn, renderOptions);
        
        const registerBtn = document.getElementById("googleBtnRegister");
        if (registerBtn) google.accounts.id.renderButton(registerBtn, renderOptions);
    }

    if (typeof google !== 'undefined') {
        initGoogleAuth();
    } else {
        const gsiScript = document.querySelector('script[src="https://accounts.google.com/gsi/client"]');
        if (gsiScript) {
            gsiScript.addEventListener('load', initGoogleAuth);
        } else {
            window.addEventListener('load', initGoogleAuth);
        }
    }

    async function handleGoogleCredential(response) {
        const credential = response.credential;
        if (!credential) return;

        try {
            const formData = new FormData();
            formData.append('credential', credential);

            const res = await fetch('api/google_auth.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': getCsrfToken() }
            });

            const data = await res.json();

            if (data.success && data.redirect) {
                window.location.href = data.redirect;
            } else {
                alert(data.message || 'Erro ao entrar com Google.');
            }
        } catch (err) {
            console.error('[Google Auth]', err);
            alert('Erro de ligação. Tente novamente.');
        }
    }

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    // ── WhatsApp Verification ──────────────────────────────────────────────────

    async function sendWhatsappCode() {
        const phoneInput = document.getElementById('reg_whatsapp');
        const phone = phoneInput.value.replace(/\D/g, '').trim();

        if (phone.length < 9) {
            alert('Por favor, insira um número de WhatsApp válido (ex: 912 345 678).');
            phoneInput.focus();
            return;
        }

        const btn = document.getElementById('btnSendWaCode');
        btn.disabled = true;
        btn.textContent = 'A enviar...';

        try {
            const formData = new FormData();
            formData.append('phone', phone);

            const res = await fetch('api/send_whatsapp_verification.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': getCsrfToken() }
            });

            const data = await res.json();

            if (data.success) {
                document.getElementById('waVerifyStep').style.display = 'block';
                btn.textContent = 'Reenviar';
                btn.disabled = false;

                // Focar no primeiro input do código
                const firstDigit = document.querySelector('.wa-digit[data-index="0"]');
                if (firstDigit) firstDigit.focus();

                // Iniciar navegação automática entre dígitos
                setupWaDigitNavigation();

                // Contador de reenvio (60 segundos)
                let seconds = 60;
                btn.disabled = true;
                btn.textContent = `Reenviar (${seconds}s)`;
                const timer = setInterval(() => {
                    seconds--;
                    btn.textContent = `Reenviar (${seconds}s)`;
                    if (seconds <= 0) {
                        clearInterval(timer);
                        btn.disabled = false;
                        btn.textContent = 'Reenviar';
                    }
                }, 1000);
            } else {
                alert(data.message || 'Não foi possível enviar o código.');
                btn.disabled = false;
                btn.textContent = 'Enviar código';
            }
        } catch (err) {
            console.error('[WhatsApp]', err);
            alert('Erro de ligação. Tente novamente.');
            btn.disabled = false;
            btn.textContent = 'Enviar código';
        }
    }

    async function verifyWhatsappCode() {
        const digits = Array.from(document.querySelectorAll('.wa-digit'))
            .map(i => i.value).join('');

        if (digits.length !== 6 || !/^\d{6}$/.test(digits)) {
            alert('Por favor, insira os 6 dígitos do código.');
            return;
        }

        const phone = document.getElementById('reg_whatsapp').value.replace(/\D/g, '');
        const btn   = document.getElementById('btnVerifyWaCode');
        btn.disabled = true;
        btn.textContent = 'A verificar...';

        try {
            const formData = new FormData();
            formData.append('phone', phone);
            formData.append('code', digits);

            const res = await fetch('api/verify_whatsapp_code.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': getCsrfToken() }
            });

            const data = await res.json();

            if (data.success) {
                document.getElementById('reg_whatsapp_verified').value = '1';
                document.getElementById('btnVerifyWaCode').style.display = 'none';
                document.getElementById('waVerifiedBadge').style.display = 'inline';
                document.getElementById('btnSendWaCode').disabled = true;
                document.getElementById('reg_whatsapp').readOnly = true;
                // Desabilitar inputs do código
                document.querySelectorAll('.wa-digit').forEach(i => i.disabled = true);
            } else {
                alert(data.message || 'Código incorreto. Tente novamente.');
                btn.disabled = false;
                btn.textContent = 'Verificar número';
                // Limpar campos do código
                document.querySelectorAll('.wa-digit').forEach(i => i.value = '');
                document.querySelector('.wa-digit[data-index="0"]')?.focus();
            }
        } catch (err) {
            console.error('[WhatsApp verify]', err);
            alert('Erro de ligação. Tente novamente.');
            btn.disabled = false;
            btn.textContent = 'Verificar número';
        }
    }

    function setupWaDigitNavigation() {
        const inputs = document.querySelectorAll('.wa-digit');
        inputs.forEach((input, index) => {
            input.addEventListener('input', () => {
                if (input.value.length === 1 && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
                // Auto-verificar quando todos os dígitos estiverem preenchidos
                const allFilled = Array.from(inputs).every(i => i.value.length === 1);
                if (allFilled) verifyWhatsappCode();
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });
        });
    }
    </script>

    <style>
    /* ── Estilos do campo WhatsApp ─────────────────────────────────── */
    .whatsapp-input-group {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
    }
    .whatsapp-input-group input[type="tel"] {
        flex: 1;
        min-width: 0;
    }
    .whatsapp-prefix {
        font-size: 14px;
        font-weight: 600;
        white-space: nowrap;
        color: var(--text-secondary, #888);
        padding: 0 4px;
    }
    .btn-send-code {
        background: linear-gradient(135deg, #25d366, #128c7e);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        transition: opacity 0.2s;
    }
    .btn-send-code:hover:not(:disabled) { opacity: 0.85; }
    .btn-send-code:disabled { opacity: 0.5; cursor: not-allowed; }
    .wa-verify-label {
        font-size: 13px;
        color: var(--text-secondary, #888);
        margin: 8px 0 6px;
    }
    #waVerifyStep { margin-top: 8px; }
    #waVerifyStep .code-inputs { margin-bottom: 10px; }
    .btn-secondary {
        background: transparent;
        border: 1px solid var(--border, #444);
        color: var(--text-primary, #fff);
        border-radius: 8px;
        padding: 8px 16px;
        font-size: 13px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-secondary:hover:not(:disabled) { background: rgba(255,255,255,0.08); }
    .btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</body>
</html>