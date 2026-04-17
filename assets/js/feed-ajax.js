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
        this.loadedVideoIds = new Set(); // Track de vídeos já carregados
        this.isFirstLoadComplete = false; // Flag para primeira carga
        
        // Chaves de storage — usar localStorage para sobreviver a limpeza de cache
        this.storageKey = 'mytube_feed_state';
        this.restoreFlagKey = 'mytube_restore_feed';
        
        // Gerar sessão única para este carregamento de feed
        // Muda quando usuário atualiza a página = novo conteúdo
        this.feedSessionId = this.generateFeedSession();
        
        // Limpar cache antigo (formato anterior guardava todos os vídeos)
        this._cleanOldCache();
    }
    
    // Remover dados do formato antigo do sessionStorage/localStorage
    _cleanOldCache() {
        try {
            // Migrar de sessionStorage antigo para localStorage
            const oldRaw = sessionStorage.getItem(this.storageKey);
            if (oldRaw) {
                sessionStorage.removeItem(this.storageKey);
                // Tentar migrar para localStorage se for válido
                const oldState = JSON.parse(oldRaw);
                if (oldState.currentVideoId && !oldState.videos) {
                    localStorage.setItem(this.storageKey, oldRaw);
                }
            }
            
            const raw = localStorage.getItem(this.storageKey);
            if (!raw) return;
            const state = JSON.parse(raw);
            // Formato antigo tinha array "videos" — apagar
            if (state.videos) {
                localStorage.removeItem(this.storageKey);
            }
        } catch (e) {
            localStorage.removeItem(this.storageKey);
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
        
        // Verificar se devemos restaurar o feed salvo
        const shouldRestore = !isProfileFeed && this.shouldRestoreFeed();
        
        if (shouldRestore) {
            this.restoreSavedFeed();
        } else {
            // Carregar vídeos novos
            this.loadVideos();
        }
        
        // Configurar scroll infinito
        this.setupInfiniteScroll();
        
        // Configurar persistência (só no feed principal)
        if (!isProfileFeed) {
            this.setupPersistence();
        }
    }
    
    // Verificar se devemos restaurar o feed
    shouldRestoreFeed() {
        // Nunca restaurar no modo convidado
        if (window.isGuestMode) return false;
        
        const urlParams = new URLSearchParams(window.location.search);
        
        // Se tem parâmetro 't', é refresh forçado (clicou no logo MyTube)
        if (urlParams.has('t')) {
            localStorage.removeItem(this.storageKey);
            localStorage.removeItem(this.restoreFlagKey);
            return false;
        }
        
        // Não restaurar em feeds de perfil
        if (urlParams.has('user_id') || urlParams.has('video_id')) {
            return false;
        }
        
        // Verificar tipo de navegação - se é reload (F5), carregar feed novo
        const navEntries = performance.getEntriesByType('navigation');
        const navType = navEntries.length > 0 ? navEntries[0].type : 'navigate';
        
        if (navType === 'reload') {
            localStorage.removeItem(this.storageKey);
            localStorage.removeItem(this.restoreFlagKey);
            return false;
        }
        
        // Verificar se a flag de restauração foi ativada (pelo smartBack ou beforeunload)
        const restoreFlag = localStorage.getItem(this.restoreFlagKey);
        // Consumir a flag imediatamente para evitar restaurações indevidas
        localStorage.removeItem(this.restoreFlagKey);
        
        // Também aceitar back_forward como sinal de restauração (botão voltar do navegador)
        const isBackNavigation = navType === 'back_forward';
        
        // Só restaurar se veio de volta (flag ou back_forward)
        if (!restoreFlag && !isBackNavigation) {
            return false;
        }
        
        // Verificar se há dados salvos
        const savedState = localStorage.getItem(this.storageKey);
        if (!savedState) return false;
        
        try {
            const state = JSON.parse(savedState);
            
            // Verificar se os dados são recentes (menos de 60 minutos)
            const age = Date.now() - state.timestamp;
            if (age > 60 * 60 * 1000) {
                localStorage.removeItem(this.storageKey);
                return false;
            }
            
            // Restaurar se tem um vídeo para restaurar
            if (state.currentVideoId) {
                return true;
            }
            
            return false;
        } catch (e) {
            return false;
        }
    }
    
    // Restaurar feed salvo — carrega apenas o vídeo que o utilizador estava a ver
    restoreSavedFeed() {
        try {
            const savedState = JSON.parse(localStorage.getItem(this.storageKey));
            
            if (!savedState || !savedState.currentVideoId) {
                this.loadVideos();
                return;
            }
            
            console.log('[Feed] Restaurando feed a partir do vídeo', savedState.currentVideoId);
            
            // NÃO restaurar o feedSessionId antigo — usar o novo gerado no constructor
            // Assim a API cria um cache novo com start_video em vez de usar o cache antigo
            
            // Usar o mecanismo existente da API: start_video
            // Isso carrega o vídeo atual primeiro + próximos vídeos
            window.startVideoId = savedState.currentVideoId;
            
            // Carregar via API normalmente — start_video garante que esse vídeo vem primeiro
            this.loadVideos();
            
        } catch (e) {
            console.error('[Feed] Erro ao restaurar:', e);
            this.loadVideos();
        }
    }
    
    // Salvar estado do feed — salva apenas o ID do vídeo atual
    // Ao restaurar, a API carrega o feed começando por esse vídeo
    saveFeedState(currentVideoId = null) {
        // Só salvar no feed principal
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('user_id') || urlParams.has('t')) return;
        
        // Não salvar se não tem vídeo atual
        if (!currentVideoId) return;
        
        const state = {
            currentVideoId: currentVideoId,
            timestamp: Date.now()
        };
        
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(state));
        } catch (e) {
            // Silently fail
        }
    }
    
    // Configurar persistência
    setupPersistence() {
        // Salvar quando clicar em links
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (link && link.href && !link.href.includes('#')) {
                // Obter vídeo atual do TikTokPlayer
                const currentVideoId = window.tiktokPlayer?.videos?.[window.tiktokPlayer.currentVideoIndex]?.videoId;
                this.saveFeedState(currentVideoId);
            }
        });
        
        // Salvar antes de sair
        window.addEventListener('beforeunload', () => {
            const currentVideoId = window.tiktokPlayer?.videos?.[window.tiktokPlayer.currentVideoIndex]?.videoId;
            this.saveFeedState(currentVideoId);
        });
    }
    
    // Limpar estado salvo (usado quando carrega feed novo)
    clearSavedState() {
        localStorage.removeItem(this.storageKey);
        localStorage.removeItem(this.restoreFlagKey);
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
                    // Registrar IDs carregados
                    newVideos.forEach(v => this.loadedVideoIds.add(v.id));
                    
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

        const escapeHtml = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

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
                       data-caption-collapsed="${escapeHtml(captionData.collapsed)}">${escapeHtml(captionData.isCollapsible ? captionData.collapsed : captionData.full)}</p>
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
        
        // Build follow button inline (for video info overlay)
        let followBtnInline = '';
        if (!window.isGuestMode && video.user.id != window.currentUserId) {
            followBtnInline = `
                <button class="follow-btn-inline ${video.user_following ? 'following' : ''}" 
                        data-user-follow-inline="${video.user.id}"
                        data-user-id="${video.user.id}"
                        data-follows-you="${video.author_follows_you ? '1' : '0'}">
                    ${video.user_following ? 'A seguir' : (video.author_follows_you ? 'Seguir de volta' : 'Seguir')}
                </button>
            `;
        }
        
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
                
                <button class="action-btn share-btn" data-video-id="${video.id}">
                    <svg class="icon-share" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4l5 4-5 4"/><path d="M18 8H8a4 4 0 0 0-4 4v1"/><path d="M4 18h12a2 2 0 0 0 2-2v-1"/></svg>
                    <span class="action-count share-count">${this.formatNumber(video.shares_count || 0)}</span>
                </button>
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
            
            let deleteBtn = '';
            if (video.user.id == window.currentUserId || window.isAdmin) {
                deleteBtn = `
                    <button class="action-btn delete-btn" 
                            data-video-id="${video.id}"
                            title="Apagar vídeo">
                        <svg class="icon-trash" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <span class="action-count">Apagar</span>
                    </button>
                `;
            }

            let boostBtn = '';
            if (window.isAdmin) {
                const isBoosted = video.is_boosted;
                boostBtn = `
                    <button class="action-btn boost-btn ${isBoosted ? 'boosted' : ''}"
                            data-video-id="${video.id}"
                            data-boosted="${isBoosted ? '1' : '0'}"
                            title="${isBoosted ? 'Remover boost' : 'Dar boost'}">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="${isBoosted ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        <span class="action-count">${isBoosted ? 'Boosted' : 'Boost'}</span>
                    </button>
                `;
            }
            
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
                
                <button class="action-btn share-btn" 
                        data-video-id="${video.id}">
                    <svg class="icon-share" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4l5 4-5 4"/><path d="M18 8H8a4 4 0 0 0-4 4v1"/><path d="M4 18h12a2 2 0 0 0 2-2v-1"/></svg>
                    <span class="action-count share-count">${this.formatNumber(video.shares_count || 0)}</span>
                </button>
                
                ${deleteBtn}
                ${boostBtn}
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
                
                <!-- Controles de áudio -->
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
                            <img src="${video.user.profile_picture_url || 'assets/images/avatars/' + video.user.profile_picture}" 
                                 alt="${video.user.username}" 
                                 class="video-author-avatar"
                                 loading="lazy">
                            <span class="video-author-name">
                                ${video.user.full_name || video.user.username}
                                ${video.user.is_verified ? '<i class="fas fa-check-circle verified-badge"></i>' : ''}
                            </span>
                        </a>
                        ${followBtnInline}
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
                            <img src="${video.user.profile_picture_url || 'assets/images/avatars/' + video.user.profile_picture}" 
                                 alt="${video.user.username}" 
                                 class="author-avatar"
                                 loading="lazy">
                        </a>
                        <div>
                            <div class="author-name">
                                <a href="perfil.php?id=${video.user.id}" class="username-link">
                                    ${video.user.full_name || video.user.username}
                                    ${video.user.is_verified ? '<span class="verified-badge">✓</span>' : ''}
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <h3 class="video-title">${video.title}</h3>
                    
                    ${video.description ? `<p class="video-description">${video.description.length > 120 ? video.description.substring(0, 120) + '...' : video.description}</p>` : ''}
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