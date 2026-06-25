<?php
/**
 * Migration: user_login_history
 * Cria a tabela de histórico de logins e semeia um registo inicial
 * por utilizador com base na data de criação da conta.
 *
 * Executar UMA VEZ: http://localhost/my/install_user_login_history.php
 */
require_once 'includes/config.php';

if (!isAdminUser()) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1>');
}

$steps  = [];
$errors = [];

// ── 1. Criar a tabela ─────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_login_history (
            id           INT          NOT NULL AUTO_INCREMENT,
            user_id      INT          NOT NULL,
            logged_in_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address   VARCHAR(45)  NULL,
            user_agent   VARCHAR(500) NULL,
            PRIMARY KEY (id),
            KEY idx_user_logged (user_id, logged_in_at),
            KEY idx_logged_at   (logged_in_at),
            CONSTRAINT fk_ulh_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $steps[] = '✅ Tabela <code>user_login_history</code> criada (ou já existia).';
} catch (PDOException $e) {
    $errors[] = '❌ Erro ao criar tabela: ' . htmlspecialchars($e->getMessage());
}

// ── 2. Semente: 1 registo por utilizador (usa created_at como "1.º login") ────
try {
    $inserted = $pdo->exec("
        INSERT IGNORE INTO user_login_history (user_id, logged_in_at, ip_address, user_agent)
        SELECT id, created_at, NULL, 'seed:account_created'
        FROM   users
        WHERE  id NOT IN (SELECT DISTINCT user_id FROM user_login_history)
    ");
    $steps[] = "✅ Semente: <strong>{$inserted}</strong> registos iniciais inseridos (created_at de cada conta).";
} catch (PDOException $e) {
    $errors[] = '❌ Erro na semente: ' . htmlspecialchars($e->getMessage());
}

// ── 3. Relatório ──────────────────────────────────────────────────────────────
$total = (int) $pdo->query("SELECT COUNT(*) FROM user_login_history")->fetchColumn();
$users = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_login_history")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Migration — user_login_history</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;max-width:700px;margin:0 auto}
  h1{color:#60a5fa;margin-bottom:24px}
  .step{background:#1e293b;border-radius:8px;padding:14px 18px;margin-bottom:10px;border-left:4px solid #10b981}
  .err {background:#1e293b;border-radius:8px;padding:14px 18px;margin-bottom:10px;border-left:4px solid #ef4444}
  .stats{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:24px}
  .stat{background:#1e293b;border-radius:10px;padding:20px;text-align:center}
  .stat strong{display:block;font-size:2rem;color:#60a5fa}
  .stat span{color:#94a3b8;font-size:.85rem}
  a{color:#60a5fa;text-decoration:none}
</style>
</head>
<body>
<h1>⚙️ Migration: user_login_history</h1>

<?php foreach ($steps  as $s): ?><div class="step"><?= $s ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="err"><?= $e ?></div><?php endforeach; ?>

<div class="stats">
  <div class="stat"><strong><?= number_format($total) ?></strong><span>Registos na tabela</span></div>
  <div class="stat"><strong><?= number_format($users) ?></strong><span>Utilizadores com histórico</span></div>
</div>

<p style="margin-top:28px;color:#64748b">
  ✅ Migração concluída — 
  <a href="boosted_videos.php#users">Ir para Painel Admin → Utilizadores</a>
</p>
</body>
</html>
