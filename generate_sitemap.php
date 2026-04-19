<?php
/**
 * Gerador Dinâmico de Sitemap
 * Gera sitemap.xml com URLs públicas do site
 * 
 * Execute periodicamente para manter o sitemap atualizado
 * Uso: php generate_sitemap.php
 */

require_once 'includes/config.php';

$base_url = 'https://www.mytube.social';
$today = date('Y-m-d');

$urls = [];

// 1. Páginas Estáticas
$static_pages = [
    ['loc' => '/', 'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => '/login.php', 'priority' => '0.9', 'changefreq' => 'monthly'],
    ['loc' => '/ranking.php', 'priority' => '0.8', 'changefreq' => 'daily'],
];

foreach ($static_pages as $page) {
    $urls[] = [
        'loc' => $base_url . $page['loc'],
        'lastmod' => $today,
        'changefreq' => $page['changefreq'],
        'priority' => $page['priority']
    ];
}

// 2. Perfis Públicos de Usuários Verificados ou Populares
try {
    $stmt = $pdo->query("
        SELECT id, username, updated_at
        FROM users
        WHERE (is_verified = 1 OR videos_count > 5)
        AND is_private = 0
        ORDER BY videos_count DESC
        LIMIT 100
    ");
    
    while ($user = $stmt->fetch()) {
        $urls[] = [
            'loc' => $base_url . '/profile.php?user=' . urlencode($user['username']),
            'lastmod' => $user['updated_at'] ? date('Y-m-d', strtotime($user['updated_at'])) : $today,
            'changefreq' => 'weekly',
            'priority' => '0.7'
        ];
    }
} catch (Exception $e) {
    error_log("Sitemap: erro ao buscar usuários - " . $e->getMessage());
}

// 3. Vídeos Públicos Populares
try {
    $stmt = $pdo->query("
        SELECT v.id, v.created_at, v.updated_at
        FROM videos v
        WHERE v.views_count > 100
        ORDER BY v.views_count DESC
        LIMIT 200
    ");
    
    while ($video = $stmt->fetch()) {
        $lastmod = $video['updated_at'] ?? $video['created_at'];
        $urls[] = [
            'loc' => $base_url . '/watch.php?id=' . $video['id'],
            'lastmod' => date('Y-m-d', strtotime($lastmod)),
            'changefreq' => 'weekly',
            'priority' => '0.6'
        ];
    }
} catch (Exception $e) {
    error_log("Sitemap: erro ao buscar vídeos - " . $e->getMessage());
}

// 4. Hashtags Populares
try {
    $stmt = $pdo->query("
        SELECT DISTINCT tag
        FROM video_hashtags
        GROUP BY tag
        HAVING COUNT(*) > 5
        ORDER BY COUNT(*) DESC
        LIMIT 50
    ");
    
    while ($row = $stmt->fetch()) {
        $urls[] = [
            'loc' => $base_url . '/explore.php?tag=' . urlencode($row['tag']),
            'lastmod' => $today,
            'changefreq' => 'daily',
            'priority' => '0.5'
        ];
    }
} catch (Exception $e) {
    error_log("Sitemap: erro ao buscar hashtags - " . $e->getMessage());
}

// Gerar XML
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($urls as $url) {
    $xml .= "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
    $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
    $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
    $xml .= "    <priority>{$url['priority']}</priority>\n";
    $xml .= "  </url>\n";
}

$xml .= "</urlset>\n";

// Salvar
$sitemap_file = __DIR__ . '/sitemap.xml';
if (file_put_contents($sitemap_file, $xml)) {
    echo "✅ Sitemap gerado com sucesso!\n";
    echo "📊 Total de URLs: " . count($urls) . "\n";
    echo "📁 Arquivo: {$sitemap_file}\n";
    echo "\n📌 Próximo passo: Enviar para Google Search Console\n";
} else {
    echo "❌ Erro ao salvar sitemap!\n";
    exit(1);
}
