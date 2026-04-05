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

        if (preg_match('/[\\\/]/', $candidate)) {
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
        $result['error'] = 'FFmpeg nao encontrado no servidor.';
        return $result;
    }

    $probe_data = video_probe_file($input_path);
    $is_mp4 = strtolower($original_extension) === 'mp4';

    if ($is_mp4 && video_is_web_compatible($probe_data)) {
        $result['success'] = true;
        $result['output_path'] = $input_path;
        $result['extension'] = 'mp4';
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
