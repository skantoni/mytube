<?php
/**
 * live-watch.php — Página do Viewer
 * Assiste a uma livestream em tempo real
 */
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';

$stream_id = (int)($_GET['id'] ?? 0);

if ($stream_id <= 0) {
    header('Location: live.php');
    exit;
}

// Buscar info da stream
$stream = null;
try {
    $stmt = $pdo->prepare("
        SELECT ls.id, ls.user_id, ls.title, ls.description, ls.category,
               ls.thumbnail_path, ls.status, ls.viewers_count,
               ls.peak_viewers, ls.total_views, ls.started_at,
               u.username, u.full_name, u.profile_picture, u.is_verified,
               u.followers_count
        FROM livestreams ls
        JOIN users u ON u.id = ls.user_id
        WHERE ls.id = ? LIMIT 1
    ");
    $stmt->execute([$stream_id]);
    $stream = $stmt->fetch();
} catch (Exception $e) {
    error_log('❌ live-watch.php: ' . $e->getMessage());
}

if (!$stream) {
    header('Location: live.php');
    exit;
}

// Verificar se segue o streamer
$is_following = false;
$current_user_id = null;
if (isLoggedIn()) {
    $current_user_id = (int)$_SESSION['user_id'];
    try {
        $stmt2 = $pdo->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1");
        $stmt2->execute([$current_user_id, $stream['user_id']]);
        $is_following = (bool)$stmt2->fetch();
    } catch (Exception $e) {}
}

// Histórico do chat
$chat_history = [];
try {
    $stmt3 = $pdo->prepare("
        SELECT lm.id, lm.message, lm.type, lm.created_at,
               u.username, u.profile_picture, u.is_verified
        FROM livestream_messages lm
        JOIN users u ON u.id = lm.user_id
        WHERE lm.stream_id = ?
        ORDER BY lm.created_at DESC
        LIMIT 50
    ");
    $stmt3->execute([$stream_id]);
    $chat_history = array_reverse($stmt3->fetchAll());
} catch (Exception $e) {}

$is_live = $stream['status'] === 'live';
$is_my_stream = isLoggedIn() && $current_user_id === (int)$stream['user_id'];
$live_server_url = env('LIVE_SERVER_URL', 'http://localhost:3003');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?php echo htmlspecialchars($stream['title']); ?> — MyTube Live</title>
    <meta name="description" content="<?php echo htmlspecialchars($stream['description'] ?: 'Assiste ao vivo na MyTube: ' . $stream['title']); ?>">
    <?php echo csrf_meta(); ?>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --live-red: #3b82f6;
            --live-glow: rgba(59,130,246,0.3);
            --bg: #000;
            --surface: rgba(255,255,255,0.05);
            --surface2: rgba(255,255,255,0.09);
            --border: rgba(255,255,255,0.1);
            --panel-border: rgba(255,255,255,0.1);
            --text: #fff;
            --text-muted: rgba(255,255,255,0.55);
            --header-h: 60px;
        }
        html, body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        @media (max-width: 900px) {
            html, body {
                height: 100%;
                height: 100dvh;
                overflow: hidden;
            }
        }

        /* ── Header ── */
        .watch-header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: var(--header-h);
            background: rgba(0,0,0,0.88);
            -webkit-backdrop-filter: blur(16px);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 16px;
            gap: 12px;
            z-index: 200;
        }
        .header-back {
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
        .header-back:hover { background: var(--surface2); }
        .header-stream-info { flex: 1; min-width: 0; }
        .header-stream-info h1 {
            font-size: 15px; font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .header-stream-info span { font-size: 12px; color: var(--text-muted); }
        .live-badge-header {
            background: var(--live-red); color: #fff;
            font-size: 11px; font-weight: 800;
            padding: 4px 10px; border-radius: 6px;
            letter-spacing: 0.8px;
            display: flex; align-items: center; gap: 5px;
            flex-shrink: 0;
        }
        .viewers-header {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px; padding: 4px 10px;
            font-size: 12px; font-weight: 600;
            display: flex; align-items: center; gap: 5px;
            flex-shrink: 0;
        }
        .live-dot-sm {
            width: 6px; height: 6px;
            background: #fff; border-radius: 50%;
            animation: pdot 1.2s ease-in-out infinite;
        }
        @keyframes pdot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.25; transform: scale(0.6); }
        }

        /* ── Layout ── */
        .watch-layout {
            padding-top: var(--header-h);
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 360px;
            grid-template-rows: 1fr;
            overflow: hidden;
        }
        @media (max-width: 900px) {
            .watch-layout {
                display: flex;
                flex-direction: column;
                height: 100dvh;
                grid-template-columns: unset;
                overflow: hidden;
            }
        }

        /* ── Video ── */
        .player-section {
            display: flex;
            flex-direction: column;
            background: #000;
            overflow: hidden;
            flex-shrink: 0;
        }
        .video-wrapper {
            position: relative;
            width: 100%;
            aspect-ratio: 16/9;
            background: #000;
            overflow: hidden;
            flex-shrink: 0;
        }
        @media (min-width: 901px) {
            .player-section { flex-shrink: 1; overflow: hidden; }
            .video-wrapper {
                aspect-ratio: unset;
                height: calc(100vh - var(--header-h) - 160px);
            }
        }
        /* Botão maximizar vídeo */
        .btn-expand-video {
            position: absolute;
            bottom: 10px; right: 10px;
            width: 36px; height: 36px;
            border-radius: 8px;
            background: rgba(0,0,0,0.6);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            font-size: 14px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
            z-index: 10;
        }
        .btn-expand-video:hover { background: rgba(0,0,0,0.85); }
        /* Botão coração */
        .btn-heart {
            position: absolute;
            bottom: 10px; right: 54px;
            width: 36px; height: 36px;
            border-radius: 50%;
            background: rgba(0,0,0,0.6);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.15);
            color: #ff4d6d;
            font-size: 16px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: transform 0.15s;
            z-index: 10;
            user-select: none;
        }
        .btn-heart:active { transform: scale(0.85); }
        /* Balões de coração flutuantes */
        .floating-heart {
            position: fixed;
            pointer-events: none;
            font-size: 22px;
            z-index: 9999;
            animation: float-up 1.4s ease-out forwards;
            user-select: none;
        }
        @keyframes float-up {
            0%   { opacity: 1; transform: translateY(0) scale(1) rotate(var(--rot)); }
            60%  { opacity: 1; }
            100% { opacity: 0; transform: translateY(-200px) scale(1.3) rotate(calc(var(--rot) * 2)); }
        }
        /* Modo ecrã inteiro no mobile */
        .video-fullscreen-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: #000;
            z-index: 5000;
            flex-direction: column;
        }
        .video-fullscreen-overlay.active {
            display: flex;
        }
        .video-fullscreen-overlay video {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .btn-close-fullscreen {
            position: absolute;
            top: 16px; right: 16px;
            width: 40px; height: 40px;
            border-radius: 50%;
            background: rgba(0,0,0,0.7);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            font-size: 18px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            z-index: 10;
        }
        .btn-heart-fs {
            position: absolute;
            bottom: 40px; right: 20px;
            width: 52px; height: 52px;
            border-radius: 50%;
            background: rgba(0,0,0,0.55);
            border: 1.5px solid rgba(255,77,109,0.4);
            color: #ff4d6d;
            font-size: 24px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: transform 0.15s;
            z-index: 10;
            user-select: none;
        }
        .btn-heart-fs:active { transform: scale(0.85); }
        #livePlayer {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }
        .player-overlay-top {
            position: absolute;
            top: 12px; left: 12px; right: 12px;
            display: flex; align-items: center; gap: 10px;
            z-index: 5;
        }
        .live-badge-watch {
            background: var(--live-red); color: #fff;
            font-size: 11px; font-weight: 800;
            padding: 4px 10px; border-radius: 6px;
            letter-spacing: 0.8px;
            display: flex; align-items: center; gap: 5px;
        }
        .viewers-live-count {
            background: rgba(0,0,0,0.6);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            color: #fff;
            font-size: 12px; font-weight: 600;
            padding: 4px 10px; border-radius: 20px;
            display: flex; align-items: center; gap: 5px;
        }
        .buffer-spinner {
            position: absolute;
            inset: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.5);
        }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid rgba(255,255,255,0.2);
            border-top-color: var(--live-red);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .offline-screen {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 12px; padding: 40px;
            background: radial-gradient(circle at center, #1a0a1e 0%, #0a0a0f 100%);
        }
        .offline-screen i { font-size: 48px; color: rgba(255,255,255,0.2); }
        .offline-screen h2 { font-size: 22px; font-weight: 700; }
        .offline-screen p { color: var(--text-muted); text-align: center; }

        .camera-off-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.85);
            z-index: 4;
            color: var(--text-muted);
            gap: 12px;
        }
        .camera-off-overlay i {
            font-size: 48px;
            color: rgba(255,255,255,0.3);
        }
        .camera-off-overlay p {
            font-size: 16px;
            font-weight: 500;
        }

        /* Stream info below video */
        .stream-info {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }
        .stream-title-row {
            display: flex; align-items: center;
            gap: 10px; margin-bottom: 14px;
        }
        .mic-muted-indicator {
            flex: 1;
            font-size: 14px; font-weight: 600; color: #ff2d55;
            display: flex; align-items: center; gap: 6px;
        }
        .category-tag {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 3px 10px; font-size: 12px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .streamer-info-row {
            display: flex; align-items: center; gap: 12px;
        }
        .streamer-avatar-lg {
            width: 42px; height: 42px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid var(--border);
            flex-shrink: 0;
        }
        .streamer-details { flex: 1; min-width: 0; }
        .streamer-name-lg {
            font-size: 15px; font-weight: 700;
            display: flex; align-items: center; gap: 5px;
        }
        .verified-check { color: #3b82f6; font-size: 13px; }
        .streamer-followers { font-size: 12px; color: var(--text-muted); }
        .btn-follow {
            background: var(--live-red);
            border: none; border-radius: 100px;
            color: #fff; padding: 8px 16px;
            font-size: 13px; font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
            box-shadow: 0 4px 14px var(--live-glow);
        }
        .btn-follow:hover { background: #ff1a40; }
        .btn-follow.following {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-muted);
            box-shadow: none;
        }
        .btn-share {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 100px;
            color: var(--text);
            padding: 8px 14px;
            font-size: 13px; font-weight: 600;
            cursor: pointer;
            display: flex; align-items: center; gap: 6px;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .btn-share:hover { background: var(--surface2); }

        /* ── Chat Panel ── */
        .chat-panel {
            display: flex;
            flex-direction: column;
            background: #0a0a0f;
            border-left: 1px solid var(--border);
            height: calc(100dvh - var(--header-h));
            overflow: hidden;
            position: sticky;
            top: var(--header-h);
        }
        @media (max-width: 900px) {
            .chat-panel {
                border-left: none;
                border-top: 1px solid var(--border);
                flex: 1;
                min-height: 0;
                position: static;
                overflow: hidden;
            }
        }
        .chat-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 14px; font-weight: 700;
            display: flex; align-items: center; gap: 8px;
            flex-shrink: 0;
        }
        .chat-viewer-count {
            margin-left: auto;
            display: flex; align-items: center; gap: 5px;
            font-size: 12px; font-weight: 600;
            color: var(--text-muted);
        }
        .chat-messages {
            flex: 1; overflow-y: auto;
            padding: 12px;
            display: flex; flex-direction: column; gap: 10px;
        }
        .chat-messages::-webkit-scrollbar { width: 3px; }
        .chat-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
        .chat-msg {
            display: flex; align-items: flex-start; gap: 8px;
        }
        .chat-msg-avatar {
            width: 26px; height: 26px;
            border-radius: 50%; object-fit: cover;
            flex-shrink: 0;
        }
        .chat-msg-body { min-width: 0; }
        .chat-msg-username {
            font-size: 12px; font-weight: 700;
            color: #4facfe; margin-bottom: 3px;
        }
        .chat-msg-username.streamer-tag { color: var(--live-red); }
        .chat-msg-text {
            font-size: 13px; line-height: 1.4;
            color: rgba(255,255,255,0.9);
            word-break: break-word;
            padding: 2px 0;
        }
        
        /* ── As minhas mensagens ── */
        .chat-msg.msg-mine {
            flex-direction: row-reverse;
        }
        .chat-msg.msg-mine .chat-msg-username {
            text-align: right;
        }
        .chat-msg.msg-mine .chat-msg-text {
            text-align: right;
            padding: 2px 0;
        }
        .chat-msg:not(.msg-mine) .chat-msg-text {
            padding: 2px 0;
        }
        
        .system-msg {
            text-align: center; font-size: 11px;
            color: var(--text-muted); padding: 4px 0;
        }
        .chat-input-area {
            padding: 12px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            border-top: 1px solid var(--border);
            flex-shrink: 0;
        }
        .chat-input-row { display: flex; gap: 8px; }
        .chat-input {
            flex: 1;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 100px;
            padding: 10px 16px;
            color: var(--text);
            font-size: 14px; font-family: inherit;
            outline: none;
        }
        .chat-input:focus { border-color: rgba(59,130,246,0.6); }
        .chat-input:disabled { opacity: 0.4; cursor: not-allowed; }
        .chat-send-btn {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--live-red);
            border: none; color: #fff;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .chat-send-btn:hover { background: #2563eb; }
        .chat-send-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .login-prompt {
            text-align: center;
            padding: 14px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .login-prompt a { color: #4facfe; text-decoration: none; }

        /* ── Toast ── */
        .toast-container {
            position: fixed;
            bottom: 24px; right: 16px;
            z-index: 9999;
            display: flex; flex-direction: column;
            gap: 8px; max-width: 300px;
        }
        .toast {
            background: rgba(20,20,35,0.95);
            -webkit-backdrop-filter: blur(12px);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px; color: var(--text);
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            animation: toast-in 0.3s ease;
        }
        .toast.success { border-color: rgba(0,210,100,0.4); }
        .toast.error   { border-color: rgba(255,45,85,0.4); }
        .toast.info    { border-color: rgba(79,172,254,0.4); }
        @keyframes toast-in {
            from { opacity: 0; transform: translateX(20px); }
            to   { opacity: 1; transform: translateX(0); }
        }
    </style>
</head>
<body>

    <!-- Header imersivo -->
    <header class="watch-header">
        <button class="header-back" onclick="history.back()" title="Voltar">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="header-stream-info">
            <h1><?php echo htmlspecialchars($stream['title']); ?></h1>
            <span>@<?php echo htmlspecialchars($stream['username']); ?></span>
        </div>
        <?php if ($is_live): ?>
        <div class="live-badge-header">
            <div class="live-dot-sm"></div>
            AO VIVO
        </div>
        <?php endif; ?>
        <div class="viewers-header">
            <i class="fas fa-eye" style="color:var(--live-red);font-size:10px;"></i>
            <span id="viewerCount"><?php echo (int)$stream['viewers_count']; ?></span>
        </div>
    </header>

    <div class="toast-container" id="toastContainer"></div>

    <main class="watch-layout">

        <!-- ── Player Section ── -->
        <div class="player-section">
            <?php if ($is_live): ?>
                <div class="video-wrapper" id="videoWrapper">
                    <video id="livePlayer" autoplay playsinline></video>
                    <div class="player-overlay-top">
                        <div class="live-badge-watch">
                            <div class="live-dot-sm"></div>
                            AO VIVO
                        </div>
                        <div class="viewers-live-count">
                            <i class="fas fa-eye" style="color:var(--live-red);font-size:10px;"></i>
                            <span id="viewerCountOverlay"><?php echo (int)$stream['viewers_count']; ?></span>
                        </div>
                    </div>
                    <div class="buffer-spinner" id="bufferSpinner">
                        <div class="spinner"></div>
                    </div>
                    <div class="camera-off-overlay" id="cameraOffOverlay" style="display: none;">
                        <i class="fas fa-video-slash"></i>
                        <p>A câmara está desligada</p>
                    </div>
                    <!-- Botões de acção sobre o vídeo -->
                    <button class="btn-heart" id="btnHeart" onclick="sendHeart(event)" title="Coração">
                        <i class="fas fa-heart"></i>
                    </button>
                    <button class="btn-expand-video" id="btnExpand" onclick="openFullscreen()" title="Maximizar">
                        <i class="fas fa-expand"></i>
                    </button>
                </div>

                <!-- Overlay de ecrã inteiro mobile -->
                <div class="video-fullscreen-overlay" id="videoFsOverlay">
                    <video id="livePlayerFs" autoplay playsinline></video>
                    <button class="btn-close-fullscreen" onclick="closeFullscreen()" title="Fechar">
                        <i class="fas fa-times"></i>
                    </button>
                    <button class="btn-heart-fs" id="btnHeartFs" onclick="sendHeart(event)" title="Coração">
                        <i class="fas fa-heart"></i>
                    </button>
                </div>
            <?php else: ?>
                <div class="offline-screen">
                    <i class="fas fa-satellite-dish"></i>
                    <h2>Stream <?php echo $stream['status'] === 'ended' ? 'terminada' : 'offline'; ?></h2>
                    <p>
                        <?php if ($stream['status'] === 'ended'): ?>
                            Esta stream já terminou. Fica atento para a próxima!
                        <?php else: ?>
                            O streamer ainda não foi ao vivo.
                        <?php endif; ?>
                    </p>
                    <a href="live.php" style="color:#4facfe;text-decoration:none;margin-top:8px;font-weight:600;">
                        <i class="fas fa-arrow-left"></i> Ver outras streams
                    </a>
                </div>
            <?php endif; ?>

            <!-- Info da stream -->
            <div class="stream-info">
                <div class="stream-title-row">
                    <div class="mic-muted-indicator" id="micMutedIndicator" style="display: none;">
                        <i class="fas fa-microphone-slash"></i> Áudio da live desligado
                    </div>
                    <div style="flex: 1;" id="micMutedSpacer"></div>
                    <?php if (!empty($stream['category'])): ?>
                        <span class="category-tag"><?php echo htmlspecialchars($stream['category']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="streamer-info-row">
                    <a href="profile.php?user=<?php echo htmlspecialchars($stream['username']); ?>">
                        <img src="<?php echo htmlspecialchars(avatar_url($stream['profile_picture'])); ?>"
                             alt="@<?php echo htmlspecialchars($stream['username']); ?>"
                             class="streamer-avatar-lg">
                    </a>
                    <div class="streamer-details">
                        <div class="streamer-name-lg">
                            <a href="profile.php?user=<?php echo htmlspecialchars($stream['username']); ?>" style="color:inherit;text-decoration:none;">
                                <?php echo htmlspecialchars($stream['username']); ?>
                            </a>
                            <?php if ($stream['is_verified']): ?>
                                <i class="fas fa-check-circle verified-check"></i>
                            <?php endif; ?>
                        </div>
                        <div class="streamer-followers">
                            <?php echo formatNumberShort($stream['followers_count']); ?> seguidores
                        </div>
                    </div>
                    <?php if (isLoggedIn() && !$is_my_stream): ?>
                        <button class="btn-follow <?php echo $is_following ? 'following' : ''; ?>"
                                id="followBtn"
                                onclick="toggleFollow(<?php echo (int)$stream['user_id']; ?>)">
                            <?php echo $is_following ? '✓ Seguindo' : '+ Seguir'; ?>
                        </button>
                    <?php endif; ?>
                    <button class="btn-share" onclick="shareStream()">
                        <i class="fas fa-share-alt"></i>
                        Partilhar
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Chat Panel ── -->
        <div class="chat-panel">
            <div class="chat-header">
                <i class="fas fa-comments" style="color:var(--live-red)"></i>
                Chat ao Vivo
                <div class="chat-viewer-count">
                    <div class="live-dot-sm"></div>
                    <span id="chatViewerCount"><?php echo (int)$stream['viewers_count']; ?></span>
                </div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <?php if (empty($chat_history)): ?>
                    <div class="system-msg" id="chatEmptyState">💬 Sê o primeiro a comentar!</div>
                <?php else: ?>
                    <?php 
                    $my_username = $_SESSION['username'] ?? '';
                    foreach ($chat_history as $msg): 
                        $is_mine = ($msg['username'] === $my_username);
                    ?>
                        <div class="chat-msg<?php echo $is_mine ? ' msg-mine' : ''; ?>">
                            <img src="<?php echo htmlspecialchars(avatar_url($msg['profile_picture'])); ?>"
                                 class="chat-msg-avatar" alt="">
                            <div class="chat-msg-body" style="<?php echo $is_mine ? 'text-align:right;' : ''; ?>">
                                <div class="chat-msg-username<?php echo ($msg['username'] === $stream['username']) ? ' streamer-tag' : ''; ?>">
                                    <?php echo htmlspecialchars($msg['username']); ?>
                                    <?php if ($msg['username'] === $stream['username']): ?> 🔴
                                    <?php elseif ($msg['is_verified']): ?> ✓
                                    <?php endif; ?>
                                </div>
                                <div class="chat-msg-text"><?php echo htmlspecialchars($msg['message']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (isLoggedIn()): ?>
                <div class="chat-input-area">
                    <div class="chat-input-row">
                        <input type="text" class="chat-input" id="chatInput"
                               placeholder="<?php echo $is_live ? 'Mete dica...' : 'Stream offline'; ?>"
                               maxlength="500"
                               <?php echo !$is_live ? 'disabled' : ''; ?>
                               onkeydown="if(event.key==='Enter') sendChat()">
                        <button class="chat-send-btn" onclick="sendChat()"
                                <?php echo !$is_live ? 'disabled' : ''; ?>>
                            <i class="fas fa-arrow-up"></i>
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="login-prompt">
                    <a href="login.php?next=live-watch.php?id=<?php echo $stream_id; ?>">Entra</a>
                    para participar no chat
                </div>
            <?php endif; ?>
        </div>

    </main>

    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
    <script>
    const LIVE_SERVER_URL    = '<?php echo $live_server_url; ?>';
    const STREAM_ID          = <?php echo $stream_id; ?>;
    const IS_LIVE            = <?php echo $is_live ? 'true' : 'false'; ?>;
    const STREAMER_USERNAME  = <?php echo json_encode($stream['username']); ?>;
    const CURRENT_USERNAME   = <?php echo json_encode($_SESSION['username'] ?? ''); ?>;
    const CSRF_TOKEN         = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const IS_LOGGED          = <?php echo isLoggedIn() ? 'true' : 'false'; ?>;

    let socket = null, livekitRoom = null;
    let isFollowing = <?php echo json_encode($is_following); ?>;

    if (IS_LIVE) initViewer();

    async function initViewer() {
        const spinner = document.getElementById('bufferSpinner');
        try {
            // 1. Obter token LiveKit para subscriber
            const tkRes = await fetch('api/livekit_token.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ stream_id: STREAM_ID, role: 'subscriber' })
            });
            const tkData = await tkRes.json();
            if (!tkData.success) throw new Error(tkData.error || 'Erro ao obter token');

            // 2. Conectar ao LiveKit como subscriber
            livekitRoom = new LivekitClient.Room({
                adaptiveStream: true,
            });

            livekitRoom.on(LivekitClient.RoomEvent.TrackSubscribed, (track, publication, participant) => {
                if (spinner) spinner.style.display = 'none';
                const video = document.getElementById('livePlayer');
                if (track.kind === LivekitClient.Track.Kind.Video ||
                    track.kind === LivekitClient.Track.Kind.Audio) {
                    track.attach(video);
                    video.play().catch(() => {});
                }
                
                if (track.kind === LivekitClient.Track.Kind.Video) {
                    document.getElementById('cameraOffOverlay').style.display = publication.isMuted ? 'flex' : 'none';
                } else if (track.kind === LivekitClient.Track.Kind.Audio) {
                    document.getElementById('micMutedIndicator').style.display = publication.isMuted ? 'flex' : 'none';
                    document.getElementById('micMutedSpacer').style.display = publication.isMuted ? 'none' : 'block';
                }
            });

            livekitRoom.on(LivekitClient.RoomEvent.TrackMuted, (publication, participant) => {
                if (publication.kind === LivekitClient.Track.Kind.Video) {
                    document.getElementById('cameraOffOverlay').style.display = 'flex';
                } else if (publication.kind === LivekitClient.Track.Kind.Audio) {
                    document.getElementById('micMutedIndicator').style.display = 'flex';
                    document.getElementById('micMutedSpacer').style.display = 'none';
                }
            });

            livekitRoom.on(LivekitClient.RoomEvent.TrackUnmuted, (publication, participant) => {
                if (publication.kind === LivekitClient.Track.Kind.Video) {
                    document.getElementById('cameraOffOverlay').style.display = 'none';
                } else if (publication.kind === LivekitClient.Track.Kind.Audio) {
                    document.getElementById('micMutedIndicator').style.display = 'none';
                    document.getElementById('micMutedSpacer').style.display = 'block';
                }
            });

            livekitRoom.on(LivekitClient.RoomEvent.Disconnected, () => {
                showStreamOffline('Ligação terminada.');
            });

            livekitRoom.on(LivekitClient.RoomEvent.ParticipantDisconnected, () => {
                // Se o streamer saiu, mostrar offline
                if (livekitRoom.remoteParticipants.size === 0) {
                    showStreamOffline('O streamer terminou a live.');
                }
            });

            await livekitRoom.connect(tkData.livekit_url, tkData.token);

            // 3. Conectar ao Socket.IO apenas para chat e eventos
            const tokenRes  = await fetch('api/chat_token.php');
            const tokenData = await tokenRes.json();

            socket = io(LIVE_SERVER_URL, {
                path: '/live-socket/',
                auth: { token: IS_LOGGED ? tokenData.token : undefined },
                transports: ['websocket']
            });

            socket.on('connect', () => { socket.emit('join_stream', { streamId: STREAM_ID }); });
            socket.on('join_stream_success', ({ viewerCount }) => { updateViewerCount(viewerCount); });
            socket.on('stream_not_found', () => { showStreamOffline(); });
            socket.on('viewer_count', ({ count }) => { updateViewerCount(count); });
            socket.on('live_chat_message', (msg) => { addChatMessage(msg); });
            socket.on('system_message', ({ message }) => { addSystemMessage(message); });
            socket.on('stream_ended', ({ message }) => {
                showStreamOffline(message);
                if (livekitRoom) livekitRoom.disconnect();
                if (socket) socket.disconnect();
            });
            socket.on('disconnect', () => { showToast('Ligação ao chat perdida', 'error'); });

            setTimeout(() => { if (spinner) spinner.style.display = 'none'; }, 8000);

        } catch (err) {
            console.error('Erro ao iniciar viewer:', err);
            if (spinner) spinner.style.display = 'none';
            showToast('Erro ao conectar à live', 'error');
        }
    }


    function showStreamOffline(message) {
        const wrapper = document.getElementById('videoWrapper');
        if (!wrapper) return;
        wrapper.innerHTML = `
            <div class="offline-screen">
                <i class="fas fa-satellite-dish"></i>
                <h2>Stream terminada</h2>
                <p>${message || 'Esta stream chegou ao fim.'}</p>
                <a href="live.php" style="color:#4facfe;text-decoration:none;font-weight:600;margin-top:8px;">
                    <i class="fas fa-arrow-left"></i> Ver outras streams
                </a>
            </div>`;
        const input = document.getElementById('chatInput');
        if (input) { input.disabled = true; input.placeholder = 'Stream terminada'; }
    }

    function updateViewerCount(count) {
        ['viewerCount','chatViewerCount','viewerCountOverlay'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = count;
        });
    }

    function sendChat() {
        const input = document.getElementById('chatInput');
        const msg = input?.value.trim();
        if (!msg || !socket?.connected) return;
        socket.emit('live_chat_message', { streamId: STREAM_ID, message: msg });
        input.value = '';
    }

    /* ── Ecrã inteiro (mobile) ── */
    function openFullscreen() {
        const overlay = document.getElementById('videoFsOverlay');
        const srcVideo = document.getElementById('livePlayer');
        const fsVideo  = document.getElementById('livePlayerFs');
        // Partilhar o mesmo srcObject
        fsVideo.srcObject = srcVideo.srcObject;
        fsVideo.play().catch(() => {});
        overlay.classList.add('active');
        // Tentar orientação landscape no mobile
        try { screen.orientation.lock('landscape').catch(() => {}); } catch(e) {}
    }

    function closeFullscreen() {
        const overlay = document.getElementById('videoFsOverlay');
        overlay.classList.remove('active');
        document.getElementById('livePlayerFs').srcObject = null;
        try { screen.orientation.unlock(); } catch(e) {}
    }

    /* ── Efeito de corações a voar ── */
    const HEARTS = ['❤️','🧡','💛','💜','💙','💖','💗'];
    function sendHeart(e) {
        const btn = e.currentTarget;
        const rect = btn.getBoundingClientRect();
        // Animar o botão
        btn.style.transform = 'scale(1.35)';
        setTimeout(() => { btn.style.transform = ''; }, 180);
        // Lançar 3-5 corações com offsets aleatórios
        const count = 3 + Math.floor(Math.random() * 3);
        for (let i = 0; i < count; i++) {
            setTimeout(() => {
                const el = document.createElement('span');
                el.className = 'floating-heart';
                el.textContent = HEARTS[Math.floor(Math.random() * HEARTS.length)];
                const offsetX = (Math.random() - 0.5) * 60;
                el.style.left = (rect.left + rect.width / 2 + offsetX) + 'px';
                el.style.top  = (rect.top - 10) + 'px';
                el.style.setProperty('--rot', (Math.random() * 30 - 15) + 'deg');
                document.body.appendChild(el);
                el.addEventListener('animationend', () => el.remove());
            }, i * 80);
        }
    }

    function addChatMessage(msg) {
        const c = document.getElementById('chatMessages');
        const emptyMsg = document.getElementById('chatEmptyState');
        if (emptyMsg) emptyMsg.remove();
        const isStreamer = msg.username === STREAMER_USERNAME;
        const isMine = msg.username === CURRENT_USERNAME;
        const div = document.createElement('div');
        div.className = 'chat-msg' + (isMine ? ' msg-mine' : '');
        
        const avatarUrl = msg.profilePicture ? 'assets/images/avatars/' + msg.profilePicture : 'assets/images/avatars/default.webp';
        
        div.innerHTML = `
            <img src="${esc(avatarUrl)}" class="chat-msg-avatar" alt="">
            <div class="chat-msg-body" style="${isMine ? 'text-align:right;' : ''}">
                <div class="chat-msg-username${isStreamer?' streamer-tag':''}">
                    ${esc(msg.username)}${isStreamer?' 🔴':msg.isVerified?' ✓':''}
                </div>
                <div class="chat-msg-text">${esc(msg.message)}</div>
            </div>`;
        c.appendChild(div);
        c.scrollTop = c.scrollHeight;
    }

    function addSystemMessage(text) {
        const c = document.getElementById('chatMessages');
        const div = document.createElement('div');
        div.className = 'system-msg';
        div.textContent = text;
        c.appendChild(div);
        c.scrollTop = c.scrollHeight;
    }

    async function toggleFollow(userId) {
        try {
            const res = await fetch('api/toggle_user_follow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ following_id: userId })
            });
            const data = await res.json();
            if (data.success !== undefined || data.following !== undefined) {
                isFollowing = !isFollowing;
                const btn = document.getElementById('followBtn');
                if (btn) {
                    btn.textContent = isFollowing ? '✓ Seguindo' : '+ Seguir';
                    btn.classList.toggle('following', isFollowing);
                }
                showToast(isFollowing ? 'A seguir!' : 'Deixaste de seguir', 'info');
            }
        } catch(e) { showToast('Erro ao seguir', 'error'); }
    }

    function shareStream() {
        const url = window.location.href;
        if (navigator.share) {
            navigator.share({ title: document.title, url });
        } else {
            navigator.clipboard.writeText(url).then(() => showToast('Link copiado!', 'success'));
        }
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showToast(msg, type = 'info') {
        const c = document.getElementById('toastContainer');
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = msg;
        c.appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const chat = document.getElementById('chatMessages');
        if (chat) chat.scrollTop = chat.scrollHeight;
    });
    </script>
</body>
</html>
