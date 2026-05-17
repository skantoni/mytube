<?php
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';
require_once 'includes/hashtag_helper.php';

ensureUserData();

$raw_tag = trim((string)($_GET['tag'] ?? ''));
$lookup_tag = trim((string)ltrim($raw_tag, '#'));
$normalized_tag = $lookup_tag !== '' ? hashtag_build_slug($lookup_tag) : '';

$hashtag = null;
$recent_videos = [];
$popular_videos = [];
$trending_hashtags = [];
$error = '';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

try {
    if (!hashtag_tables_available($pdo)) {
        $error = 'Sistema de hashtags ainda não foi instalado.';
    } else {
        $trending_stmt = $pdo->query("\n            SELECT name, slug, posts_count\n            FROM hashtags\n            ORDER BY posts_count DESC, last_used_at DESC, name ASC\n            LIMIT 30\n        ");
        $trending_hashtags = $trending_stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($normalized_tag !== '') {
            $hashtag_stmt = $pdo->prepare("\n                SELECT id, name, slug, posts_count\n                FROM hashtags\n                WHERE slug = ? OR name = ?\n                LIMIT 1\n            ");
            $hashtag_stmt->execute([$normalized_tag, mb_strtolower($lookup_tag, 'UTF-8')]);
            $hashtag = $hashtag_stmt->fetch(PDO::FETCH_ASSOC);

            if ($hashtag) {
                $base_select = "\n                    SELECT\n                        v.id, v.title, v.description, v.video_path, v.thumbnail_path,\n                        v.views_count, v.likes_count, v.comments_count, v.created_at,\n                        u.id AS user_id, u.username, u.profile_picture, u.is_verified\n                    FROM video_hashtags vh\n                    INNER JOIN videos v ON v.id = vh.video_id\n                    INNER JOIN users u ON u.id = v.user_id\n                    WHERE vh.hashtag_id = ? AND v.is_public = 1\n                ";

                $recent_stmt = $pdo->prepare($base_select . " AND v.created_at >= DATE_SUB(NOW(), INTERVAL 5 DAY) ORDER BY v.created_at DESC LIMIT 24");
                $recent_stmt->execute([(int)$hashtag['id']]);
                $recent_videos = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

                $popular_stmt = $pdo->prepare($base_select . " ORDER BY v.likes_count DESC, v.views_count DESC, v.comments_count DESC, v.created_at DESC LIMIT 24");
                $popular_stmt->execute([(int)$hashtag['id']]);
                $popular_videos = $popular_stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $hashtag = [
                    'id' => 0,
                    'name' => mb_strtolower($lookup_tag, 'UTF-8'),
                    'slug' => $normalized_tag,
                    'posts_count' => 0,
                ];
            }
        }
    }
} catch (Throwable $e) {
    $error = 'Erro ao carregar hashtags.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hashtags - MyTube</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/hashtags.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body class="hashtags-page">
    <header class="hashtags-header">
        <button class="back-btn" onclick="history.back()" title="Voltar">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="hashtags-header-title">
            <?php if ($hashtag): ?>
                #<?php echo h($hashtag['name']); ?>
            <?php else: ?>
                Hashtags
            <?php endif; ?>
        </div>
        <a class="home-btn" href="index.php" title="Feed">
            <i class="fas fa-house"></i>
        </a>
    </header>

    <main class="hashtags-main">
        <?php if ($error): ?>
            <div class="hashtags-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo h($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($hashtag): ?>
            <section class="hashtag-summary">
                <div class="hashtag-pill">#<?php echo h($hashtag['name']); ?></div>
                <div class="hashtag-count">
                    <strong><?php echo formatNumberShort((int)($hashtag['posts_count'] ?? 0)); ?></strong>
                    <span>posts</span>
                </div>
            </section>

            <section class="hashtag-section">
                <h2>Vídeos recentes</h2>
                <?php if (empty($recent_videos)): ?>
                    <div class="empty-state">Não há vídeos recentes com essa hashtag.</div>
                <?php else: ?>
                    <div class="videos-grid">
                        <?php foreach ($recent_videos as $video): ?>
                            <a class="video-card" href="index.php?video_id=<?php echo (int)$video['id']; ?>&user_id=<?php echo (int)$video['user_id']; ?>">
                                <video class="video-thumb" muted preload="metadata">
                                    <?php $resolved_url = resolve_video_url($video['video_path']); ?>
                                    <source src="<?php echo htmlspecialchars($resolved_url); ?>#t=0.5" type="video/mp4">
                                </video>
                                <div class="video-card-body">
                                    <div class="video-card-title"><?php echo h($video['title'] ?: 'Sem título'); ?></div>
                                    <div class="video-card-meta">
                                        @<?php echo h($video['username']); ?> · <?php echo timeAgo($video['created_at']); ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="hashtag-section">
                <h2>Vídeos mais populares</h2>
                <?php if (empty($popular_videos)): ?>
                    <div class="empty-state">Sem vídeos populares para essa hashtag ainda.</div>
                <?php else: ?>
                    <div class="videos-grid">
                        <?php foreach ($popular_videos as $video): ?>
                            <a class="video-card" href="index.php?video_id=<?php echo (int)$video['id']; ?>&user_id=<?php echo (int)$video['user_id']; ?>">
                                <video class="video-thumb" muted preload="metadata">
                                    <?php $resolved_url = resolve_video_url($video['video_path']); ?>
                                    <source src="<?php echo htmlspecialchars($resolved_url); ?>#t=0.5" type="video/mp4">
                                </video>
                                <div class="video-card-body">
                                    <div class="video-card-title"><?php echo h($video['title'] ?: 'Sem título'); ?></div>
                                    <div class="video-card-stats">
                                        <span><i class="fas fa-eye"></i> <?php echo formatNumberShort((int)$video['views_count']); ?></span>
                                        <span><i class="fas fa-heart"></i> <?php echo formatNumberShort((int)$video['likes_count']); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="hashtag-section">
                <h2>Hashtags em destaque</h2>
                <?php if (empty($trending_hashtags)): ?>
                    <div class="empty-state">Nenhuma hashtag cadastrada ainda.</div>
                <?php else: ?>
                    <div class="hashtag-cloud">
                        <?php foreach ($trending_hashtags as $tag): ?>
                            <a class="cloud-tag" href="hashtags.php?tag=<?php echo urlencode($tag['slug']); ?>">
                                #<?php echo h($tag['name']); ?>
                                <small><?php echo formatNumberShort((int)$tag['posts_count']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
