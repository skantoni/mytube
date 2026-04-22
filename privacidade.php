<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidade - MyTube</title>
    <meta name="description" content="Política de Privacidade e Proteção de Dados da plataforma MyTube.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/privacidade.php">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #1e40af;
            --secondary-blue: #3b82f6;
            --light-blue: #60a5fa;
            --dark-blue: #1e3a8a;
            --accent-blue: #06b6d4;
            --gradient-blue: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #06b6d4 100%);
            --white: #ffffff;
            --light-gray: #f8fafc;
            --gray: #64748b;
            --dark-gray: #334155;
            --black: #0f172a;
            --border-radius: 12px;
            --shadow-lg: 0 20px 40px rgba(30, 64, 175, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f0f4ff;
            color: var(--dark-gray);
            line-height: 1.7;
            min-height: 100vh;
        }

        /* ── Header ── */
        .page-header {
            background: var(--gradient-blue);
            padding: 48px 20px 56px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header-logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            margin-bottom: 20px;
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .logo-icon svg {
            width: 24px;
            height: 24px;
            fill: #ffffff;
        }

        .logo-text {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
        }

        .page-header h1 {
            font-size: clamp(24px, 5vw, 36px);
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
            position: relative;
        }

        /* ── Main content ── */
        .content-wrapper {
            max-width: 800px;
            margin: -32px auto 60px;
            padding: 0 20px;
            position: relative;
        }

        .terms-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            padding: clamp(28px, 6vw, 56px);
        }

        .terms-section {
            margin-bottom: 40px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }

        .section-number {
            width: 36px;
            height: 36px;
            min-width: 36px;
            background: var(--gradient-blue);
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .section-header h2 {
            font-size: clamp(16px, 3vw, 20px);
            font-weight: 700;
            color: var(--dark-blue);
        }

        p {
            margin-bottom: 15px;
            color: #475569;
        }

        ul {
            list-style: none;
            margin: 15px 0;
        }

        li {
            position: relative;
            padding-left: 25px;
            margin-bottom: 10px;
            color: #475569;
        }

        li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: var(--secondary-blue);
            font-weight: bold;
        }

        .last-updated {
            display: inline-flex;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 12px;
            color: #166534;
            margin-bottom: 28px;
        }

        .highlight-box {
            background: #f8fafc;
            border-left: 4px solid var(--secondary-blue);
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }

        .page-footer {
            text-align: center;
            padding-bottom: 40px;
            color: var(--gray);
            font-size: 14px;
        }

        .page-footer a {
            color: var(--secondary-blue);
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <div class="page-header">
        <a href="login.php" class="header-logo">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 5v14l11-7z"/>
                </svg>
            </div>
            <span class="logo-text">MyTube</span>
        </a>
        <h1>Política de Privacidade</h1>
    </div>

    <div class="content-wrapper">
        <div class="terms-card">
            <div class="last-updated">Atualizado em: Abril de 2026</div>

            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">1</div>
                    <h2>Introdução</h2>
                </div>
                <p>O MyTube respeita a sua privacidade e está empenhado em proteger os dados pessoais que partilha connosco. Esta política descreve como recolhemos, usamos e protegemos as suas informações ao utilizar a nossa plataforma.</p>
            </div>

            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">2</div>
                    <h2>Dados que Recolhemos</h2>
                </div>
                <p>Recolhemos informações necessárias para o funcionamento da plataforma, tais como:</p>
                <ul>
                    <li><strong>Informações de Registo:</strong> Nome, e-mail e nome de utilizador.</li>
                    <li><strong>Autenticação Google:</strong> Se optar pelo login com Google, recebemos o seu ID único, e-mail e foto de perfil autorizada.</li>
                    <li><strong>Conteúdo:</strong> Vídeos e comentários que publica.</li>
                    <li><strong>Interação:</strong> Seguidores, gostos e histórico de visualização.</li>
                </ul>
            </div>

            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">3</div>
                    <h2>Uso das Informações</h2>
                </div>
                <p>As suas informações são utilizadas exclusivamente para:</p>
                <ul>
                    <li>Personalizar a sua experiência na plataforma.</li>
                    <li>Gerir o sistema de ranking e competições.</li>
                    <li>Enviar notificações sobre interações (seguidores, comentários).</li>
                    <li>Garantir a segurança da conta e prevenir fraudes.</li>
                </ul>
                <div class="highlight-box">
                    <p><strong>Importante:</strong> Nós NÃO vendemos os seus dados a terceiros nem os utilizamos para fins publicitários externos sem o seu consentimento.</p>
                </div>
            </div>

            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">4</div>
                    <h2>Partilha de Dados</h2>
                </div>
                <p>Apenas partilhamos informações com terceiros nas seguintes situações:</p>
                <ul>
                    <li>Quando exigido por lei ou autoridades judiciais.</li>
                    <li>Para proteger os direitos e segurança da plataforma e utilizadores.</li>
                    <li>Com serviços de infraestrutura (ex: alojamento de vídeos) estritamente necessários para o serviço.</li>
                </ul>
            </div>

            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">5</div>
                    <h2>Os Seus Direitos</h2>
                </div>
                <p>Como utilizador, tem o direito de:</p>
                <ul>
                    <li>Aceder e atualizar os seus dados nas definições de perfil.</li>
                    <li>Solicitar a eliminação total da sua conta e dados associados.</li>
                    <li>Revogar o acesso de aplicações de terceiros (como o Google) através das definições da sua conta Google.</li>
                </ul>
            </div>

            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">6</div>
                    <h2>Contacto</h2>
                </div>
                <p>Se tiver dúvidas sobre esta política, pode contactar-nos através do e-mail: <br><strong>mytubeao@gmail.com</strong></p>
            </div>

        </div>
    </div>

    <div class="page-footer">
        <p>&copy; <?php echo date('Y'); ?> MyTube &bull; <a href="login.php">Voltar ao Login</a></p>
    </div>

</body>
</html>
