<?php
/**
 * Ferramenta de Deduplicação de Escolas
 * 
 * Funciona via CLI e navegador.
 * - Detecta duplicatas por nome normalizado (remove acentos, parênteses, pontuação)
 * - Mostra grupos de duplicatas com contagem de alunos vinculados
 * - Permite escolher qual manter; migra users e stats para a sobrevivente
 * 
 * USO CLI:   php dedupe_schools.php
 * USO WEB:   https://mytube.social/dedupe_schools.php (requer admin)
 */
require_once __DIR__ . '/includes/config.php';

$is_cli = (php_sapi_name() === 'cli');

// Segurança: apenas admin pode acessar via web
if (!$is_cli) {
    if (!isLoggedIn() || !isAdminUser()) {
        http_response_code(403);
        die('Acesso negado. Apenas administradores.');
    }
}

// ── Helpers ──────────────────────────────────────────────

function normalize_name(string $name): string {
    $name = mb_strtolower(trim($name), 'UTF-8');
    // Remover conteúdo entre parênteses: "Universidade Agostinho Neto (UAN)" → "universidade agostinho neto"
    $name = preg_replace('/\s*\([^)]*\)\s*/', ' ', $name);
    // Transliterar acentos
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    // Remover tudo que não é letra ou espaço
    $name = preg_replace('/[^a-z0-9 ]/', '', $name);
    // Colapsar espaços
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name;
}

function levenshtein_utf8(string $a, string $b): int {
    return levenshtein($a, $b);
}

// ── Buscar todas as escolas ──────────────────────────────

$schools = $pdo->query("
    SELECT s.id, s.name, s.short_name, s.city, s.province, s.total_points, s.total_students, s.total_videos,
           (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id) AS real_students
    FROM schools s
    WHERE s.is_active = 1
    ORDER BY s.name
")->fetchAll();

// ── Agrupar duplicatas ──────────────────────────────────

$normalized = [];
foreach ($schools as $s) {
    $key = normalize_name($s['name']);
    $normalized[$key][] = $s;
}

// Fase 1: duplicatas exactas por nome normalizado
$exact_dupes = [];
foreach ($normalized as $key => $group) {
    if (count($group) > 1) {
        $exact_dupes[] = $group;
    }
}

// Fase 2: fuzzy match (levenshtein ≤ 5 entre nomes normalizados diferentes)
$keys = array_keys($normalized);
$fuzzy_dupes = [];
$already_matched = [];

for ($i = 0; $i < count($keys); $i++) {
    if (isset($already_matched[$keys[$i]])) continue;
    for ($j = $i + 1; $j < count($keys); $j++) {
        if (isset($already_matched[$keys[$j]])) continue;
        $dist = levenshtein_utf8($keys[$i], $keys[$j]);
        $max_len = max(strlen($keys[$i]), strlen($keys[$j]));
        // Similaridade ≥ 85% ou distância ≤ 4
        if ($max_len > 0 && ($dist <= 4 || ($dist / $max_len) < 0.15)) {
            $merged = array_merge($normalized[$keys[$i]], $normalized[$keys[$j]]);
            $fuzzy_dupes[] = ['schools' => $merged, 'dist' => $dist, 'a' => $keys[$i], 'b' => $keys[$j]];
            $already_matched[$keys[$i]] = true;
            $already_matched[$keys[$j]] = true;
        }
    }
}

// ── Processar acção POST (merge) ────────────────────────

$action_result = null;
if (!$is_cli && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validar CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $action_result = ['error' => 'Token CSRF inválido.'];
    } elseif ($_POST['action'] === 'merge') {
        $keep_id = (int)$_POST['keep_id'];
        $remove_ids = array_map('intval', $_POST['remove_ids'] ?? []);
        
        if ($keep_id && !empty($remove_ids)) {
            try {
                $pdo->beginTransaction();
                
                $placeholders = implode(',', array_fill(0, count($remove_ids), '?'));
                
                // 1. Migrar users para a escola sobrevivente
                $stmt = $pdo->prepare("UPDATE users SET school_id = ? WHERE school_id IN ($placeholders)");
                $stmt->execute(array_merge([$keep_id], $remove_ids));
                $users_moved = $stmt->rowCount();
                
                // 2. Migrar school_weekly_stats (somar pontos se mesmo week_start)
                foreach ($remove_ids as $rid) {
                    // Verificar se já existe stats para a semana na escola sobrevivente
                    $existing = $pdo->prepare("SELECT week_start FROM school_weekly_stats WHERE school_id = ?");
                    $existing->execute([$keep_id]);
                    $existing_weeks = $existing->fetchAll(PDO::FETCH_COLUMN);
                    
                    $old_stats = $pdo->prepare("SELECT * FROM school_weekly_stats WHERE school_id = ?");
                    $old_stats->execute([$rid]);
                    
                    foreach ($old_stats->fetchAll() as $stat) {
                        if (in_array($stat['week_start'], $existing_weeks)) {
                            // Somar ao existente
                            $pdo->prepare("
                                UPDATE school_weekly_stats 
                                SET points = points + ?, videos_count = videos_count + ?, 
                                    views_count = views_count + ?, likes_count = likes_count + ?,
                                    new_creators = new_creators + ?
                                WHERE school_id = ? AND week_start = ?
                            ")->execute([
                                $stat['points'], $stat['videos_count'], $stat['views_count'],
                                $stat['likes_count'], $stat['new_creators'], $keep_id, $stat['week_start']
                            ]);
                        } else {
                            // Mover para a escola sobrevivente
                            $pdo->prepare("UPDATE school_weekly_stats SET school_id = ? WHERE id = ?")
                                ->execute([$keep_id, $stat['id']]);
                        }
                    }
                    // Limpar stats órfãs
                    $pdo->prepare("DELETE FROM school_weekly_stats WHERE school_id = ?")->execute([$rid]);
                }
                
                // 3. Recalcular totais da escola sobrevivente
                $pdo->prepare("
                    UPDATE schools SET 
                        total_students = (SELECT COUNT(*) FROM users WHERE school_id = ?),
                        total_points = (SELECT COALESCE(SUM(ranking_points), 0) FROM users WHERE school_id = ?),
                        total_videos = (SELECT COALESCE(SUM(
                            (SELECT COUNT(*) FROM videos v WHERE v.user_id = u.id AND v.is_public = 1)
                        ), 0) FROM users u WHERE u.school_id = ?)
                    WHERE id = ?
                ")->execute([$keep_id, $keep_id, $keep_id, $keep_id]);
                
                // 4. Desactivar as escolas removidas (soft delete)
                $stmt = $pdo->prepare("UPDATE schools SET is_active = 0 WHERE id IN ($placeholders)");
                $stmt->execute($remove_ids);
                $schools_removed = $stmt->rowCount();
                
                $pdo->commit();
                
                $action_result = [
                    'success' => "✅ Merge concluído! $users_moved alunos migrados, $schools_removed escolas desactivadas."
                ];
                
                // Recarregar dados
                header("Location: dedupe_schools.php?merged=1&msg=" . urlencode($action_result['success']));
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $action_result = ['error' => '❌ Erro: ' . $e->getMessage()];
            }
        }
    } elseif ($_POST['action'] === 'hard_delete') {
        // Apagar permanentemente escolas já desactivadas
        $delete_ids = array_map('intval', $_POST['delete_ids'] ?? []);
        if (!empty($delete_ids)) {
            $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));
            $pdo->prepare("DELETE FROM schools WHERE id IN ($placeholders) AND is_active = 0")->execute($delete_ids);
            $action_result = ['success' => '✅ ' . count($delete_ids) . ' escolas eliminadas permanentemente.'];
            header("Location: dedupe_schools.php?deleted=1");
            exit;
        }
    }
}

// ── CLI Mode ────────────────────────────────────────────

if ($is_cli) {
    echo "\n🏫 DEDUPLICAÇÃO DE ESCOLAS\n";
    echo str_repeat('=', 60) . "\n\n";
    
    if (empty($exact_dupes) && empty($fuzzy_dupes)) {
        echo "✅ Nenhuma duplicata encontrada!\n";
        exit(0);
    }
    
    $group_num = 0;
    
    if (!empty($exact_dupes)) {
        echo "📌 DUPLICATAS EXACTAS (mesmo nome normalizado):\n\n";
        foreach ($exact_dupes as $group) {
            $group_num++;
            echo "  Grupo $group_num:\n";
            foreach ($group as $s) {
                $students = $s['real_students'] > 0 ? " [{$s['real_students']} alunos]" : "";
                echo "    ID {$s['id']}: {$s['name']} ({$s['short_name']}) - {$s['city']}{$students}\n";
            }
            echo "\n";
        }
    }
    
    if (!empty($fuzzy_dupes)) {
        echo "🔍 POSSÍVEIS DUPLICATAS (nomes similares):\n\n";
        foreach ($fuzzy_dupes as $fd) {
            $group_num++;
            echo "  Grupo $group_num (dist={$fd['dist']}: \"{$fd['a']}\" ≈ \"{$fd['b']}\"):\n";
            foreach ($fd['schools'] as $s) {
                $students = $s['real_students'] > 0 ? " [{$s['real_students']} alunos]" : "";
                echo "    ID {$s['id']}: {$s['name']} ({$s['short_name']}) - {$s['city']}{$students}\n";
            }
            echo "\n";
        }
    }
    
    echo "Total: $group_num grupos de duplicatas encontrados.\n";
    echo "👉 Use a versão web para fazer merge interactivo.\n\n";
    exit(0);
}

// ── Web Mode (HTML) ─────────────────────────────────────
$csrf = csrf_token();
$msg = $_GET['msg'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deduplicação de Escolas - MyTube Admin</title>
<style>
  :root { --bg: #0f1117; --card: #1a1d27; --border: #2a2d3a; --accent: #6366f1; --accent2: #818cf8; --green: #22c55e; --red: #ef4444; --yellow: #f59e0b; --text: #e2e8f0; --muted: #94a3b8; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Inter', -apple-system, sans-serif; background: var(--bg); color: var(--text); padding: 20px; max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 1.6rem; margin-bottom: 8px; }
  .subtitle { color: var(--muted); margin-bottom: 24px; font-size: 0.9rem; }
  .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
  .msg.success { background: rgba(34,197,94,.15); border: 1px solid var(--green); color: var(--green); }
  .msg.error { background: rgba(239,68,68,.15); border: 1px solid var(--red); color: var(--red); }
  .stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
  .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; flex: 1; min-width: 140px; }
  .stat-card .num { font-size: 1.8rem; font-weight: 700; color: var(--accent2); }
  .stat-card .label { font-size: 0.8rem; color: var(--muted); margin-top: 4px; }
  .group { background: var(--card); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 16px; overflow: hidden; }
  .group-header { padding: 14px 18px; background: rgba(99,102,241,.08); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
  .group-header h3 { font-size: 0.95rem; }
  .badge { font-size: 0.7rem; padding: 3px 8px; border-radius: 99px; font-weight: 600; }
  .badge.exact { background: var(--red); color: #fff; }
  .badge.fuzzy { background: var(--yellow); color: #000; }
  .school-row { padding: 12px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; transition: background .15s; }
  .school-row:last-child { border-bottom: none; }
  .school-row:hover { background: rgba(255,255,255,.03); }
  .school-row input[type=radio] { accent-color: var(--green); width: 18px; height: 18px; cursor: pointer; }
  .school-info { flex: 1; }
  .school-name { font-weight: 600; font-size: 0.9rem; }
  .school-meta { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
  .school-students { font-size: 0.8rem; color: var(--accent2); font-weight: 600; white-space: nowrap; }
  .btn { padding: 8px 18px; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all .15s; }
  .btn-merge { background: var(--accent); color: #fff; }
  .btn-merge:hover { background: var(--accent2); }
  .btn-merge:disabled { opacity: .4; cursor: not-allowed; }
  .group-actions { padding: 12px 18px; background: rgba(0,0,0,.2); display: flex; justify-content: flex-end; gap: 8px; }
  .empty { text-align: center; padding: 60px 20px; color: var(--muted); }
  .empty .icon { font-size: 3rem; margin-bottom: 12px; }
  .section-title { font-size: 1.1rem; font-weight: 700; margin: 28px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
  .back-link { color: var(--accent2); text-decoration: none; font-size: 0.85rem; }
  .back-link:hover { text-decoration: underline; }
  @media(max-width:600px) { .school-row { flex-wrap: wrap; } .stats { flex-direction: column; } }
</style>
</head>
<body>

<a href="ranking.php" class="back-link">← Voltar ao Ranking</a>
<h1>🏫 Deduplicação de Escolas</h1>
<p class="subtitle">Encontre e unifique escolas duplicadas no banco de dados. Seleccione qual manter em cada grupo.</p>

<?php if ($msg): ?>
<div class="msg success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($action_result): ?>
<div class="msg <?= isset($action_result['success']) ? 'success' : 'error' ?>">
    <?= htmlspecialchars($action_result['success'] ?? $action_result['error']) ?>
</div>
<?php endif; ?>

<div class="stats">
    <div class="stat-card"><div class="num"><?= count($schools) ?></div><div class="label">Escolas Activas</div></div>
    <div class="stat-card"><div class="num"><?= count($exact_dupes) ?></div><div class="label">Duplicatas Exactas</div></div>
    <div class="stat-card"><div class="num"><?= count($fuzzy_dupes) ?></div><div class="label">Possíveis Duplicatas</div></div>
</div>

<?php if (empty($exact_dupes) && empty($fuzzy_dupes)): ?>
<div class="empty">
    <div class="icon">✅</div>
    <p>Nenhuma duplicata encontrada! A base está limpa.</p>
</div>
<?php else: ?>

<?php if (!empty($exact_dupes)): ?>
<div class="section-title">📌 Duplicatas Exactas</div>
<?php foreach ($exact_dupes as $gi => $group): ?>
<form method="POST" class="group" onsubmit="return confirmMerge(this)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="merge">
    <div class="group-header">
        <h3>Grupo <?= $gi + 1 ?> <span class="badge exact">Exacta</span></h3>
        <span style="color:var(--muted);font-size:.8rem"><?= count($group) ?> registos</span>
    </div>
    <?php foreach ($group as $s): ?>
    <label class="school-row">
        <input type="radio" name="keep_id" value="<?= $s['id'] ?>" required>
        <div class="school-info">
            <div class="school-name">ID <?= $s['id'] ?>: <?= htmlspecialchars($s['name']) ?></div>
            <div class="school-meta"><?= htmlspecialchars($s['short_name']) ?> · <?= htmlspecialchars($s['city']) ?>, <?= htmlspecialchars($s['province']) ?></div>
        </div>
        <div class="school-students"><?= $s['real_students'] ?> alunos</div>
        <input type="hidden" name="all_ids[]" value="<?= $s['id'] ?>">
    </label>
    <?php endforeach; ?>
    <div class="group-actions">
        <button type="submit" class="btn btn-merge">Manter seleccionada, fundir resto</button>
    </div>
</form>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($fuzzy_dupes)): ?>
<div class="section-title">🔍 Possíveis Duplicatas (nomes similares)</div>
<?php foreach ($fuzzy_dupes as $fi => $fd): ?>
<form method="POST" class="group" onsubmit="return confirmMerge(this)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="merge">
    <div class="group-header">
        <h3>Grupo <?= count($exact_dupes) + $fi + 1 ?> <span class="badge fuzzy">Similar (dist=<?= $fd['dist'] ?>)</span></h3>
        <span style="color:var(--muted);font-size:.8rem">"<?= htmlspecialchars($fd['a']) ?>" ≈ "<?= htmlspecialchars($fd['b']) ?>"</span>
    </div>
    <?php foreach ($fd['schools'] as $s): ?>
    <label class="school-row">
        <input type="radio" name="keep_id" value="<?= $s['id'] ?>" required>
        <div class="school-info">
            <div class="school-name">ID <?= $s['id'] ?>: <?= htmlspecialchars($s['name']) ?></div>
            <div class="school-meta"><?= htmlspecialchars($s['short_name']) ?> · <?= htmlspecialchars($s['city']) ?>, <?= htmlspecialchars($s['province']) ?></div>
        </div>
        <div class="school-students"><?= $s['real_students'] ?> alunos</div>
        <input type="hidden" name="all_ids[]" value="<?= $s['id'] ?>">
    </label>
    <?php endforeach; ?>
    <div class="group-actions">
        <button type="submit" class="btn btn-merge">Manter seleccionada, fundir resto</button>
    </div>
</form>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<script>
function confirmMerge(form) {
    const keepId = form.querySelector('input[name="keep_id"]:checked');
    if (!keepId) { alert('Seleccione a escola que deve permanecer.'); return false; }
    
    const allIds = [...form.querySelectorAll('input[name="all_ids[]"]')].map(i => i.value);
    const removeIds = allIds.filter(id => id !== keepId.value);
    
    // Adicionar remove_ids ao form
    removeIds.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'remove_ids[]'; input.value = id;
        form.appendChild(input);
    });
    
    const keepName = keepId.closest('.school-row').querySelector('.school-name').textContent;
    return confirm(`Manter: ${keepName}\nDesactivar ${removeIds.length} escola(s) duplicada(s).\n\nAlunos serão migrados automaticamente.\nContinuar?`);
}
</script>

</body>
</html>
