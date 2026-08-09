<?php
/**
 * live.php — Página principal do sistema de Livestream
 * Lista todas as streams ativas + botão para ir ao vivo
 */
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';

ensureUserData();

$page_title = 'Ao Vivo — MyTube';
$current_user_id = isLoggedIn() ? (int)$_SESSION['user_id'] : null;
$is_admin = isLoggedIn() && isAdminUser();

// Buscar streams ativas
$live_streams = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            ls.id, ls.title, ls.description, ls.category,
            ls.thumbnail_path, ls.viewers_count, ls.peak_viewers, ls.started_at,
            u.id AS user_id, u.username, u.full_name, u.profile_picture, u.is_verified
        FROM livestreams ls
        JOIN users u ON u.id = ls.user_id
        WHERE ls.status = 'live'
        ORDER BY ls.viewers_count DESC, ls.started_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $live_streams = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('❌ live.php: ' . $e->getMessage());
}

function streamDuration($started_at) {
    if (!$started_at) return '—';
    $diff = time() - strtotime($started_at);
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    if ($h > 0) return "{$h}h {$m}min";
    return "{$m}min";
}

$live_server_url = env('LIVE_SERVER_URL', 'http://localhost:3003');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="Assiste a lives em tempo real e vai ao vivo na MyTube.">
    <?php echo csrf_meta(); ?>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --live-red: #3b82f6;
            --live-glow: rgba(59,130,246,0.35);
            --bg: #000;
            --surface: rgba(255,255,255,0.05);
            --border: rgba(255,255,255,0.1);
            --text: #fff;
            --text-muted: rgba(255,255,255,0.55);
            --header-h: 60px;
        }

        html, body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', -apple-system, sans-serif;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ── HEADER ── */
        .live-header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: var(--header-h);
            background: rgba(0,0,0,0.85);
            -webkit-backdrop-filter: blur(16px);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 12px;
            z-index: 100;
        }

        .live-header-back {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--surface);
            border: none;
            color: var(--text);
            font-size: 16px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .live-header-back:hover { background: rgba(255,255,255,0.12); }

        .live-header-title {
            display: flex; align-items: center; gap: 10px;
            flex: 1;
        }
        .live-header-title h1 {
            font-size: 18px;
            font-weight: 700;
        }
        .live-count-pill {
            background: var(--live-red);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            display: flex; align-items: center; gap: 5px;
        }
        .live-dot-pulse {
            width: 6px; height: 6px;
            background: #fff;
            border-radius: 50%;
            animation: dot-pulse 1.4s ease-in-out infinite;
        }
        @keyframes dot-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.7); }
        }

        .btn-go-live-header {
            background: var(--live-red);
            color: #fff;
            border: none;
            border-radius: 100px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; gap: 7px;
            transition: all 0.2s;
            white-space: nowrap;
            text-decoration: none;
            box-shadow: 0 4px 16px var(--live-glow);
        }
        .btn-go-live-header:hover {
            background: #ff1a40;
            transform: translateY(-1px);
            box-shadow: 0 6px 24px var(--live-glow);
        }

        /* ── PAGE BODY ── */
        .live-page {
            padding: 32px 16px 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ── HERO ── */
        .live-hero {
            background: linear-gradient(90deg, rgba(10,10,26,0.95) 0%, rgba(10,10,26,0.7) 50%, rgba(10,10,26,0.4) 100%), url('assets/images/live_hero.png') center/cover no-repeat;
            border-bottom: 1px solid rgba(59, 130, 246, 0.3);
            padding: calc(var(--header-h) + 40px) 16px 40px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 40px rgba(0,0,0,0.5);
        }
        .live-hero-inner {
            width: 100%;
            max-width: 1400px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }
        .live-hero::before {
            content: '';
            position: absolute;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(255,45,85,0.25) 0%, transparent 70%);
            top: -80px; right: -60px;
            border-radius: 50%;
            pointer-events: none;
        }
        .live-hero-text { position: relative; z-index: 1; }
        .live-hero-text h2 {
            font-size: clamp(24px, 5vw, 38px);
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 12px;
        }
        .live-hero-text h2 span { color: var(--live-red); }
        .live-hero-text p {
            color: var(--text-muted);
            font-size: 15px;
            line-height: 1.6;
            max-width: 440px;
            margin-bottom: 24px;
        }
        .live-hero-icon {
            font-size: clamp(48px, 8vw, 80px);
            line-height: 1;
            flex-shrink: 0;
        }
        .btn-go-live-big {
            background: var(--live-red);
            color: #fff;
            border: none;
            border-radius: 100px;
            padding: 14px 28px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
            box-shadow: 0 6px 24px var(--live-glow);
        }
        .btn-go-live-big:hover {
            background: #ff1a40;
            transform: translateY(-2px);
            box-shadow: 0 10px 32px var(--live-glow);
        }

        /* ── SECTION HEADER ── */
        .section-header {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 20px;
        }
        .section-header h3 {
            font-size: 18px;
            font-weight: 700;
        }
        .section-count {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* ── STREAMS GRID ── */
        .live-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        .stream-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.25s;
            text-decoration: none;
            display: block;
        }
        .stream-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255,45,85,0.4);
            box-shadow: 0 12px 40px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,45,85,0.2);
        }

        .stream-thumbnail {
            position: relative;
            width: 100%;
            aspect-ratio: 16/9;
            background: #111;
            overflow: hidden;
        }
        .stream-thumbnail img {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .stream-thumbnail-placeholder {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #0d0d1a 0%, #1a0a1e 100%);
        }
        .stream-thumbnail-placeholder i {
            font-size: 36px;
            color: rgba(255,45,85,0.5);
        }
        .stream-thumbnail-placeholder span {
            font-size: 12px;
            color: var(--text-muted);
        }
        .live-badge {
            position: absolute;
            top: 10px; left: 10px;
            background: var(--live-red);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: 0.8px;
            display: flex; align-items: center; gap: 4px;
        }
        .viewers-badge {
            position: absolute;
            top: 10px; right: 10px;
            background: rgba(0,0,0,0.7);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 20px;
            display: flex; align-items: center; gap: 4px;
        }
        .duration-badge {
            position: absolute;
            bottom: 8px; right: 8px;
            background: rgba(0,0,0,0.65);
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
        }

        .stream-info {
            padding: 14px;
        }
        .stream-title {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
            margin-bottom: 10px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            color: var(--text);
        }
        .stream-streamer {
            display: flex; align-items: center; gap: 9px;
        }
        .stream-streamer img {
            width: 28px; height: 28px;
            border-radius: 50%;
            object-fit: cover;
            border: 1.5px solid var(--border);
        }
        .stream-streamer-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            display: flex; align-items: center; gap: 4px;
        }
        .stream-streamer-name .fa-check-circle { color: #3b82f6; font-size: 11px; }
        .stream-category {
            margin-left: auto;
            font-size: 11px;
            color: var(--text-muted);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 8px;
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            grid-column: 1/-1;
            text-align: center;
            padding: 80px 20px;
        }
        .empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.4;
        }
        .empty-state h3 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .empty-state p {
            color: var(--text-muted);
            font-size: 15px;
            margin-bottom: 28px;
        }

        @media (max-width: 480px) {
            .live-hero { padding: calc(var(--header-h) + 28px) 20px 28px; }
            .live-hero-inner { flex-direction: column; align-items: flex-start; }
            .live-hero-icon { display: none; }
        }
    </style>
</head>
<body>

    <!-- Header imersivo -->
    <header class="live-header">
        <button class="live-header-back" onclick="window.location.href='index.php' " title="Voltar">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="live-header-title">
            <h1>Ao Vivo</h1>
            <?php if (count($live_streams) > 0): ?>
            <div class="live-count-pill">
                <div class="live-dot-pulse"></div>
                <?php echo count($live_streams); ?> ao vivo
            </div>
            <?php endif; ?>
        </div>
        <?php if (isLoggedIn()): ?>
        <a href="live-stream.php" class="btn-go-live-header">
            <i class="fas fa-broadcast-tower"></i>
            Ir ao Vivo
        </a>
        <?php endif; ?>
    </header>

    <!-- Hero -->
    <div class="live-hero">
        <div class="live-hero-inner">
            <div class="live-hero-text">
                <h2>Streams<br><span>Ao Vivo 🔴</span></h2>
                <p>Vê os teus criadores favoritos em tempo real. Interage no chat e nunca percas um momento.</p>
                <?php if (isLoggedIn()): ?>
                    <a href="live-stream.php" class="btn-go-live-big">
                        <i class="fas fa-broadcast-tower"></i>
                        Ir ao Vivo agora
                    </a>
                <?php else: ?>
                    <a href="login.php?next=live-stream.php" class="btn-go-live-big">
                        <i class="fas fa-sign-in-alt"></i>
                        Entrar para ir ao vivo
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <main class="live-page">

        <!-- Streams Grid -->
        <div class="section-header">
            <h3>Streams Ativas</h3>
            <span class="section-count" id="activeStreamsCount"><?php echo count($live_streams); ?></span>
        </div>

        <div class="live-grid" id="liveGrid">
            <?php if (empty($live_streams)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📡</div>
                    <h3>Nenhuma stream ao vivo</h3>
                    <p>Sê o primeiro a transmitir hoje e conquista a tua audiência!</p>
                    <?php if (isLoggedIn()): ?>
                        <a href="live-stream.php" class="btn-go-live-big">
                            <i class="fas fa-broadcast-tower"></i>
                            Começar stream
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($live_streams as $s): ?>
                <a href="live-watch.php?id=<?php echo $s['id']; ?>" class="stream-card" id="stream-card-<?php echo $s['id']; ?>">
                    <div class="stream-thumbnail">
                        <?php if ($s['thumbnail_path']): ?>
                            <img src="<?php echo htmlspecialchars($s['thumbnail_path']); ?>" alt="<?php echo htmlspecialchars($s['title']); ?>">
                        <?php else: ?>
                            <div class="stream-thumbnail-placeholder">
                                <i class="fas fa-broadcast-tower"></i>
                                <span>Ao Vivo</span>
                            </div>
                        <?php endif; ?>
                        <div class="live-badge">
                            <div class="live-dot-pulse"></div>
                            LIVE
                        </div>
                        <div class="viewers-badge">
                            <i class="fas fa-eye" style="color:#3b82f6;font-size:10px;"></i>
                            <?php echo number_format($s['viewers_count']); ?>
                        </div>
                        <div class="duration-badge" data-started-at="<?php echo strtotime($s['started_at']) * 1000; ?>">
                            <?php echo streamDuration($s['started_at']); ?>
                        </div>
                    </div>
                    <div class="stream-info">
                        <div class="stream-title"><?php echo htmlspecialchars($s['title']); ?></div>
                        <div class="stream-streamer">
                            <img src="<?php echo htmlspecialchars(avatar_url($s['profile_picture'])); ?>" alt="<?php echo htmlspecialchars($s['username']); ?>">
                            <span class="stream-streamer-name">
                                <?php echo htmlspecialchars($s['username']); ?>
                                <?php if ($s['is_verified']): ?><i class="fas fa-check-circle"></i><?php endif; ?>
                            </span>
                            <?php if ($s['category']): ?>
                                <span class="stream-category"><?php echo htmlspecialchars($s['category']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Botão Carregar Mais -->
        <div id="loadMoreContainer" style="text-align:center; margin-top: 32px; display: none;">
            <button class="btn-go-live-big" onclick="loadMoreStreams()" style="background: var(--surface); color: var(--text); box-shadow: none; border: 1px solid var(--border);">
                Carregar mais
            </button>
        </div>

    </main>

    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    <script>
    const LIVE_SERVER_URL = '<?php echo $live_server_url; ?>';
    let socket = null;
    const MAX_VISIBLE_STREAMS = 20;

    function initLiveFeed() {
        socket = io(LIVE_SERVER_URL, {
            path: '/live-socket/',
            transports: ['websocket'],
            auth: { token: null } // Visitante
        });
        socket.on('connect', () => {
            socket.emit('join_live_feed');
        });

        socket.on('feed_stream_started', (stream) => {
            removeEmptyState();
            
            const card = document.createElement('a');
            card.href = `live-watch.php?id=${stream.id}`;
            card.className = 'stream-card';
            card.id = `stream-card-${stream.id}`;
            
            let thumbHtml = stream.thumbnail_path 
                ? `<img src="${escapeHtml(stream.thumbnail_path)}" alt="${escapeHtml(stream.title)}">`
                : `<div class="stream-thumbnail-placeholder"><i class="fas fa-broadcast-tower"></i><span>Ao Vivo</span></div>`;

            let categoryHtml = stream.category
                ? `<span class="stream-category">${escapeHtml(stream.category)}</span>`
                : '';
                
            let verifiedHtml = stream.streamer.is_verified
                ? `<i class="fas fa-check-circle"></i>`
                : '';

            card.innerHTML = `
                <div class="stream-thumbnail">
                    ${thumbHtml}
                    <div class="live-badge">
                        <div class="live-dot-pulse"></div>LIVE
                    </div>
                    <div class="viewers-badge">
                        <i class="fas fa-eye" style="color:#3b82f6;font-size:10px;"></i>
                        <span id="vc-${stream.id}">0</span>
                    </div>
                    <div class="duration-badge" data-started-at="${Date.now()}">agora</div>
                </div>
                <div class="stream-info">
                    <div class="stream-title">${escapeHtml(stream.title)}</div>
                    <div class="stream-streamer">
                        <img src="${resolveAvatarUrl(stream.streamer.profile_picture)}" alt="${escapeHtml(stream.streamer.username)}">
                        <span class="stream-streamer-name">${escapeHtml(stream.streamer.username)}${verifiedHtml}</span>
                        ${categoryHtml}
                    </div>
                </div>
            `;
            
            const grid = document.getElementById('liveGrid');
            grid.insertBefore(card, grid.firstChild);
            
            updateCount();
            checkLoadMore();
        });

        socket.on('feed_stream_ended', ({ streamId }) => {
            const card = document.getElementById(`stream-card-${streamId}`);
            if (card) {
                card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    card.remove();
                    updateCount();
                    checkLoadMore();
                    checkEmptyState();
                }, 400);
            }
        });

        socket.on('feed_viewers_update', ({ streamId, count }) => {
            const span = document.getElementById(`vc-${streamId}`);
            if (span) span.textContent = formatNumber(count);
        });
    }

    function removeEmptyState() {
        const empty = document.querySelector('.empty-state');
        if (empty) empty.remove();
    }

    function checkEmptyState() {
        const grid = document.getElementById('liveGrid');
        if (grid.querySelectorAll('.stream-card').length === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">📡</div>
                    <h3>Nenhuma stream ao vivo</h3>
                    <p>Sê o primeiro a transmitir hoje e conquista a tua audiência!</p>
                    <a href="live-stream.php" class="btn-go-live-big">
                        <i class="fas fa-broadcast-tower"></i> Começar stream
                    </a>
                </div>
            `;
        }
    }

    function updateCount() {
        const count = document.querySelectorAll('.stream-card').length;
        const el1 = document.getElementById('activeStreamsCount');
        if (el1) el1.textContent = count;
        
        // Header pill
        const headerTitle = document.querySelector('.live-header-title');
        let pill = headerTitle.querySelector('.live-count-pill');
        if (count > 0) {
            if (!pill) {
                pill = document.createElement('div');
                pill.className = 'live-count-pill';
                pill.innerHTML = '<div class="live-dot-pulse"></div> <span></span>';
                headerTitle.appendChild(pill);
            }
            pill.querySelector('span').textContent = count + ' ao vivo';
        } else if (pill) {
            pill.remove();
            checkEmptyState();
        }
    }

    function checkLoadMore() {
        const cards = document.querySelectorAll('.stream-card');
        const loadMore = document.getElementById('loadMoreContainer');
        let hiddenCount = 0;
        
        cards.forEach((card, index) => {
            if (card.classList.contains('force-show')) {
                card.style.display = '';
            } else if (index >= MAX_VISIBLE_STREAMS) {
                card.style.display = 'none';
                hiddenCount++;
            } else {
                card.style.display = '';
            }
        });

        if (hiddenCount > 0) {
            loadMore.style.display = 'block';
        } else {
            loadMore.style.display = 'none';
        }
    }

    function loadMoreStreams() {
        const cards = document.querySelectorAll('.stream-card');
        cards.forEach(card => card.classList.add('force-show'));
        document.getElementById('loadMoreContainer').style.display = 'none';
    }

    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function resolveAvatarUrl(pic) {
        if (!pic) return 'assets/images/avatars/default.webp';
        // Se já for URL absoluta (http/https/R2), usar diretamente
        if (pic.startsWith('http://') || pic.startsWith('https://')) return escapeHtml(pic);
        // Se vier só o nome do ficheiro (sem barras), replicar lógica do PHP avatar_url()
        if (!pic.includes('/')) return 'assets/images/avatars/' + escapeHtml(pic);
        // Path relativo com prefixo (ex: "uploads/avatars/..." ou "assets/...")
        return escapeHtml(pic);
    }
    
    function formatNumber(num) {
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'k';
        return num.toString();
    }

    function updateDurations() {
        const now = Date.now();
        document.querySelectorAll('.duration-badge[data-started-at]').forEach(badge => {
            const startedAt = parseInt(badge.getAttribute('data-started-at'), 10);
            if (!startedAt) return;
            const diffSecs = Math.floor((now - startedAt) / 1000);
            if (diffSecs < 0) return;
            
            const h = Math.floor(diffSecs / 3600);
            const m = Math.floor((diffSecs % 3600) / 60);
            
            let text = 'agora';
            if (h > 0) {
                text = `${h}h ${m}min`;
            } else if (m > 0) {
                text = `${m}min`;
            }
            
            if (badge.textContent.trim() !== text) {
                badge.textContent = text;
            }
        });
    }

    // Inicializar o JS
    document.addEventListener('DOMContentLoaded', () => {
        // Tag inicial dos ids para updates de view (o id já vem do PHP, mas garantimos o vc-span)
        const cards = document.querySelectorAll('.stream-card');
        cards.forEach(c => {
            const href = c.getAttribute('href');
            const match = href.match(/id=(\d+)/);
            if (match) {
                const id = match[1];
                // Garantir id no card caso não venha do PHP (fallback)
                if (!c.id) c.id = `stream-card-${id}`;
                const viewBadge = c.querySelector('.viewers-badge');
                if (viewBadge && !viewBadge.querySelector('span[id]')) {
                    const txt = viewBadge.textContent.trim();
                    viewBadge.innerHTML = `<i class="fas fa-eye" style="color:#3b82f6;font-size:10px;"></i> <span id="vc-${id}">${txt}</span>`;
                }
            }
        });
        
        checkLoadMore();
        initLiveFeed();
        
        // Atualizar durações automaticamente (a cada 10s para maior precisão na virada do minuto)
        setInterval(updateDurations, 10000);
    });
    </script>
</body>
</html>
