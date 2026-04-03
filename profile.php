<?php
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';

// Verificar se o usuário está logado
if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Recuperar mensagem de sucesso do PRG (Post-Redirect-Get)
if (isset($_SESSION['profile_success'])) {
    $success = $_SESSION['profile_success'];
    unset($_SESSION['profile_success']);
}

// Processar ações do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['logout'])) {
        // Logout
        session_destroy();
        redirect('login.php');
    }
    
    if (isset($_POST['update_profile'])) {
        // Buscar dados atuais do utilizador para comparação
        $currentStmt = $pdo->prepare("SELECT full_name, bio, instituicao, school_id FROM users WHERE id = ?");
        $currentStmt->execute([$user_id]);
        $currentData = $currentStmt->fetch();

        // Atualizar perfil — preparar novos valores
        $full_name = trim($_POST['full_name']);
        $bio = trim($_POST['bio']);
        $instituicao = trim($_POST['instituicao'] ?? '');
        
        // Limitar nome a 25 caracteres
        $full_name = mb_substr($full_name, 0, 25);
        
        // Limitar instituicao a 150 caracteres
        $instituicao = mb_substr($instituicao, 0, 150);
        
        // Limitar bio a 75 caracteres e 3 linhas
        $bio_lines = explode("\n", $bio);
        $bio_lines = array_slice($bio_lines, 0, 3);
        $bio = implode("\n", $bio_lines);
        $bio = mb_substr($bio, 0, 75);
        
        if (empty($full_name)) {
            $error = 'Nome completo é obrigatório.';
        } else {
            // Processar upload da foto de perfil
            $profile_picture = null;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'assets/images/avatars/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_types)) {
                    $new_filename = 'user_' . $user_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                        $profile_picture = $new_filename;
                    } else {
                        $error = 'Erro ao fazer upload da foto.';
                    }
                } else {
                    $error = 'Tipo de arquivo não permitido. Use JPG, PNG ou GIF.';
                }
            }
            
            if (empty($error)) {
                // Detectar quais campos realmente mudaram
                $changedFields = [];
                $params = [];
                $old_bio = $currentData['bio'] ?? '';

                if ($full_name !== ($currentData['full_name'] ?? '')) {
                    $changedFields[] = "full_name = ?";
                    $params[] = $full_name;
                }

                $bioChanged = ($bio !== $old_bio);
                if ($bioChanged) {
                    $changedFields[] = "bio = ?";
                    $params[] = $bio;
                }

                $old_instituicao = $currentData['instituicao'] ?? '';
                $new_instituicao = $instituicao ?: null;
                if (($new_instituicao ?? '') !== $old_instituicao) {
                    $changedFields[] = "instituicao = ?";
                    $params[] = $new_instituicao;

                    // Resolver school_id a partir da instituição selecionada
                    $resolved_school_id = null;
                    if (!empty($instituicao)) {
                        $schoolStmt = $pdo->prepare("SELECT id FROM schools WHERE name = ? AND is_active = 1 LIMIT 1");
                        $schoolStmt->execute([$instituicao]);
                        $schoolRow = $schoolStmt->fetch();
                        if ($schoolRow) {
                            $resolved_school_id = $schoolRow['id'];
                        }
                    }
                    $changedFields[] = "school_id = ?";
                    $params[] = $resolved_school_id;
                }
                
                if ($profile_picture) {
                    $changedFields[] = "profile_picture = ?";
                    $params[] = $profile_picture;
                }

                // Só executar UPDATE se algo realmente mudou
                if (!empty($changedFields)) {
                    $sql = "UPDATE users SET " . implode(", ", $changedFields) . " WHERE id = ?";
                    $params[] = $user_id;
                    
                    $stmt = $pdo->prepare($sql);
                    if ($stmt->execute($params)) {
                        if ($full_name !== ($currentData['full_name'] ?? '')) {
                            $_SESSION['full_name'] = $full_name;
                        }
                        invalidateUserCache(); // Forçar recarga dos dados na próxima page load
                        
                        // Só notificar menções na bio se a bio realmente mudou
                        if ($bioChanged) {
                            // Extrair menções da bio antiga e nova
                            preg_match_all('/@(\w+)/', $old_bio, $oldMentions);
                            preg_match_all('/@(\w+)/', $bio, $newMentions);
                            $oldMentionNames = array_unique($oldMentions[1] ?? []);
                            $newMentionNames = array_unique($newMentions[1] ?? []);

                            // Só notificar menções NOVAS (que não existiam na bio anterior)
                            $addedMentions = array_diff($newMentionNames, $oldMentionNames);
                            $addedMentions = array_slice($addedMentions, 0, 5);

                            if (!empty($addedMentions)) {
                                $ph = str_repeat('?,', count($addedMentions) - 1) . '?';
                                $mStmt = $pdo->prepare("SELECT id FROM users WHERE username IN ($ph) AND id != ?");
                                $mStmt->execute(array_merge(array_values($addedMentions), [$user_id]));
                                $mentionedIds = $mStmt->fetchAll(PDO::FETCH_COLUMN);
                                
                                if (!empty($mentionedIds)) {
                                    // Tipo 'bio_mention' para distinguir de menções em comentários
                                    $nStmt = $pdo->prepare("
                                        INSERT IGNORE INTO notifications (user_id, actor_id, type, reference_id)
                                        VALUES (?, ?, 'bio_mention', NULL)
                                    ");
                                    foreach ($mentionedIds as $mid) {
                                        try { $nStmt->execute([(int)$mid, $user_id]); } catch (Exception $e) {}
                                    }
                                }
                            }
                        }
                        
                        // PRG: redirecionar para evitar resubmissão do formulário ao voltar
                        $_SESSION['profile_success'] = 'Perfil atualizado com sucesso!';
                        header('Location: profile.php');
                        exit;
                    } else {
                        $error = 'Erro ao atualizar perfil.';
                    }
                } else {
                    // Nada mudou, redirecionar sem mensagem de erro
                    $_SESSION['profile_success'] = 'Perfil atualizado com sucesso!';
                    header('Location: profile.php');
                    exit;
                }
            }
        }
    }
}

// Buscar informações do usuário
$stmt = $pdo->prepare("
    SELECT 
        u.*,
        u.followers_count,
        u.following_count,
        u.videos_count
    FROM users u 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    redirect('login.php');
}

// Buscar vídeos do usuário
$videos_page_limit = 18;
$videos_stmt = $pdo->prepare("
    SELECT
        v.id,
        v.title,
        v.video_path,
        v.thumbnail_path,
        v.views_count,
        v.likes_count,
        v.comments_count,
        v.created_at
    FROM videos v
    WHERE v.user_id = ? AND v.is_public = 1
    ORDER BY v.created_at DESC
    LIMIT ? OFFSET 0
");
$videos_stmt->bindValue(1, (int)$user_id, PDO::PARAM_INT);
$videos_stmt->bindValue(2, (int)$videos_page_limit, PDO::PARAM_INT);
$videos_stmt->execute();
$user_videos = $videos_stmt->fetchAll();

$total_user_videos = isset($user['videos_count']) ? (int)$user['videos_count'] : count($user_videos);
if ($total_user_videos < count($user_videos)) {
    $total_user_videos = count($user_videos);
}
$has_more_videos = $total_user_videos > count($user_videos);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($user['full_name']); ?> - MyTube</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/profile.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php echo r2_js_config(); ?>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body>
    <!-- Header -->
    <header class="profile-header">
        <div class="header-content">
            <button class="back-btn" onclick="smartBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <script>
            function smartBack() {
                // Sinalizar que queremos restaurar o feed ao voltar
                try { localStorage.setItem('mytube_restore_feed', '1'); } catch(e) {}
                
                var ref = document.referrer || '';
                // Evitar loop: se veio de perfil, profile ou notificações, ir para o feed
                if (!ref || ref.indexOf('chat.php') !== -1 || ref.indexOf('settings.php') !== -1 || ref.indexOf('perfil.php') !== -1 || ref.indexOf('profile.php') !== -1 || ref.indexOf('notification') !== -1) {
                    window.location.href = 'index.php';
                } else {
                    history.back();
                }
            }
            </script>
            <h1>Perfil</h1>
            <div class="header-actions">
                <button class="header-btn chat-btn" onclick="openChatList()" title="Chat">
                    <i class="fas fa-comment"></i>
                    <span class="chat-unread-badge" id="chatUnreadBadge" style="display: none;">0</span>
                </button>
                <button class="header-btn notification-btn" onclick="NotificationSystem.openModal()" title="Notificações">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                </button>
                <button class="header-btn" onclick="window.location.href='settings.php'" title="Definições">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </div>
    </header>

    <main class="profile-main">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Informações do perfil -->
        <section class="profile-info">
            <div class="profile-avatar">
                <?php
                    $avatarFile = $user['profile_picture'] ?? 'default.jpg';
                    $avatarPath = 'assets/images/avatars/' . $avatarFile;
                    if (!file_exists($avatarPath)) {
                        $avatarPath = 'assets/images/avatars/default.jpg';
                    }
                ?>
                <img src="<?php echo $avatarPath; ?>" 
                     alt="<?php echo htmlspecialchars($user['full_name']); ?>" 
                     class="avatar-image"
                     onerror="this.onerror=null;this.src='assets/images/avatars/default.jpg';"
                     onclick="openAvatarActionSheet()" 
                     style="cursor:pointer">
                <?php if ($user['is_verified']): ?>
                    <div class="verified-badge-large">
                        <i class="fas fa-check"></i>
                    </div>
                <?php endif; ?>
                <?php 
                // Badge "Best MyTuber da Semana" — lógica real
                $now_dt = new DateTime('now', new DateTimeZone('Africa/Luanda'));
                $now_check = $now_dt->format('Y-m-d H:i:s');
                $badge_stmt = $pdo->prepare("
                    SELECT scope, school_id FROM best_mytuber_weekly 
                    WHERE user_id = ? AND badge_visible_from <= ? AND badge_visible_until >= ?
                ");
                $badge_stmt->execute([$user_id, $now_check, $now_check]);
                $my_badges = $badge_stmt->fetchAll();
                $is_best_global = false;
                $is_best_school = false;
                foreach ($my_badges as $mb) {
                    if ($mb['scope'] === 'global') $is_best_global = true;
                    if ($mb['scope'] === 'school') $is_best_school = true;
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
                <h2 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                <p class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></p>
                
                <?php if ($user['bio']): ?>
                    <p class="profile-bio"><?php echo renderBioWithMentions($user['bio']); ?></p>
                <?php endif; ?>
                
                <div class="profile-stats">
                    <div class="stat-item stat-clickable" onclick="openTopVideosModal(<?php echo $user_id; ?>)">
                        <span class="stat-number"><?php echo formatNumberShort($user['videos_count']); ?></span>
                        <span class="stat-label">Vídeos</span>
                    </div>
                    <div class="stat-item stat-clickable" onclick="openFollowModal('followers', <?php echo $user_id; ?>)">
                        <span class="stat-number" id="followersCount"><?php echo formatNumberShort($user['followers_count']); ?></span>
                        <span class="stat-label">Seguidores</span>
                    </div>
                    <div class="stat-item stat-clickable" onclick="openFollowModal('following', <?php echo $user_id; ?>)">
                        <span class="stat-number" id="followingCount"><?php echo formatNumberShort($user['following_count']); ?></span>
                        <span class="stat-label">Seguindo</span>
                    </div>
                </div>
                
                <div class="profile-actions">
                    <button class="btn btn-primary" onclick="toggleEditModal()">
                        <i class="fas fa-edit"></i>
                        Editar Perfil
                    </button>
                    <button class="btn btn-secondary" onclick="window.location.href='upload.php'">
                        <i class="fas fa-plus"></i>
                        Novo Vídeo
                    </button>
                </div>
            </div>
        </section>

        <!-- Vídeos do usuário -->
        <section class="user-videos">
            <div class="section-header">
                <h3>Meus Vídeos</h3>
                <span class="video-count"><?php echo $total_user_videos; ?> vídeo<?php echo $total_user_videos != 1 ? 's' : ''; ?></span>
            </div>
            
            <?php if (empty($user_videos)): ?>
                <div class="empty-videos">
                    <i class="fas fa-video"></i>
                    <h4>Nenhum vídeo ainda</h4>
                    <p>Compartilhe seu primeiro vídeo com o mundo!</p>
                    <a href="upload.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        Criar Primeiro Vídeo
                    </a>
                </div>
            <?php else: ?>
                <div class="videos-grid" id="profileVideosGrid">
                    <?php foreach ($user_videos as $video): ?>
                        <div class="video-thumbnail" data-video-id="<?php echo (int)$video['id']; ?>" onclick="window.location.href='index.php?user_id=<?php echo $user_id; ?>&video_id=<?php echo $video['id']; ?>'">
                            <div class="thumbnail-container">
                                <?php if (!empty($video['thumbnail_path'])): ?>
                                    <img src="uploads/thumbnails/<?php echo htmlspecialchars($video['thumbnail_path']); ?>"
                                         alt="<?php echo htmlspecialchars($video['title']); ?>"
                                         loading="lazy"
                                         decoding="async">
                                <?php elseif (!empty($video['video_path'])): ?>
                                    <?php $resolved_url = resolve_video_url($video['video_path']); ?>
                                    <video 
                                        muted 
                                        preload="none"
                                        class="video-preview lazy-video-preview"
                                        data-video-src="<?php echo htmlspecialchars($resolved_url); ?>"
                                        onloadeddata="this.currentTime = 0.5;">
                                        <source data-src="<?php echo htmlspecialchars($resolved_url); ?>" type="video/mp4">
                                    </video>
                                    <div class="play-overlay">
                                        <i class="fas fa-play"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="default-thumbnail">
                                        <i class="fas fa-play"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="video-overlay">
                                    <div class="video-stats">
                                        <span class="stat">
                                            <i class="fas fa-heart"></i>
                                            <?php echo formatNumberShort($video['likes_count']); ?>
                                        </span>
                                        <span class="stat">
                                            <i class="fas fa-eye"></i>
                                            <?php echo formatNumberShort($video['views_count']); ?>
                                        </span>
                                        <span class="stat">
                                            <i class="fas fa-comment"></i>
                                            <?php echo formatNumberShort($video['comments_count']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="video-info">
                                <h4 class="video-title"><?php echo htmlspecialchars($video['title']); ?></h4>
                                <p class="video-date"><?php echo timeAgo($video['created_at']); ?></p>
                                
                                <button class="delete-video-btn" 
                                        data-video-id="<?php echo $video['id']; ?>"
                                        onclick="event.stopPropagation(); confirmDeleteVideo(<?php echo $video['id']; ?>)"
                                        title="Apagar vídeo">
                                    <i class="fas fa-trash"></i>
                                </button>
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

    <!-- Modal de edição de perfil -->
    <div class="modal-overlay" id="editModal">
        <div class="modal edit-profile-modal">
            <div class="modal-header">
                <h3>Editar Perfil</h3>
                <button class="close-btn" onclick="toggleEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="modal-body">
                <div class="form-group">
                    <label>Foto de Perfil</label>
                    <div class="avatar-upload">
                        <img src="<?php echo $avatarPath; ?>" 
                             alt="Avatar" class="current-avatar" id="avatarPreview"
                             onerror="this.onerror=null;this.src='assets/images/avatars/default.jpg';">
                        <input type="file" name="profile_picture" id="profilePicture" accept="image/*" onchange="previewAvatar(this)">
                        <label for="profilePicture" class="upload-btn">
                            <i class="fas fa-camera"></i>
                            Alterar Foto
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Nome Completo <small class="char-counter" id="nameCounter">0/25</small></label>
                    <input type="text" 
                           id="full_name" 
                           name="full_name" 
                           value="<?php echo htmlspecialchars($user['full_name']); ?>" 
                           class="form-input" 
                           maxlength="25"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="bio">Biografia <small class="char-counter" id="bioCounter">0/75</small></label>
                    <textarea id="bio" 
                              name="bio" 
                              class="form-textarea" 
                              rows="3" 
                              maxlength="75"
                              placeholder="Conte um pouco sobre você..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="instituicao">Instituição <small style="color:#64748b;">(opcional)</small></label>
                    <div style="position:relative;">
                        <input type="text" 
                               id="instituicao" 
                               name="instituicao" 
                               value="<?php echo htmlspecialchars($user['instituicao'] ?? ''); ?>" 
                               class="form-input" 
                               maxlength="150"
                               placeholder="Ex: 42Luanda, ISPTEC, UAN..."
                               autocomplete="off"
                               onfocus="showSchoolSuggestions()"
                               oninput="filterSchoolSuggestions(this.value)">
                        <div id="schoolSuggestions" class="school-suggestions" style="display:none;"></div>
                    </div>
                    <small style="color:#64748b; display:block; margin-top:4px;">Selecione da lista para vincular ao ranking</small>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="toggleEditModal()">
                        Cancelar
                    </button>
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmação de logout -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal logout-modal">
            <div class="modal-header">
                <h3>Confirmar Logout</h3>
            </div>
            <div class="modal-body">
                <p>Tem certeza de que deseja sair da sua conta?</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="toggleLogoutModal()">
                        Cancelar
                    </button>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="logout" class="btn btn-danger">
                            <i class="fas fa-sign-out-alt"></i>
                            Sair
                        </button>
                    </form>
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

    <!-- Dados dos vídeos para o JavaScript -->
    <script>
        const userVideos = <?php echo json_encode(array_map(function($v) {
            return [
                'id' => $v['id'],
                'title' => $v['title'],
                'video_path' => $v['video_path'],
                'video_url' => resolve_video_url($v['video_path']),
                'views_count' => $v['views_count'],
                'likes_count' => $v['likes_count'],
                'comments_count' => $v['comments_count']
            ];
        }, $user_videos)); ?>;
        const currentUserId = <?php echo $user_id; ?>;
        const profileVideosConfig = {
            userId: <?php echo (int)$user_id; ?>,
            pageLimit: <?php echo (int)$videos_page_limit; ?>,
            hasMore: <?php echo $has_more_videos ? 'true' : 'false'; ?>
        };
    </script>

    <script>
    (function() {
        const grid = document.getElementById('profileVideosGrid');
        const sentinel = document.getElementById('videosLoadSentinel');
        const statusEl = document.getElementById('videosLoadStatus');

        if (!grid || !sentinel || !statusEl || !profileVideosConfig) {
            return;
        }

        let nextPage = 2;
        let loading = false;
        let hasMore = !!profileVideosConfig.hasMore;
        const pageLimit = Number(profileVideosConfig.pageLimit) || 18;
        const userId = Number(profileVideosConfig.userId) || 0;
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
            const timeAgo = escHtml(video.time_ago || 'agora');
            const thumbSrc = video.thumbnail_path ? `uploads/thumbnails/${encodeURIComponent(video.thumbnail_path)}` : '';
            const videoSrc = video.video_url || (video.video_path ? resolveVideoUrl(video.video_path) : '');

            let mediaHtml = '<div class="default-thumbnail"><i class="fas fa-play"></i></div>';
            if (thumbSrc) {
                mediaHtml = `<img src="${thumbSrc}" alt="${title}" loading="lazy" decoding="async">`;
            } else if (videoSrc) {
                mediaHtml = `<video muted preload="none" class="video-preview lazy-video-preview" data-video-src="${videoSrc}" onloadeddata="this.currentTime = 0.5;"><source data-src="${videoSrc}" type="video/mp4"></video><div class="play-overlay"><i class="fas fa-play"></i></div>`;
            }

            return `
                <div class="video-thumbnail" data-video-id="${video.id}" onclick="window.location.href='index.php?user_id=${userId}&video_id=${video.id}'">
                    <div class="thumbnail-container">
                        ${mediaHtml}
                        <div class="video-overlay">
                            <div class="video-stats">
                                <span class="stat"><i class="fas fa-heart"></i>${formatNum(video.likes_count)}</span>
                                <span class="stat"><i class="fas fa-eye"></i>${formatNum(video.views_count)}</span>
                                <span class="stat"><i class="fas fa-comment"></i>${formatNum(video.comments_count)}</span>
                            </div>
                        </div>
                    </div>
                    <div class="video-info">
                        <h4 class="video-title">${title}</h4>
                        <p class="video-date">${timeAgo}</p>
                        <button class="delete-video-btn" data-video-id="${video.id}" onclick="event.stopPropagation(); confirmDeleteVideo(${video.id})" title="Apagar vídeo">
                            <i class="fas fa-trash"></i>
                        </button>
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

    <!-- Modal de Notificações -->
    <div class="notifications-modal" id="notificationsModal">
        <div class="notifications-modal-content">
            <div class="notifications-header">
                <h3>Notificações</h3>
                <div class="notifications-header-actions">
                    <button class="mark-all-read-btn" onclick="NotificationSystem.markAllAsRead()" title="Marcar todas como lidas">
                        Marcar todas como lidas
                    </button>
                    <button class="notifications-close" onclick="NotificationSystem.closeModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="notifications-list" id="notificationsList">
                <div class="notifications-empty">
                    <i class="fas fa-bell"></i>
                    <p>Nenhuma notificação</p>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset('assets/js/notifications.js'); ?>"></script>
    <script>
        // Função para abrir lista de chats
        function openChatList() {
            window.location.href = 'chat.php?from=profile';
        }
    </script>

    <!-- Modal de Seguidores / Seguindo -->
    <div class="modal-overlay" id="followModal" onclick="if(event.target===this)closeFollowModal()">
        <div class="modal follow-list-modal">
            <div class="modal-header">
                <h3 id="followModalTitle">Seguidores</h3>
                <button class="close-btn" onclick="closeFollowModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body follow-modal-body">
                <div class="follow-list" id="followList">
                    <div class="follow-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                </div>
                <button class="btn btn-secondary follow-load-more" id="followLoadMore" style="display:none" onclick="loadMoreFollows()">
                    Carregar mais
                </button>
            </div>
        </div>
    </div>

    <script>
    // === Limites de caracteres no formulário ===
    (function(){
        const nameInput = document.getElementById('full_name');
        const bioInput = document.getElementById('bio');
        const nameCounter = document.getElementById('nameCounter');
        const bioCounter = document.getElementById('bioCounter');

        function updateCounter(input, counter, max) {
            if (!input || !counter) return;
            const len = input.value.length;
            counter.textContent = len + '/' + max;
            counter.style.color = len >= max ? '#ef4444' : '';
        }

        if (nameInput) {
            updateCounter(nameInput, nameCounter, 25);
            nameInput.addEventListener('input', () => updateCounter(nameInput, nameCounter, 25));
        }

        if (bioInput) {
            updateCounter(bioInput, bioCounter, 75);
            bioInput.addEventListener('input', function() {
                // Limitar a 3 linhas
                const lines = this.value.split('\n');
                if (lines.length > 3) {
                    this.value = lines.slice(0, 3).join('\n');
                }
                updateCounter(this, bioCounter, 75);
            });
            bioInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    const lines = this.value.split('\n');
                    if (lines.length >= 3) {
                        e.preventDefault();
                    }
                }
            });
        }
    })();

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
                // Remove loading placeholder on first page
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
        const verified = u.is_verified ? ' <i class="fas fa-check-circle follow-verified"></i>' : '';
        const followBtn = u.is_me ? '' :
            `<button class="btn-follow-small ${u.is_followed_by_me ? 'following' : ''}" 
                     onclick="toggleFollowInModal(this, ${u.id})" data-uid="${u.id}">
                ${u.is_followed_by_me ? 'Seguindo' : 'Seguir'}
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
                btn.classList.toggle('following', data.is_following);
                btn.textContent = data.is_following ? 'Seguindo' : 'Seguir';
                // Atualizar contadores na página se for o próprio perfil
                if (data.followers_count !== undefined) {
                    const fc = document.getElementById('followersCount');
                    if (fc) fc.textContent = _formatNum(data.followers_count);
                }
            }
        } catch (e) { console.error(e); }
        finally { btn.disabled = false; }
    }

    function _formatNum(n) {
        n = parseInt(n) || 0;
        if (n >= 1e9) { let v = (n/1e9).toFixed(1); return (v.endsWith('.0') ? v.slice(0,-2) : v) + 'B'; }
        if (n >= 1e6) { let v = (n/1e6).toFixed(1); return (v.endsWith('.0') ? v.slice(0,-2) : v) + 'M'; }
        if (n >= 1e3) { let v = (n/1e3).toFixed(1); return (v.endsWith('.0') ? v.slice(0,-2) : v) + 'k'; }
        return n.toString();
    }

    // ESC para fechar
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFollowModal(); });
    </script>

    <!-- Modal Top 10 Vídeos -->
    <div class="modal-overlay" id="topVideosModal" onclick="if(event.target===this)closeTopVideosModal()">
        <div class="follow-list-modal">
            <div class="modal-header">
                <h3 id="topVideosModalTitle"><i class="fas fa-trophy" style="color:#f59e0b;margin-right:6px;"></i> Top 10 Vídeos</h3>
                <button class="close-btn" onclick="closeTopVideosModal()">
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
        .modal-overlay#topVideosModal { display: none; }
        .modal-overlay#topVideosModal.active { display: flex; }
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
        .top-video-stats i { margin-right: 3px; }
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

                    const escTitle = document.createElement('div');
                    escTitle.textContent = v.title || 'Sem título';

                    list.insertAdjacentHTML('beforeend', `
                        <div class="top-video-item" onclick="window.location.href='index.php?user_id=${userId}&video_id=${v.id}'">
                            <span class="top-video-rank ${rankClass}">${medal}</span>
                            ${thumbHtml}
                            <div class="top-video-info">
                                <div class="top-video-title">${escTitle.innerHTML}</div>
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
        const d = document.createElement('div'); d.textContent = inst;
        tooltip.innerHTML = '<span class="tooltip-label">Institui\u00e7\u00e3o</span>' + d.innerHTML;
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

    <style>
    /* School suggestions dropdown */
    .school-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #1e293b;
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 10px;
        max-height: 220px;
        overflow-y: auto;
        z-index: 1000;
        box-shadow: 0 8px 24px rgba(0,0,0,0.4);
        margin-top: 4px;
    }
    .school-suggestion-item {
        padding: 12px 16px;
        cursor: pointer;
        font-size: 14px;
        color: #e2e8f0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background 0.15s;
    }
    .school-suggestion-item:hover,
    .school-suggestion-item.active {
        background: rgba(99,102,241,0.15);
    }
    .school-suggestion-item:last-child {
        border-bottom: none;
    }
    .school-suggestion-icon {
        font-size: 16px;
        flex-shrink: 0;
    }
    .school-suggestion-name {
        flex: 1;
    }
    .school-suggestion-short {
        font-size: 11px;
        color: #64748b;
    }
    .school-suggestion-linked {
        font-size: 10px;
        color: #00d4ff;
        background: rgba(0,212,255,0.1);
        padding: 2px 6px;
        border-radius: 6px;
    }
    .school-suggestions::-webkit-scrollbar { width: 4px; }
    .school-suggestions::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
    </style>

    <script>
    // School dropdown logic
    let allSchoolsList = [];
    let schoolsLoaded = false;

    function loadSchoolsList() {
        if (schoolsLoaded) return Promise.resolve(allSchoolsList);
        return fetch('api/get_rankings.php?action=list_schools')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    allSchoolsList = data.schools;
                    schoolsLoaded = true;
                }
                return allSchoolsList;
            });
    }

    function showSchoolSuggestions() {
        loadSchoolsList().then(schools => {
            renderSchoolSuggestions(schools);
        });
    }

    function filterSchoolSuggestions(query) {
        query = query.toLowerCase().trim();
        if (!schoolsLoaded) {
            loadSchoolsList().then(() => filterSchoolSuggestions(query));
            return;
        }
        if (!query) {
            renderSchoolSuggestions(allSchoolsList);
            return;
        }
        const filtered = allSchoolsList.filter(s =>
            s.name.toLowerCase().includes(query) ||
            (s.short_name && s.short_name.toLowerCase().includes(query))
        );
        renderSchoolSuggestions(filtered);
    }

    function renderSchoolSuggestions(schools) {
        const el = document.getElementById('schoolSuggestions');
        const input = document.getElementById('instituicao');
        const currentVal = input.value.trim().toLowerCase();
        
        if (schools.length === 0) {
            el.innerHTML = '<div class="school-suggestion-item" style="color:#64748b; cursor:default;"><span class="school-suggestion-icon">🔍</span><span>Nenhuma escola encontrada</span></div>';
            el.style.display = 'block';
            return;
        }

        el.innerHTML = schools.map(s => {
            const isSelected = s.name.toLowerCase() === currentVal;
            return `<div class="school-suggestion-item ${isSelected ? 'active' : ''}" onclick="selectSchoolSuggestion('${escAttr(s.name)}')">
                <span class="school-suggestion-icon">🏫</span>
                <span class="school-suggestion-name">${escText(s.name)}</span>
                ${s.short_name ? `<span class="school-suggestion-short">${escText(s.short_name)}</span>` : ''}
                ${isSelected ? '<span class="school-suggestion-linked">✓ vinculada</span>' : ''}
            </div>`;
        }).join('');
        el.style.display = 'block';
    }

    function selectSchoolSuggestion(name) {
        const input = document.getElementById('instituicao');
        input.value = name;
        document.getElementById('schoolSuggestions').style.display = 'none';
    }

    function escText(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function escAttr(s) {
        return s.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const suggestions = document.getElementById('schoolSuggestions');
        const input = document.getElementById('instituicao');
        if (suggestions && input && !input.contains(e.target) && !suggestions.contains(e.target)) {
            suggestions.style.display = 'none';
        }
    });
    </script>

    <!-- Action Sheet do Avatar -->
    <div class="avatar-action-overlay" id="avatarActionOverlay" onclick="closeAvatarActionSheet()">
        <div class="avatar-action-sheet" onclick="event.stopPropagation()">
            <div class="action-sheet-header">
                <span class="action-sheet-title">Foto de Perfil</span>
            </div>
            <button class="action-sheet-btn" onclick="viewProfilePhoto()">
                <i class="fas fa-eye"></i>
                <span>Ver foto</span>
            </button>
            <button class="action-sheet-btn" onclick="showProfileInfoModal()">
                <i class="fas fa-info-circle"></i>
                <span>Informações</span>
            </button>
            <button class="action-sheet-btn" onclick="changeProfilePhoto()">
                <i class="fas fa-camera"></i>
                <span>Mudar foto de perfil</span>
            </button>
            <button class="action-sheet-btn action-sheet-cancel" onclick="closeAvatarActionSheet()">
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

    <!-- Modal de Informações do Utilizador (profile.php) -->
    <div class="user-info-modal-overlay" id="userInfoModalOverlay" onclick="closeProfileInfoModal()">
        <div class="user-info-modal" onclick="event.stopPropagation()">
            <div class="user-info-modal-header">
                <h3><i class="fas fa-user-circle"></i> Informações</h3>
                <button class="user-info-modal-close" onclick="closeProfileInfoModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="user-info-modal-body">
                <div class="user-info-avatar">
                    <img src="assets/images/avatars/<?php echo $user['profile_picture'] ?? 'default.webp'; ?>" alt="" loading="lazy">
                </div>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-user"></i> Nome</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($user['full_name']); ?></span>
                </div>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-at"></i> Username</span>
                    <span class="user-info-value">@<?php echo htmlspecialchars($user['username']); ?></span>
                </div>
                <?php if (!empty($user['bio'])): ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-quote-left"></i> Bio</span>
                    <span class="user-info-value"><?php echo nl2br(htmlspecialchars($user['bio'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user['instituicao'])): ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-university"></i> Instituição</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($user['instituicao']); ?></span>
                </div>
                <?php endif; ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-calendar"></i> Membro desde</span>
                    <span class="user-info-value"><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></span>
                </div>
                <?php if ($user['is_verified']): ?>
                <div class="user-info-item">
                    <span class="user-info-label"><i class="fas fa-check-circle" style="color:#818cf8"></i> Estado</span>
                    <span class="user-info-value" style="color:#818cf8">Verificado</span>
                </div>
                <?php endif; ?>
                <div class="user-info-stats-row">
                    <div class="user-info-stat">
                        <span class="stat-num"><?php echo formatNumberShort($user['videos_count']); ?></span>
                        <span class="stat-lbl">Vídeos</span>
                    </div>
                    <div class="user-info-stat">
                        <span class="stat-num"><?php echo formatNumberShort($user['followers_count']); ?></span>
                        <span class="stat-lbl">Seguidores</span>
                    </div>
                    <div class="user-info-stat">
                        <span class="stat-num"><?php echo formatNumberShort($user['following_count']); ?></span>
                        <span class="stat-lbl">Seguindo</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
    /* === Action Sheet === */
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
    /* === User Info Modal (profile.php) === */
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
    /* === Best MyTuber Badges === */
    .best-mytuber-badge {
        position: absolute;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        color: #fff;
        border: 2.5px solid #0f172a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        z-index: 3;
        cursor: default;
    }
    .best-mytuber-badge i {
        filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3));
    }
    /* Global Badge = Dourado com coroa */
    .best-mytuber-global {
        top: -4px;
        right: -4px;
        background: linear-gradient(135deg, #FFD700, #f59e0b, #f97316);
        box-shadow: 0 2px 12px rgba(255, 215, 0, 0.6);
        animation: badgePulseGlobal 2.5s ease-in-out infinite;
    }
    /* School Badge = Azul/Prata com medalha */
    .best-mytuber-school {
        top: -4px;
        right: 28px;
        background: linear-gradient(135deg, #60a5fa, #3b82f6, #6366f1);
        box-shadow: 0 2px 12px rgba(59, 130, 246, 0.6);
        animation: badgePulseSchool 2.5s ease-in-out infinite;
    }
    /* Se tem ambos, escola fica mais à esquerda */
    .best-mytuber-global + .best-mytuber-school {
        right: 28px;
    }
    /* Se só tem escola badge, fica na posição principal */
    .best-mytuber-school:first-of-type:last-of-type {
        right: -4px;
    }
    @keyframes badgePulseGlobal {
        0%, 100% { box-shadow: 0 2px 12px rgba(255, 215, 0, 0.5); transform: scale(1); }
        50%      { box-shadow: 0 4px 20px rgba(255, 215, 0, 0.9); transform: scale(1.1); }
    }
    @keyframes badgePulseSchool {
        0%, 100% { box-shadow: 0 2px 12px rgba(59, 130, 246, 0.5); transform: scale(1); }
        50%      { box-shadow: 0 4px 20px rgba(59, 130, 246, 0.9); transform: scale(1.1); }
    }
    @media (min-width: 768px) {
        .avatar-action-sheet {
            border-radius: 16px;
            margin-bottom: 40px;
        }
    }
    </style>

    <script>
    // === Avatar Action Sheet (profile.php) ===
    function openAvatarActionSheet() {
        document.getElementById('avatarActionOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeAvatarActionSheet() {
        document.getElementById('avatarActionOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    function viewProfilePhoto() {
        closeAvatarActionSheet();
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
    function changeProfilePhoto() {
        closeAvatarActionSheet();
        const editModal = document.getElementById('editModal');
        if (!editModal.classList.contains('active')) {
            toggleEditModal();
        }
        setTimeout(() => {
            const fileInput = document.getElementById('profilePicture');
            if (fileInput) fileInput.click();
        }, 350);
    }
    function showProfileInfoModal() {
        closeAvatarActionSheet();
        setTimeout(() => {
            document.getElementById('userInfoModalOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }, 150);
    }
    function closeProfileInfoModal() {
        document.getElementById('userInfoModalOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (document.getElementById('photoViewerOverlay').classList.contains('active')) {
                closePhotoViewer();
            } else if (document.getElementById('userInfoModalOverlay').classList.contains('active')) {
                closeProfileInfoModal();
            } else if (document.getElementById('avatarActionOverlay').classList.contains('active')) {
                closeAvatarActionSheet();
            }
        }
    });
    </script>

    <script src="<?php echo asset('assets/js/profile.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/video-delete.js'); ?>"></script>
    <?php include 'includes/presence_bootstrap.php'; ?>
</body>
</html>