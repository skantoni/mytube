<?php
/**
 * API proxy para buscar músicas royalty-free do Jamendo.
 * Evita expor o client_id no frontend e resolve CORS.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/music_config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado.']);
    exit;
}

if (empty(JAMENDO_CLIENT_ID)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'API de música não configurada.']);
    exit;
}

$action = $_GET['action'] ?? 'search';

switch ($action) {
    case 'search':
        handleSearch();
        break;
    case 'featured':
        handleFeatured();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida.']);
}

/**
 * Buscar músicas por termo.
 */
function handleSearch(): void {
    $query = trim($_GET['q'] ?? '');
    if ($query === '') {
        echo json_encode(['success' => true, 'tracks' => []]);
        return;
    }

    // Limitar tamanho da query para evitar abuso
    $query = mb_substr($query, 0, 100);

    $params = [
        'client_id'    => JAMENDO_CLIENT_ID,
        'format'       => 'json',
        'limit'        => JAMENDO_RESULTS_LIMIT,
        'search'       => $query,
        'include'      => 'musicinfo',
        'audiodlformat' => 'mp32',
        'order'        => 'relevance',
    ];

    $url = JAMENDO_API_URL . '/tracks/?' . http_build_query($params);
    $data = fetchJamendo($url);

    if ($data === null) {
        echo json_encode(['success' => false, 'error' => 'Erro ao contactar API de música.']);
        return;
    }

    echo json_encode([
        'success' => true,
        'tracks'  => formatTracks($data['results'] ?? []),
    ]);
}

/**
 * Obter músicas populares/destaque.
 */
function handleFeatured(): void {
    $params = [
        'client_id'    => JAMENDO_CLIENT_ID,
        'format'       => 'json',
        'limit'        => JAMENDO_RESULTS_LIMIT,
        'order'        => 'popularity_week',
        'include'      => 'musicinfo',
        'audiodlformat' => 'mp32',
    ];

    $tag = trim($_GET['tag'] ?? '');
    if ($tag !== '') {
        $params['tags'] = mb_substr($tag, 0, 50);
    }

    $url = JAMENDO_API_URL . '/tracks/?' . http_build_query($params);
    $data = fetchJamendo($url);

    if ($data === null) {
        echo json_encode(['success' => false, 'error' => 'Erro ao contactar API de música.']);
        return;
    }

    echo json_encode([
        'success' => true,
        'tracks'  => formatTracks($data['results'] ?? []),
    ]);
}

/**
 * Faz requisição HTTP para a API Jamendo.
 */
function fetchJamendo(string $url): ?array {
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        error_log('Jamendo API request failed: ' . $url);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log('Jamendo API invalid JSON response');
        return null;
    }

    return $data;
}

/**
 * Formata os resultados para o frontend.
 * Retorna apenas os campos necessários.
 */
function formatTracks(array $results): array {
    $tracks = [];
    foreach ($results as $track) {
        if (!is_array($track) || empty($track['id'])) {
            continue;
        }

        $duration = (int)($track['duration'] ?? 0);

        $tracks[] = [
            'id'          => (string)$track['id'],
            'name'        => $track['name'] ?? 'Sem título',
            'artist'      => $track['artist_name'] ?? 'Artista desconhecido',
            'duration'    => $duration,
            'duration_fmt' => sprintf('%d:%02d', intdiv($duration, 60), $duration % 60),
            'audio_url'   => $track['audio'] ?? '',        // URL de streaming (prévia)
            'download_url'=> $track['audiodownload'] ?? '', // URL de download MP3
            'image'       => $track['image'] ?? '',
            'genre'       => $track['musicinfo']['tags']['genres'][0] ?? '',
        ];
    }
    return $tracks;
}
