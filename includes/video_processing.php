<?php
/**
 * Helpers de processamento de video para compatibilidade web.
 *
 * Fluxo principal:
 * - Detecta codecs com ffprobe
 * - Se necessario, transcodifica para MP4 (H.264 + AAC) com ffmpeg
 */

if (!defined('VIDEO_TRANSCODE_ENABLED')) {
    define('VIDEO_TRANSCODE_ENABLED', true);
}

if (!defined('VIDEO_TRANSCODE_PRESET')) {
    define('VIDEO_TRANSCODE_PRESET', 'veryfast');
}

if (!defined('VIDEO_TRANSCODE_CRF')) {
    define('VIDEO_TRANSCODE_CRF', 23);
}

// Quando true, se o FFmpeg não for encontrado o vídeo é enviado sem transcodificação
// (em vez de retornar erro). Útil para desenvolvimento local sem FFmpeg instalado.
// Controlado pelo APP_ENV no .env:
//   APP_ENV=development  → skip FFmpeg (sem erro)
//   APP_ENV=production   → exige FFmpeg (comportamento normal em produção)
if (!defined('VIDEO_TRANSCODE_SKIP_IF_NO_FFMPEG')) {
    // Usa a função env() do config.php se disponível, caso contrário lê direto do getenv()
    $__app_env = function_exists('env')
        ? env('APP_ENV', 'development')
        : (getenv('APP_ENV') ?: 'development');
    define('VIDEO_TRANSCODE_SKIP_IF_NO_FFMPEG', $__app_env !== 'production');
    unset($__app_env);
}

/**
 * Verifica se o exec esta disponivel no PHP.
 */
function video_exec_available(): bool {
    if (!function_exists('exec')) {
        return false;
    }

    $disabled = (string)ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }

    $disabled_functions = array_map('trim', explode(',', $disabled));
    return !in_array('exec', $disabled_functions, true);
}

/**
 * Resolve caminho de um binario do sistema.
 */
function video_resolve_binary(array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
            if (file_exists($candidate) && is_file($candidate)) {
                return $candidate;
            }
            continue;
        }

        if (!video_exec_available()) {
            continue;
        }

        $command = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? 'where ' . escapeshellarg($candidate) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($candidate) . ' 2>/dev/null';

        $output = [];
        $exit_code = 1;
        exec($command, $output, $exit_code);

        if ($exit_code === 0 && !empty($output[0])) {
            return trim((string)$output[0]);
        }
    }

    return null;
}

function video_get_ffmpeg_binary(): ?string {
    $candidates = [];

    if (defined('FFMPEG_PATH') && FFMPEG_PATH) {
        $candidates[] = FFMPEG_PATH;
    }

    $candidates = array_merge($candidates, [
        'ffmpeg',
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
    ]);

    return video_resolve_binary($candidates);
}

function video_get_ffprobe_binary(): ?string {
    $candidates = [];

    if (defined('FFMPEG_PATH') && FFMPEG_PATH) {
        $path = (string)FFMPEG_PATH;
        $candidates[] = str_ends_with(strtolower($path), 'ffmpeg.exe')
            ? str_replace('ffmpeg.exe', 'ffprobe.exe', $path)
            : str_replace('ffmpeg', 'ffprobe', $path);
    }

    $candidates = array_merge($candidates, [
        'ffprobe',
        '/usr/bin/ffprobe',
        '/usr/local/bin/ffprobe',
        'C:\\ffmpeg\\bin\\ffprobe.exe',
    ]);

    return video_resolve_binary($candidates);
}

/**
 * Lanca ffprobe e retorna metadados basicos do ficheiro.
 */
function video_probe_file(string $file_path): ?array {
    if (!video_exec_available()) {
        return null;
    }

    $ffprobe = video_get_ffprobe_binary();
    if (!$ffprobe) {
        return null;
    }

    $command = sprintf(
        '%s -v error -print_format json -show_streams -show_format %s 2>&1',
        escapeshellarg($ffprobe),
        escapeshellarg($file_path)
    );

    $output = [];
    $exit_code = 1;
    exec($command, $output, $exit_code);

    if ($exit_code !== 0) {
        return null;
    }

    $json = trim(implode("\n", $output));
    $data = json_decode($json, true);

    return is_array($data) ? $data : null;
}

/**
 * Verifica se o ficheiro ja esta em formato amplamente compativel.
 */
function video_is_web_compatible(?array $probe_data): bool {
    if (!$probe_data || empty($probe_data['streams']) || !is_array($probe_data['streams'])) {
        return false;
    }

    $video_codec = null;
    $audio_codec = null;

    foreach ($probe_data['streams'] as $stream) {
        if (!is_array($stream) || empty($stream['codec_type'])) {
            continue;
        }

        if ($stream['codec_type'] === 'video' && !empty($stream['codec_name'])) {
            $video_codec = strtolower((string)$stream['codec_name']);
        }

        if ($stream['codec_type'] === 'audio' && !empty($stream['codec_name'])) {
            $audio_codec = strtolower((string)$stream['codec_name']);
        }
    }

    return $video_codec === 'h264' && ($audio_codec === null || $audio_codec === 'aac');
}

/**
 * Processa o video para formato web-friendly.
 *
 * Retorna:
 * - success: bool
 * - output_path: string|null
 * - extension: string (extensao final para armazenamento)
 * - transcoded: bool
 * - error: string|null
 */
function video_prepare_for_storage(string $input_path, string $original_extension): array {
    $result = [
        'success' => false,
        'output_path' => null,
        'extension' => strtolower($original_extension),
        'transcoded' => false,
        'error' => null,
    ];

    if (!VIDEO_TRANSCODE_ENABLED) {
        $result['success'] = true;
        $result['output_path'] = $input_path;
        return $result;
    }

    if (!video_exec_available()) {
        $result['error'] = 'Processamento de video indisponivel no servidor (exec desativado).';
        return $result;
    }

    $ffmpeg = video_get_ffmpeg_binary();
    if (!$ffmpeg) {
        if (VIDEO_TRANSCODE_SKIP_IF_NO_FFMPEG) {
            // Ambiente local sem FFmpeg: passar o vídeo sem transcodificação
            error_log('video_processing: FFmpeg não encontrado — a enviar vídeo original sem transcodificação (modo desenvolvimento).');
            $result['success'] = true;
            $result['output_path'] = $input_path;
            return $result;
        }
        $result['error'] = 'FFmpeg nao encontrado no servidor.';
        return $result;
    }

    $probe_data = video_probe_file($input_path);
    $is_mp4 = strtolower($original_extension) === 'mp4';

    if ($is_mp4 && video_is_web_compatible($probe_data)) {
        // Vídeo já é H.264+AAC — apenas garantir que o moov atom está no início
        // (faststart). Usa -c copy: sem re-codificação, operação quase instantânea.
        $faststart_output = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . uniqid('mytube_fs_', true)
            . '.mp4';

        $faststart_cmd = sprintf(
            '%s -y -i %s -c copy -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($input_path),
            escapeshellarg($faststart_output)
        );

        $fs_output = [];
        $fs_exit   = 1;
        exec($faststart_cmd, $fs_output, $fs_exit);

        if ($fs_exit === 0 && file_exists($faststart_output) && filesize($faststart_output) > 0) {
            $result['success']     = true;
            $result['output_path'] = $faststart_output;
            $result['extension']   = 'mp4';
            $result['transcoded']  = false; // não re-codificado, só reposicionado
            return $result;
        }

        // Se o faststart falhou (raro), servir o ficheiro original sem falhar
        if (file_exists($faststart_output)) {
            @unlink($faststart_output);
        }
        error_log('video_processing: faststart falhou, a usar ficheiro original.');
        $result['success']     = true;
        $result['output_path'] = $input_path;
        $result['extension']   = 'mp4';
        return $result;
    }

    $output_path = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . uniqid('mytube_h264_', true)
        . '.mp4';

    $command = sprintf(
        '%s -y -i %s -map 0:v:0 -map 0:a:0? -c:v libx264 -preset %s -crf %d -pix_fmt yuv420p -profile:v main -movflags +faststart -c:a aac -b:a 128k -ar 48000 %s 2>&1',
        escapeshellarg($ffmpeg),
        escapeshellarg($input_path),
        escapeshellarg(VIDEO_TRANSCODE_PRESET),
        (int)VIDEO_TRANSCODE_CRF,
        escapeshellarg($output_path)
    );

    $output = [];
    $exit_code = 1;
    exec($command, $output, $exit_code);

    if ($exit_code !== 0 || !file_exists($output_path) || filesize($output_path) === 0) {
        if (file_exists($output_path)) {
            @unlink($output_path);
        }
        $result['error'] = 'Falha ao transcodificar video para formato compativel.';
        return $result;
    }

    $result['success'] = true;
    $result['output_path'] = $output_path;
    $result['extension'] = 'mp4';
    $result['transcoded'] = true;

    return $result;
}

/**
 * Faz download de um ficheiro de audio a partir de uma URL segura (Deezer CDN).
 * Usa cURL como método principal (mais fiável que file_get_contents para HTTPS).
 * Retorna o caminho do ficheiro temporario ou null em caso de erro.
 */
function video_download_music(string $url): ?string {
    require_once __DIR__ . '/ssrf_protection.php';
    
    // Download seguro com proteção SSRF
    $result = ssrf_safe_download(
        $url,
        ['dzcdn.net', 'deezer.com'],  // Apenas domínios Deezer permitidos
        10,  // 10MB máximo
        30   // 30 segundos timeout
    );
    
    if (!$result['success']) {
        error_log('video_download_music: ' . $result['error'] . ' — URL: ' . $url);
        return null;
    }
    
    // Renomear arquivo para .mp3
    $mp3_path = str_replace('.tmp', '.mp3', $result['path']);
    rename($result['path'], $mp3_path);
    
    error_log('video_download_music: OK — ' . $result['size'] . ' bytes — ' . $mp3_path);
    return $mp3_path;
}

/**
 * Combina video com uma faixa de audio de fundo usando FFmpeg.
 *
 * @param string $video_path    Caminho do video processado
 * @param string $music_path    Caminho do ficheiro MP3
 * @param string $mode          'mix' (mistura com audio original) ou 'replace' (substitui audio)
 * @param float  $music_volume  Volume da musica (0.0-1.0), usado no modo 'mix'
 * @param float  $start_offset  Segundo onde a musica começa (ex: 5.30 = começa aos 5.3s)
 * @return array{success: bool, output_path: ?string, error: ?string}
 */
function video_merge_music(string $video_path, string $music_path, string $mode = 'mix', float $music_volume = 0.25, float $start_offset = 0.0): array {
    $result = ['success' => false, 'output_path' => null, 'error' => null];

    if (!video_exec_available()) {
        $result['error'] = 'exec não disponível para processamento de áudio.';
        return $result;
    }

    $ffmpeg = video_get_ffmpeg_binary();
    if (!$ffmpeg) {
        if (VIDEO_TRANSCODE_SKIP_IF_NO_FFMPEG) {
            // Ambiente local sem FFmpeg: ignorar música de fundo silenciosamente
            error_log('video_processing: FFmpeg não encontrado — música de fundo ignorada (modo desenvolvimento).');
            $result['success'] = true;
            $result['output_path'] = $video_path;
            return $result;
        }
        $result['error'] = 'FFmpeg não encontrado para merge de áudio.';
        return $result;
    }

    $output_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('mytube_merged_', true) . '.mp4';

    // Verificar se o video original tem audio
    $probe = video_probe_file($video_path);
    $has_audio = false;
    if ($probe && !empty($probe['streams'])) {
        foreach ($probe['streams'] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                $has_audio = true;
                break;
            }
        }
    }

    $music_volume = max(0.05, min(1.0, $music_volume));
    $start_offset = max(0.0, $start_offset);
    $ss_arg = $start_offset > 0.01
        ? sprintf('-ss %s', escapeshellarg(number_format($start_offset, 2, '.', '')))
        : '';

    // IMPORTANTE: -stream_loop e -ss são input options — devem vir ANTES do -i a que se aplicam
    // -stream_loop -1 : repete a musica infinitamente, -shortest corta quando o video acaba
    if ($mode === 'replace' || !$has_audio) {
        // Substituir audio ou video sem audio: usar faixa de musica directamente
        $command = sprintf(
            '%s -y -i %s -stream_loop -1 %s -i %s -map 0:v:0 -map 1:a:0 -c:v copy -c:a aac -b:a 128k -ar 48000 -shortest -fflags +shortest -max_interleave_delta 100M %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($video_path),
            $ss_arg,
            escapeshellarg($music_path),
            escapeshellarg($output_path)
        );
    } else {
        // Modo mix: misturar audio original com musica de fundo em loop
        $vol = number_format($music_volume, 2, '.', '');
        $command = sprintf(
            '%s -y -i %s -stream_loop -1 %s -i %s -filter_complex "[1:a]volume=%s[music];[0:a][music]amix=inputs=2:duration=first:dropout_transition=2[out]" -map 0:v:0 -map "[out]" -c:v copy -c:a aac -b:a 128k -ar 48000 -shortest -fflags +shortest -max_interleave_delta 100M %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($video_path),
            $ss_arg,
            escapeshellarg($music_path),
            $vol,
            escapeshellarg($output_path)
        );
    }

    error_log('video_merge_music: command = ' . $command);

    $output = [];
    $exit_code = 1;
    exec($command, $output, $exit_code);

    if ($exit_code !== 0 || !file_exists($output_path) || filesize($output_path) === 0) {
        if (file_exists($output_path)) {
            @unlink($output_path);
        }
        $result['error'] = 'Falha ao adicionar música de fundo ao vídeo (FFmpeg exit ' . $exit_code . ').';
        error_log('FFmpeg music merge failed (exit ' . $exit_code . '): ' . implode("\n", array_slice($output, -10)));
        return $result;
    }

    $result['success'] = true;
    $result['output_path'] = $output_path;
    return $result;
}

/**
 * Move um ficheiro para o destino final.
 * Suporta temp de upload e temp gerado por transcodificacao.
 */
function video_move_to_storage(string $source_path, string $destination_path): bool {
    if (is_uploaded_file($source_path)) {
        return move_uploaded_file($source_path, $destination_path);
    }

    if (@rename($source_path, $destination_path)) {
        return true;
    }

    if (@copy($source_path, $destination_path)) {
        @unlink($source_path);
        return true;
    }

    return false;
}
