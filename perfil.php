<?php
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';

// Verificar se está logado (necessário para algumas funcionalidades)
if (!isLoggedIn()) {
    redirect('login.php');
}

// Obter ID do usuário a ser visualizado
if (isset($_GET['username'])) {
    // Suporte a links de menção: perfil.php?username=XYZ
    $uStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $uStmt->execute([trim($_GET['username'])]);
    $profile_user_id = (int)$uStmt->fetchColumn();
    if (!$profile_user_id) { redirect('index.php'); exit; }
} else {
    $profile_user_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
}
$is_own_profile = ($profile_user_id === $_SESSION['user_id']);

// Buscar dados do usuário
try {
    $user_stmt = $pdo->prepare("
        SELECT 
            u.*,
            u.followers_count,
            u.following_count,
            u.videos_count
        FROM users u 
        WHERE u.id = ?
    ");
    $user_stmt->execute([$profile_user_id]);
    $user_data = $user_stmt->fetch();

    if (!$user_data) {
        redirect('index.php');
        exit;
    }

    // Buscar vídeos do usuário
    $videos_page_limit = 18;
    $videos_stmt = $pdo->prepare("
        SELECT
               v.id,
               v.title,
               v.video_path,
               v.thumbnail_path,
               v.likes_count,
               v.comments_count,
               v.views_count,
               v.created_at
        FROM videos v
        WHERE v.user_id = ? AND v.is_public = 1
        ORDER BY v.created_at DESC
        LIMIT ? OFFSET 0
    ");
    $videos_stmt->bindValue(1, (int)$profile_user_id, PDO::PARAM_INT);
    $videos_stmt->bindValue(2, (int)$videos_page_limit, PDO::PARAM_INT);
    $videos_stmt->execute();
    $user_videos = $videos_stmt->fetchAll();

    $total_user_videos = isset($user_data['videos_count']) ? (int)$user_data['videos_count'] : count($user_videos);
    if ($total_user_videos < count($user_videos)) {
        $total_user_videos = count($user_videos);
    }
    $has_more_videos = $total_user_videos > count($user_videos);

    // Verificar se o usuário logado já segue este perfil
    $is_following = false;
    $follows_you = false;
    if (!$is_own_profile) {
        $follow_stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
        $follow_stmt->execute([$_SESSION['user_id'], $profile_user_id]);
        $is_following = $follow_stmt->fetch() !== false;

        // Verificar se o perfil visitado segue o usuário logado (para exibir "Seguir de volta")
        $follows_you_stmt = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
        $follows_you_stmt->execute([$profile_user_id, $_SESSION['user_id']]);
        $follows_you = $follows_you_stmt->fetch() !== false;
    }

    // Verificar se o usuário logado é admin
    $is_admin = false;
    $admin_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $admin_stmt->execute([$_SESSION['user_id']]);
    $current_user = $admin_stmt->fetch();
    $is_admin = ($current_user['username'] === 'Admin');
    $can_manage_profile_videos = ($is_own_profile || $is_admin);

} catch (Exception $e) {
    $error = 'Erro ao carregar perfil.';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($user_data['username']); ?> - MyTube</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/perfil.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/interactive-buttons.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Meta tag para informar se o usuário está logado -->
    <meta name="user-logged-in" content="<?php echo isLoggedIn() ? 'true' : 'false'; ?>"><?php 
    // Adicionar data attribute no body também
    if (isLoggedIn()) {
        echo '<script>document.addEventListener("DOMContentLoaded", () => { document.body.dataset.userLoggedIn = "true"; });</script>';
    }
    echo r2_js_config();
    ?>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body>
    <!-- Header simples com botão voltar -->
    <header class="profile-header">
        <div class="header-content">
            <button onclick="smartBack()" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </button>
            <script>
            function smartBack() {
                // Sinalizar que queremos restaurar o feed ao voltar
                try { localStorage.setItem('mytube_restore_feed', '1'); } catch(e) {}
                
                var ref = document.referrer || '';
                // Evitar loop: se veio de perfil, profile ou notificações, ir para o feed
                if (!ref || ref.indexOf('chat.php') !== -1 || ref.indexOf('perfil.php') !== -1 || ref.indexOf('profile.php') !== -1 || ref.indexOf('settings.php') !== -1 || ref.indexOf('notification') !== -1) {
                    window.location.href = 'index.php';
                } else {
                    history.back();
                }
            }
            </script>
            <h1>Perfil</h1>
            <?php if ($is_own_profile): ?>
                <a href="profile.php" class="edit-btn" title="Configurações">
                    <i class="fas fa-cog"></i>
                </a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
        </div>
    </header>

    <main class="profile-main">
        <!-- Seção do perfil -->
        <section class="profile-section">
            <div class="profile-info">
                <div class="profile-avatar">
                    <?php 
                    $avatar_pic = $user_data['profile_picture'] ?? 'default.webp';
                    $avatar_path = 'assets/images/avatars/' . $avatar_pic;
                    if (empty($avatar_pic) || !file_exists($avatar_path)) {
                        $avatar_path = 'assets/images/avatars/default.webp';
                    }
                    ?>
                    <img src="<?php echo htmlspecialchars($avatar_path); ?>" 
                         alt="<?php echo htmlspecialchars($user_data['username']); ?>"
                         class="avatar-image"
                         onclick="openPerfilAvatarSheet()" 
                         style="cursor:pointer">
                    <?php if ($user_data['is_verified']): ?>
                        <span class="verified-badge-large">
                            <i class="fas fa-check"></i>
                        </span>
                    <?php endif; ?>
                    <?php 
                    // Badge "Best MyTuber da Semana" — lógica real
                    $now_dt = new DateTime('now', new DateTimeZone('Africa/Luanda'));
                    $now_check = $now_dt->format('Y-m-d H:i:s');
                    $badge_stmt = $pdo->prepare("
                        SELECT scope, school_id FROM best_mytuber_weekly 
                        WHERE user_id = ? AND badge_visible_from <= ? AND badge_visible_until >= ?
                    ");
                    $badge_stmt->execute([$profile_user_id, $now_check, $now_check]);
                    $user_badges = $badge_stmt->fetchAll();
                    $is_best_global = false;
                    $is_best_school = false;
                    foreach ($user_badges as $ub) {
                        if ($ub['scope'] === 'global') $is_best_global = true;
                        if ($ub['scope'] === 'school') $is_best_school = true;
                    }
                    if ($is_best_global): ?>
                        <span class="best-mytuber-badge best-mytuber-global" title="🏆 Best MyTuber Global da Semana">
                            <i class="fas fa-crown"></i>
                        </span>
                    <?php endif; ?>
                    <?php if ($is_best_school): ?>
                        <span class="best-mytuber-badge best-mytuber-school" title="🏅 Best MyTuber da Escola">
                            <i class="fas fa-medal"></i>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="profile-details">
                    <h2 class="profile-name"><?php echo htmlspecialchars($user_data['full_name']); ?></h2>
                    <p class="profile-username">@<?php echo htmlspecialchars($user_data['username']); ?></p>
                    
                    <?php if ($user_data['bio']): ?>
                        <p class="profile-bio"><?php echo renderBioWithMentions($user_data['bio']); ?></p>
                    <?php endif; ?>

                    <!-- Estatísticas -->
                    <div class="profile-stats">
                        <div class="stat stat-clickable" onclick="openTopVideosModal(<?php echo $profile_user_id; ?>)">
                            <span class="stat-number"><?php echo formatNumberShort($user_data['videos_count']); ?></span>
                            <span class="stat-label">Vídeos</span>
                        </div>
                        <div class="stat stat-clickable" onclick="openFollowModal('followers', <?php echo $profile_user_id; ?>)">
                            <span class="stat-number" id="followersCount"><?php echo formatNumberShort($user_data['followers_count']); ?></span>
                            <span class="stat-label">Seguidores</span>
                        </div>
                        <div class="stat stat-clickable" onclick="openFollowModal('following', <?php echo $profile_user_id; ?>)">
                            <span class="stat-number" id="followingCount"><?php echo formatNumberShort($user_data['following_count']); ?></span>
                            <span class="stat-label">Seguindo</span>
                        </div>
                    </div>

                    <!-- Botões de ação -->
                    <div class="profile-actions">
                        <?php if ($is_own_profile): ?>
                            <a href="profile.php" class="btn btn-primary">
                                <i class="fas fa-edit"></i>
                                Editar Perfil
                            </a>
                        <?php else: ?>
                            <button class="btn follow-profile-btn <?php echo $is_following ? 'following' : ''; ?>" 
                                    data-user-follow="<?php echo $profile_user_id; ?>"
                                    data-follows-you="<?php echo $follows_you ? '1' : '0'; ?>">
                                <i class="fas <?php echo $is_following ? 'fa-check' : 'fa-plus'; ?>"></i>
                                <span><?php echo $is_following ? 'Seguindo' : ($follows_you ? 'Seguir de volta' : 'Seguir'); ?></span>
                            </button>
                            <button class="btn btn-chat" onclick="openChat(<?php echo $profile_user_id; ?>, '<?php echo htmlspecialchars($user_data['username']); ?>')">
                                <i class="fas fa-envelope"></i>
                                Mensagem
                            </button>
                            <?php if ($is_admin): ?>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    onclick="toggleVerifiedStatus(<?php echo $profile_user_id; ?>, <?php echo $user_data['is_verified'] ? '0' : '1'; ?>, this)">
                                    <i class="fas fa-check-circle"></i>
                                    <span><?php echo $user_data['is_verified'] ? 'Remover verificado' : 'Dar verificado'; ?></span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Grade de vídeos -->
        <section class="videos-section">
            <div class="videos-header">
                <h3><i class="fas fa-video"></i> Vídeos</h3>
            </div>

            <?php if (empty($user_videos)): ?>
                <div class="empty-videos">
                    <i class="fas fa-video" style="font-size: 3rem; opacity: 0.5; margin-bottom: 1rem;"></i>
                    <p><?php echo $is_own_profile ? 'Você ainda não postou nenhum vídeo.' : 'Este usuário ainda não postou vídeos.'; ?></p>
                    <?php if ($is_own_profile): ?>
                        <a href="upload.php" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i>
                            Postar Primeiro Vídeo
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="videos-grid" id="perfilVideosGrid">
                    <?php foreach ($user_videos as $video): ?>
                        <div class="video-card" data-video-id="<?php echo (int)$video['id']; ?>" onclick="window.location.href='index.php?user_id=<?php echo $profile_user_id; ?>&video_id=<?php echo $video['id']; ?>'">
                            <div class="video-thumbnail">
                                <?php if (!empty($video['thumbnail_path'])): ?>
                                    <img src="uploads/thumbnails/<?php echo htmlspecialchars($video['thumbnail_path']); ?>" alt="Thumbnail" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <?php $resolved_url = resolve_video_url($video['video_path']); ?>
                                    <video muted preload="none" class="lazy-video-preview" data-video-src="<?php echo htmlspecialchars($resolved_url); ?>" onloadeddata="this.currentTime = 0.5;">
                                        <source data-src="<?php echo htmlspecialchars($resolved_url); ?>" type="video/mp4">
                                    </video>
                                <?php endif; ?>
                                
                                <div class="video-overlay">
                                    <div class="video-stats">
                                        <span><i class="fas fa-eye"></i> <?php echo formatNumberShort($video['views_count']); ?></span>
                                        <span><i class="fas fa-heart"></i> <?php echo formatNumberShort($video['likes_count']); ?></span>
                                        <span><i class="fas fa-comment"></i> <?php echo formatNumberShort($video['comments_count']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="video-info">
                                <h4 class="video-title"><?php echo htmlspecialchars($video['title']); ?></h4>
                                <p class="video-date"><?php echo date('d/m/Y', strtotime($video['created_at'])); ?></p>
                                
                                <?php if ($can_manage_profile_videos): ?>
                                    <button class="delete-video-btn" 
                                            data-video-id="<?php echo $video['id']; ?>"
                                            onclick="event.stopPropagation(); confirmDeleteVideo(<?php echo $video['id']; ?>)"
                                            title="Apagar vídeo">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="videosLoadSentinel" style="height:1px;"></div>
                <div id="videosLoadStatus" class="follow-loading" style="display:none; padding: 12px 0 2px 0; text-align:center;">
                    <i class="fas fa-spinner fa-spin"></i> Carregando mais vídeos...
                </div>
            <?php endif; ?>
        </section>
    </main>

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

    <script>
        // Função para abrir vídeo específico
        function openVideo(videoId) {
            // Redirecionar para o feed com o vídeo específico
            window.location.href = 'index.php#video-' + videoId;
        }
    </script>

    <script>
        const perfilVideosConfig = {
            userId: <?php echo (int)$profile_user_id; ?>,
            pageLimit: <?php echo (int)$videos_page_limit; ?>,
            hasMore: <?php echo $has_more_videos ? 'true' : 'false'; ?>,
            canManage: <?php echo $can_manage_profile_videos ? 'true' : 'false'; ?>
        };
    </script>

    <script>
    (function() {
        const grid = document.getElementById('perfilVideosGrid');
        const sentinel = document.getElementById('videosLoadSentinel');
        const statusEl = document.getElementById('videosLoadStatus');

        if (!grid || !sentinel || !statusEl || !perfilVideosConfig) {
            return;
        }

        let nextPage = 2;
        let loading = false;
        let hasMore = !!perfilVideosConfig.hasMore;
        const pageLimit = Number(perfilVideosConfig.pageLimit) || 18;
        const userId = Number(perfilVideosConfig.userId) || 0;
        const canManage = !!perfilVideosConfig.canManage;
        let pagerObserver = null;

        function escHtml(value) {
            const d = document.createElement('div');
            d.textContent = value == null ? '' : String(value);
            return d.innerHTML;
        }

        function formatNum(n) {
            n = parseInt(n, 10) || 0;
            if (n >= 1e9) { let v = (n / 1e9).toFixed(1); return (v.endsWith('.0') ? v.slice(0, -2) : v) + 'B'; }
            if (n >= 1e6) { let v = (n / 1e6).toFixed(1); return (v.endsWith('.0') ? v.slice(0, -2) : v) + 'M'; }
            if (n >= 1e3) { let v = (n / 1e3).toFixed(1); return (v.endsWith('.0') ? v.slice(0, -2) : v) + 'k'; }
            return String(n);
        }

        function hydrateVideoPreview(videoEl) {
            if (!videoEl || videoEl.dataset.previewReady === '1') {
                return;
            }
            const source = videoEl.querySelector('source');
            const src = videoEl.dataset.videoSrc || (source ? source.dataset.src : '');
            if (!src) {
                return;
            }
            if (source) {
                source.src = src;
            } else {
                videoEl.src = src;
            }
            videoEl.load();
            videoEl.dataset.previewReady = '1';
        }

        const previewObserver = 'IntersectionObserver' in window
            ? new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    hydrateVideoPreview(entry.target);
                    previewObserver.unobserve(entry.target);
                });
            }, { root: null, rootMargin: '700px 0px', threshold: 0.01 })
            : null;

        function observeVideoPreviews(scope) {
            const root = scope || document;
            root.querySelectorAll('video.lazy-video-preview').forEach((videoEl) => {
                if (videoEl.dataset.previewObserved === '1') {
                    return;
                }
                videoEl.dataset.previewObserved = '1';
                if (previewObserver) {
                    previewObserver.observe(videoEl);
                } else {
                    hydrateVideoPreview(videoEl);
                }
            });
        }

        function buildVideoCard(video) {
            const title = escHtml(video.title || 'Sem título');
            const dateLabel = escHtml(video.date_label || '');
            const thumbSrc = video.thumbnail_path ? `uploads/thumbnails/${encodeURIComponent(video.thumbnail_path)}` : '';
            const videoSrc = video.video_url || (video.video_path ? resolveVideoUrl(video.video_path) : '');

            let mediaHtml = '<div class="default-thumbnail"><i class="fas fa-play"></i></div>';
            if (thumbSrc) {
                mediaHtml = `<img src="${thumbSrc}" alt="Thumbnail" loading="lazy" decoding="async">`;
            } else if (videoSrc) {
                mediaHtml = `<video muted preload="none" class="lazy-video-preview" data-video-src="${videoSrc}" onloadeddata="this.currentTime = 0.5;"><source data-src="${videoSrc}" type="video/mp4"></video>`;
            }

            const deleteButton = canManage
                ? `<button class="delete-video-btn" data-video-id="${video.id}" onclick="event.stopPropagation(); confirmDeleteVideo(${video.id})" title="Apagar vídeo"><i class="fas fa-trash"></i></button>`
                : '';

            return `
                <div class="video-card" data-video-id="${video.id}" onclick="window.location.href='index.php?user_id=${userId}&video_id=${video.id}'">
                    <div class="video-thumbnail">
                        ${mediaHtml}
                        <div class="video-overlay">
                            <div class="video-stats">
                                <span><i class="fas fa-eye"></i> ${formatNum(video.views_count)}</span>
                                <span><i class="fas fa-heart"></i> ${formatNum(video.likes_count)}</span>
                                <span><i class="fas fa-comment"></i> ${formatNum(video.comments_count)}</span>
                            </div>
                        </div>
                    </div>
                    <div class="video-info">
                        <h4 class="video-title">${title}</h4>
                        <p class="video-date">${dateLabel}</p>
                        ${deleteButton}
                    </div>
                </div>
            `;
        }

        function stopPaging() {
            hasMore = false;
            sentinel.style.display = 'none';
            statusEl.style.display = 'none';
            if (pagerObserver) {
                pagerObserver.disconnect();
            }
        }

        async function loadMoreVideos() {
            if (loading || !hasMore || userId <= 0) {
                return;
            }

            loading = true;
            statusEl.style.display = 'block';

            try {
                const response = await fetch(`api/get_user_videos.php?user_id=${userId}&page=${nextPage}&limit=${pageLimit}`, { cache: 'no-store' });
                const data = await response.json();

                if (!data.success || !Array.isArray(data.videos) || data.videos.length === 0) {
                    stopPaging();
                    return;
                }

                const html = data.videos.map(buildVideoCard).join('');
                grid.insertAdjacentHTML('beforeend', html);
                observeVideoPreviews(grid);

                hasMore = !!data.has_more;
                nextPage += 1;
                if (!hasMore) {
                    stopPaging();
                }
            } catch (e) {
                stopPaging();
            } finally {
                loading = false;
                if (hasMore) {
                    statusEl.style.display = 'none';
                }
            }
        }

        observeVideoPreviews(document);

        if (!hasMore) {
            stopPaging();
            return;
        }

        if ('IntersectionObserver' in window) {
            pagerObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        loadMoreVideos();
                    }
                });
            }, { root: null, rootMargin: '1100px 0px', threshold: 0 });

            pagerObserver.observe(sentinel);
        } else {
            window.addEventListener('scroll', () => {
                if (!hasMore || loading) {
                    return;
                }
                const distanceToBottom = document.documentElement.scrollHeight - (window.scrollY + window.innerHeight);
                if (distanceToBottom < 1200) {
                    loadMoreVideos();
                }
            }, { passive: true });
        }
    })();
    </script>

    <!-- CSS para animações -->
    <style>
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
    
    <script>
        function _formatNum(n) {
            n = parseInt(n) || 0;
            if (n >= 1e9) { let v = (n/1e9).toFixed(1); return (v.endsWith('.0') ? v.slice(0,-2) : v) + 'B'; }
            if (n >= 1e6) { let v = (n/1e6).toFixed(1); return (v.endsWith('.0') ? v.slice(0,-2) : v) + 'M'; }
            if (n >= 1e3) { let v = (n/1e3).toFixed(1); return (v.endsWith('.0') ? v.slice(0,-2) : v) + 'k'; }
            return n.toString();
        }

        document.addEventListener('DOMContentLoaded', () => {
            setupFollowButtons();
        });
        
        // Configurar funcionalidade dos botões de seguir
        function setupFollowButtons() {
            document.querySelectorAll('[data-user-follow]').forEach(button => {
                button.addEventListener('click', async function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const userId = this.dataset.userFollow;
                    const icon = this.querySelector('i');
                    const text = this.querySelector('span');
                    
                    // Desabilitar botão durante requisição
                    if (this.disabled) return;
                    this.disabled = true;
                    
                    const originalText = text.textContent;
                    text.textContent = 'Aguarde...';
                    
                    try {
                        const response = await fetch('api/toggle_follow.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                user_id: parseInt(userId)
                            })
                        });
                        
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            console.log('✅ Follow atualizado:', data);
                            
                            const followsYou = this.dataset.followsYou === '1' || data.follows_you;
                            
                            // Atualizar interface
                            if (data.is_following) {
                                this.classList.add('following');
                                icon.className = 'fas fa-check';
                                text.textContent = 'Seguindo';
                            } else {
                                this.classList.remove('following');
                                icon.className = 'fas fa-plus';
                                text.textContent = followsYou ? 'Seguir de volta' : 'Seguir';
                            }
                            
                            // Atualizar data attribute com info da API
                            if (data.follows_you !== undefined) {
                                this.dataset.followsYou = data.follows_you ? '1' : '0';
                            }
                            
                            // Atualizar contador de seguidores (buscar o segundo .stat que contém seguidores)
                            const stats = document.querySelectorAll('.stat');
                            if (stats[1]) { // Segundo stat é seguidores
                                const followersCountEl = stats[1].querySelector('.stat-number');
                                if (followersCountEl && data.followers_count !== undefined) {
                                    followersCountEl.textContent = _formatNum(data.followers_count);
                                    console.log('✅ Contador atualizado para:', data.followers_count);
                                }
                            }
                        } else {
                            console.error('❌ Erro da API:', data);
                            alert(data.error || 'Erro ao seguir usuário');
                            text.textContent = originalText;
                        }
                    } catch (error) {
                        console.error('❌ Erro na requisição:', error);
                        alert('Erro de conexão. Verifique o console.');
                        text.textContent = originalText;
                    } finally {
                        this.disabled = false;
                    }
                });
            });
        }
        
        // Função para abrir chat
        function openChat(userId, username) {
            // Redirecionar para a página de chat com o usuário
            window.location.href = `chat.php?user_id=${userId}&from=perfil`;
        }
    </script>
    
    <script src="<?php echo asset('assets/js/video-delete.js'); ?>"></script>

    <!-- Modal de Seguidores / Seguindo -->
    <div class="follow-modal-overlay" id="followModal" onclick="if(event.target===this)closeFollowModal()">
        <div class="follow-modal-box">
            <div class="follow-modal-header">
                <h3 id="followModalTitle">Seguidores</h3>
                <button class="follow-modal-close" onclick="closeFollowModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="follow-modal-body">
                <div class="follow-list" id="followList">
                    <div class="follow-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                </div>
                <button class="btn follow-load-more" id="followLoadMore" style="display:none" onclick="loadMoreFollows()">
                    Carregar mais
                </button>
            </div>
        </div>
    </div>

    <script>
    // === Modal Seguidores / Seguindo ===
    let followModalState = { type: '', userId: 0, page: 0, loading: false, hasMore: false };

    function openFollowModal(type, userId) {
        followModalState = { type, userId, page: 0, loading: false, hasMore: true };
        document.getElementById('followModalTitle').textContent = type === 'followers' ? 'Seguidores' : 'Seguindo';
        document.getElementById('followList').innerHTML = '<div class="follow-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
        document.getElementById('followLoadMore').style.display = 'none';
        document.getElementById('followModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        loadMoreFollows();
    }

    function closeFollowModal() {
        document.getElementById('followModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    async function loadMoreFollows() {
        if (followModalState.loading || !followModalState.hasMore) return;
        followModalState.loading = true;
        followModalState.page++;

        const btn = document.getElementById('followLoadMore');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Carregando...';

        try {
            const resp = await fetch(`api/get_followers.php?user_id=${followModalState.userId}&type=${followModalState.type}&page=${followModalState.page}&limit=20`);
            const data = await resp.json();

            if (data.success) {
                const list = document.getElementById('followList');
                if (followModalState.page === 1) list.innerHTML = '';

                if (data.users.length === 0 && followModalState.page === 1) {
                    list.innerHTML = '<div class="follow-empty"><i class="fas fa-users"></i><p>' +
                        (followModalState.type === 'followers' ? 'Nenhum seguidor ainda' : 'Não segue ninguém ainda') + '</p></div>';
                }

                data.users.forEach(u => {
                    list.insertAdjacentHTML('beforeend', renderFollowItem(u));
                });

                followModalState.hasMore = data.has_more;
                btn.style.display = data.has_more ? 'block' : 'none';
            }
        } catch (e) {
            console.error('Erro ao carregar lista:', e);
        } finally {
            followModalState.loading = false;
            btn.disabled = false;
            btn.innerHTML = 'Carregar mais';
        }
    }

    function renderFollowItem(u) {
        const verified = u.is_verified ? ' <i class="fas fa-check-circle verified-badge follow-verified"></i>' : '';
        let followLabel = 'Seguir';
        if (u.is_followed_by_me) {
            followLabel = 'Seguindo';
        } else if (u.follows_me) {
            followLabel = 'Seguir de volta';
        }
        const followBtn = u.is_me ? '' :
            `<button class="btn-follow-small ${u.is_followed_by_me ? 'following' : ''}" 
                     onclick="toggleFollowInModal(this, ${u.id})" data-uid="${u.id}" data-follows-me="${u.follows_me ? '1' : '0'}">
                ${followLabel}
            </button>`;

        return `<div class="follow-item">
            <img src="assets/images/avatars/${u.profile_picture}" alt="" class="follow-avatar" loading="lazy"
                 onclick="window.location.href='perfil.php?id=${u.id}'" style="cursor:pointer">
            <div class="follow-info" onclick="window.location.href='perfil.php?id=${u.id}'" style="cursor:pointer">
                <span class="follow-name">${escHtml(u.full_name)}${verified}</span>
                <span class="follow-username">@${escHtml(u.username)}</span>
            </div>
            ${followBtn}
        </div>`;
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    async function toggleFollowInModal(btn, userId) {
        if (btn.disabled) return;
        btn.disabled = true;
        try {
            const resp = await fetch('api/toggle_follow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId })
            });
            const data = await resp.json();
            if (data.success) {
                const followsMe = btn.dataset.followsMe === '1' || data.follows_you;
                btn.classList.toggle('following', data.is_following);
                if (data.is_following) {
                    btn.textContent = 'Seguindo';
                } else {
                    btn.textContent = followsMe ? 'Seguir de volta' : 'Seguir';
                }
                if (data.followers_count !== undefined) {
                    const fc = document.getElementById('followersCount');
                    if (fc) fc.textContent = _formatNum(data.followers_count);
                }
            }
        } catch (e) { console.error(e); }
        finally { btn.disabled = false; }
    }

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFollowModal(); });
    </script>

    <!-- Modal Top 10 Vídeos -->
    <div class="follow-modal-overlay" id="topVideosModal" onclick="if(event.target===this)closeTopVideosModal()">
        <div class="follow-modal-box" style="max-width:480px;">
            <div class="follow-modal-header">
                <h3 id="topVideosModalTitle"><i class="fas fa-trophy" style="color:#f59e0b;margin-right:6px;"></i> Top 10 Vídeos</h3>
                <button class="follow-modal-close" onclick="closeTopVideosModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="follow-modal-body">
                <div class="follow-list" id="topVideosList">
                    <div class="follow-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .top-video-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .top-video-item:hover {
            background: rgba(255,255,255,0.05);
        }
        .top-video-rank {
            font-size: 1.1rem;
            font-weight: 800;
            min-width: 28px;
            text-align: center;
            color: #94a3b8;
            flex-shrink: 0;
        }
        .top-video-rank.gold { color: #f59e0b; }
        .top-video-rank.silver { color: #cbd5e1; }
        .top-video-rank.bronze { color: #d97706; }
        .top-video-thumb {
            width: 56px;
            height: 72px;
            border-radius: 8px;
            object-fit: cover;
            background: #1e293b;
            flex-shrink: 0;
        }
        .top-video-info {
            flex: 1;
            min-width: 0;
        }
        .top-video-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 4px;
        }
        .top-video-stats {
            display: flex;
            gap: 12px;
            font-size: 0.75rem;
            color: #94a3b8;
        }
        .top-video-stats i {
            margin-right: 3px;
        }
        .top-video-stats .stat-likes { color: #f87171; }
        .top-video-stats .stat-comments { color: #60a5fa; }
        .top-video-stats .stat-views { color: #94a3b8; }
    </style>

    <script>
    function openTopVideosModal(userId) {
        const modal = document.getElementById('topVideosModal');
        const list = document.getElementById('topVideosList');
        list.innerHTML = '<div class="follow-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        fetch(`api/get_top_videos.php?user_id=${userId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.videos.length) {
                    list.innerHTML = '<div class="follow-empty"><i class="fas fa-video"></i><p>Nenhum vídeo encontrado</p></div>';
                    return;
                }
                list.innerHTML = '';
                data.videos.forEach(v => {
                    const rankClass = v.rank === 1 ? 'gold' : v.rank === 2 ? 'silver' : v.rank === 3 ? 'bronze' : '';
                    const medal = v.rank === 1 ? '🥇' : v.rank === 2 ? '🥈' : v.rank === 3 ? '🥉' : v.rank;
                    const thumbSrc = v.thumbnail_path
                        ? `uploads/thumbnails/${v.thumbnail_path}`
                        : (v.video_url || resolveVideoUrl(v.video_path));
                    const isVideo = !v.thumbnail_path;

                    let thumbHtml;
                    if (isVideo) {
                        thumbHtml = `<video class="top-video-thumb" muted preload="metadata" onloadeddata="this.currentTime=0.5"><source src="${thumbSrc}" type="video/mp4"></video>`;
                    } else {
                        thumbHtml = `<img class="top-video-thumb" src="${thumbSrc}" alt="" loading="lazy">`;
                    }

                    list.insertAdjacentHTML('beforeend', `
                        <div class="top-video-item" onclick="window.location.href='index.php?user_id=${userId}&video_id=${v.id}'">
                            <span class="top-video-rank ${rankClass}">${medal}</span>
                            ${thumbHtml}
                            <div class="top-video-info">
                                <div class="top-video-title">${_escHtml(v.title || 'Sem título')}</div>
                                <div class="top-video-stats">
                                    <span class="stat-likes"><i class="fas fa-heart"></i>${_formatNum(v.likes_count)}</span>
                                    <span class="stat-comments"><i class="fas fa-comment"></i>${_formatNum(v.comments_count)}</span>
                                    <span class="stat-views"><i class="fas fa-eye"></i>${_formatNum(v.views_count)}</span>
                                </div>
                            </div>
                        </div>
                    `);
                });
            })
            .catch(() => {
                list.innerHTML = '<div class="follow-empty"><i class="fas fa-exclamation-triangle"></i><p>Erro ao carregar</p></div>';
            });
    }

    function closeTopVideosModal() {
        document.getElementById('topVideosModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    function _escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTopVideosModal(); });
    </script>

    <script>
    // Institution info tooltip
    function showInstitutionInfo(btn) {
        const existing = document.querySelector('.institution-tooltip');
        if (existing) { existing.remove(); return; }
        const inst = btn.getAttribute('data-institution');
        const tooltip = document.createElement('div');
        tooltip.className = 'institution-tooltip';
        tooltip.innerHTML = '<span class="tooltip-label">Institui\u00e7\u00e3o</span>' + _escHtml(inst);
        btn.closest('.profile-avatar').appendChild(tooltip);
        setTimeout(() => {
            document.addEventListener('click', function handler(e) {
                if (!btn.contains(e.target)) {
                    tooltip.remove();
                    document.removeEventListener('click', handler);
                }
            });
        }, 10);
    }
    </script>

    <!-- Action Sheet do Avatar (perfil.php) -->
    <div class="avatar-action-overlay" id="avatarActionOverlay" onclick="closePerfilAvatarSheet()">
        <div class="avatar-action-sheet" onclick="event.stopPropagation()">
            <div class="action-sheet-header">
                <span class="action-sheet-title">Foto de Perfil</span>
            </div>
            <button class="action-sheet-btn" onclick="showUserInfoModal()">
                <i class="fas fa-info-circle"></i>
                <span>Informações</span>
            </button>
            <button class="action-sheet-btn" onclick="viewPerfilPhoto()">
                <i class="fas fa-expand"></i>
                <span>Ver foto em ponto grande</span>
            </button>
            <button class="action-sheet-btn action-sheet-cancel" onclick="closePerfilAvatarSheet()">
                <span>Cancelar</span>
            </button>
        </div>
    </div>

    <!-- Visualizador de Foto em Grande -->
    <div class="photo-viewer-overlay" id="photoViewerOverlay" onclick="closePhotoViewer()">
        <button class="photo-viewer-close" onclick="closePhotoViewer()">
            <i class="fas fa-times"></i>
        </button>
        <img id="photoViewerImg" class="photo-viewer-img" src="" alt="Foto de perfil" onclick="event.stopPropagation()">
    </div>

    <!-- Modal de Informações do Utilizador -->
    <div class="user-info-modal-overlay" id="userInfoModalOverlay" onclick="closeUserInfoModal()">
        <div class="user-info-modal" onclick="event.stopPropagation()">
            <div class="user-info-modal-header">
                <h3><i class="fas fa-user-circle"></i> Informações</h3>
                <button class="user-info-modal-close" onclick="closeUserInfoModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="user-info-modal-body">
                <div class="user-info-avatar">
                    <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="" loading="lazy">
                </div>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-user"></i> Nome</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($user_data['full_name']); ?></span>
                </div>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-at"></i> Username</span>
                    <span class="user-info-value">@<?php echo htmlspecialchars($user_data['username']); ?></span>
                </div>
                <?php if (!empty($user_data['bio'])): ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-quote-left"></i> Bio</span>
                    <span class="user-info-value"><?php echo nl2br(htmlspecialchars($user_data['bio'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user_data['instituicao'])): ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-university"></i> Instituição</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($user_data['instituicao']); ?></span>
                </div>
                <?php endif; ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-calendar"></i> Membro desde</span>
                    <span class="user-info-value"><?php echo date('d/m/Y', strtotime($user_data['created_at'])); ?></span>
                </div>
                <?php if ($user_data['is_verified']): ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-check-circle" style="color:#818cf8"></i> Estado</span>
                    <span class="user-info-value" style="color:#818cf8">Verificado</span>
                </div>
                <?php endif; ?>
                <div class="user-info-stats-row">
                    <div class="user-info-stat">
                        <span class="stat-num"><?php echo formatNumberShort($user_data['videos_count']); ?></span>
                        <span class="stat-lbl">Vídeos</span>
                    </div>
                    <div class="user-info-stat">
                        <span class="stat-num"><?php echo formatNumberShort($user_data['followers_count']); ?></span>
                        <span class="stat-lbl">Seguidores</span>
                    </div>
                    <div class="user-info-stat">
                        <span class="stat-num"><?php echo formatNumberShort($user_data['following_count']); ?></span>
                        <span class="stat-lbl">Seguindo</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
    /* === Action Sheet (perfil.php) === */
    .avatar-action-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.6);
        z-index: 9999;
        align-items: flex-end;
        justify-content: center;
        -webkit-backdrop-filter: blur(4px);
        backdrop-filter: blur(4px);
    }
    .avatar-action-overlay.active {
        display: flex;
    }
    .avatar-action-sheet {
        background: #1e293b;
        border-radius: 16px 16px 0 0;
        width: 100%;
        max-width: 500px;
        padding: 8px 0 env(safe-area-inset-bottom, 12px);
        animation: slideUpSheet 0.3s ease;
    }
    @keyframes slideUpSheet {
        from { transform: translateY(100%); }
        to   { transform: translateY(0); }
    }
    .action-sheet-header {
        text-align: center;
        padding: 14px 20px 10px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    .action-sheet-title {
        font-size: 0.85rem;
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .action-sheet-btn {
        display: flex;
        align-items: center;
        gap: 14px;
        width: 100%;
        padding: 16px 24px;
        background: none;
        border: none;
        color: #e2e8f0;
        font-size: 1rem;
        cursor: pointer;
        transition: background 0.15s;
    }
    .action-sheet-btn:hover {
        background: rgba(255,255,255,0.06);
    }
    .action-sheet-btn i {
        font-size: 1.1rem;
        width: 24px;
        text-align: center;
        color: #818cf8;
    }
    .action-sheet-cancel {
        border-top: 1px solid rgba(255,255,255,0.08);
        justify-content: center;
        color: #94a3b8;
        margin-top: 4px;
    }
    /* === Photo Viewer === */
    .photo-viewer-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.92);
        z-index: 10000;
        align-items: center;
        justify-content: center;
    }
    .photo-viewer-overlay.active {
        display: flex;
    }
    .photo-viewer-close {
        position: absolute;
        top: 16px;
        right: 16px;
        background: rgba(255,255,255,0.15);
        border: none;
        color: #fff;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        font-size: 1.2rem;
        cursor: pointer;
        z-index: 10001;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
    }
    .photo-viewer-close:hover {
        background: rgba(255,255,255,0.25);
    }
    .photo-viewer-img {
        max-width: 90vw;
        max-height: 85vh;
        border-radius: 12px;
        object-fit: contain;
        animation: zoomIn 0.3s ease;
    }
    @keyframes zoomIn {
        from { transform: scale(0.8); opacity: 0; }
        to   { transform: scale(1); opacity: 1; }
    }
    /* === User Info Modal === */
    .user-info-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.6);
        z-index: 10000;
        align-items: center;
        justify-content: center;
        -webkit-backdrop-filter: blur(4px);
        backdrop-filter: blur(4px);
    }
    .user-info-modal-overlay.active {
        display: flex;
    }
    .user-info-modal {
        background: #1e293b;
        border-radius: 20px;
        width: 90%;
        max-width: 420px;
        max-height: 85vh;
        overflow-y: auto;
        animation: zoomIn 0.3s ease;
        border: 1px solid rgba(255,255,255,0.08);
    }
    .user-info-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 18px 20px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    .user-info-modal-header h3 {
        font-size: 1.1rem;
        color: #fff;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .user-info-modal-header h3 i {
        color: #818cf8;
    }
    .user-info-modal-close {
        background: rgba(255,255,255,0.1);
        border: none;
        color: #94a3b8;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
    }
    .user-info-modal-close:hover {
        background: rgba(255,255,255,0.2);
        color: #fff;
    }
    .user-info-modal-body {
        padding: 20px;
    }
    .user-info-avatar {
        text-align: center;
        margin-bottom: 20px;
    }
    .user-info-avatar img {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid rgba(129,140,248,0.3);
    }
    .user-info-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 12px 0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .user-info-item:last-of-type {
        border-bottom: none;
    }
    .user-info-label {
        font-size: 0.78rem;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .user-info-label i {
        color: #818cf8;
        font-size: 0.8rem;
    }
    .user-info-value {
        font-size: 0.95rem;
        color: #e2e8f0;
        line-height: 1.5;
    }
    .user-info-stats-row {
        display: flex;
        justify-content: space-around;
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid rgba(255,255,255,0.08);
    }
    .user-info-stat {
        text-align: center;
    }
    .user-info-stat .stat-num {
        display: block;
        font-size: 1.2rem;
        font-weight: 700;
        color: #fff;
    }
    .user-info-stat .stat-lbl {
        font-size: 0.75rem;
        color: #64748b;
    }
    @media (min-width: 768px) {
        .avatar-action-sheet {
            border-radius: 16px;
            margin-bottom: 40px;
        }
    }
    </style>

    <script>
    // === Avatar Action Sheet (perfil.php) ===
    function openPerfilAvatarSheet() {
        document.getElementById('avatarActionOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closePerfilAvatarSheet() {
        document.getElementById('avatarActionOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    function viewPerfilPhoto() {
        closePerfilAvatarSheet();
        const img = document.querySelector('.profile-avatar .avatar-image');
        if (!img) return;
        const viewer = document.getElementById('photoViewerImg');
        viewer.src = img.src;
        document.getElementById('photoViewerOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closePhotoViewer() {
        document.getElementById('photoViewerOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    function showUserInfoModal() {
        closePerfilAvatarSheet();
        setTimeout(() => {
            document.getElementById('userInfoModalOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }, 150);
    }
    function closeUserInfoModal() {
        document.getElementById('userInfoModalOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    function toggleVerifiedStatus(userId, verified, button) {
        const nextState = parseInt(verified, 10) === 1 ? 1 : 0;
        const actionLabel = nextState === 1 ? 'dar o selo de verificado' : 'remover o selo de verificado';

        if (!confirm(`Deseja ${actionLabel} a este utilizador?`)) {
            return;
        }

        const originalHtml = button ? button.innerHTML : '';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>A guardar...</span>';
        }

        fetch('api/toggle_verified.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `user_id=${encodeURIComponent(userId)}&verified=${nextState}`
        })
        .then((response) => response.json())
        .then((data) => {
            if (!data.success) {
                throw new Error(data.error || 'Não foi possível atualizar o verificado');
            }

            window.location.reload();
        })
        .catch((error) => {
            alert(error.message);

            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        });
    }
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (document.getElementById('photoViewerOverlay').classList.contains('active')) {
                closePhotoViewer();
            } else if (document.getElementById('userInfoModalOverlay').classList.contains('active')) {
                closeUserInfoModal();
            } else if (document.getElementById('avatarActionOverlay').classList.contains('active')) {
                closePerfilAvatarSheet();
            }
        }
    });
    </script>
    <?php include 'includes/presence_bootstrap.php'; ?>
</body>
</html>