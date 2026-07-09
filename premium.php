<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = (int)$_SESSION['user_id'];

// Buscar dados completos do utilizador
$stmt = $pdo->prepare("
    SELECT username, full_name, profile_picture, is_premium, premium_since, premium_expires, stream_key
    FROM users WHERE id = ? LIMIT 1
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) redirect('login.php');

// Verificar pedido pendente
$pending = $pdo->prepare("
    SELECT id, plan_months, amount_kz, created_at
    FROM premium_requests
    WHERE user_id = ? AND status = 'pending'
    ORDER BY created_at DESC LIMIT 1
");
$pending->execute([$user_id]);
$pending_request = $pending->fetch();

// Estado real do Premium
$is_premium  = (bool)$user['is_premium'];
$is_expired  = $is_premium && !empty($user['premium_expires']) && strtotime($user['premium_expires']) < time();
if ($is_expired) $is_premium = false; // expirado não conta

$expires_soon = $is_premium && !empty($user['premium_expires']) &&
                strtotime($user['premium_expires']) < strtotime('+7 days');

// Preços e planos
$plans = [
    ['months' => 1,  'price' => 500,  'label' => '1 Mês',   'save' => null],
    ['months' => 3,  'price' => 1300, 'label' => '3 Meses', 'save' => '200 AOA'],
    ['months' => 6,  'price' => 2400, 'label' => '6 Meses', 'save' => '600 AOA'],
    ['months' => 12, 'price' => 4500, 'label' => '1 Ano',   'save' => '1.500 AOA'],
];

// Número Express do MyTube
define('EXPRESS_NUMBER', '938 913 718'); // ← Alterares para o teu número real
define('MYTUBE_INSTAGRAM', 'mytube_ao');  // ← Conta verificada do Instagram
define('MYTUBE_WHATSAPP', '244933751074'); // ← Número de suporte
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <title>MyTube Premium</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root {
            --bg-base:    #080c14;
            --bg-surface: #0d1220;
            --bg-card:    #121929;
            --bg-hover:   #182034;
            --border:     rgba(255,255,255,0.07);
            --text-main:  #f1f5f9;
            --text-muted: #94a3b8;
            --text-subtle:#64748b;
            --gold:       #f59e0b;
            --gold-soft:  #fbbf24;
            --gold-glow:  rgba(245,158,11,0.15);
            --gold-border:rgba(245,158,11,0.3);
            --blue:       #3b82f6;
            --blue-glow:  rgba(59,130,246,0.15);
            --green:      #10b981;
            --red:        #ef4444;
            --radius:     14px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
        }

        /* ─── Header ─────────────────────────────────────────── */
        .prem-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(8,12,20,0.92);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .prem-back {
            width: 36px; height: 36px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .prem-back:hover { background: var(--bg-hover); color: var(--text-main); }

        .prem-header-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 1.1rem; font-weight: 700;
        }
        .prem-header-title .crown {
            font-size: 1.2rem;
            filter: drop-shadow(0 0 6px rgba(245,158,11,0.6));
        }

        /* ─── Layout ─────────────────────────────────────────── */
        .prem-main {
            max-width: 640px;
            margin: 0 auto;
            padding: 24px 16px 80px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ─── Card genérico ──────────────────────────────────── */
        .prem-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
        }

        .card-title {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-subtle);
            margin-bottom: 14px;
        }

        /* ─── Status Card ────────────────────────────────────── */
        .status-card {
            border-radius: var(--radius);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .status-card.is-premium {
            background: linear-gradient(135deg, #1a1400, #1f1700);
            border: 1px solid var(--gold-border);
        }
        .status-card.not-premium {
            background: var(--bg-card);
            border: 1px solid var(--border);
        }
        .status-card.has-pending {
            background: linear-gradient(135deg, #0d1526, #111c32);
            border: 1px solid rgba(59,130,246,0.3);
        }
        .status-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .status-icon.gold  { background: rgba(245,158,11,0.12); }
        .status-icon.grey  { background: rgba(100,116,139,0.1); }
        .status-icon.blue  { background: rgba(59,130,246,0.1); }
        .status-info { flex: 1; }
        .status-info h3 { font-size: 1rem; font-weight: 700; margin-bottom: 3px; }
        .status-info p  { font-size: 0.82rem; color: var(--text-muted); line-height: 1.5; }
        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .badge-gold   { background: rgba(245,158,11,0.15); color: var(--gold); }
        .badge-blue   { background: rgba(59,130,246,0.12); color: #60a5fa; }
        .badge-grey   { background: rgba(100,116,139,0.1); color: var(--text-subtle); }
        .badge-red    { background: rgba(239,68,68,0.1); color: #f87171; }

        /* ─── Stream Key Box ─────────────────────────────────── */
        .stream-key-box {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            margin-top: 12px;
        }
        .stream-key-label {
            font-size: 0.72rem;
            color: var(--text-subtle);
            font-weight: 600;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .stream-key-row {
            display: flex; align-items: center; gap: 8px;
        }
        .stream-key-val {
            font-family: 'Courier New', monospace;
            font-size: 0.82rem;
            color: var(--gold-soft);
            flex: 1;
            word-break: break-all;
            letter-spacing: 0.5px;
        }
        .stream-key-copy {
            background: none; border: none;
            color: var(--text-subtle);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .stream-key-copy:hover { color: var(--text-main); background: var(--bg-hover); }

        /* ─── Benefits List ──────────────────────────────────── */
        .benefits-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .benefit-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 10px;
        }
        .benefit-icon {
            width: 34px; height: 34px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .benefit-icon.gold  { background: rgba(245,158,11,0.12); color: var(--gold); }
        .benefit-icon.blue  { background: rgba(59,130,246,0.12); color: #60a5fa; }
        .benefit-icon.red   { background: rgba(239,68,68,0.1);   color: #f87171; }
        .benefit-icon.green { background: rgba(16,185,129,0.1);  color: #34d399; }
        .benefit-text span  { display: block; font-size: 0.88rem; font-weight: 600; }
        .benefit-text small { font-size: 0.78rem; color: var(--text-muted); line-height: 1.5; }

        /* ─── Plan Selector ──────────────────────────────────── */
        .plan-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .plan-btn {
            background: var(--bg-surface);
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 14px 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.18s;
            position: relative;
        }
        .plan-btn:hover { border-color: rgba(245,158,11,0.4); background: #1a1400; }
        .plan-btn.selected {
            border-color: var(--gold);
            background: linear-gradient(135deg, #1a1400, #1a1600);
            box-shadow: 0 0 0 1px var(--gold-border), 0 4px 20px var(--gold-glow);
        }
        .plan-months { font-size: 0.85rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; }
        .plan-price  { font-size: 1.3rem; font-weight: 800; color: var(--gold); }
        .plan-price small { font-size: 0.7rem; font-weight: 500; color: var(--text-muted); }
        .plan-save {
            position: absolute;
            top: -8px; right: -8px;
            background: var(--green);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            white-space: nowrap;
        }

        /* ─── Payment Info ───────────────────────────────────── */
        .express-box {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .express-header {
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--border);
        }
        .express-logo {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, #ff6b00, #ff8c00);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 900;
            font-size: 0.75rem;
            color: #fff;
            letter-spacing: -0.5px;
            flex-shrink: 0;
        }
        .express-header-text { flex: 1; }
        .express-header-text h4 { font-size: 0.9rem; font-weight: 700; }
        .express-header-text p  { font-size: 0.78rem; color: var(--text-muted); }

        .express-steps {
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .express-step {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .step-num {
            width: 22px; height: 22px; min-width: 22px;
            background: rgba(245,158,11,0.15);
            border: 1px solid var(--gold-border);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; font-weight: 800;
            color: var(--gold);
            margin-top: 1px;
        }
        .step-text {
            font-size: 0.83rem;
            color: var(--text-muted);
            line-height: 1.55;
        }
        .step-text strong { color: var(--text-main); font-weight: 600; }
        .step-text .num-highlight {
            display: inline-block;
            background: rgba(245,158,11,0.12);
            border: 1px solid var(--gold-border);
            border-radius: 6px;
            padding: 1px 8px;
            color: var(--gold-soft);
            font-weight: 700;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        /* ─── Contact Warning ────────────────────────────────── */
        .contact-box {
            background: rgba(59,130,246,0.06);
            border: 1px solid rgba(59,130,246,0.2);
            border-radius: 12px;
            padding: 14px 16px;
        }
        .contact-box h4 {
            font-size: 0.85rem;
            font-weight: 700;
            color: #93c5fd;
            margin-bottom: 10px;
            display: flex; align-items: center; gap: 7px;
        }
        .contact-methods {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .contact-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 9px;
            text-decoration: none;
            color: var(--text-main);
            font-size: 0.83rem;
            transition: all 0.2s;
        }
        .contact-link:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.12); }
        .contact-link i { width: 16px; text-align: center; font-size: 0.9rem; }
        .contact-link .cl-label { flex: 1; }
        .contact-link .cl-sub  { font-size: 0.75rem; color: var(--text-subtle); }

        /* ─── Form (pedido) ──────────────────────────────────── */
        .prem-form { display: flex; flex-direction: column; gap: 12px; }

        .form-field label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }
        .form-field input {
            width: 100%;
            padding: 12px 14px;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-main);
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .form-field input:focus { border-color: rgba(245,158,11,0.5); }
        .form-field input::placeholder { color: var(--text-subtle); }

        .form-summary {
            background: var(--bg-surface);
            border: 1px solid var(--gold-border);
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .form-summary span { font-size: 0.83rem; color: var(--text-muted); }
        .form-summary strong { font-size: 1.1rem; color: var(--gold); font-weight: 800; }

        .btn-submit-premium {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #d97706, #f59e0b);
            border: none;
            border-radius: 12px;
            color: #000;
            font-size: 0.95rem;
            font-weight: 800;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 20px rgba(245,158,11,0.25);
        }
        .btn-submit-premium:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 25px rgba(245,158,11,0.35); }
        .btn-submit-premium:active { transform: scale(0.99); }
        .btn-submit-premium:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ─── Feedback messages ──────────────────────────────── */
        .msg-error {
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.25);
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 0.83rem;
            color: #f87171;
            display: none;
        }
        .msg-success {
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.25);
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 0.83rem;
            color: #34d399;
            display: none;
        }

        /* ─── Pending notice ─────────────────────────────────── */
        .pending-notice {
            background: rgba(59,130,246,0.08);
            border: 1px solid rgba(59,130,246,0.2);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .pending-notice i { color: #60a5fa; font-size: 1.1rem; margin-top: 1px; flex-shrink: 0; }
        .pending-notice-text h4 { font-size: 0.88rem; font-weight: 700; color: #93c5fd; margin-bottom: 3px; }
        .pending-notice-text p  { font-size: 0.8rem; color: var(--text-muted); line-height: 1.5; }

        @media (max-width: 420px) {
            .plan-grid { grid-template-columns: 1fr 1fr; }
            .plan-price { font-size: 1.1rem; }
        }
    </style>
</head>
<body>

<header class="prem-header">
    <a href="settings.php" class="prem-back"><i class="fas fa-arrow-left"></i></a>
    <div class="prem-header-title">
        <span class="crown">👑</span>
        <span>MyTube Premium</span>
    </div>
</header>

<main class="prem-main">

    <?php if ($is_premium): ?>
    <!-- ── Estado: Premium Ativo ──────────────────────────── -->
    <div class="status-card is-premium">
        <div class="status-icon gold">👑</div>
        <div class="status-info">
            <div class="status-badge badge-gold"><i class="fas fa-check-circle"></i> Premium Ativo</div>
            <h3>A tua conta é Premium</h3>
            <p>
                <?php if (!empty($user['premium_expires'])): ?>
                    Expira em <?php echo date('d/m/Y', strtotime($user['premium_expires'])); ?>
                    <?php if ($expires_soon): ?> — <span style="color:#f87171">Expira em breve!</span><?php endif; ?>
                <?php else: ?>
                    Acesso vitalício
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if (!empty($user['stream_key'])): ?>
    <!-- Stream Key -->
    <div class="prem-card" style="border-color: var(--gold-border); background: linear-gradient(135deg, #1a1400, #1f1700);">
        <div class="card-title">🎙️ Livestream</div>
        <p style="font-size:0.83rem; color:var(--text-muted); margin-bottom:12px; line-height:1.55;">
            Usa estas credenciais no <strong style="color:var(--text-main)">OBS Studio</strong> ou em qualquer software de transmissão para fazeres live.
        </p>
        <div class="stream-key-box">
            <div class="stream-key-label">Servidor RTMP</div>
            <div class="stream-key-row">
                <span class="stream-key-val" id="rtmpServer">rtmp://live.mytube.social/live</span>
                <button class="stream-key-copy" onclick="copyText('rtmpServer', this)"><i class="fas fa-copy"></i></button>
            </div>
        </div>
        <div class="stream-key-box" style="margin-top:8px;">
            <div class="stream-key-label">Stream Key (Secreta)</div>
            <div class="stream-key-row">
                <span class="stream-key-val" id="streamKey" data-real="<?php echo htmlspecialchars($user['stream_key']); ?>">••••••••••••••••</span>
                <button class="stream-key-copy" onclick="toggleStreamKey(this)" id="toggleKeyBtn"><i class="fas fa-eye"></i></button>
                <button class="stream-key-copy" onclick="copyText('streamKey', this)"><i class="fas fa-copy"></i></button>
            </div>
        </div>
        <p style="font-size:0.75rem; color:var(--text-subtle); margin-top:10px;">
            <i class="fas fa-lock" style="margin-right:4px;"></i> Nunca partilhes a tua Stream Key com ninguém.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($expires_soon): ?>
    <!-- Renovação -->
    <div class="prem-card">
        <div class="card-title">⚠️ Renovar Premium</div>
        <p style="font-size:0.83rem; color:var(--text-muted); margin-bottom:16px; line-height:1.5;">
            O teu Premium expira em breve. Faz a renovação antes de perder o acesso.
        </p>
        <!-- Mostrar formulário de renovação -->
        <?php $pending_request = false; // Permitir renovação quando quase a expirar ?>
        <!-- Incluir secção de pagamento abaixo -->
    </div>
    <?php endif; ?>

    <?php elseif ($pending_request): ?>
    <!-- ── Estado: Pedido Pendente ────────────────────────── -->
    <div class="status-card has-pending">
        <div class="status-icon blue">⏳</div>
        <div class="status-info">
            <div class="status-badge badge-blue"><i class="fas fa-clock"></i> Em Análise</div>
            <h3>Pedido Submetido</h3>
            <p>Recebemos o teu pedido de <?php echo $pending_request['plan_months']; ?> mês(es). Vamos verificar o pagamento e ativar em até 24h.</p>
        </div>
    </div>

    <div class="pending-notice">
        <i class="fas fa-info-circle"></i>
        <div class="pending-notice-text">
            <h4>O que acontece a seguir?</h4>
            <p>Assim que confirmarmos a transferência Express, ativamos a tua conta Premium e recebes uma notificação. Pedido feito em <?php echo date('d/m/Y H:i', strtotime($pending_request['created_at'])); ?>.</p>
        </div>
    </div>

    <?php elseif ($is_expired): ?>
    <!-- ── Estado: Expirado ───────────────────────────────── -->
    <div class="status-card not-premium" style="border-color:rgba(239,68,68,0.25);">
        <div class="status-icon" style="background:rgba(239,68,68,0.1);">⚠️</div>
        <div class="status-info">
            <div class="status-badge badge-red"><i class="fas fa-times-circle"></i> Expirado</div>
            <h3>Premium Expirado</h3>
            <p>O teu acesso Premium expirou em <?php echo date('d/m/Y', strtotime($user['premium_expires'])); ?>. Renova para recuperar os benefícios.</p>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Estado: Não Premium ────────────────────────────── -->
    <div class="status-card not-premium">
        <div class="status-icon grey">👤</div>
        <div class="status-info">
            <div class="status-badge badge-grey">Conta Gratuita</div>
            <h3>Sem Premium</h3>
            <p>Subscreve para desbloquear funcionalidades exclusivas.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Benefícios (sempre visível) ──────────────────────── -->
    <div class="prem-card">
        <div class="card-title">O que inclui</div>
        <div class="benefits-list">
            <div class="benefit-row">
                <div class="benefit-icon gold"><i class="fas fa-camera"></i></div>
                <div class="benefit-text">
                    <span>Foto ao lado do nome</span>
                    <small>Poderás colocar uma foto que aparece ao lado do teu nome no teu perfil.</small>
                </div>
            </div>
            <div class="benefit-row">
                <div class="benefit-icon red"><i class="fas fa-tower-broadcast"></i></div>
                <div class="benefit-text">
                    <span>Livestreams</span>
                    <small>Faz transmissões ao vivo diretamente da tua conta, em tempo real.</small>
                </div>
            </div>
            <div class="benefit-row">
                <div class="benefit-icon blue"><i class="fas fa-crown"></i></div>
                <div class="benefit-text">
                    <span>Crachá Premium</span>
                    <small>Identificação visual que destaca o teu perfil para os outros utilizadores.</small>
                </div>
            </div>
            <div class="benefit-row">
                <div class="benefit-icon green"><i class="fas fa-headset"></i></div>
                <div class="benefit-text">
                    <span>Suporte prioritário</span>
                    <small>Acesso a suporte direto para resolver qualquer problema mais rápido.</small>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$is_premium || $expires_soon): ?>
    <?php if (!$pending_request): ?>

    <!-- ── Escolha de Plano ───────────────────────────────── -->
    <div class="prem-card">
        <div class="card-title">Escolhe o Plano</div>
        <div class="plan-grid">
            <?php foreach ($plans as $p): ?>
            <button
                class="plan-btn <?php echo $p['months'] === 1 ? 'selected' : ''; ?>"
                data-months="<?php echo $p['months']; ?>"
                data-price="<?php echo $p['price']; ?>"
                onclick="selectPlan(this)"
                id="plan-<?php echo $p['months']; ?>"
            >
                <?php if ($p['save']): ?>
                <span class="plan-save">−<?php echo $p['save']; ?></span>
                <?php endif; ?>
                <div class="plan-months"><?php echo $p['label']; ?></div>
                <div class="plan-price"><?php echo number_format($p['price'], 0, ',', '.'); ?> <small>AOA</small></div>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Como Pagar (Express) ──────────────────────────── -->
    <div class="prem-card">
        <div class="card-title">Como Pagar</div>
        <div class="express-box">
            <div class="express-header">
                <div class="express-logo">EXP</div>
                <div class="express-header-text">
                    <h4>Transferência Express</h4>
                    <p>Método de pagamento aceite</p>
                </div>
            </div>
            <div class="express-steps">
                <div class="express-step">
                    <div class="step-num">1</div>
                    <div class="step-text">
                        Abre o app <strong>Express</strong> e faz uma transferência para o número: <br>
                        <span class="num-highlight"><?php echo EXPRESS_NUMBER; ?></span>
                        <br><span style="font-size:0.75rem; opacity:0.7;">Nome da Conta: Júlio Bemba Mendes António</span>
                    </div>
                </div>
                <div class="express-step">
                    <div class="step-num">2</div>
                    <div class="step-text">
                        No campo de <strong>descrição</strong> escreve o teu <strong>username</strong> ex: <span style="color:var(--text-main); font-weight:600;">@<?php echo htmlspecialchars($user['username']); ?></span>
                    </div>
                </div>
                <div class="express-step">
                    <div class="step-num">3</div>
                    <div class="step-text">
                        Guarda o comprovativo e <strong>envia no formulário abaixo</strong>.
                    </div>
                </div>
                <div class="express-step">
                    <div class="step-num">4</div>
                    <div class="step-text">
                        Ativamos o teu Premium em <strong>até 24 horas</strong> após confirmarmos o pagamento.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Como nos Contactar ────────────────────────────── -->
    <div class="contact-box">
        <h4><i class="fas fa-shield-halved"></i> Tens dúvidas ou problemas?</h4>
        <div class="contact-methods">
            <a href="https://wa.me/<?php echo MYTUBE_WHATSAPP; ?>?text=Ol%C3%A1%2C+tenho+uma+d%C3%BAvida+sobre+o+MyTube+Premium" target="_blank" rel="noopener" class="contact-link">
                <i class="fab fa-whatsapp" style="color:#25d366;"></i>
                <div class="cl-label">
                    WhatsApp Oficial
                    <div class="cl-sub">Resposta mais rápida</div>
                </div>
                <i class="fas fa-external-link-alt" style="font-size:0.75rem; color:var(--text-subtle);"></i>
            </a>
            <a href="https://instagram.com/<?php echo MYTUBE_INSTAGRAM; ?>" target="_blank" rel="noopener" class="contact-link">
                <i class="fab fa-instagram" style="color:#e1306c;"></i>
                <div class="cl-label">
                    Instagram @<?php echo MYTUBE_INSTAGRAM; ?>
                    <div class="cl-sub">Conta verificada MyTube</div>
                </div>
                <i class="fas fa-external-link-alt" style="font-size:0.75rem; color:var(--text-subtle);"></i>
            </a>
        </div>
    </div>

    <!-- ── Formulário de Pedido ──────────────────────────── -->
    <div class="prem-card">
        <div class="card-title">Confirmar Pedido</div>

        <div id="msgError" class="msg-error"></div>
        <div id="msgSuccess" class="msg-success"></div>

        <div class="prem-form" id="premiumForm">
            <div class="form-field">
                <label>Nº de Referência Express <span style="color:var(--gold)">*</span></label>
                <input type="text" id="expressRef" placeholder="Ex: REF2024XXXXXXXX" maxlength="100">
            </div>
            <div class="form-field">
                <label>O teu telemóvel (para contacto) <span style="color:var(--gold)">*</span></label>
                <input type="tel" id="userPhone" placeholder="Ex: 912 345 678" maxlength="20">
            </div>
            <div class="form-summary">
                <span>Plano selecionado</span>
                <strong id="summaryPrice">500 AOA / 1 mês</strong>
            </div>
            <button class="btn-submit-premium" id="btnSubmit" onclick="submitPremiumRequest()">
                <i class="fas fa-paper-plane"></i>
                Enviar Pedido
            </button>
            <p style="font-size:0.73rem; color:var(--text-subtle); text-align:center; line-height:1.5;">
                Ao enviar confirmas que fizeste o pagamento via Express para o número 938 913 718.
            </p>
        </div>
    </div>

    <?php endif; ?>
    <?php endif; ?>

</main>

<script>
// ── Plano selecionado ──────────────────────────────────────────────────────
let selectedMonths = 1;
let selectedPrice  = 500;

const planLabels = {
    1:  '500 AOA / 1 mês',
    3:  '1.300 AOA / 3 meses',
    6:  '2.400 AOA / 6 meses',
    12: '4.500 AOA / 1 ano'
};

function selectPlan(btn) {
    document.querySelectorAll('.plan-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedMonths = parseInt(btn.dataset.months);
    selectedPrice  = parseInt(btn.dataset.price);
    const summary = document.getElementById('summaryPrice');
    if (summary) summary.textContent = planLabels[selectedMonths];
}

// ── Stream Key toggle ──────────────────────────────────────────────────────
let keyVisible = false;
function toggleStreamKey(btn) {
    const el   = document.getElementById('streamKey');
    const real = el.dataset.real;
    keyVisible = !keyVisible;
    el.textContent = keyVisible ? real : '••••••••••••••••';
    btn.innerHTML  = keyVisible ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
}

// ── Copy text ──────────────────────────────────────────────────────────────
function copyText(elId, btn) {
    const el   = document.getElementById(elId);
    const text = el.dataset.real || el.textContent;
    if (text.includes('•')) { return; } // Não copiar mascarado
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check" style="color:#10b981"></i>';
        setTimeout(() => btn.innerHTML = orig, 1500);
    }).catch(() => {
        // Fallback para browsers mais antigos
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    });
}

// ── Submit Premium Request ─────────────────────────────────────────────────
async function submitPremiumRequest() {
    const expressRef = document.getElementById('expressRef')?.value.trim();
    const userPhone  = document.getElementById('userPhone')?.value.trim();
    const errEl      = document.getElementById('msgError');
    const sucEl      = document.getElementById('msgSuccess');
    const btn        = document.getElementById('btnSubmit');

    errEl.style.display = 'none';
    sucEl.style.display = 'none';

    if (!expressRef || expressRef.length < 5) {
        errEl.textContent = 'Indica o número de referência do pagamento Express.';
        errEl.style.display = 'block';
        return;
    }
    if (!userPhone) {
        errEl.textContent = 'Indica o teu número de telemóvel.';
        errEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A enviar...';

    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res  = await fetch('api/submit_premium_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ plan_months: selectedMonths, express_ref: expressRef, phone: userPhone })
        });
        const data = await res.json();

        if (data.success) {
            sucEl.textContent   = data.message;
            sucEl.style.display = 'block';
            document.getElementById('premiumForm').style.opacity = '0.4';
            document.getElementById('premiumForm').style.pointerEvents = 'none';
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-check"></i> Pedido Enviado';
        } else {
            errEl.textContent = data.message || 'Ocorreu um erro. Tenta novamente.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Pedido';
        }
    } catch (e) {
        errEl.textContent   = 'Erro de ligação. Verifica a tua conexão e tenta novamente.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Pedido';
    }
}
</script>

</body>
</html>
