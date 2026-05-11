<?php
/**
 * Image Sanitizer - Remove EXIF/metadata e redimensiona imagens
 *
 * Previne vazamento de informações sensíveis:
 * - GPS (localização exata)
 * - Data/hora real
 * - Modelo da câmera/dispositivo
 * - Software usado
 * - Outros metadados privados
 *
 * Também redimensiona imagens grandes (fallback server-side para o compressor JS).
 */

// Dimensão máxima server-side (lado maior, em px)
define('IMG_SANITIZER_MAX_DIM', 1280);

/**
 * Remove EXIF e outros metadados de uma imagem
 * 
 * @param string $file_path Caminho completo do arquivo de imagem
 * @param int $quality Qualidade JPEG (0-100), padrão 90
 * @return array ['success' => bool, 'message' => string]
 */
function sanitize_image_exif(string $file_path, int $quality = 90): array {
    // ✅ VERIFICAR SE GD ESTÁ DISPONÍVEL
    if (!extension_loaded('gd')) {
        return [
            'success' => false, 
            'message' => 'Extensão GD não disponível (imagem salva sem sanitização)',
            'warning' => true // Flag para não logar como erro crítico
        ];
    }
    
    if (!file_exists($file_path)) {
        return ['success' => false, 'message' => 'Arquivo não encontrado'];
    }
    
    // Detectar tipo MIME real
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_path);
    finfo_close($finfo);
    
    try {
        // Criar imagem a partir do arquivo original
        $image = null;
        
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $image = @imagecreatefromjpeg($file_path);
                break;
                
            case 'image/png':
                $image = @imagecreatefrompng($file_path);
                break;
                
            case 'image/gif':
                $image = @imagecreatefromgif($file_path);
                break;
                
            case 'image/webp':
                $image = @imagecreatefromwebp($file_path);
                break;
                
            default:
                return [
                    'success' => false, 
                    'message' => "Tipo de imagem não suportado: $mime_type"
                ];
        }
        
        if ($image === false) {
            return ['success' => false, 'message' => 'Falha ao carregar imagem'];
        }
        
        // Obter dimensões originais
        $orig_width  = imagesx($image);
        $orig_height = imagesy($image);

        // ── Calcular dimensões finais (redimensionar se necessário) ──────────
        $max_dim = defined('IMG_SANITIZER_MAX_DIM') ? IMG_SANITIZER_MAX_DIM : 1280;
        $width   = $orig_width;
        $height  = $orig_height;

        if ($orig_width > $max_dim || $orig_height > $max_dim) {
            if ($orig_width >= $orig_height) {
                $width  = $max_dim;
                $height = (int) round($orig_height * $max_dim / $orig_width);
            } else {
                $height = $max_dim;
                $width  = (int) round($orig_width * $max_dim / $orig_height);
            }
        }

        // Criar nova imagem limpa (sem EXIF, com novo tamanho)
        $clean_image = imagecreatetruecolor($width, $height);

        // Preservar transparência para PNG/GIF/WebP
        if (in_array($mime_type, ['image/png', 'image/gif', 'image/webp'])) {
            imagealphablending($clean_image, false);
            imagesavealpha($clean_image, true);
            $transparent = imagecolorallocatealpha($clean_image, 0, 0, 0, 127);
            imagefill($clean_image, 0, 0, $transparent);
        }

        // Copiar + redimensionar (remove EXIF automaticamente)
        imagecopyresampled(
            $clean_image, $image,
            0, 0, 0, 0,
            $width, $height, $orig_width, $orig_height
        );
        
        // Salvar imagem limpa no mesmo arquivo (sobrescrever)
        $saved = false;
        
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $saved = imagejpeg($clean_image, $file_path, $quality);
                break;
                
            case 'image/png':
                // PNG: qualidade 0-9 (inverso do JPEG)
                $png_quality = (int) ((100 - $quality) / 11.11);
                $saved = imagepng($clean_image, $file_path, $png_quality);
                break;
                
            case 'image/gif':
                $saved = imagegif($clean_image, $file_path);
                break;
                
            case 'image/webp':
                $saved = imagewebp($clean_image, $file_path, $quality);
                break;
        }
        
        // Liberar memória
        imagedestroy($image);
        imagedestroy($clean_image);
        
        if (!$saved) {
            return ['success' => false, 'message' => 'Falha ao salvar imagem sanitizada'];
        }
        
        return [
            'success'    => true,
            'message'    => 'EXIF removido com sucesso',
            'mime_type'  => $mime_type,
            'dimensions' => ['width' => $width, 'height' => $height],
            'resized'    => ($width !== $orig_width || $height !== $orig_height)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro ao processar imagem: ' . $e->getMessage()];
    }
}

/**
 * Verifica se uma imagem contém dados EXIF
 * 
 * @param string $file_path Caminho do arquivo
 * @return array ['has_exif' => bool, 'exif_data' => array|null]
 */
function check_image_exif(string $file_path): array {
    if (!file_exists($file_path)) {
        return ['has_exif' => false, 'exif_data' => null];
    }
    
    // Verificar se funções EXIF estão disponíveis
    if (!function_exists('exif_read_data')) {
        return ['has_exif' => false, 'exif_data' => null, 'error' => 'EXIF extension not available'];
    }
    
    try {
        $exif = @exif_read_data($file_path, 0, true);
        
        if ($exif === false || empty($exif)) {
            return ['has_exif' => false, 'exif_data' => null];
        }
        
        // Verificar se tem dados sensíveis
        $has_sensitive = false;
        $sensitive_keys = ['GPS', 'IFD0', 'EXIF', 'Make', 'Model', 'Software', 'DateTime'];
        
        foreach ($sensitive_keys as $key) {
            if (isset($exif[$key]) && !empty($exif[$key])) {
                $has_sensitive = true;
                break;
            }
        }
        
        return [
            'has_exif' => $has_sensitive,
            'exif_data' => $exif,
            'keys_found' => array_keys($exif)
        ];
        
    } catch (Exception $e) {
        return ['has_exif' => false, 'exif_data' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Log de tentativas de upload com EXIF GPS
 * 
 * @param int $user_id ID do usuário
 * @param string $file_path Caminho do arquivo
 * @param array $exif_data Dados EXIF encontrados
 */
function log_exif_gps_attempt(int $user_id, string $file_path, array $exif_data): void {
    // Log apenas se tiver GPS
    if (!isset($exif_data['GPS'])) {
        return;
    }
    
    $log_dir = __DIR__ . '/../logs/';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . 'exif_gps_' . date('Y-m-d') . '.log';
    $log_entry = sprintf(
        "[%s] User ID: %d | File: %s | GPS: %s\n",
        date('Y-m-d H:i:s'),
        $user_id,
        basename($file_path),
        json_encode($exif_data['GPS'])
    );
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}
