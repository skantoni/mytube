<?php require_once __DIR__ . '/includes/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidade — MyTube</title>
    <meta name="description" content="Política de Privacidade e Proteção de Dados da plataforma MyTube. Saiba como recolhemos, usamos e protegemos os seus dados, incluindo o uso de cookies e publicidade.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/privacidade.php">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <meta name="google-adsense-account" content="ca-pub-7296999127636132">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --blue:    #1e40af;
            --blue-lt: #3b82f6;
            --ink:     #111827;
            --body:    #374151;
            --muted:   #6b7280;
            --rule:    #e5e7eb;
            --bg:      #f9fafb;
            --white:   #ffffff;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--body);
            line-height: 1.75;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Topbar ── */
        .topbar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255,255,255,.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--rule);
            padding: 0 24px;
        }
        .topbar-inner {
            max-width: 900px; margin: 0 auto;
            display: flex; align-items: center; justify-content: space-between;
            height: 56px;
        }
        .nav-logo {
            display: flex; align-items: center; gap: 9px;
            text-decoration: none; color: var(--ink);
        }
        .nav-logo-icon {
            width: 32px; height: 32px;
            object-fit: contain;
        }
        .nav-logo-name { font-size: 16px; font-weight: 800; letter-spacing: -.3px; }
        .nav-links { display: flex; gap: 28px; }
        .nav-links a {
            font-size: 13.5px; font-weight: 500; color: var(--muted);
            text-decoration: none; transition: color .15s;
        }
        .nav-links a:hover, .nav-links a.active { color: var(--ink); }
        .nav-btn {
            font-size: 13px; font-weight: 600;
            color: var(--white); background: var(--blue);
            padding: 7px 18px; border-radius: 8px;
            text-decoration: none; transition: background .15s;
        }
        .nav-btn:hover { background: #1e40af; }

        /* ── Hero ── */
        .hero {
            max-width: 900px; margin: 0 auto;
            padding: 80px 24px 64px;
            border-bottom: 1px solid var(--rule);
        }
        .hero-kicker {
            display: inline-block;
            font-size: 12px; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: var(--blue);
            margin-bottom: 20px;
        }
        .hero h1 {
            font-size: clamp(34px, 6vw, 48px);
            font-weight: 800; color: var(--ink);
            line-height: 1.1; letter-spacing: -1.5px;
            max-width: 660px;
        }
        .hero-lead {
            margin-top: 24px; max-width: 540px;
            font-size: 17px; color: var(--muted); line-height: 1.7;
        }
        .last-updated {
            display: inline-block;
            margin-top: 24px;
            font-size: 13px; font-weight: 500;
            color: var(--muted); background: var(--white);
            padding: 6px 14px; border-radius: 20px;
            border: 1px solid var(--rule);
        }

        /* ── Conteúdo ── */
        .page-body {
            max-width: 900px; margin: 0 auto;
            padding: 0 24px 80px;
        }

        .text-section {
            padding: 60px 0;
            border-bottom: 1px solid var(--rule);
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            align-items: start;
        }
        .text-section:last-of-type {
            border-bottom: none;
        }
        
        .section-content h2 {
            font-size: clamp(22px, 3.5vw, 28px); font-weight: 800;
            color: var(--ink); line-height: 1.2; letter-spacing: -.5px;
            margin-bottom: 20px;
        }
        .section-content h3 {
            font-size: 18px; font-weight: 700;
            color: var(--ink); margin-top: 32px; margin-bottom: 12px;
        }
        .section-content p {
            font-size: 16px; color: var(--body); line-height: 1.8;
            margin-bottom: 16px;
        }
        .section-content p:last-child { margin-bottom: 0; }
        .section-content strong { color: var(--ink); font-weight: 600; }
        .section-content a { color: var(--blue); text-decoration: underline; font-weight: 500; }

        .clean-list {
            list-style: none; margin-top: 20px; margin-bottom: 20px;
        }
        .clean-list li {
            padding: 14px 0;
            border-top: 1px solid var(--rule);
            display: flex; gap: 16px; align-items: flex-start;
            font-size: 15px; color: var(--body);
        }
        .clean-list li:last-child { border-bottom: 1px solid var(--rule); }
        .list-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--blue); margin-top: 9px; flex-shrink: 0;
        }
        .list-title { font-weight: 600; color: var(--ink); display: block; margin-bottom: 2px; }

        /* ── Callout / Highlight ── */
        .highlight {
            background: rgba(59, 130, 246, 0.05);
            border-left: 3px solid var(--blue-lt);
            padding: 20px 24px;
            margin: 24px 0;
            border-radius: 0 8px 8px 0;
        }
        .highlight p { margin: 0; font-size: 15px; color: #1e3a8a; }

        /* ── Footer ── */
        .site-footer {
            border-top: 1px solid var(--rule);
            padding: 32px 24px;
            max-width: 900px; margin: 0 auto;
        }
        .footer-inner {
            display: flex; flex-wrap: wrap;
            align-items: center; justify-content: space-between; gap: 16px;
        }
        .footer-copy { font-size: 13px; color: var(--muted); }
        .footer-nav { display: flex; flex-wrap: wrap; gap: 6px 20px; }
        .footer-nav a { font-size: 13px; color: var(--muted); text-decoration: none; }
        .footer-nav a:hover { color: var(--ink); }
    </style>
</head>
<body>

<!-- Navegação -->
<nav class="topbar">
    <div class="topbar-inner">
        <a href="index.php" class="nav-logo">
            <img src="assets/images/logo_icon.png" alt="MyTube Logo" class="nav-logo-icon">
            <span class="nav-logo-name">MyTube</span>
        </a>
        <div class="nav-links">
            <a href="index.php">Início</a>
            <a href="sobre.php">Sobre</a>
            <a href="contacto.php">Contacto</a>
        </div>
        <a href="login.php?register=1" class="nav-btn">Criar conta</a>
    </div>
</nav>

<!-- Hero -->
<header class="hero">
    <span class="hero-kicker">Legal e Transparência</span>
    <h1>Política de Privacidade</h1>
    <p class="hero-lead">
        Sabe exactamente que informações recolhemos sobre ti, porque o fazemos, e como podes controlar os teus dados na plataforma.
    </p>
    <div class="last-updated">Última atualização: Julho de 2026</div>
</header>

<!-- Corpo -->
<main class="page-body">

    <div class="text-section">
        <div class="section-content">
            <h2>1. Os dados que recolhemos</h2>
            <p>
                Quando usas o MyTube (em <a href="https://mytube.social">mytube.social</a>), precisamos de algumas informações para que a plataforma funcione correctamente:
            </p>
            <ul class="clean-list">
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Informações de Registo</span>
                        O teu nome, email e nome de utilizador. Se usares o login do Google, recebemos apenas o teu email e foto de perfil.
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">O teu conteúdo</span>
                        Os vídeos que publicas, os comentários que fazes e as tuas interações (gostos, quem segues).
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Dados Técnicos</span>
                        Como endereço IP, tipo de dispositivo e navegador. Estes dados são usados anonimamente para garantir a segurança da tua conta e detectar spam.
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <div class="text-section">
        <div class="section-content">
            <h2>2. Cookies e Rastreamento</h2>
            <p>
                Usamos cookies — pequenos ficheiros de texto guardados no teu dispositivo — para funções essenciais e analíticas:
            </p>
            <ul class="clean-list">
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Essenciais</span>
                        Para te manteres com sessão iniciada e proteger a tua conta.
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Publicidade e Analítica</span>
                        Para ajudar parceiros externos a exibir anúncios relevantes e medir o desempenho do site.
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <div class="text-section">
        <div class="section-content">
            <h2>3. Publicidade (Google AdSense)</h2>
            <p>
                O MyTube é 100% gratuito. Para cobrir os custos de servidores e desenvolvimento, usamos o <strong>Google AdSense</strong> para exibir anúncios publicitários.
            </p>
            <div class="highlight">
                <p>
                    <strong>Como funciona:</strong> O Google e os seus parceiros usam cookies para apresentar anúncios com base nas tuas visitas anteriores ao MyTube e a outros websites (publicidade personalizada).
                </p>
            </div>
            <p>
                Nós não partilhamos o teu nome, email ou dados de registo diretamente com os anunciantes. O Google gere os cookies de acordo com a sua <a href="https://policies.google.com/privacy" target="_blank">Política de Privacidade</a>.
            </p>
            <h3>Como fazer opt-out de anúncios personalizados</h3>
            <p>
                Se não quiseres ver anúncios baseados nos teus interesses, podes desativar essa função diretamente no Google visitando as <a href="https://www.google.com/settings/ads" target="_blank">Definições de Anúncios da Google</a>. Podes também visitar o site <a href="https://optout.aboutads.info/" target="_blank">AboutAds</a> para recusar cookies de terceiros.
            </p>
        </div>
    </div>

    <div class="text-section">
        <div class="section-content">
            <h2>4. Segurança e Eliminação de Dados</h2>
            <p>
                Os teus dados são armazenados de forma segura e não são vendidos a terceiros. Protegemos as tuas palavras-passe com criptografia avançada.
            </p>
            <p>
                Tu controlas a tua presença no MyTube. Podes alterar os teus dados no teu perfil a qualquer momento ou solicitar a eliminação completa e permanente da tua conta enviando um pedido para a nossa equipa de suporte.
            </p>
        </div>
    </div>

    <div class="text-section">
        <div class="section-content">
            <h2>5. Contacto</h2>
            <p>
                Se tiveres dúvidas sobre como tratamos os teus dados ou quiseres exercer os teus direitos de privacidade, estamos totalmente disponíveis:
            </p>
            <p>
                Email: <a href="mailto:mytubeao@gmail.com">mytubeao@gmail.com</a><br>
                Ou através da nossa <a href="contacto.php">página de Contacto</a>.
            </p>
        </div>
    </div>

</main>

<!-- Footer -->
<footer class="site-footer" style="max-width:100%;border-top:1px solid var(--rule);">
    <div class="footer-inner" style="max-width:900px;margin:0 auto;">
        <span class="footer-copy">&copy; <?php echo date('Y'); ?> MyTube &mdash; Angola</span>
        <nav class="footer-nav">
            <a href="index.php">Início</a>
            <a href="sobre.php">Sobre</a>
            <a href="contacto.php">Contacto</a>
            <a href="termos.php">Termos</a>
            <a href="privacidade.php" class="active">Privacidade</a>
        </nav>
    </div>
</footer>

<?php require_once 'includes/cookie_banner.php'; ?>
</body>
</html>
