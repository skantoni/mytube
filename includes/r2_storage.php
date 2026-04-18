<?php
/**
 * Cloudflare R2 Storage Helper
 * 
 * Funções para upload, delete e resolução de URLs de vídeos
 * armazenados no Cloudflare R2.
 * 
 * Compatível com armazenamento local (vídeos antigos) e R2 (novos).
 */

require_once __DIR__ . '/r2_config.php';

/**
 * Obter instância do cliente S3 para o R2
 * Usa singleton para evitar múltiplas instâncias
 */
function r2_get_client() {
    static $client = null;
    
    if ($client === null) {
        if (!function_exists('mb_strlen')) {
            throw new RuntimeException('Extensão mbstring não está ativa no PHP.');
        }

        $aws_phar = __DIR__ . '/../aws.phar';
        if (!file_exists($aws_phar)) {
            throw new RuntimeException(
                'aws.phar não encontrado em ' . realpath(__DIR__ . '/..') . '/aws.phar — ' .
                'Corre: cd /var/www/mytube.social && wget https://github.com/aws/aws-sdk-php/releases/download/3.338.2/aws.phar'
            );
        }
        require_once $aws_phar;
        
        $client = new Aws\S3\S3Client([
            'region' => R2_REGION,
            'version' => 'latest',
            'endpoint' => R2_ENDPOINT,
            'credentials' => [
                'key' => R2_ACCESS_KEY_ID,
                'secret' => R2_SECRET_ACCESS_KEY,
            ],
            'use_path_style_endpoint' => false,  // CORRIGIDO: R2 usa virtual-hosted style
            // Desabilitar checksum para compatibilidade com R2
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }
    
    return $client;
}

/**
 * Fazer upload de um vídeo para o Cloudflare R2
 * 
 * @param string $local_file_path Caminho local do ficheiro temporário
 * @param string $r2_file_name   Nome do ficheiro no R2 (ex: "abc123_1234567890.mp4")
 * @param string $content_type   MIME type do vídeo
 * @return array ['success' => bool, 'key' => string, 'error' => string]
 */
function r2_upload_video(string $local_file_path, string $r2_file_name, string $content_type = 'video/mp4'): array {
    try {
        $client = r2_get_client();
        $key = R2_VIDEO_FOLDER . $r2_file_name;
        
        $result = $client->putObject([
            'Bucket' => R2_BUCKET_NAME,
            'Key'    => $key,
            'SourceFile' => $local_file_path,
            'ContentType' => $content_type,
            'CacheControl' => 'public, max-age=31536000', // Cache de 1 ano
        ]);
        
        return [
            'success' => true,
            'key' => $key,
            'url' => R2_PUBLIC_URL . '/' . $key,
            'error' => null,
        ];
    } catch (Throwable $e) {
        error_log('R2 Upload Error: ' . $e->getMessage());
        return [
            'success' => false,
            'key' => null,
            'url' => null,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Apagar um vídeo do Cloudflare R2
 * 
 * @param string $video_path O video_path do banco de dados (com ou sem prefixo r2://)
 * @return bool
 */
function r2_delete_video(string $video_path): bool {
    try {
        // Remover o prefixo r2:// se existir
        $file_name = r2_strip_prefix($video_path);
        $key = R2_VIDEO_FOLDER . $file_name;
        
        $client = r2_get_client();
        $client->deleteObject([
            'Bucket' => R2_BUCKET_NAME,
            'Key'    => $key,
        ]);
        
        return true;
    } catch (Throwable $e) {
        error_log('R2 Delete Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verificar se um video_path é do R2
 * 
 * @param string $video_path
 * @return bool
 */
function r2_is_r2_path(string $video_path): bool {
    return str_starts_with($video_path, R2_PATH_PREFIX);
}

/**
 * Remover o prefixo r2:// de um video_path
 * 
 * @param string $video_path
 * @return string
 */
function r2_strip_prefix(string $video_path): string {
    if (r2_is_r2_path($video_path)) {
        return substr($video_path, strlen(R2_PATH_PREFIX));
    }
    return $video_path;
}

/**
 * Gera URL de vídeo local com cache-busting baseado na data de modificação.
 *
 * @param string $video_path Nome/caminho do ficheiro guardado no banco
 * @return string URL relativa para o vídeo local
 */
function resolve_local_video_url(string $video_path): string {
    $relative_url = 'uploads/videos/' . ltrim($video_path, '/\\');
    $absolute_path = dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative_url);

    if (!is_file($absolute_path)) {
        return $relative_url;
    }

    $mtime = @filemtime($absolute_path);
    if ($mtime === false) {
        return $relative_url;
    }

    return $relative_url . '?v=' . (int)$mtime;
}

/**
 * Resolver a URL completa de um vídeo
 * 
 * - Vídeos R2: retorna URL pública do R2
 * - Vídeos locais: retorna caminho relativo local
 * 
 * @param string $video_path O video_path do banco de dados
 * @return string URL/caminho para aceder ao vídeo
 */
function resolve_video_url(string $video_path): string {
    if (empty($video_path)) {
        return '';
    }
    
    if (r2_is_r2_path($video_path)) {
        $file_name = r2_strip_prefix($video_path);
        return R2_PUBLIC_URL . '/' . R2_VIDEO_FOLDER . rawurlencode($file_name);
    }
    
    // Vídeo local (compatibilidade com vídeos antigos)
    return resolve_local_video_url($video_path);
}

/**
 * Obter o mapa MIME type baseado na extensão
 * 
 * @param string $extension Extensão do ficheiro (sem ponto)
 * @return string MIME type
 */
function r2_get_mime_type(string $extension): string {
    $mime_types = [
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'avi'  => 'video/x-msvideo',
        'mov'  => 'video/quicktime',
        'wmv'  => 'video/x-ms-wmv',
        'mkv'  => 'video/x-matroska',
    ];
    
    return $mime_types[strtolower($extension)] ?? 'video/mp4';
}

/**
 * Gerar o valor da constante JS com a URL pública do R2 e o prefixo
 * Para incluir no header das páginas HTML
 * 
 * @return string Script tag com as constantes JS
 */
function r2_js_config(): string {
    $public_url = R2_PUBLIC_URL;
    $video_folder = R2_VIDEO_FOLDER;
    $prefix = R2_PATH_PREFIX;
    $enabled = R2_ENABLED ? 'true' : 'false';
    
    return <<<HTML
<script>
    window.R2_CONFIG = {
        enabled: {$enabled},
        publicUrl: '{$public_url}',
        videoFolder: '{$video_folder}',
        pathPrefix: '{$prefix}'
    };
    
    /**
     * Resolver URL de um vídeo (local ou R2)
     * @param {string} videoPath - O video_path do banco de dados
     * @returns {string} URL completa para o vídeo
     */
    function resolveVideoUrl(videoPath) {
        if (!videoPath) return '';
        if (videoPath.startsWith(window.R2_CONFIG.pathPrefix)) {
            var fileName = videoPath.substring(window.R2_CONFIG.pathPrefix.length);
            return window.R2_CONFIG.publicUrl + '/' + window.R2_CONFIG.videoFolder + encodeURIComponent(fileName);
        }
        return 'uploads/videos/' + videoPath;
    }
</script>
HTML;
}
?>
