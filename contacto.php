<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacto — MyTube</title>
    <meta name="description" content="Entra em contacto com a equipa do MyTube. Suporte técnico, reportar conteúdo, parcerias ou sugestões — estamos à distância de uma mensagem.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/contacto.php">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <meta name="google-adsense-account" content="ca-pub-7296999127636132">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --blue:    #1d4ed8;
            --blue-lt: #3b82f6;
            --ink:     #111827;
            --body:    #374151;
            --muted:   #6b7280;
            --rule:    #e5e7eb;
            --bg:      #f9fafb;
            --white:   #ffffff;
            --err:     #dc2626;
            --ok:      #16a34a;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--body);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
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
            background: var(--blue); border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .nav-logo-icon svg { width: 18px; height: 18px; fill: #fff; }
        .nav-logo-name { font-size: 16px; font-weight: 800; letter-spacing: -.3px; }
        .nav-links { display: flex; gap: 28px; }
        .nav-links a {
            font-size: 13.5px; font-weight: 500; color: var(--muted);
            text-decoration: none; transition: color .15s;
        }
        .nav-links a:hover, .nav-links a.active { color: var(--ink); }
        .nav-btn {
            font-size: 13px; font-weight: 600; color: var(--white);
            background: var(--blue); padding: 7px 18px; border-radius: 8px;
            text-decoration: none; transition: background .15s;
        }
        .nav-btn:hover { background: #1e40af; }

        /* ── Layout principal ── */
        .page-wrap {
            max-width: 900px; margin: 0 auto;
            padding: 56px 24px 80px;
            display: grid;
            grid-template-columns: 1fr 1.65fr;
            gap: 56px;
            align-items: start;
        }
        @media (max-width: 680px) {
            .page-wrap { grid-template-columns: 1fr; gap: 40px; padding-top: 40px; }
        }

        /* ── Coluna esquerda ── */
        .left-col {}
        .page-kicker {
            font-size: 11px; font-weight: 700; letter-spacing: .1em;
            text-transform: uppercase; color: var(--blue); margin-bottom: 14px;
        }
        .page-title {
            font-size: clamp(28px, 5vw, 38px); font-weight: 800;
            color: var(--ink); line-height: 1.15; letter-spacing: -1px;
        }
        .page-lead {
            margin-top: 16px; font-size: 15.5px; color: var(--muted); line-height: 1.75;
        }

        /* Info simples */
        .info-block { margin-top: 40px; }
        .info-row {
            padding: 18px 0;
            border-top: 1px solid var(--rule);
        }
        .info-row:last-child { border-bottom: 1px solid var(--rule); }
        .info-key {
            font-size: 11px; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: var(--muted); margin-bottom: 4px;
        }
        .info-val {
            font-size: 15px; font-weight: 500; color: var(--ink);
        }
        .info-val a { color: var(--blue); text-decoration: none; }
        .info-val a:hover { text-decoration: underline; }

        /* ── Coluna direita (formulário) ── */
        .form-card {
            background: var(--white);
            border: 1px solid var(--rule);
            border-radius: 16px;
            padding: clamp(28px, 5vw, 44px);
        }
        .form-title {
            font-size: 18px; font-weight: 700; color: var(--ink);
            margin-bottom: 28px; letter-spacing: -.2px;
        }

        .form-group { margin-bottom: 20px; }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 480px) { .form-row-2 { grid-template-columns: 1fr; } }

        label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--ink); margin-bottom: 7px;
        }
        .req { color: var(--err); margin-left: 2px; font-weight: 400; }

        input[type="text"],
        input[type="email"],
        select,
        textarea {
            width: 100%;
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            color: var(--ink);
            background: var(--bg);
            border: 1.5px solid var(--rule);
            border-radius: 10px;
            padding: 11px 14px;
            outline: none;
            transition: border-color .15s, background .15s, box-shadow .15s;
            -webkit-appearance: none;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        select:focus,
        textarea:focus {
            border-color: var(--blue-lt);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(59,130,246,.1);
        }
        textarea { resize: vertical; min-height: 120px; }
        select { cursor: pointer; }

        .char-hint { font-size: 12px; color: var(--muted); text-align: right; margin-top: 5px; }

        /* Botão */
        .btn-send {
            width: 100%; margin-top: 4px;
            font-family: 'Inter', sans-serif;
            font-size: 14.5px; font-weight: 600; color: var(--white);
            background: var(--blue);
            border: none; border-radius: 10px;
            padding: 13px 24px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background .15s, transform .15s;
        }
        .btn-send:hover { background: #1e40af; transform: translateY(-1px); }
        .btn-send:active { transform: translateY(0); }
        .btn-send:disabled { opacity: .55; cursor: not-allowed; transform: none; }
        .btn-send svg { width: 16px; height: 16px; fill: currentColor; flex-shrink: 0; }

        /* Spinner */
        .spin {
            width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,.35);
            border-top-color: #fff; border-radius: 50%;
            animation: rot .65s linear infinite;
            display: none; flex-shrink: 0;
        }
        @keyframes rot { to { transform: rotate(360deg); } }

        /* Alertas */
        .alert {
            border-radius: 10px; padding: 13px 16px;
            font-size: 13.5px; font-weight: 500;
            display: none; align-items: flex-start; gap: 10px;
            margin-bottom: 20px; line-height: 1.5;
        }
        .alert.show { display: flex; }
        .alert-ok  { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--ok); }
        .alert-err { background: #fef2f2; border: 1px solid #fecaca; color: var(--err); }
        .alert-icon { flex-shrink: 0; margin-top: 1px; }

        /* ── Footer ── */
        .site-footer {
            border-top: 1px solid var(--rule);
            padding: 28px 24px;
        }
        .footer-inner {
            max-width: 900px; margin: 0 auto;
            display: flex; flex-wrap: wrap;
            align-items: center; justify-content: space-between; gap: 12px;
        }
        .footer-copy { font-size: 13px; color: var(--muted); }
        .footer-nav { display: flex; flex-wrap: wrap; gap: 4px 18px; }
        .footer-nav a { font-size: 13px; color: var(--muted); text-decoration: none; }
        .footer-nav a:hover { color: var(--ink); }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/config.php'; ?>

<!-- Topbar -->
<nav class="topbar">
    <div class="topbar-inner">
        <a href="/" class="nav-logo">
            <div class="nav-logo-icon">
                <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </div>
            <span class="nav-logo-name">MyTube</span>
        </a>
        <div class="nav-links">
            <a href="/">Início</a>
            <a href="/sobre.php">Sobre</a>
            <a href="/contacto.php" class="active">Contacto</a>
        </div>
        <a href="/login.php?register=1" class="nav-btn">Criar conta</a>
    </div>
</nav>

<!-- Conteúdo -->
<div class="page-wrap">

    <!-- Esquerda -->
    <div class="left-col">
        <p class="page-kicker">Contacto</p>
        <h1 class="page-title">Fala directamente<br>com a nossa equipa.</h1>
        <p class="page-lead">
            Seja um problema técnico, uma sugestão ou uma questão de parceria —
            respondemos a todas as mensagens. Normalmente em menos de 48 horas.
        </p>

        <div class="info-block">
            <div class="info-row">
                <div class="info-key">Email directo</div>
                <div class="info-val"><a href="mailto:mytubeao@gmail.com">mytubeao@gmail.com</a></div>
            </div>
            <div class="info-row">
                <div class="info-key">Plataforma</div>
                <div class="info-val"><a href="https://mytube.social" target="_blank">mytube.social</a></div>
            </div>
            <div class="info-row">
                <div class="info-key">País</div>
                <div class="info-val">Angola</div>
            </div>
            <div class="info-row">
                <div class="info-key">Tempo de resposta</div>
                <div class="info-val">Até 48 horas úteis</div>
            </div>
        </div>
    </div>

    <!-- Direita: formulário -->
    <div class="form-card">
        <p class="form-title">Enviar mensagem</p>

        <!-- Alertas -->
        <div class="alert alert-ok" id="alertOk" role="alert">
            <svg class="alert-icon" width="16" height="16" viewBox="0 0 24 24" fill="#16a34a">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
            <span>Mensagem enviada. Respondemos em até 48 horas.</span>
        </div>
        <div class="alert alert-err" id="alertErr" role="alert">
            <svg class="alert-icon" width="16" height="16" viewBox="0 0 24 24" fill="#dc2626">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
            <span id="alertErrMsg">Ocorreu um erro. Tenta novamente.</span>
        </div>

        <form id="contactForm" novalidate>
            <?php echo csrf_field(); ?>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="nome">Nome <span class="req">*</span></label>
                    <input type="text" id="nome" name="nome" placeholder="O teu nome" required maxlength="80" autocomplete="name">
                </div>
                <div class="form-group">
                    <label for="email">Email <span class="req">*</span></label>
                    <input type="email" id="email" name="email" placeholder="email@exemplo.com" required maxlength="120" autocomplete="email">
                </div>
            </div>

            <div class="form-group">
                <label for="assunto">Assunto <span class="req">*</span></label>
                <select id="assunto" name="assunto" required>
                    <option value="">Selecciona o assunto…</option>
                    <option value="Suporte técnico">Suporte técnico</option>
                    <option value="Reportar conteúdo">Reportar conteúdo</option>
                    <option value="Problema com conta">Problema com conta</option>
                    <option value="Publicidade e parcerias">Publicidade e parcerias</option>
                    <option value="Sugestão de melhoria">Sugestão de melhoria</option>
                    <option value="Questão sobre privacidade">Questão sobre privacidade</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>

            <div class="form-group">
                <label for="mensagem">Mensagem <span class="req">*</span></label>
                <textarea id="mensagem" name="mensagem" placeholder="Descreve o teu problema ou questão com o máximo de detalhe possível…" required maxlength="3000"></textarea>
                <div class="char-hint"><span id="charCount">0</span> / 3000</div>
            </div>

            <button type="submit" class="btn-send" id="btnSend">
                <span id="btnLabel">Enviar mensagem</span>
                <div class="spin" id="spin"></div>
                <svg id="btnIcon" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            </button>
        </form>
    </div>

</div>

<!-- Footer -->
<footer class="site-footer">
    <div class="footer-inner">
        <span class="footer-copy">&copy; <?php echo date('Y'); ?> MyTube &mdash; Angola</span>
        <nav class="footer-nav">
            <a href="/">Início</a>
            <a href="/sobre.php">Sobre</a>
            <a href="/termos.php">Termos</a>
            <a href="/privacidade.php">Privacidade</a>
        </nav>
    </div>
</footer>

<script>
(function () {
    const form     = document.getElementById('contactForm');
    const btnSend  = document.getElementById('btnSend');
    const btnLabel = document.getElementById('btnLabel');
    const spin     = document.getElementById('spin');
    const btnIcon  = document.getElementById('btnIcon');
    const msgInput = document.getElementById('mensagem');
    const charCount= document.getElementById('charCount');
    const alertOk  = document.getElementById('alertOk');
    const alertErr = document.getElementById('alertErr');
    const alertErrMsg = document.getElementById('alertErrMsg');

    msgInput.addEventListener('input', () => {
        charCount.textContent = msgInput.value.length;
    });

    function setLoading(on) {
        btnSend.disabled = on;
        btnLabel.textContent = on ? 'A enviar…' : 'Enviar mensagem';
        spin.style.display    = on ? 'block' : 'none';
        btnIcon.style.display = on ? 'none'  : 'block';
    }

    function notify(type, msg) {
        alertOk.classList.remove('show');
        alertErr.classList.remove('show');
        if (type === 'ok') {
            alertOk.classList.add('show');
        } else {
            alertErrMsg.textContent = msg;
            alertErr.classList.add('show');
        }
        window.scrollTo({ top: alertOk.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertOk.classList.remove('show');
        alertErr.classList.remove('show');

        const nome    = document.getElementById('nome').value.trim();
        const email   = document.getElementById('email').value.trim();
        const assunto = document.getElementById('assunto').value;
        const msg     = msgInput.value.trim();

        if (!nome || !email || !assunto || !msg) {
            notify('err', 'Preenche todos os campos antes de enviar.');
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            notify('err', 'O endereço de email não é válido.');
            return;
        }

        setLoading(true);

        const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';

        try {
            const res  = await fetch('/api/contacto_enviar.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                body: new FormData(form)
            });
            const data = await res.json();

            if (data.success) {
                notify('ok');
                form.reset();
                charCount.textContent = '0';
            } else {
                notify('err', data.message || 'Erro desconhecido. Tenta novamente.');
            }
        } catch {
            notify('err', 'Sem ligação ao servidor. Verifica a tua internet e tenta novamente.');
        } finally {
            setLoading(false);
        }
    });
})();
</script>

</body>
</html>
