<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacto — MyTube</title>
    <meta name="description" content="Entra em contacto com a equipa do MyTube. Estamos aqui para ajudar com dúvidas, sugestões ou questões sobre a plataforma.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/contacto.php">
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
            --gradient: linear-gradient(135deg,#1e40af 0%,#3b82f6 50%,#06b6d4 100%);
            --white:    #ffffff;
            --light:    #f0f4ff;
            --gray:     #64748b;
            --shadow:   0 20px 40px rgba(30,64,175,.12);
        }

        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Inter',-apple-system,sans-serif;
            background:var(--light);
            color:#334155;
            line-height:1.7;
        }

        /* ── Header ── */
        .page-header {
            background:var(--gradient);
            padding:52px 20px 64px;
            text-align:center;
            position:relative;
            overflow:hidden;
        }
        .page-header::before {
            content:'';
            position:absolute; inset:0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .header-logo {
            display:inline-flex; align-items:center; gap:10px;
            text-decoration:none; margin-bottom:24px; position:relative;
        }
        .logo-icon {
            width:48px; height:48px;
            background:rgba(255,255,255,.2); border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            backdrop-filter:blur(4px);
        }
        .logo-icon svg { width:28px; height:28px; fill:#fff; }
        .logo-text { font-size:24px; font-weight:800; color:#fff; letter-spacing:-.5px; }
        .page-header h1 {
            font-size:clamp(26px,6vw,40px); font-weight:800; color:#fff;
            letter-spacing:-.5px; position:relative;
        }
        .page-header p { color:rgba(255,255,255,.85); font-size:16px; margin-top:10px; position:relative; }

        /* ── Layout ── */
        .content-wrapper {
            max-width:860px; margin:-40px auto 70px; padding:0 20px;
        }
        .grid {
            display:grid;
            grid-template-columns:1fr 1.6fr;
            gap:24px;
            align-items:start;
        }
        @media(max-width:680px) { .grid { grid-template-columns:1fr; } }

        /* ── Cards ── */
        .card {
            background:#fff; border-radius:18px;
            box-shadow:var(--shadow);
            padding:clamp(24px,5vw,40px);
        }
        .card h2 {
            font-size:clamp(18px,3vw,22px); font-weight:800;
            color:#0f172a; margin-bottom:20px; letter-spacing:-.3px;
        }

        /* ── Info Cards (esquerda) ── */
        .info-item {
            display:flex; align-items:flex-start; gap:14px;
            padding:16px 0; border-bottom:1px solid #f1f5f9;
        }
        .info-item:last-child { border-bottom:none; padding-bottom:0; }
        .info-icon {
            width:42px; height:42px; min-width:42px;
            background:linear-gradient(135deg,#eff6ff,#e0f2fe);
            border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            font-size:18px;
        }
        .info-label { font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em; }
        .info-value { font-size:14.5px; font-weight:600; color:#0f172a; margin-top:2px; }
        .info-value a { color:var(--secondary); text-decoration:none; }
        .info-value a:hover { text-decoration:underline; }
        .info-sub { font-size:12.5px; color:#94a3b8; margin-top:2px; }

        /* ── Formulário (direita) ── */
        .form-group { margin-bottom:18px; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        @media(max-width:480px) { .form-row { grid-template-columns:1fr; } }

        label {
            display:block; font-size:13px; font-weight:600;
            color:#334155; margin-bottom:7px;
        }
        label span { color:#ef4444; margin-left:2px; }

        input, select, textarea {
            width:100%; border:1.5px solid #e2e8f0;
            border-radius:10px; padding:12px 14px;
            font-family:'Inter',sans-serif; font-size:14.5px;
            color:#0f172a; background:#fff;
            transition:border-color .2s, box-shadow .2s;
            outline:none;
        }
        input:focus, select:focus, textarea:focus {
            border-color:var(--secondary);
            box-shadow:0 0 0 3px rgba(59,130,246,.12);
        }
        textarea { resize:vertical; min-height:130px; }
        select { cursor:pointer; }

        .char-count { font-size:11.5px; color:#94a3b8; text-align:right; margin-top:4px; }

        /* ── Botão submit ── */
        .btn-submit {
            width:100%; padding:14px 24px;
            background:var(--gradient); color:#fff;
            font-size:15px; font-weight:700;
            border:none; border-radius:12px; cursor:pointer;
            display:flex; align-items:center; justify-content:center; gap:8px;
            transition:transform .2s, box-shadow .2s;
            margin-top:4px;
        }
        .btn-submit:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(30,64,175,.3); }
        .btn-submit:active { transform:translateY(0); }
        .btn-submit:disabled { opacity:.6; cursor:not-allowed; transform:none; }

        /* ── Alertas ── */
        .alert {
            padding:14px 18px; border-radius:10px;
            font-size:14px; font-weight:500;
            display:none; margin-bottom:18px;
            align-items:center; gap:10px;
        }
        .alert.show { display:flex; }
        .alert-success { background:#f0fdf4; border:1px solid #86efac; color:#166534; }
        .alert-error   { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; }

        /* ── Footer ── */
        .page-footer {
            text-align:center; padding-bottom:48px;
            color:var(--gray); font-size:14px;
        }
        .page-footer a { color:var(--secondary); text-decoration:none; font-weight:600; }
        .footer-links { display:flex; flex-wrap:wrap; gap:8px 20px; justify-content:center; margin-bottom:10px; }

        /* ── Spinner ── */
        .spinner {
            width:18px; height:18px; border:2px solid rgba(255,255,255,.4);
            border-top-color:#fff; border-radius:50%;
            animation:spin .7s linear infinite;
            display:none;
        }
        @keyframes spin { to { transform:rotate(360deg); } }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/config.php'; ?>

<div class="page-header">
    <a href="/" class="header-logo">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 5v14l11-7z"/>
            </svg>
        </div>
        <span class="logo-text">MyTube</span>
    </a>
    <h1>Fala Connosco</h1>
    <p>Estamos aqui para ajudar. Resposta em até 48 horas.</p>
</div>

<div class="content-wrapper">
    <div class="grid">

        <!-- Coluna esquerda: Informações de contacto -->
        <div class="card">
            <h2>Informações</h2>

            <div class="info-item">
                <div class="info-icon">📧</div>
                <div>
                    <div class="info-label">Email</div>
                    <div class="info-value"><a href="mailto:mytubeao@gmail.com">mytubeao@gmail.com</a></div>
                    <div class="info-sub">Resposta em até 48h</div>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">🌍</div>
                <div>
                    <div class="info-label">Localização</div>
                    <div class="info-value">Angola</div>
                    <div class="info-sub">Plataforma feita por angolanos</div>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">🕐</div>
                <div>
                    <div class="info-label">Horário de Suporte</div>
                    <div class="info-value">Segunda a Sábado</div>
                    <div class="info-sub">08h00 – 20h00 (WAT)</div>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">🌐</div>
                <div>
                    <div class="info-label">Plataforma</div>
                    <div class="info-value"><a href="https://mytube.social" target="_blank">mytube.social</a></div>
                    <div class="info-sub">Disponível 24/7</div>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">⚡</div>
                <div>
                    <div class="info-label">Assuntos Urgentes</div>
                    <div class="info-value">Abuso / Conteúdo ilícito</div>
                    <div class="info-sub">Tratados com prioridade</div>
                </div>
            </div>
        </div>

        <!-- Coluna direita: Formulário -->
        <div class="card">
            <h2>Enviar Mensagem</h2>

            <div id="alertSuccess" class="alert alert-success">
                ✅ <span>Mensagem enviada com sucesso! Respondemos em até 48 horas.</span>
            </div>
            <div id="alertError" class="alert alert-error">
                ❌ <span id="alertErrorMsg">Erro ao enviar. Tenta novamente.</span>
            </div>

            <form id="contactForm" novalidate>
                <?php echo csrf_field(); ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nome">Nome <span>*</span></label>
                        <input type="text" id="nome" name="nome" placeholder="O teu nome" required maxlength="80" autocomplete="name">
                    </div>
                    <div class="form-group">
                        <label for="email">Email <span>*</span></label>
                        <input type="email" id="email" name="email" placeholder="o.teu@email.com" required maxlength="120" autocomplete="email">
                    </div>
                </div>

                <div class="form-group">
                    <label for="assunto">Assunto <span>*</span></label>
                    <select id="assunto" name="assunto" required>
                        <option value="">Seleciona o assunto…</option>
                        <option value="Suporte técnico">🛠️ Suporte técnico</option>
                        <option value="Reportar conteúdo">🚩 Reportar conteúdo</option>
                        <option value="Problema com conta">👤 Problema com conta</option>
                        <option value="Publicidade e parcerias">📢 Publicidade e parcerias</option>
                        <option value="Sugestão de melhoria">💡 Sugestão de melhoria</option>
                        <option value="Questão sobre privacidade">🔒 Questão sobre privacidade</option>
                        <option value="Outro">💬 Outro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="mensagem">Mensagem <span>*</span></label>
                    <textarea id="mensagem" name="mensagem" placeholder="Descreve o teu problema ou questão com o máximo de detalhe possível…" required maxlength="3000"></textarea>
                    <div class="char-count"><span id="charCount">0</span> / 3000</div>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">
                    <span id="btnText">Enviar Mensagem</span>
                    <div class="spinner" id="spinner"></div>
                    <svg id="btnIcon" width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </form>
        </div>

    </div>
</div>

<div class="page-footer">
    <div class="footer-links">
        <a href="/">Início</a>
        <a href="/sobre.php">Sobre o MyTube</a>
        <a href="/termos.php">Termos de Uso</a>
        <a href="/privacidade.php">Política de Privacidade</a>
    </div>
    <p>&copy; <?php echo date('Y'); ?> MyTube &bull; Angola &bull; <a href="mailto:mytubeao@gmail.com">mytubeao@gmail.com</a></p>
</div>

<script>
(function () {
    const form      = document.getElementById('contactForm');
    const btn       = document.getElementById('btnSubmit');
    const btnText   = document.getElementById('btnText');
    const spinner   = document.getElementById('spinner');
    const btnIcon   = document.getElementById('btnIcon');
    const msgInput  = document.getElementById('mensagem');
    const charCount = document.getElementById('charCount');
    const alertOk   = document.getElementById('alertSuccess');
    const alertErr  = document.getElementById('alertError');
    const alertErrMsg = document.getElementById('alertErrorMsg');

    // Contador de caracteres
    msgInput.addEventListener('input', () => {
        charCount.textContent = msgInput.value.length;
    });

    function setLoading(loading) {
        btn.disabled = loading;
        btnText.textContent = loading ? 'A enviar…' : 'Enviar Mensagem';
        spinner.style.display = loading ? 'block' : 'none';
        btnIcon.style.display = loading ? 'none' : 'block';
    }

    function showAlert(type, msg) {
        alertOk.classList.remove('show');
        alertErr.classList.remove('show');
        if (type === 'success') {
            alertOk.classList.add('show');
        } else {
            alertErrMsg.textContent = msg;
            alertErr.classList.add('show');
        }
        alertOk.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertOk.classList.remove('show');
        alertErr.classList.remove('show');

        const nome     = document.getElementById('nome').value.trim();
        const email    = document.getElementById('email').value.trim();
        const assunto  = document.getElementById('assunto').value;
        const mensagem = msgInput.value.trim();

        // Validação client-side básica
        if (!nome || !email || !assunto || !mensagem) {
            showAlert('error', 'Por favor, preenche todos os campos obrigatórios.');
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showAlert('error', 'Endereço de email inválido.');
            return;
        }

        setLoading(true);

        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        const body = new FormData(form);

        try {
            const res  = await fetch('/api/contacto_enviar.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body
            });
            const data = await res.json();

            if (data.success) {
                showAlert('success');
                form.reset();
                charCount.textContent = '0';
            } else {
                showAlert('error', data.message || 'Erro desconhecido. Tenta novamente.');
            }
        } catch (err) {
            showAlert('error', 'Erro de ligação. Verifica a tua internet e tenta novamente.');
        } finally {
            setLoading(false);
        }
    });
})();
</script>

</body>
</html>
