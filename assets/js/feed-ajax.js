// Sistema de Feed AJAX - MyTube
class FeedManager {
    constructor() {
        this.offset = 0;
        this.loading = false;
        this.hasMore = true;
        this.videosContainer = null;
        this.loadingIndicator = null;
        this.lastRequest = 0;
        this.videoObserver = null;
        this.loadedVideoIds = new Set();
        this.isFirstLoadComplete = false;
        
        // Chave única de storage — localStorage é a ÚNICA fonte de verdade
        // Sem flags auxiliares, sem signals, sem race conditions
        this.storageKey = 'mytube_feed_state';
        
        this.feedSessionId = this.generateFeedSession();
        
        // Dados brutos da API (videoId → objecto JSON puro, serializável)
        // tiktokPlayer.videos contém refs DOM que não podem ir para localStorage
        this._rawVideoData = new Map();
        
        this._cleanOldCache();
    }
    
    // Devolver a fila de vídeos não vistos ainda (do índice atual em diante)
    // Usa _rawVideoData (dados puros da API, serializáveis) em vez das refs DOM do tiktokPlayer
    // SINCRONIZA com o estado actual do DOM antes de devolver (likes, comentários, follows)
    _getUnseenQueue() {
        if (!window.tiktokPlayer) return [];
        const domVideos = window.tiktokPlayer.videos || [];
        const currentIndex = window.tiktokPlayer.currentVideoIndex || 0;
        
        // Pegar os vídeos do índice actual em diante (ainda não vistos)
        return domVideos
            .slice(currentIndex)
            .map(vd => {
                const raw = this._rawVideoData.get(String(vd.videoId));
                if (!raw) return null;
                
                // Sincronizar dados interactivos do DOM → rawData
                // Sem isto, likes/comentários feitos durante a sessão perdem-se ao restaurar
                const videoEl = vd.element || document.querySelector(`.video-item[data-video-id="${vd.videoId}"]`);
                if (videoEl) {
                    // Like: ler o estado actual do botão e do contador
                    const likeBtn = videoEl.querySelector('.like-btn');
                    if (likeBtn) {
                        raw.user_liked = likeBtn.classList.contains('liked');
                        const likeCount = videoEl.querySelector('.like-count');
                        if (likeCount) {
                            raw.likes_count = parseInt(likeCount.textContent) || 0;
                        }
                    }
                    
                    // Comentários: ler o contador actual
                    const commentBtn = videoEl.querySelector('.comment-btn');
                    if (commentBtn) {
                        const commentCount = commentBtn.querySelector('.action-count');
                        if (commentCount) {
                            raw.comments_count = parseInt(commentCount.textContent) || 0;
                        }
                    }
                    
                    // Follow: ler o estado actual do botão
                    const followBtn = videoEl.querySelector('.follow-btn');
                    if (followBtn) {
                        raw.user_following = followBtn.classList.contains('following');
                    }
                }
                
                return raw;
            })
            .filter(Boolean);
    }
    
    // Limpar formatos antigos de cache (migração)
    _cleanOldCache() {
        try {
            // Migrar de sessionStorage antigo para localStorage
            const oldRaw = sessionStorage.getItem(this.storageKey);
            if (oldRaw) {
                sessionStorage.removeItem(this.storageKey);
            }
            // Apagar flag legada (já não é usada)
            localStorage.removeItem('mytube_restore_feed');
        } catch (e) {
            // ignore
        }
    }
    
    // Gerar ID único para sessão do feed
    generateFeedSession() {
        return 'feed_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
    
    init() {
        // Encontrar containers
        this.videosContainer = document.getElementById('videoContainer');
        this.loadingIndicator = document.getElementById('loadingIndicator');
        
        if (!this.videosContainer) {
            return;
        }
        
        // Verificar se é feed de perfil (não salvar/restaurar)
        const urlParams = new URLSearchParams(window.location.search);
        const isProfileFeed = urlParams.has('user_id') || urlParams.has('video_id');
        
        // Configurar scroll infinito PRIMEIRO para que o IntersectionObserver
        // já exista quando os vídeos forem renderizados (restauro ou carga nova)
        this.setupInfiniteScroll();
        
        // Verificar se devemos restaurar o feed salvo
        const shouldRestore = !isProfileFeed && this.shouldRestoreFeed();
        
        if (shouldRestore) {
            this.restoreSavedFeed();
        } else {
            // Carregar vídeos novos
            this.loadVideos();
        }
        
        // Configurar persistência (só no feed principal)
        if (!isProfileFeed) {
            this.setupPersistence();
        }
    }
    
    // ======================================================
    // DECISÃO DE RESTAURO — determinística, sem flags
    // ======================================================
    //
    // Regras simples:
    //   1. ?t= no URL        → feed novo (logo MyTube)
    //   2. F5 / reload       → feed novo
    //   3. Guest / perfil    → sem restauro
    //   4. Tudo o resto      → restaurar se existir estado recente
    //
    // O localStorage com o estado é a ÚNICA fonte de verdade.
    // Sem flags auxiliares, sem sinais, sem race conditions.
    // ======================================================
    shouldRestoreFeed() {
        if (window.isGuestMode) return false;
        
        const urlParams = new URLSearchParams(window.location.search);
        
        // Logo MyTube → feed novo
        if (urlParams.has('t')) {
            localStorage.removeItem(this.storageKey);
            return false;
        }
        
        // Feed de perfil
        if (urlParams.has('user_id') || urlParams.has('video_id')) {
            return false;
        }
        
        // Reload (F5) → feed novo
        const navEntries = performance.getEntriesByType('navigation');
        const navType = navEntries.length > 0 ? navEntries[0].type : 'navigate';
        if (navType === 'reload') {
            localStorage.removeItem(this.storageKey);
            return false;
        }
        
        // Para qualquer outro tipo de navegação (back, link, botão, etc.):
        // restaurar se existir estado válido e recente
        return this._hasFreshSavedState();
    }
    
    // Verificar se existe estado salvo e se é recente (< 30 min)
    _hasFreshSavedState() {
        const raw = localStorage.getItem(this.storageKey);
        if (!raw) return false;
        
        try {
            const state = JSON.parse(raw);
            const age = Date.now() - (state.timestamp || 0);
            if (age > 30 * 60 * 1000) {
                localStorage.removeItem(this.storageKey);
                return false;
            }
            return !!state.currentVideoId;
        } catch (e) {
            localStorage.removeItem(this.storageKey);
            return false;
        }
    }
    
    // Restaurar feed salvo
    // Se há vídeos não vistos em cache, reidratá-los directamente (zero requests API)
    // Se não, pedir à API começando pelo vídeo onde parou (fallback)
    restoreSavedFeed() {
        try {
            const savedState = JSON.parse(localStorage.getItem(this.storageKey));
            
            if (!savedState || !savedState.currentVideoId) {
                this.loadVideos();
                return;
            }
            
            const queue = savedState.unseenQueue;
            const hasQueue = Array.isArray(queue) && queue.length > 0;
            
            if (hasQueue) {
                console.log(`[Feed] Restaurando ${queue.length} vídeo(s) não vistos do cache — sem request à API`);
                
                // Remover o loading inicial
                this.removeInitialLoading();
                
                // Reidratar vídeos directamente da cache
                const newVideos = queue.filter(v => !this.loadedVideoIds.has(v.id));
                newVideos.forEach(v => {
                    this.loadedVideoIds.add(v.id);
                    this._rawVideoData.set(String(v.id), v); // Restaurar também o mapa de dados brutos
                });
                this.renderVideos(newVideos);
                
                // Restaurar paginação para que o scroll infinito continue do ponto certo
                this.offset = savedState.nextOffset || 0;
                this.hasMore = savedState.hasMore !== undefined ? savedState.hasMore : true;
                
            } else {
                // Fallback: a fila estava vazia (utilizador viu tudo) → pedir vídeos novos
                console.log('[Feed] Fila vazia — pedindo vídeos novos à API');
                window.startVideoId = savedState.currentVideoId;
                this.loadVideos();
            }
            
        } catch (e) {
            console.error('[Feed] Erro ao restaurar:', e);
            this.loadVideos();
        }
    }
    
    // Salvar estado do feed — guarda o vídeo actual + fila de não vistos
    // Chamado pelo tiktokPlayer.persistFeedState() A CADA troca de vídeo
    // e também pelo beforeunload como rede de segurança
    saveFeedState(currentVideoId = null) {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('user_id') || urlParams.has('t')) return;
        if (!currentVideoId) return;
        
        const unseenQueue = this._getUnseenQueue();
        
        const state = {
            currentVideoId: currentVideoId,
            unseenQueue: unseenQueue.length > 0 ? unseenQueue : [],
            nextOffset: this.offset,
            hasMore: this.hasMore,
            timestamp: Date.now()
        };
        
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(state));
        } catch (e) {
            // ignore — se localStorage estiver cheio, continua sem cache
        }
    }
    
    // Configurar persistência — simples: apenas beforeunload como rede de segurança
    // O estado principal já é gravado pelo tiktokPlayer a cada troca de vídeo
    setupPersistence() {
        window.addEventListener('beforeunload', () => {
            const currentVideoId = window.tiktokPlayer?.videos?.[window.tiktokPlayer.currentVideoIndex]?.videoId;
            this.saveFeedState(currentVideoId);
        });
    }
    
    // Limpar estado salvo
    clearSavedState() {
        localStorage.removeItem(this.storageKey);
    }
    
    async loadVideos() {
        if (this.loading || !this.hasMore) return;
        
        // Evitar requisições muito frequentes
        const now = Date.now();
        if (this.lastRequest && (now - this.lastRequest) < 1000) {
            return;
        }
        
        this.loading = true;
        this.lastRequest = now;
        this.showLoading();
        
        try {
            // Construir URL com parâmetros
            let url = `api/get_feed.php?offset=${this.offset}&feed_session=${this.feedSessionId}&t=${Date.now()}`;
            
            // Modo convidado
            if (window.isGuestMode) {
                url += '&guest=1';
            }
            
            // Se é modo perfil, adicionar parâmetros
            if (window.feedMode === 'profile' && window.profileUserId) {
                url += `&profile_user=${window.profileUserId}`;
            }
            
            // Se tem vídeo inicial específico (de notificação ou modo perfil)
            if (window.startVideoId && this.offset === 0) {
                url += `&start_video=${window.startVideoId}`;
            }
            
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-cache'
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || data.message || `Erro HTTP ${response.status}`);
            }
            
            if (data.success === false) {
                throw new Error(data.error || data.message || 'Erro na API');
            }
            
            if (data.success && data.videos && data.videos.length > 0) {
                // Remover loading inicial se for a primeira carga
                if (this.offset === 0) {
                    this.removeInitialLoading();
                }
                
                // Filtrar vídeos duplicados (segurança extra)
                const newVideos = data.videos.filter(v => !this.loadedVideoIds.has(v.id));
                
                if (newVideos.length > 0) {
                    // Registrar IDs carregados + guardar dados brutos para persistência
                    newVideos.forEach(v => {
                        this.loadedVideoIds.add(v.id);
                        this._rawVideoData.set(String(v.id), v); // Dados puros da API (serializáveis)
                    });
                    
                    // Renderizar novos vídeos
                    this.renderVideos(newVideos);
                }
                
                // Atualizar estado
                this.offset = data.next_offset;
                this.hasMore = data.has_more;
                
            } else if (this.offset === 0) {
                this.showEmptyFeed();
            } else {
                this.hasMore = false;
            }
            
        } catch (error) {
            if (this.offset === 0) {
                this.showInitialError(error.message);
            } else {
                this.showError('Erro ao carregar mais vídeos. Tente novamente.');
            }
        } finally {
            this.loading = false;
            this.hideLoading();
        }
    }
    
    renderVideos(videos) {
        videos.forEach(video => {
            const videoElement = this.createVideoElement(video);
            this.videosContainer.appendChild(videoElement);
            
            // Registrar no IntersectionObserver se disponível
            if (this.videoObserver) {
                this.videoObserver.observe(videoElement);
            }
        });
        
        // Reinicializar funcionalidades após adicionar novos vídeos
        this.initializeVideoFeatures();
    }
    
    createVideoElement(video) {
        const videoDiv = document.createElement('div');
        videoDiv.className = 'video-item';
        videoDiv.dataset.videoId = video.id;
        // Store school info for floating score micro-interactions
        if (video.school_name) {
            videoDiv.dataset.schoolName = video.school_name;
            videoDiv.dataset.schoolShort = video.school_short || video.school_name;
        }
        videoDiv.dataset.authorId = video.user.id;

        const escapeHtml = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        // Converte URLs em texto HTML-escapado para links clicáveis
        const linkifyHtml = (escapedHtml) => escapedHtml.replace(
            /(https?:\/\/(?:[^\s<>"'&]|&amp;)*)/gi,
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="description-link" onclick="event.stopPropagation()">$1</a>'
        );

        const decodeHtml = (value) => {
            const textarea = document.createElement('textarea');
            textarea.innerHTML = String(value || '');
            return textarea.value;
        };

        const CAPTION_COLLAPSE_LIMIT = 120;
        const NO_SPACE_FALLBACK_OFFSET = 6;

        const normalizeCaption = (value) => String(decodeHtml(value) || '')
            .replace(/\s+/g, ' ')
            .trim();

        const buildCaptionData = (rawText) => {
            const full = normalizeCaption(rawText);

            if (!full) {
                return { full: '', collapsed: '', isCollapsible: false };
            }

            if (full.length <= CAPTION_COLLAPSE_LIMIT) {
                return { full, collapsed: full, isCollapsible: false };
            }

            const candidate = full.slice(0, CAPTION_COLLAPSE_LIMIT + 1);
            let cutIndex = candidate.lastIndexOf(' ');

            if (cutIndex < Math.floor(CAPTION_COLLAPSE_LIMIT * 0.55)) {
                cutIndex = Math.max(1, CAPTION_COLLAPSE_LIMIT - NO_SPACE_FALLBACK_OFFSET);
            }

            const collapsed = full.slice(0, cutIndex).trimEnd();
            return { full, collapsed, isCollapsible: true };
        };

        const captionData = buildCaptionData(video.description);
        const captionBlock = captionData.full
            ? `
                <div class="video-caption-block ${captionData.isCollapsible ? 'is-collapsed' : ''}">
                    <p class="video-caption"
                       data-caption-full="${escapeHtml(captionData.full)}"
                       data-caption-collapsed="${escapeHtml(captionData.collapsed)}">${linkifyHtml(escapeHtml(captionData.isCollapsible ? captionData.collapsed : captionData.full))}</p>
                    ${captionData.isCollapsible ? '<button type="button" class="caption-toggle-btn" aria-expanded="false">...Ver mais</button>' : ''}
                </div>
            `
            : '';

        const hashtagChips = Array.isArray(video.hashtags) && video.hashtags.length > 0
            ? `
                <div class="video-hashtags">
                    ${video.hashtags.map((hashtag) => {
                        const slug = (hashtag && hashtag.slug) ? String(hashtag.slug) : '';
                        const name = (hashtag && hashtag.name) ? String(hashtag.name) : '';
                        if (!slug || !name) return '';
                        const href = `hashtags.php?tag=${encodeURIComponent(slug)}`;
                        return `<a class="video-hashtag-link" href="${href}">#${escapeHtml(name)}</a>`;
                    }).join('')}
                </div>
            `
            : '';
        
        // Contar vídeos existentes para definir index
        const existingVideos = document.querySelectorAll('.video-item:not(.initial-loading)');
        videoDiv.dataset.index = existingVideos.length;
        
        // Build action buttons based on guest mode
        let actionButtonsHTML = '';
        if (window.isGuestMode) {
            actionButtonsHTML = `
                <button class="action-btn like-btn" onclick="showGuestLoginModal(); return false;">
                    <svg class="icon-heart" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    <span class="like-count">${this.formatNumber(video.likes_count)}</span>
                </button>
                
                <button class="action-btn comment-btn" onclick="showGuestLoginModal(); return false;">
                    <svg class="icon-comment" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    <span class="action-count">${this.formatNumber(video.comments_count)}</span>
                </button>
                
                <div class="more-btn-wrapper">
                    <button class="action-btn more-btn" data-video-id="${video.id}" title="Mais opções">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                        <span class="action-count"></span>
                    </button>
                    <div class="more-menu" id="more-menu-${video.id}">
                        <button class="more-menu-item" data-action="share" data-video-id="${video.id}">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4l5 4-5 4"/><path d="M18 8H8a4 4 0 0 0-4 4v1"/><path d="M4 18h12a2 2 0 0 0 2-2v-1"/></svg>
                            Partilhar
                        </button>
                    </div>
                </div>
            `;
        } else {
            let followBtn = '';
            if (video.user.id != window.currentUserId) {
                followBtn = `
                    <button class="action-btn follow-btn ${video.user_following ? 'following' : ''}" 
                            data-user-follow="${video.user.id}"
                            data-user-id="${video.user.id}"
                            data-follows-you="${video.author_follows_you ? '1' : '0'}">
                        ${video.user_following ? '<svg class="icon-check" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' : '<svg class="icon-plus" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>'}
                        <span class="action-count"></span>
                    </button>
                `;
            }

            // Itens do menu ⋯ (contextuais)
            const isOwner = video.user.id == window.currentUserId;

            // Botão de denúncia (apenas para vídeos de outros utilizadores)
            const reportItem = !isOwner ? `
                <button class="more-menu-item" data-action="report" data-video-id="${video.id}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                    Denunciar
                </button>
            ` : '';

            // Botão de apagar (dono ou admin)
            const deleteItem = (isOwner || window.isAdmin) ? `
                <hr class="more-menu-divider">
                <button class="more-menu-item danger delete-btn" data-video-id="${video.id}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    Apagar vídeo
                </button>
            ` : '';

            // Botão de boost (admin)
            const isBoosted = video.is_boosted;
            const boostItem = window.isAdmin ? `
                <button class="more-menu-item boost-btn ${isBoosted ? 'boosted' : ''}" data-video-id="${video.id}" data-boosted="${isBoosted ? '1' : '0'}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="${isBoosted ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    ${isBoosted ? 'Remover Boost' : 'Dar Boost'}
                </button>
            ` : '';
            
            actionButtonsHTML = `
                <button class="action-btn like-btn ${video.user_liked ? 'liked' : ''}" 
                             data-video-like="${video.id}"
                             data-video-id="${video.id}">
                    <svg class="icon-heart" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    <span class="like-count">${this.formatNumber(video.likes_count)}</span>
                </button>
                
                <button class="action-btn comment-btn" 
                        data-video-id="${video.id}">
                    <svg class="icon-comment" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    <span class="action-count">${this.formatNumber(video.comments_count)}</span>
                </button>
                
                ${followBtn}
                
                <div class="more-btn-wrapper">
                    <button class="action-btn more-btn" data-video-id="${video.id}" title="Mais opções">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                        <span class="action-count"></span>
                    </button>
                    <div class="more-menu" id="more-menu-${video.id}">
                        <button class="more-menu-item" data-action="share" data-video-id="${video.id}">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4l5 4-5 4"/><path d="M18 8H8a4 4 0 0 0-4 4v1"/><path d="M4 18h12a2 2 0 0 0 2-2v-1"/></svg>
                            Partilhar
                        </button>
                        ${reportItem}
                        ${deleteItem}
                        ${boostItem}
                    </div>
                </div>
            `;
        }
        
        videoDiv.innerHTML = `
            <!-- Player de vídeo centralizado -->
            <div class="video-player">
                <video 
                    id="video-${video.id}"
                    loop
                    muted
                    playsinline
                    preload="metadata"
                    data-has-audio="true"
                    data-video-id="${video.id}"
                    data-video-path="${video.video_path}">
                    <source src="${video.video_url || resolveVideoUrl(video.video_path)}" type="video/mp4">
                    Seu navegador não suporta o elemento de vídeo.
                </video>
                
                <!-- Controles de áudio - Voltaram para dentro do player, mas com cálculo inteligente de posição -->
                <div class="video-controls">
                    <button class="audio-toggle" data-video-id="${video.id}" title="Ativar/Desativar Som">
                        <i class="fas fa-volume-up"></i>
                    </button>
                </div>
                
                <!-- Overlay para ativar som -->
                <div class="audio-prompt-overlay" id="audio-prompt-${video.id}" style="display: none;">
                    <div class="audio-prompt-content">
                        <i class="fas fa-volume-up"></i>
                        <h3>Toque para ativar o som</h3>
                        <p>Clique aqui para reproduzir com áudio</p>
                    </div>
                </div>
                
                <!-- Informações do vídeo na parte inferior -->
                <div class="video-info-overlay">
                    <div class="video-author-row">
                        <a href="perfil.php?id=${video.user.id}" class="video-author-link">
                            <img src="${video.user.profile_picture_url || 'assets/images/avatars/' + escapeHtml(video.user.profile_picture)}" 
                                 alt="${escapeHtml(video.user.username)}" 
                                 class="video-author-avatar"
                                 loading="lazy">
                            <span class="video-author-name">
                                ${escapeHtml(video.user.full_name || video.user.username)}
                                ${video.user.is_verified ? '<i class="fas fa-check-circle verified-badge"></i>' : ''}
                            </span>
                        </a>
                        ${(() => {
                            const pts = video.ranking_points || video.user.ranking_points || 0;
                            const school = video.school_short || video.user.school_short || video.school_name || video.user.school_name || '';
                            if (!school) return '';
                            const fmtPts = pts >= 1000 ? (pts / 1000).toFixed(1).replace('.0','') + 'k' : String(pts);
                            return `<a href="ranking.php" class="ranking-badge" title="Ver Ranking">
                                <span class="ranking-badge-icon"><svg class="mytube-rank-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:1.2em; height:1.2em; vertical-align:middle; display:inline-block; filter:drop-shadow(0 2px 4px rgba(0,123,255,0.4));"><defs><linearGradient id="mytubeFlame" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4facfe"/><stop offset="100%" stop-color="#00f2fe"/></linearGradient><linearGradient id="mytubeBar" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#007BFF"/><stop offset="100%" stop-color="#003D82"/></linearGradient></defs><path d="M12 2C12 2 15 5.5 15 8C15 9.65 13.65 11 12 11C10.35 11 9 9.65 9 8C9 5.5 12 2 12 2Z" fill="url(#mytubeFlame)"/><rect x="7" y="13" width="10" height="4" rx="1" fill="url(#mytubeBar)"/><rect x="3" y="19" width="18" height="4" rx="1" fill="url(#mytubeBar)"/></svg></span>
                                <span class="ranking-badge-text">${fmtPts} pts&nbsp;•&nbsp;${escapeHtml(school)}</span>
                            </a>`;
                        })()}
                    </div>
                    ${video.is_boosted ? '<div class="boosted-badge"> Patrocinado</div>' : ''}
                    ${captionBlock}
                    ${hashtagChips}
                </div>
                
                <!-- Barra de progresso interativa -->
                <div class="progress-container" data-video-id="${video.id}">
                    <div class="progress-track">
                        <div class="progress-bar" id="progress-${video.id}"></div>
                    </div>
                    <div class="progress-time" id="progress-time-${video.id}">0:00</div>
                </div>
            </div>
            
            <!-- Overlay com informações (para compatibilidade) -->
            <div class="video-overlay" style="display: none;">
                <div class="video-info">
                    <div class="video-author">
                        <a href="perfil.php?id=${video.user.id}" class="avatar-link">
                            <img src="${video.user.profile_picture_url || 'assets/images/avatars/' + escapeHtml(video.user.profile_picture)}" 
                                 alt="${escapeHtml(video.user.username)}" 
                                 class="author-avatar"
                                 loading="lazy">
                        </a>
                        <div>
                            <div class="author-name">
                                <a href="perfil.php?id=${video.user.id}" class="username-link">
                                    ${escapeHtml(video.user.full_name || video.user.username)}
                                    ${video.user.is_verified ? '<span class="verified-badge">✓</span>' : ''}
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <h3 class="video-title">${escapeHtml(video.title)}</h3>
                    
                    ${video.description ? `<p class="video-description">${escapeHtml(video.description.length > 120 ? video.description.substring(0, 120) + '...' : video.description)}</p>` : ''}
                </div>
            </div>
            
            <!-- Botões de ação laterais -->
            <div class="action-buttons">
                ${actionButtonsHTML}
            </div>
        `;
        
        return videoDiv;
    }
    
    setupInfiniteScroll() {
        let scrollTimeout;
        
        window.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                if (this.loading || !this.hasMore) return;
                
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                const scrollHeight = document.documentElement.scrollHeight;
                const clientHeight = document.documentElement.clientHeight;
                
                // Método 1: Carregar quando chegar a 80% da página
                const scrollPercent = (scrollTop + clientHeight) / scrollHeight;
                
                // Método 2: Carregar quando chegar ao 3º vídeo visível
                const videoItems = document.querySelectorAll('.video-item:not(.initial-loading)');
                let thirdVideoReached = false;
                
                if (videoItems.length >= 3) {
                    const thirdVideo = videoItems[2]; // Index 2 = 3º vídeo
                    const thirdVideoRect = thirdVideo.getBoundingClientRect();
                    const viewportHeight = window.innerHeight;
                    
                    // Se o 3º vídeo está visível (50% dele aparecendo)
                    thirdVideoReached = thirdVideoRect.top < viewportHeight * 0.5;
                }
                
                // Carregar se qualquer condição for atendida
                if (scrollPercent >= 0.8 || thirdVideoReached) {
                    this.loadVideos();
                }
            }, 150); // Aumentei um pouco o debounce para melhor performance
        });
        
        // Adicionar também um observer para detecção mais precisa
        if ('IntersectionObserver' in window) {
            this.setupIntersectionObserver();
        }
    }
    
    setupIntersectionObserver() {
        // Observer para detectar quando vídeos ficam visíveis
        this.videoObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const videoElement = entry.target;
                    const videoIndex = parseInt(videoElement.dataset.index) || 0;
                    const totalVideos = document.querySelectorAll('.video-item:not(.initial-loading)').length;
                    
                    // Se chegou ao 3º vídeo e há mais para carregar
                    if (videoIndex >= 2 && !this.loading && this.hasMore && totalVideos - videoIndex <= 2) {
                        this.loadVideos();
                    }
                }
            });
        }, {
            rootMargin: '50px', // Disparar 50px antes do elemento ficar visível
            threshold: 0.3 // Quando 30% do vídeo estiver visível
        });
    }
    
    initializeVideoFeatures() {
        // Esta função será chamada após carregar novos vídeos
        // para reinicializar as funcionalidades existentes
        
        // Contar vídeos ativos
        const videoItems = document.querySelectorAll('.video-item:not(.initial-loading)');
        
        // Verificar se é primeira carga
        const isFirstLoad = !this.isFirstLoadComplete;
        this.isFirstLoadComplete = true;
        
        // Disparar evento customizado para outros scripts
        window.dispatchEvent(new CustomEvent('videosLoaded', {
            detail: {
                videoCount: videoItems.length,
                isFirstLoad: isFirstLoad
            }
        }));
        
        // Reinicializar reprodução de vídeo
        if (window.videoManager) {
            window.videoManager.initializeNewVideos();
        }
        
        // Reinicializar sistema de likes
        if (window.initializeLikeSystem) {
            window.initializeLikeSystem();
        }
        
        // Reinicializar comentários
        if (window.initializeCommentSystem) {
            window.initializeCommentSystem();
        }
        
        // Reinicializar follows
        if (window.initializeFollowSystem) {
            window.initializeFollowSystem();
        }
    }
    
    showLoading() {
        if (this.loadingIndicator) {
            this.loadingIndicator.style.display = 'flex';
        }
    }
    
    hideLoading() {
        if (this.loadingIndicator) {
            this.loadingIndicator.style.display = 'none';
        }
    }
    
    showError(message) {
        // Criar elemento de erro temporário
        const errorDiv = document.createElement('div');
        errorDiv.className = 'feed-error';
        errorDiv.innerHTML = `
            <div class="error-content">
                <i class="fas fa-exclamation-triangle"></i>
                <p>${message}</p>
                <button onclick="this.parentElement.parentElement.remove()" class="retry-btn">
                    Tentar Novamente
                </button>
            </div>
        `;
        
        this.videosContainer.appendChild(errorDiv);
        
        // Remover após 5 segundos
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 5000);
    }
    
    removeInitialLoading() {
        const initialLoading = document.getElementById('initialLoading');
        if (initialLoading) {
            initialLoading.remove();
        }
    }
    
    showEmptyFeed() {
        const initialLoading = document.getElementById('initialLoading');
        if (initialLoading) {
            initialLoading.innerHTML = `
                <div class="empty-feed">
                    <i class="fas fa-video" style="font-size: 4rem; color: #3b82f6; margin-bottom: 20px;"></i>
                    <h3>Ainda não há vídeos</h3>
                    <p>Seja o primeiro a compartilhar um vídeo incrível!</p>
                    <a href="upload.php" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-plus"></i>
                        Criar Primeiro Vídeo
                    </a>
                </div>
            `;
        }
    }
    
    showInitialError(errorMessage) {
        const initialLoading = document.getElementById('initialLoading');
        if (initialLoading) {
            initialLoading.innerHTML = `
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Erro ao carregar feed</h3>
                    <p>${errorMessage}</p>
                    <button class="retry-button" onclick="location.reload()">
                        Tentar Novamente
                    </button>
                </div>
            `;
        }
    }
    
    formatNumber(num) {
        num = parseInt(num) || 0;
        if (num >= 1000000000) {
            const val = (num / 1000000000).toFixed(1);
            return (val.endsWith('.0') ? val.slice(0, -2) : val) + 'B';
        } else if (num >= 1000000) {
            const val = (num / 1000000).toFixed(1);
            return (val.endsWith('.0') ? val.slice(0, -2) : val) + 'M';
        } else if (num >= 1000) {
            const val = (num / 1000).toFixed(1);
            return (val.endsWith('.0') ? val.slice(0, -2) : val) + 'k';
        }
        return num.toString();
    }
    
    // Método para recarregar feed (útil para atualizações)
    refresh() {
        this.offset = 0;
        this.hasMore = true;
        this.videosContainer.innerHTML = '';
        this.loadVideos();
    }
}

// Expor globalmente para outros scripts
window.FeedManager = FeedManager;