<?php
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';
ensureUserData();

if (!isLoggedIn()) {
    redirect('login.php');
}

// Buscar escola do usuário logado
$stmt = $pdo->prepare("SELECT u.school_id, s.name AS school_name, s.short_name AS school_short 
    FROM users u LEFT JOIN schools s ON u.school_id = s.id WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userData = $stmt->fetch();
$userSchoolId = $userData['school_id'] ?? null;
$userSchoolName = $userData['school_name'] ?? null;

// Verificar admin via RBAC (função centralizada)
$is_admin = isAdminUser();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#111111">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>🏆 Rankings - MyTube</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/tiktok.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/ranking.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php echo r2_js_config(); ?>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body class="ranking-body">
    <!-- Header -->
    <header class="tiktok-header ranking-header">
        <button class="header-btn" onclick="window.location.href='index.php'" title="Voltar">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="ranking-header-title">
            <i class="fas fa-trophy"></i> Rankings
        </div>
        <div class="header-actions">
            <button class="header-btn" onclick="openSearchModal()" title="Pesquisar">
                <i class="fas fa-search"></i>
            </button>
            <?php 
            $profile_pic = $_SESSION['profile_picture'] ?? null;
            $profile_pic_path = avatar_url($profile_pic);
            ?>
            <button class="header-btn profile-btn" onclick="window.location.href='profile.php'" title="Perfil">
                <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Perfil" class="header-profile-pic">
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main class="ranking-main">
        
        <!-- Meu Ranking Card -->
        <section class="my-rank-card" id="myRankCard">
            <div class="my-rank-loading">
                <div class="spinner-small"></div>
            </div>
        </section>

        <!-- School Selector (se não tem escola) -->
        <?php if (!$userSchoolId): ?>
        <section class="school-select-banner" id="schoolSelectBanner">
            <div class="school-select-icon">🏫</div>
            <h3>Selecione sua escola!</h3>
            <p>Represente sua escola e ajude ela a subir no ranking</p>
            <button class="btn-select-school" onclick="openSchoolSelector()">
                <i class="fas fa-graduation-cap"></i> Escolher Escola
            </button>
        </section>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="ranking-tabs">
            <button class="ranking-tab active" data-tab="dominant" onclick="switchTab('dominant')">
                <i class="fas fa-crown"></i>
                <span>Destaque</span>
            </button>
            <button class="ranking-tab" data-tab="creators" onclick="switchTab('creators')">
                <i class="fas fa-star"></i>
                <span>Criadores</span>
            </button>
            <button class="ranking-tab" data-tab="schools" onclick="switchTab('schools')">
                <i class="fas fa-school"></i>
                <span>Escolas</span>
            </button>
            <?php if ($userSchoolId): ?>
            <button class="ranking-tab" data-tab="myschool" onclick="switchTab('myschool')">
                <i class="fas fa-users"></i>
                <span>Minha Escola</span>
            </button>
            <?php endif; ?>
        </div>

        <!-- Tab Content: Escola Dominante -->
        <div class="ranking-tab-content active" id="tab-dominant">
            <div class="dominant-section">
                
                <!-- ══ BEST MYTUBER DA SEMANA ══ -->
                <div id="bestMytuberSection" class="best-mytuber-section" style="display:none;">
                    <!-- Preenchido via JS -->
                </div>
                
                <div class="section-header-label">
                    <i class="fas fa-fire"></i> Escola Dominante da Semana
                </div>
                <div id="dominantSchoolBanner" class="dominant-banner loading-placeholder">
                    <div class="spinner-small"></div>
                </div>
                
                <div class="section-header-label" style="margin-top: 24px;">
                    <i class="fas fa-chart-line"></i> Vídeos em Alta
                </div>
                <div id="trendingVideos" class="trending-grid loading-placeholder">
                    <div class="spinner-small"></div>
                </div>
            </div>
        </div>

        <!-- Tab Content: Top Criadores Global -->
        <div class="ranking-tab-content" id="tab-creators">
            <div class="creators-section">
                <!-- Period Filter -->
                <div class="period-filter">
                    <button class="period-btn" data-period="all" onclick="filterPeriod('all')">Todos</button>
                    <button class="period-btn active" data-period="week" onclick="filterPeriod('week')">Semana</button>
                    <button class="period-btn" data-period="month" onclick="filterPeriod('month')">Mês</button>
                </div>
                
                <!-- Top 3 Podium -->
                <div id="podiumSection" class="podium-section loading-placeholder">
                    <div class="spinner-small"></div>
                </div>
                
                <!-- Lista completa -->
                <div id="creatorsTable" class="creators-table">
                    <div class="spinner-small"></div>
                </div>
            </div>
        </div>

        <!-- Tab Content: Top Escolas -->
        <div class="ranking-tab-content" id="tab-schools">
            <div id="schoolsRanking" class="schools-section loading-placeholder">
                <div class="spinner-small"></div>
            </div>
        </div>

        <!-- Tab Content: Minha Escola -->
        <?php if ($userSchoolId): ?>
        <div class="ranking-tab-content" id="tab-myschool">
            <div class="myschool-section">
                <div class="myschool-header">
                    <h2><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($userSchoolName); ?></h2>
                    <p>Top criadores da sua escola</p>
                </div>
                <div id="mySchoolCreators" class="creators-table loading-placeholder">
                    <div class="spinner-small"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <!-- Modal Seletor de Escola -->
    <div class="school-modal" id="schoolModal">
        <div class="school-modal-backdrop" onclick="closeSchoolSelector()"></div>
        <div class="school-modal-content">
            <div class="school-modal-header">
                <h3><i class="fas fa-graduation-cap"></i> Escolha sua Escola</h3>
                <button class="close-modal-btn" onclick="closeSchoolSelector()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="school-search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="schoolSearch" placeholder="Buscar escola..." oninput="filterSchools(this.value)">
            </div>
            <div class="school-list" id="schoolList">
                <div class="spinner-small"></div>
            </div>
        </div>
    </div>

    <!-- Modal Pesquisa Ranking -->
    <div class="ranking-search-modal" id="rankingSearchModal">
        <div class="ranking-search-container">
            <div class="ranking-search-header">
                <button class="ranking-search-back" onclick="closeRankingSearch()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="ranking-search-input-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="rankingSearchInput" placeholder="Pesquisar criadores..." autocomplete="off">
                    <button class="ranking-search-clear" id="rankingSearchClear" style="display:none" onclick="clearRankingSearch()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="ranking-search-results" id="rankingSearchResults">
                <div class="search-placeholder">
                    <i class="fas fa-search"></i>
                    <p>Pesquise por criadores</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Botão Admin: Calcular Best MyTuber (só visível para admin) -->
    <?php if ($is_admin): ?>
    <div id="adminBestMytuberPanel" style="position:fixed;bottom:20px;right:20px;z-index:9999;">
        <button onclick="calcularBestMyTuber()" id="btnCalcBest" style="
            background: linear-gradient(135deg, #FFD700, #f59e0b);
            color: #1a1a2e;
            border: none;
            padding: 14px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(255, 215, 0, 0.4);
            display: flex;
            align-items: center;
            gap: 8px;
        ">
            <i class="fas fa-crown"></i> Calcular Best MyTuber
        </button>
        <div id="calcResult" style="
            display:none;
            margin-top:10px;
            background:#1e293b;
            border:1px solid rgba(255,215,0,0.3);
            border-radius:12px;
            padding:14px;
            max-width:340px;
            max-height:300px;
            overflow-y:auto;
            font-size:12px;
            color:#e2e8f0;
        "></div>
    </div>
    <script>
    function calcularBestMyTuber() {
        const btn = document.getElementById('btnCalcBest');
        const result = document.getElementById('calcResult');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calculando...';
        btn.disabled = true;
        
        fetch('api/calculate_best_mytuber.php?force')
            .then(r => r.json())
            .then(data => {
                result.style.display = 'block';
                if (data.success) {
                    let html = '<div style="color:#22c55e;font-weight:700;margin-bottom:8px;">✅ Calculado!</div>';
                    html += '<div style="color:#94a3b8;">Candidatos: ' + (data.total_candidates || 0) + '</div>';
                    if (data.winners && data.winners.length > 0) {
                        data.winners.forEach(w => {
                            const icon = w.scope === 'global' ? '🔥' : '🏅';
                            html += '<div style="margin-top:6px;padding:6px 8px;background:rgba(255,215,0,0.1);border-radius:8px;">';
                            html += icon + ' <strong>@' + w.username + '</strong> (' + w.scope + ')';
                            html += '<br><span style="color:#FFD700;">Score: ' + w.score + '</span>';
                            html += '</div>';
                        });
                    } else {
                        html += '<div style="color:#f59e0b;margin-top:6px;">Nenhum vencedor (sem atividade)</div>';
                    }
                    html += '<div style="margin-top:8px;color:#64748b;font-size:11px;">Badge visível: ' + (data.badge_visible || '') + '</div>';
                    html += '<button onclick="location.reload()" style="margin-top:10px;background:#3b82f6;color:#fff;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-weight:600;">🔄 Recarregar Página</button>';
                    result.innerHTML = html;
                } else {
                    result.innerHTML = '<div style="color:#ef4444;">❌ ' + (data.error || 'Erro desconhecido') + '</div>';
                }
                btn.innerHTML = '<i class="fas fa-crown"></i> Recalcular Best MyTuber';
                btn.disabled = false;
            })
            .catch(err => {
                result.style.display = 'block';
                result.innerHTML = '<div style="color:#ef4444;">❌ Erro: ' + err.message + '</div>';
                btn.innerHTML = '<i class="fas fa-crown"></i> Recalcular Best MyTuber';
                btn.disabled = false;
            });
    }
    </script>
    <?php endif; ?>

    <script>
        window.currentUserId = <?php echo $_SESSION['user_id']; ?>;
        window.userSchoolId = <?php echo $userSchoolId ? $userSchoolId : 'null'; ?>;
        window.isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        window.serverDay = <?php echo (int)date('j'); ?>;
    </script>
    <?php include 'includes/presence_bootstrap.php'; ?>
    <script src="<?php echo asset('assets/js/ranking.js'); ?>"></script>
</body>
</html>
