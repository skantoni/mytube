<?php
require_once 'includes/config.php';

if (!isLoggedIn()) redirect('login.php');

$user_id = (int)$_SESSION['user_id'];

// ── Carregar planos ──────────────────────────────────────
$plans = $pdo->query("SELECT * FROM ad_plans WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll();

// ── Carregar campanhas do utilizador ────────────────────
$campaigns_stmt = $pdo->prepare("
    SELECT c.*, v.title AS video_title, v.thumbnail_path, p.name AS plan_name_ref
    FROM ad_campaigns c
    INNER JOIN videos v ON v.id = c.video_id
    LEFT JOIN ad_plans p ON p.id = c.plan_id
    WHERE c.user_id = ?
    ORDER BY c.created_at DESC
    LIMIT 50
");
$campaigns_stmt->execute([$user_id]);
$all_campaigns = $campaigns_stmt->fetchAll();

$pending_campaigns = array_filter($all_campaigns, fn($c) => $c['status'] === 'pending');
$active_past_campaigns = array_filter($all_campaigns, fn($c) => $c['status'] !== 'pending');

// ── Stats resumo ─────────────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_campaigns,
        COALESCE(SUM(impressions), 0) AS total_impressions,
        COALESCE(SUM(clicks), 0) AS total_clicks,
        COALESCE(SUM(plan_price_kz), 0) AS total_spent
    FROM ad_campaigns
    WHERE user_id = ? AND status IN ('active','expired')
");
$stats->execute([$user_id]);
$user_stats = $stats->fetch();

$active_count = count(array_filter($all_campaigns, fn($c) => $c['status'] === 'active'));

// Mapear status para texto e classes
$status_labels = [
    'pending'  => ['label' => 'Aguarda Pagamento / Aprovação', 'class' => 'status-pending', 'icon' => 'fa-clock'],
    'active'   => ['label' => 'Ativa', 'class' => 'status-active', 'icon' => 'fa-rocket'],
    'paused'   => ['label' => 'Pausada', 'class' => 'status-paused', 'icon' => 'fa-pause'],
    'expired'  => ['label' => 'Expirada', 'class' => 'status-expired', 'icon' => 'fa-stop'],
    'rejected' => ['label' => 'Rejeitada', 'class' => 'status-rejected', 'icon' => 'fa-xmark'],
];
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <title>MyTube Ads – Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

        :root {
            --bg-base: #060b14;
            --bg-surface: #0d1526;
            --bg-card: #131b2f;
            --bg-hover: #1a243e;
            --border: rgba(255,255,255,0.06);
            --border-hover: rgba(255,255,255,0.12);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-subtle: #64748b;
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --accent-glow: rgba(99,102,241,0.2);
            --gold: #f59e0b;
            --green: #10b981;
            --red: #ef4444;
            --radius-md: 12px;
            --radius-lg: 16px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-base); color: var(--text-main); overflow-x: hidden; display: flex; height: 100vh; }

        /* ─── Dashboard Layout ───────────────────────────── */
        .dash-sidebar { width: 260px; background-color: var(--bg-surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 100; }
        .dash-header { padding: 24px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--border); }
        .dash-back { color: var(--text-muted); text-decoration: none; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: var(--bg-card); transition: all 0.2s; }
        .dash-back:hover { color: var(--text-main); background: var(--bg-hover); }
        .dash-brand { font-weight: 800; font-size: 1.1rem; color: #fff; display: flex; align-items: center; gap: 8px; }
        .dash-brand i { color: var(--accent); }

        .dash-nav { padding: 20px 12px; flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: var(--radius-md); color: var(--text-muted); font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s; position: relative; }
        .nav-item i { font-size: 1.1rem; width: 20px; text-align: center; }
        .nav-item:hover { background: var(--bg-hover); color: var(--text-main); }
        .nav-item.active { background: var(--accent-glow); color: var(--accent); }
        .nav-item.highlight-btn { background: var(--accent); color: #fff; margin-top: 16px; box-shadow: 0 4px 15px var(--accent-glow); justify-content: center; }
        .nav-item.highlight-btn:hover { background: var(--accent-hover); transform: translateY(-1px); }
        
        .nav-badge { margin-left: auto; background: var(--gold); color: #000; font-size: 0.7rem; font-weight: 800; padding: 2px 8px; border-radius: 20px; }

        .dash-main { flex: 1; overflow-y: auto; background: radial-gradient(circle at top right, rgba(99,102,241,0.03), transparent 40%), var(--bg-base); padding: 32px 40px; }
        .dash-topbar { display: none; }

        /* ─── Tabs Content ───────────────────────────────── */
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .page-title { font-size: 1.7rem; font-weight: 800; margin-bottom: 8px; color: #fff; }
        .page-subtitle { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 32px; }

        /* ─── Stats Grid ─────────────────────────────────── */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 40px; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .stat-icon.blue { background: rgba(99,102,241,0.1); color: var(--accent); }
        .stat-icon.green { background: rgba(16,185,129,0.1); color: var(--green); }
        .stat-icon.gold { background: rgba(245,158,11,0.1); color: var(--gold); }
        .stat-info { display: flex; flex-direction: column; }
        .stat-val { font-size: 1.6rem; font-weight: 800; color: #fff; line-height: 1.2; }
        .stat-lbl { font-size: 0.8rem; color: var(--text-subtle); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }

        /* ─── Data Tables/Lists ──────────────────────────── */
        .data-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
        .data-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .data-header h3 { font-size: 1.1rem; font-weight: 700; color: #fff; }
        
        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-state i { font-size: 3rem; color: var(--text-subtle); margin-bottom: 16px; }
        .empty-state h4 { font-size: 1.1rem; color: var(--text-main); margin-bottom: 8px; }
        .empty-state p { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 24px; }
        .btn-create { display:inline-flex; align-items:center; gap:8px; background:var(--accent); color:#fff; padding:12px 24px; border-radius:8px; font-weight:700; text-decoration:none; cursor:pointer; border:none; }
        .btn-create:hover { background:var(--accent-hover); }

        .campaign-item { display: grid; grid-template-columns: 80px 1fr auto; gap: 20px; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border); transition: background 0.2s; }
        .campaign-item:last-child { border-bottom: none; }
        .campaign-item:hover { background: var(--bg-hover); }
        .camp-thumb { width: 80px; height: 50px; border-radius: 8px; object-fit: cover; background: var(--bg-surface); }
        .camp-details { min-width: 0; }
        .camp-title { font-size: 0.95rem; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 6px; }
        .camp-meta { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .camp-meta span { font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
        .camp-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-active { background: rgba(16,185,129,0.1); color: var(--green); }
        .status-pending { background: rgba(245,158,11,0.1); color: var(--gold); }
        .status-paused, .status-expired { background: rgba(148,163,184,0.1); color: var(--text-muted); }
        .status-rejected { background: rgba(239,68,68,0.1); color: var(--red); }
        .metrics-row { display: flex; gap: 16px; }
        .metric { text-align: right; }
        .metric-val { font-size: 0.9rem; font-weight: 700; color: #fff; }
        .metric-lbl { font-size: 0.7rem; color: var(--text-subtle); text-transform: uppercase; }

        /* ─── Plans Grid ─────────────────────────────────── */
        .plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; }
        .plan-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 32px 24px; text-align: center; position: relative; }
        .plan-card.popular { border-color: var(--gold); background: linear-gradient(180deg, rgba(245,158,11,0.05) 0%, var(--bg-card) 100%); }
        .plan-popular-badge { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--gold); color: #000; font-size: 0.75rem; font-weight: 800; padding: 4px 12px; border-radius: 20px; }
        .plan-icon { width: 56px; height: 56px; border-radius: 14px; background: rgba(99,102,241,0.1); color: var(--accent); font-size: 1.5rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .plan-card.popular .plan-icon { background: rgba(245,158,11,0.1); color: var(--gold); }
        .plan-name { font-size: 1.1rem; font-weight: 700; color: #fff; margin-bottom: 8px; }
        .plan-desc { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px; }
        .plan-price { font-size: 2rem; font-weight: 900; color: #fff; margin-bottom: 24px; display: flex; align-items: baseline; justify-content: center; gap: 4px; }
        .plan-price small { font-size: 1rem; color: var(--text-subtle); }

        /* ─── Create Campaign Flow (Tab) ─────────────────── */
        .create-flow { max-width: 900px; margin: 0 auto; }
        .step-indicator { display: flex; gap: 8px; margin-bottom: 32px; }
        .step-dot { flex: 1; height: 6px; background: var(--bg-card); border-radius: 3px; transition: 0.3s; position: relative; }
        .step-dot::after { content: attr(data-label); position: absolute; top: 12px; left: 0; font-size: 0.75rem; font-weight: 600; color: var(--text-subtle); transition: 0.3s; }
        .step-dot.active { background: var(--accent); }
        .step-dot.active::after { color: var(--accent); }
        .step-dot.done { background: var(--green); }
        .step-dot.done::after { color: var(--green); }

        .form-step { display: none; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 32px; animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .form-step.active { display: block; }
        @keyframes slideIn { from{opacity:0; transform:translateX(20px);} to{opacity:1; transform:translateX(0);} }
        
        .source-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 24px; }
        .source-card { background: var(--bg-card); border: 2px solid var(--border); border-radius: var(--radius-md); padding: 32px 24px; text-align: center; cursor: pointer; transition: all 0.2s; text-decoration: none; display: block; color: var(--text-main); }
        .source-card:hover { border-color: var(--accent); background: rgba(99,102,241,0.05); transform: translateY(-2px); }
        .source-card i { font-size: 2.5rem; color: var(--accent); margin-bottom: 16px; display: block; }
        .source-card h3 { font-size: 1.2rem; font-weight: 700; margin-bottom: 8px; }
        .source-card p { font-size: 0.9rem; color: var(--text-muted); }

        /* Video Grid Picker */
        .video-picker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-top: 24px; max-height: 500px; overflow-y: auto; padding-right: 8px; }
        /* Custom scrollbar for grid */
        .video-picker-grid::-webkit-scrollbar { width: 8px; }
        .video-picker-grid::-webkit-scrollbar-track { background: var(--bg-card); border-radius: 4px; }
        .video-picker-grid::-webkit-scrollbar-thumb { background: var(--border-hover); border-radius: 4px; }

        .vid-card { background: var(--bg-card); border: 2px solid transparent; border-radius: 8px; overflow: hidden; cursor: pointer; transition: 0.2s; position: relative; }
        .vid-card:hover { border-color: var(--accent-glow); }
        .vid-card.selected { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent) inset; }
        .vid-card.selected::after { content: '\f058'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; top: 8px; right: 8px; color: var(--accent); font-size: 1.2rem; background: #000; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; }
        .vid-thumb { width: 100%; height: 100px; object-fit: cover; }
        .vid-info { padding: 12px; }
        .vid-title { font-size: 0.85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; }
        .vid-date { font-size: 0.7rem; color: var(--text-subtle); }
        
        .load-more-btn { width: 100%; padding: 14px; background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-muted); border-radius: var(--radius-md); font-weight: 600; cursor: pointer; margin-top: 16px; transition: 0.2s; }
        .load-more-btn:hover { background: var(--bg-hover); color: #fff; border-style: solid; }

        .form-group { margin-bottom: 24px; }
        .form-label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; }
        .form-control { width: 100%; padding: 14px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); color: #fff; font-family: inherit; font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: var(--accent); }
        
        .modal-plan-option { border: 2px solid var(--border); border-radius: var(--radius-md); padding: 20px; margin-bottom: 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: var(--bg-card); transition: 0.2s; }
        .modal-plan-option input { display: none; }
        .modal-plan-option:has(input:checked) { border-color: var(--accent); background: rgba(99,102,241,0.05); }
        .modal-plan-option:hover { border-color: var(--border-hover); }
        
        .review-panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 24px; }
        .review-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
        .review-row:last-child { border-bottom: none; font-weight: 800; font-size: 1.2rem; padding-top: 20px; margin-top: 8px; }
        
        .form-actions { display: flex; gap: 16px; margin-top: 32px; justify-content: space-between; align-items: center; }
        .btn { padding: 14px 24px; border-radius: var(--radius-md); font-weight: 600; font-size: 0.95rem; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-ghost { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }
        .btn-ghost:hover { background: var(--bg-card); color: #fff; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ─── Mobile Responsiveness ──────────────────────── */
        @media (max-width: 768px) {
            body { flex-direction: column; }
            .dash-sidebar { position: fixed; bottom: 0; left: 0; right: 0; width: 100%; height: 65px; flex-direction: row; border-right: none; border-top: 1px solid var(--border); background: rgba(13,21,38,0.95); backdrop-filter: blur(10px); padding: 0; }
            .dash-header { display: none; }
            .dash-nav { flex-direction: row; justify-content: space-around; align-items: center; padding: 0; gap: 0; }
            .nav-item { flex-direction: column; gap: 4px; padding: 10px; border-radius: 0; font-size: 0.65rem; flex: 1; justify-content: center; }
            .nav-item i { font-size: 1.2rem; }
            .nav-item.active { background: transparent; color: var(--accent); border-top: 2px solid var(--accent); }
            .nav-item.highlight-btn { margin:0; background: transparent; color: var(--accent); box-shadow:none; border-top: 2px solid transparent; }
            .nav-item.highlight-btn.active { border-top-color: var(--accent); }
            .nav-badge { position: absolute; top: 4px; right: 25%; padding: 2px 5px; font-size: 0.6rem; }
            
            .dash-topbar { display: flex; align-items: center; justify-content: flex-start; gap: 16px; padding: 16px 20px; background: var(--bg-surface); border-bottom: 1px solid var(--border); }
            .dash-back-mobile { color: var(--text-muted); font-size: 1.2rem; text-decoration: none; display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; background: var(--bg-hover); transition: background 0.2s; }
            .dash-back-mobile:active { background: var(--border); }
            .dash-topbar-title { font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
            .dash-topbar-title i { color: var(--accent); }

            .dash-main { padding: 20px 16px 85px; }
            .campaign-item { grid-template-columns: 1fr; gap: 12px; }
            .camp-thumb { width: 100%; height: 160px; }
            .camp-actions { align-items: flex-start; flex-direction: row; justify-content: space-between; width: 100%; margin-top: 8px; }
            
            .source-cards { grid-template-columns: 1fr; }
            .form-step { padding: 20px; }
            .video-picker-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
            .step-dot::after { display: none; }
            .modal-plan-option { flex-direction: column; text-align: center; gap: 12px; }
        }
        
        #toastContainer { position: fixed; bottom: 80px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; }
        .toast { padding: 14px 20px; background: #fff; color: #000; border-radius: 8px; font-weight: 600; font-size: 0.9rem; box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: toastIn 0.3s; }
        @keyframes toastIn { from{transform:translateY(20px);opacity:0;} to{transform:translateY(0);opacity:1;} }
    </style>
</head>
<body>

    <!-- Mobile Topbar -->
    <div class="dash-topbar">
        <a href="settings.php" class="dash-back-mobile"><i class="fas fa-arrow-left"></i></a>
        <div class="dash-topbar-title"><i class="fas fa-rectangle-ad"></i> MyTube Ads</div>
    </div>

    <!-- Sidebar -->
    <aside class="dash-sidebar">
        <div class="dash-header">
            <a href="settings.php" class="dash-back"><i class="fas fa-arrow-left"></i></a>
            <div class="dash-brand"><i class="fas fa-rectangle-ad"></i> MyTube Ads</div>
        </div>
        
        <div class="dash-nav">
            <div class="nav-item active" data-tab="overview">
                <i class="fas fa-chart-pie"></i>
                <span>Visão Geral</span>
            </div>
            <div class="nav-item" data-tab="campaigns">
                <i class="fas fa-bullhorn"></i>
                <span>Campanhas</span>
            </div>
            <div class="nav-item" data-tab="pending">
                <i class="fas fa-clock"></i>
                <span>Pendentes</span>
                <?php if(count($pending_campaigns) > 0): ?>
                    <span class="nav-badge"><?php echo count($pending_campaigns); ?></span>
                <?php endif; ?>
            </div>
            <div class="nav-item" data-tab="plans">
                <i class="fas fa-box-open"></i>
                <span>Planos</span>
            </div>
            <div class="nav-item highlight-btn" data-tab="create">
                <i class="fas fa-plus"></i>
                <span>Nova Campanha</span>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="dash-main">
        
        <!-- Tab: Overview -->
        <div id="tab-overview" class="tab-content active">
            <h1 class="page-title">Visão Geral</h1>
            <p class="page-subtitle">Acompanha o desempenho global dos teus anúncios.</p>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-bullhorn"></i></div>
                    <div class="stat-info">
                        <span class="stat-val"><?php echo $active_count; ?></span>
                        <span class="stat-lbl">Campanhas Ativas</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-eye"></i></div>
                    <div class="stat-info">
                        <span class="stat-val"><?php echo number_format($user_stats['total_impressions'] ?? 0); ?></span>
                        <span class="stat-lbl">Total Impressões</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon gold"><i class="fas fa-wallet"></i></div>
                    <div class="stat-info">
                        <span class="stat-val"><?php echo number_format($user_stats['total_spent'] ?? 0); ?> <small>Kz</small></span>
                        <span class="stat-lbl">Investimento</span>
                    </div>
                </div>
            </div>

            <div class="data-card">
                <div class="data-header">
                    <h3>Desempenho Recente</h3>
                </div>
                <?php if (empty($active_past_campaigns)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <h4>Sem dados suficientes</h4>
                        <p>Cria a tua primeira campanha para veres as métricas aqui.</p>
                        <button class="btn-create" onclick="openTab('create')">Criar Campanha</button>
                    </div>
                <?php else: ?>
                    <div class="campaign-list">
                        <?php foreach (array_slice($active_past_campaigns, 0, 3) as $camp): 
                            $status = $status_labels[$camp['status']];
                        ?>
                            <div class="campaign-item">
                                <img src="<?php echo $camp['thumbnail_path'] ? 'uploads/thumbnails/'.$camp['thumbnail_path'] : 'assets/images/logo_icon.png'; ?>" class="camp-thumb" style="object-fit: <?php echo $camp['thumbnail_path'] ? 'cover' : 'contain'; ?>; background: var(--bg-surface);">
                                <div class="camp-details">
                                    <div class="camp-title"><?php echo htmlspecialchars($camp['video_title']); ?></div>
                                    <div class="camp-meta">
                                        <span><i class="fas fa-box"></i> <?php echo htmlspecialchars($camp['plan_name']); ?></span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($camp['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="camp-actions">
                                    <span class="status-badge <?php echo $status['class']; ?>"><i class="fas <?php echo $status['icon']; ?>"></i> <?php echo $status['label']; ?></span>
                                    <div class="metrics-row">
                                        <div class="metric"><div class="metric-val"><?php echo number_format($camp['impressions']); ?></div><div class="metric-lbl">Views Ads</div></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Campanhas -->
        <div id="tab-campaigns" class="tab-content">
            <h1 class="page-title">Minhas Campanhas</h1>
            <p class="page-subtitle">Histórico de todos os teus anúncios ativos e terminados.</p>
            <div class="data-card">
                <?php if (empty($active_past_campaigns)): ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h4>Nenhuma campanha</h4>
                        <p>Ainda não tens campanhas ativas ou anteriores.</p>
                    </div>
                <?php else: ?>
                    <div class="campaign-list">
                        <?php foreach ($active_past_campaigns as $camp): 
                            $status = $status_labels[$camp['status']];
                        ?>
                            <div class="campaign-item">
                                <img src="<?php echo $camp['thumbnail_path'] ? 'uploads/thumbnails/'.$camp['thumbnail_path'] : 'assets/images/logo_icon.png'; ?>" class="camp-thumb" style="object-fit: <?php echo $camp['thumbnail_path'] ? 'cover' : 'contain'; ?>; background: var(--bg-surface);">
                                <div class="camp-details">
                                    <div class="camp-title"><?php echo htmlspecialchars($camp['video_title']); ?></div>
                                    <div class="camp-meta">
                                        <span><i class="fas fa-box"></i> <?php echo htmlspecialchars($camp['plan_name']); ?> (<?php echo $camp['plan_days']; ?> dias)</span>
                                        <span><i class="fas fa-bullseye"></i> <?php echo $camp['target_gender'] === 'all' ? 'Todos' : ($camp['target_gender'] === 'male' ? 'Homens' : 'Mulheres'); ?></span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($camp['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="camp-actions">
                                    <span class="status-badge <?php echo $status['class']; ?>"><i class="fas <?php echo $status['icon']; ?>"></i> <?php echo $status['label']; ?></span>
                                    <div class="metrics-row">
                                        <div class="metric"><div class="metric-val"><?php echo number_format($camp['impressions']); ?></div><div class="metric-lbl">Views Ads</div></div>
                                        <div class="metric"><div class="metric-val"><?php echo number_format($camp['plan_price_kz']); ?> Kz</div><div class="metric-lbl">Custo</div></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Pendentes -->
        <div id="tab-pending" class="tab-content">
            <h1 class="page-title">Campanhas Pendentes</h1>
            <p class="page-subtitle">A aguardar confirmação de pagamento ou aprovação.</p>
            <div class="data-card">
                <?php if (empty($pending_campaigns)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle" style="color:var(--green)"></i>
                        <h4>Tudo em dia!</h4>
                        <p>Não tens nenhuma campanha pendente de aprovação neste momento.</p>
                    </div>
                <?php else: ?>
                    <div class="campaign-list">
                        <?php foreach ($pending_campaigns as $camp): ?>
                            <div class="campaign-item">
                                <img src="<?php echo $camp['thumbnail_path'] ? 'uploads/thumbnails/'.$camp['thumbnail_path'] : 'assets/images/logo_icon.png'; ?>" class="camp-thumb" style="object-fit: <?php echo $camp['thumbnail_path'] ? 'cover' : 'contain'; ?>; background: var(--bg-surface);">
                                <div class="camp-details">
                                    <div class="camp-title"><?php echo htmlspecialchars($camp['video_title']); ?></div>
                                    <div class="camp-meta">
                                        <span><i class="fas fa-box"></i> <?php echo htmlspecialchars($camp['plan_name']); ?> (<?php echo $camp['plan_days']; ?> dias)</span>
                                        <span><i class="fas fa-calendar"></i> Submetido a <?php echo date('d/m/Y H:i', strtotime($camp['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="camp-actions">
                                    <span class="status-badge status-pending"><i class="fas fa-clock"></i> Aguarda Aprovação</span>
                                    <div class="metrics-row">
                                        <div class="metric"><div class="metric-val" style="color:var(--gold)"><?php echo number_format($camp['plan_price_kz']); ?> Kz</div><div class="metric-lbl">A Pagar</div></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="padding: 20px; background: rgba(245,158,11,0.05); border-top: 1px solid var(--border); font-size: 0.85rem; color: var(--text-muted);">
                        <i class="fas fa-info-circle" style="color:var(--gold)"></i> Vc será notificado para realizar o pagamento e após confirmação, a campanha passará a ativa. O processo de aprovação pode levar até 24 horas úteis.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Planos -->
        <div id="tab-plans" class="tab-content">
            <h1 class="page-title">Planos de Patrocínio</h1>
            <p class="page-subtitle">Escolhe a opção ideal para impulsionar o teu conteúdo.</p>
            <div class="plans-grid">
                <?php foreach ($plans as $index => $plan): $isPopular = ($index === 1); ?>
                    <div class="plan-card <?php echo $isPopular ? 'popular' : ''; ?>">
                        <?php if($isPopular): ?><div class="plan-popular-badge">MAIS POPULAR</div><?php endif; ?>
                        <div class="plan-icon"><i class="fas <?php echo $index === 0 ? 'fa-seedling' : ($index === 1 ? 'fa-fire' : 'fa-crown'); ?>"></i></div>
                        <h3 class="plan-name"><?php echo htmlspecialchars($plan['name']); ?></h3>
                        <p class="plan-desc"><?php echo htmlspecialchars($plan['description']); ?></p>
                        <div class="plan-price"><?php echo number_format($plan['price_kz']); ?> <small>Kz</small></div>
                        <div style="font-weight: 700; color: #fff; margin-bottom: 24px; font-size: 1.1rem;"><?php echo $plan['days']; ?> dias de destaque</div>
                        <button class="btn btn-primary" style="width:100%; justify-content:center;" onclick="startCampaignWithPlan(<?php echo $plan['id']; ?>)">Subscrever</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tab: CRIAR CAMPANHA (Novo Fluxo em Ecrã Inteiro) -->
        <div id="tab-create" class="tab-content">
            <div class="create-flow">
                <h1 class="page-title">Nova Campanha</h1>
                <p class="page-subtitle">Configura o teu anúncio passo-a-passo.</p>

                <div class="step-indicator">
                    <div class="step-dot active" id="dot1" data-label="Fonte do Vídeo"></div>
                    <div class="step-dot" id="dot2" data-label="Plano"></div>
                    <div class="step-dot" id="dot3" data-label="Público & Revisão"></div>
                </div>

                <form id="campaignForm">
                    <!-- Esconder o input do vídeo para manipular via JS -->
                    <input type="hidden" name="video_id" id="hidden_video_id" value="">

                    <!-- Step 1: Source -->
                    <div class="form-step active" id="step1">
                        
                        <div id="sourceSelection">
                            <h3 style="font-size: 1.2rem; margin-bottom: 8px;">Qual vídeo queres promover?</h3>
                            <p style="color: var(--text-muted); font-size: 0.95rem;">Podes carregar um novo vídeo diretamente para anúncio, ou impulsionar um vídeo que já publicaste no teu perfil.</p>
                            
                            <div class="source-cards">
                                <!-- CARREGAR -->
                                <a href="upload.php?ad_flow=1" class="source-card">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <h3>Carregar Novo</h3>
                                    <p>Faz upload de um vídeo específico para esta campanha.</p>
                                </a>
                                <!-- IMPULSIONAR -->
                                <div class="source-card" onclick="showVideoPicker()">
                                    <i class="fas fa-video"></i>
                                    <h3>Impulsionar Existente</h3>
                                    <p>Escolhe um vídeo que já publicaste no teu perfil.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Video Picker (Hidden initially) -->
                        <div id="videoPickerSection" style="display: none;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                                <h3 style="font-size: 1.1rem;">Seleciona um Vídeo</h3>
                                <button type="button" class="btn btn-ghost" style="padding: 8px 16px; font-size: 0.85rem;" onclick="hideVideoPicker()"><i class="fas fa-arrow-left"></i> Voltar</button>
                            </div>
                            
                            <div class="video-picker-grid" id="videoGrid">
                                <!-- Videos injetados via AJAX -->
                            </div>
                            
                            <button type="button" id="loadMoreVidsBtn" class="load-more-btn" style="display: none;" onclick="loadUserVideos()">
                                Carregar Mais
                            </button>

                            <div class="form-actions" style="margin-top: 24px; justify-content: flex-end;">
                                <button type="button" class="btn btn-primary" onclick="nextStep(2)">Continuar <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>

                    </div>

                    <!-- Step 2: Plan -->
                    <div class="form-step" id="step2">
                        <h3 style="font-size: 1.2rem; margin-bottom: 16px;">Escolhe o teu plano</h3>
                        <?php foreach ($plans as $plan): ?>
                            <label class="modal-plan-option">
                                <div>
                                    <div style="font-weight:700; font-size:1.1rem; color:#fff;"><?php echo htmlspecialchars($plan['name']); ?></div>
                                    <div style="color:var(--text-muted); font-size:0.85rem;"><?php echo $plan['days']; ?> dias de destaque</div>
                                </div>
                                <div style="font-weight:800; color:var(--accent); font-size:1.1rem;">
                                    <?php echo number_format($plan['price_kz']); ?> Kz
                                </div>
                                <input type="radio" name="plan_id" value="<?php echo $plan['id']; ?>" 
                                       data-name="<?php echo htmlspecialchars($plan['name']); ?>" 
                                       data-price="<?php echo $plan['price_kz']; ?>" required>
                            </label>
                        <?php endforeach; ?>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-ghost" onclick="nextStep(1)">Voltar</button>
                            <button type="button" class="btn btn-primary" onclick="nextStep(3)">Continuar <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- Step 3: Audience & Review -->
                    <div class="form-step" id="step3">
                        <div class="form-group">
                            <label class="form-label">Público-alvo (Género)</label>
                            <select name="target_gender" class="form-control">
                                <option value="all">Todos</option>
                                <option value="male">Homens</option>
                                <option value="female">Mulheres</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Localização (Opcional)</label>
                            <input type="text" name="target_location" class="form-control" placeholder="Ex: Luanda, Angola">
                        </div>

                        <div class="review-panel mt-4">
                            <div class="review-row">
                                <span style="color:var(--text-muted)">Plano Selecionado</span>
                                <span id="revPlanName" style="color:#fff; font-weight:600;">-</span>
                            </div>
                            <div class="review-row">
                                <span>Total a pagar</span>
                                <span id="revTotal" style="color:var(--gold); font-size:1.2rem; font-weight:800;">-</span>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-ghost" onclick="nextStep(2)">Voltar</button>
                            <button type="submit" class="btn btn-primary" id="btnSubmitForm"><i class="fas fa-check"></i> Submeter Campanha</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </main>

    <div id="toastContainer"></div>

    <script>
        const userId = <?php echo $user_id; ?>;
        
        // ── Tabs Logic ──
        const navItems = document.querySelectorAll('.nav-item');
        const tabContents = document.querySelectorAll('.tab-content');

        function openTab(tabId) {
            navItems.forEach(n => n.classList.remove('active'));
            document.querySelector(`.nav-item[data-tab="${tabId}"]`).classList.add('active');
            
            tabContents.forEach(tc => tc.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
        }

        navItems.forEach(item => {
            item.addEventListener('click', () => {
                openTab(item.getAttribute('data-tab'));
            });
        });

        // ── Create Flow Logic ──
        let currentStep = 1;
        
        function startCampaignWithPlan(planId) {
            openTab('create');
            const radio = document.querySelector(`input[name="plan_id"][value="${planId}"]`);
            if(radio) radio.checked = true;
            goToStep(1); // Force user to pick a video first
        }

        function nextStep(step) {
            if(step === 2) {
                if(!document.getElementById('hidden_video_id').value) {
                    showToast('Por favor seleciona um vídeo primeiro.', 'error');
                    return;
                }
            }
            if(step === 3) {
                const plan = document.querySelector('input[name="plan_id"]:checked');
                if(!plan) {
                    showToast('Por favor escolhe um plano.', 'error');
                    return;
                }
                document.getElementById('revPlanName').textContent = plan.dataset.name;
                document.getElementById('revTotal').textContent = parseInt(plan.dataset.price).toLocaleString() + ' Kz';
            }
            goToStep(step);
        }

        function goToStep(step) {
            currentStep = step;
            document.querySelectorAll('.form-step').forEach(el => el.classList.remove('active'));
            document.getElementById('step' + step).classList.add('active');
            
            document.querySelectorAll('.step-dot').forEach((el, index) => {
                el.classList.remove('active', 'done');
                if(index + 1 === step) el.classList.add('active');
                if(index + 1 < step) el.classList.add('done');
            });
        }

        // ── Video Picker Logic (AJAX) ──
        let vidPage = 1;
        let isFetchingVids = false;

        function showVideoPicker() {
            document.getElementById('sourceSelection').style.display = 'none';
            document.getElementById('videoPickerSection').style.display = 'block';
            if(vidPage === 1) loadUserVideos();
        }

        function hideVideoPicker() {
            document.getElementById('videoPickerSection').style.display = 'none';
            document.getElementById('sourceSelection').style.display = 'block';
        }

        async function loadUserVideos() {
            if(isFetchingVids) return;
            isFetchingVids = true;
            const btn = document.getElementById('loadMoreVidsBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A carregar...';
            btn.style.display = 'block';

            try {
                const res = await fetch(`api/get_user_videos.php?user_id=${userId}&page=${vidPage}&limit=12`);
                const data = await res.json();
                
                if(data.success) {
                    const grid = document.getElementById('videoGrid');
                    data.videos.forEach(v => {
                        const mediaHtml = v.thumbnail_path 
                            ? `<img src="uploads/thumbnails/${v.thumbnail_path}" class="vid-thumb">`
                            : `<video muted preload="metadata" src="${v.video_url}" onloadeddata="this.currentTime = 0.5;" class="vid-thumb" style="object-fit: cover;"></video>`;

                        const shortTitle = v.title.length > 35 ? v.title.substring(0,35) + '...' : v.title;
                        
                        const div = document.createElement('div');
                        div.className = 'vid-card';
                        div.dataset.id = v.id;
                        div.onclick = function() { selectVideo(this, v.id); };
                        div.innerHTML = `
                            ${mediaHtml}
                            <div class="vid-info">
                                <div class="vid-title" title="${v.title}">${shortTitle}</div>
                                <div class="vid-date">${v.date_label} &bull; <i class="fas fa-eye"></i> ${v.views_count}</div>
                            </div>
                        `;
                        grid.appendChild(div);
                    });

                    if(data.has_more) {
                        vidPage++;
                        btn.innerHTML = 'Carregar Mais';
                    } else {
                        btn.style.display = 'none';
                    }
                } else {
                    btn.innerHTML = 'Erro ao carregar';
                }
            } catch(e) {
                btn.innerHTML = 'Falha na rede';
            }
            isFetchingVids = false;
        }

        function selectVideo(element, id) {
            document.querySelectorAll('.vid-card').forEach(el => el.classList.remove('selected'));
            element.classList.add('selected');
            document.getElementById('hidden_video_id').value = id;
        }

        // ── Auto-Select Logic from URL ──
        // If user comes back from upload.php?ad_flow=1 with ?new_video_id=XXX
        const urlParams = new URLSearchParams(window.location.search);
        const newVideoId = urlParams.get('new_video_id');
        if(newVideoId) {
            // Force open create tab and set video
            openTab('create');
            document.getElementById('hidden_video_id').value = newVideoId;
            showToast('Vídeo carregado com sucesso. Escolhe agora o plano!', 'success');
            // Go to step 2 directly
            goToStep(2);
            // Clean URL so it doesn't trigger again on refresh
            window.history.replaceState({}, document.title, "anuncios.php");
        }

        // ── Form Submission ──
        document.getElementById('campaignForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitForm');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A processar...';

            const formData = new FormData(this);

            try {
                const res = await fetch('api/create_ad_campaign.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                
                if(data.success) {
                    showToast('Campanha submetida com sucesso!', 'success');
                    setTimeout(() => window.location.href = 'anuncios.php', 1500);
                } else {
                    showToast(data.error || 'Ocorreu um erro.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Submeter Campanha';
                }
            } catch(err) {
                showToast('Erro de rede.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Submeter Campanha';
            }
        });

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.style.background = type === 'success' ? '#10b981' : '#ef4444';
            toast.style.color = '#fff';
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'}"></i> ${msg}`;
            container.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 4000);
        }
    </script>
</body>
</html>
