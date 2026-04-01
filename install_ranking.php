<?php
/**
 * Script de instalação do sistema de Rankings
 * Executa as migrações necessárias no banco de dados
 */
require_once 'includes/config.php';

echo "<h2>🏆 Instalação do Sistema de Rankings</h2>";
echo "<pre>";

try {
    // 1. Criar tabela de escolas
    echo "Criando tabela 'schools'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schools` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(200) NOT NULL,
            `short_name` varchar(50) DEFAULT NULL,
            `logo_path` varchar(255) DEFAULT NULL,
            `city` varchar(100) DEFAULT 'Luanda',
            `province` varchar(100) DEFAULT 'Luanda',
            `total_points` int(11) DEFAULT 0,
            `total_students` int(11) DEFAULT 0,
            `total_videos` int(11) DEFAULT 0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_school_name` (`name`),
            KEY `idx_total_points` (`total_points` DESC),
            KEY `idx_city` (`city`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Tabela 'schools' criada!\n";

    // 2. Adicionar campo school_id na tabela users
    echo "Adicionando campo 'school_id' na tabela 'users'...\n";
    
    // Verificar se a coluna já existe
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'school_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `school_id` int(11) DEFAULT NULL AFTER `is_verified`");
        $pdo->exec("ALTER TABLE `users` ADD KEY `idx_school_id` (`school_id`)");
        echo "✅ Campo 'school_id' adicionado!\n";
    } else {
        echo "⏭ Campo 'school_id' já existe.\n";
    }

    // 3. Adicionar campo ranking_points na tabela users
    echo "Adicionando campo 'ranking_points' na tabela 'users'...\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'ranking_points'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `ranking_points` int(11) DEFAULT 0 AFTER `school_id`");
        $pdo->exec("ALTER TABLE `users` ADD KEY `idx_ranking_points` (`ranking_points` DESC)");
        echo "✅ Campo 'ranking_points' adicionado!\n";
    } else {
        echo "⏭ Campo 'ranking_points' já existe.\n";
    }

    // 4. Criar tabela school_weekly_stats para Escola Dominante da Semana
    echo "Criando tabela 'school_weekly_stats'...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `school_weekly_stats` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `school_id` int(11) NOT NULL,
            `week_start` date NOT NULL,
            `week_end` date NOT NULL,
            `points` int(11) DEFAULT 0,
            `videos_count` int(11) DEFAULT 0,
            `views_count` int(11) DEFAULT 0,
            `likes_count` int(11) DEFAULT 0,
            `new_creators` int(11) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_school_week` (`school_id`, `week_start`),
            KEY `idx_week_points` (`week_start`, `points` DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Tabela 'school_weekly_stats' criada!\n";

    // 5. Inserir escolas de exemplo de Luanda
    echo "\nInserindo escolas de Luanda...\n";
    
    $schools = [
        ['Colégio Angolano de Talatona', 'CAT', 'Luanda', 'Luanda'],
        ['Escola Portuguesa de Luanda', 'EPL', 'Luanda', 'Luanda'],
        ['Colégio São Francisco de Assis', 'CSFA', 'Luanda', 'Luanda'],
        ['Escola Internacional de Luanda', 'EIL', 'Luanda', 'Luanda'],
        ['Colégio Pitaval', 'CP', 'Luanda', 'Luanda'],
        ['Externato Rainha Santa Isabel', 'ERSI', 'Luanda', 'Luanda'],
        ['Colégio Madre Luísa Mafo', 'CMLM', 'Luanda', 'Luanda'],
        ['Escola Secundária do Alvalade', 'ESA', 'Luanda', 'Luanda'],
        ['Escola Mutu Ya Kevela', 'EMYK', 'Luanda', 'Luanda'],
        ['Colégio Dom Bosco', 'CDB', 'Luanda', 'Luanda'],
        ['Colégio ABC', 'CABC', 'Luanda', 'Luanda'],
        ['Escola Secundária da Maianga', 'ESM', 'Luanda', 'Luanda'],
        ['Instituto Médio Industrial de Luanda', 'IMIL', 'Luanda', 'Luanda'],
        ['Complexo Escolar Privado Internacional', 'CEPI', 'Luanda', 'Luanda'],
        ['Escola Horizonte', 'EH', 'Luanda', 'Luanda'],
    ];

    $insertSchool = $pdo->prepare("
        INSERT IGNORE INTO schools (name, short_name, city, province) VALUES (?, ?, ?, ?)
    ");

    foreach ($schools as $school) {
        $insertSchool->execute($school);
    }
    echo "✅ " . count($schools) . " escolas inseridas!\n";

    // 6. Recalcular pontos dos usuários existentes
    echo "\nRecalculando pontos dos usuários existentes...\n";
    $pdo->exec("
        UPDATE users u SET ranking_points = (
            SELECT COALESCE(
                (SELECT COUNT(*) FROM videos WHERE user_id = u.id AND is_public = 1) * 10 +
                (SELECT COALESCE(SUM(likes_count), 0) FROM videos WHERE user_id = u.id AND is_public = 1) * 2 +
                (SELECT COALESCE(SUM(comments_count), 0) FROM videos WHERE user_id = u.id AND is_public = 1) * 3 +
                (SELECT COALESCE(SUM(views_count), 0) FROM videos WHERE user_id = u.id AND is_public = 1) * 1
            , 0)
        )
    ");
    echo "✅ Pontos recalculados!\n";

    echo "\n========================================\n";
    echo "🎉 Sistema de Rankings instalado com sucesso!\n";
    echo "========================================\n";
    echo "\n⚠️ Lembre-se: Os alunos precisam selecionar suas escolas no perfil.\n";
    echo "Acesse: ranking.php para ver o sistema de rankings.\n";

} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
