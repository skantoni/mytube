<?php
/**
 * live-stream.php — Painel do Streamer (Go Live)
 * Permite ao utilizador autenticado ir ao vivo via MediaRecorder API
 */
require_once 'includes/config.php';
require_once 'includes/r2_storage.php';

if (!isLoggedIn()) {
    header('Location: login.php?next=live-stream.php');
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT id, username, full_name, profile_picture, is_verified FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$current_user_id]);
$me = $stmt->fetch();

function generateLiveJwt(int $userId, string $username): string {
    $secret = env('CHAT_JWT_SECRET', 'CHANGE_ME_IN_PRODUCTION');
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $header  = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = $b64(json_encode(['userId' => $userId, 'username' => $username, 'exp' => time() + 7200]));
    $sig     = $b64(hash_hmac('sha256', "$header.$payload", $secret, true));
    return "$header.$payload.$sig";
}

$live_jwt = generateLiveJwt($current_user_id, $me['username'] ?? '');
$live_server_url = env('LIVE_SERVER_URL', 'http://localhost:3003');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Ir ao Vivo — MyTube</title>
    <meta name="description" content="Transmite ao vivo para os teus seguidores na MyTube.">
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
            --surface2: rgba(255,255,255,0.08);
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
            overscroll-behavior: none;
        }

        /* ── HEADER ── */
        .live-header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: var(--header-h);
            background: rgba(0,0,0,0.9);
            -webkit-backdrop-filter: blur(16px);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 16px;
            gap: 12px;
            z-index: 200;
        }
        .header-back-btn {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--surface);
            border: none;
            color: var(--text);
            font-size: 16px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .header-back-btn:hover { background: var(--surface2); }
        .header-title {
            flex: 1;
            font-size: 17px;
            font-weight: 700;
        }
        .header-user {
            display: flex; align-items: center; gap: 9px;
        }
        .header-user img {
            width: 34px; height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
        }
        .header-user span {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .live-indicator-header {
            background: var(--live-red);
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.8px;
            display: none;
            align-items: center;
            gap: 5px;
        }
        .live-indicator-header.visible { display: flex; }
        .live-dot-sm {
            width: 6px; height: 6px;
            background: #fff;
            border-radius: 50%;
            animation: pulse-dot 1s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(0.6); }
        }

        /* ── LAYOUT ── */
        .streamer-page {
            padding-top: var(--header-h);
            min-height: 100vh;
        }

        /* ── SETUP PANEL ── */
        .setup-panel {
            max-width: 680px;
            margin: 0 auto;
            padding: 28px 16px 40px;
        }
        .setup-hero {
            text-align: center;
            margin-bottom: 28px;
        }
        .setup-hero h2 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .setup-hero p {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* ── CAMERA PREVIEW ── */
        .camera-box {
            width: 100%;
            aspect-ratio: 16/9;
            background: #0a0a0f;
            border: 1.5px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            margin-bottom: 24px;
        }
        .camera-box video {
            width: 100%; height: 100%;
            object-fit: cover;
            display: none;
        }
        .camera-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: radial-gradient(circle at center, #1a0a1e 0%, #0a0a0f 100%);
        }
        .camera-placeholder i {
            font-size: 48px;
            color: rgba(255,255,255,0.15);
        }
        .camera-placeholder p {
            color: var(--text-muted);
            font-size: 14px;
        }
        .btn-activate-cam {
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 100px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.2s;
        }
        .btn-activate-cam:hover {
            background: rgba(255,255,255,0.12);
        }

        /* ── FORM ── */
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .form-input, .form-textarea, .form-select {
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 13px 16px;
            color: var(--text);
            font-size: 15px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            border-color: rgba(255,45,85,0.5);
        }
        .form-textarea { resize: vertical; min-height: 90px; line-height: 1.5; }
        .form-select option { background: #1a1a2e; }

        /* Dropdown Customizado (Minimalista) */
        .custom-select { position: relative; user-select: none; width: 100%; }
        .custom-select-trigger { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; cursor: pointer; color: var(--text); font-size: 15px; font-weight: 500; transition: all 0.2s; }
        .custom-select-trigger:hover { border-color: rgba(255,255,255,0.2); background: var(--surface2); }
        .custom-select-trigger i.fa-chevron-down { font-size: 14px; color: var(--text-muted); transition: transform 0.2s; }
        .custom-select.open .custom-select-trigger i.fa-chevron-down { transform: rotate(180deg); }
        .custom-options { position: absolute; top: 100%; left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; margin-top: 6px; z-index: 100; max-height: 250px; overflow-y: auto; opacity: 0; visibility: hidden; transition: all 0.2s; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .custom-select.open .custom-options { opacity: 1; visibility: visible; }
        .custom-option { padding: 12px 16px; display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text); font-size: 14px; font-weight: 500; transition: background 0.2s; }
        .custom-option:hover { background: var(--surface2); }
        .custom-option i { width: 20px; text-align: center; color: var(--text-muted); font-size: 16px; }
        .custom-option:hover i { color: #fff; }

        /* ── SETUP ACTIONS ── */
        .setup-actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
        }
        .btn-back {
            flex: 1;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 100px;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background 0.2s;
        }
        .btn-back:hover { background: var(--surface2); }
        .btn-go-live {
            flex: 2;
            background: var(--live-red);
            border: none;
            color: #fff;
            border-radius: 100px;
            padding: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 6px 20px var(--live-glow);
        }
        .btn-go-live:hover { background: #ff1a40; transform: translateY(-1px); }
        .btn-go-live:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* ── LIVE VIEW ── */
        .live-view {
            display: none;
            height: calc(100vh - var(--header-h));
            position: relative;
        }
        .live-view.active { display: grid; }

        /* Desktop: grid 2 cols */
        @media (min-width: 900px) {
            .live-view { grid-template-columns: 1fr 340px; }
        }

        /* ── VIDEO AREA ── */
        .video-area {
            position: relative;
            background: #000;
            overflow: hidden;
        }
        .live-video {
            width: 100%; height: 100%;
            object-fit: cover;
        }

        /* Overlay info on video */
        .video-overlay-top {
            position: absolute;
            top: 16px; left: 16px; right: 16px;
            display: flex; align-items: center; gap: 10px;
            z-index: 5;
        }
        .live-badge-overlay {
            background: var(--live-red);
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.8px;
            display: flex; align-items: center; gap: 5px;
        }
        .stream-timer-overlay {
            background: rgba(0,0,0,0.6);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            font-variant-numeric: tabular-nums;
        }
        .viewers-overlay {
            margin-left: auto;
            background: rgba(0,0,0,0.6);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            display: flex; align-items: center; gap: 5px;
        }

        /* Controls bar bottom of video */
        .video-controls {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            padding: 20px 16px 16px;
            background: linear-gradient(transparent, rgba(0,0,0,0.85));
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 5;
        }
        .ctrl-btn {
            width: 46px; height: 46px;
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .ctrl-btn:hover { background: rgba(255,255,255,0.25); }
        .ctrl-btn.muted { background: rgba(255,45,85,0.3); color: var(--live-red); }
        .btn-end-stream {
            margin-left: auto;
            background: #cc0033;
/*             background: linear-gradient(135deg, #ff2d55, #ff1a40);
 */            /* //cc0033 */
            border: none;
            color: #fff;
            border-radius: 100px;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 16px var(--live-glow);
        }

        /* ── CHAT PANEL ── */
        .chat-side {
            display: flex;
            flex-direction: column;
            background: #0a0a0f;
            border-left: 1px solid var(--border);
            height: 100%;
            overflow: hidden;
        }
        .chat-side-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            font-weight: 700;
            display: flex; align-items: center; gap: 8px;
            flex-shrink: 0;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .chat-messages::-webkit-scrollbar { width: 3px; }
        .chat-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
        .chat-msg {
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .chat-msg img {
            width: 28px; height: 28px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        .chat-msg-body { min-width: 0; }
        .chat-msg-name {
            font-size: 12px;
            font-weight: 700;
            color: #4facfe;
            margin-bottom: 3px;
        }
        .chat-msg-name.verified::after { content: ' ✓'; color: #3b82f6; }
        .chat-msg-text {
            font-size: 13px;
            line-height: 1.4;
            color: rgba(255,255,255,0.9);
            word-break: break-word;
        }

        /* ── As minhas mensagens ── */
        .chat-msg.msg-mine { flex-direction: row-reverse; }
        .chat-msg.msg-mine .chat-msg-name { text-align: right; }
        .chat-msg.msg-mine .chat-msg-text {
            text-align: right;
            padding: 2px 0;
        }

        .system-msg {
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
            padding: 4px 0;
        }
        .chat-input-row {
            padding: 12px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }
        .chat-input {
            flex: 1;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 100px;
            padding: 10px 16px;
            color: var(--text);
            font-size: 14px;
            font-family: inherit;
            outline: none;
        }
        .chat-input:focus { border-color: rgba(255,45,85,0.4); }
        .chat-send-btn {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--live-red);
            border: none;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .chat-send-btn:hover { background: #ff1a40; }

        /* Mobile chat toggle */
        @media (max-width: 899px) {
            .live-view { display: none; flex-direction: column; }
            .live-view.active { display: flex; }
            .video-area { flex: none; height: 55vw; min-height: 200px; }
            .chat-side {
                flex: 1;
                border-left: none;
                border-top: 1px solid var(--border);
                min-height: 250px;
            }
        }

        /* ── TOAST ── */
        .toast-wrap {
            position: fixed;
            bottom: 24px; right: 16px;
            z-index: 9999;
            display: flex; flex-direction: column;
            gap: 8px;
            max-width: 320px;
        }
        .toast {
            background: rgba(20,20,35,0.95);
            -webkit-backdrop-filter: blur(12px);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            color: var(--text);
            display: flex; align-items: center; gap: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            animation: toast-in 0.3s ease;
        }
        .toast.success { border-color: rgba(0,210,100,0.4); }
        .toast.error { border-color: rgba(255,45,85,0.4); }
        @keyframes toast-in {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
    </style>
</head>
<body>

    <!-- Header imersivo -->
    <header class="live-header">
        <button class="header-back-btn" onclick="history.back()" title="Voltar">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="header-title">Ir ao Vivo</div>
        <div class="live-indicator-header" id="liveIndicatorHeader">
            <div class="live-dot-sm"></div>
            AO VIVO
        </div>
        <div class="header-user">
            <img src="<?php echo htmlspecialchars(avatar_url($me['profile_picture'])); ?>" alt="<?php echo htmlspecialchars($me['username']); ?>">
            <span><?php echo htmlspecialchars('@' . $me['username']); ?></span>
        </div>
    </header>

    <!-- Toasts -->
    <div class="toast-wrap" id="toastWrap"></div>

    <div class="streamer-page">

        <!-- ── SETUP PANEL ── -->
        <div class="setup-panel" id="setupPanel">
            <div class="setup-hero">
                <h2>🔴 Ir ao Vivo</h2>
                <p>Configura a tua transmissão e partilha o momento com os teus seguidores</p>
            </div>

            <!-- Camera -->
            <div class="camera-box">
                <video id="previewVideo" autoplay muted playsinline></video>
                <div class="camera-placeholder" id="cameraPlaceholder">
                    <i class="fas fa-video-slash"></i>
                    <p>Câmera desligada</p>
                    <button class="btn-activate-cam" onclick="activateCamera()">
                        <i class="fas fa-video"></i>
                        Ativar câmera
                    </button>
                </div>
            </div>

            <!-- Título -->
            <div class="form-group">
                <label class="form-label" for="streamTitle">Título da Stream *</label>
                <input type="text" id="streamTitle" class="form-input"
                       placeholder="Ex: Jogando FIFA, estudo ao vivo, Q&A..."
                       maxlength="255">
            </div>

            <!-- Descrição -->
            <div class="form-group">
                <label class="form-label" for="streamDesc">Descrição (opcional)</label>
                <textarea id="streamDesc" class="form-textarea"
                          placeholder="Conta aos teus seguidores o que vais fazer..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Categoria</label>
                <div class="custom-select" id="categorySelect">
                    <div class="custom-select-trigger" onclick="toggleDropdown()">
                        <span id="selectedCategoryText"><i class="fas fa-layer-group" style="width:20px;color:var(--text-muted);"></i> Sem categoria</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="custom-options">
                        <div class="custom-option" onclick="selectCat('', 'Sem categoria', 'fa-layer-group')">
                            <i class="fas fa-layer-group"></i> Sem categoria
                        </div>
                        <div class="custom-option" onclick="selectCat('Gaming', 'Gaming', 'fa-gamepad')">
                            <i class="fas fa-gamepad"></i> Gaming
                        </div>
                        <div class="custom-option" onclick="selectCat('Música', 'Música', 'fa-music')">
                            <i class="fas fa-music"></i> Música
                        </div>
                        <div class="custom-option" onclick="selectCat('Conversa', 'Conversa / Q&A', 'fa-comments')">
                            <i class="fas fa-comments"></i> Conversa / Q&A
                        </div>
                        <div class="custom-option" onclick="selectCat('Educação', 'Educação', 'fa-book')">
                            <i class="fas fa-book"></i> Educação
                        </div>
                        <div class="custom-option" onclick="selectCat('Desporto', 'Desporto', 'fa-futbol')">
                            <i class="fas fa-futbol"></i> Desporto
                        </div>
                        <div class="custom-option" onclick="selectCat('Arte', 'Arte', 'fa-palette')">
                            <i class="fas fa-palette"></i> Arte
                        </div>
                        <div class="custom-option" onclick="selectCat('Tecnologia', 'Tecnologia', 'fa-laptop-code')">
                            <i class="fas fa-laptop-code"></i> Tecnologia
                        </div>
                        <div class="custom-option" onclick="selectCat('Entretenimento', 'Entretenimento', 'fa-masks-theater')">
                            <i class="fas fa-masks-theater"></i> Entretenimento
                        </div>
                    </div>
                </div>
                <input type="hidden" id="streamCategory" value="">
            </div>

            <div class="setup-actions">
                <a href="live.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i>
                    Voltar
                </a>
                <button class="btn-go-live" id="btnGoLive" onclick="goLive()">
                    <i class="fas fa-broadcast-tower"></i>
                    Ir ao Vivo
                </button>
            </div>
        </div>

        <!-- ── LIVE VIEW ── -->
        <div class="live-view" id="liveView">

            <!-- Vídeo -->
            <div class="video-area">
                <video class="live-video" id="liveVideo" autoplay muted playsinline></video>

                <div class="video-overlay-top">
                    <div class="live-badge-overlay">
                        <div class="live-dot-sm"></div>
                        AO VIVO
                    </div>
                    <div class="stream-timer-overlay" id="streamTimer">00:00</div>
                    <div class="viewers-overlay">
                        <i class="fas fa-eye" style="color:#3b82f6;font-size:10px;"></i>
                        <span id="viewerCount">0</span>
                    </div>
                </div>

                <div class="video-controls">
                    <button class="ctrl-btn" id="btnMuteMic" title="Microfone" onclick="toggleMic()">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <button class="ctrl-btn" id="btnToggleCam" title="Câmera" onclick="toggleCam()">
                        <i class="fas fa-video"></i>
                    </button>
                    <button class="btn-end-stream" onclick="endStream()">
                        <i class="fas fa-stop-circle"></i>
                        Terminar
                    </button>
                </div>
            </div>

            <!-- Chat -->
            <div class="chat-side">
                <div class="chat-side-header">
                    <i class="fas fa-comments" style="color:var(--live-red)"></i>
                    Chat ao Vivo
                    <span style="margin-left:auto;font-size:12px;color:var(--text-muted);font-weight:500;" id="msgCountDisplay">0 msgs</span>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <div class="system-msg">💬 O chat aparece aqui quando estiveres ao vivo</div>
                </div>
                <div class="chat-input-row">
                    <input type="text" class="chat-input" id="chatInput"
                           placeholder="Mete dica..."
                           maxlength="500"
                           onkeydown="if(event.key==='Enter') sendChat()">
                    <button class="chat-send-btn" onclick="sendChat()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
    <script>
    const LIVE_SERVER_URL = '<?php echo $live_server_url; ?>';
    const CSRF_TOKEN      = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const MY_USER_ID      = <?php echo $current_user_id; ?>;
    const MY_USERNAME     = <?php echo json_encode($me['username']); ?>;
    const MY_AVATAR       = <?php echo json_encode(avatar_url($me['profile_picture'])); ?>;
    const LIVE_JWT        = <?php echo json_encode($live_jwt); ?>;

    let socket = null, livekitRoom = null, mediaStream = null;
    let streamId = null;
    let timerInterval = null, streamSeconds = 0, msgCount = 0;
    let isMicMuted = false, isCamOff = false, cameraActivated = false;
    let localVideoTrack = null, localAudioTrack = null;

    // Custom Dropdown Logic
    function toggleDropdown() {
        document.getElementById('categorySelect').classList.toggle('open');
    }
    
    function selectCat(value, text, icon) {
        document.getElementById('streamCategory').value = value;
        document.getElementById('selectedCategoryText').innerHTML = `<i class="fas ${icon}" style="width:20px;color:var(--text-muted);"></i> ${text}`;
        document.getElementById('categorySelect').classList.remove('open');
    }

    // Fechar dropdown ao clicar fora
    document.addEventListener('click', function(e) {
        if (!document.getElementById('categorySelect').contains(e.target)) {
            document.getElementById('categorySelect').classList.remove('open');
        }
    });

    // Ativar câmera
    async function activateCamera() {
        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                video: { width: 1280, height: 720, facingMode: 'user' },
                audio: true
            });
            const video = document.getElementById('previewVideo');
            video.srcObject = mediaStream;
            video.style.display = 'block';
            document.getElementById('cameraPlaceholder').style.display = 'none';
            cameraActivated = true;
            toast('Câmera ativada! Podes ir ao vivo.', 'success');
        } catch (err) {
            toast('Não foi possível aceder à câmera: ' + err.message, 'error');
        }
    }

    // Ir ao vivo
    async function goLive() {
        const title    = document.getElementById('streamTitle').value.trim();
        const desc     = document.getElementById('streamDesc').value.trim();
        const category = document.getElementById('streamCategory').value;

        if (!title) { toast('O título é obrigatório!', 'error'); document.getElementById('streamTitle').focus(); return; }
        if (!cameraActivated) { toast('Ativa a câmera primeiro!', 'error'); return; }

        const btn = document.getElementById('btnGoLive');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A iniciar...';

        try {
            // 1. Registar a stream na base de dados
            const res = await fetch('api/start_stream.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ title, description: desc, category })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erro ao criar stream');
            streamId = data.stream_id;

            // 2. Obter token LiveKit para publisher
            const tkRes = await fetch('api/livekit_token.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ stream_id: streamId, role: 'publisher' })
            });
            const tkData = await tkRes.json();
            if (!tkData.success) throw new Error(tkData.error || 'Erro ao obter token LiveKit');

            // 3. Conectar ao Socket.IO (apenas para chat e contagem de viewers)
            socket = io(LIVE_SERVER_URL, {
                path: '/live-socket/',
                auth: { token: LIVE_JWT },
                transports: ['websocket']
            });
            socket.on('connect', () => { socket.emit('go_live', { streamId }); });
            socket.on('viewer_count', ({ count }) => {
                document.getElementById('viewerCount').textContent = count;
            });
            socket.on('live_chat_message', (msg) => { addChatMsg(msg); });
            socket.on('system_message', ({ message }) => { addSysMsg(message); });
            socket.on('error', ({ message }) => { toast('❌ ' + message, 'error'); });
            socket.on('disconnect', () => { toast('Ligação ao chat perdida', 'error'); });

            // 4. Conectar ao LiveKit e publicar câmara + microfone
            livekitRoom = new LivekitClient.Room({
                adaptiveStream: true,
                dynacast: true,
            });

            livekitRoom.on(LivekitClient.RoomEvent.Disconnected, () => {
                toast('LiveKit desligado', 'error');
            });

            await livekitRoom.connect(tkData.livekit_url, tkData.token);

            // Publicar vídeo e áudio a partir do stream já capturado
            const tracks = await LivekitClient.createLocalTracks({
                audio: true,
                video: { width: 1280, height: 720 },
            });
            for (const track of tracks) {
                await livekitRoom.localParticipant.publishTrack(track);
                if (track.kind === LivekitClient.Track.Kind.Video) localVideoTrack = track;
                if (track.kind === LivekitClient.Track.Kind.Audio) localAudioTrack = track;
            }

            startBroadcast();

        } catch (err) {
            toast(err.message, 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-broadcast-tower"></i> Ir ao Vivo';
        }
    }

    function startBroadcast() {
        document.getElementById('setupPanel').style.display = 'none';
        document.getElementById('liveView').classList.add('active');

        // Mostrar o preview da câmara local no elemento de vídeo
        const liveVideo = document.getElementById('liveVideo');
        if (localVideoTrack) {
            localVideoTrack.attach(liveVideo);
        } else if (mediaStream) {
            liveVideo.srcObject = mediaStream; // fallback
        }

        streamSeconds = 0;
        timerInterval = setInterval(updateTimer, 1000);
        toast('🔴 Estás ao vivo via WebRTC!', 'success');
    }

    function updateTimer() {
        streamSeconds++;
        const h = Math.floor(streamSeconds / 3600);
        const m = Math.floor((streamSeconds % 3600) / 60);
        const s = streamSeconds % 60;
        const str = h > 0 ? `${pad(h)}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
        document.getElementById('streamTimer').textContent = str;
    }
    function pad(n) { return String(n).padStart(2,'0'); }

    function toggleMic() {
        isMicMuted = !isMicMuted;
        // Usar LiveKit para mutar/desmutar (reflete para os viewers)
        if (localAudioTrack) {
            isMicMuted ? localAudioTrack.mute() : localAudioTrack.unmute();
        } else if (mediaStream) {
            mediaStream.getAudioTracks().forEach(t => { t.enabled = !isMicMuted; });
        }
        const btn = document.getElementById('btnMuteMic');
        btn.innerHTML = `<i class="fas fa-${isMicMuted ? 'microphone-slash' : 'microphone'}"></i>`;
        btn.classList.toggle('muted', isMicMuted);
    }

    function toggleCam() {
        isCamOff = !isCamOff;
        // Usar LiveKit para ligar/desligar câmara (reflete para os viewers)
        if (localVideoTrack) {
            isCamOff ? localVideoTrack.mute() : localVideoTrack.unmute();
        } else if (mediaStream) {
            mediaStream.getVideoTracks().forEach(t => { t.enabled = !isCamOff; });
        }
        const btn = document.getElementById('btnToggleCam');
        btn.innerHTML = `<i class="fas fa-${isCamOff ? 'video-slash' : 'video'}"></i>`;
        btn.classList.toggle('muted', isCamOff);
    }

    async function endStream() {
        if (!confirm('Tens a certeza que queres terminar o stream?')) return;

        // 1. Desligar do LiveKit (para de enviar vídeo/áudio)
        if (livekitRoom) {
            await livekitRoom.disconnect();
            livekitRoom = null;
        }

        // 2. Notificar o chat-server e desligar
        if (socket) { socket.emit('end_stream'); socket.disconnect(); }

        // 3. Parar câmara local
        if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
        clearInterval(timerInterval);

        // 4. Marcar como terminada na DB
        try {
            await fetch('api/end_stream.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ stream_id: streamId })
            });
        } catch(e) {}

        streamId = null; // Evita o aviso de saída do beforeunload
        toast('Stream terminada. Obrigado!', 'success');
        setTimeout(() => { window.location.href = 'live.php'; }, 2000);
    }

    function sendChat() {
        const input = document.getElementById('chatInput');
        const msg = input.value.trim();
        if (!msg || !socket?.connected) return;
        socket.emit('live_chat_message', { streamId, message: msg });
        input.value = '';
    }

    function addChatMsg(msg) {
        const c = document.getElementById('chatMessages');
        const isMine = msg.userId === MY_USER_ID;
        msgCount++;
        document.getElementById('msgCountDisplay').textContent = msgCount + ' msgs';
        const div = document.createElement('div');
        div.className = 'chat-msg' + (isMine ? ' msg-mine' : '');
        
        const avatarUrl = msg.profilePicture ? 'assets/images/avatars/' + msg.profilePicture : 'assets/images/avatars/default.webp';
        
        div.innerHTML = `
            <img src="${esc(avatarUrl)}" alt="">
            <div class="chat-msg-body" style="${isMine ? 'text-align:right;' : ''}">
                <div class="chat-msg-name${msg.isVerified?' verified':''}">${esc(msg.username)}</div>
                <div class="chat-msg-text">${esc(msg.message)}</div>
            </div>`;
        c.appendChild(div);
        c.scrollTop = c.scrollHeight;
    }

    function addSysMsg(text) {
        const c = document.getElementById('chatMessages');
        const div = document.createElement('div');
        div.className = 'system-msg';
        div.textContent = text;
        c.appendChild(div);
        c.scrollTop = c.scrollHeight;
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function toast(msg, type = 'info') {
        const wrap = document.getElementById('toastWrap');
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        const icons = { success: '✅', error: '❌', info: 'ℹ️' };
        el.innerHTML = `<span>${icons[type]||''}</span> ${msg}`;
        wrap.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }

    window.addEventListener('beforeunload', (e) => {
        if (streamId && document.getElementById('liveView').classList.contains('active')) {
            e.preventDefault();
            return e.returnValue = 'A stream ainda está ativa. Tens a certeza?';
        }
    });
    </script>
</body>
</html>
