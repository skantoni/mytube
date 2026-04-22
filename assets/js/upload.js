// JavaScript para upload de vídeos - Com Barra de Progresso Real
const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB
const ALLOWED_TYPES = ['mp4', 'avi', 'mov', 'wmv', 'webm', 'mkv'];
const MAX_HASHTAGS_PER_VIDEO = 4;
const MAX_HASHTAG_LENGTH = 20;
const HASHTAG_TYPEAHEAD_DEBOUNCE_MS = 180;
const HASHTAG_TYPEAHEAD_MIN_CHARS = 2;

document.addEventListener('DOMContentLoaded', function() {
    console.log('Upload.js iniciando...');
    
    const fileDropArea = document.getElementById('fileDropArea');
    const videoInput = document.getElementById('videoInput');
    const fileInfo = document.getElementById('fileInfo');
    const videoPreview = document.getElementById('videoPreview');
    const uploadForm = document.getElementById('uploadForm');
    const uploadProgress = document.getElementById('uploadProgress');
    const submitBtn = document.getElementById('submitBtn');
    
    if (!fileDropArea || !videoInput || !fileInfo || !videoPreview) {
        console.error('Elementos não encontrados!');
        return;
    }
    
    console.log('Elementos encontrados com sucesso!');
    
    // Contadores de caracteres
    const titleInput = document.getElementById('title');
    const titleCount = document.getElementById('titleCount');
    const descTextarea = document.getElementById('description');
    const descCount = document.getElementById('descCount');
    
    if (titleInput && titleCount) {
        titleInput.addEventListener('input', function() {
            titleCount.textContent = this.value.length;
        });
    }
    
    if (descTextarea && descCount) {
        descTextarea.addEventListener('input', function() {
            descCount.textContent = this.value.length;
        });
    }

    initHashtagTypeahead();
    
    // Sistema de clique simples e direto
    fileDropArea.addEventListener('click', function() {
        console.log('Clique na área - abrindo seletor');
        videoInput.click();
    });
    
    // Drag and Drop básico
    fileDropArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        fileDropArea.classList.add('dragover');
    });
    
    fileDropArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        fileDropArea.classList.remove('dragover');
    });
    
    fileDropArea.addEventListener('drop', function(e) {
        e.preventDefault();
        fileDropArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            processFile(files[0]);
        }
    });
    
    // Quando arquivo é selecionado
    videoInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            console.log('Arquivo selecionado:', file.name);
            processFile(file);
        }
    });
    
    // Envio do formulário via AJAX com progresso real
    uploadForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!videoInput.files[0]) {
            showUploadMessage('Por favor, selecione um vídeo.', 'error');
            return;
        }
        
        const titleInput = document.getElementById('title');
        if (!titleInput.value.trim()) {
            showUploadMessage('Título é obrigatório.', 'error');
            titleInput.focus();
            return;
        }

        const hashtagsInput = document.getElementById('hashtags');
        if (hashtagsInput) {
            const hashtagCheck = validateHashtagsInput(hashtagsInput.value || '');
            if (!hashtagCheck.valid) {
                showUploadMessage(hashtagCheck.error, 'error');
                hashtagsInput.focus();
                return;
            }
            hashtagsInput.value = hashtagCheck.normalized;
        }
        
        // Iniciar upload AJAX com progresso
        startAjaxUpload();
    });
});

// Variáveis globais para controle do upload
let uploadXHR = null;
let uploadStartTime = 0;
let uploadedVideoId = null;     // ID do vídeo já inserido no servidor (para cancelamento tardio)
let uploadPercent = 0;          // Progresso actual da transferência (0-100)
let cancelRequested = false;    // Flag: utilizador pediu cancelamento durante fase de processamento

// Função para processar arquivo selecionado
function processFile(file) {
    console.log('Processando arquivo:', file.name);
    
    const fileExtension = file.name.split('.').pop().toLowerCase();
    
    if (!ALLOWED_TYPES.includes(fileExtension)) {
        showUploadMessage('Tipo de arquivo não suportado. Use: ' + ALLOWED_TYPES.join(', ').toUpperCase(), 'error');
        return;
    }
    
    if (file.size > MAX_FILE_SIZE) {
        showUploadMessage('Arquivo muito grande! Tamanho máximo: ' + formatFileSize(MAX_FILE_SIZE) + '. Seu arquivo: ' + formatFileSize(file.size), 'error');
        return;
    }
    
    // Mostrar informações do arquivo
    showFileInfo(file);
    
    // Criar preview
    createPreview(file);
    
}

// Mostrar informações do arquivo
function showFileInfo(file) {
    const fileInfo = document.getElementById('fileInfo');
    const fileDropArea = document.getElementById('fileDropArea');
    
    // Calcular % do limite
    const percentOfLimit = ((file.size / MAX_FILE_SIZE) * 100).toFixed(1);
    const sizeClass = percentOfLimit > 80 ? 'color: #f59e0b' : 'color: #10b981';
    
    if (fileInfo && fileDropArea) {
        fileInfo.innerHTML = `
            <div class="file-info-card">
                <div class="file-info-icon">
                    <i class="fas fa-file-video"></i>
                </div>
                <div class="file-info-details">
                    <div class="file-info-name">${file.name}</div>
                    <div class="file-info-meta">
                        <span class="file-info-size">${formatFileSize(file.size)}</span>
                        <span class="file-info-separator">•</span>
                        <span style="${sizeClass}">${percentOfLimit}% do limite</span>
                    </div>
                </div>
                <button type="button" onclick="removeFile()" class="file-remove-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        fileInfo.style.display = 'block';
        fileDropArea.style.display = 'none';
    }
}

// Criar preview do vídeo
function createPreview(file) {
    const videoPreview = document.getElementById('videoPreview');
    
    if (videoPreview) {
        const video = videoPreview.querySelector('video');
        if (video) {
            const url = URL.createObjectURL(file);
            video.src = url;
            videoPreview.style.display = 'block';
        }
    }
}

// Remover arquivo
function removeFile() {
    const videoInput = document.getElementById('videoInput');
    const fileInfo = document.getElementById('fileInfo');
    const videoPreview = document.getElementById('videoPreview');
    const fileDropArea = document.getElementById('fileDropArea');
    
    if (videoInput) videoInput.value = '';
    if (fileInfo) fileInfo.style.display = 'none';
    if (videoPreview) videoPreview.style.display = 'none';
    if (fileDropArea) fileDropArea.style.display = 'block';
    
    console.log('Arquivo removido');
}

// ============================================
// UPLOAD AJAX COM PROGRESSO REAL
// ============================================

function startAjaxUpload() {
    const uploadForm = document.getElementById('uploadForm');
    const submitBtn = document.getElementById('submitBtn');
    const uploadProgress = document.getElementById('uploadProgress');
    const formData = new FormData(uploadForm);
    
    // Adicionar flag para identificar requisição AJAX
    formData.append('ajax_upload', '1');
    
    // Mostrar barra de progresso
    if (uploadProgress) {
        uploadProgress.style.display = 'block';
        uploadProgress.innerHTML = `
            <div class="progress-upload-container">
                <div class="progress-header">
                    <div class="progress-status">
                        <i class="fas fa-cloud-upload-alt progress-icon uploading"></i>
                        <span id="progressStatusText">Preparando envio...</span>
                    </div>
                    <button type="button" onclick="cancelUpload()" class="progress-cancel-btn" id="cancelUploadBtn" title="Cancelar upload">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="progress-bar-wrapper">
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" id="progressBarFill"></div>
                        <div class="progress-bar-glow" id="progressBarGlow"></div>
                    </div>
                    <div class="progress-percentage" id="progressPercentage">0%</div>
                </div>
                
                <div class="progress-details">
                    <div class="progress-detail-item">
                        <i class="fas fa-file"></i>
                        <span id="progressSent">0 MB</span> / <span id="progressTotal">0 MB</span>
                    </div>
                    <div class="progress-detail-item">
                        <i class="fas fa-tachometer-alt"></i>
                        <span id="progressSpeed">Calculando...</span>
                    </div>
                    <div class="progress-detail-item">
                        <i class="fas fa-clock"></i>
                        <span id="progressETA">Calculando...</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Desabilitar botão
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
    }
    
    // Desabilitar campos do formulário
    toggleFormFields(true);
    
    // Criar XMLHttpRequest para ter progresso
    uploadXHR = new XMLHttpRequest();
    uploadStartTime = Date.now();
    uploadPercent = 0;
    cancelRequested = false;
    uploadedVideoId = null;
    let lastLoaded = 0;
    let lastTime = uploadStartTime;
    
    // Evento de progresso do upload
    uploadXHR.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const percent = Math.round((e.loaded / e.total) * 100);
            const now = Date.now();
            const elapsedMs = now - uploadStartTime;
            const elapsedSec = elapsedMs / 1000;
            
            // Calcular velocidade (bytes por segundo) - média móvel
            const deltaBytes = e.loaded - lastLoaded;
            const deltaTime = (now - lastTime) / 1000;
            let speed = 0;
            if (deltaTime > 0) {
                speed = deltaBytes / deltaTime;
            }
            lastLoaded = e.loaded;
            lastTime = now;
            
            // Velocidade média geral (para ETA mais estável)
            const avgSpeed = e.loaded / elapsedSec;
            
            // ETA baseada na velocidade média
            const remaining = e.total - e.loaded;
            const eta = avgSpeed > 0 ? remaining / avgSpeed : 0;
            
            updateProgress(percent, e.loaded, e.total, speed, eta);
        }
    });
    
    // Quando o upload termina e servidor responde
    uploadXHR.addEventListener('load', function() {
        if (uploadXHR.status === 200) {
            try {
                const response = JSON.parse(uploadXHR.responseText);
                handleServerResponse(response);
            } catch(e) {
                // Resposta não é JSON válido — tentar extrair JSON de resposta com warnings
                const text = uploadXHR.responseText || '';
                const jsonMatch = text.match(/\{[\s\S]*\}$/);
                if (jsonMatch) {
                    try {
                        handleServerResponse(JSON.parse(jsonMatch[0]));
                        return;
                    } catch(e2) {}
                }
                console.error('Upload response parse error:', text.substring(0, 500));
                showUploadComplete(false, 'Erro no servidor. Resposta inesperada.');
                resetUploadUI();
            }
        } else {
            showUploadComplete(false, 'Erro no servidor (HTTP ' + uploadXHR.status + '). Tente novamente.');
            resetUploadUI();
        }
    });

    // Erro de rede
    uploadXHR.addEventListener('error', function() {
        showUploadComplete(false, 'Erro de conexão. Verifique sua internet e tente novamente.');
        resetUploadUI();
    });

    // Upload abortado (só acontece se cancelado ANTES de atingir 100%)
    uploadXHR.addEventListener('abort', function() {
        showUploadComplete(false, 'Upload cancelado.');
        resetUploadUI();
        // Caso raro: servidor pode ter inserido antes do abort — tentar limpar
        deleteOrphanedUpload();
    });
    
    // Timeout
    uploadXHR.addEventListener('timeout', function() {
        showUploadComplete(false, 'Tempo esgotado. O servidor demorou muito para responder.');
        resetUploadUI();
    });
    
    // Configurar e enviar
    uploadXHR.open('POST', 'upload.php', true);
    uploadXHR.timeout = 600000; // 10 minutos de timeout
    uploadXHR.send(formData);
}

// Atualizar barra de progresso
function updateProgress(percent, loaded, total, speed, eta) {
    const fill = document.getElementById('progressBarFill');
    const glow = document.getElementById('progressBarGlow');
    const percentText = document.getElementById('progressPercentage');
    const statusText = document.getElementById('progressStatusText');
    const sentText = document.getElementById('progressSent');
    const totalText = document.getElementById('progressTotal');
    const speedText = document.getElementById('progressSpeed');
    const etaText = document.getElementById('progressETA');
    
    // Guardar percentagem actual (usada pelo cancelUpload)
    uploadPercent = percent;

    if (fill) {
        fill.style.width = percent + '%';
    }
    if (glow) {
        glow.style.width = percent + '%';
    }
    if (percentText) {
        percentText.textContent = percent + '%';
        // Mudar cor conforme progresso
        if (percent >= 90) percentText.style.color = '#10b981';
        else if (percent >= 50) percentText.style.color = '#3b82f6';
    }
    if (statusText) {
        if (percent < 100) {
            statusText.textContent = 'Enviando vídeo... ' + percent + '%';
        } else {
            statusText.textContent = 'Processando no servidor...';
            // Mudar ícone para processamento
            const icon = document.querySelector('.progress-icon');
            if (icon) {
                icon.className = 'fas fa-cog fa-spin progress-icon processing';
            }
        }
    }
    if (sentText) sentText.textContent = formatFileSize(loaded);
    if (totalText) totalText.textContent = formatFileSize(total);
    if (speedText) speedText.textContent = formatSpeed(speed);
    if (etaText) etaText.textContent = formatETA(eta);
}

// Upload completo (sucesso ou erro)
function showUploadComplete(success, message) {
    const uploadProgress = document.getElementById('uploadProgress');
    
    if (uploadProgress) {
        const icon = success ? 'fa-check-circle' : 'fa-exclamation-circle';
        const colorClass = success ? 'success' : 'error';
        
        uploadProgress.innerHTML = `
            <div class="progress-upload-container ${colorClass}">
                <div class="progress-complete">
                    <i class="fas ${icon} progress-complete-icon ${colorClass}"></i>
                    <div class="progress-complete-text">${message}</div>
                    ${success ? '<div class="progress-complete-sub">Redirecionando...</div>' : '<div class="progress-complete-sub">Você pode tentar novamente.</div>'}
                </div>
                ${success ? `
                <div class="progress-bar-wrapper">
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill complete" style="width: 100%"></div>
                    </div>
                </div>` : ''}
            </div>
        `;
    }
}

// Tratar resposta do servidor (sucesso ou erro) — separado para reutilização
function handleServerResponse(response) {
    if (response.success) {
        const videoId = response.video_id || null;

        if (cancelRequested) {
            // ─── Utilizador cancelou durante o processamento ───
            // O servidor já inseriu o vídeo — apagar imediatamente
            uploadedVideoId = videoId;
            deleteOrphanedUpload();
            showUploadComplete(false, 'Upload cancelado. Vídeo removido do servidor.');
            resetUploadUI();
        } else {
            // ─── Sucesso normal — redirecionar ───
            showUploadComplete(true, response.message || 'Vídeo enviado com sucesso!');
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 2000);
        }
    } else {
        showUploadComplete(false, response.error || 'Erro ao enviar vídeo.');
        resetUploadUI();
    }
}

// Cancelar upload
// ─ Se transferência < 100%: abortar XHR (PHP nunca recebeu tudo → sem registo)
// ─ Se transferência = 100% (a processar): NÃO abortar — marcar flag e aguardar
//   resposta do servidor para obter o video_id e apagá-lo correctamente
function cancelUpload() {
    if (uploadXHR && uploadPercent < 100) {
        // Fase de transferência: abortar é seguro, PHP não terminou de receber
        uploadXHR.abort();
        uploadXHR = null;

    } else if (uploadXHR && uploadPercent >= 100) {
        // Fase de processamento no servidor: NÃO abortar!
        // O PHP já recebeu tudo e está a trabalhar — se abortarmos o XHR
        // perdemos a resposta (e o video_id) e o vídeo fica órfão.
        cancelRequested = true;
        showCancellingUI();

    } else if (uploadedVideoId) {
        // Servidor já respondeu e temos o video_id — apagar directamente
        deleteOrphanedUpload();
        showUploadComplete(false, 'Upload cancelado. Vídeo removido do servidor.');
        resetUploadUI();
    }
}

// UI de "a cancelar" — feedback imediato enquanto aguarda resposta do servidor
function showCancellingUI() {
    const statusText = document.getElementById('progressStatusText');
    const cancelBtn = document.getElementById('cancelUploadBtn');
    const icon = document.querySelector('.progress-icon');

    if (statusText) statusText.textContent = 'Cancelando... aguardando servidor.';
    if (cancelBtn) {
        cancelBtn.disabled = true;
        cancelBtn.style.opacity = '0.5';
        cancelBtn.title = 'A cancelar...';
    }
    if (icon) {
        icon.className = 'fas fa-spinner fa-spin progress-icon';
    }
}

// Apagar vídeo que ficou órfão no servidor após cancelamento
function deleteOrphanedUpload() {
    if (!uploadedVideoId) return;

    const videoIdToDelete = uploadedVideoId;
    uploadedVideoId = null; // Prevenir chamadas duplas

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfValue = csrfMeta ? csrfMeta.getAttribute('content') : '';
    const payload = JSON.stringify({ video_id: videoIdToDelete });

    // fetch com keepalive: funciona mesmo se a página estiver a ser fechada
    fetch('api/cancel_upload.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfValue
        },
        body: payload,
        keepalive: true
    }).then(function(res) {
        return res.json();
    }).then(function(data) {
        console.log('cancel_upload:', data.message || data);
    }).catch(function(err) {
        // Fallback síncrono se fetch falhar
        console.warn('fetch falhou, a tentar XHR síncrono:', err);
        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api/cancel_upload.php', false);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', csrfValue);
            xhr.send(payload);
        } catch(e2) { /* nada a fazer */ }
    });

    console.log('Cancelamento solicitado para vídeo #' + videoIdToDelete);
}

// Resetar UI após erro
function resetUploadUI() {
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-upload"></i> Publicar Vídeo';
    }
    toggleFormFields(false);
    uploadXHR = null;
}

// Desabilitar/habilitar campos do formulário
function toggleFormFields(disabled) {
    const fields = document.querySelectorAll('#uploadForm input:not([type="file"]), #uploadForm textarea, #uploadForm select');
    fields.forEach(f => f.disabled = disabled);
}

// Mostrar mensagem temporária
function showUploadMessage(msg, type) {
    const existing = document.querySelector('.upload-toast');
    if (existing) existing.remove();
    
    const toast = document.createElement('div');
    toast.className = 'upload-toast ' + type;
    toast.innerHTML = `
        <i class="fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}"></i>
        <span>${msg}</span>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('fade-out');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ============================================
// FUNÇÕES DE FORMATAÇÃO
// ============================================

function formatFileSize(bytes) {
    const sizes = ['B', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 B';
    
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i >= 2 ? 1 : 0) + ' ' + sizes[i];
}

function formatSpeed(bytesPerSec) {
    if (bytesPerSec <= 0) return 'Calculando...';
    if (bytesPerSec < 1024) return bytesPerSec.toFixed(0) + ' B/s';
    if (bytesPerSec < 1024 * 1024) return (bytesPerSec / 1024).toFixed(1) + ' KB/s';
    return (bytesPerSec / (1024 * 1024)).toFixed(1) + ' MB/s';
}

function formatETA(seconds) {
    if (seconds <= 0 || !isFinite(seconds)) return 'Calculando...';
    if (seconds < 60) return Math.ceil(seconds) + 's restantes';
    if (seconds < 3600) {
        const min = Math.floor(seconds / 60);
        const sec = Math.ceil(seconds % 60);
        return min + 'min ' + sec + 's restantes';
    }
    const hr = Math.floor(seconds / 3600);
    const min = Math.ceil((seconds % 3600) / 60);
    return hr + 'h ' + min + 'min restantes';
}

let hashtagTypeaheadTimer = null;
let hashtagTypeaheadRequestId = 0;
let hashtagTypeaheadSuggestions = [];
let hashtagTypeaheadActiveIndex = -1;

function initHashtagTypeahead() {
    const hashtagsInput = document.getElementById('hashtags');
    const suggestionsEl = document.getElementById('hashtagsSuggestions');

    if (!hashtagsInput || !suggestionsEl) {
        return;
    }

    const closeSuggestions = function() {
        hashtagTypeaheadSuggestions = [];
        hashtagTypeaheadActiveIndex = -1;
        suggestionsEl.innerHTML = '';
        suggestionsEl.hidden = true;
    };

    const setActiveIndex = function(index) {
        hashtagTypeaheadActiveIndex = index;
        const items = suggestionsEl.querySelectorAll('.hashtag-suggestion-item');

        items.forEach(function(item, itemIndex) {
            if (itemIndex === hashtagTypeaheadActiveIndex) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
    };

    const applySuggestion = function(suggestion) {
        const context = getHashtagTokenContext(hashtagsInput);
        const before = context.value.slice(0, context.tokenStart);
        const after = context.value.slice(context.tokenEnd).trimStart();
        let nextValue = before + '#' + suggestion.name;

        if (after) {
            nextValue += ' ' + after;
        } else {
            nextValue += ' ';
        }

        hashtagsInput.value = nextValue;

        const caretPosition = (before + '#' + suggestion.name + ' ').length;
        hashtagsInput.setSelectionRange(caretPosition, caretPosition);
        hashtagsInput.dispatchEvent(new Event('input', { bubbles: true }));
        closeSuggestions();
    };

    const renderSuggestions = function() {
        suggestionsEl.innerHTML = '';

        if (!hashtagTypeaheadSuggestions.length) {
            closeSuggestions();
            return;
        }

        hashtagTypeaheadSuggestions.forEach(function(suggestion, index) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'hashtag-suggestion-item';

            if (index === hashtagTypeaheadActiveIndex) {
                item.classList.add('active');
            }

            const name = document.createElement('span');
            name.className = 'hashtag-suggestion-name';
            name.textContent = '#' + suggestion.name;

            const meta = document.createElement('span');
            meta.className = 'hashtag-suggestion-meta';
            meta.textContent = suggestion.posts_count + ' posts';

            item.appendChild(name);
            item.appendChild(meta);

            item.addEventListener('mousedown', function(event) {
                event.preventDefault();
            });

            item.addEventListener('click', function() {
                applySuggestion(suggestion);
            });

            suggestionsEl.appendChild(item);
        });

        suggestionsEl.hidden = false;
    };

    const requestSuggestions = function() {
        const context = getHashtagTokenContext(hashtagsInput);

        if (!context.token || context.token.charAt(0) !== '#') {
            closeSuggestions();
            return;
        }

        const term = context.token.replace(/^#+/, '').toLowerCase();
        if (term.length < HASHTAG_TYPEAHEAD_MIN_CHARS) {
            closeSuggestions();
            return;
        }

        const requestId = ++hashtagTypeaheadRequestId;

        fetch('api/search.php?q=' + encodeURIComponent('#' + term), {
            credentials: 'same-origin'
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('request_failed');
                }
                return response.json();
            })
            .then(function(data) {
                if (requestId !== hashtagTypeaheadRequestId) {
                    return;
                }

                if (!data || data.success !== true || !Array.isArray(data.hashtags)) {
                    closeSuggestions();
                    return;
                }

                hashtagTypeaheadSuggestions = data.hashtags
                    .map(function(item) {
                        return {
                            name: String(item.name || '').toLowerCase(),
                            posts_count: Number(item.posts_count || 0)
                        };
                    })
                    .filter(function(item) {
                        return item.name !== '';
                    });

                if (!hashtagTypeaheadSuggestions.length) {
                    closeSuggestions();
                    return;
                }

                hashtagTypeaheadActiveIndex = 0;
                renderSuggestions();
            })
            .catch(function() {
                if (requestId === hashtagTypeaheadRequestId) {
                    closeSuggestions();
                }
            });
    };

    hashtagsInput.addEventListener('input', function() {
        window.clearTimeout(hashtagTypeaheadTimer);

        const context = getHashtagTokenContext(hashtagsInput);
        if (!context.token || context.token.charAt(0) !== '#') {
            closeSuggestions();
            return;
        }

        const term = context.token.replace(/^#+/, '').toLowerCase();
        if (term.length < HASHTAG_TYPEAHEAD_MIN_CHARS) {
            closeSuggestions();
            return;
        }

        hashtagTypeaheadTimer = window.setTimeout(function() {
            requestSuggestions();
        }, HASHTAG_TYPEAHEAD_DEBOUNCE_MS);
    });

    hashtagsInput.addEventListener('keydown', function(event) {
        if (suggestionsEl.hidden || !hashtagTypeaheadSuggestions.length) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            const nextIndex = (hashtagTypeaheadActiveIndex + 1) % hashtagTypeaheadSuggestions.length;
            setActiveIndex(nextIndex);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            const nextIndex = (hashtagTypeaheadActiveIndex - 1 + hashtagTypeaheadSuggestions.length) % hashtagTypeaheadSuggestions.length;
            setActiveIndex(nextIndex);
            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            const selectedIndex = hashtagTypeaheadActiveIndex >= 0 ? hashtagTypeaheadActiveIndex : 0;
            const suggestion = hashtagTypeaheadSuggestions[selectedIndex];
            if (suggestion) {
                applySuggestion(suggestion);
            }
            return;
        }

        if (event.key === 'Escape') {
            closeSuggestions();
        }
    });

    hashtagsInput.addEventListener('blur', function() {
        window.setTimeout(function() {
            closeSuggestions();
        }, 120);
    });

    document.addEventListener('click', function(event) {
        if (event.target === hashtagsInput || suggestionsEl.contains(event.target)) {
            return;
        }
        closeSuggestions();
    });
}

function getHashtagTokenContext(inputElement) {
    const value = inputElement.value || '';
    const caret = typeof inputElement.selectionStart === 'number'
        ? inputElement.selectionStart
        : value.length;

    const searchFrom = Math.max(0, caret - 1);
    const tokenStart = value.lastIndexOf(' ', searchFrom) + 1;
    let tokenEnd = value.indexOf(' ', caret);

    if (tokenEnd === -1) {
        tokenEnd = value.length;
    }

    return {
        value,
        caret,
        tokenStart,
        tokenEnd,
        token: value.slice(tokenStart, caret)
    };
}

function validateHashtagsInput(rawInput) {
    const input = (rawInput || '').trim();
    if (!input) {
        return { valid: true, normalized: '' };
    }

    const parts = input.split(/\s+/u).filter(Boolean);
    const normalizedTags = [];
    const seen = new Set();

    for (const part of parts) {
        const clean = part.replace(/^#+/, '').trim().toLowerCase();
        if (!clean) continue;

        if (clean.length > MAX_HASHTAG_LENGTH) {
            return {
                valid: false,
                error: `Cada hashtag deve ter no máximo ${MAX_HASHTAG_LENGTH} caracteres.`
            };
        }

        if (!/^[\p{L}\p{N}]+$/u.test(clean)) {
            return {
                valid: false,
                error: 'Hashtags devem conter apenas letras e números, sem espaços ou símbolos.'
            };
        }

        if (seen.has(clean)) {
            continue;
        }

        seen.add(clean);
        normalizedTags.push('#' + clean);

        if (normalizedTags.length > MAX_HASHTAGS_PER_VIDEO) {
            return {
                valid: false,
                error: `Máximo de ${MAX_HASHTAGS_PER_VIDEO} hashtags por vídeo.`
            };
        }
    }

    return {
        valid: true,
        normalized: normalizedTags.join(' ')
    };
}