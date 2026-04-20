<?php
require_once 'includes/config.php';

// Se já estiver logado, redirecionar para home
if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';
$success = '';
$error_from = ''; // 'login' ou 'register'

// Mensagem de sucesso após registo (Post/Redirect/Get)
if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $success = 'Conta criada com sucesso! Faça login.';
}

// Preservar dados do formulário de cadastro
$reg_username = '';
$reg_email = '';
$reg_full_name = '';
$reg_instituicao = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validar CSRF token
    csrf_verify_or_die('Token de segurança inválido. Recarregue a página e tente novamente.');
    
    if (isset($_POST['login'])) {
        // LOGIN
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        if (empty($username) || empty($password)) {
            $error = 'Por favor, preencha todos os campos.';
        } else {
            // ✅ PROTEÇÃO RATE LIMITING (previne brute force)
            require_once 'includes/rate_limit.php';
            $client_ip = rate_limit_get_client_ip();
            
            // Verificar rate limit por IP (5 tentativas em 15 minutos)
            $rate_limit_ip = rate_limit_check($pdo, 'login', $client_ip, 5, 15);
            
            // Verificar rate limit por usuário (3 tentativas em 15 minutos)
            $rate_limit_user = rate_limit_check($pdo, 'login_user', strtolower($username), 3, 15);
            
            if ($rate_limit_ip['blocked']) {
                $time_remaining = rate_limit_format_time_remaining($rate_limit_ip['reset_at']);
                $error = "Muitas tentativas de login. Tente novamente em $time_remaining.";
            } elseif ($rate_limit_user['blocked']) {
                $time_remaining = rate_limit_format_time_remaining($rate_limit_user['reset_at']);
                $error = "Muitas tentativas para este usuário. Tente novamente em $time_remaining.";
            } else {
                // Processar login normalmente
                $stmt = $pdo->prepare("SELECT * FROM users WHERE BINARY username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $users = $stmt->fetchAll();
                
                $user = null;
                foreach ($users as $u) {
                    if (password_verify($password, $u['password'])) {
                        $user = $u;
                        break;
                    }
                }
                
                if ($user) {
                    // ✅ LOGIN BEM-SUCEDIDO - Limpar rate limit
                    rate_limit_record($pdo, 'login', $client_ip, true);
                    rate_limit_record($pdo, 'login_user', strtolower($username), true);
                    
                    // Resetar status online stale no banco (pode estar preso de crash anterior)
                    try {
                        $stmt2 = $pdo->prepare("
                            UPDATE user_online_status 
                            SET is_online = 0, last_seen = NOW() 
                            WHERE user_id = ?
                        ");
                        $stmt2->execute([$user['id']]);
                    } catch (Exception $e) {
                        // Silenciar — login deve sempre funcionar
                    }
                    
                    // Limpar sessão antiga e recarregar dados atualizados
                    session_regenerate_id(true);
                    $_SESSION = [];
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['profile_picture'] = $user['profile_picture'];
                    
                    // Usar JavaScript para redirecionamento mais confiável
                    echo "<script>window.location.href = 'index.php?splash=1';</script>";
                    exit();
                } else {
                    // ❌ LOGIN FALHADO - Registrar tentativa
                    rate_limit_record($pdo, 'login', $client_ip, false);
                    rate_limit_record($pdo, 'login_user', strtolower($username), false);
                    
                    // Verificar rate limit atualizado APÓS registrar tentativa
                    $rate_limit_ip_updated = rate_limit_check($pdo, 'login', $client_ip, 5, 15);
                    $rate_limit_user_updated = rate_limit_check($pdo, 'login_user', strtolower($username), 3, 15);
                    
                    $remaining = min($rate_limit_ip_updated['remaining'], $rate_limit_user_updated['remaining']);
                    
                    if ($remaining > 0) {
                        $error = "Usuário ou senha incorretos. ($remaining tentativas restantes)";
                    } else {
                        $error = 'Usuário ou senha incorretos. Você será bloqueado na próxima tentativa.';
                    }
                }
            }
        }
    } 
    
    
    elseif (isset($_POST['register'])) {
        // CADASTRO
        $username = trim($_POST['reg_username']);
        $email = trim($_POST['reg_email']);
        $full_name = trim($_POST['reg_full_name']);
        $instituicao = trim($_POST['reg_instituicao'] ?? '');
        $password = $_POST['reg_password'];
        $confirm_password = $_POST['reg_confirm_password'];
        
        // Preservar dados para reexibir no formulário
        $reg_username = $username;
        $reg_email = $email;
        $reg_full_name = $full_name;
        $reg_instituicao = $instituicao;
        $error_from = 'register';
        
        $email_code = trim($_POST['email_code'] ?? '');

        // Validações
        if (empty($username) || empty($email) || empty($full_name) || empty($password)) {
            $error = 'Por favor, preencha todos os campos.';
        } elseif (strlen($username) < 3 || strlen($username) > 12) {
            $error = 'Nome de usuário deve ter entre 3 e 12 caracteres.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            $error = 'Nome de usuário pode conter apenas letras, números, - e _';
        /* EMAIL_VALIDATION_DISABLED - reativar quando Angola tiver mais uso de email
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@.+\..+/', $email)) {
            $error = 'E-mail inválido. Verifique se contém @ e um domínio válido.';
        */
        } elseif (strlen($password) < 6) {
            $error = 'Senha deve ter pelo menos 6 caracteres.';
        } elseif ($password !== $confirm_password) {
            $error = 'Senhas não conferem.';
        /* EMAIL_CODE_REQUIRED_DISABLED
        } elseif (empty($email_code) || !preg_match('/^\d{6}$/', $email_code)) {
            $error = 'Por favor, verifique o seu e-mail com o código de 6 dígitos enviado.';
        */
        } else {
            /* EMAIL_CODE_VERIFY_DISABLED
            // Verificar código de e-mail
            $stmt_code = null;
            try {
                $stmt_code = $pdo->prepare("SELECT id FROM email_verifications WHERE email = ? AND code = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
                $stmt_code->execute([$email, $email_code]);
                $valid_code = $stmt_code->fetch();
            } catch (Exception $tableErr) {
                $valid_code = false;
            }
            if (!$valid_code) {
                $error = 'Código de verificação inválido ou expirado. Solicite um novo código.';
            } else {
            */ {
                // Verificar se usuário já existe
                $stmt_user = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt_user->execute([$username]);
                
                if ($stmt_user->fetch()) {
                    $error = 'Nome de usuário já existe.';
                } else {
                    // Verificar limite de contas por email (máx 3)
                    $stmt_email = $pdo->prepare("SELECT COUNT(id) FROM users WHERE email = ?");
                    $stmt_email->execute([$email]);
                    $email_count = (int)$stmt_email->fetchColumn();
                    
                    if ($email_count >= 3) {
                        $error = 'Este e-mail já atingiu o limite de 3 contas.';
                    } else {
                        /* MARK_CODE_USED_DISABLED
                        $stmt_use = $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?");
                        $stmt_use->execute([$valid_code['id']]);
                        */

                        // Criar usuário
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, full_name, password, instituicao) VALUES (?, ?, ?, ?, ?)");
                        
                        try {
                            if ($stmt->execute([$username, $email, $full_name, $hashed_password, $instituicao])) {
                                header('Location: login.php?registered=1');
                                exit;
                            } else {
                                $error = 'Erro ao criar conta. Tente novamente.';
                            }
                        } catch (PDOException $e) {
                            if ($e->getCode() == 23000) {
                                $error = 'Este e-mail já está registado.';
                            } else {
                                $error = 'Erro ao criar conta. Tente novamente.';
                            }
                        }
                    }
                }
            /* } */ }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyTube - Sua rede social de vídeos</title>
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
    <meta name="description" content="MyTube - Aqui os criadores competem! Rede social angolana de vídeos com competições escolares. Partilhe vídeos, descubra talentos e conecte-se com criadores de Angola!">
    <meta name="keywords" content="mytube, rede social angola, vídeos angola, criadores angolanos, competições escolares, talentos, social media angola">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/login.php">

    <!-- Open Graph (Facebook, WhatsApp, etc.) -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.mytube.social/login.php">
    <meta property="og:title" content="MyTube - Aqui os criadores competem | Angola">
    <meta property="og:description" content="Rede social angolana onde criadores competem! Participe de competições escolares, partilhe vídeos e descubra talentos incríveis de Angola!">
    <meta property="og:image" content="https://www.mytube.social/assets/images/og-image.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="pt_AO">
    <meta property="og:site_name" content="MyTube">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="MyTube - Aqui os criadores competem | Angola">
    <meta name="twitter:description" content="Rede social angolana com competições escolares. Partilhe vídeos e descubra talentos de Angola!">
    <meta name="twitter:image" content="https://www.mytube.social/assets/images/og-image.jpg">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "MyTube",
      "url": "https://www.mytube.social",
      "description": "Rede social angolana de vídeos onde criadores competem e talentos são descobertos",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "https://www.mytube.social/index.php?search={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    }
    </script>

    <link rel="stylesheet" href="<?php echo asset('assets/css/auth.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-card">
            <div class="logo">
                <h1>MyTube</h1>
                <p>Aquí os criadores competem</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="auth-tabs">
                <button class="tab-btn <?php echo ($error_from !== 'register') ? 'active' : ''; ?>" onclick="showLogin()">Entrar</button>
                <button class="tab-btn <?php echo ($error_from === 'register') ? 'active' : ''; ?>" onclick="showRegister()">Cadastrar</button>
            </div>
            
            <!-- Formulário de Login -->
            <form id="loginForm" method="POST" class="auth-form <?php echo ($error_from !== 'register') ? 'active' : ''; ?>">
                <div class="input-group">
                    <input type="text" name="username" placeholder="Nome de usuário ou e-mail" required>
                </div>
                <div class="input-group password-group">
                    <input type="password" name="password" placeholder="Senha" required>
                    <button type="button" class="toggle-password" onclick="togglePassword(this)" tabindex="-1">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="eye-off-icon" style="display:none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
                <?php echo csrf_field(); ?>
                <button type="submit" name="login" class="btn btn-primary">Entrar</button>
                <p class="forgot-password">
                    <a href="#" onclick="showForgotPassword(); return false;">Esqueceu a senha?</a>
                </p>
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
                    <input type="text" name="reg_username" placeholder="Nome de usuário" maxlength="12" pattern="[a-zA-Z0-9_\-]{3,12}" title="3 a 12 caracteres. Apenas letras, números, - e _" autocomplete="off" value="<?php echo htmlspecialchars($reg_username); ?>" required>
                    <small class="field-hint">3-12 caracteres (letras, números, - e _)</small>
                </div>
                <!-- EMAIL_VERIFY_WIDGET_DISABLED: reativar quando necessário
                <div class="input-group" id="emailVerifyGroup">
                    <div class="email-input-wrap">
                        <input type="email" name="reg_email" id="regEmailInput" placeholder="E-mail" value="" required autocomplete="email">
                        <button type="button" id="btnSendEmailCode" class="btn-send-code" onclick="sendRegEmailCode()">Enviar código</button>
                    </div>
                    <div id="emailCodeRow" class="email-code-row" style="display:none;">
                        <input type="text" id="regEmailCodeInput" placeholder="Código de 6 dígitos" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                        <button type="button" id="btnResendEmailCode" class="btn-resend-code" onclick="resendRegEmailCode()" style="display:none;">Reenviar</button>
                    </div>
                    <div id="emailVerifyMsg" class="email-verify-msg" style="display:none;"></div>
                    <span id="emailVerifiedBadge" class="email-verified-badge" style="display:none;">&#10003; E-mail verificado</span>
                </div>
                <input type="hidden" name="email_code" id="emailCodeHidden">
                -->
                <div class="input-group">
                    <input type="text" name="reg_email" placeholder="E-mail (Recomenda-se um email valido)" value="<?php echo htmlspecialchars($reg_email); ?>" autocomplete="email">
                </div>
                <div class="input-group">
                    <input type="text" name="reg_full_name" placeholder="Nome completo" value="<?php echo htmlspecialchars($reg_full_name); ?>" required>
                </div>
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
                <?php echo csrf_field(); ?>
                <button type="submit" name="register" class="btn btn-primary">Criar Conta</button>
                <p class="terms">
                    Ao cadastrar-se, você concorda com nossos 
                    <a href="termos.php" target="_blank">Termos de Uso</a>
                </p>
            </form>
        </div>
    </div>
    
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>
</body>
</html>