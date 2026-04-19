<?php
/**
 * SEO Meta Tags & Structured Data
 * 
 * Inclui Open Graph, Twitter Cards e Schema.org JSON-LD
 * para melhorar a aparência nos resultados de busca e compartilhamentos
 */

// Configurações padrão
$seo_config = [
    'site_name' => 'MyTube',
    'site_url' => 'https://www.mytube.social',
    'title' => 'MyTube - Compartilhe vídeos e conecte-se com outros criadores e Aqui os criadores competem!',
    'description' => 'MyTube é a rede social angolana onde criadores competem e talentos são descobertos! Participe de competições escolares, compartilhe vídeos e conecte-se com outros criadores. Mostre seu talento!',
    'image' => 'https://www.mytube.social/assets/images/og-image.jpg',
    'image_width' => 1200,
    'image_height' => 630,
    'type' => 'website',
    'locale' => 'pt_AO',
    'twitter_card' => 'summary_large_image',
    'twitter_site' => 'NULL', // Alterar se tiver Twitter
];

// Permitir override de configurações
if (isset($page_seo)) {
    $seo_config = array_merge($seo_config, $page_seo);
}
?>

<!-- Primary Meta Tags -->
<meta name="title" content="<?php echo htmlspecialchars($seo_config['title']); ?>">
<meta name="description" content="<?php echo htmlspecialchars($seo_config['description']); ?>">
<meta name="keywords" content="rede social angola, vídeos angola, criadores angolanos, competições escolares, talentos angola, social media angola, mytube, videos curtos, compartilhar vídeos, escolas angola">

<!-- Open Graph / Facebook -->
<meta property="og:type" content="<?php echo htmlspecialchars($seo_config['type']); ?>">
<meta property="og:url" content="<?php echo htmlspecialchars($seo_config['site_url']); ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($seo_config['title']); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($seo_config['description']); ?>">
<meta property="og:image" content="<?php echo htmlspecialchars($seo_config['image']); ?>">
<meta property="og:image:width" content="<?php echo $seo_config['image_width']; ?>">
<meta property="og:image:height" content="<?php echo $seo_config['image_height']; ?>">
<meta property="og:site_name" content="<?php echo htmlspecialchars($seo_config['site_name']); ?>">
<meta property="og:locale" content="<?php echo htmlspecialchars($seo_config['locale']); ?>">

<!-- Twitter Card -->
<meta name="twitter:card" content="<?php echo htmlspecialchars($seo_config['twitter_card']); ?>">
<meta name="twitter:url" content="<?php echo htmlspecialchars($seo_config['site_url']); ?>">
<meta name="twitter:title" content="<?php echo htmlspecialchars($seo_config['title']); ?>">
<meta name="twitter:description" content="<?php echo htmlspecialchars($seo_config['description']); ?>">
<meta name="twitter:image" content="<?php echo htmlspecialchars($seo_config['image']); ?>">
<?php if (!empty($seo_config['twitter_site'])): ?>
<meta name="twitter:site" content="<?php echo htmlspecialchars($seo_config['twitter_site']); ?>">
<?php endif; ?>

<!-- Schema.org JSON-LD -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "<?php echo htmlspecialchars($seo_config['site_name'], ENT_QUOTES); ?>",
  "url": "<?php echo htmlspecialchars($seo_config['site_url'], ENT_QUOTES); ?>",
  "description": "<?php echo htmlspecialchars($seo_config['description'], ENT_QUOTES); ?>",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "<?php echo htmlspecialchars($seo_config['site_url'], ENT_QUOTES); ?>/search?q={search_term_string}"
    },
    "query-input": "required name=search_term_string"
  }
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SocialMediaPosting",
  "url": "<?php echo htmlspecialchars($seo_config['site_url'], ENT_QUOTES); ?>",
  "datePublished": "<?php echo date('c'); ?>",
  "dateModified": "<?php echo date('c'); ?>",
  "author": {
    "@type": "Organization",
    "name": "<?php echo htmlspecialchars($seo_config['site_name'], ENT_QUOTES); ?>",
    "url": "<?php echo htmlspecialchars($seo_config['site_url'], ENT_QUOTES); ?>"
  },
  "publisher": {
    "@type": "Organization",
    "name": "<?php echo htmlspecialchars($seo_config['site_name'], ENT_QUOTES); ?>",
    "url": "<?php echo htmlspecialchars($seo_config['site_url'], ENT_QUOTES); ?>",
    "logo": {
      "@type": "ImageObject",
      "url": "<?php echo htmlspecialchars($seo_config['site_url'], ENT_QUOTES); ?>/assets/images/logo.png"
    }
  },
  "headline": "<?php echo htmlspecialchars($seo_config['title'], ENT_QUOTES); ?>",
  "description": "<?php echo htmlspecialchars($seo_config['description'], ENT_QUOTES); ?>",
  "image": "<?php echo htmlspecialchars($seo_config['image'], ENT_QUOTES); ?>"
}
</script>

<!-- Additional SEO -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#111111">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="format-detection" content="telephone=no">
