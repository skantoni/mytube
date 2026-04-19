<?php
/**
 * CONFIGURAÇÃO PARA UPLOADS DO CHAT
 * 
 * Este arquivo contém as configurações para upload de:
 * - Imagens
 * - Vídeos
 * - Áudios
 * - Documentos
 * - Stickers
 */

// Diretórios de upload
define('CHAT_UPLOAD_DIR', __DIR__ . '/../uploads/chat/');
define('CHAT_IMAGES_DIR', CHAT_UPLOAD_DIR . 'images/');
define('CHAT_VIDEOS_DIR', CHAT_UPLOAD_DIR . 'videos/');
define('CHAT_AUDIO_DIR', CHAT_UPLOAD_DIR . 'audio/');
define('CHAT_FILES_DIR', CHAT_UPLOAD_DIR . 'files/');
define('CHAT_STICKERS_DIR', CHAT_UPLOAD_DIR . 'stickers/');

// Criar diretórios se não existirem
$dirs = [
    CHAT_UPLOAD_DIR,
    CHAT_IMAGES_DIR,
    CHAT_VIDEOS_DIR,
    CHAT_AUDIO_DIR,
    CHAT_FILES_DIR,
    CHAT_STICKERS_DIR
];

foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Tamanhos máximos de arquivo (em bytes)
define('MAX_IMAGE_SIZE', 50 * 1024 * 1024);    // 50 MB (fotos sem limite prático)
if (!defined('MAX_VIDEO_SIZE')) {
    define('MAX_VIDEO_SIZE', 6 * 1024 * 1024);    // 6 MB
}
define('MAX_AUDIO_SIZE', 10 * 1024 * 1024);   // 10 MB
if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 25 * 1024 * 1024);    // 25 MB
}

// Extensões permitidas
$ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'heic', 'heif', 'avif'];
$ALLOWED_VIDEO_TYPES = ['mp4', 'webm', 'mov', 'avi', 'mkv', '3gp'];
$ALLOWED_AUDIO_TYPES = ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'aac', 'flac'];
// SEGURANÇA: Removidos svg, html, css, js, xml, json (podem executar código malicioso)
$ALLOWED_FILE_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'rar', '7z', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

/**
 * Função para fazer upload de arquivo
 * 
 * @param array $file - Arquivo do $_FILES
 * @param string $type - Tipo: 'image', 'video', 'audio', 'file', 'sticker'
 * @return array - ['success' => bool, 'url' => string, 'error' => string]
 */
function uploadChatFile($file, $type) {
    global $ALLOWED_IMAGE_TYPES, $ALLOWED_VIDEO_TYPES, $ALLOWED_AUDIO_TYPES, $ALLOWED_FILE_TYPES;
    
    // Verificar se o arquivo foi enviado
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Arquivo não foi enviado'];
    }
    
    // Verificar erros de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Erro no upload: ' . $file['error']];
    }
    
    // Obter extensão
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validação MIME type (segurança contra arquivos maliciosos)
    require_once __DIR__ . '/upload_validation.php';
    
    // Verificar tipo e tamanho
    switch ($type) {
        case 'image':
        case 'sticker':
            // Validação rigorosa de imagem com MIME type
            $validation = validate_image_upload($file['tmp_name'], $file['name'], $ALLOWED_IMAGE_TYPES);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }
            if ($file['size'] > MAX_IMAGE_SIZE) {
                return ['success' => false, 'error' => 'Imagem muito grande (máx 50MB)'];
            }
            $ext = $validation['extension']; // Usar extensão validada
            $upload_dir = $type === 'sticker' ? CHAT_STICKERS_DIR : CHAT_IMAGES_DIR;
            break;
            
        case 'video':
            // Validação rigorosa de vídeo com MIME type
            $validation = validate_video_upload($file['tmp_name'], $file['name'], $ALLOWED_VIDEO_TYPES, 6);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }
            $ext = $validation['extension']; // Usar extensão validada
            $upload_dir = CHAT_VIDEOS_DIR;
            break;
            
        case 'audio':
            if (!in_array($ext, $ALLOWED_AUDIO_TYPES)) {
                return ['success' => false, 'error' => 'Tipo de áudio não permitido'];
            }
            if ($file['size'] > MAX_AUDIO_SIZE) {
                return ['success' => false, 'error' => 'Áudio muito grande (máx 10MB)'];
            }
            $upload_dir = CHAT_AUDIO_DIR;
            break;
            
        case 'file':
            if (!in_array($ext, $ALLOWED_FILE_TYPES)) {
                return ['success' => false, 'error' => 'Tipo de arquivo não permitido'];
            }
            if ($file['size'] > MAX_FILE_SIZE) {
                return ['success' => false, 'error' => 'Arquivo muito grande (máx 25MB)'];
            }
            $upload_dir = CHAT_FILES_DIR;
            break;
            
        default:
            return ['success' => false, 'error' => 'Tipo de arquivo inválido'];
    }
    
    // Gerar nome único
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    // Mover arquivo
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'error' => 'Erro ao salvar arquivo'];
    }
    
    // Retornar URL relativa
    $relative_path = 'uploads/chat/' . basename($upload_dir) . '/' . $filename;
    
    return [
        'success' => true,
        'url' => $relative_path,
        'filename' => $filename,
        'size' => $file['size'],
        'type' => $type
    ];
}

/**
 * Função para deletar arquivo
 * 
 * @param string $url - URL do arquivo
 * @return bool
 */
function deleteChatFile($url) {
    $filepath = __DIR__ . '/../' . $url;
    
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    
    return false;
}

/**
 * Função para obter informações do arquivo
 * 
 * @param string $url - URL do arquivo
 * @return array|null
 */
function getChatFileInfo($url) {
    $filepath = __DIR__ . '/../' . $url;
    
    if (!file_exists($filepath)) {
        return null;
    }
    
    return [
        'size' => filesize($filepath),
        'type' => mime_content_type($filepath),
        'modified' => filemtime($filepath)
    ];
}

/**
 * Exemplo de uso em API:
 * 
 * // api/upload_chat_file.php
 * require_once '../includes/config.php';
 * require_once '../includes/chat_upload_config.php';
 * 
 * if (isset($_FILES['file'])) {
 *     $type = $_POST['type'] ?? 'file';
 *     $result = uploadChatFile($_FILES['file'], $type);
 *     
 *     if ($result['success']) {
 *         // Salvar no banco de dados
 *         // Enviar mensagem com file_url
 *         echo json_encode(['success' => true, 'url' => $result['url']]);
 *     } else {
 *         echo json_encode(['success' => false, 'error' => $result['error']]);
 *     }
 * }
 */
?>
