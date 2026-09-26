<?php
/**
 * Script de linha de comandos (CLI) para gerar thumbnails em falta
 * 
 * Executar via terminal na VPS:
 * php /var/www/mytube.social/scripts/generate_missing_thumbnails.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/r2_storage.php';
require_once __DIR__ . '/../includes/video_processing.php';

if (php_sapi_name() !== 'cli') {
    die("Este script só pode ser executado via linha de comandos (CLI).\n");
}

echo "=== INICIANDO GERAÇÃO DE THUMBNAILS EM FALTA ===\n\n";

global $pdo;

// Buscar todos os vídeos que NÃO têm thumbnail
$stmt = $pdo->prepare("SELECT id, video_path FROM videos WHERE thumbnail_path IS NULL OR thumbnail_path = '' ORDER BY id DESC");
$stmt->execute();
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($videos)) {
    echo "✅ Todos os vídeos já têm thumbnails!\n";
    exit(0);
}

echo "Foram encontrados " . count($videos) . " vídeos sem thumbnail.\n\n";

$sucesso = 0;
$falha = 0;

foreach ($videos as $video) {
    $video_id = $video['id'];
    $video_path = $video['video_path'];
    
    echo "A processar Vídeo #$video_id... ";

    // 1. Resolver URL do vídeo (FFmpeg consegue ler directamente do R2 via HTTP/HTTPS)
    $video_url = resolve_video_url($video_path);
    
    // Remover o cache buster se existir para não confundir o FFmpeg
    $video_url = explode('?', $video_url)[0];
    
    if (empty($video_url)) {
        echo "❌ Caminho inválido ($video_path)\n";
        $falha++;
        continue;
    }

    // 2. Gerar thumbnail temporário
    $temp_thumb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "thumb_recover_{$video_id}.webp";
    
    $thumb_generated = video_generate_thumbnail($video_url, $temp_thumb, 1);
    
    if (!$thumb_generated || !file_exists($temp_thumb)) {
        echo "❌ Falha no FFmpeg ao gerar WEBP\n";
        $falha++;
        continue;
    }

    // 3. Fazer upload para R2 (ou local)
    $db_thumbnail_path = null;
    $unique_name = "recover_{$video_id}_" . time() . "_thumb.webp";

    if (R2_ENABLED) {
        $r2_thumb = r2_upload_video($temp_thumb, $unique_name, 'image/webp');
        if ($r2_thumb['success']) {
            $db_thumbnail_path = R2_PATH_PREFIX . $r2_thumb['key'];
        }
    } else {
        $local_thumb = ROOT_DIR . '/uploads/videos/' . $unique_name;
        if (copy($temp_thumb, $local_thumb)) {
            $db_thumbnail_path = $unique_name;
        }
    }

    // 4. Limpar temporário
    @unlink($temp_thumb);

    if ($db_thumbnail_path) {
        // 5. Atualizar Base de Dados
        $update = $pdo->prepare("UPDATE videos SET thumbnail_path = ? WHERE id = ?");
        $update->execute([$db_thumbnail_path, $video_id]);
        
        echo "✅ OK! ($db_thumbnail_path)\n";
        $sucesso++;
    } else {
        echo "❌ Falha no upload\n";
        $falha++;
    }
}

echo "\n=== CONCLUSÃO ===\n";
echo "Total processado: " . count($videos) . "\n";
echo "Sucessos: $sucesso\n";
echo "Falhas: $falha\n";

?>
