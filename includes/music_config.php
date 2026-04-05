<?php
/**
 * Configuração da API Jamendo para músicas royalty-free.
 *
 * Para obter um client_id gratuito:
 * 1. Acesse https://devportal.jamendo.com/
 * 2. Crie uma conta e registre sua aplicação
 * 3. Copie o client_id gerado e cole abaixo
 */

// Client ID obtido no portal de desenvolvedores do Jamendo
if (!defined('JAMENDO_CLIENT_ID')) {
    define('JAMENDO_CLIENT_ID', '7ff2cd69'); // Preencher com seu client_id
}

// URL base da API Jamendo v3
if (!defined('JAMENDO_API_URL')) {
    define('JAMENDO_API_URL', 'https://api.jamendo.com/v3.0');
}

// Número máximo de resultados por busca
if (!defined('JAMENDO_RESULTS_LIMIT')) {
    define('JAMENDO_RESULTS_LIMIT', 12);
}

// Volume da música de fundo ao mixar (0.0 a 1.0)
if (!defined('MUSIC_MIX_VOLUME')) {
    define('MUSIC_MIX_VOLUME', 0.25);
}
