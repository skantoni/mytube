/**
 * Smart Polling — Sincronização de Comentários Otimizada
 * 
 * Estratégias de economia:
 * - Só ativo quando painel de comentários está ABERTO
 * - Intervalo adaptativo: 12s ativo → 30s idle → 0 em background/fechado
 * - Combina likes + novos comentários num ÚNICO request quando possível
 * - Backoff exponencial em erros
 * - Para completamente ao fechar comentários
 * - Sem setInterval(100ms) de polling para init
 */

class CommentSyncManager {
    constructor() {
        this.syncTimeout = null;
        this.isActive = false;
        this.visibleComments = new Set();
        this.lastSync = 0;
        this.lastCommentCheck = Math.floor(Date.now() / 1000);
        this.pendingUpdates = new Map();
        this.currentVideoId = null;
        this.consecutiveErrors = 0;
        
        // Smart Polling config — otimizado para shared hosting
        this.ACTIVE_INTERVAL = 45000;   // 45s quando utilizador está ativo
        this.IDLE_INTERVAL = 120000;    // 2min quando idle
        this.IDLE_TIMEOUT = 30000;      // 30s sem interação = idle
        this.MAX_BACKOFF = 180000;      // Máx 3 min em erro
        this.lastUserActivity = Date.now();
        
        this._onActivity = this._onActivity.bind(this);
        this._onVisibility = this._onVisibility.bind(this);
    }
    
    // ========================
    // LIFECYCLE
    // ========================
    
    startForVideo(videoId) {
        this.stop(); // Limpar estado anterior
        
        this.currentVideoId = videoId;
        this.isActive = true;
        this.lastCommentCheck = Math.floor(Date.now() / 1000);
        this.consecutiveErrors = 0;
        this.lastUserActivity = Date.now();
        
        // Rastrear comentários visíveis
        this.trackComments();
        
        // Listeners de atividade
        document.addEventListener('click', this._onActivity, { passive: true });
        document.addEventListener('scroll', this._onActivity, { passive: true, capture: true });
        document.addEventListener('visibilitychange', this._onVisibility);
        
        // Iniciar polling
        this._scheduleNext();
    }
    
    stop() {
        this.isActive = false;
        this.currentVideoId = null;
        this.visibleComments.clear();
        this.consecutiveErrors = 0;
        
        if (this.syncTimeout) {
            clearTimeout(this.syncTimeout);
            this.syncTimeout = null;
        }
        
        document.removeEventListener('click', this._onActivity);
        document.removeEventListener('scroll', this._onActivity, { capture: true });
        document.removeEventListener('visibilitychange', this._onVisibility);
    }
    
    // ========================
    // SMART INTERVAL
    // ========================
    
    _getCurrentInterval() {
        if (this.consecutiveErrors > 0) {
            return Math.min(
                this.ACTIVE_INTERVAL * Math.pow(2, this.consecutiveErrors),
                this.MAX_BACKOFF
            );
        }
        
        const timeSinceActivity = Date.now() - this.lastUserActivity;
        return timeSinceActivity > this.IDLE_TIMEOUT ? this.IDLE_INTERVAL : this.ACTIVE_INTERVAL;
    }
    
    _scheduleNext() {
        if (!this.isActive || document.hidden || !this.currentVideoId) return;
        
        if (this.syncTimeout) clearTimeout(this.syncTimeout);
        
        this.syncTimeout = setTimeout(() => {
            this._doSync();
        }, this._getCurrentInterval());
    }
    
    _onActivity() {
        this.lastUserActivity = Date.now();
    }
    
    _onVisibility() {
        if (document.hidden) {
            if (this.syncTimeout) {
                clearTimeout(this.syncTimeout);
                this.syncTimeout = null;
            }
        } else if (this.isActive) {
            this.lastUserActivity = Date.now();
            this._doSync();
        }
    }
    
    // ========================
    // TRACKING
    // ========================
    
    trackComments() {
        this.visibleComments.clear();
        const elements = document.querySelectorAll('.comment-item[data-comment-id], .reply-item[data-comment-id]');
        elements.forEach(el => {
            const id = el.dataset.commentId;
            if (id) this.visibleComments.add(id);
        });
    }
    
    // ========================
    // SYNC (combina likes + novos comentários)
    // ========================
    
    async _doSync() {
        if (!this.isActive || document.hidden || !this.currentVideoId) {
            this._scheduleNext();
            return;
        }
        
        try {
            // Request ÚNICO: novos comentários + likes combinados
            await this._syncCombined();
            this.consecutiveErrors = 0;
        } catch (error) {
            this.consecutiveErrors++;
        }
        
        this._scheduleNext();
    }
    
    /**
     * Request combinado: novos comentários + likes num único HTTP request
     * Antes eram 2 requests separados (sync_comments_feed + sync_comments)
     */
    async _syncCombined() {
        if (!this.currentVideoId) return;
        
        const commentIds = Array.from(this.visibleComments).map(Number);
        
        const body = {
            video_id: this.currentVideoId,
            last_check: this.lastCommentCheck
        };
        
        // Enviar IDs visíveis para sincronizar likes no mesmo request (máx 100)
        if (commentIds.length > 0) {
            body.comment_ids = commentIds.slice(0, 100);
        }
        
        const response = await fetch('api/sync_comments_feed.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });
        
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const ct = response.headers.get('content-type');
        if (!ct || !ct.includes('application/json')) throw new Error('Resposta não é JSON');
        
        const data = await response.json();
        
        if (data.success) {
            this.lastCommentCheck = data.timestamp;
            
            if (data.new_comments && data.new_comments.length > 0) {
                this._addNewCommentsToUI(data.new_comments);
            }
            
            if (data.updated_comments && data.updated_comments.length > 0) {
                this._updateEditedComments(data.updated_comments);
            }
            
            // Likes — antes era request separado para sync_comments.php
            if (data.likes_data) {
                this._updateLikesUI(data.likes_data);
            }
        }
    }
    
    // ========================
    // UI UPDATES
    // ========================
    
    _addNewCommentsToUI(comments) {
        if (!window.commentsSystem) return;
        
        comments.forEach(comment => {
            // Skip se já existe
            if (document.querySelector(`[data-comment-id="${comment.id}"]`)) return;
            
            if (comment.parent_comment_id) {
                const targetParentId = comment.root_comment_id || comment.parent_comment_id;
                window.commentsSystem.addReplyToUI(comment, targetParentId, false);
            } else {
                const isDesktopVisible = document.getElementById('commentsList')?.offsetParent !== null;
                const platform = isDesktopVisible ? 'desktop' : 'mobile';
                window.commentsSystem.addCommentToUI(comment, platform);
            }
        });
        
        // Re-rastrear comentários
        this.trackComments();
    }
    
    _updateEditedComments(comments) {
        comments.forEach(data => {
            const el = document.querySelector(`[data-comment-id="${data.id}"] .comment-text`);
            if (el && el.textContent !== data.comment_text) {
                el.textContent = data.comment_text;
                el.style.transition = 'background 0.3s ease';
                el.style.background = 'rgba(255, 215, 0, 0.1)';
                setTimeout(() => { el.style.background = 'transparent'; }, 2000);
            }
        });
    }
    
    _updateLikesUI(comments) {
        for (const [commentId, data] of Object.entries(comments)) {
            // Skip updates optimistas
            if (this.pendingUpdates.has(commentId)) {
                if (Date.now() - this.pendingUpdates.get(commentId) < 3000) continue;
                this.pendingUpdates.delete(commentId);
            }
            
            const likeBtn = document.querySelector(`.comment-like-btn[data-comment-id="${commentId}"]`);
            if (likeBtn) {
                const countEl = likeBtn.querySelector('.like-count');
                if (countEl) {
                    const current = parseInt(countEl.textContent) || 0;
                    if (current !== data.likes_count) {
                        countEl.textContent = data.likes_count;
                        countEl.style.transition = 'transform 0.3s ease';
                        countEl.style.transform = 'scale(1.15)';
                        setTimeout(() => { countEl.style.transform = 'scale(1)'; }, 300);
                    }
                }
                
                if (data.user_liked) {
                    likeBtn.classList.add('liked');
                } else {
                    likeBtn.classList.remove('liked');
                }
            }
        }
    }
    
    // ========================
    // API PÚBLICA
    // ========================
    
    registerOptimisticUpdate(commentId) {
        this.pendingUpdates.set(String(commentId), Date.now());
        setTimeout(() => this.pendingUpdates.delete(String(commentId)), 5000);
    }
    
    forceSyncNow() {
        this.lastSync = 0;
        this.consecutiveErrors = 0;
        this.trackComments();
        this._doSync();
    }
}

// === INICIALIZAÇÃO ===
window.commentSyncManager = new CommentSyncManager();

// Integrar com CommentsSystem quando disponível
document.addEventListener('DOMContentLoaded', () => {
    // Esperar pelo commentsSystem (com timeout curto, sem polling agressivo)
    let attempts = 0;
    const maxAttempts = 20; // 20 × 250ms = 5s máximo
    
    const tryIntegrate = () => {
        if (window.commentsSystem) {
            _integrateWithComments();
            return;
        }
        if (++attempts < maxAttempts) {
            setTimeout(tryIntegrate, 250);
        }
    };
    
    setTimeout(tryIntegrate, 500);
});

function _integrateWithComments() {
    const cs = window.commentsSystem;
    const csm = window.commentSyncManager;
    
    // Wrap openComments
    const origOpen = cs.openComments.bind(cs);
    cs.openComments = function(videoId) {
        origOpen(videoId);
        setTimeout(() => {
            csm.startForVideo(videoId);
        }, 800);
    };
    
    // Wrap closeComments
    const origClose = cs.closeComments.bind(cs);
    cs.closeComments = function() {
        origClose();
        csm.stop();
    };
    
    // Wrap loadComments
    const origLoad = cs.loadComments.bind(cs);
    cs.loadComments = function(highlightCommentId) {
        origLoad(highlightCommentId);
        setTimeout(() => { csm.trackComments(); }, 500);
    };
}

// Integrar com likes para optimistic updates (um único listener)
document.addEventListener('click', (e) => {
    const likeBtn = e.target.closest('.comment-like-btn');
    if (likeBtn && window.commentSyncManager) {
        const commentId = likeBtn.dataset.commentId;
        if (commentId) {
            window.commentSyncManager.registerOptimisticUpdate(commentId);
        }
    }
});