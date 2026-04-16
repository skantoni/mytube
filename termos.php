<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termos e Condições - MyTube</title>
    <meta name="description" content="Termos e Condições de Utilização da plataforma MyTube.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.mytube.social/termos.php">
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

        .page-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
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

        .logo-text span {
            color: var(--accent-blue);
        }

        .page-header h1 {
            font-size: clamp(24px, 5vw, 36px);
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
            position: relative;
        }

        .page-header .subtitle {
            margin-top: 10px;
            color: rgba(255,255,255,0.75);
            font-size: 14px;
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

        /* ── Table of contents ── */
        .toc {
            background: #f0f4ff;
            border-left: 4px solid var(--secondary-blue);
            border-radius: 0 8px 8px 0;
            padding: 20px 24px;
            margin-bottom: 40px;
        }

        .toc h3 {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--primary-blue);
            margin-bottom: 12px;
        }

        .toc ol {
            padding-left: 20px;
        }

        .toc ol li {
            margin-bottom: 6px;
        }

        .toc ol li a {
            color: var(--secondary-blue);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }

        .toc ol li a:hover {
            color: var(--primary-blue);
            text-decoration: underline;
        }

        /* ── Sections ── */
        .terms-section {
            margin-bottom: 40px;
            scroll-margin-top: 20px;
        }

        .terms-section:last-child {
            margin-bottom: 0;
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

        .terms-section p {
            font-size: 15px;
            color: #475569;
            margin-bottom: 12px;
        }

        .terms-section p:last-child {
            margin-bottom: 0;
        }

        /* ── Lists ── */
        .terms-list {
            list-style: none;
            padding: 0;
            margin: 10px 0;
        }

        .terms-list li {
            font-size: 15px;
            color: #475569;
            padding: 8px 0 8px 28px;
            border-bottom: 1px solid #f1f5f9;
            position: relative;
        }

        .terms-list li:last-child {
            border-bottom: none;
        }

        .terms-list li::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 17px;
            width: 8px;
            height: 8px;
            background: var(--secondary-blue);
            border-radius: 50%;
        }

        /* ── Highlight box ── */
        .highlight-box {
            background: linear-gradient(135deg, #eff6ff, #f0fdff);
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 16px 20px;
            margin: 16px 0;
        }

        .highlight-box p {
            margin: 0;
            color: #1e40af;
            font-size: 14px;
        }

        /* ── Warning box ── */
        .warning-box {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 10px;
            padding: 16px 20px;
            margin: 16px 0;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .warning-box .icon {
            font-size: 18px;
            line-height: 1;
            margin-top: 1px;
        }

        .warning-box p {
            margin: 0;
            color: #92400e;
            font-size: 14px;
        }

        /* ── Divider ── */
        .section-divider {
            border: none;
            border-top: 1px solid #e2e8f0;
            margin: 36px 0;
        }

        /* ── Last updated badge ── */
        .last-updated {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 12px;
            color: #166534;
            font-weight: 500;
            margin-bottom: 28px;
        }

        /* ── Footer ── */
        .page-footer {
            text-align: center;
            padding: 24px 20px 40px;
        }

        .page-footer a {
            color: var(--secondary-blue);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
        }

        .page-footer a:hover {
            color: var(--primary-blue);
        }

        .page-footer .footer-links {
            margin-top: 16px;
            color: var(--gray);
            font-size: 13px;
        }

        /* ── Email link ── */
        .email-link {
            color: var(--secondary-blue);
            font-weight: 600;
            text-decoration: none;
        }

        .email-link:hover {
            text-decoration: underline;
        }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .terms-card {
                padding: 24px 20px;
            }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="page-header">
        <a href="login.php" class="header-logo">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 5v14l11-7z"/>
                </svg>
            </div>
            <span class="logo-text">My<span>Tube</span>_</span>
        </a>
        <h1>Termos e Condições de Utilização</h1>
        <p class="subtitle">Leia com atenção antes de utilizar a plataforma</p>
    </div>

    <!-- Content -->
    <div class="content-wrapper">
        <div class="terms-card">

            <div class="last-updated">
                &#10003; Última atualização: Abril de 2026
            </div>

            <!-- Table of Contents -->
            <div class="toc">
                <h3>Índice</h3>
                <ol>
                    <li><a href="#sec1">Aceitação dos Termos</a></li>
                    <li><a href="#sec2">Descrição do Serviço</a></li>
                    <li><a href="#sec3">Registo e Conta</a></li>
                    <li><a href="#sec4">Conduta do Utilizador</a></li>
                    <li><a href="#sec5">Conteúdo Publicado</a></li>
                    <li><a href="#sec6">Direitos de Propriedade Intelectual</a></li>
                    <li><a href="#sec7">Privacidade e Proteção de Dados</a></li>
                    <li><a href="#sec8">Idade Mínima</a></li>
                    <li><a href="#sec9">Suspensão e Encerramento</a></li>
                    <li><a href="#sec10">Limitação de Responsabilidade</a></li>
                    <li><a href="#sec11">Alterações aos Termos</a></li>
                    <li><a href="#sec12">Lei Aplicável</a></li>
                    <li><a href="#sec13">Contacto</a></li>
                </ol>
            </div>

            <!-- 1 -->
            <div class="terms-section" id="sec1">
                <div class="section-header">
                    <div class="section-number">1</div>
                    <h2>Aceitação dos Termos</h2>
                </div>
                <p>Ao aceder e utilizar o site <strong>MyTube_</strong>, o utilizador concorda em cumprir estes Termos e Condições, bem como todas as leis e regulamentos aplicáveis.</p>
                <div class="warning-box">
                    <span class="icon">&#9888;</span>
                    <p>Caso não concorde com algum dos pontos aqui descritos, deverá cessar imediatamente a utilização da plataforma.</p>
                </div>
                <p>O simples acesso à plataforma implica a aceitação integral e sem reservas de todos os termos e condições aqui estabelecidos.</p>
            </div>

            <hr class="section-divider">

            <!-- 2 -->
            <div class="terms-section" id="sec2">
                <div class="section-header">
                    <div class="section-number">2</div>
                    <h2>Descrição do Serviço</h2>
                </div>
                <p>O <strong>MyTube_</strong> é uma plataforma digital de partilha de conteúdos que permite aos utilizadores:</p>
                <ul class="terms-list">
                    <li>Criar e gerir contas pessoais</li>
                    <li>Publicar e visualizar vídeos, imagens e outros conteúdos multimédia</li>
                    <li>Interagir com outros utilizadores através de comentários, gostos e mensagens</li>
                    <li>Participar em rankings e funcionalidades de destaque</li>
                    <li>Subscrever notificações e seguir outros criadores de conteúdo</li>
                </ul>
                <div class="highlight-box">
                    <p>A plataforma pode ser atualizada, modificada ou interrompida a qualquer momento e sem aviso prévio. O MyTube_ não garante disponibilidade contínua do serviço.</p>
                </div>
            </div>

            <hr class="section-divider">

            <!-- 3 -->
            <div class="terms-section" id="sec3">
                <div class="section-header">
                    <div class="section-number">3</div>
                    <h2>Registo e Conta</h2>
                </div>
                <p>Para aceder a determinadas funcionalidades da plataforma, o utilizador deverá criar uma conta. Ao fazê-lo, compromete-se a:</p>
                <ul class="terms-list">
                    <li>Fornecer informações verdadeiras, precisas e atualizadas durante o registo</li>
                    <li>Manter a confidencialidade das suas credenciais de acesso (utilizador e senha)</li>
                    <li>Não partilhar o acesso à sua conta com terceiros</li>
                    <li>Notificar imediatamente o MyTube_ em caso de suspeita de uso não autorizado da sua conta</li>
                    <li>Ser totalmente responsável por todas as atividades realizadas na sua conta</li>
                </ul>
                <p>A plataforma reserva-se o direito de recusar o registo ou de suspender contas que forneçam informações falsas ou que violem estes termos.</p>
            </div>

            <hr class="section-divider">

            <!-- 4 -->
            <div class="terms-section" id="sec4">
                <div class="section-header">
                    <div class="section-number">4</div>
                    <h2>Conduta do Utilizador</h2>
                </div>
                <p>O utilizador compromete-se a utilizar a plataforma de forma responsável e ética. É expressamente proibido:</p>
                <ul class="terms-list">
                    <li>Publicar conteúdos ilegais, ofensivos, difamatórios, racistas, pornográficos ou prejudiciais</li>
                    <li>Violar direitos de terceiros, incluindo direitos de autor, direitos de imagem e privacidade</li>
                    <li>Utilizar a plataforma para fins de fraude, spam, phishing ou atividades maliciosas</li>
                    <li>Tentar aceder a contas de outros utilizadores ou a sistemas da plataforma sem autorização</li>
                    <li>Publicar informações falsas ou enganosas que possam induzir outros utilizadores em erro</li>
                    <li>Assediar, intimidar ou ameaçar outros utilizadores</li>
                    <li>Utilizar scripts, bots ou meios automatizados para interagir com a plataforma sem consentimento expresso</li>
                    <li>Tentar comprometer a segurança, integridade ou disponibilidade da plataforma</li>
                </ul>
                <div class="warning-box">
                    <span class="icon">&#128683;</span>
                    <p>A violação destas regras pode resultar na suspensão imediata da conta e, nos casos mais graves, na comunicação às autoridades competentes.</p>
                </div>
            </div>

            <hr class="section-divider">

            <!-- 5 -->
            <div class="terms-section" id="sec5">
                <div class="section-header">
                    <div class="section-number">5</div>
                    <h2>Conteúdo Publicado</h2>
                </div>
                <p>O utilizador é o único responsável pelo conteúdo que publica na plataforma. Ao publicar:</p>
                <ul class="terms-list">
                    <li>O utilizador declara que detém todos os direitos necessários sobre o conteúdo publicado</li>
                    <li>É concedida ao MyTube_ uma licença não exclusiva, mundial e gratuita para usar, exibir, reproduzir e distribuir esse conteúdo dentro da plataforma</li>
                    <li>O MyTube_ não reivindica propriedade sobre os conteúdos dos utilizadores</li>
                    <li>A plataforma pode remover, sem aviso prévio, qualquer conteúdo que viole estes termos ou a legislação aplicável</li>
                    <li>Conteúdos que envolvam menores de forma inadequada serão removidos e reportados às autoridades</li>
                </ul>
            </div>

            <hr class="section-divider">

            <!-- 6 -->
            <div class="terms-section" id="sec6">
                <div class="section-header">
                    <div class="section-number">6</div>
                    <h2>Direitos de Propriedade Intelectual</h2>
                </div>
                <p>Todos os elementos constituintes do MyTube_, incluindo mas não se limitando a logótipo, design, interface, software, bases de dados, textos e funcionalidades, são propriedade exclusiva do MyTube_ ou dos seus licenciadores e estão protegidos pela legislação aplicável em matéria de propriedade intelectual.</p>
                <p>É expressamente proibida a reprodução, distribuição, modificação ou utilização comercial de qualquer elemento da plataforma sem autorização prévia e escrita do MyTube_.</p>
            </div>

            <hr class="section-divider">

            <!-- 7 -->
            <div class="terms-section" id="sec7">
                <div class="section-header">
                    <div class="section-number">7</div>
                    <h2>Privacidade e Proteção de Dados</h2>
                </div>
                <p>O MyTube_ valoriza a privacidade dos seus utilizadores. Os dados pessoais recolhidos são utilizados exclusivamente para o funcionamento da plataforma e melhoria do serviço.</p>
                <ul class="terms-list">
                    <li>Os dados não são vendidos nem cedidos a terceiros para fins comerciais</li>
                    <li>O utilizador pode solicitar a eliminação da sua conta e dados associados através do email oficial</li>
                    <li>São aplicadas medidas de segurança técnicas e organizacionais para proteger os dados</li>
                    <li>Cookies e tecnologias semelhantes podem ser utilizados para melhorar a experiência do utilizador</li>
                    <li>Dados de utilização podem ser analisados de forma agregada e anónima para fins estatísticos</li>
                </ul>
                <div class="highlight-box">
                    <p>Ao utilizar o MyTube_, o utilizador consente com o tratamento dos seus dados pessoais de acordo com as práticas descritas nesta secção e em conformidade com a legislação de proteção de dados aplicável.</p>
                </div>
            </div>

            <hr class="section-divider">

            <!-- 8 -->
            <div class="terms-section" id="sec8">
                <div class="section-header">
                    <div class="section-number">8</div>
                    <h2>Idade Mínima</h2>
                </div>
                <p>O MyTube_ destina-se exclusivamente a utilizadores com <strong>13 anos de idade ou mais</strong>. Ao criar uma conta, o utilizador declara ter a idade mínima exigida.</p>
                <p>Contas de utilizadores que se verifique terem menos de 13 anos serão eliminadas sem aviso prévio. Os pais e encarregados de educação são encorajados a supervisionar a utilização da plataforma por menores.</p>
            </div>

            <hr class="section-divider">

            <!-- 9 -->
            <div class="terms-section" id="sec9">
                <div class="section-header">
                    <div class="section-number">9</div>
                    <h2>Suspensão e Encerramento</h2>
                </div>
                <p>O MyTube_ reserva-se o direito de, a qualquer momento e sem aviso prévio:</p>
                <ul class="terms-list">
                    <li>Suspender temporariamente contas que violem estes termos</li>
                    <li>Eliminar permanentemente contas com infrações graves ou reiteradas</li>
                    <li>Remover conteúdos que violem as regras da plataforma ou a legislação vigente</li>
                    <li>Interromper o serviço total ou parcialmente para manutenção ou melhorias</li>
                </ul>
                <p>O utilizador pode encerrar a sua conta a qualquer momento através das definições de conta ou por contacto direto com o suporte.</p>
            </div>

            <hr class="section-divider">

            <!-- 10 -->
            <div class="terms-section" id="sec10">
                <div class="section-header">
                    <div class="section-number">10</div>
                    <h2>Limitação de Responsabilidade</h2>
                </div>
                <p>O MyTube_ é disponibilizado "tal como está" e "conforme disponível", sem garantias de qualquer tipo. Na máxima extensão permitida pela lei aplicável, o MyTube_ não se responsabiliza por:</p>
                <ul class="terms-list">
                    <li>Conteúdos publicados por utilizadores e quaisquer danos deles decorrentes</li>
                    <li>Perdas de dados, interrupções de serviço ou falhas técnicas</li>
                    <li>Danos diretos, indiretos, incidentais ou consequentes resultantes do uso da plataforma</li>
                    <li>Ações de terceiros que possam afetar a segurança ou disponibilidade do serviço</li>
                    <li>Links externos que possam estar presentes na plataforma</li>
                </ul>
            </div>

            <hr class="section-divider">

            <!-- 11 -->
            <div class="terms-section" id="sec11">
                <div class="section-header">
                    <div class="section-number">11</div>
                    <h2>Alterações aos Termos</h2>
                </div>
                <p>O MyTube_ pode atualizar os presentes Termos e Condições a qualquer momento. As alterações entram em vigor imediatamente após a sua publicação nesta página.</p>
                <p>Recomenda-se que o utilizador revise regularmente esta página para se manter informado. A continuação da utilização da plataforma após a publicação de alterações constitui a aceitação dos novos termos.</p>
            </div>

            <hr class="section-divider">

            <!-- 12 -->
            <div class="terms-section" id="sec12">
                <div class="section-header">
                    <div class="section-number">12</div>
                    <h2>Lei Aplicável</h2>
                </div>
                <p>Os presentes Termos e Condições são regidos pelas leis do país onde o serviço opera. Quaisquer disputas decorrentes da utilização da plataforma serão submetidas à jurisdição competente do referido país.</p>
                <p>Caso alguma disposição destes Termos seja considerada inválida ou inaplicável, as restantes disposições permanecem em pleno vigor.</p>
            </div>

            <hr class="section-divider">

            <!-- 13 -->
            <div class="terms-section" id="sec13">
                <div class="section-header">
                    <div class="section-number">13</div>
                    <h2>Contacto</h2>
                </div>
                <p>Para questões relacionadas com estes Termos, pedidos de remoção de dados, suporte técnico ou qualquer outra dúvida, o utilizador pode contactar a equipa do MyTube_ através do email oficial:</p>
                <div class="highlight-box">
                    <p>&#128231;&nbsp; <a href="mailto:mytubeao@gmail.com" class="email-link">mytubeao@gmail.com</a></p>
                </div>
                <p>Faremos o possível para responder a todas as mensagens no prazo de 5 dias úteis.</p>
            </div>

        </div><!-- /terms-card -->
    </div><!-- /content-wrapper -->

    <!-- Footer -->
    <div class="page-footer">
        <a href="login.php">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Voltar ao MyTube_
        </a>
        <p class="footer-links">
            &copy; <?php echo date('Y'); ?> MyTube_ &mdash; Todos os direitos reservados
        </p>
    </div>

</body>
</html>
