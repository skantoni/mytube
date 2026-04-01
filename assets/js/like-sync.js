/**
 * Smart Polling — Sincronização de Likes Otimizada
 * 
 * Estratégias de economia:
 * - Intervalo adaptativo: 15s ativo → 45s idle → 0 em background
 * - Só faz request se há vídeos visíveis
 * - Só faz request se utilizador interagiu recentemente
 * - Backoff exponencial em erros de rede
 * - Um único IntersectionObserver reutilizado
 * - Sem MutationObserver (usa evento custom do feed)
 */

class LikeSyncManager {
    constructor() {
        this.syncInterval = null;
        this.isActive = false;
        this.visibleVideos = new Set();
        this.lastSync = 0;
        this.pendingUpdates = new Map();
        this.observer = null;
        this.observedElements = new WeakSet();
        
        // Smart Polling config
        this.ACTIVE_INTERVAL = 30000;   // 30s quando utilizador está ativo
        this.IDLE_INTERVAL = 90000;     // 90s quando utilizador está idle
        this.IDLE_TIMEOUT = 30000;      // 30s sem interação = idle
        this.lastUserActivity = Date.now();
        this.consecutiveErrors = 0;
        this.MAX_BACKOFF = 120000;      // Máx 2 min entre requests em erro
        
        this._onActivity = this._onActivity.bind(this);
        this._onVisibility = this._onVisibility.bind(this);
    }
    
    start() {
        if (this.isActive) return;
        this.isActive = true;
        
        // Criar IntersectionObserver uma única vez
        this._setupObserver();
        
        // Observar vídeos existentes
        this._observeNewVideos();
        
        // Escutar novos vídeos carregados pelo feed (evento custom)
        document.addEventListener('videosLoaded', () => this._observeNewVideos());
        
        // Rastrear atividade do utilizador (passive — sem overhead)
        document.addEventListener('click', this._onActivity, { passive: true });
        document.addEventListener('scroll', this._onActivity, { passive: true });
        document.addEventListener('touchstart', this._onActivity, { passive: true });
        
        // Pausar em background
        document.addEventListener('visibilitychange', this._onVisibility);
        
        // Iniciar loop de polling
        this._scheduleNext();
    }
    
    stop() {
        this.isActive = false;
        if (this.syncInterval) {
            clearTimeout(this.syncInterval);
            this.syncInterval = null;
        }
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
        document.removeEventListener('click', this._onActivity);
        document.removeEventListener('scroll', this._onActivity);
        document.removeEventListener('touchstart', this._onActivity);
        document.removeEventListener('visibilitychange', this._onVisibility);
    }
    
    // ========================
    // SMART INTERVAL LOGIC
    // ========================
    
    _getCurrentInterval() {
        // Backoff exponencial em erros
        if (this.consecutiveErrors > 0) {
            return Math.min(
                this.ACTIVE_INTERVAL * Math.pow(2, this.consecutiveErrors),
                this.MAX_BACKOFF
            );
        }
        
        // Idle vs Active
        const timeSinceActivity = Date.now() - this.lastUserActivity;
        return timeSinceActivity > this.IDLE_TIMEOUT ? this.IDLE_INTERVAL : this.ACTIVE_INTERVAL;
    }
    
    _scheduleNext() {
        if (!this.isActive || document.hidden) return;
        
        if (this.syncInterval) clearTimeout(this.syncInterval);
        
        this.syncInterval = setTimeout(() => {
            this._doSync();
        }, this._getCurrentInterval());
    }
    
    _onActivity() {
        this.lastUserActivity = Date.now();
    }
    
    _onVisibility() {
        if (document.hidden) {
            // Parar polling quando tab está oculta
            if (this.syncInterval) {
                clearTimeout(this.syncInterval);
                this.syncInterval = null;
            }
        } else {
            // Retomar — sync imediato + agendar próximo
            this.lastUserActivity = Date.now();
            this._doSync();
        }
    }
    
    // ========================
    // OBSERVER (instância única)
    // ========================
    
    _setupObserver() {
        if (this.observer) return;
        
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const videoId = entry.target.dataset.videoId;
                if (!videoId) return;
                
                if (entry.isIntersecting) {
                    this.visibleVideos.add(videoId);
                } else {
                    this.visibleVideos.delete(videoId);
                }
            });
        }, { threshold: 0.5 });
    }
    
    _observeNewVideos() {
        if (!this.observer) return;
        
        const videos = document.querySelectorAll('.video-item[data-video-id]');
        videos.forEach(el => {
            if (!this.observedElements.has(el)) {
                this.observer.observe(el);
                this.observedElements.add(el);
            }
        });
    }
    
    // ========================
    // SYNC
    // ========================
    
    async _doSync() {
        if (!this.isActive || document.hidden || this.visibleVideos.size === 0) {
            this._scheduleNext();
            return;
        }
        
        const videoIds = Array.from(this.visibleVideos).map(Number);
        
        try {
            const response = await fetch('api/sync_likes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ video_ids: videoIds })
            });
            
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            const ct = response.headers.get('content-type');
            if (!ct || !ct.includes('application/json')) throw new Error('Resposta não é JSON');
            
            const data = await response.json();
            
            if (data.success) {
                this.consecutiveErrors = 0;
                this._updateUI(data.videos);
            }
            
        } catch (error) {
            this.consecutiveErrors++;
        }
        
        this._scheduleNext();
    }
    
    _updateUI(videos) {
        for (const [videoId, data] of Object.entries(videos)) {
            // Pular updates optimistas recentes
            if (this.pendingUpdates.has(videoId)) {
                if (Date.now() - this.pendingUpdates.get(videoId) < 3000) continue;
                this.pendingUpdates.delete(videoId);
            }
            
            // Atualizar botão de like
            const likeButton = document.querySelector(`[data-video-like="${videoId}"], [data-video-id="${videoId}"].like-btn`);
            if (likeButton) {
                const countEl = likeButton.querySelector('.like-count') || likeButton.querySelector('.action-count');
                if (countEl) {
                    const current = parseInt(countEl.textContent.replace(/[^0-9]/g, '')) || 0;
                    if (current !== data.likes_count) {
                        countEl.textContent = this.formatCount(data.likes_count);
                        countEl.style.transition = 'transform 0.3s ease';
                        countEl.style.transform = 'scale(1.15)';
                        setTimeout(() => { countEl.style.transform = 'scale(1)'; }, 300);
                    }
                }
                
                if (data.user_liked) {
                    likeButton.classList.add('liked');
                } else {
                    likeButton.classList.remove('liked');
                }
            }
            
            // Atualizar contador de comentários
            const commentBtn = document.querySelector(`.comment-btn[data-video-id="${videoId}"]`);
            if (commentBtn) {
                const countEl = commentBtn.querySelector('.action-count');
                if (countEl && data.comments_count !== undefined) {
                    const current = parseInt(countEl.textContent.replace(/[^0-9]/g, '')) || 0;
                    if (current !== data.comments_count) {
                        countEl.textContent = this.formatCount(data.comments_count);
                    }
                }
            }
        }
    }
    
    // ========================
    // API PÚBLICA
    // ========================
    
    registerOptimisticUpdate(videoId) {
        this.pendingUpdates.set(String(videoId), Date.now());
        setTimeout(() => this.pendingUpdates.delete(String(videoId)), 5000);
    }
    
    forceSyncNow() {
        this.lastSync = 0;
        this.consecutiveErrors = 0;
        this._doSync();
    }
    
    formatCount(count) {
        if (count >= 1000000) return (count / 1000000).toFixed(1).replace('.0', '') + 'M';
        if (count >= 1000) return (count / 1000).toFixed(1).replace('.0', '') + 'K';
        return count.toString();
    }
}

// === INICIALIZAÇÃO ===
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        if (document.getElementById('videoContainer')) {
            window.likeSyncManager = new LikeSyncManager();
            window.likeSyncManager.start();
        }
    }, 2000);
});
