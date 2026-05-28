<?php
require_once 'includes/config.php';
require_once 'includes/hashtag_helper.php';
require_once 'includes/upload_validation.php';

// Verificar se está logado
if (!isLoggedIn()) {
    if (isset($_POST['ajax_upload'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Sessão expirada. Faça login novamente.']);
        exit;
    }
    redirect('login.php');
}

$error   = '';
$success = '';
$isAjax  = isset($_POST['ajax_upload']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF token
    if (!csrf_verify()) {
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Token de segurança inválido']);
            exit;
        }
        csrf_verify_or_die();
    }

    // Buffer para evitar warnings a corromper JSON
    if ($isAjax) {
        ob_start();
    }

    // ── Validações rápidas (< 1s) ─────────────────────────────────────────────
    $title       = sanitize($_POST['title'] ?? '');
    $description = mb_substr(sanitize($_POST['description'] ?? ''), 0, 400);
    $hashtags_raw = trim((string)($_POST['hashtags'] ?? ''));
    $is_public   = isset($_POST['is_public']) ? 1 : 0;
    $ad_flow     = (isset($_POST['ad_flow']) && $_POST['ad_flow'] == '1') ? 1 : 0;

    if (empty($title)) {
        $error = 'Título é obrigatório.';
    } else {
        // Validar hashtags (rápido, só strings)
        try {
            $parsed_hashtags = hashtag_parse_input($hashtags_raw);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
            $parsed_hashtags = [];
        }
    }

    // ── Validar ficheiro enviado ─────────────────────────────────────────────
    if (!$error && (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK)) {
        $uploadError = $_FILES['video']['error'] ?? -1;
        $error = match($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande. Tamanho máximo permitido: 100MB',
            UPLOAD_ERR_PARTIAL  => 'Upload interrompido. O arquivo foi enviado parcialmente.',
            UPLOAD_ERR_NO_FILE  => 'Nenhum arquivo selecionado.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Erro no servidor ao salvar arquivo temporário.',
            default             => 'Por favor, selecione um vídeo válido.',
        };
    }

    // ── Verificar upload parcial ─────────────────────────────────────────────
    if (!$error) {
        $expected_size = isset($_POST['expected_size']) ? (int)$_POST['expected_size'] : 0;
        if ($expected_size > 0 && (int)$_FILES['video']['size'] !== $expected_size) {
            $error = 'Upload incompleto. O ficheiro foi enviado parcialmente — verifique a sua ligação e tente novamente.';
        }
    }

    // ── Validação de tipo/MIME do vídeo ─────────────────────────────────────
    if (!$error) {
        $video        = $_FILES['video'];
        $videoTmp     = $video['tmp_name'];
        $originalName = $video['name'];

        $validation = validate_video_upload(
            $videoTmp,
            $originalName,
            ['mp4', 'avi', 'mov', 'wmv', 'webm'],
            100 // 100MB
        );

        if (!$validation['valid']) {
            $error = $validation['error'];
        }
    }

    // ── Verificar que a tabela upload_jobs existe ────────────────────────────
    if (!$error) {
        try {
            $tableExists = $pdo->query("SHOW TABLES LIKE 'upload_jobs'")->fetchColumn();
            if (!$tableExists) {
                $error = 'Sistema de upload não está configurado. Contacte o administrador.';
                error_log('upload.php: tabela upload_jobs não existe. Corre worker/install_upload_jobs.php');
            }
        } catch (Throwable $e) {
            $error = 'Erro ao verificar sistema de upload.';
        }
    }

    // ── Salvar ficheiro RAW na fila ──────────────────────────────────────────
    if (!$error) {
        $raw_queue_dir = __DIR__ . '/uploads/raw_queue';
        if (!is_dir($raw_queue_dir)) {
            mkdir($raw_queue_dir, 0750, true);
        }

        $safe_ext     = $validation['extension'];
        $raw_filename = uniqid('raw_', true) . '_' . time() . '.' . $safe_ext;
        $raw_path     = $raw_queue_dir . '/' . $raw_filename;

        if (!move_uploaded_file($videoTmp, $raw_path)) {
            $error = 'Falha ao salvar o ficheiro no servidor. Tente novamente.';
        }
    }

    // ── Criar registo na BD e colocar job na fila ────────────────────────────
    if (!$error) {
        try {
            $pdo->beginTransaction();

            // Verificar/adicionar colunas de música e moderação
            $has_music_cols = false;
            try { $pdo->query("SELECT music_name FROM videos LIMIT 0"); $has_music_cols = true; } catch (Throwable $e) {
                try {
                    $pdo->exec("ALTER TABLE videos ADD COLUMN music_name VARCHAR(255) NOT NULL DEFAULT '' AFTER hashtags");
                    $pdo->exec("ALTER TABLE videos ADD COLUMN music_artist VARCHAR(255) NOT NULL DEFAULT '' AFTER music_name");
                    $has_music_cols = true;
                } catch (Throwable $e2) {}
            }

            $has_moderation_cols = false;
            try { $pdo->query("SELECT moderation_status FROM videos LIMIT 0"); $has_moderation_cols = true; } catch (Throwable $e) {
                try {
                    $pdo->exec("ALTER TABLE videos
                        ADD COLUMN moderation_status ENUM('processing','pending','approved','rejected') NOT NULL DEFAULT 'approved',
                        ADD COLUMN moderation_score FLOAT DEFAULT NULL,
                        ADD COLUMN moderation_checked_at DATETIME DEFAULT NULL");
                    $has_moderation_cols = true;
                } catch (Throwable $e2) {}
            }

            // Garantir que o ENUM tem o valor 'processing'
            if ($has_moderation_cols) {
                try {
                    $pdo->exec("ALTER TABLE videos
                        MODIFY COLUMN moderation_status ENUM('processing','pending','approved','rejected') NOT NULL DEFAULT 'approved'");
                } catch (Throwable $e) { /* já existe */ }
            }

            // Inserir vídeo com status 'processing' — visível para o utilizador
            // mas não aparece no feed até estar 'approved'
            $hashtags_formatted = hashtag_format_for_storage($parsed_hashtags);

            if ($has_music_cols && $has_moderation_cols) {
                $stmt = $pdo->prepare("
                    INSERT INTO videos (user_id, title, description, video_path, hashtags, is_public,
                                        music_name, music_artist, moderation_status, created_at)
                    VALUES (?, ?, ?, '', ?, ?, '', '', 'processing', NOW())
                ");
                $stmt->execute([$_SESSION['user_id'], $title, $description, $hashtags_formatted, $is_public]);
            } elseif ($has_moderation_cols) {
                $stmt = $pdo->prepare("
                    INSERT INTO videos (user_id, title, description, video_path, hashtags, is_public,
                                        moderation_status, created_at)
                    VALUES (?, ?, ?, '', ?, ?, 'processing', NOW())
                ");
                $stmt->execute([$_SESSION['user_id'], $title, $description, $hashtags_formatted, $is_public]);
            } elseif ($has_music_cols) {
                $stmt = $pdo->prepare("
                    INSERT INTO videos (user_id, title, description, video_path, hashtags, is_public,
                                        music_name, music_artist, created_at)
                    VALUES (?, ?, ?, '', ?, ?, '', '', NOW())
                ");
                $stmt->execute([$_SESSION['user_id'], $title, $description, $hashtags_formatted, $is_public]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO videos (user_id, title, description, video_path, hashtags, is_public, created_at)
                    VALUES (?, ?, ?, '', ?, ?, NOW())
                ");
                $stmt->execute([$_SESSION['user_id'], $title, $description, $hashtags_formatted, $is_public]);
            }

            $video_id = (int)$pdo->lastInsertId();

            // Incrementar contagem de vídeos (rollback se o job falhar fica no worker)
            $pdo->prepare("UPDATE users SET videos_count = videos_count + 1 WHERE id = ?")
                ->execute([$_SESSION['user_id']]);

            // Inserir job na fila
            $music_track_data = trim($_POST['music_track_data'] ?? '');
            $music_mode       = in_array($_POST['music_mode'] ?? '', ['mix','replace'], true)
                                ? $_POST['music_mode']
                                : 'mix';
            $music_volume     = max(5, min(100, (int)($_POST['music_volume'] ?? 25))) / 100.0;
            $music_start      = max(0.0, min(300.0, (float)($_POST['music_start'] ?? 0)));

            $pdo->prepare("
                INSERT INTO upload_jobs
                    (user_id, video_id, tmp_video_path, original_name, title, description,
                     hashtags, is_public, music_track_data, music_mode, music_volume, music_start, ad_flow,
                     status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', NOW())
            ")->execute([
                $_SESSION['user_id'],
                $video_id,
                $raw_path,
                $originalName,
                $title,
                $description,
                $hashtags_formatted,
                $is_public,
                $music_track_data ?: null,
                $music_mode,
                $music_volume,
                $music_start,
                $ad_flow,
            ]);

            $job_id = (int)$pdo->lastInsertId();
            $pdo->commit();

            // ── Tentar lançar worker imediatamente (não-bloqueante) ─────────
            $worker_script = __DIR__ . '/worker/process_upload_job.php';
            if (file_exists($worker_script) && function_exists('exec')) {
                $php_bin = PHP_BINARY ?: 'php';
                $is_win  = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
                if ($is_win) {
                    // Windows: start /B para não bloquear
                    $cmd = 'start /B "" ' . escapeshellarg($php_bin) . ' ' . escapeshellarg($worker_script) . ' > NUL 2>&1';
                    pclose(popen($cmd, 'r'));
                } else {
                    // Linux: nohup em background
                    $cmd = 'nohup ' . escapeshellarg($php_bin) . ' ' . escapeshellarg($worker_script)
                         . ' > /dev/null 2>&1 &';
                    exec($cmd);
                }
            }

        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $e2) {}
            // Limpar ficheiro RAW se a BD falhou
            if (isset($raw_path) && file_exists($raw_path)) {
                @unlink($raw_path);
            }
            $error = 'Erro interno ao iniciar o upload. Tente novamente.';
            error_log('upload.php: ' . $e->getMessage());
        }
    }

    // ── Responder AJAX ───────────────────────────────────────────────────────
    if ($isAjax) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        if ($error) {
            echo json_encode(['success' => false, 'error' => $error]);
        } else {
            echo json_encode([
                'success'  => true,
                'job_id'   => $job_id,
                'video_id' => $video_id,
                'message'  => 'Vídeo recebido! A processar em background...',
            ]);
        }
        exit;
    }

    if (!$error) {
        $success = 'Vídeo recebido! Está a ser processado e estará disponível em breve.';
        header('refresh:3;url=index.php');
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload de Vídeo - MyTube</title>
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/upload.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/music-picker.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Ajuste para remover o espaço do header */
        .main-content {
            padding-top: 20px !important;
            margin-top: 0 !important;
        }
        
        /* Botão de voltar customizado */
        .back-button {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.1);
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 12px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            font-size: 1.1rem;
        }
        
        .back-button:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        /* Responsividade para mobile */
        @media (max-width: 768px) {
            .back-button {
                position: fixed;
                top: 15px;
                left: 15px;
                z-index: 9999;
                padding: 10px;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
                backdrop-filter: blur(20px);
                border: 1px solid rgba(255, 255, 255, 0.3);
                font-size: 1rem;
            }
            
            .back-button:hover {
                transform: none;
                background: rgba(59, 130, 246, 1);
            }
            
            .main-content {
                padding-top: 70px !important;
            }
        }
        
        @media (max-width: 480px) {
            .back-button {
                top: 10px;
                left: 10px;
                padding: 8px;
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }
        }
    </style>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
</head>
<body>
    <!-- Botão de voltar sem menu -->
    <button onclick="history.back()" class="back-button" title="Voltar">
        <i class="fas fa-arrow-left"></i>
    </button>
    
    <div class="main-content">
        <div class="upload-container">
            <div class="upload-card">
                <div class="upload-header">
                    <i class="fas fa-video upload-icon"></i>
                    <h2>Compartilhar Vídeo</h2>
                    <p>Faça upload do seu vídeo e compartilhe com o mundo!</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" class="upload-form" id="uploadForm">
                    <!-- Área de drop de arquivo -->
                    <div class="file-drop-area" id="fileDropArea">
                        <input type="file" name="video" id="videoInput" accept="video/*">
                        <div class="file-drop-content">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h3>Arraste seu vídeo aqui</h3>
                            <p>ou clique para selecionar</p>
                            <div class="file-size-limit">
                                <i class="fas fa-info-circle"></i>
                                Formatos: MP4, AVI, MOV, WMV, WebM &bull; Tamanho máximo: <strong>100MB</strong>
                            </div>
                        </div>
                        <div class="file-info" id="fileInfo" style="display: none;">
                            <i class="fas fa-file-video"></i>
                            <span class="file-name"></span>
                            <span class="file-size"></span>
                        </div>
                    </div>
                    
                    <!-- Preview do vídeo -->
                    <div class="video-preview" id="videoPreview" style="display: none;">
                        <video controls></video>
                        <button type="button" class="remove-video" onclick="removeFile()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Campos do formulário -->
                    <div class="form-fields">
                        <div class="form-group">
                            <label for="title" class="form-label">
                                <i class="fas fa-heading"></i>
                                Título do Vídeo
                            </label>
                            <input type="text" name="title" id="title" class="form-input" 
                                   placeholder="Dê um título chamativo ao seu vídeo..." 
                                   maxlength="255" required>
                            <div class="char-count">
                                <span id="titleCount">0</span>/255
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="form-label">
                                <i class="fas fa-align-left"></i>
                                Descrição
                            </label>
                            <textarea name="description" id="description" class="form-textarea" 
                                      placeholder="Descreva seu vídeo (opcional)..." 
                                      maxlength="400"></textarea>
                            <div class="char-count">
                                <span id="descCount">0</span>/400
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="hashtags" class="form-label">
                                <i class="fas fa-hashtag"></i>
                                Hashtags
                            </label>
                            <div class="hashtag-input-wrapper">
                                <input type="text" name="hashtags" id="hashtags" class="form-input" 
                                       placeholder="#escola #viral #mytube (máx 4)" autocomplete="off" autocapitalize="off" spellcheck="false">
                                <div id="hashtagsSuggestions" class="hashtags-suggestions" ></div>
                            </div>
                            <small class="form-hint">
                                Máximo 4 hashtags por vídeo, até 20 caracteres cada, apenas letras e números.
                                Separe por espaço e não use espaço dentro da hashtag. Ex: #diversao #viral #mytube
                            </small>
                        </div>

                        <!-- Música de Fundo (Royalty-Free) -->
                        <div class="form-group">
                            <label class="music-toggle-group" for="musicToggle">
                                <div class="music-toggle-left">
                                    <i class="fas fa-music"></i>
                                    <div>
                                        <span>Adicionar Música de Fundo</span>
                                        <small>Músicas royalty-free via Deezer</small>
                                    </div>
                                </div>
                                <div class="music-switch">
                                    <input type="checkbox" id="musicToggle">
                                    <span class="music-switch-slider"></span>
                                </div>
                            </label>

                            <div class="music-panel" id="musicPanel">
                                <div class="music-search-bar">
                                    <i class="fas fa-search"></i>
                                    <input type="text" id="musicSearch" placeholder="Buscar músicas... ex: happy, chill, energetic" autocomplete="off">
                                </div>

                                <div class="music-tags">
                                    <button type="button" class="music-tag-btn" data-tag="afrobeat">Afrobeat</button>
                                    <button type="button" class="music-tag-btn" data-tag="kizomba">Kizomba</button>
                                    <button type="button" class="music-tag-btn" data-tag="kuduro">Kuduro</button>
                                    <button type="button" class="music-tag-btn" data-tag="funk">Funk</button>
                                    <button type="button" class="music-tag-btn" data-tag="pop">Pop</button>
                                    <button type="button" class="music-tag-btn" data-tag="hip hop">Hip Hop</button>
                                    <button type="button" class="music-tag-btn" data-tag="reggaeton">Reggaeton</button>
                                    <button type="button" class="music-tag-btn" data-tag="electronic">Electronic</button>
                                </div>

                                <div class="music-results" id="musicResults"></div>

                                <div class="music-selected" id="musicSelected"></div>

                                <div class="music-options">
                                    <div class="music-option-group">
                                        <label for="musicMode"><i class="fas fa-sliders-h"></i> Modo:</label>
                                        <select id="musicMode" name="music_mode">
                                            <option value="mix">Mixar com áudio original</option>
                                            <option value="replace">Substituir áudio</option>
                                        </select>
                                    </div>
                                    <div class="music-option-group">
                                        <label><i class="fas fa-volume-up"></i> Volume:</label>
                                        <input type="range" id="musicVolume" name="music_volume" class="music-volume-slider" min="5" max="100" value="25">
                                        <span class="music-volume-label" id="musicVolumeLabel">25%</span>
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" name="music_track_data" id="musicTrackData" value="">
                            <input type="hidden" name="music_start" id="musicStartOffset" value="0">
                        </div>
                        
                        <div class="form-group" style="display: none;">
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_public" id="isPublic" checked>
                                <label for="isPublic" class="checkbox-label">
                                    <i class="fas fa-globe"></i>
                                    Tornar vídeo público
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Progresso do upload -->
                    <div class="upload-progress" id="uploadProgress" style="display: none;"></div>
                    
                    <!-- Botões -->
                    <div class="form-actions">
                        <?php echo csrf_field(); ?>
                        <?php if (isset($_GET['ad_flow']) && $_GET['ad_flow'] == '1'): ?>
                            <input type="hidden" name="ad_flow" value="1">
                        <?php endif; ?>
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php'">
                            <i class="fas fa-arrow-left"></i>
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-upload"></i>
                            Publicar Vídeo
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Dicas -->
            <div class="upload-tips">
                <h3><i class="fas fa-lightbulb"></i> Dicas para um bom vídeo:</h3>
                <ul>
                    <li>Use títulos chamativos e descritivos</li>
                    <li>Adicione hashtags relevantes</li>
                    <li>Mantenha vídeos entre 15-60 segundos</li>
                    <li>Grave em boa qualidade e iluminação</li>
                    <li>Seja autêntico e criativo!</li>
                </ul>
            </div>
        </div>
    </div>
    
    <script src="<?php echo asset('assets/js/upload.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/music-picker.js'); ?>"></script>
    <?php include 'includes/presence_bootstrap.php'; ?>
</body>
</html>