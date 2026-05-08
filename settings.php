<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Processar logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    // Validar CSRF token
    csrf_verify_or_die('Token de segurança inválido. Recarregue a página e tente novamente.');
    
    // Destruir sessão completamente
    session_unset();
    session_destroy();
    
    // Deletar cookie de sessão (todos os domínios)
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $cookieName = session_name();
        setcookie($cookieName, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        setcookie($cookieName, '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
        setcookie($cookieName, '', time() - 42000, '/', 'mytube.social', $params['secure'], $params['httponly']);
        setcookie($cookieName, '', time() - 42000, '/', 'www.mytube.social', $params['secure'], $params['httponly']);
        setcookie($cookieName, '', time() - 42000, '/', '.mytube.social', $params['secure'], $params['httponly']);
    }
    
    redirect('login.php');
}

// Buscar dados do usuário
$stmt = $pdo->prepare("SELECT username, full_name, profile_picture, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) {
    redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Definições - MyTube</title>
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #fff;
            margin: 0;
        }

        .settings-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(15, 23, 42, 0.95);
            -webkit-backdrop-filter: blur(20px);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 15px 0;
        }

        .header-content {
            max-width: 600px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 8px;
            transition: background 0.3s;
        }
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .header-content h1 {
            color: #fff;
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }

        .settings-main {
            padding-top: 80px;
            max-width: 600px;
            margin: 0 auto;
            padding-left: 20px;
            padding-right: 20px;
            padding-bottom: 60px;
        }

        /* User card */
        .settings-user-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 32px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .settings-user-card:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .settings-user-card img {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(59, 130, 246, 0.4);
        }
        .settings-user-info {
            flex: 1;
        }
        .settings-user-info h3 {
            margin: 0 0 2px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .settings-user-info p {
            margin: 0;
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .settings-user-card .fa-chevron-right {
            color: #475569;
        }

        /* Section */
        .settings-section {
            margin-bottom: 24px;
        }
        .settings-section-title {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            padding: 0 4px;
            margin-bottom: 8px;
        }

        .settings-group {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            overflow: hidden;
        }

        .settings-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            text-decoration: none;
            color: #fff;
        }
        .settings-item:last-child {
            border-bottom: none;
        }
        .settings-item:hover {
            background: rgba(255, 255, 255, 0.06);
        }

        .settings-item-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .settings-item-icon.blue   { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .settings-item-icon.green  { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .settings-item-icon.purple { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }
        .settings-item-icon.orange { background: rgba(249, 115, 22, 0.15); color: #f97316; }
        .settings-item-icon.red    { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        .settings-item-text {
            flex: 1;
        }
        .settings-item-text span {
            display: block;
            font-weight: 500;
            font-size: 0.95rem;
        }
        .settings-item-text small {
            color: #64748b;
            font-size: 0.8rem;
        }
        .settings-item .fa-chevron-right {
            color: #475569;
            font-size: 0.8rem;
        }

        /* Logout */
        .settings-item.logout-item {
            color: #ef4444;
        }
        .settings-item.logout-item:hover {
            background: rgba(239, 68, 68, 0.08);
        }

        /* Logout modal */
        .logout-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            -webkit-backdrop-filter: blur(4px);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .logout-overlay.active {
            display: flex;
        }
        .logout-box {
            background: #1e293b;
            border-radius: 16px;
            padding: 32px 28px 24px;
            max-width: 360px;
            width: 90%;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .logout-box i.fa-sign-out-alt {
            font-size: 2.5rem;
            color: #ef4444;
            margin-bottom: 16px;
        }
        .logout-box h3 {
            margin: 0 0 8px;
            font-size: 1.2rem;
        }
        .logout-box p {
            color: #94a3b8;
            margin: 0 0 24px;
            font-size: 0.9rem;
        }
        .logout-actions {
            display: flex;
            gap: 12px;
        }
        .logout-actions button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-cancel-logout {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .btn-cancel-logout:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        .btn-confirm-logout {
            background: #ef4444;
            color: #fff;
        }
        .btn-confirm-logout:hover {
            background: #dc2626;
        }

        .app-version {
            text-align: center;
            color: #475569;
            font-size: 0.8rem;
            margin-top: 40px;
        }

        /* PWA Install Banner */
        .pwa-install-banner {
            display: none;
            background: linear-gradient(135deg, rgba(59,130,246,0.15), rgba(139,92,246,0.15));
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        .pwa-install-banner::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 120px;
            height: 120px;
            background: radial-gradient(circle, rgba(99,102,241,0.2), transparent);
            border-radius: 50%;
        }
        .pwa-install-banner.visible {
            display: block;
        }
        .pwa-banner-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }
        .pwa-banner-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(99,102,241,0.4);
        }
        .pwa-banner-text h4 {
            margin: 0 0 3px;
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
        }
        .pwa-banner-text p {
            margin: 0;
            font-size: 0.82rem;
            color: #94a3b8;
        }
        .pwa-install-btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: opacity 0.2s, transform 0.2s;
            box-shadow: 0 4px 15px rgba(99,102,241,0.35);
        }
        .pwa-install-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .pwa-install-btn:active {
            transform: scale(0.98);
        }
        .pwa-installed-badge {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            color: #10b981;
            font-size: 0.9rem;
            font-weight: 600;
        }
        /* iOS instructions */
        .pwa-ios-tip {
            display: none;
            margin-top: 12px;
            padding: 12px 14px;
            background: rgba(255,255,255,0.04);
            border-radius: 10px;
            font-size: 0.82rem;
            color: #94a3b8;
            line-height: 1.6;
        }
        .pwa-ios-tip span {
            display: inline-block;
            background: rgba(255,255,255,0.1);
            border-radius: 6px;
            padding: 1px 6px;
            font-size: 1rem;
            vertical-align: middle;
        }

        /* iOS Guide Modal (bottom-sheet) */
        .ios-guide-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 3000;
            align-items: flex-end;
            justify-content: center;
        }
        .ios-guide-overlay.active {
            display: flex;
            animation: fadeInOverlay 0.25s ease;
        }
        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        .ios-guide-sheet {
            background: #1e293b;
            border-radius: 24px 24px 0 0;
            padding: 12px 24px 36px;
            width: 100%;
            max-width: 500px;
            border: 1px solid rgba(255,255,255,0.1);
            border-bottom: none;
            animation: slideUp 0.35s cubic-bezier(.32,1.2,.48,1);
        }
        @keyframes slideUp {
            from { transform: translateY(100%); }
            to   { transform: translateY(0); }
        }
        .ios-guide-handle {
            width: 40px;
            height: 4px;
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
            margin: 0 auto 20px;
        }
        .ios-guide-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .ios-guide-icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        .ios-guide-header h3 {
            margin: 0 0 6px;
            font-size: 1.2rem;
            font-weight: 700;
            color: #fff;
        }
        .ios-guide-header p {
            margin: 0;
            font-size: 0.85rem;
            color: #64748b;
        }
        .ios-guide-steps {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 24px;
        }
        .ios-step {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 16px;
            background: rgba(255,255,255,0.04);
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.07);
        }
        .ios-step-num {
            width: 30px;
            height: 30px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
            color: #fff;
            box-shadow: 0 2px 10px rgba(99,102,241,0.4);
        }
        .ios-step-content {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .ios-step-content strong {
            font-size: 0.9rem;
            color: #fff;
            font-weight: 600;
        }
        .ios-step-content span {
            font-size: 0.82rem;
            color: #94a3b8;
            line-height: 1.5;
        }
        .ios-option-pill {
            display: inline-flex !important;
            align-items: center;
            gap: 5px;
            background: rgba(59,130,246,0.12);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: 8px;
            padding: 3px 10px;
            color: #93c5fd !important;
            font-size: 0.82rem !important;
            font-weight: 600;
            margin-top: 3px;
        }
        .ios-guide-close {
            width: 100%;
            padding: 14px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 14px;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .ios-guide-close:hover {
            background: rgba(255,255,255,0.12);
        }

        /* Email change modal */
        .email-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            -webkit-backdrop-filter: blur(4px);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .email-overlay.active {
            display: flex;
        }
        .email-box {
            background: #1e293b;
            border-radius: 16px;
            padding: 28px 24px 24px;
            max-width: 400px;
            width: 90%;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .email-box h3 {
            margin: 0 0 4px;
            font-size: 1.15rem;
            text-align: center;
        }
        .email-box .email-subtitle {
            color: #94a3b8;
            font-size: 0.85rem;
            text-align: center;
            margin: 0 0 20px;
        }
        .email-field {
            margin-bottom: 14px;
        }
        .email-field label {
            display: block;
            font-size: 0.8rem;
            color: #94a3b8;
            margin-bottom: 6px;
            font-weight: 500;
        }
        .email-field input {
            width: 100%;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            color: #fff;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .email-field input:focus {
            border-color: rgba(16, 185, 129, 0.5);
        }
        .email-error {
            color: #ef4444;
            font-size: 0.82rem;
            margin: -6px 0 12px;
            display: none;
        }
        .email-success {
            color: #10b981;
            font-size: 0.82rem;
            margin: -6px 0 12px;
            display: none;
        }
        .email-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .email-actions button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-cancel-email {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .btn-cancel-email:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        .btn-save-email {
            background: #10b981;
            color: #fff;
        }
        .btn-save-email:hover {
            background: #059669;
        }
        .btn-save-email:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Password change modal */

        .password-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            -webkit-backdrop-filter: blur(4px);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .password-overlay.active {
            display: flex;
        }
        .password-box {
            background: #1e293b;
            border-radius: 16px;
            padding: 28px 24px 24px;
            max-width: 400px;
            width: 90%;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .password-box h3 {
            margin: 0 0 4px;
            font-size: 1.15rem;
            text-align: center;
        }
        .password-box .pwd-subtitle {
            color: #94a3b8;
            font-size: 0.85rem;
            text-align: center;
            margin: 0 0 20px;
        }
        .pwd-field {
            margin-bottom: 14px;
        }
        .pwd-field label {
            display: block;
            font-size: 0.8rem;
            color: #94a3b8;
            margin-bottom: 6px;
            font-weight: 500;
        }
        .pwd-input-wrap {
            position: relative;
        }
        .pwd-input-wrap input {
            width: 100%;
            padding: 12px 42px 12px 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            color: #fff;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .pwd-input-wrap input:focus {
            border-color: rgba(59, 130, 246, 0.5);
        }
        .pwd-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            font-size: 0.95rem;
            padding: 4px;
        }
        .pwd-toggle:hover {
            color: #94a3b8;
        }
        .pwd-error {
            color: #ef4444;
            font-size: 0.82rem;
            margin: -6px 0 12px;
            display: none;
        }
        .pwd-success {
            color: #10b981;
            font-size: 0.82rem;
            margin: -6px 0 12px;
            display: none;
        }
        .pwd-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .pwd-actions button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-cancel-pwd {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .btn-cancel-pwd:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        .btn-save-pwd {
            background: #3b82f6;
            color: #fff;
        }
        .btn-save-pwd:hover {
            background: #2563eb;
        }
        .btn-save-pwd:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body>
    <header class="settings-header">
        <div class="header-content">
            <button class="back-btn" onclick="window.location.href='profile.php'">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>Definições</h1>
        </div>
    </header>

    <main class="settings-main">
        <!-- User card -->
        <div class="settings-user-card" onclick="window.location.href='profile.php'">
            <img src="<?php echo htmlspecialchars(avatar_url($user['profile_picture'] ?? null)); ?>" alt="">
            <div class="settings-user-info">
                <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <p>@<?php echo htmlspecialchars($user['username']); ?></p>
            </div>
            <i class="fas fa-chevron-right"></i>
        </div>

        <!-- Conta -->
        <div class="settings-section">
            <div class="settings-section-title">Conta</div>
            <div class="settings-group">
                <a href="profile.php" class="settings-item">
                    <div class="settings-item-icon blue">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div class="settings-item-text">
                        <span>Editar Perfil</span>
                        <small>Nome, foto e biografia</small>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </a>
                <a href="upload.php" class="settings-item">
                    <div class="settings-item-icon purple">
                        <i class="fas fa-video"></i>
                    </div>
                    <div class="settings-item-text">
                        <span>Novo Vídeo</span>
                        <small>Publicar um vídeo</small>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </a>
                <div class="settings-item" onclick="showChangeEmail()">
                    <div class="settings-item-icon green">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="settings-item-text">
                        <span>Editar Email</span>
                        <small>Alterar o email da conta</small>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </div>
                <div class="settings-item" onclick="showChangePassword()">
                    <div class="settings-item-icon orange">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div class="settings-item-text">
                        <span>Alterar Senha</span>
                        <small>Mudar a senha da conta</small>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </div>
            </div>
        </div>

        <!-- Social -->
        <div class="settings-section">
            <div class="settings-section-title">Social</div>
            <div class="settings-group">
                <a href="chat.php" class="settings-item">
                    <div class="settings-item-icon green">
                        <i class="fas fa-comment"></i>
                    </div>
                    <div class="settings-item-text">
                        <span>Mensagens</span>
                        <small>Conversas e chat</small>
                    </div>
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>

        <!-- App (PWA Install) -->
        <div class="settings-section" id="pwaSection">
            <div class="settings-section-title">App</div>

            <!-- Banner principal (Android/Desktop: mostra botão de instalar) -->
            <div class="pwa-install-banner" id="pwaBanner">
                <div class="pwa-banner-header">
                    <div class="pwa-banner-icon">📱</div>
                    <div class="pwa-banner-text">
                        <h4>Instalar o MyTube</h4>
                        <p>Acede mais rápido, sem browser, como um app nativo</p>
                    </div>
                </div>
                <button class="pwa-install-btn" id="pwaInstallBtn" onclick="installPWA()">
                    <i class="fas fa-download"></i>
                    Instalar App
                </button>
                <!-- Botão fallback desktop (quando beforeinstallprompt não está disponível) -->
                <button class="pwa-install-btn pwa-desktop-guide-btn" id="pwaDesktopGuideBtn" style="display:none;" onclick="showDesktopGuide()">
                    <i class="fas fa-question-circle"></i>
                    Como instalar
                </button>
                <div class="pwa-installed-badge" id="pwaInstalledBadge">
                    <i class="fas fa-check-circle"></i>
                    App instalado com sucesso!
                </div>
                <!-- Botão para iOS -->
                <button class="pwa-install-btn" id="pwaIosBtn" style="display:none;" onclick="showIosGuide()">
                    <i class="fas fa-download"></i>
                    Instalar App
                </button>
            </div>
        </div>

        <!-- Sessão -->
        <div class="settings-section">
            <div class="settings-section-title">Sessão</div>
            <div class="settings-group">
                <div class="settings-item logout-item" onclick="showLogoutConfirm()">
                    <div class="settings-item-icon red">
                        <i class="fas fa-sign-out-alt"></i>
                    </div>
                    <div class="settings-item-text">
                        <span>Terminar Sessão</span>
                        <small>Sair da sua conta</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-version">MyTube v1.1.1</div>
    </main>

    <!-- Modal guia Desktop -->
    <div class="ios-guide-overlay" id="desktopGuideOverlay" onclick="if(event.target===this)hideDesktopGuide()">
        <div class="ios-guide-sheet" style="border-radius:20px; margin:auto; max-width:480px;">
            <div class="ios-guide-handle"></div>
            <div class="ios-guide-header">
                <div class="ios-guide-icon">🖥️</div>
                <h3>Instalar no computador</h3>
                <p>Siga os passos no teu browser</p>
            </div>
            <div class="ios-guide-steps">
                <div class="ios-step">
                    <div class="ios-step-num">1</div>
                    <div class="ios-step-content">
                        <strong>Olha para a barra de endereço</strong>
                        <span>No Chrome/Edge, procura o ícone
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            ou um ecrã com uma seta no canto direito da barra de endereço</span>
                    </div>
                </div>
                <div class="ios-step">
                    <div class="ios-step-num">2</div>
                    <div class="ios-step-content">
                        <strong>Clica nesse ícone</strong>
                        <span>Aparece um popup a perguntar se queres instalar o <strong style="color:#fff">MyTube</strong></span>
                    </div>
                </div>
                <div class="ios-step">
                    <div class="ios-step-num">3</div>
                    <div class="ios-step-content">
                        <strong>Clica em Instalar</strong>
                        <span>O app abre numa janela própria, sem barra do browser. Podes fazer pin na barra de tarefas!</span>
                    </div>
                </div>
                <div class="ios-step" style="border-color: rgba(99,102,241,0.2); background: rgba(99,102,241,0.06);">
                    <div class="ios-step-num" style="background: rgba(99,102,241,0.3); box-shadow:none; color:#a5b4fc;">ℹ</div>
                    <div class="ios-step-content">
                        <strong style="color:#a5b4fc;">Dica: Firefox</strong>
                        <span>No Firefox, usa o menu (⋮) → <span class="ios-option-pill">Instalar Site</span> ou usa Chrome/Edge para melhor experiência PWA</span>
                    </div>
                </div>
            </div>
            <button class="ios-guide-close" onclick="hideDesktopGuide()">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
    </div>

    <!-- Modal guia iOS -->
    <div class="ios-guide-overlay" id="iosGuideOverlay" onclick="if(event.target===this)hideIosGuide()">
        <div class="ios-guide-sheet">
            <div class="ios-guide-handle"></div>
            <div class="ios-guide-header">
                <div class="ios-guide-icon">📱</div>
                <h3>Adicionar à tela inicial</h3>
                <p>Siga os passos abaixo no Safari</p>
            </div>
            <div class="ios-guide-steps">
                <div class="ios-step">
                    <div class="ios-step-num">1</div>
                    <div class="ios-step-content">
                        <strong>Toca no botão Partilhar</strong>
                        <span>O ícone <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;color:#3b82f6"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> na barra inferior do Safari</span>
                    </div>
                </div>
                <div class="ios-step">
                    <div class="ios-step-num">2</div>
                    <div class="ios-step-content">
                        <strong>Desliza e toca em</strong>
                        <span class="ios-option-pill"><i class="fas fa-plus-square"></i> Adicionar ao ecrã principal</span>
                    </div>
                </div>
                <div class="ios-step">
                    <div class="ios-step-num">3</div>
                    <div class="ios-step-content">
                        <strong>Confirma</strong>
                        <span>Toca em <strong style="color:#3b82f6">Adicionar</strong> no canto superior direito</span>
                    </div>
                </div>
            </div>
            <button class="ios-guide-close" onclick="hideIosGuide()">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
    </div>

    <!-- Change email modal -->
    <div class="email-overlay" id="emailOverlay" onclick="if(event.target===this)hideChangeEmail()">
        <div class="email-box">
            <h3><i class="fas fa-envelope" style="color:#10b981;margin-right:8px"></i>Editar Email</h3>
            <p class="email-subtitle">Email atual: <strong><?php echo htmlspecialchars($user['email']); ?></strong></p>
            <form id="changeEmailForm" onsubmit="return submitChangeEmail(event)">
                <div class="email-field">
                    <label>Novo Email</label>
                    <input type="email" id="newEmail" placeholder="novo@exemplo.com" autocomplete="email" maxlength="255">
                </div>
                <div class="email-field">
                    <label>Confirmar Novo Email</label>
                    <input type="email" id="confirmEmail" placeholder="Repita o novo email" autocomplete="email" maxlength="255">
                </div>
                <div class="email-field">
                    <label>Senha Atual <small style="color:#64748b">(para confirmar)</small></label>
                    <div class="pwd-input-wrap">
                        <input type="password" id="emailPassword" placeholder="Senha da conta" autocomplete="current-password">
                        <button type="button" class="pwd-toggle" onclick="togglePwdVisibility(this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="email-error" id="emailError"></div>
                <div class="email-success" id="emailSuccess"></div>
                <div class="email-actions">
                    <button type="button" class="btn-cancel-email" onclick="hideChangeEmail()">Cancelar</button>
                    <button type="submit" class="btn-save-email" id="btnSaveEmail">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change password modal -->
    <div class="password-overlay" id="passwordOverlay" onclick="if(event.target===this)hideChangePassword()">
        <div class="password-box">
            <h3><i class="fas fa-lock" style="color:#f97316;margin-right:8px"></i>Alterar Senha</h3>
            <p class="pwd-subtitle">Insira sua senha atual e escolha uma nova</p>
            <form id="changePasswordForm" onsubmit="return submitChangePassword(event)">
                <div class="pwd-field">
                    <label>Senha Atual</label>
                    <div class="pwd-input-wrap">
                        <input type="password" id="currentPassword" placeholder="Senha atual" autocomplete="current-password">
                        <button type="button" class="pwd-toggle" onclick="togglePwdVisibility(this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="pwd-field">
                    <label>Nova Senha</label>
                    <div class="pwd-input-wrap">
                        <input type="password" id="newPassword" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
                        <button type="button" class="pwd-toggle" onclick="togglePwdVisibility(this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="pwd-field">
                    <label>Confirmar Nova Senha</label>
                    <div class="pwd-input-wrap">
                        <input type="password" id="confirmPassword" placeholder="Repita a nova senha" autocomplete="new-password">
                        <button type="button" class="pwd-toggle" onclick="togglePwdVisibility(this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="pwd-error" id="pwdError"></div>
                <div class="pwd-success" id="pwdSuccess"></div>
                <div class="pwd-actions">
                    <button type="button" class="btn-cancel-pwd" onclick="hideChangePassword()">Cancelar</button>
                    <button type="submit" class="btn-save-pwd" id="btnSavePwd">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout confirm modal -->
    <div class="logout-overlay" id="logoutOverlay" onclick="if(event.target===this)hideLogoutConfirm()">
        <div class="logout-box">
            <i class="fas fa-sign-out-alt"></i>
            <h3>Terminar Sessão</h3>
            <p>Tem certeza de que deseja sair da sua conta?</p>
            <div class="logout-actions">
                <button class="btn-cancel-logout" onclick="hideLogoutConfirm()">Cancelar</button>
                <form method="POST" style="flex:1;display:flex">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="logout" class="btn-confirm-logout" style="flex:1">Sair</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // ─── PWA Install Logic ────────────────────────────────────────────
        (function() {
            const banner           = document.getElementById('pwaBanner');
            const installBtn       = document.getElementById('pwaInstallBtn');
            const desktopGuideBtn  = document.getElementById('pwaDesktopGuideBtn');
            const iosBtn           = document.getElementById('pwaIosBtn');
            const installedBadge   = document.getElementById('pwaInstalledBadge');

            const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
            // Detecção robusta: userAgent OU (tela touch + tela pequena) — funciona mesmo com "Solicitar site desktop"
            const isMobile = /android|iphone|ipad|ipod/i.test(navigator.userAgent)
                || ('ontouchstart' in window && screen.width <= 1024);
            const isInStandaloneMode = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            function showBanner() { banner.classList.add('visible'); }

            if (isInStandaloneMode) {
                // Já instalado — badge verde
                showBanner();
                installBtn.style.display = 'none';
                desktopGuideBtn.style.display = 'none';
                iosBtn.style.display = 'none';
                installedBadge.style.display = 'flex';

            } else if (isIos) {
                // iPhone/iPad — botão que abre modal com passos
                showBanner();
                installBtn.style.display = 'none';
                desktopGuideBtn.style.display = 'none';
                iosBtn.style.display = 'flex';

            } else {
                // Android / Desktop Chrome / Edge
                showBanner(); // mostrar sempre o banner no desktop

                if (window._pwaInstallPrompt) {
                    // prompt já capturado — botão direto
                    installBtn.style.display = 'flex';
                    desktopGuideBtn.style.display = 'none';
                } else {
                    // prompt ainda não disparou — escutar
                    installBtn.style.display = 'none';
                    desktopGuideBtn.style.display = 'flex'; // fallback: mostrar guia

                    window.addEventListener('beforeinstallprompt', function(e) {
                        // quando chegar, trocar para botão de instalar
                        installBtn.style.display = 'flex';
                        desktopGuideBtn.style.display = 'none';
                    });
                }

                window.addEventListener('appinstalled', function() {
                    installBtn.style.display = 'none';
                    desktopGuideBtn.style.display = 'none';
                    installedBadge.style.display = 'flex';
                });
            }
        })();

        function showDesktopGuide() {
            document.getElementById('desktopGuideOverlay').classList.add('active');
        }
        function hideDesktopGuide() {
            document.getElementById('desktopGuideOverlay').classList.remove('active');
        }
        function showIosGuide() {
            document.getElementById('iosGuideOverlay').classList.add('active');
        }
        function hideIosGuide() {
            document.getElementById('iosGuideOverlay').classList.remove('active');
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { hideDesktopGuide(); hideIosGuide(); }
        });
    </script>

    <script>
        function showLogoutConfirm() {
            document.getElementById('logoutOverlay').classList.add('active');
        }
        function hideLogoutConfirm() {
            document.getElementById('logoutOverlay').classList.remove('active');
        }

        // Change password
        function showChangePassword() {
            document.getElementById('passwordOverlay').classList.add('active');
            document.getElementById('currentPassword').focus();
        }
        function hideChangePassword() {
            document.getElementById('passwordOverlay').classList.remove('active');
            document.getElementById('changePasswordForm').reset();
            document.getElementById('pwdError').style.display = 'none';
            document.getElementById('pwdSuccess').style.display = 'none';
            document.getElementById('btnSavePwd').disabled = false;
            document.getElementById('btnSavePwd').textContent = 'Salvar';
        }

        function togglePwdVisibility(btn) {
            const input = btn.parentElement.querySelector('input');
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function submitChangePassword(e) {
            e.preventDefault();
            const errorEl = document.getElementById('pwdError');
            const successEl = document.getElementById('pwdSuccess');
            const btn = document.getElementById('btnSavePwd');
            const current = document.getElementById('currentPassword').value;
            const newPwd = document.getElementById('newPassword').value;
            const confirm = document.getElementById('confirmPassword').value;

            errorEl.style.display = 'none';
            successEl.style.display = 'none';

            // Validação local
            if (!current || !newPwd || !confirm) {
                errorEl.textContent = 'Preencha todos os campos.';
                errorEl.style.display = 'block';
                return false;
            }
            if (newPwd.length < 6) {
                errorEl.textContent = 'A nova senha deve ter pelo menos 6 caracteres.';
                errorEl.style.display = 'block';
                return false;
            }
            if (newPwd !== confirm) {
                errorEl.textContent = 'As senhas não coincidem.';
                errorEl.style.display = 'block';
                return false;
            }

            btn.disabled = true;
            btn.textContent = 'Salvando...';

            const formData = new FormData();
            formData.append('current_password', current);
            formData.append('new_password', newPwd);
            formData.append('confirm_password', confirm);

            fetch('api/change_password.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    successEl.textContent = data.message;
                    successEl.style.display = 'block';
                    document.getElementById('changePasswordForm').reset();
                    setTimeout(() => hideChangePassword(), 1500);
                } else {
                    errorEl.textContent = data.message;
                    errorEl.style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Salvar';
                }
            })
            .catch(() => {
                errorEl.textContent = 'Erro de conexão. Tente novamente.';
                errorEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Salvar';
            });

            return false;
        }

        // Change email
        function showChangeEmail() {
            document.getElementById('emailOverlay').classList.add('active');
            document.getElementById('newEmail').focus();
        }
        function hideChangeEmail() {
            document.getElementById('emailOverlay').classList.remove('active');
            document.getElementById('changeEmailForm').reset();
            document.getElementById('emailError').style.display = 'none';
            document.getElementById('emailSuccess').style.display = 'none';
            document.getElementById('btnSaveEmail').disabled = false;
            document.getElementById('btnSaveEmail').textContent = 'Salvar';
        }

        function submitChangeEmail(e) {
            e.preventDefault();
            const errorEl   = document.getElementById('emailError');
            const successEl = document.getElementById('emailSuccess');
            const btn       = document.getElementById('btnSaveEmail');
            const newEmail  = document.getElementById('newEmail').value.trim();
            const confirmEmail = document.getElementById('confirmEmail').value.trim();
            const password  = document.getElementById('emailPassword').value;

            errorEl.style.display   = 'none';
            successEl.style.display = 'none';

            // Validação local
            if (!newEmail || !confirmEmail || !password) {
                errorEl.textContent = 'Preencha todos os campos.';
                errorEl.style.display = 'block';
                return false;
            }

            // Formato básico de email (regex simples)
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(newEmail)) {
                errorEl.textContent = 'Formato de email inválido.';
                errorEl.style.display = 'block';
                return false;
            }

            if (newEmail !== confirmEmail) {
                errorEl.textContent = 'Os emails não coincidem.';
                errorEl.style.display = 'block';
                return false;
            }

            btn.disabled = true;
            btn.textContent = 'Salvando...';

            const formData = new FormData();
            formData.append('new_email', newEmail);
            formData.append('password', password);

            fetch('api/change_email.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    successEl.textContent = data.message;
                    successEl.style.display = 'block';
                    document.getElementById('changeEmailForm').reset();
                    setTimeout(() => hideChangeEmail(), 1500);
                } else {
                    errorEl.textContent = data.message;
                    errorEl.style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Salvar';
                }
            })
            .catch(() => {
                errorEl.textContent = 'Erro de conexão. Tente novamente.';
                errorEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Salvar';
            });

            return false;
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                hideLogoutConfirm();
                hideChangePassword();
                hideChangeEmail();
            }
        });
    </script>
    <?php include 'includes/presence_bootstrap.php'; ?>
</body>
</html>
