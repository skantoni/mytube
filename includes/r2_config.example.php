<?php
/**
 * Configuração do Cloudflare R2 Storage
 * 
 * Copie este ficheiro para r2_config.php e preencha com as suas credenciais:
 *   cp r2_config.example.php r2_config.php
 * 
 * Para obter as credenciais:
 * 1. Acesse o dashboard da Cloudflare: https://dash.cloudflare.com
 * 2. Vá em R2 > Manage R2 API Tokens
 * 3. Crie um novo token com permissões de leitura/escrita
 */

// ============================================
// CREDENCIAIS CLOUDFLARE R2
// ============================================
define('R2_ACCESS_KEY_ID', 'SUA_ACCESS_KEY_AQUI');
define('R2_SECRET_ACCESS_KEY', 'SUA_SECRET_KEY_AQUI');
define('R2_ACCOUNT_ID', 'SEU_ACCOUNT_ID_AQUI');
define('R2_BUCKET_NAME', 'mytube-videos');

// ============================================
// ENDPOINTS
// ============================================
// Endpoint S3 API (para uploads/deletes via SDK)
define('R2_ENDPOINT', 'https://' . R2_ACCOUNT_ID . '.r2.cloudflarestorage.com');

// URL pública do bucket (para servir vídeos aos utilizadores)
define('R2_PUBLIC_URL', 'https://SUA_URL_PUBLICA.r2.dev');

// ============================================
// CONFIGURAÇÕES DE ARMAZENAMENTO
// ============================================
// Activar/desactivar o R2 (false = usar armazenamento local)
define('R2_ENABLED', false);

// Prefixo para identificar vídeos armazenados no R2 no banco de dados
define('R2_PATH_PREFIX', 'r2://');

// Pasta dentro do bucket onde os vídeos serão guardados
define('R2_VIDEO_FOLDER', 'videos/');

// Região (R2 usa 'auto' por padrão)
define('R2_REGION', 'auto');
?>
