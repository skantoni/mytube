<?php
/**
 * Configuração da API Deezer para músicas.
 *
 * A API pública do Deezer não requer autenticação.
 * Disponibiliza previews de 30 segundos de milhões de músicas.
 * https://developers.deezer.com/api
 */

// URL base da API Deezer
if (!defined('DEEZER_API_URL')) {
    define('DEEZER_API_URL', 'https://api.deezer.com');
}

// Número máximo de resultados por busca
if (!defined('MUSIC_RESULTS_LIMIT')) {
    define('MUSIC_RESULTS_LIMIT', 15);
}

// Volume da música de fundo ao mixar (0.0 a 1.0)
if (!defined('MUSIC_MIX_VOLUME')) {
    define('MUSIC_MIX_VOLUME', 0.25);
}
