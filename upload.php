<?php
require_once 'includes/config.php';
require_once 'includes/ranking_cache.php';
require_once 'includes/hashtag_helper.php';
require_once 'includes/r2_storage.php';

// Verificar se está logado
if (!isLoggedIn()) {
    if (isset($_POST['ajax_upload'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Sessão expirada. Faça login novamente.']);
        exit;
    }
    redirect('login.php');
}

$error = '';
$success = '';
$isAjax = isset($_POST['ajax_upload']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = sanitize($_POST['title']);
    $description = mb_substr(sanitize($_POST['description']), 0, 400);
    $hashtags_input = isset($_POST['hashtags']) ? trim((string)$_POST['hashtags']) : '';
    $parsed_hashtags = [];
    $hashtags = '';
    $is_public = isset($_POST['is_public']) ? 1 : 0;

    if (empty($title)) {
        $error = 'Título é obrigatório.';
    } else {
        try {
            $parsed_hashtags = hashtag_parse_input($hashtags_input);
            $hashtags = hashtag_format_for_storage($parsed_hashtags);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }

    if (!$error && (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK)) {
        $uploadError = isset($_FILES['video']) ? $_FILES['video']['error'] : -1;
        switch ($uploadError) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error = 'Arquivo muito grande. Tamanho máximo permitido: 100MB';
                break;
            case UPLOAD_ERR_PARTIAL:
                $error = 'Upload interrompido. O arquivo foi enviado parcialmente.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $error = 'Nenhum arquivo selecionado.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                $error = 'Erro no servidor ao salvar arquivo temporário.';
                break;
            default:
                $error = 'Por favor, selecione um vídeo válido.';
        }
    } elseif (!$error) {
        $video = $_FILES['video'];
        $videoName = $video['name'];
        $videoSize = $video['size'];
        $videoTmp = $video['tmp_name'];
        $videoType = strtolower(pathinfo($videoName, PATHINFO_EXTENSION));

        $allowedTypes = ['mp4', 'avi', 'mov', 'wmv', 'webm'];
        if (!in_array($videoType, $allowedTypes)) {
            $error = 'Tipo de arquivo não permitido. Use: MP4, AVI, MOV, WMV, WebM';
        } elseif ($videoSize > 100 * 1024 * 1024) {
            $error = 'Arquivo muito grande. Tamanho máximo: 100MB';
        } else {
            $uniqueName = uniqid() . '_' . time() . '.' . $videoType;
            
            // ============================================
            // UPLOAD PARA CLOUDFLARE R2 OU LOCAL
            // ============================================
            $upload_success = false;
            $db_video_path = $uniqueName; // valor padrão (local)
            $local_path = null;
            
            if (R2_ENABLED) {
                // Tentar upload para R2 directamente do ficheiro temporário
                $mime_type = r2_get_mime_type($videoType);
                $r2_result = r2_upload_video($videoTmp, $uniqueName, $mime_type);
                
                if ($r2_result['success']) {
                    $upload_success = true;
                    $db_video_path = R2_PATH_PREFIX . $uniqueName; // r2://nome_ficheiro.mp4
                } else {
                    // Fallback: salvar localmente se R2 falhar
                    error_log('R2 upload falhou, usando armazenamento local: ' . $r2_result['error']);
                    $local_path = 'uploads/videos/' . $uniqueName;
                    if (!is_dir('uploads/videos/')) {
                        mkdir('uploads/videos/', 0755, true);
                    }
                    if (move_uploaded_file($videoTmp, $local_path)) {
                        $upload_success = true;
                        $db_video_path = $uniqueName;
                    }
                }
            } else {
                // R2 desactivado — usar armazenamento local
                $local_path = 'uploads/videos/' . $uniqueName;
                if (!is_dir('uploads/videos/')) {
                    mkdir('uploads/videos/', 0755, true);
                }
                if (move_uploaded_file($videoTmp, $local_path)) {
                    $upload_success = true;
                    $db_video_path = $uniqueName;
                }
            }

            if ($upload_success) {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        INSERT INTO videos (user_id, title, description, video_path, hashtags, is_public, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");

                    if (!$stmt->execute([$_SESSION['user_id'], $title, $description, $db_video_path, $hashtags, $is_public])) {
                        throw new RuntimeException('Erro ao salvar vídeo no banco de dados.');
                    }

                    $video_id = (int)$pdo->lastInsertId();
                    if ($video_id > 0) {
                        hashtag_sync_video_relations($pdo, $video_id, $parsed_hashtags);
                    }

                    $pdo->prepare("
                        UPDATE users 
                        SET videos_count = videos_count + 1
                        WHERE id = ?
                    ")->execute([$_SESSION['user_id']]);

                    ranking_points_increment($pdo, $_SESSION['user_id'], 10);
                    ranking_cache_clear_all();

                    $pdo->commit();

                    $success = 'Vídeo enviado com sucesso!';

                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => $success]);
                        exit;
                    }

                    header('refresh:2;url=index.php');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = 'Erro interno: ' . $e->getMessage();
                    
                    // Limpar ficheiro em caso de erro
                    if (r2_is_r2_path($db_video_path)) {
                        r2_delete_video($db_video_path);
                    } elseif ($local_path && file_exists($local_path)) {
                        unlink($local_path);
                    }
                }
            } else {
                $error = 'Erro ao fazer upload do arquivo.';
            }
        }
    }

    if ($isAjax && $error) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload de Vídeo - MyTube</title>
    <link rel="stylesheet" href="<?php echo asset('assets/css/main.css'); ?>">
    <script src="<?php echo asset('assets/js/avatar-fallback.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo asset('assets/css/upload.css'); ?>">
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
    <button onclick="try{localStorage.setItem('mytube_restore_feed','1')}catch(e){} history.back()" class="back-button" title="Voltar">
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
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" class="upload-form" id="uploadForm">
                    <!-- Área de drop de arquivo -->
                    <div class="file-drop-area" id="fileDropArea">
                        <input type="file" name="video" id="videoInput" accept="video/*" required>
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
                        
                        <div class="form-group">
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
                    <div class="upload-progress" id="uploadProgress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill" id="progressFill"></div>
                        </div>
                        <div class="progress-text">
                            <span id="progressText">Enviando...</span>
                            <span id="progressPercent">0%</span>
                        </div>
                    </div>
                    
                    <!-- Botões -->
                    <div class="form-actions">
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
    <?php include 'includes/presence_bootstrap.php'; ?>
</body>
</html>