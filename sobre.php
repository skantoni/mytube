<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sobre o MyTube — A rede social de vídeos de Angola</title>
    <meta name="description" content="Conheça o MyTube: a plataforma angolana de vídeos curtos onde criadores partilham talento, competem em rankings escolares e se conectam em tempo real.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/sobre.php">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Google AdSense -->
    <meta name="google-adsense-account" content="ca-pub-7296999127636132">
    <style>
        :root {
            --primary:  #1e40af;
            --secondary:#3b82f6;
            --accent:   #06b6d4;
            --gradient: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #06b6d4 100%);
            --white:    #ffffff;
            --light:    #f0f4ff;
            --gray:     #64748b;
            --dark:     #0f172a;
            --card-shadow: 0 20px 40px rgba(30,64,175,.12);
        }

        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Inter', -apple-system, sans-serif;
            background: var(--light);
            color: #334155;
            line-height: 1.7;
        }

        /* ── Header ── */
        .page-header {
            background: var(--gradient);
            padding: 52px 20px 64px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content:'';
            position:absolute; inset:0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .header-logo {
            display:inline-flex; align-items:center; gap:10px;
            text-decoration:none; margin-bottom:24px;
        }
        .logo-icon {
            width:48px; height:48px;
            background:rgba(255,255,255,.2);
            border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            backdrop-filter:blur(4px);
        }
        .logo-icon svg { width:28px; height:28px; fill:#fff; }
        .logo-text { font-size:24px; font-weight:800; color:#fff; letter-spacing:-.5px; }
        .page-header h1 {
            font-size: clamp(28px,6vw,42px);
            font-weight:800; color:#fff;
            letter-spacing:-.5px;
            position:relative;
        }
        .page-header p {
            color:rgba(255,255,255,.85);
            font-size:clamp(15px,2vw,18px);
            margin-top:12px;
            position:relative;
        }

        /* ── Layout ── */
        .content-wrapper {
            max-width: 860px;
            margin: -40px auto 70px;
            padding: 0 20px;
            position: relative;
        }

        /* ── Cards ── */
        .card {
            background:#fff;
            border-radius:18px;
            box-shadow: var(--card-shadow);
            padding: clamp(28px,6vw,52px);
            margin-bottom: 28px;
        }

        .section-label {
            display:inline-flex; align-items:center; gap:8px;
            background: linear-gradient(135deg,#eff6ff,#e0f2fe);
            color: var(--primary);
            font-size:12px; font-weight:700;
            text-transform:uppercase; letter-spacing:.08em;
            padding:5px 14px; border-radius:100px;
            margin-bottom:16px;
        }

        h2.section-title {
            font-size:clamp(20px,4vw,26px);
            font-weight:800; color:#0f172a;
            margin-bottom:16px;
            letter-spacing:-.3px;
        }

        p { margin-bottom:14px; color:#475569; font-size:15.5px; }
        p:last-child { margin-bottom:0; }

        /* ── Missão / Valores ── */
        .values-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
            gap:20px;
            margin-top:24px;
        }
        .value-card {
            background: linear-gradient(135deg,#f0f9ff,#f0f4ff);
            border-radius:14px;
            padding:24px 20px;
            border:1px solid #e0eaff;
            transition: transform .2s, box-shadow .2s;
        }
        .value-card:hover { transform:translateY(-4px); box-shadow:0 12px 30px rgba(30,64,175,.1); }
        .value-icon { font-size:32px; margin-bottom:12px; }
        .value-title { font-size:15px; font-weight:700; color:#1e3a8a; margin-bottom:6px; }
        .value-desc  { font-size:13.5px; color:#64748b; line-height:1.6; }

        /* ── Stats ── */
        .stats-row {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(140px,1fr));
            gap:16px; margin-top:24px;
        }
        .stat-box {
            text-align:center;
            background: var(--gradient);
            border-radius:14px;
            padding:24px 16px;
            color:#fff;
        }
        .stat-number { font-size:clamp(28px,5vw,36px); font-weight:800; }
        .stat-label  { font-size:13px; opacity:.85; margin-top:4px; }

        /* ── Team ── */
        .team-note {
            background:#f8fafc;
            border-left:4px solid var(--secondary);
            padding:20px 24px;
            border-radius:0 10px 10px 0;
            margin-top:8px;
        }

        /* ── CTA ── */
        .cta-box {
            background: var(--gradient);
            border-radius:18px;
            padding:40px 32px;
            text-align:center;
        }
        .cta-box h2 { color:#fff; font-size:clamp(20px,4vw,26px); font-weight:800; margin-bottom:10px; }
        .cta-box p  { color:rgba(255,255,255,.85); margin-bottom:24px; }
        .cta-btn {
            display:inline-flex; align-items:center; gap:8px;
            background:#fff; color:var(--primary);
            font-weight:700; font-size:15px;
            padding:12px 32px; border-radius:100px;
            text-decoration:none;
            transition: transform .2s, box-shadow .2s;
            box-shadow:0 4px 20px rgba(0,0,0,.15);
        }
        .cta-btn:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(0,0,0,.2); }

        /* ── Footer ── */
        .page-footer {
            text-align:center; padding-bottom:48px;
            color:var(--gray); font-size:14px;
        }
        .page-footer a { color:var(--secondary); text-decoration:none; font-weight:600; }
        .footer-links { display:flex; flex-wrap:wrap; gap:8px 20px; justify-content:center; margin-bottom:10px; }
    </style>
</head>
<body>

<div class="page-header">
    <a href="/" class="header-logo">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 5v14l11-7z"/>
            </svg>
        </div>
        <span class="logo-text">MyTube</span>
    </a>
    <h1>Sobre o MyTube</h1>
    <p>A rede social de vídeos feita por e para angolanos</p>
</div>

<div class="content-wrapper">

    <!-- Quem Somos -->
    <div class="card">
        <div class="section-label">🇦🇴 Quem Somos</div>
        <h2 class="section-title">Uma plataforma angolana, para criadores angolanos</h2>
        <p>
            O <strong>MyTube</strong> é uma plataforma digital de vídeos curtos criada em Angola, pensada para dar voz, visibilidade
            e oportunidade a criadores de conteúdo de todo o país. Nascemos com a convicção de que o talento angolano
            merece uma plataforma própria — moderna, rápida e feita à nossa medida.
        </p>
        <p>
            Desde o nosso lançamento, temos crescido graças a uma comunidade vibrante de estudantes, artistas,
            humoristas, desportistas e criadores de todo o tipo que encontraram no MyTube o seu palco digital.
        </p>
        <p>
            A plataforma está disponível 24/7 em <strong>mytube.social</strong> e é totalmente gratuita para qualquer pessoa
            que queira criar, partilhar e interagir.
        </p>
    </div>

    <!-- Missão e Valores -->
    <div class="card">
        <div class="section-label">🎯 Missão e Valores</div>
        <h2 class="section-title">O que nos move todos os dias</h2>
        <p>A nossa missão é simples: <strong>democratizar a criação de conteúdo em Angola</strong>, oferecendo ferramentas profissionais
        de forma gratuita e acessível a qualquer jovem com um telemóvel e uma ideia.</p>

        <div class="values-grid">
            <div class="value-card">
                <div class="value-icon">🎬</div>
                <div class="value-title">Criatividade Sem Limites</div>
                <div class="value-desc">Acreditamos que qualquer pessoa tem uma história para contar. Damos-lhe as ferramentas para a contar em vídeo.</div>
            </div>
            <div class="value-card">
                <div class="value-icon">🏆</div>
                <div class="value-title">Competição Saudável</div>
                <div class="value-desc">Os nossos rankings escolares criam um ambiente de competição positiva que une estudantes em vez de os dividir.</div>
            </div>
            <div class="value-card">
                <div class="value-icon">🔒</div>
                <div class="value-title">Privacidade e Segurança</div>
                <div class="value-desc">Levamos a sério a proteção dos dados dos nossos utilizadores. A segurança é uma prioridade, não uma opção.</div>
            </div>
            <div class="value-card">
                <div class="value-icon">🌍</div>
                <div class="value-title">Comunidade Angolana</div>
                <div class="value-desc">Construímos uma comunidade que reflete a diversidade, cultura e energia de Angola.</div>
            </div>
        </div>
    </div>

    <!-- O que oferecemos -->
    <div class="card">
        <div class="section-label">✨ Funcionalidades</div>
        <h2 class="section-title">O que encontras no MyTube</h2>
        <div class="values-grid">
            <div class="value-card">
                <div class="value-icon">📱</div>
                <div class="value-title">Feed de Vídeos Imersivo</div>
                <div class="value-desc">Um feed vertical de vídeos curtos, otimizado para telemóvel, onde descobres conteúdo novo todos os dias.</div>
            </div>
            <div class="value-card">
                <div class="value-icon">📊</div>
                <div class="value-title">Rankings Escolares</div>
                <div class="value-desc">Representa a tua escola ou universidade e compete pelos primeiros lugares do ranking nacional.</div>
            </div>
            <div class="value-card">
                <div class="value-icon">💬</div>
                <div class="value-title">Chat em Tempo Real</div>
                <div class="value-desc">Troca mensagens com outros criadores, forma grupos e mantém-te conectado à comunidade.</div>
            </div>
            <div class="value-card">
                <div class="value-icon">🔴</div>
                <div class="value-title">Transmissões ao Vivo</div>
                <div class="value-desc">Vai a LIVE e interage com os teus seguidores em tempo real, diretamente da plataforma.</div>
            </div>
        </div>
    </div>

    <!-- Números -->
    <div class="card">
        <div class="section-label">📈 A Nossa Comunidade</div>
        <h2 class="section-title">Crescemos juntos</h2>
        <p>Números que refletem a confiança que a comunidade angolana deposita no MyTube diariamente.</p>
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-number">🇦🇴</div>
                <div class="stat-label">Feito em Angola</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">24/7</div>
                <div class="stat-label">Disponível sempre</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">100%</div>
                <div class="stat-label">Grátis para sempre</div>
            </div>
        </div>
    </div>

    <!-- Equipa -->
    <div class="card">
        <div class="section-label">👥 Equipa</div>
        <h2 class="section-title">Quem está por detrás do MyTube</h2>
        <p>
            O MyTube é uma plataforma independente, desenvolvida e mantida por uma equipa apaixonada por tecnologia e pelo potencial criativo de Angola.
            Trabalhamos continuamente para melhorar a experiência, lançar novas funcionalidades e garantir que a plataforma está sempre disponível para a nossa comunidade.
        </p>
        <div class="team-note">
            <strong>Transparência:</strong> O MyTube é uma plataforma com fins comerciais que se financia através de publicidade (Google AdSense).
            Isto permite-nos manter o serviço gratuito para todos os utilizadores, sem cobrar subscrições ou taxas de qualquer tipo.
        </div>
    </div>

    <!-- CTA -->
    <div class="cta-box">
        <h2>Faz parte desta história 🚀</h2>
        <p>Junta-te a nós e mostra o teu talento para toda Angola.</p>
        <a href="/login.php?register=1" class="cta-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
            Criar Conta Grátis
        </a>
    </div>

</div>

<div class="page-footer">
    <div class="footer-links">
        <a href="/">Início</a>
        <a href="/contacto.php">Contacto</a>
        <a href="/termos.php">Termos de Uso</a>
        <a href="/privacidade.php">Política de Privacidade</a>
    </div>
    <p>&copy; <?php echo date('Y'); ?> MyTube &bull; Angola &bull; <a href="mailto:mytubeao@gmail.com">mytubeao@gmail.com</a></p>
</div>

</body>
</html>
