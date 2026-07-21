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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Google AdSense -->
    <meta name="google-adsense-account" content="ca-pub-7296999127636132">
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

        /* Destaque especial para secção de publicidade */
        .highlight-box.adsense {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }

        .highlight-box.warning {
            border-left-color: #ef4444;
            background: #fef2f2;
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

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 20px;
            justify-content: center;
            margin-bottom: 10px;
        }

        a.inline-link {
            color: var(--secondary-blue);
            text-decoration: none;
            font-weight: 600;
        }
        a.inline-link:hover { text-decoration: underline; }
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
        <h1>Política de Privacidade</h1>
    </div>

    <div class="content-wrapper">
        <div class="terms-card">
            <div class="last-updated">Atualizado em: Julho de 2026</div>

            <!-- 1. Introdução -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">1</div>
                    <h2>Introdução</h2>
                </div>
                <p>O <strong>MyTube</strong> (disponível em <a href="https://mytube.social" class="inline-link">mytube.social</a>) respeita a sua privacidade e está empenhado em proteger os dados pessoais que partilha connosco. Esta Política de Privacidade descreve como recolhemos, usamos, partilhamos e protegemos as suas informações ao utilizar a nossa plataforma, incluindo o uso de <strong>cookies</strong> e tecnologias de publicidade de terceiros.</p>
                <p>Ao utilizar o MyTube, concorda com os termos descritos nesta política. Se não concordar, pedimos que não utilize os nossos serviços.</p>
            </div>

            <!-- 2. Dados que Recolhemos -->
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
                    <li><strong>Dados técnicos:</strong> Endereço IP, tipo de navegador, sistema operativo e páginas visitadas — recolhidos automaticamente para fins de segurança e melhoria do serviço.</li>
                </ul>
            </div>

            <!-- 3. Cookies e Tecnologias de Rastreamento -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">3</div>
                    <h2>Cookies e Tecnologias de Rastreamento</h2>
                </div>
                <p>O MyTube e os seus parceiros de publicidade utilizam <strong>cookies</strong> e tecnologias semelhantes (como web beacons e pixels) para:</p>
                <ul>
                    <li>Manter a sua sessão de login ativa de forma segura.</li>
                    <li>Recordar as suas preferências na plataforma.</li>
                    <li>Analisar o tráfego e o comportamento dos utilizadores (de forma agregada e anónima).</li>
                    <li>Exibir publicidade personalizada através de parceiros de terceiros.</li>
                    <li>Prevenir fraudes e garantir a segurança da plataforma.</li>
                </ul>
                <div class="highlight-box">
                    <p><strong>O que são cookies?</strong> Cookies são pequenos ficheiros de texto guardados no seu dispositivo quando visita um website. Permitem que o site "se lembre" de si entre visitas. Pode gerir ou desativar cookies nas definições do seu navegador, mas algumas funcionalidades do MyTube podem não funcionar corretamente sem eles.</p>
                </div>
            </div>

            <!-- 4. Publicidade — Google AdSense -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">4</div>
                    <h2>Publicidade — Google AdSense</h2>
                </div>
                <p>O MyTube utiliza o <strong>Google AdSense</strong> (Publisher ID: <code>ca-pub-7296999127636132</code>) para exibir anúncios na plataforma. O AdSense é um serviço de publicidade fornecido pela Google LLC que nos permite financiar o serviço gratuitamente para todos os utilizadores.</p>

                <div class="highlight-box adsense">
                    <p><strong>⚠️ Como funciona a publicidade personalizada:</strong> O Google AdSense pode utilizar cookies e dados sobre as suas visitas ao MyTube e a outros websites para exibir anúncios relevantes com base nos seus interesses. Isto é chamado de publicidade baseada em interesses ou publicidade comportamental.</p>
                </div>

                <p>Ao visitar o MyTube, o Google pode:</p>
                <ul>
                    <li>Colocar cookies de publicidade no seu dispositivo.</li>
                    <li>Utilizar o seu endereço IP e informações sobre o seu navegador.</li>
                    <li>Recolher dados sobre as páginas que visita para personalizar anúncios.</li>
                    <li>Medir o desempenho dos anúncios exibidos.</li>
                </ul>

                <p>O MyTube <strong>não tem controlo direto</strong> sobre os cookies colocados pelo Google AdSense — estes são geridos pela Google de acordo com a sua própria política de privacidade.</p>

                <p>Para mais informações sobre como a Google utiliza os dados recolhidos:</p>
                <ul>
                    <li><a href="https://policies.google.com/privacy" target="_blank" class="inline-link">Política de Privacidade da Google</a></li>
                    <li><a href="https://policies.google.com/technologies/ads" target="_blank" class="inline-link">Como a Google usa cookies em publicidade</a></li>
                </ul>

                <div class="highlight-box">
                    <p><strong>Opt-out de anúncios personalizados:</strong> Pode desativar a publicidade personalizada da Google em: <a href="https://www.google.com/settings/ads" target="_blank" class="inline-link">google.com/settings/ads</a>. Também pode utilizar o <a href="https://optout.aboutads.info/" target="_blank" class="inline-link">Digital Advertising Alliance opt-out</a>. Note que mesmo após o opt-out, continuará a ver anúncios — apenas não serão personalizados com base nos seus interesses.</p>
                </div>
            </div>

            <!-- 5. Uso das Informações -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">5</div>
                    <h2>Uso das Informações</h2>
                </div>
                <p>As suas informações são utilizadas para:</p>
                <ul>
                    <li>Personalizar a sua experiência na plataforma.</li>
                    <li>Gerir o sistema de ranking e competições.</li>
                    <li>Enviar notificações sobre interações (seguidores, comentários).</li>
                    <li>Garantir a segurança da conta e prevenir fraudes.</li>
                    <li>Exibir publicidade relevante através do Google AdSense para financiar a plataforma.</li>
                    <li>Melhorar os nossos serviços com base em análises de utilização.</li>
                    <li>Cumprir obrigações legais quando aplicável.</li>
                </ul>
            </div>

            <!-- 6. Partilha de Dados -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">6</div>
                    <h2>Partilha de Dados com Terceiros</h2>
                </div>
                <p>Partilhamos informações com terceiros nas seguintes situações:</p>
                <ul>
                    <li>Quando exigido por lei ou autoridades judiciais.</li>
                    <li>Para proteger os direitos e segurança da plataforma e utilizadores.</li>
                    <li>Com serviços de infraestrutura (ex: Cloudflare R2 para armazenamento de vídeos) estritamente necessários para o serviço.</li>
                    <li>Com o <strong>Google AdSense</strong> para fins de publicidade, conforme descrito na secção 4 desta política.</li>
                </ul>
                <p>O MyTube <strong>não vende os seus dados pessoais</strong> a terceiros para fins que não estejam descritos nesta política.</p>
            </div>

            <!-- 7. Os Seus Direitos -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">7</div>
                    <h2>Os Seus Direitos</h2>
                </div>
                <p>Como utilizador, tem o direito de:</p>
                <ul>
                    <li>Aceder e atualizar os seus dados nas definições de perfil.</li>
                    <li>Solicitar a eliminação total da sua conta e dados associados.</li>
                    <li>Revogar o acesso de aplicações de terceiros (como o Google) através das definições da sua conta Google.</li>
                    <li>Desativar anúncios personalizados através das <a href="https://www.google.com/settings/ads" target="_blank" class="inline-link">definições de anúncios da Google</a>.</li>
                    <li>Gerir cookies nas definições do seu navegador.</li>
                </ul>
            </div>

            <!-- 8. Retenção de Dados -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">8</div>
                    <h2>Retenção de Dados</h2>
                </div>
                <p>Mantemos os seus dados enquanto a sua conta estiver ativa. Após a eliminação da conta, os dados pessoais são removidos dos nossos servidores num prazo de 30 dias, exceto quando obrigados por lei a retê-los por mais tempo.</p>
            </div>

            <!-- 9. Segurança -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">9</div>
                    <h2>Segurança dos Dados</h2>
                </div>
                <p>Implementamos medidas técnicas e organizacionais para proteger os seus dados contra acesso não autorizado, alteração, divulgação ou destruição. Estas medidas incluem:</p>
                <ul>
                    <li>Transmissão de dados encriptada via HTTPS/TLS.</li>
                    <li>Proteção contra CSRF (Cross-Site Request Forgery).</li>
                    <li>Armazenamento seguro de passwords com hash.</li>
                    <li>Rate limiting para prevenir abusos.</li>
                </ul>
                <p>Nenhum sistema é 100% seguro. Em caso de violação de dados que afete os seus direitos, será notificado de acordo com a legislação aplicável.</p>
            </div>

            <!-- 10. Contacto -->
            <div class="terms-section">
                <div class="section-header">
                    <div class="section-number">10</div>
                    <h2>Contacto</h2>
                </div>
                <p>Se tiver dúvidas sobre esta Política de Privacidade, questões sobre os seus dados, ou quiser exercer algum dos seus direitos, pode contactar-nos através de:</p>
                <ul>
                    <li><strong>Email:</strong> <a href="mailto:mytubeao@gmail.com" class="inline-link">mytubeao@gmail.com</a></li>
                    <li><strong>Formulário:</strong> <a href="/contacto.php" class="inline-link">mytube.social/contacto.php</a></li>
                </ul>
                <p>Respondemos a todos os pedidos relacionados com privacidade em até <strong>15 dias úteis</strong>.</p>
            </div>

        </div>
    </div>

    <div class="page-footer">
        <div class="footer-links">
            <a href="/">Início</a>
            <a href="/sobre.php">Sobre o MyTube</a>
            <a href="/contacto.php">Contacto</a>
            <a href="/termos.php">Termos de Uso</a>
        </div>
        <p>&copy; <?php echo date('Y'); ?> MyTube &bull; <a href="mailto:mytubeao@gmail.com">mytubeao@gmail.com</a></p>
    </div>

</body>
</html>
