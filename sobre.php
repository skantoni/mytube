<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sobre o MyTube — A rede social de vídeos de Angola</title>
    <meta name="description" content="Conheça o MyTube: a plataforma angolana de vídeos curtos criada para dar voz a criadores de conteúdo de todo o país.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/sobre.php">
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

        /* ── Barra de navegação topo ── */
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
            font-size: clamp(34px, 6vw, 58px);
            font-weight: 800; color: var(--ink);
            line-height: 1.1; letter-spacing: -1.5px;
            max-width: 660px;
        }
        .hero h1 em {
            font-style: italic; font-weight: 300;
            color: var(--blue);
        }
        .hero-lead {
            margin-top: 28px; max-width: 540px;
            font-size: 17px; color: var(--muted); line-height: 1.7;
        }
        .hero-cta {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 36px;
            font-size: 14px; font-weight: 600; color: var(--white);
            background: var(--blue); padding: 12px 28px; border-radius: 10px;
            text-decoration: none; transition: background .15s, transform .15s;
        }
        .hero-cta:hover { background: #1e40af; transform: translateY(-1px); }
        .hero-cta svg { width: 16px; height: 16px; fill: currentColor; }

        /* ── Conteúdo ── */
        .page-body {
            max-width: 900px; margin: 0 auto;
            padding: 0 24px 80px;
        }

        /* ── Secções de texto ── */
        .text-section {
            padding: 60px 0;
            border-bottom: 1px solid var(--rule);
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 48px;
            align-items: start;
        }
        @media (max-width: 640px) {
            .text-section { grid-template-columns: 1fr; gap: 20px; }
        }
        .section-label-col {
            padding-top: 4px;
        }
        .section-tag {
            font-size: 11px; font-weight: 700; letter-spacing: .1em;
            text-transform: uppercase; color: var(--blue);
        }
        .section-num {
            font-size: 64px; font-weight: 800; color: var(--rule);
            line-height: 1; margin-top: 6px; letter-spacing: -2px;
            user-select: none;
        }
        .section-content h2 {
            font-size: clamp(22px, 3.5vw, 30px); font-weight: 800;
            color: var(--ink); line-height: 1.2; letter-spacing: -.5px;
            margin-bottom: 20px;
        }
        .section-content p {
            font-size: 16px; color: var(--body); line-height: 1.8;
            margin-bottom: 16px;
        }
        .section-content p:last-child { margin-bottom: 0; }
        .section-content strong { color: var(--ink); font-weight: 600; }

        /* ── Lista limpa ── */
        .clean-list {
            list-style: none; margin-top: 20px;
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

        /* ── Bloco de destaque ── */
        .callout {
            background: var(--ink); color: var(--bg);
            border-radius: 16px; padding: 40px;
            margin: 60px 0 0;
        }
        .callout p { font-size: 15px; line-height: 1.8; color: rgba(255,255,255,.7); }
        .callout p + p { margin-top: 12px; }
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
            <a href="index.php" >Início</a>
            <a href="sobre.php" class="active">Sobre</a>
            <a href="contacto.php">Contacto</a>
        </div>
        <a href="login.php?register=1" class="nav-btn">Criar conta</a>
    </div>
</nav>

<!-- Hero -->
<header class="hero">
    <span class="hero-kicker">Sobre o MyTube</span>
    <h1>Uma plataforma feita<br>em Angola, <em>para Angola.</em></h1>
    <p class="hero-lead">
        O MyTube nasceu da vontade simples de criar um espaço onde jovens angolanos possam partilhar
        o seu talento sem depender de plataformas feitas para outras realidades.
    </p>
    <a href="login.php?register=1" class="hero-cta">
        <svg viewBox="0 0 24 24"><path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/></svg>
        Entra na comunidade
    </a>
</header>

<!-- Corpo -->
<main class="page-body">

    <!-- Secção 1 — A história -->
    <div class="text-section">
        <div class="section-label-col">
            <div class="section-tag">A história</div>
            <div class="section-num">01</div>
        </div>
        <div class="section-content">
            <h2>Começámos com uma pergunta simples</h2>
            <p>
                Porque é que os criadores angolanos tinham de depender de plataformas que não foram construídas a pensar neles?
                Plataformas com algoritmos pensados para outras culturas, outras línguas, outras realidades?
            </p>
            <p>
                O MyTube é a resposta a essa pergunta. Uma plataforma de vídeos curtos desenvolvida em Angola,
                onde o feed fala a nossa língua, os rankings valorizam as nossas escolas e a comunidade reflecte
                quem nós somos.
            </p>
            <p>
                Desde o lançamento, temos crescido graças a criadores que acreditam que o talento angolano
                merece o seu próprio palco — e nós acreditamos nisso também.
            </p>
        </div>
    </div>

    <!-- Secção 2 — O que fazemos -->
    <div class="text-section">
        <div class="section-label-col">
            <div class="section-tag">O produto</div>
            <div class="section-num">02</div>
        </div>
        <div class="section-content">
            <h2>Uma plataforma completa, não um protótipo</h2>
            <p>
                O MyTube está disponível em <strong>mytube.social</strong> e é totalmente gratuito.
                Não é um projecto académico nem uma beta eterna — é uma plataforma em produção,
                com utilizadores reais que publicam vídeos todos os dias.
            </p>
            <ul class="clean-list">
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Feed de vídeos verticais</span>
                        Conteúdo angolano descoberto de forma orgânica, sem pagar para aparecer.
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Rankings escolares</span>
                        A única plataforma com um sistema de competição entre instituições de ensino angolanas.
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Chat em tempo real</span>
                        Mensagens directas e grupos para manter a comunidade ligada.
                    </span>
                </li>
                <li>
                    <span class="list-dot"></span>
                    <span>
                        <span class="list-title">Transmissões ao vivo</span>
                        Vai a LIVE e interage com os teus seguidores sem sair da plataforma.
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <!-- Secção 3 — Transparência -->
    <div class="text-section" style="border-bottom:none;">
        <div class="section-label-col">
            <div class="section-tag">Transparência</div>
            <div class="section-num">03</div>
        </div>
        <div class="section-content">
            <h2>Gratuito não significa sem custos</h2>
            <p>
                Manter servidores, armazenamento de vídeos e desenvolvimento activo tem custos reais.
                Para que o MyTube continue gratuito para todos, utilizamos publicidade do
                <strong>Google AdSense</strong> como modelo de financiamento.
            </p>
            <p>
                Não vendemos os teus dados. Não cobramos subscrições. A publicidade que vês é o
                que nos permite manter a plataforma a funcionar 24 horas por dia, 365 dias por ano —
                sem pedir nada em troca.
            </p>
            <p>
                Podes saber mais sobre como tratamos os teus dados na nossa
                <a href="privacidade.php" style="color:var(--blue);font-weight:600;text-decoration:none;">Política de Privacidade</a>.
            </p>
        </div>
    </div>

    <!-- Callout final -->
    <div class="callout">
        <p>
            O MyTube é construído por pessoas que usam a plataforma todos os dias e que percebem
            o que a comunidade angolana precisa. Se tens questões, sugestões ou queres trabalhar
            connosco, <strong>fala directamente connosco</strong>.
        </p>
        <a href="contacto.php" class="callout-link">
            Entrar em contacto
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
            <a href="termos.php">Termos</a>
            <a href="privacidade.php">Privacidade</a>
        </nav>
    </div>
</footer>

</body>
</html>
