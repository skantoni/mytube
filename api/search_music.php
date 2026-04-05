<?php
/**
 * API proxy para buscar músicas via Deezer.
 * A API pública do Deezer não requer autenticação.
 * Previews de 30s — ideal para vídeos curtos.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/music_config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado.']);
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
    case 'genre':
        handleGenre();
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

    $query = mb_substr($query, 0, 100);
    $limit = MUSIC_RESULTS_LIMIT;

    $url = DEEZER_API_URL . '/search?q=' . urlencode($query) . '&limit=' . $limit . '&order=RANKING';
    $data = fetchDeezer($url);

    if ($data === null) {
        echo json_encode(['success' => false, 'error' => 'Erro ao contactar API de música.']);
        return;
    }

    echo json_encode([
        'success' => true,
        'tracks'  => formatTracks($data['data'] ?? []),
    ]);
}

/**
 * Obter músicas populares (chart global do Deezer).
 */
function handleFeatured(): void {
    $url = DEEZER_API_URL . '/chart/0/tracks?limit=' . MUSIC_RESULTS_LIMIT;
    $data = fetchDeezer($url);

    if ($data === null) {
        echo json_encode(['success' => false, 'error' => 'Erro ao contactar API de música.']);
        return;
    }

    echo json_encode([
        'success' => true,
        'tracks'  => formatTracks($data['data'] ?? []),
    ]);
}

/**
 * Obter músicas por género/tag.
 * Usa o endpoint de busca com o nome do género.
 */
function handleGenre(): void {
    $tag = trim($_GET['tag'] ?? '');
    if ($tag === '') {
        handleFeatured();
        return;
    }

    $tag = mb_substr($tag, 0, 50);
    $limit = MUSIC_RESULTS_LIMIT;

    // Buscar por género como query — funciona bem no Deezer
    $url = DEEZER_API_URL . '/search?q=' . urlencode($tag) . '&limit=' . $limit . '&order=RANKING';
    $data = fetchDeezer($url);

    if ($data === null) {
        echo json_encode(['success' => false, 'error' => 'Erro ao contactar API de música.']);
        return;
    }

    echo json_encode([
        'success' => true,
        'tracks'  => formatTracks($data['data'] ?? []),
    ]);
}

/**
 * Faz requisição HTTP para a API Deezer.
 */
function fetchDeezer(string $url): ?array {
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
        error_log('Deezer API request failed: ' . $url);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log('Deezer API invalid JSON response');
        return null;
    }

    // Deezer retorna erro como {"error": {"type": ..., "message": ...}}
    if (isset($data['error'])) {
        error_log('Deezer API error: ' . json_encode($data['error']));
        return null;
    }

    return $data;
}

/**
 * Formata os resultados do Deezer para o frontend.
 */
function formatTracks(array $results): array {
    $tracks = [];
    foreach ($results as $track) {
        if (!is_array($track) || empty($track['id'])) {
            continue;
        }

        // Ignorar tracks sem preview
        $preview = $track['preview'] ?? '';
        if (empty($preview)) {
            continue;
        }

        $duration = (int)($track['duration'] ?? 0);
        $artist_name = $track['artist']['name'] ?? 'Artista desconhecido';
        $album_cover = $track['album']['cover_medium'] ?? $track['album']['cover'] ?? '';

        $tracks[] = [
            'id'           => (string)$track['id'],
            'name'         => $track['title_short'] ?? $track['title'] ?? 'Sem título',
            'artist'       => $artist_name,
            'duration'     => $duration,
            'duration_fmt' => sprintf('%d:%02d', intdiv($duration, 60), $duration % 60),
            'audio_url'    => $preview,              // Preview 30s para ouvir no browser
            'download_url' => $preview,              // Mesmo URL para download do preview
            'image'        => $album_cover,
        ];
    }
    return $tracks;
}
