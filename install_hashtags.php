<?php
require_once 'includes/config.php';
require_once 'includes/hashtag_helper.php';

echo "<h2>#️⃣ Instalação do Sistema de Hashtags</h2>";
echo "<pre>";

try {
    echo "[1/5] Garantindo tabela schools...\n";
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS `schools` (\n            `id` int(11) NOT NULL AUTO_INCREMENT,\n            `name` varchar(200) NOT NULL,\n            `short_name` varchar(50) DEFAULT NULL,\n            `logo_path` varchar(255) DEFAULT NULL,\n            `city` varchar(100) DEFAULT 'Luanda',\n            `province` varchar(100) DEFAULT 'Luanda',\n            `total_points` int(11) DEFAULT 0,\n            `total_students` int(11) DEFAULT 0,\n            `total_videos` int(11) DEFAULT 0,\n            `is_active` tinyint(1) DEFAULT 1,\n            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),\n            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),\n            PRIMARY KEY (`id`),\n            UNIQUE KEY `uk_school_name` (`name`)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");

    echo "[2/5] Inserindo escolas solicitadas (se não existirem)...\n";
    $required_schools = [
        ['Complexo escolar privado o imperador', 'Imperador'],
        ['Complexo Escolar Privado Betânia Zango - I', 'BetaniaZango1'],
        ['Complexo Escolar Privado Betânia Zango - III', 'BetaniaZango3'],
    ];

    $school_stmt = $pdo->prepare("\n        INSERT INTO schools (name, short_name, city, province)\n        VALUES (?, ?, 'Luanda', 'Luanda')\n        ON DUPLICATE KEY UPDATE\n            short_name = VALUES(short_name),\n            city = VALUES(city),\n            province = VALUES(province),\n            updated_at = NOW()\n    ");

    foreach ($required_schools as $school) {
        $school_stmt->execute([$school[0], $school[1]]);
        echo "   - OK: {$school[0]}\n";
    }

    echo "[3/5] Criando tabelas de hashtags...\n";
    hashtag_ensure_tables($pdo);

    echo "[4/5] Backfill de hashtags existentes em vídeos...\n";
    $videos_with_hashtags = 0;
    $normalized_rows = 0;

    $video_stmt = $pdo->query("SELECT id, hashtags FROM videos");
    $update_video_stmt = $pdo->prepare("UPDATE videos SET hashtags = ? WHERE id = ?");

    while ($video = $video_stmt->fetch(PDO::FETCH_ASSOC)) {
        $video_id = (int)$video['id'];
        $parsed = hashtag_extract_from_storage($video['hashtags'] ?? '');

        if (!empty($parsed)) {
            $videos_with_hashtags++;
            hashtag_sync_video_relations($pdo, $video_id, $parsed);
        }

        $normalized_storage = hashtag_format_for_storage($parsed);
        $current_storage = trim((string)($video['hashtags'] ?? ''));

        if ($normalized_storage !== $current_storage) {
            $update_video_stmt->execute([$normalized_storage, $video_id]);
            $normalized_rows++;
        }
    }

    echo "   - Vídeos com hashtags válidas: {$videos_with_hashtags}\n";
    echo "   - Linhas normalizadas em videos.hashtags: {$normalized_rows}\n";

    echo "[5/5] Semeando hashtags iniciais...\n";
    $manual_seed_tags = [
        'desafioMytube',
        'mytube',
        'angola',
        'luanda',
        'escola',
        'talento',
        'danca',
        'musica',
        'humor',
        'futebol',
        'basquete',
        'estudo',
        'matematica',
        'ciencia',
        'tecnologia',
        'arte',
        'inspiracao',
        'motivacao',
        'criadores',
        'viral',
        'trend',
        'video',
        'desafio',
        'top',
        'campus',
        'turma',
        'professor',
        'aprendizagem',
        'juventude',
        'superacao',
    ];

    $school_tags = [];
    $school_rows_stmt = $pdo->query("SELECT short_name, name FROM schools");
    while ($school_row = $school_rows_stmt->fetch(PDO::FETCH_ASSOC)) {
        $candidate = trim((string)($school_row['short_name'] ?? ''));
        if ($candidate === '') {
            $candidate = trim((string)($school_row['name'] ?? ''));
        }
        if ($candidate !== '') {
            $school_tags[] = $candidate;
        }
    }

    $seed_total = hashtag_seed_bulk($pdo, array_merge($manual_seed_tags, $school_tags));
    echo "   - Hashtags processadas para seed: {$seed_total}\n";

    $all_hashtag_ids = $pdo->query("SELECT id FROM hashtags")->fetchAll(PDO::FETCH_COLUMN);
    hashtag_recalculate_counts($pdo, array_map('intval', $all_hashtag_ids));

    echo "\n✅ Sistema de hashtags instalado com sucesso!\n";
    echo "✅ Escolas solicitadas garantidas no banco.\n";
} catch (Throwable $e) {
    echo "\n❌ Erro na instalação: " . $e->getMessage() . "\n";
}

echo "</pre>";
