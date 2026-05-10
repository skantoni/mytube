<?php
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';

// Garantir que os dados do usuário estão carregados na sessão
ensureUserData();

// Auto-criar tabela de histórico de pesquisa (caso não exista)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_history (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            query       VARCHAR(255) NOT NULL,
            searched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_searched (user_id, searched_at),
            INDEX idx_cleanup       (searched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) { /* tabela já existe */ }


// AJAX Feed System - Videos will be loaded dynamically via JavaScript

// Verificar modo de feed: normal ou perfil de usuário
$feed_mode = 'normal';
$profile_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$start_video_id = isset($_GET['video_id']) ? (int)$_GET['video_id'] : 0;
$highlight_comment_id = isset($_GET['comment_id']) ? (int)$_GET['comment_id'] : 0;
$profile_user = null;

// Se há user_id, é modo perfil
if ($profile_user_id > 0 && isLoggedIn()) {
    $feed_mode = 'profile';
    
    // Buscar dados do usuário do perfil
    $stmt = $pdo->prepare("
        SELECT id, username, full_name, profile_picture, is_verified, videos_count
        FROM users WHERE id = ?
    ");
    $stmt->execute([$profile_user_id]);
    $profile_user = $stmt->fetch();
    
    if (!$profile_user) {
        $feed_mode = 'normal';
        $profile_user_id = 0;
    }
}

// Verificar se o usuário logado é admin
$is_admin = isLoggedIn() && isAdminUser();
$force_splash = isset($_GET['splash']) && $_GET['splash'] === '1';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>MyTube - Sua rede social de vídeos</title>
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/tiktok.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/comments.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/feed.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/splash.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- SEO & Social Media Meta Tags -->
    <?php include __DIR__ . '/includes/seo_meta.php'; ?>
    
    <link rel="canonical" href="https://www.mytube.social/">
    <?php echo r2_js_config(); ?>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body>
    <div class="splash-screen" id="splashScreen" aria-label="Tela de abertura">
        <img src="assets/images/logo.png" alt="MyTube" class="splash-logo">
    </div>

    <?php 
    $guest_explore = (!isLoggedIn() && isset($_GET['explore']));
    ?>
    <?php if (!isLoggedIn() && !$guest_explore): ?>
        <!-- Se não estiver logado, mostrar página de boas-vindas -->
        <?php include 'includes/header.php'; ?>
        <!-- Link para o robô do Google encontrar facilmente -->
        <a href="<?php echo SITE_URL; ?>/privacidade.php" style="display:none;">Política de Privacidade</a>
        <main class="main-content">
            <section class="hero-section">
                <div class="hero-content">
                    <h1 class="hero-title">
                        Bem-vindo ao <span  class="gradient-text">MyTube</span>
                    </h1>
                    <p class="hero-subtitle">
                        Aqui os criadores competem! Descubra talentos, participe de competições escolares e conecte-se com criadores de Angola.
                    </p>
                    <div class="hero-actions">
                        <a href="login.php?register=1" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus"></i>
                            Criar Conta Grátis
                        </a>
                        <a href="login.php" class="btn btn-secondary btn-lg">
                            <i class="fas fa-sign-in-alt"></i>
                            Já tenho conta
                        </a>
                    </div>
                </div>
            </section>
            
            <footer class="landing-footer">
                <div class="footer-links">
                    <a href="<?php echo SITE_URL; ?>/termos.php">Termos de Uso</a>
                    <span class="footer-divider">&bull;</span>
                    <a href="<?php echo SITE_URL; ?>/privacidade.php">Política de Privacidade</a>
                </div>
                <p class="footer-copy">&copy; <?php echo date('Y'); ?> MyTube. Todos os direitos reservados.</p>
            </footer>
        </main>
    <?php elseif ($guest_explore): ?>
        <!-- Feed para visitantes (modo explorar) -->
        <header class="tiktok-header">
            <div onclick="window.location.href='index.php'" class="tiktok-logo">MyTube</div>
            <div class="header-actions">
                <a href="login.php" class="btn btn-primary" style="padding: 6px 16px; font-size: 14px; border-radius: 20px;">
                    <i class="fas fa-sign-in-alt"></i> Entrar
                </a>
            </div>
        </header>
        
        <!-- Container TikTok -->
        <div class="tiktok-container" id="videoContainer">
            <div class="video-item initial-loading" id="initialLoading">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Carregando vídeos...
                </div>
                <h3>Preparando seu feed</h3>
                <p>Buscando os melhores vídeos para você</p>
            </div>
        </div>
        
        <!-- Botões de navegação desktop -->
        <div class="desktop-nav-buttons" id="desktopNavButtons">
            <button class="desktop-nav-btn" id="navPrevBtn" title="Vídeo anterior" disabled>
                <i class="fas fa-chevron-up"></i>
            </button>
            <button class="desktop-nav-btn" id="navNextBtn" title="Próximo vídeo">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        
        <!-- Loading indicator for pagination -->
        <div class="loading-indicator" id="loadingIndicator" style="display: none;">
            <div class="loading-spinner">
                <div class="spinner"></div>
                Carregando mais vídeos...
            </div>
        </div>

        <!-- Modal de login obrigatório -->
        <div id="guestLoginModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:10000; justify-content:center; align-items:center;">
            <div style="background:#1a1a2e; border-radius:16px; padding:32px 24px; max-width:340px; width:90%; text-align:center; box-shadow:0 8px 32px rgba(0,0,0,0.5);">
                <i class="fas fa-lock" style="font-size:40px; color:#00d4ff; margin-bottom:16px;"></i>
                <h3 style="color:#fff; margin-bottom:8px;">Faça login</h3>
                <p style="color:#aaa; margin-bottom:24px; font-size:14px;">Para interagir com vídeos, você precisa estar logado.</p>
                <div style="display:flex; gap:12px; justify-content:center;">
                    <a href="login.php" style="background:linear-gradient(135deg,#00d4ff,#0099ff); color:#fff; padding:10px 24px; border-radius:24px; text-decoration:none; font-weight:600;">Entrar</a>
                    <button onclick="document.getElementById('guestLoginModal').style.display='none'" style="background:#333; color:#fff; padding:10px 24px; border-radius:24px; border:none; cursor:pointer;">Fechar</button>
                </div>
            </div>
        </div>

        <!-- Sidebar de Comentários (view-only for guest) -->
        <div class="comments-sidebar" id="commentsSidebar">
            <div class="comments-header">
                <h3></h3>
                <button class="close-comments" id="closeComments">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="comments-content">
                <div class="comments-list" id="commentsList"></div>
                <div class="login-prompt">
                    <p>Faça login para comentar</p>
                    <a href="login.php" class="login-link">Entrar</a>
                </div>
            </div>
        </div>
        
        <!-- Modal de Comentários - Mobile -->
        <div class="comments-modal" id="commentsModal">
            <div class="modal-backdrop"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-handle"></div>
                    <h3></h3>
                    <button class="close-modal" id="closeModal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="comments-list" id="commentsListMobile"></div>
                </div>
                <div class="modal-footer">
                    <div class="login-prompt">
                        <p>Faça login para comentar</p>
                        <a href="login.php" class="login-link">Entrar</a>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Guest mode configuration
            window.isGuestMode = true;
            window.isAdmin = false;
            window.currentUserId = 0;
            window.feedMode = 'normal';
            window.profileUserId = 0;
            window.startVideoId = 0;
            window.highlightCommentId = 0;
            
            function showGuestLoginModal() {
                document.getElementById('guestLoginModal').style.display = 'flex';
            }
        </script>
        <script src="<?php echo asset('assets/js/network-quality.js'); ?>"></script>
        <script src="<?php echo asset('assets/js/modal-mobile-helper.js'); ?>"></script>
        <script src="<?php echo asset('assets/js/tiktok.js'); ?>"></script>
        <script src="<?php echo asset('assets/js/comments-new.js'); ?>"></script>
        <script src="<?php echo asset('assets/js/feed-ajax.js'); ?>"></script>
        <script>
            // Initialize guest feed
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof FeedManager === 'undefined') {
                    console.error('FeedManager não encontrado!');
                    return;
                }
                const container = document.getElementById('videoContainer');
                if (!container) return;
                
                const feedManager = new FeedManager();
                feedManager.init();
                window.feedManager = feedManager;
            });
        </script>
    <?php else: ?>
        <!-- Layout TikTok para usuários logados -->
        <!-- Header fixo -->
        <header class="tiktok-header">
            <?php if ($feed_mode === 'profile' && $profile_user): ?>
                <!-- Header modo perfil -->
                <button class="header-btn" onclick="try{localStorage.setItem('mytube_restore_feed','1')}catch(e){} history.back()" title="Voltar">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <a href="perfil.php?id=<?php echo $profile_user_id; ?>" class="profile-header-info">
                    <img src="<?php echo htmlspecialchars(avatar_url($profile_user['profile_picture'] ?? null)); ?>" 
                         alt="<?php echo htmlspecialchars($profile_user['username']); ?>" 
                         class="profile-header-avatar">
                    <div class="profile-header-text">
                        <span class="profile-header-name">
                            @<?php echo htmlspecialchars($profile_user['username']); ?>
                            <?php if ($profile_user['is_verified']): ?>
                                <i class="fas fa-check-circle verified-badge"></i>
                            <?php endif; ?>
                        </span>
                        <span class="profile-header-videos"><?php echo $profile_user['videos_count']; ?> vídeos</span>
                    </div>
                </a>
            <?php else: ?>
                <!-- Header normal -->
                <div onclick="localStorage.removeItem('mytube_feed_state'); localStorage.removeItem('mytube_restore_feed'); window.location.href='index.php?t=' + Date.now()" class="tiktok-logo">MyTube</div>
            <?php endif; ?>
            <div class="header-actions">
                <button class="header-btn" onclick="openSearchModal()" title="Pesquisar">
                    <i class="fas fa-search"></i>
                </button>
                <button class="header-btn" onclick="window.location.href='ranking.php'" title="Rankings">
                    <i class="fas fa-sort"></i>
                </button>
                <?php if ($is_admin): ?>
                <button class="header-btn" onclick="window.location.href='boosted_videos.php'" title="Painel Boosted">
                    <i class="fas fa-bolt"></i>
                </button>
                <?php endif; ?>
                <?php 
                $profile_pic_path = avatar_url($_SESSION['profile_picture'] ?? null);
                ?>
                <button class="header-btn profile-btn" onclick="toggleProfile()" title="Perfil">
                    <img src="<?php echo htmlspecialchars($profile_pic_path); ?>" alt="Perfil" class="header-profile-pic">
                    <span class="notification-dot" id="notificationDot" style="display: none;"></span>
                </button>
            </div>
        </header>
        
        <!-- Container TikTok -->
        <div class="tiktok-container" id="videoContainer">
            <!-- Initial loading state -->
            <div class="video-item initial-loading" id="initialLoading">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    Carregando vídeos...
                </div>
                <h3>Preparando seu feed</h3>
                <p>Buscando os melhores vídeos para você</p>
            </div>
        </div>
        
        <!-- Botões de navegação desktop -->
        <div class="desktop-nav-buttons" id="desktopNavButtons">
            <button class="desktop-nav-btn" id="navPrevBtn" title="Vídeo anterior" disabled>
                <i class="fas fa-chevron-up"></i>
            </button>
            <button class="desktop-nav-btn" id="navNextBtn" title="Próximo vídeo">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        
        <!-- Loading indicator for pagination -->
        <div class="loading-indicator" id="loadingIndicator" style="display: none;">
            <div class="loading-spinner">
                <div class="spinner"></div>
                Carregando mais vídeos...
            </div>
        </div>
    
    <!-- Menu de compartilhamento -->
    <div class="share-menu" id="shareMenu">
        <h3>Compartilhar</h3>
        <div class="share-options">
            <div class="share-option" onclick="shareToWhatsApp()">
                <i class="fab fa-whatsapp"></i>
                <span>WhatsApp</span>
            </div>
            <div class="share-option" onclick="shareToFacebook()">
                <i class="fab fa-facebook"></i>
                <span>Facebook</span>
            </div>
            <div class="share-option" onclick="shareToChat()">
                <i class="fas fa-comment-dots"></i>
                <span>Chat</span>
            </div>
            <div class="share-option" onclick="copyLink()">
                <i class="fas fa-link"></i>
                <span>Copiar Link</span>
            </div>
        </div>
    </div>
    
    <!-- Modal: Compartilhar no Chat -->
    <div class="chat-share-overlay" id="chatShareOverlay" style="display:none;" onclick="if(event.target===this)closeChatShareModal()">
        <div class="chat-share-modal">
            <div class="chat-share-header">
                <h3><i class="fas fa-paper-plane"></i> Enviar no Chat</h3>
                <button class="chat-share-close" onclick="closeChatShareModal()"><i class="fas fa-times"></i></button>
            </div>
            <input type="text" class="chat-share-search" id="chatShareSearch" placeholder="Buscar conversa..." autocomplete="off">
            <div class="chat-share-list" id="chatShareList">
                <div class="chat-share-loading"><div class="spinner-small"></div></div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Pesquisa -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal-content">
            <div class="search-header">
                <div class="search-input-wrapper">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" placeholder="Pesquisar usuários, vídeos ou #hashtags..." autocomplete="off">
                    <button class="search-clear" id="searchClear" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <button class="search-cancel" onclick="closeSearchModal()">Cancelar</button>
            </div>
            <div class="search-results" id="searchResults">
                <div class="search-placeholder">
                    <i class="fas fa-search"></i>
                    <p>Pesquise por usuários, vídeos ou hashtags</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmação para deletar vídeo -->
    <div class="delete-modal" id="deleteModal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <h3>⚠️ Confirmar Exclusão</h3>
            </div>
            <div class="delete-modal-body">
                <p>Tem certeza que deseja apagar este vídeo?</p>
                <p><small>Esta ação não pode ser desfeita. O vídeo e todos os seus comentários e curtidas serão permanentemente removidos.</small></p>
            </div>
            <div class="delete-modal-footer">
                <button class="btn-cancel" onclick="closeDeleteModal()">Cancelar</button>
                <button class="btn-delete" id="confirmDeleteBtn">
                    <i class="fas fa-trash"></i> Apagar
                </button>
            </div>
        </div>
    </div>
    
    <!-- Sidebar de Comentários - Desktop -->
    <div class="comments-sidebar" id="commentsSidebar">
        <div class="comments-header">
            <h3></h3>
            <button class="close-comments" id="closeComments">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="comments-content">
            <div class="comments-list" id="commentsList">
                <!-- Comentários serão carregados aqui via JavaScript -->
            </div>
            
            <?php if (isLoggedIn()): ?>
            <div class="comment-form">
                <div class="comment-input-container">
                    <div class="comment-input-wrapper">
                        <button type="button" class="comment-emoji-btn" data-target-input="commentInput" aria-label="Abrir emojis">
                            <i class="fas fa-smile"></i>
                        </button>
                        <textarea id="commentInput" placeholder="Mete dica..." maxlength="500"></textarea>
                        <button id="submitComment" class="submit-comment">
                            <i class="fas fa-arrow-up"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="login-prompt">
                <p>Faça login para comentar</p>
                <a href="login.php" class="login-link">Entrar</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal de Comentários - Mobile -->
    <div class="comments-modal" id="commentsModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-handle"></div>
                <h3></h3>
                <button class="close-modal" id="closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="modal-body">
                <div class="comments-list" id="commentsListMobile">
                    <!-- Comentários móvel -->
                </div>
            </div>
            
            <?php if (isLoggedIn()): ?>
            <div class="modal-footer">
                <div class="comment-input-container">
                    <div class="comment-input-wrapper">
                        <button type="button" class="comment-emoji-btn" data-target-input="commentInputMobile" aria-label="Abrir emojis">
                            <i class="fas fa-smile"></i>
                        </button>
                        <textarea id="commentInputMobile" placeholder="Mete dica..." maxlength="500"></textarea>
                        <button id="submitCommentMobile" class="submit-comment">
                            <i class="fas fa-arrow-up"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="modal-footer">
                <div class="login-prompt">
                    <p>Faça login para comentar</p>
                    <a href="login.php" class="login-link">Entrar</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (isLoggedIn()): ?>
    <script>
        // Pass PHP variables to JavaScript ANTES de carregar os scripts
        window.isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        window.currentUserId = <?php echo $_SESSION['user_id']; ?>;
        window.feedMode = '<?php echo $feed_mode; ?>';
        window.profileUserId = <?php echo $profile_user_id; ?>;
        window.startVideoId = <?php echo $start_video_id; ?>;
        window.highlightCommentId = <?php echo $highlight_comment_id; ?>;
    </script>
    <?php endif; ?>
    
   <!-- <script src="<?php echo asset('assets/js/feed.js'); ?>"></script> -->
    <script src="<?php echo asset('assets/js/network-quality.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/modal-mobile-helper.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/tiktok.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/comments-new.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/video-delete.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/feed-ajax.js'); ?>"></script>
    <!-- Sistema de Sincronização de Likes em Tempo Real -->
    <script src="<?php echo asset('assets/js/like-sync.js'); ?>"></script>
    <!-- Sistema de Sincronização de Comentários em Tempo Real -->
    <script src="<?php echo asset('assets/js/comment-sync.js'); ?>"></script>
    <?php include 'includes/presence_bootstrap.php'; ?>
    <?php if (isLoggedIn()): ?>
    <script>
        
        // Initialize AJAX feed system
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof FeedManager === 'undefined') {
                console.error('FeedManager não encontrado!');
                return;
            }
            
            const container = document.getElementById('videoContainer');
            if (!container) {
                console.error('Container videoContainer não encontrado!');
                return;
            }
            
            const feedManager = new FeedManager();
            feedManager.init();
            window.feedManager = feedManager;
            
            // Se há um comentário para destacar, abrir modal após carregar o vídeo
            if (window.highlightCommentId && window.startVideoId) {
                // Escutar evento quando os vídeos são carregados
                const openCommentsHandler = function(e) {
                    if (e.detail && e.detail.isFirstLoad) {
                        // Aguardar o DOM estar pronto
                        setTimeout(() => {
                            if (window.commentsSystem) {
                                window.commentsSystem.openCommentsWithHighlight(window.startVideoId, window.highlightCommentId);
                            }
                        }, 500);
                        // Remover listener após usar
                        window.removeEventListener('videosLoaded', openCommentsHandler);
                    }
                };
                window.addEventListener('videosLoaded', openCommentsHandler);
            }
        });
    </script>
    <script src="<?php echo asset('assets/js/notifications.js'); ?>"></script>
    <?php endif; ?>
    <?php endif; /* end main if/elseif/else */ ?>
    <script>
        (function() {
            const splash = document.getElementById('splashScreen');
            if (!splash) {
                return;
            }

            const forceSplash = <?php echo $force_splash ? 'true' : 'false'; ?>;
            const splashStorageKey = 'mytube_splash_shown';
            const splashVisibleMs = 500;
            const splashExitMs = 1900;
            const hasShownSplash = sessionStorage.getItem(splashStorageKey) === '1';

            if (!forceSplash && hasShownSplash) {
                splash.remove();
                return;
            }

            sessionStorage.setItem(splashStorageKey, '1');
            document.body.classList.add('splash-active');

            setTimeout(function() {
                splash.classList.add('hidden');
                setTimeout(function() {
                    document.body.classList.remove('splash-active');
                    splash.remove();
                }, splashExitMs);
            }, splashVisibleMs);

            if (forceSplash && window.history.replaceState) {
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('splash');
                window.history.replaceState({}, document.title, cleanUrl.toString());
            }
        })();
    </script>
</body>
</html>