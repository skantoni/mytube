<?php require_once __DIR__ . '/includes/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termos e Condições — MyTube</title>
    <meta name="description" content="Termos e Condições de Utilização da plataforma MyTube.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/termos.php">
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

        .callout {
            background: var(--ink); color: var(--bg);
            border-radius: 16px; padding: 40px;
            margin: 60px 0 0;
        }
        .callout p { font-size: 15px; line-height: 1.8; color: rgba(255,255,255,.7); }
        .callout strong { color: #fff; }
        .callout a.callout-link {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 24px; font-size: 14px; font-weight: 600;
            color: #fff; text-decoration: none;
            border: 1px solid rgba(255,255,255,.3); padding: 10px 22px;
            border-radius: 8px; transition: background .15s;
        }
        .callout a.callout-link:hover { background: rgba(255,255,255,.1); }

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
    <span class="hero-kicker">Legal</span>
    <h1>Termos e Condições</h1>
    <p class="hero-lead">
        Regras de utilização da plataforma. Ao aceder e utilizar o MyTube, assumes o compromisso de respeitar estes termos.
    </p>
    <div class="last-updated">Última atualização: Julho de 2026</div>
</header>

<!-- Corpo -->
<main class="page-body">

    <div class="text-section">
        <div class="section-content">
            <h2>1. Aceitação e Conduta</h2>
            <p>
                O <strong>MyTube</strong> é uma plataforma digital de partilha de vídeos criada para a comunidade angolana. 
                Para garantir um ambiente saudável e seguro para todos, existem regras que devem ser rigorosamente cumpridas.
            </p>
            <p>
                É estritamente proibido utilizar a plataforma para publicar:
            </p>
            <ul class="clean-list">
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Conteúdo Ilegal ou Ofensivo</span>
                        Material pornográfico, apologia ao ódio, discriminação, racismo, ou que viole a lei angolana.
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Direitos de Autor</span>
                        Publicar vídeos, músicas ou imagens que não te pertencem ou para os quais não tens autorização de uso.
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Assédio e Bullying</span>
                        Comportamentos que visem intimidar, ameaçar ou expor negativamente outros utilizadores.
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <div class="text-section">
        <div class="section-content">
            <h2>2. Idade Mínima e Contas</h2>
            <p>
                O MyTube destina-se a utilizadores com <strong>13 anos ou mais</strong>. Ao criar uma conta, o utilizador declara ter a idade mínima exigida. Contas de menores de 13 anos serão encerradas imediatamente.
            </p>
            <p>
                És totalmente responsável por manter a segurança da tua conta e por toda a actividade que ocorra nela. O MyTube reserva-se o direito de suspender ou eliminar contas que violem de forma repetida ou grave as regras da plataforma.
            </p>
        </div>
    </div>

    <div class="text-section">
        <div class="section-content">
            <h2>3. Propriedade do Conteúdo</h2>
            <p>
                Tu manténs a propriedade de todos os direitos sobre os vídeos que publicas. No entanto, ao publicar conteúdo no MyTube, concedes-nos uma licença global, não exclusiva e gratuita para exibir e partilhar esse conteúdo dentro da plataforma para que outros utilizadores o possam ver.
            </p>
        </div>
    </div>

    <div class="text-section">
        <div class="section-content">
            <h2>4. Publicidade e Dados</h2>
            <p>
                Para manter a plataforma gratuita, o MyTube utiliza publicidade fornecida pelo <strong>Google AdSense</strong>. 
                Ao aceitar estes termos, compreendes que a plataforma exibe anúncios e que o nosso parceiro de publicidade pode recolher certos dados (através de cookies) para apresentar anúncios mais relevantes.
            </p>
            <p>
                Para saberes em detalhe como os teus dados são processados e como podes gerir as tuas preferências, lê atentamente a nossa <a href="privacidade.php" style="color:var(--blue);text-decoration:underline;">Política de Privacidade</a>.
            </p>
        </div>
    </div>

    <div class="callout">
        <p>
            Tens alguma dúvida sobre o que é ou não permitido no MyTube? Encontraste conteúdo que viola os nossos termos?
            <strong>A nossa equipa de moderação está pronta para ajudar.</strong>
        </p>
        <a href="contacto.php" class="callout-link">
            Falar com o Suporte
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/></svg>
        </a>
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
            <a href="termos.php" class="active">Termos</a>
            <a href="privacidade.php">Privacidade</a>
        </nav>
    </div>
</footer>

<?php require_once 'includes/cookie_banner.php'; ?>
</body>
</html>
