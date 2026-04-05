<?php
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';

ensureUserData();

if (!isLoggedIn()) {
    redirect('login.php');
}

if (!isAdminUser()) {
    redirect('index.php');
}

function boostedShortText($text, $limit = 140) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

$stmt = $pdo->query("\n    SELECT
        v.id,
        v.user_id,
        v.title,
        v.description,
        v.video_path,
        v.thumbnail_path,
        v.views_count,
        v.likes_count,
        v.comments_count,
        v.created_at,
        v.updated_at,
        u.username,
        u.full_name,
        u.profile_picture,
        u.is_verified
    FROM videos v
    INNER JOIN users u ON u.id = v.user_id
    WHERE v.is_public = 1 AND v.is_boosted = 1
    ORDER BY v.updated_at DESC, v.created_at DESC
");
$boosted_videos = $stmt->fetchAll();

$boosted_count = count($boosted_videos);
$boosted_creators = count(array_unique(array_map(static fn($video) => (int) $video['user_id'], $boosted_videos)));
$boosted_views = array_sum(array_map(static fn($video) => (int) $video['views_count'], $boosted_videos));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Boosted - MyTube</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/boosted-videos.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body class="boosted-page">
    <main class="boosted-panel-main">
        <div class="boosted-panel-container">
            <section class="boosted-hero">
                <div class="boosted-title-row">
                    <div>
                        <p class="boosted-eyebrow">Painel Admin</p>
                        <h1>Vídeos Boosted</h1>
                        <p class="boosted-subtitle-text">Veja todos os vídeos em destaque e remova o boost sem entrar no feed.</p>
                    </div>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-play"></i>
                        Abrir feed
                    </a>
                </div>

                <div class="boosted-stats-grid" id="boostedStatsGrid">
                    <div class="card boosted-stat-card">
                        <span class="boosted-stat-label">Boosted ativos</span>
                        <strong class="boosted-stat-value" id="boostedCount"><?php echo $boosted_count; ?></strong>
                    </div>
                    <div class="card boosted-stat-card">
                        <span class="boosted-stat-label">Criadores destacados</span>
                        <strong class="boosted-stat-value" id="boostedCreatorsCount"><?php echo $boosted_creators; ?></strong>
                    </div>
                    <div class="card boosted-stat-card">
                        <span class="boosted-stat-label">Views somadas</span>
                        <strong class="boosted-stat-value" id="boostedViewsCount"><?php echo formatNumberShort($boosted_views); ?></strong>
                    </div>
                    <div class="card boosted-stat-card">
                        <span class="boosted-stat-label">Impressões (30d)</span>
                        <strong class="boosted-stat-value" id="boostedImpressions"><i class="fas fa-spinner fa-spin" style="font-size:1rem;color:#94a3b8"></i></strong>
                    </div>
                    <div class="card boosted-stat-card">
                        <span class="boosted-stat-label">Alcance único (30d)</span>
                        <strong class="boosted-stat-value" id="boostedReach"><i class="fas fa-spinner fa-spin" style="font-size:1rem;color:#94a3b8"></i></strong>
                    </div>
                    <div class="card boosted-stat-card">
                        <span class="boosted-stat-label">CTR médio</span>
                        <strong class="boosted-stat-value" id="boostedAvgCtr"><i class="fas fa-spinner fa-spin" style="font-size:1rem;color:#94a3b8"></i></strong>
                    </div>
                </div>
            </section>

            <section class="boosted-list-section">
                <div class="boosted-list-header">
                    <h2>Todos os vídeos em destaque</h2>
                    <span class="boosted-list-meta" id="boostedListMeta"><?php echo $boosted_count; ?> vídeo<?php echo $boosted_count !== 1 ? 's' : ''; ?> com boost ativo</span>
                </div>

                <div class="card boosted-empty-state<?php echo $boosted_count > 0 ? ' boosted-hidden' : ''; ?>" id="boostedEmptyState">
                    <i class="fas fa-bolt"></i>
                    <h3>Nenhum vídeo boosted</h3>
                    <p>Quando você der boost em um vídeo no feed, ele vai aparecer aqui.</p>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home"></i>
                        Ir para o feed
                    </a>
                </div>

                <div class="boosted-grid<?php echo $boosted_count === 0 ? ' boosted-hidden' : ''; ?>" id="boostedGrid">
                    <?php foreach ($boosted_videos as $video): ?>
                        <article class="card boosted-video-card"
                                 data-video-id="<?php echo (int) $video['id']; ?>"
                                 data-user-id="<?php echo (int) $video['user_id']; ?>"
                                 data-views="<?php echo (int) $video['views_count']; ?>">
                            <div class="boosted-video-media" onclick="window.location.href='index.php?video_id=<?php echo (int) $video['id']; ?>'">
                                <?php if (!empty($video['thumbnail_path']) && file_exists(__DIR__ . '/uploads/thumbnails/' . $video['thumbnail_path'])): ?>
                                    <img src="uploads/thumbnails/<?php echo htmlspecialchars($video['thumbnail_path']); ?>"
                                         alt="<?php echo htmlspecialchars($video['title']); ?>"
                                         loading="lazy">
                                <?php elseif (!empty($video['video_path'])): ?>
                                    <video preload="metadata" muted loop playsinline class="video-preview-player lazy-video">
                                        <?php $resolved_url = resolve_video_url($video['video_path']); ?>
                                        <source src="<?php echo htmlspecialchars($resolved_url); ?>" type="video/mp4">
                                    </video>
                                <?php else: ?>
                                    <div class="boosted-video-fallback">
                                        <i class="fas fa-play"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="boosted-media-overlay">
                                    <span class="boost-chip">
                                        <i class="fas fa-bolt"></i>
                                        Boosted
                                    </span>
                                    <button type="button" class="boosted-open-btn" onclick="event.stopPropagation(); window.location.href='index.php?video_id=<?php echo (int) $video['id']; ?>'">
                                        <i class="fas fa-up-right-from-square"></i>
                                        Abrir no feed
                                    </button>
                                </div>
                            </div>

                            <div class="boosted-video-body">
                                <div class="boosted-author-row">
                                    <a href="perfil.php?id=<?php echo (int) $video['user_id']; ?>" class="boosted-author-link">
                                        <img src="<?php echo htmlspecialchars(avatar_url($video['profile_picture'] ?? null)); ?>"
                                             alt="<?php echo htmlspecialchars($video['username']); ?>"
                                             class="boosted-author-avatar"
                                             loading="lazy">
                                        <div>
                                            <div class="boosted-author-name">
                                                <?php echo htmlspecialchars($video['full_name'] ?: $video['username']); ?>
                                                <?php if (!empty($video['is_verified'])): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="boosted-author-username">@<?php echo htmlspecialchars($video['username']); ?></div>
                                        </div>
                                    </a>
                                </div>

                                <h3 class="boosted-video-title"><?php echo htmlspecialchars($video['title']); ?></h3>

                                <?php $description = boostedShortText($video['description'] ?? ''); ?>
                                <?php if ($description !== ''): ?>
                                    <p class="boosted-video-description"><?php echo htmlspecialchars($description); ?></p>
                                <?php endif; ?>

                                <div class="boosted-video-stats">
                                    <span><i class="fas fa-eye"></i> <?php echo formatNumberShort($video['views_count']); ?></span>
                                    <span><i class="fas fa-heart"></i> <?php echo formatNumberShort($video['likes_count']); ?></span>
                                    <span><i class="fas fa-comment"></i> <?php echo formatNumberShort($video['comments_count']); ?></span>
                                    <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars(timeAgo($video['created_at'])); ?></span>
                                </div>

                                <div class="boosted-ctr-bar" id="ctrBar_<?php echo (int) $video['id']; ?>">
                                    <div class="ctr-bar-header">
                                        <span class="ctr-label">CTR</span>
                                        <span class="ctr-value" id="ctrValue_<?php echo (int) $video['id']; ?>">—</span>
                                    </div>
                                    <div class="ctr-bar-track">
                                        <div class="ctr-bar-fill" id="ctrFill_<?php echo (int) $video['id']; ?>" style="width:0%"></div>
                                    </div>
                                    <div class="ctr-bar-details">
                                        <span id="ctrImpressions_<?php echo (int) $video['id']; ?>">— impressões</span>
                                        <span id="ctrReach_<?php echo (int) $video['id']; ?>">— users</span>
                                    </div>
                                </div>

                                <div class="boosted-video-actions">
                                    <a href="index.php?video_id=<?php echo (int) $video['id']; ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-play"></i>
                                        Ver vídeo
                                    </a>
                                    <button type="button"
                                            class="btn btn-sm btn-boost-remove js-remove-boost"
                                            data-video-id="<?php echo (int) $video['id']; ?>">
                                        <i class="fas fa-bolt"></i>
                                        Remover boost
                                    </button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>

    <script src="<?php echo asset('assets/js/boosted-videos.js'); ?>"></script>
</body>
</html>