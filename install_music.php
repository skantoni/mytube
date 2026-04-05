<?php
/**
 * Migração: Adicionar colunas de música de fundo à tabela videos.
 *
 * Uso: php install_music.php
 */
require_once __DIR__ . '/includes/config.php';

echo "=== Instalação: Música de Fundo ===\n\n";

try {
    // Verificar se as colunas já existem
    $stmt = $pdo->prepare("SHOW COLUMNS FROM videos LIKE 'music_name'");
    $stmt->execute();

    if ($stmt->fetch()) {
        echo "✓ Colunas de música já existem. Nada a fazer.\n";
    } else {
        $pdo->exec("
            ALTER TABLE videos
            ADD COLUMN music_name VARCHAR(255) DEFAULT '' AFTER hashtags,
            ADD COLUMN music_artist VARCHAR(255) DEFAULT '' AFTER music_name
        ");
        echo "✓ Colunas music_name e music_artist adicionadas com sucesso.\n";
    }

    echo "\n=== Instalação concluída! ===\n";
    echo "Configure o JAMENDO_CLIENT_ID em includes/music_config.php\n";
    echo "Obtenha um client_id gratuito em: https://devportal.jamendo.com/\n";
} catch (Throwable $e) {
    echo "✗ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
