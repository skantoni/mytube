<?php
/**
 * Migração: cria tabela user_feed_seen
 * Usada pelo algoritmo de feed para deduplição cross-sessão:
 * regista os vídeos que cada utilizador já viu nos últimos 21-30 dias,
 * garantindo que vídeos repetidos não voltem a aparecer em novas sessões.
 *
 * Executar 1× no VPS/XAMPP:
 *   php migrations/add_user_feed_seen.php
 */

require_once __DIR__ . '/../includes/config.php';

echo "=== Migração: user_feed_seen ===\n\n";

try {
    // 1. Criar a tabela se ainda não existir
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_feed_seen (
            user_id   INT UNSIGNED NOT NULL,
            video_id  INT UNSIGNED NOT NULL,
            seen_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, video_id),
            KEY idx_user_seen_at (user_id, seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ Tabela user_feed_seen criada (ou já existia).\n";

    // 2. Confirmar que o índice existe (pode ser criado em passo separado em DBs antigas)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = 'user_feed_seen'
          AND index_name   = 'idx_user_seen_at'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE user_feed_seen ADD KEY idx_user_seen_at (user_id, seen_at)");
        echo "✓ Índice idx_user_seen_at criado.\n";
    } else {
        echo "✓ Índice idx_user_seen_at já existe.\n";
    }

    echo "\n✅ Migração concluída com sucesso.\n";
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
