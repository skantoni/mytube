<?php
/**
 * Instalador da tabela boost_impressions
 * Regista cada impressão de vídeo boosted para métricas de CTR e cap diário
 * 
 * Acede a: install_boost_tracking.php
 */
require_once 'includes/config.php';

if (!isLoggedIn() || !isAdminUser()) {
    die('Acesso negado');
}

try {
    // Tabela de impressões de boost (tracking de CTR)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS boost_impressions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            video_id INT NOT NULL,
            user_id INT NOT NULL,
            impression_date DATE NOT NULL,
            impressions INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_daily (video_id, user_id, impression_date),
            INDEX idx_video_date (video_id, impression_date),
            INDEX idx_user_date (user_id, impression_date),
            INDEX idx_date (impression_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Tabela de cliques em boost (para calcular CTR)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS boost_clicks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            video_id INT NOT NULL,
            user_id INT NOT NULL,
            click_type ENUM('view', 'like', 'comment', 'share') DEFAULT 'view',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_video (video_id),
            INDEX idx_video_date (video_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "<h2>✅ Tabelas criadas com sucesso!</h2>";
    echo "<p><strong>boost_impressions</strong> — regista impressões diárias por user/vídeo (para cap diário + métricas)</p>";
    echo "<p><strong>boost_clicks</strong> — regista cliques/interações em vídeos boosted (para CTR)</p>";
    echo "<br><a href='boosted_videos.php'>← Voltar ao painel</a>";

} catch (Exception $e) {
    echo "<h2>❌ Erro</h2><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
