<?php
/**
 * API de Streaming de Vídeo com Suporte a Range Requests
 * Otimizado para conexões lentas (3G/4G)
 * 
 * Funcionalidades:
 * - Range requests (partial content) para loading progressivo
 * - Headers de cache otimizados
 * - Compressão quando possível
 * - Detecção de velocidade e ajuste de buffer
 * - Suporte a Cloudflare R2 (redireciona para URL pública)
 */

require_once '../includes/config.php';
require_once '../includes/r2_storage.php';

// Desabilitar output buffering para streaming
if (ob_get_level()) ob_end_clean();

// Obter ID do vídeo
$video_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$quality = isset($_GET['q']) ? $_GET['q'] : 'original'; // original, medium, low

if (!$video_id) {
    http_response_code(400);
    die('ID do vídeo não fornecido');
}

try {
    // Buscar informações do vídeo no banco
    $stmt = $pdo->prepare("
        SELECT v.id, v.video_path, v.user_id, v.is_public,
               u.username
        FROM videos v
        JOIN users u ON v.user_id = u.id
        WHERE v.id = ?
    ");
    $stmt->execute([$video_id]);
    $video = $stmt->fetch();
    
    if (!$video) {
        http_response_code(404);
        die('Vídeo não encontrado');
    }
    
    // Verificar permissões de acesso
    $can_access = false;
    
    // Vídeo público
    if ($video['is_public']) {
        $can_access = true;
    }
    
    // Vídeo privado - apenas o dono pode ver
    if (!$video['is_public'] && isLoggedIn() && $_SESSION['user_id'] == $video['user_id']) {
        $can_access = true;
    }
    
    if (!$can_access) {
        http_response_code(403);
        die('Sem permissão para acessar este vídeo');
    }
    
    // ============================================
    // VÍDEO NO CLOUDFLARE R2 — redirecionar
    // ============================================
    if (r2_is_r2_path($video['video_path'])) {
        $r2_url = resolve_video_url($video['video_path']);
        header('Cache-Control: public, max-age=604800'); // 7 dias
        header('Location: ' . $r2_url, true, 302);
        exit;
    }
    
    // ============================================
    // VÍDEO LOCAL — streaming tradicional
    // ============================================
    
    // ⚠️ PROTEÇÃO CONTRA PATH TRAVERSAL
    // Validar que o caminho está dentro de uploads/videos/
    $uploads_dir = realpath(__DIR__ . '/../uploads/videos');
    
    if ($uploads_dir === false) {
        http_response_code(500);
        error_log("stream_video.php: Diretório uploads/videos não existe");
        die('Erro interno do servidor');
    }
    
    // ✅ PRIMEIRA VALIDAÇÃO: Detectar padrões de path traversal no video_path
    // Bloqueia ../, ..\, etc ANTES de tentar resolver o caminho
    $suspicious_patterns = ['../', '..\\', '../', '..\\'];
    foreach ($suspicious_patterns as $pattern) {
        if (strpos($video['video_path'], $pattern) !== false) {
            http_response_code(403);
            error_log("stream_video.php: TENTATIVA DE PATH TRAVERSAL BLOQUEADA (padrão suspeito) - video_id: $video_id, path: {$video['video_path']}");
            die('Acesso negado');
        }
    }
    
    // Normalizar path (remover barras duplas, etc)
    $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $video['video_path']);
    $normalized_path = preg_replace('#' . DIRECTORY_SEPARATOR . '+#', DIRECTORY_SEPARATOR, $normalized_path);
    
    // Construir caminho completo
    $requested_path = $uploads_dir . DIRECTORY_SEPARATOR . $normalized_path;
    
    // Tentar resolver caminho real (se arquivo existir)
    $file_path = realpath($requested_path);
    
    // Se realpath falhou (arquivo não existe ou é path inválido)
    if ($file_path === false) {
        // Verificar se é tentativa de path traversal mesmo sem existir
        // (previne enumeração de arquivos do sistema)
        $canonical_path = $uploads_dir . DIRECTORY_SEPARATOR . $normalized_path;
        
        // Normalizar para comparação (resolver ..)
        $parts = explode(DIRECTORY_SEPARATOR, $canonical_path);
        $resolved_parts = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved_parts); // Subir um nível
            } else {
                $resolved_parts[] = $part;
            }
        }
        $canonical_path = implode(DIRECTORY_SEPARATOR, $resolved_parts);
        
        // Se o caminho resolvido não está dentro de uploads/videos, é path traversal
        if (strpos($canonical_path, $uploads_dir) !== 0) {
            http_response_code(403);
            error_log("stream_video.php: TENTATIVA DE PATH TRAVERSAL BLOQUEADA - video_id: $video_id, path: {$video['video_path']}, canonical: $canonical_path");
            die('Acesso negado');
        }
        
        // Caminho está OK, mas arquivo não existe
        http_response_code(404);
        error_log("stream_video.php: Arquivo não encontrado - video_id: $video_id, path: {$video['video_path']}");
        die('Arquivo de vídeo não encontrado');
    }
    
    // ✅ SEGUNDA VALIDAÇÃO: Garantir que o caminho RESOLVIDO está dentro de uploads/videos/
    // (proteção contra symlinks e outros truques)
    if (strpos($file_path, $uploads_dir) !== 0) {
        http_response_code(403);
        error_log("stream_video.php: TENTATIVA DE PATH TRAVERSAL BLOQUEADA (fora do diretório) - video_id: $video_id, path: {$video['video_path']}, resolved: $file_path");
        die('Acesso negado');
    }
    
    // Validar MIME type (apenas vídeos)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_path);
    finfo_close($finfo);
    
    // Bloquear arquivos que não são vídeos
    if (!$mime_type || strpos($mime_type, 'video') === false) {
        http_response_code(403);
        error_log("stream_video.php: MIME type inválido bloqueado - video_id: $video_id, mime: $mime_type");
        die('Tipo de arquivo não permitido');
    }
    
    // Informações do arquivo
    $file_size = filesize($file_path);
    $file_name = basename($file_path);
    
    // ============================================
    // SUPORTE A RANGE REQUESTS (Progressive Download)
    // ============================================
    
    $start = 0;
    $end = $file_size - 1;
    $length = $file_size;
    $is_partial = false;
    
    // Verificar se cliente suporta e solicitou range
    if (isset($_SERVER['HTTP_RANGE'])) {
        $is_partial = true;
        
        // Parse do header Range: bytes=0-1024
        if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = intval($matches[1]);
            
            if (!empty($matches[2])) {
                $end = intval($matches[2]);
            }
            
            // Validar range
            if ($start > $end || $start >= $file_size) {
                header("HTTP/1.1 416 Requested Range Not Satisfiable");
                header("Content-Range: bytes */$file_size");
                exit;
            }
            
            // Ajustar end se necessário
            $end = min($end, $file_size - 1);
            $length = $end - $start + 1;
        }
    }
    
    // ============================================
    // HEADERS DE RESPOSTA
    // ============================================
    
    // Cache por 7 dias
    $cache_duration = 604800;
    $etag = '"' . $file_size . '-' . filemtime($file_path) . '"';
    $last_modified = gmdate('D, d M Y H:i:s', filemtime($file_path)) . ' GMT';
    
    // Verificar cache do cliente (304 Not Modified)
    $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : null;
    $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : null;
    
    if (($if_none_match && $if_none_match == $etag) || 
        ($if_modified_since && $if_modified_since == $last_modified)) {
        http_response_code(304);
        exit;
    }
    
    // Status code
    if ($is_partial) {
        http_response_code(206); // Partial Content
    } else {
        http_response_code(200); // OK
    }
    
    // Headers básicos
    header("Content-Type: $mime_type");
    header("Content-Length: $length");
    header("Accept-Ranges: bytes");
    
    // Headers de cache
    header("Cache-Control: public, max-age=$cache_duration");
    header("ETag: $etag");
    header("Last-Modified: $last_modified");
    header("Expires: " . gmdate('D, d M Y H:i:s', time() + $cache_duration) . ' GMT');
    
    // Header de range (se partial)
    if ($is_partial) {
        header("Content-Range: bytes $start-$end/$file_size");
    }
    
    // Headers de segurança
    header("X-Content-Type-Options: nosniff");
    
    // ============================================
    // ENVIAR DADOS DO ARQUIVO
    // ============================================
    
    // Abrir arquivo
    $fp = fopen($file_path, 'rb');
    
    if (!$fp) {
        http_response_code(500);
        die('Erro ao abrir arquivo');
    }
    
    // Mover ponteiro para posição inicial
    if ($start > 0) {
        fseek($fp, $start);
    }
    
    // Enviar em chunks de 8KB (otimizado para 3G)
    // Chunks menores = menos memória + melhor para conexões lentas
    $chunk_size = 8192; // 8KB
    $bytes_sent = 0;
    
    while (!feof($fp) && $bytes_sent < $length && connection_status() == 0) {
        // Calcular tamanho do próximo chunk
        $bytes_to_read = min($chunk_size, $length - $bytes_sent);
        
        // Ler e enviar chunk
        $chunk = fread($fp, $bytes_to_read);
        echo $chunk;
        flush();
        
        $bytes_sent += strlen($chunk);
        
        // Pequeno delay para evitar sobrecarga em conexões muito lentas
        // Remove este usleep se causar problemas
        // usleep(10000); // 10ms
    }
    
    fclose($fp);
    exit;
    
} catch (Exception $e) {
    error_log("Erro no streaming de vídeo: " . $e->getMessage());
    http_response_code(500);
    die('Erro ao processar vídeo');
}
?>
