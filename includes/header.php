<?php
// Verificar se o usuário está logado para mostrar o menu apropriado
$user_data = null;
$is_admin_user = false;
if (isLoggedIn()) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch();
    $is_admin_user = isAdminUser();
}

// Carregar configuração R2 para resolução de URLs de vídeo no JS
if (!function_exists('resolve_video_url')) {
    require_once __DIR__ . '/r2_storage.php';
}
echo r2_js_config();
?>

<!-- <head>
    <link rel="shortcut icon" href="../assets/images/logo_icon.png" type="image/x-icon">
</head> -->
<header class="header">
    <div class="header-content">
        <a href="#" class="logo" onclick="sessionStorage.removeItem('mytube_feed_state'); window.location.href='index.php?t=' + Date.now(); return false;">MyTube</a>

        <nav class="nav-menu">
            <?php if (isLoggedIn()): ?>
                <!-- Menu para usuários logados -->
                <a href="index.php" class="nav-item">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>

                <a href="explore.php" class="nav-item">
                    <i class="fas fa-compass"></i>
                    <span>Explorar</span>
                </a>

                <a href="ranking.php" class="nav-item">
                    <i class="fas fa-sort"></i>
                    <span>Rankings</span>
                </a>

                <?php if ($is_admin_user): ?>
                    <a href="boosted_videos.php" class="nav-item">
                        <i class="fas fa-bolt"></i>
                        <span>Boosted</span>
                    </a>
                <?php endif; ?>

                <a href="upload.php" class="nav-item nav-upload">
                    <i class="fas fa-plus-circle"></i>
                    <span>Upload</span>
                </a>

                <a href="chat.php" class="nav-item">
                    <i class="fas fa-comments"></i>
                    <span>Chat</span>
                </a>

                <div class="user-menu">
                    <button class="user-menu-btn" onclick="toggleUserMenu()">
                        <img src="<?php echo htmlspecialchars(avatar_url($user_data['profile_picture'] ?? null)); ?>"
                            alt="Avatar" class="user-avatar">
                        <span class="user-name"><?php echo htmlspecialchars($user_data['username']); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>

                    <div class="user-dropdown" id="userDropdown">
                        <a href="profile.php?user=<?php echo htmlspecialchars($user_data['username'], ENT_QUOTES, 'UTF-8'); ?>" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            Meu Perfil
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            Configurações
                        </a>
                        <?php if ($is_admin_user): ?>
                            <a href="boosted_videos.php" class="dropdown-item">
                                <i class="fas fa-bolt"></i>
                                Painel Boosted
                            </a>
                        <?php endif; ?>
                        <hr class="dropdown-divider">
                        <a href="logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i>
                            Sair
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Menu para visitantes -->
                <a href="index.php?explore=1" class="nav-item">
                    <i class="fas fa-compass"></i>
                    <span>Explorar</span>
                </a>

                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i>
                    Entrar
                </a>
            <?php endif; ?>
        </nav>

        <?php if (isLoggedIn()): ?>
            <!-- Botão mobile menu -->
            <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
        <?php endif; ?>
    </div>

    <?php if (isLoggedIn()): ?>
        <!-- Search overlay (futuro) -->
        <div class="search-overlay" id="searchOverlay">
            <div class="search-container">
                <input type="text" placeholder="Buscar vídeos, usuários..." class="search-input">
                <button class="search-btn">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
    <?php endif; ?>
</header>

<!-- Mobile sidebar -->
<?php if (isLoggedIn()): ?>
    <div class="mobile-sidebar" id="mobileSidebar">
        <div class="sidebar-header">
            <img src="<?php echo htmlspecialchars(avatar_url($user_data['profile_picture'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                alt="Avatar" class="sidebar-avatar">
            <div class="sidebar-user-info">
                <h4><?php echo htmlspecialchars($user_data['full_name']); ?></h4>
                <p>@<?php echo htmlspecialchars($user_data['username']); ?></p>
            </div>
            <button class="sidebar-close" onclick="toggleMobileMenu()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="sidebar-menu">
            <a href="index.php" class="sidebar-item">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>

            <a href="profile.php?user=<?php echo htmlspecialchars($user_data['username'], ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-item">
                <i class="fas fa-user"></i>
                <span>Meu Perfil</span>
            </a>

            <a href="explore.php" class="sidebar-item">
                <i class="fas fa-compass"></i>
                <span>Explorar</span>
            </a>

            <a href="ranking.php" class="sidebar-item">
                <i class="fas fa-sort"></i>
                <span>Rankings</span>
            </a>

            <?php if ($is_admin_user): ?>
                <a href="boosted_videos.php" class="sidebar-item">
                    <i class="fas fa-bolt"></i>
                    <span>Painel Boosted</span>
                </a>
            <?php endif; ?>

            <a href="upload.php" class="sidebar-item">
                <i class="fas fa-plus-circle"></i>
                <span>Criar Vídeo</span>
            </a>

            <a href="chat.php" class="sidebar-item">
                <i class="fas fa-comments"></i>
                <span>Mensagens</span>
            </a>

            <hr class="sidebar-divider">

            <a href="settings.php" class="sidebar-item">
                <i class="fas fa-cog"></i>
                <span>Configurações</span>
            </a>

            <a href="help.php" class="sidebar-item">
                <i class="fas fa-question-circle"></i>
                <span>Ajuda</span>
            </a>

            <a href="logout.php" class="sidebar-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Sair</span>
            </a>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileMenu()"></div>
<?php endif; ?>

<script>
    // Toggle user dropdown menu
    function toggleUserMenu() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('active');
    }

    // Toggle mobile menu
    function toggleMobileMenu() {
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (sidebar && overlay) {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }
    }

    // Fechar dropdown ao clicar fora
    document.addEventListener('click', function(event) {
        const userMenu = document.querySelector('.user-menu');
        const dropdown = document.getElementById('userDropdown');

        if (userMenu && dropdown && !userMenu.contains(event.target)) {
            dropdown.classList.remove('active');
        }
    });

    // Destacar item ativo do menu
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname.split('/').pop() || 'index.php';
        const navItems = document.querySelectorAll('.nav-item, .sidebar-item');

        navItems.forEach(item => {
            const href = item.getAttribute('href');
            if (href && href.includes(currentPath)) {
                item.classList.add('active');
            }
        });
    });
</script>

<!-- Meta tag para informar se o usuário está logado -->
<meta name="user-logged-in" content="<?php echo isLoggedIn() ? 'true' : 'false'; ?>"><?php
                                                                                        // Adicionar data attribute no body também
                                                                                        if (isLoggedIn()) {
                                                                                            echo '<script>document.addEventListener("DOMContentLoaded", () => { document.body.dataset.userLoggedIn = "true"; });</script>';
                                                                                            include __DIR__ . '/presence_bootstrap.php';
                                                                                        }
                                                                                        ?>