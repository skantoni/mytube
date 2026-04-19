<?php
/**
 * Script para atualizar manifest.json com screenshots automaticamente
 * Execute este script depois de adicionar as imagens em assets/images/screenshots/
 * 
 * Uso: php update_manifest_screenshots.php
 */

$screenshots_dir = __DIR__ . '/assets/images/screenshots';
$manifest_file = __DIR__ . '/manifest.json';

// Verificar se o diretório existe
if (!is_dir($screenshots_dir)) {
    echo "❌ Diretório de screenshots não existe: {$screenshots_dir}\n";
    echo "📁 Crie o diretório e adicione as imagens primeiro!\n";
    exit(1);
}

// Ler manifest atual
if (!file_exists($manifest_file)) {
    echo "❌ Arquivo manifest.json não encontrado!\n";
    exit(1);
}

$manifest = json_decode(file_get_contents($manifest_file), true);
if (!$manifest) {
    echo "❌ Erro ao ler manifest.json!\n";
    exit(1);
}

// Buscar screenshots
$screenshots = [];
$files = glob($screenshots_dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE);

foreach ($files as $file) {
    $filename = basename($file);
    $size = getimagesize($file);
    
    if (!$size) {
        echo "⚠️  Ignorando {$filename} (não é imagem válida)\n";
        continue;
    }
    
    $width = $size[0];
    $height = $size[1];
    $mime = $size['mime'];
    
    // Determinar tipo de dispositivo baseado no tamanho
    $form_factor = 'wide'; // padrão
    if ($width < $height) {
        $form_factor = 'narrow'; // mobile/portrait
    }
    
    $screenshots[] = [
        'src' => '/assets/images/screenshots/' . $filename,
        'sizes' => "{$width}x{$height}",
        'type' => $mime,
        'form_factor' => $form_factor,
        'label' => ucfirst(str_replace(['-', '_', '.jpg', '.jpeg', '.png', '.webp'], ' ', $filename))
    ];
    
    echo "✅ Adicionado: {$filename} ({$width}x{$height}, {$form_factor})\n";
}

if (empty($screenshots)) {
    echo "❌ Nenhuma screenshot encontrada em {$screenshots_dir}\n";
    echo "📸 Adicione imagens JPG, PNG ou WebP no diretório!\n";
    exit(1);
}

// Ordenar: wide primeiro, depois narrow
usort($screenshots, function($a, $b) {
    if ($a['form_factor'] === $b['form_factor']) return 0;
    return $a['form_factor'] === 'wide' ? -1 : 1;
});

// Atualizar manifest
$manifest['screenshots'] = $screenshots;

// Salvar
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (file_put_contents($manifest_file, $json)) {
    echo "\n🎉 Manifest.json atualizado com sucesso!\n";
    echo "📊 Total de screenshots: " . count($screenshots) . "\n";
} else {
    echo "\n❌ Erro ao salvar manifest.json!\n";
    exit(1);
}
