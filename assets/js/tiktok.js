// TikTok Style Functionality for MyTube
// 
// ARQUITETURA DE EVENTOS v2.0 (LIMPA E ESCALÁVEL)
// ================================================
// 
// PRINCÍPIOS:
// 1. UM ÚNICO event listener no container (event delegation)
// 2. Identificação clara de onde o click ocorreu
// 3. Sem stopPropagation excessivo
// 4. Compatível com mobile e desktop
// 5. Fácil debug e manutenção
//
// HIERARQUIA DE Z-INDEX:
// - video: base (0)
// - video-overlay: 2 (pointer-events: none, exceto links)
// - action-buttons: 3
// - video-controls: 4
// - audio-prompt-overlay: 10 (apenas quando visível)

class TikTokPlayer {
    constructor() {
        this.currentVideoIndex = 0;
        this.videos = [];
        this.isScrolling = false;
        this.isSnapping = false;
        this.scrollEndDelay = 250;
        this._snapUnlockTimer = null;
        this.initialized = false;
        this.controlsSetup = false;
        this.intersectionObserver = null;
        this.recycleObserver = null;
        this.eventsBound = false;
        this.maxMaterialized = 5;
        // Controle de views: evitar requests duplicados
        this._viewsUpdated = new Set();
        this._viewsInFlight = new Set();
        this._requestCount = 0;
        this._requestResetTime = Date.now();
        this._maxRequestsPerMinute = 30;
        this._pendingViews = [];      // Queue para views adiadas
    }

    init() {
        this.setupVideos();
        this.setupScrolling();
        this.setupGlobalEventHandler();
        this.setupKeyboardControls();
        this.setupScrubbing();
        this.setupIntersectionObserver();
        this.setupRecycleObserver();
        this.setupDesktopNavButtons();
        this.loadCurrentVideo();
        this.initializeAudioState();
    }

    setupVideos() {
        const videoItems = document.querySelectorAll('.video-item');
        
        if (videoItems.length === 0) {
            this.videos = [];
            return;
        }
        
        // Determinar estado de mute atual antes de reconstruir o array
        const currentMuteState = this.getCurrentMuteState();
        
        // Pausar vídeos antigos
        if (this.videos && this.videos.length > 0) {
            this.videos.forEach(vd => {
                if (vd.video && !vd.video.paused) vd.video.pause();
            });
        }
        
        this.videos = Array.from(videoItems).map((item, index) => {
            const video = item.querySelector('video');
            const videoId = item.dataset.videoId;
            
            // Eventos básicos do elemento video (não são de click)
            if (video) {
                // Remover listeners antigos
                video.onloadeddata = null;
                video.ontimeupdate = null;
                video.onended = null;
                video.onerror = null;
                
                // Adicionar novos
                video.onloadeddata = () => {
                    const vd = this.videos.find(v => v.video === video);
                    if (vd) {
                        vd.loaded = true;
                        vd._retryCount = 0; // Reset retry on success
                    }
                };
                video.ontimeupdate = () => {
                    const vd = this.videos.find(v => v.video === video);
                    if (vd) this.updateProgress(vd);
                };
                video.onended = () => this.nextVideo();

                // Buffering: adicionar/remover classe para animação de loading
                video.onwaiting = () => {
                    const vd = this.videos.find(v => v.video === video);
                    if (vd) vd.element.classList.add('buffering');
                };
                video.onplaying = () => {
                    const vd = this.videos.find(v => v.video === video);
                    if (vd) vd.element.classList.remove('buffering');
                };
                video.oncanplay = () => {
                    const vd = this.videos.find(v => v.video === video);
                    if (vd) vd.element.classList.remove('buffering');
                };
                
                // Recovery: tentar recarregar vídeo em caso de erro (508, rede, etc)
                video.onerror = () => {
                    const vd = this.videos.find(v => v.video === video);
                    if (vd) {
                        vd._retryCount = (vd._retryCount || 0) + 1;
                        const maxRetries = 3;
                        if (vd._retryCount <= maxRetries) {
                            const delay = Math.min(5000 * vd._retryCount, 15000);
                            console.warn(`Vídeo ${videoId} falhou ao carregar. Retry ${vd._retryCount}/${maxRetries} em ${delay/1000}s`);
                            setTimeout(() => {
                                if (video.src || video.querySelector('source')) {
                                    video.load();
                                }
                            }, delay);
                        }
                    }
                };
            }
            
            const placeholder = item.querySelector('.video-placeholder');
            const videoPath = video?.dataset?.videoPath || placeholder?.dataset?.videoPath || '';

            // Aplicar estado de mute consistente
            if (video) video.muted = currentMuteState;

            return {
                element: item,
                video: video,
                videoId: videoId,
                videoPath: videoPath,
                index: index,
                loaded: false,
                progress: 0,
                manuallyPaused: false,
                materialized: !!video,
                savedTime: 0,
                wasMuted: currentMuteState
            };
        });

        // Atualizar ícones dos botões de áudio para refletir estado atual
        this.updateAllAudioButtons(currentMuteState);
        // Atualizar estado dos botões de navegação desktop
        this.updateDesktopNavButtons();
    }

    // ============================================
    // HELPER: Estado de mute global atual
    // ============================================
    getCurrentMuteState() {
        // Verificar uma flag explícita do utilizador
        const explicitMute = localStorage.getItem('mytube_global_muted');
        if (explicitMute !== null) {
            return explicitMute === 'true';
        }
        
        // Fallback: verificar se utilizador já interagiu (som ativado)
        const userInteracted = localStorage.getItem('mytube_user_interacted') === 'true';
        return !userInteracted;
    }

    // ============================================
    // HELPER: Atualizar ícones de todos os botões de áudio
    // ============================================
    updateAllAudioButtons(isMuted) {
        document.querySelectorAll('.audio-toggle').forEach(btn => {
            const icon = btn.querySelector('i');
            if (isMuted) {
                btn.classList.remove('active');
                icon.className = 'fas fa-volume-mute';
                btn.title = 'Ativar Som';
            } else {
                btn.classList.add('active');
                icon.className = 'fas fa-volume-up';
                btn.title = 'Desativar Som';
            }
        });
    }

    // ============================================
    // HELPER: Buscar ou criar videoData
    // ============================================
    getOrCreateVideoData(videoId) {
        // Tentar encontrar no array existente
        let videoData = this.videos.find(v => String(v.videoId) === String(videoId));
        
        if (!videoData) {
            // Vídeo carregado via AJAX - criar dinamicamente
            const videoItem = document.querySelector(`.video-item[data-video-id="${videoId}"]`);
            if (videoItem) {
                const video = videoItem.querySelector('video');
                if (video) {
                    const currentMuteState = this.getCurrentMuteState();
                    video.muted = currentMuteState;
                    videoData = {
                        element: videoItem,
                        video: video,
                        videoId: String(videoId),
                        videoPath: video?.dataset?.videoPath || '',
                        index: this.videos.length,
                        loaded: true,
                        progress: 0,
                        manuallyPaused: false,
                        materialized: true,
                        savedTime: 0,
                        wasMuted: currentMuteState
                    };
                    // Adicionar ao array
                    this.videos.push(videoData);
                    
                    // Configurar eventos básicos do vídeo
                    video.onended = () => this.nextVideo();
                    video.onwaiting = () => { videoData.element.classList.add('buffering'); };
                    video.onplaying = () => { videoData.element.classList.remove('buffering'); };
                    video.oncanplay = () => { videoData.element.classList.remove('buffering'); };
                }
            }
        }
        
        return videoData;
    }

    // ============================================
    // NOVO: SISTEMA DE EVENTOS CENTRALIZADO
    // ============================================
    setupGlobalEventHandler() {
        if (this.eventsBound) return;
        this.eventsBound = true;
        
        const container = document.querySelector('.tiktok-container');
        if (!container) return;
        
        // Um único listener para TODOS os clicks
        container.addEventListener('click', (e) => {
            const target = e.target;
            
            // 1. BOTÃO DE LIKE
            if (target.closest('.like-btn')) {
                e.preventDefault();
                const btn = target.closest('.like-btn');
                const videoId = btn.dataset.videoId;
                this.likeVideo(videoId, btn);
                return;
            }
            
            // 2. BOTÃO DE COMENTÁRIO
            if (target.closest('.comment-btn')) {
                // Deixar comments.js processar
                return;
            }
            
            // 3. BOTÃO DE FOLLOW
            if (target.closest('.follow-btn')) {
                e.preventDefault();
                const btn = target.closest('.follow-btn');
                const userId = btn.dataset.userId;
                this.followUser(userId, btn);
                return;
            }
            
            // 3.1. BOTÃO DE FOLLOW INLINE (estilo Facebook)
            if (target.closest('.follow-btn-inline')) {
                e.preventDefault();
                const btn = target.closest('.follow-btn-inline');
                const userId = btn.dataset.userId;
                this.followUserInline(userId, btn);
                return;
            }
            
            // 4. BOTÃO DE SHARE
            if (target.closest('.share-btn')) {
                e.preventDefault();
                const btn = target.closest('.share-btn');
                const videoId = btn.dataset.videoId;
                this.openShareMenu(videoId);
                return;
            }
            
            // 5. BOTÃO DE DELETE
            if (target.closest('.delete-btn')) {
                // Deixar video-delete.js processar
                return;
            }
            
            // 6. BOTÃO DE ÁUDIO
            if (target.closest('.audio-toggle')) {
                e.preventDefault();
                const btn = target.closest('.audio-toggle');
                const videoId = btn.dataset.videoId;
                this.toggleAudio(videoId, btn);
                return;
            }
            
            // 7. OVERLAY DE ÁUDIO (ativar som)
            if (target.closest('.audio-prompt-overlay')) {
                e.preventDefault();
                const overlay = target.closest('.audio-prompt-overlay');
                const videoId = overlay.id.replace('audio-prompt-', '');
                this.enableAudioForAll(videoId);
                const videoData = this.getOrCreateVideoData(videoId);
                if (videoData) this.togglePlayPause(videoData);
                return;
            }
            
            // 8. LINKS no overlay (deixar funcionar)
            if (target.closest('.avatar-link') || target.closest('.username-link') || target.closest('.video-author-link')) {
                return; // Deixar navegação normal
            }
            
            // 9. VIDEO CONTROLS AREA (não fazer nada)
            if (target.closest('.video-controls') || target.closest('.progress-container')) {
                return;
            }
            
            // 9.5. LINKS DENTRO DO VIDEO (deixar navegação normal)
            if (target.closest('.video-info-overlay a') || target.closest('.video-author-link')) {
                return; // Deixar navegação normal para o perfil
            }

            // 9.55. TOGGLE DA DESCRIÇÃO (Ver mais / Ver menos)
            if (target.closest('.caption-toggle-btn')) {
                e.preventDefault();
                const toggleBtn = target.closest('.caption-toggle-btn');
                this.toggleCaptionText(toggleBtn);
                return;
            }

            // 9.57. ÁREA DE DESCRIÇÃO / HASHTAGS (texto e links) - não pausar vídeo
            if (target.closest('.video-caption-block') || target.closest('.video-hashtags')) {
                return;
            }
            
            // 9.6. MODAL DE COMENTÁRIOS ABERTO - não pausar vídeo
            const commentsModal = document.getElementById('commentsModal');
            if (commentsModal && commentsModal.classList.contains('open')) {
                return; // Não pausar vídeo enquanto modal está aberto
            }

            // 10. CLICK NO VÍDEO = PLAY/PAUSE
            const videoItem = target.closest('.video-item');
            if (videoItem) {
                const videoPlayer = target.closest('.video-player');
                const videoElement = target.closest('video');
                
                if (videoPlayer || videoElement) {
                    e.preventDefault();
                    const videoId = videoItem.dataset.videoId;
                    const videoData = this.getOrCreateVideoData(videoId);
                    if (videoData) {
                        this.togglePlayPause(videoData);
                    }
                }
            }
        });
        
        // Touch events para mobile (melhor responsividade)
        container.addEventListener('touchend', (e) => {
            // Apenas processar se não houve scroll
            if (this.isScrolling) return;
            
            // O click event já vai processar, mas marcamos interação
            localStorage.setItem('mytube_user_interacted', 'true');
        }, { passive: true });
    }

    toggleCaptionText(toggleBtn) {
        const captionBlock = toggleBtn?.closest('.video-caption-block');
        const captionEl = captionBlock?.querySelector('.video-caption');

        if (!captionBlock || !captionEl) {
            return;
        }

        const fullText = captionEl.dataset.captionFull || captionEl.textContent || '';
        const collapsedText = captionEl.dataset.captionCollapsed || fullText;
        const isExpanded = captionBlock.classList.toggle('is-expanded');

        // Helper para escapar HTML e linkificar URLs
        const _esc = (s) => String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const _linkify = (escaped) => escaped.replace(
            /(https?:\/\/(?:[^\s<>"'&]|&amp;)*)/gi,
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="description-link" onclick="event.stopPropagation()">$1</a>'
        );

        captionEl.innerHTML = isExpanded ? _linkify(_esc(fullText)) : _linkify(_esc(collapsedText));
        toggleBtn.textContent = isExpanded ? 'Ver menos' : '...Ver mais';
        toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    }

    setupKeyboardControls() {
        if (this.controlsSetup) return;
        this.controlsSetup = true;
        
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || 
                e.target.tagName === 'TEXTAREA' || 
                e.target.contentEditable === 'true') {
                return;
            }

            const current = this.videos[this.currentVideoIndex];
            
            switch (e.key) {
                case 'ArrowUp':
                    e.preventDefault();
                    this.previousVideo();
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    this.nextVideo();
                    break;
                case ' ':
                    e.preventDefault();
                    if (current) this.togglePlayPause(current);
                    break;
                case 'M':
                case 'm':
                    e.preventDefault();
                    if (current) {
                        const audioBtn = document.querySelector(`[data-video-id="${current.videoId}"].audio-toggle`);
                        if (audioBtn) this.toggleAudio(current.videoId, audioBtn);
                    }
                    break;
            }
        });
    }

    setupScrolling() {
        const container = document.querySelector('.tiktok-container');
        if (!container) return;
        
        let scrollTimeout;

        container.addEventListener('scroll', () => {
            if (!this.isScrolling) {
                this.pauseAllVideos();
            }
            
            this.isScrolling = true;
            clearTimeout(scrollTimeout);

            scrollTimeout = setTimeout(() => {
                this.isScrolling = false;
                this.handleScrollEnd();
            }, this.scrollEndDelay);
        }, { passive: true });
    }

    setupIntersectionObserver() {
        // Desconectar observer antigo se existir
        if (this.intersectionObserver) {
            this.intersectionObserver.disconnect();
        }
        
        const options = {
            root: document.querySelector('.tiktok-container'),
            rootMargin: '0px',
            threshold: 0.7
        };

        this.intersectionObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const videoData = this.videos.find(v => v.element === entry.target);
                if (!videoData) return;
                
                if (entry.isIntersecting && entry.intersectionRatio >= 0.7) {
                    // Pausar TODOS os outros vídeos primeiro
                    this.pauseAllVideos();
                    
                    const previousIndex = this.currentVideoIndex;
                    
                    // Vídeo está visível - tocar APENAS se não foi pausado manualmente
                    this.currentVideoIndex = videoData.index;
                    this.persistFeedState(videoData.videoId);
                    if (!videoData.manuallyPaused) {
                        this.playVideo(videoData);
                    }
                    this.updateViews(videoData.videoId);
                    this.updateDesktopNavButtons();
                    
                    // Se mudou de vídeo e o sidebar de comentários está aberto, recarregar comentários
                    if (previousIndex !== videoData.index) {
                        const sidebar = document.getElementById('commentsSidebar');
                        if (sidebar && sidebar.classList.contains('open') && window.commentsSystem) {
                            window.commentsSystem.openComments(videoData.videoId);
                        }
                    }
                } else {
                    // Vídeo saiu da view - pausar e resetar flag
                    this.pauseVideo(videoData);
                    videoData.manuallyPaused = false;
                }
            });
        }, options);

        this.videos.forEach(videoData => {
            this.intersectionObserver.observe(videoData.element);
        });
    }

    // ============================================
    // VIRTUAL SCROLL - Manter max 5 <video> no DOM
    // ============================================

    setupRecycleObserver() {
        if (this.recycleObserver) {
            this.recycleObserver.disconnect();
        }

        const container = document.querySelector('.tiktok-container');
        if (!container) return;

        // Observer com margem ampla — materializa vídeos 1 viewport antes de ficarem visíveis
        this.recycleObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const videoData = this.videos.find(v => v.element === entry.target);
                if (!videoData) return;

                if (entry.isIntersecting && !videoData.materialized) {
                    this.materializeVideo(videoData);
                }
            });
            this.enforceMaxMaterialized();
        }, {
            root: container,
            rootMargin: '100% 0px',
            threshold: 0
        });

        this.videos.forEach(vd => {
            this.recycleObserver.observe(vd.element);
        });
    }

    virtualizeVideo(videoData) {
        if (!videoData.materialized || !videoData.video) return;

        const video = videoData.video;
        const player = videoData.element.querySelector('.video-player');
        if (!player) return;

        // Salvar estado de reprodução
        videoData.savedTime = video.currentTime || 0;
        videoData.wasMuted = video.muted;

        // Pausar e liberar recursos de mídia (decoder, buffers)
        video.pause();
        video.removeAttribute('src');
        // Remover elementos <source> para garantir a purga do buffer
        while (video.firstChild) {
            video.removeChild(video.firstChild);
        }
        video.load(); // Forçar o descarregamento da rede/buffer

        // Apenas esconder visualmente ou aplicar estilo de placeholder (sem remover a tag <video>!)
        // Isso preserva o "token de interação do usuário" associado a este elemento no mobile.
        video.classList.add('video-unloaded');
        video.style.opacity = '0';

        videoData.materialized = false;
        videoData.loaded = false;
        videoData.element.classList.remove('buffering');
    }

    materializeVideo(videoData) {
        if (videoData.materialized) return;

        const player = videoData.element.querySelector('.video-player');
        if (!player) return;

        // Recuperar o <video> existente em vez de criar um novo
        let video = player.querySelector('video');
        
        // Cuidar dos casos de carregamento original do HTML onde o backend só gerou o placeholder (div)
        const placeholder = player.querySelector('.video-placeholder');
        if (placeholder && !video) {
            video = document.createElement('video');
            video.id = `video-${videoData.videoId}`;
            video.playsInline = true;
            video.setAttribute('playsinline', '');
            video.setAttribute('data-has-audio', 'true');
            video.setAttribute('data-video-id', videoData.videoId);
            video.setAttribute('data-video-path', videoData.videoPath);
            player.replaceChild(video, placeholder);
            videoData.video = video;
            // Assinar eventos 1x
            video.onended = () => this.nextVideo();
            video.onwaiting = () => { videoData.element.classList.add('buffering'); };
            video.onplaying = () => { videoData.element.classList.remove('buffering'); };
            video.oncanplay = () => { videoData.element.classList.remove('buffering'); };
        } else if (!video) {
            return; 
        }

        video.loop = true;
        // Respeitar sempre a vontade global estrita para evitar mute forçado do browser
        video.muted = this.getCurrentMuteState();
        video.preload = 'metadata';

        // Reconstruir o src
        const source = document.createElement('source');
        source.src = resolveVideoUrl(videoData.videoPath);
        source.type = 'video/mp4';
        video.appendChild(source);

        // Restaurar estado visual
        video.classList.remove('video-unloaded');
        video.style.opacity = '1';

        // Re-attachar eventos de progresso e carregamento
        video.onloadeddata = () => {
            videoData.loaded = true;
            if (videoData.savedTime > 0) {
                video.currentTime = videoData.savedTime;
            }
        };
        video.ontimeupdate = () => this.updateProgress(videoData);

        videoData.video = video;
        videoData.materialized = true;

        // Carregar conteúdo
        const nq = window.networkQuality;
        if (nq) video.preload = nq.getPreload();
        video.load();
    }

    enforceMaxMaterialized() {
        const materialized = this.videos.filter(v => v.materialized);
        if (materialized.length <= this.maxMaterialized) return;

        const currentIdx = this.currentVideoIndex;

        // Ordenar por distância do vídeo atual (mais longe primeiro)
        const sorted = materialized
            .map(v => ({ vd: v, dist: Math.abs(v.index - currentIdx) }))
            .sort((a, b) => b.dist - a.dist);

        const excess = materialized.length - this.maxMaterialized;
        for (let i = 0; i < excess; i++) {
            this.virtualizeVideo(sorted[i].vd);
        }
    }

    handleScrollEnd() {
        if (this.isSnapping) return;

        // CSS scroll-snap handles physical alignment.
        // JS only identifies which video is most visible and activates it.
        const container = document.querySelector('.tiktok-container');
        if (!container || this.videos.length === 0) return;

        let mostVisible = null;
        let maxVisibility = 0;

        this.videos.forEach((videoData, index) => {
            const rect = videoData.element.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();

            const visibleTop = Math.max(rect.top, containerRect.top);
            const visibleBottom = Math.min(rect.bottom, containerRect.bottom);
            const visibleHeight = Math.max(0, visibleBottom - visibleTop);
            const visibility = visibleHeight / rect.height;

            if (visibility > maxVisibility) {
                maxVisibility = visibility;
                mostVisible = index;
            }
        });

        if (mostVisible === null) return;

        this.activateVideoAtIndex(mostVisible);
        this.enforceMaxMaterialized();
    }

    activateVideoAtIndex(index) {
        if (index === null || index < 0 || index >= this.videos.length) return;
        if (index !== this.currentVideoIndex) {
            this.currentVideoIndex = index;
            this.persistFeedState();
            this.loadCurrentVideo();
            this.updateDesktopNavButtons();
            return;
        }
        this.updateDesktopNavButtons();
    }

    snapToVideo(index, behavior = 'smooth') {
        const container = document.querySelector('.tiktok-container');
        const videoData = this.videos[index];
        if (!container || !videoData) return;

        if (!videoData.materialized) {
            this.materializeVideo(videoData);
        }

        this.isSnapping = true;
        videoData.element.scrollIntoView({ behavior, block: 'start' });

        clearTimeout(this._snapUnlockTimer);
        this._snapUnlockTimer = setTimeout(() => {
            this.isSnapping = false;
            this.activateVideoAtIndex(index);
            this.enforceMaxMaterialized();
        }, behavior === 'smooth' ? 350 : 50);
    }

    loadCurrentVideo() {
        const currentVideo = this.videos[this.currentVideoIndex];
        if (!currentVideo) return;

        this.persistFeedState(currentVideo.videoId);

        // Garantir que o vídeo actual está materializado
        if (!currentVideo.materialized) {
            this.materializeVideo(currentVideo);
        }

        if (!currentVideo.loaded && currentVideo.video) {
            const nq = window.networkQuality;
            if (nq) currentVideo.video.preload = nq.getPreload();
            currentVideo.video.load();
        }
        this.preloadNearbyVideos();
    }

    persistFeedState(videoId = null) {
        const currentVideoId = String(videoId || this.videos[this.currentVideoIndex]?.videoId || '');
        if (!currentVideoId) return;

        if (window.feedManager && typeof window.feedManager.saveFeedState === 'function') {
            window.feedManager.saveFeedState(currentVideoId);
            return;
        }

        try {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('user_id') || urlParams.has('t')) {
                return;
            }

            localStorage.setItem('mytube_feed_state', JSON.stringify({
                currentVideoId: currentVideoId,
                timestamp: Date.now()
            }));
        } catch (e) {
            // ignore
        }
    }

    preloadNearbyVideos() {
        const nq = window.networkQuality;
        const offsets = (nq && nq.quality === 'low') ? [1] : [-1, 1];
        
        offsets.forEach(offset => {
            const index = this.currentVideoIndex + offset;
            if (index >= 0 && index < this.videos.length) {
                const vd = this.videos[index];
                // Materializar vídeos próximos
                if (!vd.materialized) {
                    this.materializeVideo(vd);
                }
                if (!vd.loaded && vd.video) {
                    if (nq) vd.video.preload = nq.getPreload();
                    vd.video.load();
                }
            }
        });
    }

    playVideo(videoData) {
        if (!videoData) return;
        // Garantir materialização antes de reproduzir
        if (!videoData.materialized) {
            this.materializeVideo(videoData);
        }
        if (videoData.video) {
            // Respeitar o estado de mute atual (global)
            const globalMuted = this.getCurrentMuteState();
            const userInteracted = localStorage.getItem('mytube_user_interacted') === 'true';
            
            // Só tentar com som se o utilizador já interagiu E o som global estiver ativo
            const shouldHaveAudio = userInteracted && !globalMuted;
            
            if (shouldHaveAudio) {
                videoData.video.muted = false;
                videoData.video.play()
                    .then(() => {
                        videoData.element.classList.remove('paused');
                        this.hideAudioPrompt(videoData.videoId);
                        this.updateAudioButtonState(videoData.videoId, false);
                    })
                    .catch(e => {
                        // Fallback: tentar sem som no mobile (autoplay prevent)
                        videoData.video.muted = true;
                        videoData.video.play()
                            .then(() => {
                                videoData.element.classList.remove('paused');
                                this.showAudioPrompt(videoData.videoId);
                                this.updateAudioButtonState(videoData.videoId, true);
                            })
                            .catch(e2 => {
                                this.showAudioPrompt(videoData.videoId);
                            });
                    });
            } else {
                // Manter mutado (utilizador silenciou ou nunca interagiu)
                videoData.video.muted = true;
                videoData.video.play()
                    .then(() => {
                        videoData.element.classList.remove('paused');
                        if (!userInteracted) {
                            this.showAudioPrompt(videoData.videoId);
                        }
                        this.updateAudioButtonState(videoData.videoId, true);
                    })
                    .catch(e => {
                        if (!userInteracted) {
                            this.showAudioPrompt(videoData.videoId);
                        }
                    });
            }
        }
    }

    pauseVideo(videoData) {
        if (videoData && videoData.video) {
            videoData.video.pause();
            videoData.element.classList.add('paused');
            videoData.element.classList.remove('buffering');
        }
    }

    pauseCurrentVideo() {
        const current = this.videos[this.currentVideoIndex];
        if (current) {
            this.pauseVideo(current);
        }
    }

    pauseAllVideos() {
        this.videos.forEach(videoData => {
            if (videoData && videoData.video && !videoData.video.paused) {
                this.pauseVideo(videoData);
            }
        });
    }

    togglePlayPause(videoData) {
        if (!videoData.materialized) {
            this.materializeVideo(videoData);
        }
        if (!videoData.video) return;
        if (videoData.video.paused) {
            videoData.manuallyPaused = false;
            this.playVideo(videoData);
        } else {
            videoData.manuallyPaused = true;
            this.pauseVideo(videoData);
        }
    }

    nextVideo() {
        if (this.currentVideoIndex < this.videos.length - 1) {
            this.scrollToVideo(this.currentVideoIndex + 1);
        }
    }

    previousVideo() {
        if (this.currentVideoIndex > 0) {
            this.scrollToVideo(this.currentVideoIndex - 1);
        }
    }

    scrollToVideo(index) {
        this.snapToVideo(index, 'smooth');
    }

    // ============================================
    // DESKTOP: Botões de navegação (estilo Facebook)
    // ============================================
    setupDesktopNavButtons() {
        const prevBtn = document.getElementById('navPrevBtn');
        const nextBtn = document.getElementById('navNextBtn');
        if (!prevBtn || !nextBtn) return;

        prevBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.previousVideo();
        });

        nextBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.nextVideo();
        });

        this.updateDesktopNavButtons();
    }

    updateDesktopNavButtons() {
        const prevBtn = document.getElementById('navPrevBtn');
        const nextBtn = document.getElementById('navNextBtn');
        if (!prevBtn || !nextBtn) return;

        prevBtn.disabled = this.currentVideoIndex <= 0;
        nextBtn.disabled = this.currentVideoIndex >= this.videos.length - 1;
    }

    updateProgress(videoData) {
        const { video } = videoData;
        if (!video || this._isScrubbing) return;
        const progress = (video.currentTime / video.duration) * 100;
        videoData.progress = progress;

        const progressBar = document.querySelector(`#progress-${videoData.videoId}`);
        if (progressBar) {
            progressBar.style.width = `${progress}%`;
        }
    }

    // Formatar segundos para m:ss
    _formatTime(seconds) {
        if (!seconds || !isFinite(seconds)) return '0:00';
        const m = Math.floor(seconds / 60);
        const s = Math.floor(seconds % 60);
        return `${m}:${s.toString().padStart(2, '0')}`;
    }

    // Sistema de scrubbing (arrastar a barra de progresso)
    setupScrubbing() {
        const container = document.querySelector('.tiktok-container');
        if (!container) return;

        let scrubContainer = null;  // o .progress-container ativo
        let scrubVideoData = null;
        let wasPaused = false;

        const getProgress = (e, el) => {
            const rect = el.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            return Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
        };

        const updateScrub = (ratio) => {
            if (!scrubContainer || !scrubVideoData) return;
            const video = scrubVideoData.video;
            if (!video || !video.duration) return;

            const time = ratio * video.duration;
            const bar = scrubContainer.querySelector('.progress-bar');
            const timeEl = scrubContainer.querySelector('.progress-time');
            if (bar) bar.style.width = `${ratio * 100}%`;
            if (timeEl) timeEl.textContent = `${this._formatTime(time)} / ${this._formatTime(video.duration)}`;
        };

        const startScrub = (e) => {
            const pc = e.target.closest('.progress-container');
            if (!pc) return;

            const videoId = pc.dataset.videoId;
            scrubVideoData = this.getOrCreateVideoData(videoId);
            if (!scrubVideoData || !scrubVideoData.video) return;

            scrubContainer = pc;
            this._isScrubbing = true;
            pc.classList.add('scrubbing');
            wasPaused = scrubVideoData.video.paused;
            scrubVideoData.video.pause();

            const ratio = getProgress(e, pc);
            updateScrub(ratio);
        };

        const moveScrub = (e) => {
            if (!this._isScrubbing || !scrubContainer) return;
            e.preventDefault();
            const ratio = getProgress(e, scrubContainer);
            updateScrub(ratio);
        };

        const endScrub = (e) => {
            if (!this._isScrubbing || !scrubContainer || !scrubVideoData) return;
            const video = scrubVideoData.video;

            // Calcular posição final
            let finalRatio;
            if (e.changedTouches) {
                const rect = scrubContainer.getBoundingClientRect();
                finalRatio = Math.max(0, Math.min(1, (e.changedTouches[0].clientX - rect.left) / rect.width));
            } else {
                finalRatio = getProgress(e, scrubContainer);
            }

            if (video && video.duration) {
                video.currentTime = finalRatio * video.duration;
            }

            scrubContainer.classList.remove('scrubbing');
            this._isScrubbing = false;

            if (!wasPaused && video) {
                video.play().catch(() => {});
            }

            scrubContainer = null;
            scrubVideoData = null;
        };

        // Mouse events
        container.addEventListener('mousedown', (e) => {
            if (e.target.closest('.progress-container')) {
                startScrub(e);
            }
        });
        document.addEventListener('mousemove', moveScrub);
        document.addEventListener('mouseup', endScrub);

        // Touch events
        container.addEventListener('touchstart', (e) => {
            if (e.target.closest('.progress-container')) {
                startScrub(e);
            }
        }, { passive: true });
        document.addEventListener('touchmove', moveScrub, { passive: false });
        document.addEventListener('touchend', endScrub);
    }

    likeVideo(videoId, button = null) {
        // Encontrar o botão antes de tudo
        if (!button) {
            button = document.querySelector(`button[data-video-id="${videoId}"].like-btn`);
        }
        if (!button) {
            button = document.querySelector(`button[data-video-id="${videoId}"].action-btn`);
        }
        if (!button) {
            const allButtons = document.querySelectorAll(`button[data-video-id="${videoId}"]`);
            for (let btn of allButtons) {
                if (btn.querySelector('.icon-heart')) {
                    button = btn;
                    break;
                }
            }
        }

        // Prevenir duplo-clique durante requisição
        if (button && button.dataset.likeLoading === 'true') return;
        if (button) button.dataset.likeLoading = 'true';

        // ======= ATUALIZAÇÃO OTIMISTA (instantânea) =======
        const wasLiked = button ? button.classList.contains('liked') : false;
        const countEl = button ? (button.querySelector('.like-count') || button.querySelector('.action-count')) : null;
        const previousCount = countEl ? parseInt(countEl.textContent) || 0 : 0;
        const optimisticCount = wasLiked ? Math.max(0, previousCount - 1) : previousCount + 1;

        // Registrar update otimista para que like-sync não sobrescreva
        if (window.likeSyncManager) {
            window.likeSyncManager.registerOptimisticUpdate(videoId);
        }

        // Atualizar UI IMEDIATAMENTE
        if (button) {
            if (wasLiked) {
                button.classList.remove('liked');
            } else {
                button.classList.add('liked');
            }

            // Animação de feedback instantânea
            button.style.transform = 'scale(1.3)';
            button.style.transition = 'transform 0.15s ease';
            setTimeout(() => {
                button.style.transform = '';
            }, 150);
        }
        if (countEl) {
            countEl.textContent = optimisticCount.toString();
        }

        // ======= REQUISIÇÃO AO SERVIDOR (em background) =======
        fetch('api/toggle_like.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                video_id: videoId
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const ct = response.headers.get('content-type');
            if (!ct || !ct.includes('application/json')) {
                throw new Error('Resposta não é JSON');
            }
            return response.json();
        })
        .then(data => {
            if (button) button.dataset.likeLoading = 'false';

            if (data.success) {
                // Reconciliar com o valor real do servidor (caso haja diferença)
                if (button) {
                    if (data.liked) {
                        button.classList.add('liked');
                    } else {
                        button.classList.remove('liked');
                    }
                }
                if (countEl && data.likes_count !== undefined) {
                    countEl.textContent = data.likes_count.toString();
                }
            } else {
                // Erro: reverter para estado anterior
                revertLike();
                showMessage(data.error || 'Erro ao curtir vídeo', 'error');
            }
        })
        .catch(error => {
            if (button) button.dataset.likeLoading = 'false';
            // Erro de rede: reverter para estado anterior
            revertLike();
            showMessage(`Erro de conexão`, 'error');
        });

        // Função para reverter caso o servidor rejeite
        function revertLike() {
            if (button) {
                if (wasLiked) {
                    button.classList.add('liked');
                } else {
                    button.classList.remove('liked');
                }
            }
            if (countEl) {
                countEl.textContent = previousCount.toString();
            }
        }
    }

    followUser(userId, button) {
        // Adicionar estado de loading
        if (button.classList.contains('loading')) return;
        button.classList.add('loading');
        
        const wasFollowing = button.classList.contains('following');
        
        fetch('api/toggle_follow.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                user_id: userId
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const ct = response.headers.get('content-type');
            if (!ct || !ct.includes('application/json')) {
                throw new Error('Resposta não é JSON');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const icon = button.querySelector('svg');
                const countEl = button.querySelector('.action-count');
                
                if (data.action === 'followed') {
                    // Agora está seguindo
                    button.classList.add('following');
                    if (icon) icon.outerHTML = '<svg class="icon-check" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                    showMessage('✅ Agora você está seguindo este usuário', 'success');
                } else {
                    // Deixou de seguir
                    const followsYou = button.dataset.followsYou === '1' || data.follows_you;
                    button.classList.remove('following');
                    if (icon) icon.outerHTML = '<svg class="icon-plus" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                    showMessage('👋 Você deixou de seguir este usuário', 'info');
                }
                
                // Atualizar data attribute com info da API
                if (data.follows_you !== undefined) {
                    button.dataset.followsYou = data.follows_you ? '1' : '0';
                }
                
                // Sincronizar todos os botões deste usuário na página
                this.syncFollowButtons(userId, data.is_following, data.follows_you);
            } else {
                console.error('Follow error:', data);
                showMessage(data.error || 'Erro ao seguir usuário', 'error');
            }
        })
        .catch(error => {
            console.error('Network error:', error);
            showMessage(`Erro: ${error.message}`, 'error');
        })
        .finally(() => {
            button.classList.remove('loading');
        });
    }

    // Função para seguir via botão inline (estilo Facebook)
    followUserInline(userId, button) {
        if (button.classList.contains('loading')) return;
        button.classList.add('loading');
        
        const wasFollowing = button.classList.contains('following');
        
        fetch('api/toggle_follow.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                user_id: userId
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const ct = response.headers.get('content-type');
            if (!ct || !ct.includes('application/json')) {
                throw new Error('Resposta não é JSON');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                if (data.action === 'followed') {
                    button.classList.add('following');
                    button.textContent = 'Seguindo';
                    showMessage('✅ Agora você está seguindo este usuário', 'success');
                } else {
                    const followsYou = button.dataset.followsYou === '1' || data.follows_you;
                    button.classList.remove('following');
                    button.textContent = followsYou ? 'Seguir de volta' : 'Seguir';
                    showMessage('👋 Você deixou de seguir este usuário', 'info');
                }
                
                // Atualizar data attribute com info da API
                if (data.follows_you !== undefined) {
                    button.dataset.followsYou = data.follows_you ? '1' : '0';
                }
                
                // Sincronizar todos os botões deste usuário na página
                this.syncFollowButtons(userId, data.is_following, data.follows_you);
            } else {
                showMessage(data.error || 'Erro ao seguir usuário', 'error');
            }
        })
        .catch(error => {
            showMessage(`Erro: ${error.message}`, 'error');
        })
        .finally(() => {
            button.classList.remove('loading');
        });
    }

    // Sincronizar estado de follow em todos os botões do mesmo usuário
    syncFollowButtons(userId, isFollowing, followsYou) {
        // Sincronizar botões laterais (.follow-btn)
        document.querySelectorAll(`.follow-btn[data-user-id="${userId}"]`).forEach(btn => {
            const icon = btn.querySelector('svg');
            const countEl = btn.querySelector('.action-count');
            const btnFollowsYou = followsYou !== undefined ? followsYou : btn.dataset.followsYou === '1';
            
            if (isFollowing) {
                btn.classList.add('following');
                if (icon) icon.outerHTML = '<svg class="icon-check" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            } else {
                btn.classList.remove('following');
                if (icon) icon.outerHTML = '<svg class="icon-plus" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            }
            
            // Atualizar data attribute
            if (followsYou !== undefined) {
                btn.dataset.followsYou = followsYou ? '1' : '0';
            }
        });
        
        // Sincronizar botões inline (.follow-btn-inline)
        document.querySelectorAll(`.follow-btn-inline[data-user-id="${userId}"]`).forEach(btn => {
            const btnFollowsYou = followsYou !== undefined ? followsYou : btn.dataset.followsYou === '1';
            
            if (isFollowing) {
                btn.classList.add('following');
                btn.textContent = 'A seguir';
            } else {
                btn.classList.remove('following');
                btn.textContent = btnFollowsYou ? 'Seguir de volta' : 'Seguir';
            }
            
            // Atualizar data attribute
            if (followsYou !== undefined) {
                btn.dataset.followsYou = followsYou ? '1' : '0';
            }
        });
    }

    updateViews(videoId) {
        // Evitar requests duplicados para o mesmo vídeo na mesma sessão
        if (this._viewsUpdated.has(videoId) || this._viewsInFlight.has(videoId)) {
            return;
        }

        // Rate limiting local: máximo N requests por minuto
        const now = Date.now();
        if (now - this._requestResetTime > 60000) {
            this._requestCount = 0;
            this._requestResetTime = now;
            // Flush pending views da janela anterior
            const pending = this._pendingViews.splice(0);
            pending.forEach(id => this.updateViews(id));
        }
        if (this._requestCount >= this._maxRequestsPerMinute) {
            // Agendar para quando o minuto resetar
            if (!this._pendingViews.includes(videoId)) {
                this._pendingViews.push(videoId);
            }
            return;
        }

        this._viewsInFlight.add(videoId);
        this._requestCount++;

        fetch('api/update_views.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                video_id: videoId
            })
        })
        .then(response => {
            // Verificar se a resposta é válida antes de parsear JSON
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Resposta não é JSON');
            }
            return response.json();
        })
        .then(data => {
            // Views atualizadas - marcar como concluído
            this._viewsUpdated.add(videoId);
        })
        .catch(error => {
            // Silenciar erros de views para não poluir console
            // mas não marcar como concluído para permitir retry futuro
        })
        .finally(() => {
            this._viewsInFlight.delete(videoId);
        });
    }

    // Método removido - comentários gerenciados pelo comments.js

    openShareMenu(videoId) {
        const shareMenu = document.getElementById('shareMenu');
        
        // Se já está aberto, fechar
        if (shareMenu.classList.contains('active')) {
            this.closeShareMenuInternal();
            return;
        }
        
        shareMenu.classList.add('active');
        shareMenu.dataset.videoId = videoId;

        // Remover listener antigo se existir
        if (this._closeShareHandler) {
            document.removeEventListener('click', this._closeShareHandler, true);
        }

        // Criar handler nomeado para poder remover depois
        this._closeShareHandler = (e) => {
            // Ignorar cliques dentro do menu ou no próprio botão de share
            if (shareMenu.contains(e.target) || e.target.closest('.share-btn')) {
                return;
            }
            this.closeShareMenuInternal();
        };

        // Usar capture + requestAnimationFrame para não capturar o clique atual
        requestAnimationFrame(() => {
            document.addEventListener('click', this._closeShareHandler, true);
        });
    }

    closeShareMenuInternal() {
        const shareMenu = document.getElementById('shareMenu');
        if (shareMenu) shareMenu.classList.remove('active');
        if (this._closeShareHandler) {
            document.removeEventListener('click', this._closeShareHandler, true);
            this._closeShareHandler = null;
        }
    }

    toggleAudio(videoId, button) {
        // Marcar que usuário interagiu
        localStorage.setItem('mytube_user_interacted', 'true');
        
        const video = document.getElementById(`video-${videoId}`);
        if (!video) return;
        const icon = button.querySelector('i');
        
        if (video.muted) {
            // Ativar som
            video.muted = false;
            localStorage.setItem('mytube_global_muted', 'false');
            button.classList.add('active');
            icon.className = 'fas fa-volume-up';
            button.title = 'Desativar Som';
            
            // Esconder prompts de áudio
            this.hideAllAudioPrompts();
        } else {
            // Desativar som
            video.muted = true;
            localStorage.setItem('mytube_global_muted', 'true');
            button.classList.remove('active');
            icon.className = 'fas fa-volume-mute';
            button.title = 'Ativar Som';
        }
        
        // Aplicar a todas as outras instâncias de vídeo
        this.updateAllVideosMuteState(video.muted);
    }

    updateAllVideosMuteState(isMuted) {
        // Atualizar todos os vídeos para manter consistência
        this.videos.forEach(videoData => {
            if (videoData.video) videoData.video.muted = isMuted;
            // Também salvar em wasMuted para vídeos virtualizados
            videoData.wasMuted = isMuted;
        });
        
        // Atualizar todos os botões de áudio
        document.querySelectorAll('.audio-toggle').forEach(btn => {
            const icon = btn.querySelector('i');
            if (isMuted) {
                btn.classList.remove('active');
                icon.className = 'fas fa-volume-mute';
                btn.title = 'Ativar Som';
            } else {
                btn.classList.add('active');
                icon.className = 'fas fa-volume-up';
                btn.title = 'Desativar Som';
            }
        });
    }

    initializeAudioState() {
        // Por padrão, tentar sempre com som ativado
        this.videos.forEach(videoData => {
            if (videoData.video) videoData.video.muted = false;
        });
        
        document.querySelectorAll('.audio-toggle').forEach(btn => {
            btn.classList.add('active');
            const icon = btn.querySelector('i');
            icon.className = 'fas fa-volume-up';
            btn.title = 'Desativar Som';
        });
        
        // Configurar cliques no prompt de áudio
        this.setupAudioPrompts();
    }

    setupAudioPrompts() {
        document.querySelectorAll('.audio-prompt-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                e.stopPropagation();
                const videoId = overlay.id.replace('audio-prompt-', '');
                this.enableAudioForAll(videoId);
            });
        });
    }

    showAudioPrompt(videoId) {
        const prompt = document.getElementById(`audio-prompt-${videoId}`);
        if (prompt && !localStorage.getItem('mytube_user_interacted')) {
            prompt.style.display = 'flex';
        }
    }

    hideAudioPrompt(videoId) {
        const prompt = document.getElementById(`audio-prompt-${videoId}`);
        if (prompt) {
            prompt.style.display = 'none';
        }
    }

    hideAllAudioPrompts() {
        document.querySelectorAll('.audio-prompt-overlay').forEach(prompt => {
            prompt.style.display = 'none';
        });
    }

    enableAudioForAll(videoId) {
        // Marcar que o usuário já interagiu
        localStorage.setItem('mytube_user_interacted', 'true');
        localStorage.setItem('mytube_global_muted', 'false');
        
        // Ativar som em todos os vídeos materializados
        this.videos.forEach(videoData => {
            if (videoData.video) videoData.video.muted = false;
        });
        
        // Tocar o vídeo atual com som
        const currentVideo = this.videos.find(v => v.videoId == videoId);
        if (currentVideo && currentVideo.video) {
            currentVideo.video.play().catch(() => {});
        }
        
        // Esconder todos os prompts
        this.hideAllAudioPrompts();
        
        // Atualizar todos os botões
        this.updateAllVideosMuteState(false);
    }

    updateAudioButtonState(videoId, isMuted) {
        const button = document.querySelector(`[data-video-id="${videoId}"].audio-toggle`);
        if (button) {
            const icon = button.querySelector('i');
            if (isMuted) {
                button.classList.remove('active');
                icon.className = 'fas fa-volume-mute';
                button.title = 'Ativar Som';
            } else {
                button.classList.add('active');
                icon.className = 'fas fa-volume-up';
                button.title = 'Desativar Som';
            }
        }
    }

    showLikeAnimation(event) {
        const heart = document.createElement('div');
        heart.innerHTML = '❤️';
        heart.style.cssText = `
            position: fixed;
            left: ${event.clientX}px;
            top: ${event.clientY}px;
            font-size: 2rem;
            pointer-events: none;
            z-index: 9999;
            animation: likeFloat 1s ease-out forwards;
        `;
        
        document.body.appendChild(heart);
        setTimeout(() => heart.remove(), 1000);
    }

    formatCount(count) {
        if (count < 1000) return count.toString();
        if (count < 1000000) return (count / 1000).toFixed(1) + 'K';
        return (count / 1000000).toFixed(1) + 'M';
    }
}

// Helper: registar partilha no servidor e atualizar contador na UI
function recordShare(videoId, platform) {
    const formData = new FormData();
    formData.append('video_id', videoId);
    formData.append('platform', platform);

    fetch('api/record_share.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success && !data.duplicate) {
                // Atualizar contador na UI
                const btn = document.querySelector(`.share-btn[data-video-id="${videoId}"]`);
                if (btn) {
                    const countEl = btn.querySelector('.share-count, .action-count');
                    if (countEl) {
                        const count = data.shares_count || 0;
                        countEl.textContent = count >= 1000 ? (count / 1000).toFixed(1).replace('.0', '') + 'K' : count;
                    }
                }
            }
        })
        .catch(() => { /* silenciar */ });
}

// Helper: gerar URL real do vídeo (funciona em qualquer domínio/servidor)
function getVideoShareUrl(videoId) {
    // Pegar o base path automaticamente a partir do pathname actual
    // Ex: se estiver em https://meusite.com/my/index.php → basePath = /my/
    const path = window.location.pathname;
    const basePath = path.substring(0, path.lastIndexOf('/') + 1);
    return `${window.location.origin}${basePath}index.php?video_id=${videoId}`;
}

// Funções de compartilhamento
function shareToWhatsApp() {
    const videoId = document.getElementById('shareMenu').dataset.videoId;
    const url = getVideoShareUrl(videoId);
    const text = 'Confira este vídeo no MyTube!';
    window.open(`https://wa.me/?text=${encodeURIComponent(text + ' ' + url)}`, '_blank');
    recordShare(videoId, 'whatsapp');
    closeShareMenu();
}

function shareToFacebook() {
    const videoId = document.getElementById('shareMenu').dataset.videoId;
    const url = getVideoShareUrl(videoId);
    window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`, '_blank');
    recordShare(videoId, 'facebook');
    closeShareMenu();
}

function shareToChat() {
    const videoId = document.getElementById('shareMenu').dataset.videoId;
    closeShareMenu();
    
    // Mostrar modal de seleção de conversa
    const overlay = document.getElementById('chatShareOverlay');
    if (!overlay) return;
    overlay.style.display = 'flex';
    overlay.dataset.videoId = videoId;
    
    // Carregar conversas recentes
    loadChatShareConversations('');
    
    // Setup busca
    const searchInput = document.getElementById('chatShareSearch');
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
        searchInput.oninput = function() {
            loadChatShareConversations(this.value.trim());
        };
    }
}

function closeChatShareModal() {
    const overlay = document.getElementById('chatShareOverlay');
    if (overlay) overlay.style.display = 'none';
}

let chatShareSearchTimeout = null;
function loadChatShareConversations(search) {
    clearTimeout(chatShareSearchTimeout);
    chatShareSearchTimeout = setTimeout(() => {
        const list = document.getElementById('chatShareList');
        if (!list) return;
        list.innerHTML = '<div class="chat-share-loading"><div class="spinner-small"></div></div>';
        
        fetch('api/search_chat_users.php?search=' + encodeURIComponent(search))
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.users || data.users.length === 0) {
                    list.innerHTML = '<div class="chat-share-empty"><i class="fas fa-inbox"></i><p>Nenhuma conversa encontrada</p></div>';
                    return;
                }
                list.innerHTML = data.users.map(u => {
                    const pic = escapeHtml(u.profile_picture || 'assets/images/avatars/default.webp');
                    const name = escapeHtml(u.full_name || u.username);
                    const uname = escapeHtml(u.username);
                    // data-user-id evita JS injection via onclick inline
                    return `
                        <div class="chat-share-item" data-user-id="${u.id}" data-username="${uname}">
                            <img src="${pic}" alt="" class="chat-share-avatar">
                            <div class="chat-share-info">
                                <div class="chat-share-name">${name}</div>
                                <div class="chat-share-username">@${uname}</div>
                            </div>
                            <i class="fas fa-paper-plane chat-share-send-icon"></i>
                        </div>
                    `;
                }).join('');
                // event delegation — lê user-id/username via data-attributes
                list.querySelectorAll('.chat-share-item').forEach(item => {
                    item.addEventListener('click', () => {
                        sendVideoToChat(parseInt(item.dataset.userId, 10), item.dataset.username);
                    });
                });
            })
            .catch(() => {
                list.innerHTML = '<div class="chat-share-empty"><p>Erro ao carregar</p></div>';
            });
    }, 200);
}

function sendVideoToChat(userId, username) {
    const overlay = document.getElementById('chatShareOverlay');
    const videoId = overlay ? overlay.dataset.videoId : null;
    if (!videoId) return;
    
    const url = getVideoShareUrl(videoId);
    const shareText = '🎬 Confira este vídeo no MyTube! ' + url;
    
    // Marcar item como enviando
    const items = document.querySelectorAll('.chat-share-item');
    items.forEach(item => item.style.pointerEvents = 'none');
    const clickedItem = event.currentTarget;
    if (clickedItem) {
        const icon = clickedItem.querySelector('.chat-share-send-icon');
        if (icon) {
            icon.className = 'fas fa-spinner fa-spin chat-share-send-icon';
            icon.style.opacity = '1';
        }
    }
    
    // Enviar mensagem via API PHP sem sair do feed
    const formData = new FormData();
    formData.append('receiver_id', userId);
    formData.append('message', shareText);
    
    fetch('api/send_chat_message.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeChatShareModal();
            recordShare(videoId, 'chat');
            showMessage('✅ Vídeo enviado para @' + username + '!', 'success');
        } else {
            showMessage('❌ ' + (data.error || 'Erro ao enviar'), 'error');
            items.forEach(item => item.style.pointerEvents = '');
            if (clickedItem) {
                const icon = clickedItem.querySelector('.chat-share-send-icon');
                if (icon) {
                    icon.className = 'fas fa-paper-plane chat-share-send-icon';
                    icon.style.opacity = '';
                }
            }
        }
    })
    .catch(() => {
        showMessage('❌ Erro de conexão', 'error');
        items.forEach(item => item.style.pointerEvents = '');
    });
}

function copyLink() {
    const videoId = document.getElementById('shareMenu').dataset.videoId;
    const url = getVideoShareUrl(videoId);
    navigator.clipboard.writeText(url).then(() => {
        showMessage('📋 Link copiado!', 'success');
    }).catch(() => {
        // Fallback para browsers que não suportam clipboard API
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showMessage('📋 Link copiado!', 'success');
    });
    // Copiar link não conta como partilha — não há garantia que foi enviado a alguém
    closeShareMenu();
}

function closeShareMenu() {
    // Usar o método da instância se disponível, senão fechar directamente
    if (window.tiktokPlayer && window.tiktokPlayer.closeShareMenuInternal) {
        window.tiktokPlayer.closeShareMenuInternal();
    } else {
        document.getElementById('shareMenu').classList.remove('active');
    }
}

function toggleProfile() {
    window.location.href = 'profile.php';
}

// Adicionar CSS para animação de like
const style = document.createElement('style');
style.textContent = `
    @keyframes likeFloat {
        0% {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        100% {
            transform: translateY(-100px) scale(1.5);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Função para mostrar mensagens de feedback
function showMessage(message, type = 'info') {
    // Remover mensagem anterior se existir
    const existingMessage = document.querySelector('.toast-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Criar nova mensagem
    const messageEl = document.createElement('div');
    messageEl.className = `toast-message toast-${type}`;
    messageEl.textContent = message;
    messageEl.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#3b82f6'};
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        font-weight: 600;
        max-width: 300px;
        word-wrap: break-word;
        animation: slideIn 0.3s ease-out;
    `;
    
    document.body.appendChild(messageEl);
    
    // Remover após 3 segundos
    setTimeout(() => {
        if (messageEl.parentNode) {
            messageEl.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => messageEl.remove(), 300);
        }
    }, 3000);
}

// CSS para animações de toast
const toastStyle = document.createElement('style');
toastStyle.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(toastStyle);

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('.tiktok-container');
    if (container) {
        const existingVideos = container.querySelectorAll('.video-item:not(.initial-loading)');
        
        if (existingVideos.length > 0) {
            window.tiktokPlayer = new TikTokPlayer();
        } else {
            window.tiktokPlayer = new TikTokPlayer();
            window.tiktokPlayer.initialized = false;
        }
    }
});

// Inicializar quando novos vídeos são carregados via AJAX
window.addEventListener('videosLoaded', (event) => {
    const { videoCount, isFirstLoad } = event.detail || {};
    
    if (!window.tiktokPlayer) {
        window.tiktokPlayer = new TikTokPlayer();
        window.tiktokPlayer.initialized = false;
    }
    
    if (!window.tiktokPlayer.initialized) {
        window.tiktokPlayer.init();
        window.tiktokPlayer.initialized = true;
    } else {
        window.tiktokPlayer.setupVideos();
        window.tiktokPlayer.setupIntersectionObserver();
        window.tiktokPlayer.setupRecycleObserver();
        
        if (window.tiktokPlayer.videos.length > 0) {
            window.tiktokPlayer.loadCurrentVideo();
        }
    }
});

// ========== SISTEMA DE PESQUISA ==========
let searchTimeout = null;

// Utility: escapar HTML para prevenir XSS via innerHTML
const escapeHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

function openSearchModal() {
    const modal = document.getElementById('searchModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Focar no input
        setTimeout(() => {
            const input = document.getElementById('searchInput');
            if (input) input.focus();
        }, 100);
    }
}

function closeSearchModal() {
    const modal = document.getElementById('searchModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
        
        // Limpar pesquisa
        const input = document.getElementById('searchInput');
        if (input) input.value = '';
        
        const results = document.getElementById('searchResults');
        if (results) {
            results.innerHTML = `
                <div class="search-placeholder">
                    <i class="fas fa-search"></i>
                    <p>Pesquise por usuários, vídeos ou hashtags</p>
                </div>
            `;
        }
        
        const clearBtn = document.getElementById('searchClear');
        if (clearBtn) clearBtn.style.display = 'none';
    }
}

function performSearch(query) {
    const results = document.getElementById('searchResults');
    
    if (query.length < 2) {
        results.innerHTML = `
            <div class="search-placeholder">
                <i class="fas fa-search"></i>
                <p>Digite pelo menos 2 caracteres</p>
            </div>
        `;
        return;
    }
    
    // Mostrar loading
    results.innerHTML = `
        <div class="search-loading">
            <div class="spinner"></div>
        </div>
    `;
    
    fetch(`api/search.php?q=${encodeURIComponent(query)}`)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const ct = response.headers.get('content-type');
            if (!ct || !ct.includes('application/json')) throw new Error('Resposta não é JSON');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                renderSearchResults(data.users || [], data.videos || [], data.hashtags || []);
            } else {
                results.innerHTML = `
                    <div class="search-no-results">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>Erro ao pesquisar</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            results.innerHTML = `
                <div class="search-no-results">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Erro de conexão</p>
                </div>
            `;
        });
}

function renderSearchResults(users, videos, hashtags = []) {
    const results = document.getElementById('searchResults');
    
    if (users.length === 0 && videos.length === 0 && hashtags.length === 0) {
        results.innerHTML = `
            <div class="search-no-results">
                <i class="fas fa-search"></i>
                <p>Nenhum resultado encontrado</p>
            </div>
        `;
        return;
    }
    
    let html = '';

    // Seção de hashtags
    if (hashtags.length > 0) {
        html += `
            <div class="search-section">
                <div class="search-section-title">Hashtags</div>
                ${hashtags.map(hashtag => `
                    <div class="search-result-hashtag" onclick="window.location.href='hashtags.php?tag=${encodeURIComponent(hashtag.slug)}'">
                        <div class="search-result-hashtag-name">#${escapeHtml(hashtag.name)}</div>
                        <div class="search-result-hashtag-meta">${formatCount(hashtag.posts_count)} posts</div>
                    </div>
                `).join('')}
            </div>
        `;
    }
    
    // Seção de usuários
    if (users.length > 0) {
        html += `
            <div class="search-section">
                <div class="search-section-title">Usuários</div>
                ${users.map(user => `
                    <div class="search-result-item" onclick="window.location.href='perfil.php?id=${encodeURIComponent(user.id)}'">
                        <img src="${escapeHtml(user.profile_picture_url || 'assets/images/avatars/' + user.profile_picture)}" 
                             alt="" 
                             class="search-result-avatar"
                             loading="lazy">
                        <div class="search-result-info">
                            <div class="search-result-name">
                                @${escapeHtml(user.username)}
                                ${user.is_verified ? '<i class="fas fa-check-circle verified-badge"></i>' : ''}
                            </div>
                            <div class="search-result-meta">
                                ${user.full_name ? escapeHtml(user.full_name) + ' · ' : ''}${formatCount(user.followers_count)} seguidores · ${user.videos_count} vídeos
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }
    
    // Seção de vídeos
    if (videos.length > 0) {
        html += `
            <div class="search-section">
                <div class="search-section-title">Vídeos</div>
                ${videos.map(video => `
                    <div class="search-result-video" onclick="window.location.href='index.php?video_id=${encodeURIComponent(video.id)}&user_id=${encodeURIComponent(video.user.id)}'">
                        <video class="search-result-thumbnail" muted preload="metadata">
                            <source src="${escapeHtml(resolveVideoUrl(video.video_path))}#t=0.5" type="video/mp4">
                        </video>
                        <div class="search-result-video-info">
                            <div class="search-result-video-title">${escapeHtml(video.title || 'Sem título')}</div>
                            <div class="search-result-video-author">
                                @${escapeHtml(video.user.username)}
                                ${video.user.is_verified ? '<i class="fas fa-check-circle verified-badge" style="color:#3b82f6;font-size:11px;"></i>' : ''}
                            </div>
                            <div class="search-result-video-stats">
                                ${formatCount(video.views_count)} visualizações · ${formatCount(video.likes_count)} curtidas
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }
    
    results.innerHTML = html;
}

function formatCount(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1).replace('.0', '') + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1).replace('.0', '') + 'K';
    }
    return num.toString();
}

// Event listeners para pesquisa
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('searchInput');
    const searchClear = document.getElementById('searchClear');
    const searchModal = document.getElementById('searchModal');
    
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            // Mostrar/esconder botão limpar
            if (searchClear) {
                searchClear.style.display = query.length > 0 ? 'flex' : 'none';
            }
            
            // Debounce a pesquisa
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                performSearch(query);
            }, 300);
        });
    }
    
    if (searchClear) {
        searchClear.addEventListener('click', () => {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
                searchClear.style.display = 'none';
                
                const results = document.getElementById('searchResults');
                if (results) {
                    results.innerHTML = `
                        <div class="search-placeholder">
                            <i class="fas fa-search"></i>
                            <p>Pesquise por usuários, vídeos ou hashtags</p>
                        </div>
                    `;
                }
            }
        });
    }
    
    // Fechar modal com ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && searchModal && searchModal.classList.contains('active')) {
            closeSearchModal();
        }
    });
    
    // Fechar modal ao clicar no backdrop
    if (searchModal) {
        searchModal.addEventListener('click', (e) => {
            if (e.target === searchModal) {
                closeSearchModal();
            }
        });
    }
});