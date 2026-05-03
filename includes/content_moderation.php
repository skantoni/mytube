<?php
/**
 * Helper de Moderação de Conteúdo — MyTube
 *
 * Usa NudeNet (Python) para detectar conteúdo 18+ em vídeos.
 * Se NudeNet não estiver disponível, o vídeo fica em fila de revisão manual.
 *
 * Fluxo:
 *   1. moderation_analyze_local_file($path) → analisa ficheiro local antes do upload para R2
 *   2. Se NSFW  → rejeitar upload imediatamente (não sobe para R2)
 *   3. Se limpo → upload normal com moderation_status = 'approved'
 *   4. Se NudeNet indisponível → upload com moderation_status = 'pending' (revisão manual)
 */

define('MODERATION_SCRIPT_PATH', __DIR__ . '/../moderation/analyze_video.py');

/** Timeout em segundos para a chamada ao Python (frame extraction + NudeNet). */
if (!defined('MODERATION_TIMEOUT')) {
    define('MODERATION_TIMEOUT', 90);
}

// ──────────────────────────────────────────────────────────────
//  Detecção de ambiente
// ──────────────────────────────────────────────────────────────

/**
 * Devolve o binário Python3 disponível no sistema, ou null se não encontrar.
 */
function moderation_get_python(): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached === '' ? null : $cached;
    }

    if (!function_exists('exec')) {
        $cached = '';
        return null;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        $cached = '';
        return null;
    }

    $is_win = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    // Procurar primeiro no venv isolado (instalação recomendada na VPS)
    $venv_python = __DIR__ . '/../moderation/venv/bin/python3';
    $candidates = [
        $venv_python,                   // venv isolado (VPS)
        'python3',
        'python',
        '/usr/bin/python3',
        '/usr/local/bin/python3',
    ];

    foreach ($candidates as $candidate) {
        $check   = $is_win
            ? 'where ' . escapeshellarg($candidate) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($candidate) . ' 2>/dev/null';
        $out  = [];
        $code = 1;
        exec($check, $out, $code);
        if ($code === 0 && !empty($out[0])) {
            $cached = trim($out[0]);
            return $cached;
        }
    }

    $cached = '';
    return null;
}

/**
 * Verifica se NudeNet está instalado no Python disponível.
 * O resultado é guardado em cache por sessão PHP (custo: apenas 1 exec por processo).
 */
function moderation_is_nudenet_available(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $python = moderation_get_python();
    if (!$python) {
        return $cached = false;
    }

    if (!file_exists(MODERATION_SCRIPT_PATH)) {
        return $cached = false;
    }

    $cmd    = sprintf('%s -c "from nudenet import NudeDetector" 2>&1', escapeshellarg($python));
    $out    = [];
    $code   = 1;
    exec($cmd, $out, $code);

    return $cached = ($code === 0);
}

// ──────────────────────────────────────────────────────────────
//  Análise de vídeo
// ──────────────────────────────────────────────────────────────

/**
 * Analisa um ficheiro de vídeo local com NudeNet.
 *
 * @param string $video_path Caminho absoluto para o ficheiro de vídeo local.
 * @return array {
 *   status:  'clean' | 'nsfw' | 'error' | 'unavailable'
 *   score:   float  (0.0 – 1.0)
 *   frames:  int
 *   details: array  (deteções individuais)
 *   error:   string|null
 * }
 */
function moderation_analyze_local_file(string $video_path): array
{
    $empty = ['status' => '', 'score' => 0.0, 'frames' => 0, 'details' => [], 'error' => null];

    if (!file_exists($video_path)) {
        return array_merge($empty, ['status' => 'error', 'error' => 'Ficheiro não encontrado']);
    }

    $python = moderation_get_python();
    if (!$python) {
        return array_merge($empty, ['status' => 'unavailable', 'error' => 'Python3 não encontrado']);
    }

    if (!file_exists(MODERATION_SCRIPT_PATH)) {
        return array_merge($empty, ['status' => 'unavailable', 'error' => 'Script de moderação não encontrado']);
    }

    $is_win = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    // Capturar stderr para diagnóstico em vez de descartar silenciosamente
    $stderr_file = tempnam(sys_get_temp_dir(), 'mytube_mod_err_');
    $redirect = $is_win ? ' 2>NUL' : ' 2>' . escapeshellarg($stderr_file);

    $cmd = sprintf(
        '%s %s %s%s',
        escapeshellarg($python),
        escapeshellarg(MODERATION_SCRIPT_PATH),
        escapeshellarg($video_path),
        $redirect
    );

    // Aplicar timeout via ulimit (Linux) ou apenas exec directo
    if (!$is_win) {
        $cmd = sprintf('timeout %d %s', MODERATION_TIMEOUT, $cmd);
    }

    $output   = [];
    $exit_code = 1;
    exec($cmd, $output, $exit_code);

    // Logar stderr se houver conteúdo (erros do Python, ffmpeg, etc.)
    if (file_exists($stderr_file)) {
        $stderr_content = trim(file_get_contents($stderr_file));
        @unlink($stderr_file);
        if ($stderr_content !== '') {
            error_log("moderation stderr (exit=$exit_code): " . mb_substr($stderr_content, 0, 500));
        }
    }

    $json_str = trim(implode("\n", $output));

    // Código 2 = NudeNet não instalado
    if ($exit_code === 2 || $json_str === '') {
        return array_merge($empty, [
            'status' => 'unavailable',
            'error'  => 'NudeNet não está instalado. Execute: bash moderation/install.sh',
        ]);
    }

    $data = json_decode($json_str, true);
    if (!is_array($data)) {
        error_log("moderation: JSON inválido do script: $json_str");
        return array_merge($empty, ['status' => 'error', 'error' => 'Resposta inválida do analisador']);
    }

    $status = (string)($data['status'] ?? 'error');

    return [
        'status'  => $status,
        'score'   => (float)($data['score'] ?? 0.0),
        'frames'  => (int)($data['frames_analyzed'] ?? 0),
        'details' => (array)($data['detections'] ?? []),
        'error'   => $data['error'] ?? null,
    ];
}

// ──────────────────────────────────────────────────────────────
//  Decisão de estado para novos uploads
// ──────────────────────────────────────────────────────────────

/**
 * Determina o moderation_status inicial para um vídeo recém-carregado.
 *
 * Chamado ANTES do upload para R2 — o ficheiro ainda existe localmente.
 *
 * @param string $video_path Caminho local do vídeo processado.
 * @return array {
 *   db_status:  'approved' | 'pending' | 'rejected'
 *   score:      float|null
 *   log:        string  (para error_log)
 *   reject_msg: string|null  (mensagem para mostrar ao utilizador, só quando rejected)
 * }
 */
function moderation_decide_status(string $video_path): array
{
    $result = moderation_analyze_local_file($video_path);

    // Em desenvolvimento sem NudeNet disponível → auto-aprovar (não bloquear o trabalho local)
    // Em produção sem NudeNet → fila de revisão manual (comportamento seguro)
    $is_dev = (function_exists('env') ? env('APP_ENV', 'development') : (getenv('APP_ENV') ?: 'development')) !== 'production';

    switch ($result['status']) {
        case 'nsfw':
            return [
                'db_status'  => 'rejected',
                'score'      => $result['score'],
                'log'        => sprintf(
                    'NSFW detetado (score=%.3f, %d deteções)',
                    $result['score'],
                    count($result['details'])
                ),
                'reject_msg' => 'O vídeo contém conteúdo inapropriado e não pode ser publicado.',
            ];

        case 'clean':
            return [
                'db_status'  => 'approved',
                'score'      => $result['score'],
                'log'        => sprintf('Conteúdo aprovado (score=%.3f, %d frames)', $result['score'], $result['frames']),
                'reject_msg' => null,
            ];

        case 'unavailable':
            // NudeNet não disponível:
            //   - desenvolvimento → auto-aprovar (não bloquear trabalho local)
            //   - produção        → fila de revisão manual (comportamento seguro)
            if ($is_dev) {
                return [
                    'db_status'  => 'approved',
                    'score'      => null,
                    'log'        => 'NudeNet indisponível em ambiente dev — vídeo auto-aprovado.',
                    'reject_msg' => null,
                ];
            }
            return [
                'db_status'  => 'pending',
                'score'      => null,
                'log'        => 'NudeNet indisponível — vídeo em revisão manual: ' . ($result['error'] ?? ''),
                'reject_msg' => null,
            ];

        default: // 'error'
            if ($is_dev) {
                return [
                    'db_status'  => 'approved',
                    'score'      => null,
                    'log'        => 'Erro na análise (dev) — vídeo auto-aprovado: ' . ($result['error'] ?? 'desconhecido'),
                    'reject_msg' => null,
                ];
            }
            return [
                'db_status'  => 'pending',
                'score'      => null,
                'log'        => 'Erro na análise: ' . ($result['error'] ?? 'desconhecido'),
                'reject_msg' => null,
            ];
    }
}

// ──────────────────────────────────────────────────────────────
//  Atualização na base de dados
// ──────────────────────────────────────────────────────────────

/**
 * Atualiza o moderation_status de um vídeo na base de dados.
 */
function moderation_update_video_status(
    $pdo,
    int    $video_id,
    string $status,
    ?float $score
): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE videos
               SET moderation_status     = ?,
                   moderation_score      = ?,
                   moderation_checked_at = NOW()
             WHERE id = ?
        ");
        return $stmt->execute([$status, $score, $video_id]);
    } catch (Throwable $e) {
        error_log("moderation_update_video_status: " . $e->getMessage());
        return false;
    }
}
