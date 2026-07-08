<?php
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';
require_once 'includes/content_moderation.php';

ensureUserData();

if (!isLoggedIn()) redirect('login.php');
if (!isAdminUser()) redirect('index.php');

// ── Helpers ───────────────────────────────────────────────────
function apShortText(string $text, int $limit = 90): string {
    $text = trim($text);
    if ($text === '') return '';
    if (function_exists('mb_strimwidth')) return mb_strimwidth($text, 0, $limit, '…');
    return strlen($text) > $limit ? substr($text, 0, $limit - 1) . '…' : $text;
}

// ── Overview stats ────────────────────────────────────────────
$total_videos   = (int)$pdo->query("SELECT COUNT(*) FROM videos")->fetchColumn();
$total_users    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(role,'user') != 'admin'")->fetchColumn();
$pending_count  = (int)$pdo->query("SELECT COUNT(*) FROM videos WHERE moderation_status='pending'")->fetchColumn();
$rejected_count = (int)$pdo->query("SELECT COUNT(*) FROM videos WHERE moderation_status='rejected'")->fetchColumn();
$boosted_total  = (int)$pdo->query("SELECT COUNT(*) FROM videos WHERE is_boosted=1 AND is_public=1")->fetchColumn();
$total_views    = (int)$pdo->query("SELECT COALESCE(SUM(views_count),0) FROM videos")->fetchColumn();
$total_likes    = (int)$pdo->query("SELECT COALESCE(SUM(likes_count),0) FROM videos")->fetchColumn();
$videos_today   = (int)$pdo->query("SELECT COUNT(*) FROM videos WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// ── Pending moderation ────────────────────────────────────────
$pending_stmt = $pdo->query("
    SELECT v.id, v.title, v.video_path, v.thumbnail_path,
           v.moderation_score, v.created_at,
           u.id AS user_id, u.username, u.full_name, u.profile_picture, u.is_verified
    FROM videos v
    INNER JOIN users u ON v.user_id = u.id
    WHERE v.moderation_status = 'pending'
    ORDER BY v.created_at ASC
    LIMIT 50
");
$pending_videos = $pending_stmt->fetchAll();

// ── Reported videos ───────────────────────────────────────────
$reported_stmt = $pdo->query("
    SELECT v.id, v.title, v.video_path, v.thumbnail_path,
           v.reports_count, v.is_hidden, v.created_at,
           u.id AS user_id, u.username
    FROM videos v
    INNER JOIN users u ON v.user_id = u.id
    WHERE v.reports_count > 0
    ORDER BY v.reports_count DESC, v.created_at DESC
    LIMIT 100
");
$reported_videos = $reported_stmt->fetchAll();

// ── Boosted videos ────────────────────────────────────────────
$boosted_stmt = $pdo->query("
    SELECT v.id, v.user_id, v.title, v.description, v.video_path, v.thumbnail_path,
           v.views_count, v.likes_count, v.comments_count, v.created_at, v.updated_at,
           u.username, u.full_name, u.profile_picture, u.is_verified
    FROM videos v
    INNER JOIN users u ON u.id = v.user_id
    WHERE v.is_public = 1 AND v.is_boosted = 1
    ORDER BY v.updated_at DESC, v.created_at DESC
");
$boosted_videos   = $boosted_stmt->fetchAll();
$boosted_count    = count($boosted_videos);
$boosted_creators = count(array_unique(array_map(static fn($v) => (int)$v['user_id'], $boosted_videos)));
$boosted_views    = array_sum(array_map(static fn($v) => (int)$v['views_count'], $boosted_videos));

// ── Rankings: top criadores ───────────────────────────────────
$top_creators = $pdo->query("
    SELECT u.id, u.username, u.full_name, u.profile_picture, u.is_verified,
           u.ranking_points, u.videos_count, u.followers_count,
           COALESCE(SUM(v.views_count), 0) AS total_views
    FROM users u
    LEFT JOIN videos v ON v.user_id = u.id AND v.is_public = 1
    WHERE COALESCE(u.role,'user') != 'admin'
    GROUP BY u.id
    ORDER BY u.ranking_points DESC
    LIMIT 10
")->fetchAll();

// ── Rankings: top vídeos ──────────────────────────────────────
$top_videos_rank = $pdo->query("
    SELECT v.id, v.title, v.thumbnail_path, v.views_count, v.likes_count,
           v.comments_count, v.created_at,
           u.username, u.is_verified
    FROM videos v
    INNER JOIN users u ON v.user_id = u.id
    WHERE v.is_public = 1 AND v.moderation_status = 'approved'
    ORDER BY v.views_count DESC
    LIMIT 10
")->fetchAll();

$nudenet_available = moderation_is_nudenet_available();

// ── Premium users ─────────────────────────────────────────────
// Column may not exist yet if migration hasn't run — safe fallback
try {
    $premium_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_premium = 1")->fetchColumn();
} catch (Exception $e) {
    $premium_count = 0;
}

// ── Ad campaigns ──────────────────────────────────────────────
try {
    $ads_pending = (int)$pdo->query("SELECT COUNT(*) FROM ad_campaigns WHERE status='pending'")->fetchColumn();
    $ads_active  = (int)$pdo->query("SELECT COUNT(*) FROM ad_campaigns WHERE status='active'")->fetchColumn();
    $ads_revenue = (int)$pdo->query("SELECT COALESCE(SUM(plan_price_kz),0) FROM ad_campaigns WHERE status IN ('active','expired')")->fetchColumn();
    $ads_all_stmt = $pdo->query("
        SELECT c.*, v.title AS video_title, v.thumbnail_path,
               u.username, u.full_name, u.profile_picture, u.is_verified
        FROM ad_campaigns c
        INNER JOIN videos v ON v.id = c.video_id
        INNER JOIN users  u ON u.id = c.user_id
        ORDER BY
            FIELD(c.status,'pending','active','paused','expired','rejected'),
            c.created_at DESC
        LIMIT 100
    ");
    $ads_all = $ads_all_stmt->fetchAll();
} catch (Exception $e) {
    $ads_pending = $ads_active = $ads_revenue = 0;
    $ads_all = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <title>Painel Admin — MyTube</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/boosted-videos.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body class="ap-page">

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
═══════════════════════════════════════════════════════════════ -->
<nav class="ap-sidebar" id="apSidebar">
    <div class="ap-sidebar-brand">
        <span class="ap-brand-icon">MT</span>
        <span class="ap-brand-label">Admin</span>
    </div>

    <ul class="ap-nav-list">
        <li class="ap-nav-item active" data-section="overview" role="button" tabindex="0">
            <i class="fas fa-chart-line"></i>
            <span>Visão Geral</span>
        </li>
        <li class="ap-nav-item" data-section="moderation" role="button" tabindex="0">
            <i class="fas fa-shield-halved"></i>
            <span>Moderação</span>
            <?php if ($pending_count > 0): ?>
                <span class="ap-badge" id="apModerationBadge"><?php echo $pending_count; ?></span>
            <?php endif; ?>
        </li>
        <li class="ap-nav-item" data-section="boosted" role="button" tabindex="0">
            <i class="fas fa-bolt"></i>
            <span>Boosted</span>
            <?php if ($boosted_count > 0): ?>
                <span class="ap-badge ap-badge-yellow"><?php echo $boosted_count; ?></span>
            <?php endif; ?>
        </li>
        <li class="ap-nav-item" data-section="rankings" role="button" tabindex="0">
            <i class="fas fa-trophy"></i>
            <span>Rankings</span>
        </li>
        <li class="ap-nav-item" data-section="premium" role="button" tabindex="0">
            <i class="fas fa-star"></i>
            <span>Premium</span>
            <?php if ($premium_count > 0): ?>
                <span class="ap-badge ap-badge-gold"><?php echo $premium_count; ?></span>
            <?php endif; ?>
        </li>

        <li class="ap-nav-item" data-section="ads" role="button" tabindex="0">
            <i class="fas fa-rectangle-ad"></i>
            <span>Anúncios</span>
            <?php if ($ads_pending > 0): ?>
                <span class="ap-badge ap-badge-orange"><?php echo $ads_pending; ?></span>
            <?php endif; ?>
        </li>

        <li class="ap-nav-divider"></li>

        <!-- Secções futuras (placeholder) -->
        <li class="ap-nav-item ap-nav-future" title="Em breve">
            <i class="fas fa-chart-bar"></i>
            <span>Analytics</span>
            <span class="ap-soon-chip">em breve</span>
        </li>
        <li class="ap-nav-item" data-section="users" role="button" tabindex="0">
            <i class="fas fa-users"></i>
            <span>Utilizadores</span>
            <span class="ap-badge ap-badge-green" id="usrOnlineBadge" style="display:none">0</span>
        </li>
        <li class="ap-nav-item" data-section="reports" role="button" tabindex="0">
            <i class="fas fa-flag"></i>
            <span>Denúncias</span>
            <?php if (count($reported_videos) > 0): ?>
                <span class="ap-badge ap-badge-orange"><?php echo count($reported_videos); ?></span>
            <?php endif; ?>
        </li>
        <li class="ap-nav-item ap-nav-future" title="Em breve">
            <i class="fas fa-gear"></i>
            <span>Configurações</span>
            <span class="ap-soon-chip">em breve</span>
        </li>
    </ul>

    <div class="ap-sidebar-footer">
        <a href="index.php" class="ap-back-btn">
            <i class="fas fa-arrow-left"></i>
            <span>Voltar ao feed</span>
        </a>
    </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════
     CONTEÚDO PRINCIPAL
═══════════════════════════════════════════════════════════════ -->
<div class="ap-main" id="apMain">

    <!-- ──────────────────────────────────────────────────────
         SECÇÃO: VISÃO GERAL
    ────────────────────────────────────────────────────────── -->
    <section class="ap-section active" id="section-overview">
        <div class="ap-section-header">
            <div>
                <p class="ap-eyebrow">Painel Admin</p>
                <h1>Visão Geral</h1>
            </div>
            <div class="ap-header-actions">
                <?php if ($pending_count > 0): ?>
                    <button class="ap-btn ap-btn-warn ap-nav-trigger" data-section="moderation">
                        <i class="fas fa-shield-halved"></i>
                        <?php echo $pending_count; ?> pendente<?php echo $pending_count !== 1 ? 's' : ''; ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats grid -->
        <div class="ap-stats-grid">
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(59,130,246,.15);color:#3b82f6">
                    <i class="fas fa-video"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Total de vídeos</span>
                    <strong class="ap-stat-value"><?php echo formatNumberShort($total_videos); ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(16,185,129,.15);color:#10b981">
                    <i class="fas fa-users"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Utilizadores</span>
                    <strong class="ap-stat-value"><?php echo formatNumberShort($total_users); ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(239,68,68,.15);color:#ef4444">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Views totais</span>
                    <strong class="ap-stat-value"><?php echo formatNumberShort($total_views); ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(244,63,94,.15);color:#f43f5e">
                    <i class="fas fa-heart"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Likes totais</span>
                    <strong class="ap-stat-value"><?php echo formatNumberShort($total_likes); ?></strong>
                </div>
            </div>
            <div class="ap-stat-card <?php echo $pending_count > 0 ? 'ap-stat-card--alert' : ''; ?>">
                <div class="ap-stat-icon" style="background:rgba(245,158,11,.15);color:#f59e0b">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Pendentes mod.</span>
                    <strong class="ap-stat-value"><?php echo $pending_count; ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(239,68,68,.15);color:#ef4444">
                    <i class="fas fa-ban"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Rejeitados</span>
                    <strong class="ap-stat-value"><?php echo $rejected_count; ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(234,179,8,.15);color:#facc15">
                    <i class="fas fa-bolt"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Vídeos boosted</span>
                    <strong class="ap-stat-value"><?php echo $boosted_total; ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(139,92,246,.15);color:#8b5cf6">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Uploads hoje</span>
                    <strong class="ap-stat-value"><?php echo $videos_today; ?></strong>
                </div>
            </div>
        </div>

        <!-- Estado do sistema -->
        <div class="ap-system-status">
            <h2 class="ap-section-title"><i class="fas fa-server"></i> Estado do Sistema</h2>
            <div class="ap-status-grid">
                <div class="ap-status-item">
                    <span class="ap-status-dot <?php echo $nudenet_available ? 'ap-dot-green' : 'ap-dot-yellow'; ?>"></span>
                    <div>
                        <strong>NudeNet (moderação IA)</strong>
                        <span><?php echo $nudenet_available ? 'Ativo — análise automática a funcionar' : 'Inativo — modo de revisão manual ativo'; ?></span>
                        <?php if (!$nudenet_available): ?>
                            <code class="ap-status-hint">bash moderation/install.sh</code>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ap-status-item">
                    <span class="ap-status-dot ap-dot-green"></span>
                    <div>
                        <strong>Base de dados</strong>
                        <span>Conectada e operacional</span>
                    </div>
                </div>
                <div class="ap-status-item">
                    <span class="ap-status-dot <?php echo defined('R2_ENABLED') && R2_ENABLED ? 'ap-dot-green' : 'ap-dot-blue'; ?>"></span>
                    <div>
                        <strong>Armazenamento</strong>
                        <span><?php echo (defined('R2_ENABLED') && R2_ENABLED) ? 'Cloudflare R2 ativo' : 'Armazenamento local'; ?></span>
                    </div>
                </div>
                <!-- Placeholder: futuras verificações -->
                <div class="ap-status-item ap-status-future">
                    <span class="ap-status-dot ap-dot-gray"></span>
                    <div>
                        <strong>Push Notifications</strong>
                        <span class="ap-status-hint-text">Monitorização em breve</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ações rápidas -->
        <div class="ap-quick-actions">
            <h2 class="ap-section-title"><i class="fas fa-zap"></i> Ações Rápidas</h2>
            <div class="ap-qa-grid">
                <button class="ap-qa-card ap-nav-trigger" data-section="moderation">
                    <i class="fas fa-shield-halved"></i>
                    <strong>Rever moderação</strong>
                    <span><?php echo $pending_count; ?> pendente<?php echo $pending_count !== 1 ? 's' : ''; ?></span>
                </button>
                <button class="ap-qa-card ap-nav-trigger" data-section="boosted">
                    <i class="fas fa-bolt"></i>
                    <strong>Gerir boosted</strong>
                    <span><?php echo $boosted_count; ?> ativo<?php echo $boosted_count !== 1 ? 's' : ''; ?></span>
                </button>
                <button class="ap-qa-card ap-nav-trigger" data-section="rankings">
                    <i class="fas fa-trophy"></i>
                    <strong>Ver rankings</strong>
                    <span>Top criadores e vídeos</span>
                </button>
                <a href="upload.php" class="ap-qa-card">
                    <i class="fas fa-plus-circle"></i>
                    <strong>Novo upload</strong>
                    <span>Publicar vídeo</span>
                </a>
            </div>
        </div>
    </section>

    <!-- ──────────────────────────────────────────────────────
         SECÇÃO: MODERAÇÃO
    ────────────────────────────────────────────────────────── -->
    <section class="ap-section" id="section-moderation">
        <div class="ap-section-header">
            <div>
                <p class="ap-eyebrow">Moderação de Conteúdo</p>
                <h1>Revisão Manual</h1>
            </div>
            <div class="ap-header-actions">
                <span class="ap-count-pill" id="pendingCountPill">
                    <?php echo $pending_count; ?> vídeo<?php echo $pending_count !== 1 ? 's' : ''; ?> pendente<?php echo $pending_count !== 1 ? 's' : ''; ?>
                </span>
            </div>
        </div>

        <?php if (!$nudenet_available): ?>
            <div class="ap-notice ap-notice--warn">
                <i class="fas fa-triangle-exclamation"></i>
                <div>
                    <strong>NudeNet não está instalado.</strong>
                    Novos uploads ficam em fila de revisão manual. Para ativar a análise automática no servidor:
                    <code>bash moderation/install.sh</code>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($pending_videos)): ?>
            <div class="ap-empty-state" id="moderationEmptyState">
                <i class="fas fa-shield-check"></i>
                <h3>Sem vídeos pendentes</h3>
                <p>Todos os vídeos foram analisados. Boa trabalho!</p>
            </div>
        <?php else: ?>
            <div class="ap-mod-grid" id="moderationGrid">
                <?php foreach ($pending_videos as $v): ?>
                    <article class="ap-mod-card" id="modCard_<?php echo (int)$v['id']; ?>" data-video-id="<?php echo (int)$v['id']; ?>">
                        <div class="ap-mod-thumb" onclick="window.open('index.php?video_id=<?php echo (int)$v['id']; ?>','_blank')">
                            <?php if (!empty($v['thumbnail_path']) && file_exists(__DIR__ . '/uploads/thumbnails/' . $v['thumbnail_path'])): ?>
                                <img src="uploads/thumbnails/<?php echo htmlspecialchars($v['thumbnail_path']); ?>" alt="" loading="lazy">
                            <?php elseif (!empty($v['video_path'])): ?>
                                <video preload="none" muted playsinline>
                                    <source src="<?php echo htmlspecialchars(resolve_video_url($v['video_path'])); ?>" type="video/mp4">
                                </video>
                            <?php else: ?>
                                <div class="ap-mod-thumb-fallback"><i class="fas fa-video"></i></div>
                            <?php endif; ?>
                            <div class="ap-mod-thumb-overlay">
                                <i class="fas fa-up-right-from-square"></i>
                            </div>
                        </div>

                        <div class="ap-mod-body">
                            <div class="ap-mod-author">
                                <img src="<?php echo htmlspecialchars(avatar_url($v['profile_picture'] ?? null)); ?>"
                                     alt="" class="ap-mod-avatar" loading="lazy">
                                <div>
                                    <span class="ap-mod-author-name">
                                        <?php echo htmlspecialchars($v['full_name'] ?: $v['username']); ?>
                                        <?php if (!empty($v['is_verified'])): ?><i class="fas fa-check-circle" style="color:#3b82f6;font-size:.8em"></i><?php endif; ?>
                                    </span>
                                    <span class="ap-mod-author-user">@<?php echo htmlspecialchars($v['username']); ?></span>
                                </div>
                            </div>

                            <h3 class="ap-mod-title"><?php echo htmlspecialchars($v['title']); ?></h3>

                            <div class="ap-mod-meta">
                                <span><i class="fas fa-clock"></i> <?php echo timeAgo($v['created_at']); ?></span>
                                <?php if ($v['moderation_score'] !== null): ?>
                                    <span class="ap-mod-score">
                                        <i class="fas fa-robot"></i>
                                        Score: <?php echo number_format((float)$v['moderation_score'] * 100, 0); ?>%
                                    </span>
                                <?php else: ?>
                                    <span class="ap-mod-score-na"><i class="fas fa-robot"></i> Sem score</span>
                                <?php endif; ?>
                            </div>

                            <div class="ap-mod-actions">
                                <button class="ap-btn ap-btn-approve js-mod-approve"
                                        data-video-id="<?php echo (int)$v['id']; ?>">
                                    <i class="fas fa-check"></i> Aprovar
                                </button>
                                <button class="ap-btn ap-btn-reject js-mod-reject"
                                        data-video-id="<?php echo (int)$v['id']; ?>">
                                    <i class="fas fa-xmark"></i> Rejeitar
                                </button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ──────────────────────────────────────────────────────
         SECÇÃO: BOOSTED
    ────────────────────────────────────────────────────────── -->
    <section class="ap-section" id="section-boosted">
        <div class="ap-section-header">
            <div>
                <p class="ap-eyebrow">Vídeos em Destaque</p>
                <h1>Boosted</h1>
            </div>
            <a href="index.php" class="ap-btn ap-btn-secondary">
                <i class="fas fa-play"></i> Abrir feed
            </a>
        </div>

        <!-- Mini stats de boost -->
        <div class="ap-stats-grid ap-stats-grid--sm" style="margin-bottom:28px">
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(234,179,8,.15);color:#facc15"><i class="fas fa-bolt"></i></div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Boosted ativos</span>
                    <strong class="ap-stat-value" id="boostedCount"><?php echo $boosted_count; ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(59,130,246,.15);color:#3b82f6"><i class="fas fa-user"></i></div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Criadores</span>
                    <strong class="ap-stat-value" id="boostedCreatorsCount"><?php echo $boosted_creators; ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(239,68,68,.15);color:#ef4444"><i class="fas fa-eye"></i></div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Views somadas</span>
                    <strong class="ap-stat-value" id="boostedViewsCount"><?php echo formatNumberShort($boosted_views); ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(16,185,129,.15);color:#10b981"><i class="fas fa-percent"></i></div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">CTR médio (30d)</span>
                    <strong class="ap-stat-value" id="boostedAvgCtr">
                        <i class="fas fa-spinner fa-spin" style="font-size:.9rem;color:#64748b"></i>
                    </strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(139,92,246,.15);color:#8b5cf6"><i class="fas fa-eye"></i></div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Impressões (30d)</span>
                    <strong class="ap-stat-value" id="boostedImpressions">
                        <i class="fas fa-spinner fa-spin" style="font-size:.9rem;color:#64748b"></i>
                    </strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(244,63,94,.15);color:#f43f5e"><i class="fas fa-users"></i></div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Alcance único (30d)</span>
                    <strong class="ap-stat-value" id="boostedReach">
                        <i class="fas fa-spinner fa-spin" style="font-size:.9rem;color:#64748b"></i>
                    </strong>
                </div>
            </div>
        </div>

        <div class="ap-list-header">
            <h2>Todos os vídeos em destaque</h2>
            <span class="ap-list-meta" id="boostedListMeta"><?php echo $boosted_count; ?> vídeo<?php echo $boosted_count !== 1 ? 's' : ''; ?> com boost ativo</span>
        </div>

        <div class="ap-empty-state<?php echo $boosted_count > 0 ? ' ap-hidden' : ''; ?>" id="boostedEmptyState">
            <i class="fas fa-bolt"></i>
            <h3>Nenhum vídeo boosted</h3>
            <p>Dá boost em vídeos no feed para os ver aqui.</p>
            <a href="index.php" class="ap-btn ap-btn-primary">
                <i class="fas fa-home"></i> Ir para o feed
            </a>
        </div>

        <div class="boosted-grid<?php echo $boosted_count === 0 ? ' ap-hidden' : ''; ?>" id="boostedGrid">
            <?php foreach ($boosted_videos as $video): ?>
                <article class="card boosted-video-card"
                         data-video-id="<?php echo (int)$video['id']; ?>"
                         data-user-id="<?php echo (int)$video['user_id']; ?>"
                         data-views="<?php echo (int)$video['views_count']; ?>">
                    <div class="boosted-video-media" onclick="window.location.href='index.php?video_id=<?php echo (int)$video['id']; ?>'">
                        <?php if (!empty($video['thumbnail_path']) && file_exists(__DIR__ . '/uploads/thumbnails/' . $video['thumbnail_path'])): ?>
                            <img src="uploads/thumbnails/<?php echo htmlspecialchars($video['thumbnail_path']); ?>"
                                 alt="<?php echo htmlspecialchars($video['title']); ?>" loading="lazy">
                        <?php elseif (!empty($video['video_path'])): ?>
                            <video preload="metadata" muted loop playsinline class="video-preview-player lazy-video">
                                <source src="<?php echo htmlspecialchars(resolve_video_url($video['video_path'])); ?>" type="video/mp4">
                            </video>
                        <?php else: ?>
                            <div class="boosted-video-fallback"><i class="fas fa-play"></i></div>
                        <?php endif; ?>
                        <div class="boosted-media-overlay">
                            <span class="boost-chip"><i class="fas fa-bolt"></i> Boosted</span>
                            <button type="button" class="boosted-open-btn"
                                    onclick="event.stopPropagation();window.location.href='index.php?video_id=<?php echo (int)$video['id']; ?>'">
                                <i class="fas fa-up-right-from-square"></i> Abrir
                            </button>
                        </div>
                    </div>
                    <div class="boosted-video-body">
                        <div class="boosted-author-row">
                            <a href="perfil.php?id=<?php echo (int)$video['user_id']; ?>" class="boosted-author-link">
                                <img src="<?php echo htmlspecialchars(avatar_url($video['profile_picture'] ?? null)); ?>"
                                     alt="" class="boosted-author-avatar" loading="lazy">
                                <div>
                                    <div class="boosted-author-name">
                                        <?php echo htmlspecialchars($video['full_name'] ?: $video['username']); ?>
                                        <?php if (!empty($video['is_verified'])): ?><i class="fas fa-check-circle"></i><?php endif; ?>
                                    </div>
                                    <div class="boosted-author-username">@<?php echo htmlspecialchars($video['username']); ?></div>
                                </div>
                            </a>
                        </div>
                        <h3 class="boosted-video-title"><?php echo htmlspecialchars($video['title']); ?></h3>
                        <?php $desc = apShortText($video['description'] ?? ''); ?>
                        <?php if ($desc !== ''): ?>
                            <p class="boosted-video-description"><?php echo htmlspecialchars($desc); ?></p>
                        <?php endif; ?>
                        <div class="boosted-video-stats">
                            <span><i class="fas fa-eye"></i> <?php echo formatNumberShort($video['views_count']); ?></span>
                            <span><i class="fas fa-heart"></i> <?php echo formatNumberShort($video['likes_count']); ?></span>
                            <span><i class="fas fa-comment"></i> <?php echo formatNumberShort($video['comments_count']); ?></span>
                            <span><i class="fas fa-clock"></i> <?php echo timeAgo($video['created_at']); ?></span>
                        </div>
                        <div class="boosted-ctr-bar" id="ctrBar_<?php echo (int)$video['id']; ?>">
                            <div class="ctr-bar-header">
                                <span class="ctr-label">CTR</span>
                                <span class="ctr-value" id="ctrValue_<?php echo (int)$video['id']; ?>">—</span>
                            </div>
                            <div class="ctr-bar-track">
                                <div class="ctr-bar-fill" id="ctrFill_<?php echo (int)$video['id']; ?>" style="width:0%"></div>
                            </div>
                            <div class="ctr-bar-details">
                                <span id="ctrImpressions_<?php echo (int)$video['id']; ?>">— impressões</span>
                                <span id="ctrReach_<?php echo (int)$video['id']; ?>">— users</span>
                            </div>
                        </div>
                        <div class="boosted-video-actions">
                            <a href="index.php?video_id=<?php echo (int)$video['id']; ?>" class="btn btn-secondary btn-sm">
                                <i class="fas fa-play"></i> Ver
                            </a>
                            <button type="button" class="btn btn-sm btn-boost-remove js-remove-boost"
                                    data-video-id="<?php echo (int)$video['id']; ?>">
                                <i class="fas fa-bolt"></i> Remover boost
                            </button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ──────────────────────────────────────────────────────
         SECÇÃO: RANKINGS
    ────────────────────────────────────────────────────────── -->
    <section class="ap-section" id="section-rankings">
        <div class="ap-section-header">
            <div>
                <p class="ap-eyebrow">Dados de Desempenho</p>
                <h1>Rankings</h1>
            </div>
        </div>

        <!-- Sub-tabs de ranking -->
        <div class="ap-subtabs" id="rankingSubtabs">
            <button class="ap-subtab active" data-subtab="creators">
                <i class="fas fa-crown"></i> Top Criadores
            </button>
            <button class="ap-subtab" data-subtab="videos">
                <i class="fas fa-fire"></i> Top Vídeos
            </button>
        </div>

        <!-- Top Criadores -->
        <div class="ap-subtab-panel active" id="subtab-creators">
            <?php if (empty($top_creators)): ?>
                <div class="ap-empty-state"><i class="fas fa-users"></i><h3>Sem dados de criadores</h3></div>
            <?php else: ?>
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Criador</th>
                                <th>Pontos</th>
                                <th>Vídeos</th>
                                <th>Seguidores</th>
                                <th>Views totais</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_creators as $i => $creator): ?>
                                <tr class="<?php echo $i < 3 ? 'ap-tr-top' : ''; ?>">
                                    <td class="ap-td-rank">
                                        <?php if ($i === 0): ?>
                                            <i class="fas fa-crown" style="color:#facc15"></i>
                                        <?php elseif ($i === 1): ?>
                                            <i class="fas fa-crown" style="color:#94a3b8"></i>
                                        <?php elseif ($i === 2): ?>
                                            <i class="fas fa-crown" style="color:#cd7c2f"></i>
                                        <?php else: ?>
                                            <span><?php echo $i + 1; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="ap-td-user">
                                            <img src="<?php echo htmlspecialchars(avatar_url($creator['profile_picture'] ?? null)); ?>"
                                                 alt="" class="ap-td-avatar" loading="lazy">
                                            <div>
                                                <span class="ap-td-name">
                                                    <?php echo htmlspecialchars($creator['full_name'] ?: $creator['username']); ?>
                                                    <?php if (!empty($creator['is_verified'])): ?>
                                                        <i class="fas fa-check-circle" style="color:#3b82f6;font-size:.8em"></i>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="ap-td-sub">@<?php echo htmlspecialchars($creator['username']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><strong class="ap-td-pts"><?php echo formatNumberShort($creator['ranking_points']); ?></strong></td>
                                    <td><?php echo (int)$creator['videos_count']; ?></td>
                                    <td><?php echo formatNumberShort($creator['followers_count']); ?></td>
                                    <td><?php echo formatNumberShort($creator['total_views']); ?></td>
                                    <td>
                                        <a href="perfil.php?id=<?php echo (int)$creator['id']; ?>" class="ap-btn ap-btn-ghost ap-btn-xs" target="_blank">
                                            <i class="fas fa-up-right-from-square"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Top Vídeos -->
        <div class="ap-subtab-panel" id="subtab-videos">
            <?php if (empty($top_videos_rank)): ?>
                <div class="ap-empty-state"><i class="fas fa-film"></i><h3>Sem vídeos publicados</h3></div>
            <?php else: ?>
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vídeo</th>
                                <th>Autor</th>
                                <th>Views</th>
                                <th>Likes</th>
                                <th>Comentários</th>
                                <th>Data</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_videos_rank as $i => $tv): ?>
                                <tr class="<?php echo $i < 3 ? 'ap-tr-top' : ''; ?>">
                                    <td class="ap-td-rank"><?php echo $i + 1; ?></td>
                                    <td>
                                        <div class="ap-td-video-title">
                                            <?php if (!empty($tv['thumbnail_path']) && file_exists(__DIR__ . '/uploads/thumbnails/' . $tv['thumbnail_path'])): ?>
                                                <img src="uploads/thumbnails/<?php echo htmlspecialchars($tv['thumbnail_path']); ?>"
                                                     alt="" class="ap-td-thumb" loading="lazy">
                                            <?php else: ?>
                                                <div class="ap-td-thumb ap-td-thumb-empty"><i class="fas fa-video"></i></div>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars(apShortText($tv['title'], 48)); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        @<?php echo htmlspecialchars($tv['username']); ?>
                                        <?php if (!empty($tv['is_verified'])): ?><i class="fas fa-check-circle" style="color:#3b82f6;font-size:.8em"></i><?php endif; ?>
                                    </td>
                                    <td><strong><?php echo formatNumberShort($tv['views_count']); ?></strong></td>
                                    <td><?php echo formatNumberShort($tv['likes_count']); ?></td>
                                    <td><?php echo formatNumberShort($tv['comments_count']); ?></td>
                                    <td class="ap-td-date"><?php echo date('d/m/y', strtotime($tv['created_at'])); ?></td>
                                    <td>
                                        <a href="index.php?video_id=<?php echo (int)$tv['id']; ?>" class="ap-btn ap-btn-ghost ap-btn-xs" target="_blank">
                                            <i class="fas fa-play"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════
         PREMIUM
    ════════════════════════════════════════════════════════════ -->
    <section class="ap-section" id="section-premium">
        <div class="ap-section-header">
            <div>
                <div class="ap-eyebrow"><i class="fas fa-star"></i> Perfis Premium</div>
                <h1 class="ap-section-title">Utilizadores Premium</h1>
                <p class="ap-section-sub">Gerir quais utilizadores têm acesso a funcionalidades premium (ícone de nome, etc.)</p>
            </div>
        </div>

        <!-- Stat card -->
        <div class="ap-stat-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:28px;">
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(245,158,11,.12);color:#f59e0b;">
                    <i class="fas fa-star"></i>
                </div>
                <div class="ap-stat-body">
                    <div class="ap-stat-value" id="premiumCount"><?php echo $premium_count; ?></div>
                    <div class="ap-stat-label">Utilizadores Premium</div>
                </div>
            </div>
        </div>

        <!-- Search & Add -->
        <div class="ap-card" style="margin-bottom:24px;">
            <div class="ap-card-header">
                <h3 class="ap-card-title"><i class="fas fa-user-plus"></i> Adicionar Utilizador Premium</h3>
            </div>
            <div class="ap-card-body">
                <div class="premium-search-wrap">
                    <div class="premium-search-input-row">
                        <i class="fas fa-search premium-search-icon"></i>
                        <input type="text" id="premiumSearchInput"
                               class="premium-search-input"
                               placeholder="Pesquisar por nome ou @username..."
                               autocomplete="off">
                    </div>
                    <div id="premiumSearchResults" class="premium-search-results ap-hidden"></div>
                </div>
            </div>
        </div>

        <!-- Current premium users list -->
        <div class="ap-card ap-card-overflow-hidden">
            <div class="ap-card-header">
                <h3 class="ap-card-title"><i class="fas fa-crown"></i> Lista Premium</h3>
            </div>
            <div class="ap-card-body" style="padding:0;">
                <div id="premiumUsersList">
                    <div class="ap-empty-state" style="padding:40px 20px;">
                        <i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:#475569;"></i>
                        <p>A carregar...</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════
         ANÚNCIOS / CAMPANHAS
    ════════════════════════════════════════════════════════════ -->
    <section class="ap-section" id="section-ads">
        <div class="ap-section-header">
            <div>
                <div class="ap-eyebrow"><i class="fas fa-rectangle-ad"></i> Publicidade</div>
                <h1 class="ap-section-title">Campanhas de Anúncios</h1>
                <p class="ap-section-sub">Gere pedidos de patrocínio submetidos pelos utilizadores.</p>
            </div>
        </div>

        <!-- Mini stats -->
        <div class="ap-stats-grid ap-stats-grid--sm" style="margin-bottom:28px;">
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(245,158,11,.15);color:#f59e0b;">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Pendentes</span>
                    <strong class="ap-stat-value" id="adsPendingCount"><?php echo $ads_pending; ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(16,185,129,.15);color:#10b981;">
                    <i class="fas fa-rocket"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Ativas</span>
                    <strong class="ap-stat-value"><?php echo $ads_active; ?></strong>
                </div>
            </div>
            <div class="ap-stat-card">
                <div class="ap-stat-icon" style="background:rgba(139,92,246,.15);color:#8b5cf6;">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="ap-stat-body">
                    <span class="ap-stat-label">Receita (Kz)</span>
                    <strong class="ap-stat-value"><?php echo number_format($ads_revenue); ?></strong>
                </div>
            </div>
        </div>

        <?php if (empty($ads_all)): ?>
            <div class="ap-empty-state">
                <i class="fas fa-rectangle-ad"></i>
                <h3>Nenhuma campanha ainda</h3>
                <p>As campanhas submetidas pelos utilizadores aparecerão aqui.</p>
            </div>
        <?php else: ?>
        <div class="ap-table-wrap">
            <table class="ap-table" id="adsTable">
                <thead>
                    <tr>
                        <th>Vídeo</th>
                        <th>Utilizador</th>
                        <th>Plano</th>
                        <th>Valor</th>
                        <th>Público</th>
                        <th>Data</th>
                        <th>Estado</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ads_all as $ad):
                    $status_badge_map = [
                        'pending'  => 'ap-badge ap-badge-yellow',
                        'active'   => 'ap-badge ap-badge-green',
                        'paused'   => 'ap-badge',
                        'expired'  => 'ap-badge',
                        'rejected' => 'ap-badge ap-badge-red',
                    ];
                    $status_labels_map = [
                        'pending'  => 'Pendente',
                        'active'   => 'Ativa',
                        'paused'   => 'Pausada',
                        'expired'  => 'Expirada',
                        'rejected' => 'Rejeitada',
                    ];
                    $badge_cls = $status_badge_map[$ad['status']] ?? 'ap-badge';
                    $status_lbl = $status_labels_map[$ad['status']] ?? $ad['status'];
                    $audience = $ad['target_gender'] === 'all' ? 'Todos' : ($ad['target_gender'] === 'male' ? 'Masc.' : 'Fem.');
                    if ($ad['target_location']) $audience .= ' · ' . htmlspecialchars($ad['target_location']);
                ?>
                <tr id="adRow<?php echo (int)$ad['id']; ?>">
                    <td>
                        <div class="ap-td-video-title">
                            <?php if (!empty($ad['thumbnail_path']) && file_exists(__DIR__ . '/uploads/thumbnails/' . $ad['thumbnail_path'])): ?>
                                <img src="uploads/thumbnails/<?php echo htmlspecialchars($ad['thumbnail_path']); ?>" alt="" class="ap-td-thumb" loading="lazy">
                            <?php else: ?>
                                <div class="ap-td-thumb ap-td-thumb-empty"><i class="fas fa-video"></i></div>
                            <?php endif; ?>
                            <a href="index.php?video_id=<?php echo (int)$ad['video_id']; ?>" target="_blank" style="color:inherit;text-decoration:none;">
                                <?php echo htmlspecialchars(apShortText($ad['video_title'], 40)); ?>
                            </a>
                        </div>
                    </td>
                    <td>
                        <div class="ap-td-user">
                            <img src="<?php echo htmlspecialchars(avatar_url($ad['profile_picture'] ?? null)); ?>"
                                 alt="" class="ap-td-avatar" loading="lazy">
                            <div>
                                <span class="ap-td-name">
                                    <?php echo htmlspecialchars($ad['full_name'] ?: $ad['username']); ?>
                                    <?php if (!empty($ad['is_verified'])): ?><i class="fas fa-check-circle" style="color:#3b82f6;font-size:.8em"></i><?php endif; ?>
                                </span>
                                <span class="ap-td-sub">@<?php echo htmlspecialchars($ad['username']); ?></span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($ad['plan_name']); ?></strong><br>
                        <small style="color:#64748b"><?php echo (int)$ad['plan_days']; ?> dias</small>
                    </td>
                    <td><strong style="color:#facc15"><?php echo number_format((int)$ad['plan_price_kz']); ?> Kz</strong></td>
                    <td style="font-size:.8rem;color:#94a3b8;"><?php echo $audience; ?></td>
                    <td class="ap-td-date"><?php echo date('d/m/y H:i', strtotime($ad['created_at'])); ?></td>
                    <td><span class="<?php echo $badge_cls; ?>"><?php echo $status_lbl; ?></span></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if ($ad['status'] === 'pending'): ?>
                            <button class="ap-btn ap-btn-approve ap-btn-xs js-ad-approve"
                                    data-id="<?php echo (int)$ad['id']; ?>" title="Aprovar">
                                <i class="fas fa-check"></i> Aprovar
                            </button>
                            <button class="ap-btn ap-btn-reject ap-btn-xs js-ad-reject"
                                    data-id="<?php echo (int)$ad['id']; ?>" title="Rejeitar">
                                <i class="fas fa-xmark"></i> Rejeitar
                            </button>
                        <?php elseif ($ad['status'] === 'active'): ?>
                            <button class="ap-btn ap-btn-secondary ap-btn-xs js-ad-pause"
                                    data-id="<?php echo (int)$ad['id']; ?>" title="Pausar">
                                <i class="fas fa-pause"></i> Pausar
                            </button>
                            <button class="ap-btn ap-btn-warn ap-btn-xs js-ad-expire"
                                    data-id="<?php echo (int)$ad['id']; ?>" title="Terminar">
                                <i class="fas fa-stop"></i> Terminar
                            </button>
                        <?php elseif ($ad['status'] === 'paused'): ?>
                            <button class="ap-btn ap-btn-approve ap-btn-xs js-ad-activate"
                                    data-id="<?php echo (int)$ad['id']; ?>" title="Reativar">
                                <i class="fas fa-play"></i> Reativar
                            </button>
                        <?php endif; ?>
                            <button class="ap-btn ap-btn-ghost ap-btn-xs js-ad-delete"
                                    data-id="<?php echo (int)$ad['id']; ?>" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- ═══════════════════════════════════════════════════════════
         SECÇÃO: UTILIZADORES
    ────────────────────────────────────────────────────────────── -->
    <section class="ap-section" id="section-users">
        <div class="ap-section-header">
            <div>
                <p class="ap-eyebrow">Painel Admin</p>
                <h1>Utilizadores</h1>
            </div>
            <div class="ap-header-actions">
                <button class="ap-btn ap-btn-secondary" id="usrRefreshBtn" style="gap:6px">
                    <i class="fas fa-arrows-rotate"></i> Atualizar
                </button>
            </div>
        </div>

        <!-- Stats cards -->
        <div class="usr-stats-grid">
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(16,185,129,.15);color:#10b981">
                    <i class="fas fa-users"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Total</span>
                    <strong class="usr-stat-value" id="usrStatTotal">—</strong>
                    <span class="usr-stat-sub">utilizadores</span>
                </div>
            </div>
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(16,185,129,.2);color:#34d399">
                    <i class="fas fa-circle"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Online agora</span>
                    <strong class="usr-stat-value" id="usrStatOnline">—</strong>
                    <span class="usr-stat-sub">há &lt;2 min</span>
                </div>
            </div>
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(139,92,246,.15);color:#a78bfa">
                    <i class="fas fa-video"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Com vídeos</span>
                    <strong class="usr-stat-value" id="usrStatVideos">—</strong>
                    <span class="usr-stat-sub">criadores</span>
                </div>
            </div>
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(59,130,246,.15);color:#60a5fa">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Novos hoje</span>
                    <strong class="usr-stat-value" id="usrStatToday">—</strong>
                    <span class="usr-stat-sub">registos</span>
                </div>
            </div>
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(59,130,246,.1);color:#3b82f6">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Esta semana</span>
                    <strong class="usr-stat-value" id="usrStatWeek">—</strong>
                    <span class="usr-stat-sub">novos</span>
                </div>
            </div>
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(245,158,11,.15);color:#f59e0b">
                    <i class="fas fa-repeat"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Taxa de Retenção</span>
                    <strong class="usr-stat-value" id="usrStatRetention">—</strong>
                    <span class="usr-stat-sub">voltaram</span>
                </div>
            </div>
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(16,185,129,.12);color:#10b981">
                    <i class="fas fa-rotate-right"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Retidos</span>
                    <strong class="usr-stat-value" id="usrStatRetained">—</strong>
                    <span class="usr-stat-sub">utilizadores</span>
                </div>
            </div>
            <div class="usr-stat-card">
                <div class="usr-stat-icon" style="background:rgba(244,63,94,.15);color:#f43f5e">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="usr-stat-body">
                    <span class="usr-stat-label">Tempo p/ 2.º login</span>
                    <strong class="usr-stat-value" id="usrStatAvgReturn">—</strong>
                    <span class="usr-stat-sub">média</span>
                </div>
            </div>
        </div>

        <!-- Sub-tabs: Lista | Retenção -->
        <div class="usr-subtabs">
            <button class="usr-subtab active" data-subtab="list">
                <i class="fas fa-list"></i> Lista de Utilizadores
            </button>
            <button class="usr-subtab" data-subtab="retention">
                <i class="fas fa-chart-line"></i> Análise de Retenção
            </button>
        </div>

        <!-- ── PANE: Lista ─────────────────────────────────────────── -->
        <div id="usrListPane">
            <!-- Toolbar -->
            <div class="usr-toolbar">
                <div class="usr-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="usrSearch" class="usr-search"
                           placeholder="Pesquisar por nome, username ou email…"
                           autocomplete="off">
                </div>
                <div class="usr-filters">
                    <select id="usrFilterStatus" class="usr-filter-select">
                        <option value="all">📡 Todos</option>
                        <option value="online">🟢 Online</option>
                        <option value="offline">⚫ Offline</option>
                    </select>
                    <select id="usrFilterRole" class="usr-filter-select">
                        <option value="all">👤 Roles</option>
                        <option value="user">User</option>
                        <option value="vip">VIP</option>
                        <option value="moderator">Moderador</option>
                    </select>
                    <select id="usrFilterVideos" class="usr-filter-select">
                        <option value="all">🎬 Vídeos</option>
                        <option value="yes">Com vídeos</option>
                        <option value="no">Sem vídeos</option>
                    </select>
                    <select id="usrFilterVerified" class="usr-filter-select">
                        <option value="all">✅ Verificação</option>
                        <option value="yes">Verificados</option>
                        <option value="no">Não verif.</option>
                    </select>
                    <select id="usrFilterSort" class="usr-filter-select">
                        <option value="newest">↓ Mais recentes</option>
                        <option value="oldest">↑ Mais antigos</option>
                        <option value="most_videos">↓ Mais vídeos</option>
                        <option value="most_followers">↓ Mais seguidores</option>
                        <option value="last_seen">↓ Último visto</option>
                    </select>
                </div>
                <span class="usr-results-count" id="usrCount"></span>
            </div>

            <!-- Table -->
            <div class="usr-table-wrap">
                <table class="usr-table">
                    <thead>
                        <tr>
                            <th>Utilizador</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th><i class="fas fa-video"></i> Vídeos</th>
                            <th><i class="fas fa-users"></i> Seguidores</th>
                            <th>Último Login</th>
                            <th>Membro desde</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="usrTbody">
                        <tr><td colspan="9">
                            <div class="usr-loading">
                                <i class="fas fa-spinner"></i>
                                A carregar utilizadores…
                            </div>
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="usr-pagination" id="usrPagination"></div>
        </div>

        <!-- ── PANE: Retenção ──────────────────────────────────────── -->
        <div id="usrRetentionPane" style="display:none">
            <div id="usrRetentionContent">
                <div class="usr-loading">
                    <i class="fas fa-spinner"></i>
                    Clica na sub-aba para carregar métricas de retenção.
                </div>
            </div>
        </div>
    </section>

    <!-- ──────────────────────────────────────────────────────
         SECÇÃO: DENÚNCIAS (REPORTS)
    ────────────────────────────────────────────────────────── -->
    <section class="ap-section" id="section-reports">
        <div class="ap-section-header">
            <div>
                <p class="ap-eyebrow">Moderação</p>
                <h1>Vídeos Denunciados</h1>
            </div>
        </div>

        <div class="ap-card">
            <?php if (empty($reported_videos)): ?>
                <div style="padding:40px; text-align:center; color:var(--text-dim)">
                    <i class="fas fa-check-circle" style="font-size:32px; color:var(--primary); margin-bottom:12px; display:block"></i>
                    Não há vídeos com denúncias no momento.
                </div>
            <?php else: ?>
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <thead>
                            <tr>
                                <th>Vídeo</th>
                                <th>Criador</th>
                                <th>Denúncias</th>
                                <th>Estado</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reported_videos as $vid): ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:12px">
                                        <div style="width:60px; height:80px; border-radius:8px; overflow:hidden; background:#2a2a35; flex-shrink:0">
                                            <img src="<?php echo htmlspecialchars(asset($vid['thumbnail_path'] ?? '')); ?>" style="width:100%; height:100%; object-fit:cover" alt="Thumb">
                                        </div>
                                        <div style="max-width:200px">
                                            <div style="font-weight:600; font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
                                                <?php echo htmlspecialchars(apShortText($vid['title'] ?: 'Sem título', 40)); ?>
                                            </div>
                                            <div style="font-size:0.75rem; color:var(--text-dim); margin-top:4px">
                                                <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($vid['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>@<?php echo htmlspecialchars($vid['username']); ?></td>
                                <td>
                                    <span class="ap-badge <?php echo $vid['reports_count'] >= 3 ? 'ap-badge-orange' : 'ap-badge-green'; ?>">
                                        <?php echo $vid['reports_count']; ?> denúncia(s)
                                    </span>
                                </td>
                                <td>
                                    <?php if ($vid['is_hidden']): ?>
                                        <span class="ap-badge" style="background:rgba(239,68,68,0.15); color:#ef4444">Ocultado</span>
                                    <?php else: ?>
                                        <span class="ap-badge ap-badge-green">Visível</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="index.php?v=<?php echo $vid['id']; ?>" target="_blank" class="ap-btn ap-btn-outline" style="padding:4px 10px; font-size:0.8rem">
                                        <i class="fas fa-external-link-alt"></i> Ver
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Drawer overlay -->
    <div class="usr-drawer-overlay" id="usrDrawerOverlay"></div>

    <!-- Drawer -->
    <aside class="usr-drawer" id="usrDrawer" role="dialog" aria-modal="true" aria-label="Detalhes do utilizador">
        <div class="usr-drawer-header" id="usrDrawerHeader">
            <div class="usr-drawer-loading">
                <i class="fas fa-spinner"></i>
            </div>
        </div>
        <div class="usr-drawer-body" id="usrDrawerBody"></div>
    </aside>

</div><!-- /.ap-main -->

<!-- Toast container -->
<div id="apToastContainer" class="ap-toast-container"></div>

<script src="<?php echo asset('assets/js/boosted-videos.js'); ?>"></script>
<script>
// ── Ad campaign management (admin) ────────────────────────────────
const CSRF_AD = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function adAction(action, id, extra = {}) {
    const body = new URLSearchParams({ csrf_token: CSRF_AD, action, campaign_id: id, ...extra });
    try {
        const res = await fetch('api/admin_ad_campaigns.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_AD },
            body
        });
        return res.json();
    } catch(e) { return { success: false, error: 'Erro de rede' }; }
}

function adToast(msg, ok = true) {
    const t = document.createElement('div');
    t.className = 'ap-toast ' + (ok ? 'ap-toast--ok' : 'ap-toast--err');
    t.textContent = msg;
    document.getElementById('apToastContainer').appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

document.getElementById('adsTable')?.addEventListener('click', async function(e) {
    const btn = e.target.closest('button[data-id]');
    if (!btn) return;
    const id = btn.dataset.id;
    btn.disabled = true;

    if (btn.classList.contains('js-ad-approve')) {
        const data = await adAction('approve', id);
        if (data.success) {
            adToast('✅ Campanha aprovada! Ativa até ' + (data.ends_at ? data.ends_at.substring(0,10) : '—'));
            document.getElementById('adRow' + id)?.querySelectorAll('.ap-badge')[0]?.replaceWith((() => { const s = document.createElement('span'); s.className = 'ap-badge ap-badge-green'; s.textContent = 'Ativa'; return s; })());
            btn.closest('td').innerHTML = '<span style="color:#34d399;font-size:.8rem"><i class="fas fa-check-circle"></i> Aprovado</span>';
        } else { adToast('❌ ' + (data.error || 'Erro'), false); btn.disabled = false; }
    }
    else if (btn.classList.contains('js-ad-reject')) {
        const reason = prompt('Motivo da rejeição (opcional):') || '';
        const data = await adAction('reject', id, { reason });
        if (data.success) {
            adToast('Campanha rejeitada.');
            location.reload();
        } else { adToast('❌ ' + (data.error || 'Erro'), false); btn.disabled = false; }
    }
    else if (btn.classList.contains('js-ad-pause')) {
        const data = await adAction('pause', id);
        if (data.success) { adToast('Campanha pausada.'); location.reload(); }
        else { adToast('❌ ' + (data.error || 'Erro'), false); btn.disabled = false; }
    }
    else if (btn.classList.contains('js-ad-activate')) {
        const data = await adAction('activate', id);
        if (data.success) { adToast('✅ Campanha reativada!'); location.reload(); }
        else { adToast('❌ ' + (data.error || 'Erro'), false); btn.disabled = false; }
    }
    else if (btn.classList.contains('js-ad-expire')) {
        if (!confirm('Tens a certeza que queres terminar esta campanha?')) { btn.disabled = false; return; }
        const data = await adAction('expire', id);
        if (data.success) { adToast('Campanha terminada.'); location.reload(); }
        else { adToast('❌ ' + (data.error || 'Erro'), false); btn.disabled = false; }
    }
    else if (btn.classList.contains('js-ad-delete')) {
        if (!confirm('Eliminar esta campanha definitivamente?')) { btn.disabled = false; return; }
        const data = await adAction('delete', id);
        if (data.success) {
            document.getElementById('adRow' + id)?.remove();
            adToast('Campanha eliminada.');
        } else { adToast('❌ ' + (data.error || 'Erro'), false); btn.disabled = false; }
    }
});
</script>
</body>
</html>