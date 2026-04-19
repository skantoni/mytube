<?php
/**
 * Upload Validation Helpers
 * 
 * Validação segura de uploads baseada em MIME type real (não apenas extensão)
 * Previne upload de arquivos maliciosos disfarçados (ex: shell.php.jpg)
 */

/**
 * Validar se o arquivo é uma imagem válida
 * 
 * Verifica:
 * 1. MIME type real do arquivo (não apenas extensão)
 * 2. Tentativa de abrir como imagem (getimagesize)
 * 3. Extensão permitida
 * 
 * @param string $tmp_path Caminho temporário do arquivo enviado
 * @param string $filename Nome original do arquivo
 * @param array $allowed_extensions Extensões permitidas (padrão: jpg, jpeg, png, gif, webp)
 * @return array ['valid' => bool, 'error' => string|null, 'mime' => string|null, 'extension' => string|null]
 */
function validate_image_upload(string $tmp_path, string $filename, array $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']): array {
    // 1. Verificar se o arquivo existe
    if (!file_exists($tmp_path) || !is_readable($tmp_path)) {
        return [
            'valid' => false,
            'error' => 'Arquivo não encontrado ou inacessível',
            'mime' => null,
            'extension' => null
        ];
    }
    
    // 2. Obter MIME type real usando fileinfo (mais confiável)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $tmp_path);
    finfo_close($finfo);
    
    // 3. Lista de MIME types permitidos para imagens
    $allowed_mimes = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif']
    ];
    
    // 4. Verificar se o MIME type é permitido
    if (!isset($allowed_mimes[$mime_type])) {
        return [
            'valid' => false,
            'error' => 'Tipo de arquivo não permitido. Use apenas imagens JPG, PNG, GIF ou WEBP.',
            'mime' => $mime_type,
            'extension' => null
        ];
    }
    
    // 5. Tentar abrir como imagem (validação adicional)
    $image_info = @getimagesize($tmp_path);
    if ($image_info === false) {
        return [
            'valid' => false,
            'error' => 'Arquivo corrompido ou não é uma imagem válida',
            'mime' => $mime_type,
            'extension' => null
        ];
    }
    
    // 6. Verificar extensão do arquivo
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // 7. A extensão deve corresponder ao MIME type
    if (!in_array($file_extension, $allowed_mimes[$mime_type])) {
        return [
            'valid' => false,
            'error' => 'A extensão do arquivo não corresponde ao tipo de imagem',
            'mime' => $mime_type,
            'extension' => $file_extension
        ];
    }
    
    // 8. Verificar se a extensão está na lista de permitidas
    if (!in_array($file_extension, $allowed_extensions)) {
        return [
            'valid' => false,
            'error' => 'Extensão não permitida. Use: ' . implode(', ', $allowed_extensions),
            'mime' => $mime_type,
            'extension' => $file_extension
        ];
    }
    
    // 9. Verificar dimensões (opcional - previne imagens muito grandes)
    if ($image_info[0] > 8000 || $image_info[1] > 8000) {
        return [
            'valid' => false,
            'error' => 'Imagem muito grande. Dimensões máximas: 8000x8000 pixels',
            'mime' => $mime_type,
            'extension' => $file_extension
        ];
    }
    
    // ✅ Validação passou
    return [
        'valid' => true,
        'error' => null,
        'mime' => $mime_type,
        'extension' => $file_extension
    ];
}

/**
 * Validar se o arquivo é um vídeo válido
 * 
 * Verifica:
 * 1. MIME type real do arquivo
 * 2. Extensão permitida
 * 3. Tamanho do arquivo
 * 
 * @param string $tmp_path Caminho temporário do arquivo enviado
 * @param string $filename Nome original do arquivo
 * @param array $allowed_extensions Extensões permitidas (padrão: mp4, avi, mov, wmv, webm)
 * @param int $max_size_mb Tamanho máximo em MB (padrão: 100)
 * @return array ['valid' => bool, 'error' => string|null, 'mime' => string|null, 'extension' => string|null]
 */
function validate_video_upload(string $tmp_path, string $filename, array $allowed_extensions = ['mp4', 'avi', 'mov', 'wmv', 'webm'], int $max_size_mb = 100): array {
    // 1. Verificar se o arquivo existe
    if (!file_exists($tmp_path) || !is_readable($tmp_path)) {
        return [
            'valid' => false,
            'error' => 'Arquivo não encontrado ou inacessível',
            'mime' => null,
            'extension' => null
        ];
    }
    
    // 2. Obter MIME type real
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $tmp_path);
    finfo_close($finfo);
    
    // 3. Lista de MIME types permitidos para vídeos
    $allowed_mimes = [
        'video/mp4' => ['mp4'],
        'video/quicktime' => ['mov'],
        'video/x-msvideo' => ['avi'],
        'video/x-ms-wmv' => ['wmv'],
        'video/webm' => ['webm'],
        'video/mpeg' => ['mpeg', 'mpg'],
        'application/octet-stream' => ['mp4', 'avi', 'mov'] // Fallback para alguns servidores
    ];
    
    // 4. Verificar se o MIME type é permitido
    if (!isset($allowed_mimes[$mime_type])) {
        return [
            'valid' => false,
            'error' => 'Tipo de arquivo não permitido. Use apenas vídeos MP4, AVI, MOV, WMV ou WEBM.',
            'mime' => $mime_type,
            'extension' => null
        ];
    }
    
    // 5. Verificar extensão do arquivo
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // 6. Verificar se a extensão está na lista de permitidas
    if (!in_array($file_extension, $allowed_extensions)) {
        return [
            'valid' => false,
            'error' => 'Extensão não permitida. Use: ' . implode(', ', $allowed_extensions),
            'mime' => $mime_type,
            'extension' => $file_extension
        ];
    }
    
    // 7. Verificar tamanho do arquivo
    $file_size = filesize($tmp_path);
    $max_size_bytes = $max_size_mb * 1024 * 1024;
    
    if ($file_size > $max_size_bytes) {
        return [
            'valid' => false,
            'error' => "Arquivo muito grande. Tamanho máximo: {$max_size_mb}MB",
            'mime' => $mime_type,
            'extension' => $file_extension
        ];
    }
    
    // ✅ Validação passou
    return [
        'valid' => true,
        'error' => null,
        'mime' => $mime_type,
        'extension' => $file_extension
    ];
}

/**
 * Sanitizar nome de arquivo (previne Path Traversal e XSS)
 * 
 * Remove:
 * - Caracteres especiais perigosos
 * - Path traversal (.., /, \)
 * - Tags HTML
 * - Caracteres não-ASCII problemáticos
 * 
 * @param string $filename Nome do arquivo
 * @return string Nome sanitizado
 */
function sanitize_filename(string $filename): string {
    // Remover path (previne path traversal)
    $filename = basename($filename);
    
    // Remover espaços do início e fim
    $filename = trim($filename);
    
    // Substituir espaços por underscores
    $filename = str_replace(' ', '_', $filename);
    
    // Remover caracteres perigosos (mantém apenas: a-z, A-Z, 0-9, _, -, .)
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
    
    // Limitar comprimento
    if (strlen($filename) > 200) {
        $filename = substr($filename, 0, 200);
    }
    
    // Se ficou vazio, usar nome genérico
    if (empty($filename)) {
        $filename = 'file_' . time();
    }
    
    return $filename;
}
