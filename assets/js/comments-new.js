// Sistema de Comentarios - MyTube

class CommentsSystem {
    constructor() {
        this.currentVideoId = null;
        this.isLoading = false;
        this.isMobile = window.innerWidth <= 768;
        this.editTimerInterval = null;
        this.commentsOffset = 0;
        this.commentsLimit = 8;
        this.hasMoreComments = false;
        this.totalComments = 0;

        // === Mention Autocomplete State ===
        this.MAX_MENTIONS = 5;
        this.mentionDebounceTimer = null;
        this.mentionCache = {};           // cache de busca (key = query)
        this.mentionCacheTTL = 30000;     // 30s
        this.activeMentionInput = null;   // textarea que está com autocomplete aberto
        this.selectedMentionIndex = -1;
        this.mentionResults = [];

        // === Replies Thread State (Facebook-style) ===
        this.isRepliesThreadOpen = false;
        this.repliesThreadParentId = null;
        this.savedCommentsState = null;
        this.threadRepliesAdded = 0;

        // === Comment Emoji Picker State ===
        this.commentEmojiPickerOpen = false;
        this.commentEmojiTargetInput = null;
        this.commentEmojiTriggerBtn = null;
        this.commentEmojiList = [
            '😀', '😁', '😂', '🤣', '😊', '😍', '🥰', '😘',
            '😉', '😎', '🤔', '🤩', '😭', '😤', '😡', '🥳',
            '🙌', '👏', '🙏', '👍', '👎', '❤️', '🔥', '💯'
        ];

        this.init();
    }
    
    init() {
        this.setupDelegatedCommentButtons();
        this.setupModalEvents();
        this.setupCommentEmojiPicker();
        this.setupMentionAutocomplete();
        this._createMentionDropdown();
        
        window.addEventListener('resize', () => {
            this.isMobile = window.innerWidth <= 768;
            if (this.commentEmojiPickerOpen) {
                this._positionCommentEmojiPicker();
            }
        });
    }
    
    setupDelegatedCommentButtons() {
        const modal = document.getElementById('commentsModal');
        const sidebar = document.getElementById('commentsSidebar');
        
        document.addEventListener('click', (e) => {
            const commentBtn = e.target.closest('.comment-btn');
            
            if (commentBtn) {
                e.preventDefault();
                e.stopPropagation();
                
                const videoId = commentBtn.dataset.videoId;
                if (videoId) {
                    this.openComments(videoId);
                }
            }
        });
    }
    
    setupModalEvents() {
        const closeComments = document.getElementById('closeComments');
        const closeModal = document.getElementById('closeModal');
        const modalBackdrop = document.querySelector('.modal-backdrop');
        
        if (closeComments) {
            closeComments.addEventListener('click', () => this.closeComments());
        }
        
        if (closeModal) {
            closeModal.addEventListener('click', () => this.closeComments());
        }
        
        if (modalBackdrop) {
            modalBackdrop.addEventListener('click', () => this.closeComments());
        }
        
        const submitComment = document.getElementById('submitComment');
        const submitCommentMobile = document.getElementById('submitCommentMobile');
        
        if (submitComment) {
            submitComment.addEventListener('click', () => this.submitComment('desktop'));
        }
        
        if (submitCommentMobile) {
            submitCommentMobile.addEventListener('click', () => this.submitComment('mobile'));
        }
    }

    setupCommentEmojiPicker() {
        this._createCommentEmojiPicker();

        document.querySelectorAll('.comment-emoji-btn').forEach(button => {
            if (button.dataset.emojiBound === 'true') return;
            button.dataset.emojiBound = 'true';

            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const targetInputId = button.dataset.targetInput;
                const targetInput = targetInputId ? document.getElementById(targetInputId) : null;
                if (!targetInput) return;

                this._toggleCommentEmojiPicker(button, targetInput);
            });
        });

        document.addEventListener('mousedown', (e) => {
            if (!this.commentEmojiPickerOpen) return;
            if (e.target.closest('.comment-emoji-btn') || e.target.closest('#commentEmojiPicker')) return;

            this._closeCommentEmojiPicker();
        });
    }

    _createCommentEmojiPicker() {
        if (document.getElementById('commentEmojiPicker')) return;

        const picker = document.createElement('div');
        picker.id = 'commentEmojiPicker';
        picker.className = 'comment-emoji-picker';
        picker.innerHTML = `
            <div class="comment-emoji-grid">
                ${this.commentEmojiList.map(emoji => `
                    <button type="button" class="comment-emoji-item" data-emoji="${emoji}" aria-label="${emoji}">${emoji}</button>
                `).join('')}
            </div>
        `;

        picker.addEventListener('mousedown', (e) => {
            const emojiBtn = e.target.closest('.comment-emoji-item');
            if (!emojiBtn) return;

            e.preventDefault();
            e.stopPropagation();
            this._insertCommentEmoji(emojiBtn.dataset.emoji || '');
        });

        document.body.appendChild(picker);
    }

    _toggleCommentEmojiPicker(triggerBtn, targetInput) {
        const isSameTarget = this.commentEmojiPickerOpen &&
            this.commentEmojiTriggerBtn === triggerBtn &&
            this.commentEmojiTargetInput === targetInput;

        this._closeCommentEmojiPicker();

        if (isSameTarget) return;

        this.commentEmojiTriggerBtn = triggerBtn;
        this.commentEmojiTargetInput = targetInput;

        const picker = document.getElementById('commentEmojiPicker');
        if (!picker) return;

        picker.classList.add('active');
        this.commentEmojiPickerOpen = true;
        triggerBtn.classList.add('active');
        this._positionCommentEmojiPicker();
        targetInput.focus();
    }

    _positionCommentEmojiPicker() {
        const picker = document.getElementById('commentEmojiPicker');
        const triggerBtn = this.commentEmojiTriggerBtn;
        if (!picker || !triggerBtn) return;

        const triggerRect = triggerBtn.getBoundingClientRect();
        const pickerWidth = Math.min(picker.offsetWidth || 260, window.innerWidth - 24);
        const pickerHeight = picker.offsetHeight || 180;

        let left = triggerRect.left;
        left = Math.max(12, Math.min(left, window.innerWidth - pickerWidth - 12));

        let top = triggerRect.top - pickerHeight - 8;
        if (top < 12) {
            top = triggerRect.bottom + 8;
        }
        if (top + pickerHeight > window.innerHeight - 12) {
            top = Math.max(12, window.innerHeight - pickerHeight - 12);
        }

        picker.style.left = `${left}px`;
        picker.style.top = `${top}px`;
    }

    _insertCommentEmoji(emoji) {
        const input = this.commentEmojiTargetInput;
        if (!input || !emoji) return;

        const start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
        const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;

        const before = input.value.substring(0, start);
        const after = input.value.substring(end);
        input.value = before + emoji + after;

        const newCursorPos = start + emoji.length;
        input.focus();
        input.setSelectionRange(newCursorPos, newCursorPos);
        input.dispatchEvent(new Event('input', { bubbles: true }));

        this._closeCommentEmojiPicker();
    }

    _closeCommentEmojiPicker() {
        const picker = document.getElementById('commentEmojiPicker');
        if (picker) {
            picker.classList.remove('active');
        }

        if (this.commentEmojiTriggerBtn) {
            this.commentEmojiTriggerBtn.classList.remove('active');
        }

        this.commentEmojiPickerOpen = false;
        this.commentEmojiTargetInput = null;
        this.commentEmojiTriggerBtn = null;
    }
    
    openComments(videoId) {
        this.currentVideoId = videoId;
        
        if (this.isMobile) {
            const modal = document.getElementById('commentsModal');
            if (modal) {
                modal.classList.add('open');
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '1';
                document.body.style.overflow = 'hidden';
            }
        } else {
            const sidebar = document.getElementById('commentsSidebar');
            if (sidebar) {
                sidebar.classList.add('open');
                document.body.classList.add('comments-open');
            }
        }
        
        this.loadComments();
    }
    
    /**
     * Abrir comentários e destacar um comentário específico
     */
    openCommentsWithHighlight(videoId, commentId) {
        this.currentVideoId = videoId;
        
        if (this.isMobile) {
            const modal = document.getElementById('commentsModal');
            if (modal) {
                modal.classList.add('open');
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '1';
                document.body.style.overflow = 'hidden';
            }
        } else {
            const sidebar = document.getElementById('commentsSidebar');
            if (sidebar) {
                sidebar.classList.add('open');
                document.body.classList.add('comments-open');
            }
        }
        
        this.loadComments(commentId);
    }
    
    /**
     * Rolar até um comentário e destacá-lo
     */
    scrollToAndHighlightComment(commentId) {
        // Procurar o comentário (pode ser comment-item ou reply-item)
        let commentElement = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
        let isReply = false;
        
        if (!commentElement) {
            commentElement = document.querySelector(`.reply-item[data-comment-id="${commentId}"]`);
            isReply = true;
        }
        
        // Se o elemento não está renderizado, pode ser uma resposta não carregada
        // Procurar primeiro no cache local
        if (!commentElement && this.allRepliesCache) {
            for (const parentId in this.allRepliesCache) {
                const replies = this.allRepliesCache[parentId];
                const found = replies.find(r => r.id == commentId);
                if (found) {
                    isReply = true;
                    const toggleBtn = document.querySelector(`.replies-toggle-btn[data-comment-id="${parentId}"]`);
                    if (toggleBtn && toggleBtn.dataset.expanded !== 'true') {
                        this.toggleReplies(parentId, toggleBtn);
                    }
                    setTimeout(() => {
                        commentElement = document.querySelector(`.reply-item[data-comment-id="${commentId}"]`);
                        if (commentElement) this._doHighlight(commentElement, true);
                    }, 800);
                    return;
                }
            }
        }
        
        // Se ainda não encontrou, pode ser uma resposta cujo pai não está expandido
        // Buscar info do comentário no servidor para descobrir o pai
        if (!commentElement) {
            this._findAndExpandReplyParent(commentId);
            return;
        }
        
        if (commentElement) {
            this._doHighlight(commentElement, isReply);
        }
    }
    
    /**
     * Buscar o parent_comment_id de uma resposta no servidor e expandir
     */
    async _findAndExpandReplyParent(commentId) {
        try {
            const resp = await fetch(`api/get_comments.php?find_parent=${commentId}`);
            const data = await resp.json();
            if (data.success && data.parent_comment_id) {
                const parentId = data.parent_comment_id;
                const toggleBtn = document.querySelector(`.replies-toggle-btn[data-comment-id="${parentId}"]`);
                if (toggleBtn) {
                    if (toggleBtn.dataset.expanded !== 'true') {
                        this.toggleReplies(parentId, toggleBtn);
                    }
                    // Esperar as respostas carregarem e depois fazer highlight
                    setTimeout(() => {
                        const el = document.querySelector(`.reply-item[data-comment-id="${commentId}"]`);
                        if (el) this._doHighlight(el, true);
                    }, 1000);
                }
            }
        } catch (e) {
            console.error('Erro ao buscar pai do comentário:', e);
        }
    }
    
    /**
     * Realçar um comentário/resposta com scroll e animação
     */
    _doHighlight(el, isReply) {
        // Se for uma resposta, garantir que o container está expandido
        if (isReply) {
            const repliesContainer = el.closest('.comment-replies');
            if (repliesContainer) {
                repliesContainer.style.display = 'block';
                
                const parentId = repliesContainer.dataset.commentId;
                const toggleBtn = document.querySelector(`.replies-toggle-btn[data-comment-id="${parentId}"]`);
                if (toggleBtn) {
                    toggleBtn.dataset.expanded = 'true';
                    const icon = toggleBtn.querySelector('i');
                    if (icon) icon.className = 'fas fa-chevron-up';
                    const countText = toggleBtn.querySelector('.replies-count');
                    if (countText) {
                        const count = countText.textContent.match(/\d+/)?.[0] || '0';
                        countText.textContent = `Ocultar ${count} ${count === '1' ? 'resposta' : 'respostas'}`;
                    }
                }
            }
        }
        
        setTimeout(() => {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            setTimeout(() => {
                el.classList.add('highlight-comment');
                el.style.background = '#1e90ff';
                el.style.borderRadius = '8px';
                el.style.boxShadow = '0 0 20px rgba(30, 144, 255, 0.8)';
                el.style.transition = 'all 0.5s ease';
                
                setTimeout(() => {
                    el.style.background = 'rgba(30, 144, 255, 0.3)';
                    el.style.boxShadow = '0 0 10px rgba(30, 144, 255, 0.4)';
                }, 1000);
                
                setTimeout(() => {
                    el.classList.remove('highlight-comment');
                    el.style.background = '';
                    el.style.borderRadius = '';
                    el.style.boxShadow = '';
                }, 3000);
            }, 300);
        }, 200);
    }
    
    closeComments() {
        // Cancel edit mode if active
        this.cancelEdit();
        this._closeCommentEmojiPicker();
        
        // Close replies thread first if open
        if (this.isRepliesThreadOpen) {
            this.closeRepliesThread();
        }
        
        if (this.isMobile) {
            const modal = document.getElementById('commentsModal');
            if (modal) {
                modal.classList.remove('open');
                modal.style.display = '';
                modal.style.visibility = '';
                modal.style.opacity = '';
                document.body.style.overflow = '';
            }
        } else {
            const sidebar = document.getElementById('commentsSidebar');
            if (sidebar) {
                sidebar.classList.remove('open');
                document.body.classList.remove('comments-open');
            }
        }
    }
    
    loadComments(highlightCommentId = null) {
        if (!this.currentVideoId || this.isLoading) return;
        
        // Close replies thread if open
        if (this.isRepliesThreadOpen) {
            this.closeRepliesThread();
        }
        
        this.isLoading = true;
        this.highlightCommentId = highlightCommentId;
        
        // Reset paginação ao abrir comentários
        this.commentsOffset = 0;
        this.hasMoreComments = false;
        this.totalComments = 0;
        
        // Limpar cache de respostas ao carregar novo vídeo
        this.allRepliesCache = {};
        
        const containerSelector = this.isMobile ? '#commentsListMobile' : '#commentsList';
        const container = document.querySelector(containerSelector);
        
        if (!container) {
            this.isLoading = false;
            return;
        }
        
        container.innerHTML = '<div class="comment-loading">Carregando...</div>';
        
        fetch(`api/get_comments.php?video_id=${this.currentVideoId}&offset=0&limit=${this.commentsLimit}`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const ct = response.headers.get('content-type');
                if (!ct || !ct.includes('application/json')) throw new Error('Resposta não é JSON');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    this.totalComments = data.total || 0;
                    this.hasMoreComments = data.has_more || false;
                    this.commentsOffset = data.comments.length;
                    
                    this.renderComments(data.comments, container);
                    
                    // Se há um comentário para destacar, rolar até ele
                    if (this.highlightCommentId) {
                        setTimeout(() => {
                            this.scrollToAndHighlightComment(this.highlightCommentId);
                        }, 500);
                    }
                } else {
                    container.innerHTML = '<div class="empty-comments"><p>Erro ao carregar</p></div>';
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                container.innerHTML = '<div class="empty-comments"><p>Erro de conexao</p></div>';
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
    
    loadMoreComments() {
        if (!this.currentVideoId || this.isLoading || !this.hasMoreComments) return;
        
        this.isLoading = true;
        
        const containerSelector = this.isMobile ? '#commentsListMobile' : '#commentsList';
        const container = document.querySelector(containerSelector);
        if (!container) { this.isLoading = false; return; }
        
        // Desabilitar botão enquanto carrega
        const loadMoreBtn = container.querySelector('.load-more-comments-btn');
        if (loadMoreBtn) {
            loadMoreBtn.disabled = true;
            loadMoreBtn.textContent = 'Carregando...';
        }
        
        fetch(`api/get_comments.php?video_id=${this.currentVideoId}&offset=${this.commentsOffset}&limit=${this.commentsLimit}`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const ct = response.headers.get('content-type');
                if (!ct || !ct.includes('application/json')) throw new Error('Resposta não é JSON');
                return response.json();
            })
            .then(data => {
                if (data.success && data.comments.length > 0) {
                    this.hasMoreComments = data.has_more || false;
                    this.commentsOffset += data.comments.length;
                    
                    // Remover botão "carregar mais" existente
                    const existingBtn = container.querySelector('.load-more-comments');
                    if (existingBtn) existingBtn.remove();
                    
                    // Adicionar novos comentários ao final
                    const fragment = document.createDocumentFragment();
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = data.comments.map(c => this.createCommentHTML(c)).join('');
                    
                    while (tempDiv.firstChild) {
                        fragment.appendChild(tempDiv.firstChild);
                    }
                    
                    container.appendChild(fragment);
                    
                    // Adicionar novo botão se ainda tem mais
                    if (this.hasMoreComments) {
                        this.appendLoadMoreButton(container);
                    }
                    
                    this.bindCommentEvents(container);
                }
            })
            .catch(error => {
                console.error('Erro ao carregar mais comentários:', error);
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.textContent = 'Tentar novamente';
                }
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
    
    renderComments(comments, container) {
        if (!comments || comments.length === 0) {
            container.innerHTML = '<div class="empty-comments"><p>Seja o primeiro a comentar!</p></div>';
            // Ainda assim vincular eventos para que comentários adicionados depois funcionem
            container.dataset.eventsbound = 'false';
            this.bindCommentEvents(container);
            return;
        }
        
        const html = comments.map(comment => this.createCommentHTML(comment)).join('');
        container.innerHTML = html;
        
        // Adicionar botão "Carregar mais" se houver mais comentários
        if (this.hasMoreComments) {
            this.appendLoadMoreButton(container);
        }
        
        // Resetar flag de events para permitir rebind
        container.dataset.eventsbound = 'false';
        this.bindCommentEvents(container);
        this.startEditTimer();
    }
    
    appendLoadMoreButton(container) {
        const remaining = this.totalComments - this.commentsOffset;
        const loadMoreDiv = document.createElement('div');
        loadMoreDiv.className = 'load-more-comments';
        loadMoreDiv.innerHTML = `
            <button class="load-more-comments-btn">
                Ver mais comentários (${remaining})
            </button>
        `;
        loadMoreDiv.querySelector('.load-more-comments-btn').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.loadMoreComments();
        });
        container.appendChild(loadMoreDiv);
    }
    
    bindCommentEvents(container) {
        if (container.dataset.eventsbound === 'true') return;
        container.dataset.eventsbound = 'true';
        
        // Remover listener anterior se existir (prevenir duplicação)
        if (container._commentClickHandler) {
            container.removeEventListener('click', container._commentClickHandler);
        }
        
        container._commentClickHandler = async (e) => {
            const target = e.target;
            
            if (target.closest('.comment-like-btn')) {
                e.preventDefault();
                e.stopPropagation(); // IMPORTANTE: Impedir que tiktok.js capture este evento!
                const button = target.closest('.comment-like-btn');
                const commentId = button.dataset.commentId;
                await this.toggleCommentLike(button, commentId);
                return;
            }
            
            if (target.closest('.comment-reply-btn')) {
                e.preventDefault();
                const button = target.closest('.comment-reply-btn');
                const commentId = button.dataset.commentId;
                
                // Encontrar o username REAL (data-username) do autor para @menção
                const commentElement = button.closest('.reply-item') || button.closest('.comment-item');
                const usernameElement = commentElement?.querySelector('.comment-username');
                const replyToUsername = usernameElement?.dataset?.username || usernameElement?.textContent?.trim() || '';
                
                this.showReplyForm(commentId, replyToUsername);
                return;
            }
            
            if (target.closest('.replies-toggle-btn')) {
                e.preventDefault();
                const button = target.closest('.replies-toggle-btn');
                const commentId = button.dataset.commentId;
                if (button.dataset.toggling !== 'true') {
                    this.toggleReplies(commentId, button);
                }
                return;
            }
            
            if (target.closest('.load-more-replies-btn')) {
                e.preventDefault();
                const button = target.closest('.load-more-replies-btn');
                this.loadMoreReplies(button);
                return;
            }
            
            if (target.closest('.comment-edit-btn')) {
                e.preventDefault();
                e.stopPropagation();
                const button = target.closest('.comment-edit-btn');
                const commentId = button.dataset.commentId;
                this.editComment(commentId);
                return;
            }
        };
        
        container.addEventListener('click', container._commentClickHandler);
    }
    
    async toggleCommentLike(button, commentId) {
        if (button.classList.contains('loading')) return;
        button.classList.add('loading');
        
        try {
            const response = await fetch('api/toggle_comment_like.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ comment_id: commentId })
            });
            
            const data = await response.json();
            
            if (data.success) {
                const likeCountSpan = button.querySelector('.like-count');
                likeCountSpan.textContent = data.likes_count;
                
                if (data.liked) {
                    button.classList.add('liked');
                } else {
                    button.classList.remove('liked');
                }
            }
        } catch (error) {
            console.error('Erro ao curtir:', error);
        } finally {
            button.classList.remove('loading');
        }
    }
    
    showReplyForm(commentId, replyToUsername = '') {
        // If already in thread view, just focus the input and prefill @username
        if (this.isRepliesThreadOpen) {
            const inputId = this.isMobile ? 'commentInputMobile' : 'commentInput';
            const input = document.getElementById(inputId);
            if (input) {
                if (replyToUsername) {
                    input.value = '@' + replyToUsername + ' ';
                    input.placeholder = 'Responder a ' + replyToUsername + '...';
                }
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);
            }
            return;
        }
        
        // Find root comment ID (if clicking reply on a sub-reply)
        let rootCommentId = commentId;
        const replyElement = document.querySelector(`.reply-item[data-comment-id="${commentId}"]`);
        if (replyElement) {
            const repliesContainer = replyElement.closest('.comment-replies');
            if (repliesContainer) {
                rootCommentId = repliesContainer.dataset.commentId;
            }
        }
        
        this.openRepliesThread(rootCommentId, replyToUsername);
    }
    
    hideReplyForm(form) {
        if (form) {
            form.style.display = 'none';
            const input = form.querySelector('.reply-input');
            if (input) input.value = '';
        }
    }
    
    // =============================================
    //  REPLIES THREAD — Facebook-style
    // =============================================
    
    /**
     * Opens a dedicated replies thread view for a comment
     */
    openRepliesThread(rootCommentId, replyToUsername = '') {
        this.isRepliesThreadOpen = true;
        this.repliesThreadParentId = rootCommentId;
        this.threadRepliesAdded = 0;
        
        // Get parent comment data from DOM
        const parentEl = document.querySelector(`.comment-item[data-comment-id="${rootCommentId}"]`);
        if (!parentEl) return;
        
        const avatarSrc = parentEl.querySelector('.comment-avatar')?.src || '';
        const usernameEl = parentEl.querySelector('.comment-username');
        const username = usernameEl?.textContent?.trim() || '';
        const realUsername = usernameEl?.dataset?.username || username;
        const userHref = usernameEl?.getAttribute('href') || '#';
        const commentText = parentEl.querySelector('.comment-text')?.innerHTML || '';
        const timeAgo = parentEl.querySelector('.comment-time')?.textContent || '';
        const likeBtn = parentEl.querySelector('.comment-like-btn');
        const likeCount = likeBtn?.querySelector('.like-count')?.textContent || '0';
        const isLiked = likeBtn?.classList.contains('liked') || false;
        const isVerified = !!parentEl.querySelector('.verified-badge');
        
        const parentData = { avatarSrc, username, realUsername, userHref, commentText, timeAgo, likeCount, isLiked, isVerified };
        
        if (this.isMobile) {
            this._openThreadMobile(rootCommentId, replyToUsername, parentData);
        } else {
            this._openThreadDesktop(rootCommentId, replyToUsername, parentData);
        }
    }
    
    _openThreadMobile(rootCommentId, replyToUsername, parentData) {
        const modalBody = document.querySelector('.modal-body');
        const commentsList = document.getElementById('commentsListMobile');
        const modalHeader = document.querySelector('.modal-header');
        const modalFooter = document.querySelector('.modal-footer');
        
        if (!modalBody || !commentsList || !modalHeader) return;
        
        // Save current state
        this.savedCommentsState = {
            headerHTML: modalHeader.innerHTML,
            scrollTop: modalBody.scrollTop
        };
        
        // Hide comments list
        commentsList.style.display = 'none';
        
        // Create thread container
        let threadContainer = modalBody.querySelector('.replies-thread-container');
        if (threadContainer) threadContainer.remove();
        threadContainer = document.createElement('div');
        threadContainer.className = 'replies-thread-container';
        modalBody.appendChild(threadContainer);
        
        // Update header with back button
        modalHeader.classList.add('thread-mode');
        modalHeader.innerHTML = `
            <button class="replies-thread-back" id="repliesThreadBack">
                <i class="fas fa-chevron-left"></i>
            </button>
            <h3>Respostas</h3>
            <button class="close-modal" id="closeModal">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.getElementById('repliesThreadBack').addEventListener('click', () => this.closeRepliesThread());
        document.getElementById('closeModal').addEventListener('click', () => {
            this.closeRepliesThread();
            this.closeComments();
        });
        
        // Render thread content
        this._renderThreadContent(threadContainer, rootCommentId, parentData);
        
        // Update footer input to reply mode
        if (modalFooter) {
            const input = document.getElementById('commentInputMobile');
            if (input) {
                input.dataset.replyMode = 'true';
                input.dataset.parentCommentId = rootCommentId;
                if (replyToUsername) {
                    input.value = '@' + replyToUsername + ' ';
                    input.placeholder = 'Responder a ' + replyToUsername + '...';
                } else {
                    input.value = '';
                    input.placeholder = 'Mete dica...';
                }
                setTimeout(() => {
                    if (replyToUsername) input.focus();
                }, 300);
            }
        }
        
        // Load replies
        this.loadRepliesForThread(rootCommentId);
        this.bindThreadEvents(threadContainer);
    }
    
    _openThreadDesktop(rootCommentId, replyToUsername, parentData) {
        const sidebar = document.getElementById('commentsSidebar');
        const commentsList = document.getElementById('commentsList');
        const commentsHeader = sidebar.querySelector('.comments-header');
        const commentsContent = sidebar.querySelector('.comments-content');
        
        if (!sidebar || !commentsList || !commentsHeader) return;
        
        // Save current state
        this.savedCommentsState = {
            headerHTML: commentsHeader.innerHTML,
            scrollTop: commentsList.scrollTop
        };
        
        // Hide comments list
        commentsList.style.display = 'none';
        
        // Create thread container
        let threadContainer = commentsContent.querySelector('.replies-thread-container');
        if (threadContainer) threadContainer.remove();
        threadContainer = document.createElement('div');
        threadContainer.className = 'replies-thread-container';
        
        // Insert before the comment form
        const commentForm = commentsContent.querySelector('.comment-form') || commentsContent.querySelector('.login-prompt')?.closest('.login-prompt')?.parentElement;
        if (commentForm) {
            commentsContent.insertBefore(threadContainer, commentForm);
        } else {
            commentsContent.appendChild(threadContainer);
        }
        
        // Update header
        commentsHeader.classList.add('thread-mode');
        commentsHeader.innerHTML = `
            <button class="replies-thread-back" id="repliesThreadBack">
                <i class="fas fa-chevron-left"></i>
            </button>
            <h3>Respostas</h3>
            <button class="close-comments" id="closeComments">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.getElementById('repliesThreadBack').addEventListener('click', () => this.closeRepliesThread());
        document.getElementById('closeComments').addEventListener('click', () => {
            this.closeRepliesThread();
            this.closeComments();
        });
        
        // Render thread content
        this._renderThreadContent(threadContainer, rootCommentId, parentData);
        
        // Update input to reply mode
        const input = document.getElementById('commentInput');
        if (input) {
            input.dataset.replyMode = 'true';
            input.dataset.parentCommentId = rootCommentId;
            if (replyToUsername) {
                input.value = '@' + replyToUsername + ' ';
                input.placeholder = 'Responder a ' + replyToUsername + '...';
            } else {
                input.value = '';
                input.placeholder = 'Mete dica...';
            }
            setTimeout(() => {
                if (replyToUsername) input.focus();
            }, 300);
        }
        
        // Load replies
        this.loadRepliesForThread(rootCommentId);
        this.bindThreadEvents(threadContainer);
    }
    
    _renderThreadContent(container, rootCommentId, data) {
        container.innerHTML = `
            <div class="thread-parent-comment">
                <div class="comment-item" data-comment-id="${rootCommentId}">
                    <img src="${data.avatarSrc}" class="comment-avatar" loading="lazy">
                    <div class="comment-content">
                        <div class="comment-header">
                            <a href="${data.userHref}" class="comment-username" data-username="${this.escapeHtml(data.realUsername)}">${this.escapeHtml(data.username)}</a>
                            ${data.isVerified ? '<i class="fas fa-check-circle verified-badge"></i>' : ''}
                            <span class="comment-time">${data.timeAgo}</span>
                        </div>
                        <div class="comment-text">${data.commentText}</div>
                        <div class="comment-actions">
                            <button class="comment-like-btn ${data.isLiked ? 'liked' : ''}" data-comment-id="${rootCommentId}">
                                <i class="fas fa-heart"></i>
                                <span class="like-count">${data.likeCount}</span>
                            </button>
                            <button class="comment-reply-btn" data-comment-id="${rootCommentId}">
                                <i class="fas fa-reply"></i> Responder
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="thread-replies-divider"></div>
            <div class="thread-replies-list" data-comment-id="${rootCommentId}">
                <div class="replies-loading" style="padding:12px;color:#888;font-size:13px;">Carregando respostas...</div>
            </div>
        `;
    }
    
    async loadRepliesForThread(commentId) {
        const repliesList = document.querySelector('.thread-replies-list[data-comment-id="' + commentId + '"]');
        if (!repliesList) return;
        
        try {
            const response = await fetch('api/get_replies.php?comment_id=' + commentId + '&offset=0&limit=20');
            const data = await response.json();
            
            if (!data.success) {
                repliesList.innerHTML = '<div class="empty-comments"><p>Erro ao carregar respostas</p></div>';
                return;
            }
            
            // Cache
            if (!this.allRepliesCache) this.allRepliesCache = {};
            this.allRepliesCache[commentId] = data.replies || [];
            
            if (!data.replies || data.replies.length === 0) {
                repliesList.innerHTML = '<div class="empty-replies"><p>Nenhuma resposta ainda. Seja o primeiro!</p></div>';
            } else {
                let html = data.replies.map(reply => this.createReplyHTML(reply)).join('');
                if (data.has_more) {
                    const nextOffset = data.replies.length;
                    html += '<div class="load-more-replies"><button class="load-more-replies-btn" data-comment-id="' + commentId + '" data-offset="' + nextOffset + '">Ver mais respostas</button></div>';
                }
                repliesList.innerHTML = html;
            }
            
            this.startEditTimer();
            
        } catch (error) {
            console.error('Erro ao carregar respostas:', error);
            repliesList.innerHTML = '<div class="empty-comments"><p>Erro de conexão</p></div>';
        }
    }
    
    addReplyToThread(reply) {
        const repliesList = document.querySelector('.thread-replies-list');
        if (!repliesList) return;
        
        // Remove empty message
        const emptyMsg = repliesList.querySelector('.empty-replies');
        if (emptyMsg) emptyMsg.remove();
        
        // Check for duplicates
        if (repliesList.querySelector('.reply-item[data-comment-id="' + reply.id + '"]')) return;
        
        const replyHTML = this.createReplyHTML(reply);
        const loadMoreBtn = repliesList.querySelector('.load-more-replies');
        if (loadMoreBtn) {
            loadMoreBtn.insertAdjacentHTML('beforebegin', replyHTML);
        } else {
            repliesList.insertAdjacentHTML('beforeend', replyHTML);
        }
        
        const newEl = repliesList.querySelector('.reply-item[data-comment-id="' + reply.id + '"]');
        if (newEl) {
            newEl.style.opacity = '0';
            newEl.style.transform = 'translateY(10px)';
            setTimeout(() => {
                newEl.style.transition = 'all 0.3s ease';
                newEl.style.opacity = '1';
                newEl.style.transform = 'translateY(0)';
            }, 10);
            setTimeout(() => newEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 350);
        }
        
        this.startEditTimer();
    }
    
    bindThreadEvents(container) {
        if (!container || container.dataset.eventsbound === 'true') return;
        container.dataset.eventsbound = 'true';
        
        container.addEventListener('click', async (e) => {
            const target = e.target;
            
            if (target.closest('.comment-like-btn')) {
                e.preventDefault();
                e.stopPropagation();
                const button = target.closest('.comment-like-btn');
                const commentId = button.dataset.commentId;
                await this.toggleCommentLike(button, commentId);
                return;
            }
            
            if (target.closest('.comment-reply-btn')) {
                e.preventDefault();
                const button = target.closest('.comment-reply-btn');
                const commentElement = button.closest('.reply-item') || button.closest('.comment-item');
                const usernameElement = commentElement?.querySelector('.comment-username');
                const replyToUsername = usernameElement?.dataset?.username || usernameElement?.textContent?.trim() || '';
                this.showReplyForm(button.dataset.commentId, replyToUsername);
                return;
            }
            
            if (target.closest('.load-more-replies-btn')) {
                e.preventDefault();
                const button = target.closest('.load-more-replies-btn');
                this.loadMoreRepliesInThread(button);
                return;
            }
            
            if (target.closest('.comment-edit-btn')) {
                e.preventDefault();
                e.stopPropagation();
                const button = target.closest('.comment-edit-btn');
                this.editComment(button.dataset.commentId);
                return;
            }
        });
    }
    
    async loadMoreRepliesInThread(button) {
        const commentId = button.dataset.commentId;
        const offset = parseInt(button.dataset.offset) || 0;
        
        button.disabled = true;
        button.textContent = 'Carregando...';
        
        try {
            const response = await fetch('api/get_replies.php?comment_id=' + commentId + '&offset=' + offset + '&limit=6');
            const data = await response.json();
            
            if (!data.success) return;
            
            const repliesList = document.querySelector('.thread-replies-list');
            if (!repliesList) return;
            
            // Remove current load more
            const loadMoreDiv = button.closest('.load-more-replies');
            if (loadMoreDiv) loadMoreDiv.remove();
            
            // Render
            let html = data.replies.map(r => this.createReplyHTML(r)).join('');
            if (data.has_more) {
                const nextOffset = offset + data.replies.length;
                html += '<div class="load-more-replies"><button class="load-more-replies-btn" data-comment-id="' + commentId + '" data-offset="' + nextOffset + '">Ver mais respostas</button></div>';
            }
            repliesList.insertAdjacentHTML('beforeend', html);
            
            // Cache
            if (!this.allRepliesCache[commentId]) this.allRepliesCache[commentId] = [];
            data.replies.forEach(r => {
                if (!this.allRepliesCache[commentId].some(c => c.id === r.id)) {
                    this.allRepliesCache[commentId].push(r);
                }
            });
            
            this.startEditTimer();
        } catch (error) {
            console.error('Erro:', error);
            button.disabled = false;
            button.textContent = 'Tentar novamente';
        }
    }
    
    async submitThreadReply(input) {
        let text = input.value.trim();
        if (!text) return;
        
        const parentCommentId = input.dataset.parentCommentId;
        if (!parentCommentId) return;
        
        this._closeCommentEmojiPicker();
        this._closeMentionAutocomplete();
        text = await this._validateMentions(text);
        if (text === null) return;
        
        input.disabled = true;
        
        // Disable submit button
        const submitBtn = input.closest('.comment-input-container')?.querySelector('.submit-comment');
        if (submitBtn) submitBtn.disabled = true;
        
        try {
            const response = await fetch('api/add_comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    video_id: parseInt(this.currentVideoId),
                    comment_text: text,
                    parent_comment_id: parseInt(parentCommentId)
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                input.value = '';
                input.placeholder = 'Mete dica...';
                
                // Add reply to thread view
                this.addReplyToThread(data.comment);
                this.threadRepliesAdded++;
                
                // Update comment count
                this.updateCommentCountInUI(this.currentVideoId, 1);
            } else {
                alert(data.error || 'Erro ao adicionar resposta');
            }
        } catch (error) {
            console.error('Erro ao enviar resposta:', error);
            alert('Erro de conexão');
        } finally {
            input.disabled = false;
            if (submitBtn) submitBtn.disabled = false;
        }
    }
    
    closeRepliesThread() {
        if (!this.isRepliesThreadOpen) return;
        this.isRepliesThreadOpen = false;
        
        const parentId = this.repliesThreadParentId;
        
        if (this.isMobile) {
            this._closeThreadMobile();
        } else {
            this._closeThreadDesktop();
        }
        
        // Update replies count in main comments list
        if (parentId && this.threadRepliesAdded > 0) {
            this._updateRepliesCountAfterThread(parentId);
        }
        
        this.savedCommentsState = null;
        this.repliesThreadParentId = null;
        this.threadRepliesAdded = 0;
    }
    
    _closeThreadMobile() {
        const modalBody = document.querySelector('.modal-body');
        const commentsList = document.getElementById('commentsListMobile');
        const modalHeader = document.querySelector('.modal-header');
        
        // Remove thread container
        const threadContainer = modalBody?.querySelector('.replies-thread-container');
        if (threadContainer) threadContainer.remove();
        
        // Show comments list
        if (commentsList) commentsList.style.display = '';
        
        // Restore header
        if (this.savedCommentsState && modalHeader) {
            modalHeader.classList.remove('thread-mode');
            modalHeader.innerHTML = this.savedCommentsState.headerHTML;
            const closeModal = document.getElementById('closeModal');
            if (closeModal) {
                closeModal.addEventListener('click', () => this.closeComments());
            }
        }
        
        // Restore footer input
        const input = document.getElementById('commentInputMobile');
        if (input) {
            delete input.dataset.replyMode;
            delete input.dataset.parentCommentId;
            input.value = '';
            input.placeholder = 'Mete dica...';
        }
        
        // Restore scroll
        if (this.savedCommentsState && modalBody) {
            modalBody.scrollTop = this.savedCommentsState.scrollTop;
        }
    }
    
    _closeThreadDesktop() {
        const sidebar = document.getElementById('commentsSidebar');
        const commentsList = document.getElementById('commentsList');
        const commentsHeader = sidebar?.querySelector('.comments-header');
        const commentsContent = sidebar?.querySelector('.comments-content');
        
        // Remove thread container
        const threadContainer = commentsContent?.querySelector('.replies-thread-container');
        if (threadContainer) threadContainer.remove();
        
        // Show comments list
        if (commentsList) commentsList.style.display = '';
        
        // Restore header
        if (this.savedCommentsState && commentsHeader) {
            commentsHeader.classList.remove('thread-mode');
            commentsHeader.innerHTML = this.savedCommentsState.headerHTML;
            const closeComments = document.getElementById('closeComments');
            if (closeComments) {
                closeComments.addEventListener('click', () => this.closeComments());
            }
        }
        
        // Restore input
        const input = document.getElementById('commentInput');
        if (input) {
            delete input.dataset.replyMode;
            delete input.dataset.parentCommentId;
            input.value = '';
            input.placeholder = 'Mete dica...';
        }
    }
    
    _updateRepliesCountAfterThread(parentId) {
        const toggleBtn = document.querySelector('.replies-toggle-btn[data-comment-id="' + parentId + '"]');
        if (toggleBtn) {
            const countText = toggleBtn.querySelector('.replies-count');
            if (countText) {
                const currentCount = parseInt(countText.textContent.match(/\d+/)?.[0] || '0');
                const newCount = currentCount + this.threadRepliesAdded;
                const repliesText = newCount === 1 ? 'resposta' : 'respostas';
                const isExpanded = toggleBtn.dataset.expanded === 'true';
                countText.textContent = (isExpanded ? 'Ocultar ' : 'Ver ') + newCount + ' ' + repliesText;
            }
            // Mark as needing refresh
            const repliesContainer = document.querySelector('.comment-replies[data-comment-id="' + parentId + '"]');
            if (repliesContainer) {
                delete repliesContainer.dataset.loaded;
            }
        } else if (this.threadRepliesAdded > 0) {
            // Create toggle button if it didn't exist
            const commentSelector = '#commentsList .comment-item[data-comment-id="' + parentId + '"], #commentsListMobile .comment-item[data-comment-id="' + parentId + '"]';
            const parentComment = document.querySelector(commentSelector);
            if (parentComment) {
                const commentContent = parentComment.querySelector('.comment-content');
                if (commentContent) {
                    const newCount = this.threadRepliesAdded;
                    const repliesText = newCount === 1 ? 'resposta' : 'respostas';
                    const toggleContainer = document.createElement('div');
                    toggleContainer.className = 'replies-toggle-container';
                    toggleContainer.innerHTML = `
                        <button class="replies-toggle-btn" data-comment-id="${parentId}" data-expanded="false">
                            <i class="fas fa-chevron-down"></i>
                            <span class="replies-count">Ver ${newCount} ${repliesText}</span>
                        </button>
                    `;
                    commentContent.appendChild(toggleContainer);
                }
            }
        }
    }
    
    async submitReply(parentCommentId, text, form) {
        if (!text.trim()) return;

        // Fechar autocomplete se aberto
        this._closeMentionAutocomplete();

        // Validar menções
        let validatedText = await this._validateMentions(text.trim());
        if (validatedText === null) return;
        
        const submitBtn = form.querySelector('.reply-submit-btn');
        const textarea = form.querySelector('.reply-input');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Enviando...';
        
        try {
            const response = await fetch('api/add_comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    video_id: parseInt(this.currentVideoId),
                    comment_text: validatedText,
                    parent_comment_id: parseInt(parentCommentId)
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Limpar formulário
                textarea.value = '';
                this.hideReplyForm(form);
                
                // Usar root_comment_id se disponível (para respostas a respostas)
                const targetParentId = data.comment.root_comment_id || parentCommentId;
                
                // Adicionar resposta na UI sem recarregar (COM autoExpand pois é o próprio usuário)
                this.addReplyToUI(data.comment, targetParentId, true);
                
                // Atualizar contador de comentários em tempo real (respostas também contam)
                this.updateCommentCountInUI(this.currentVideoId, 1);
            } else {
                alert(data.error || 'Erro ao adicionar resposta');
            }
        } catch (error) {
            console.error('Erro ao enviar resposta:', error);
            alert('Erro de conexão ao enviar resposta');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Responder';
        }
    }
    
    /**
     * Adicionar resposta na UI em tempo real (sem reload)
     * @param {Object} reply - Dados da resposta
     * @param {number} parentCommentId - ID do comentário pai
     * @param {boolean} autoExpand - Se true, expande automaticamente. Se false, respeita estado atual
     */
    addReplyToUI(reply, parentCommentId, autoExpand = true) {
        // If the replies thread is open for this comment, add to thread view too
        if (this.isRepliesThreadOpen && this.repliesThreadParentId == parentCommentId) {
            this.addReplyToThread(reply);
        }
        
        // Primeiro, verificar se parentCommentId é de uma resposta (reply-item) ou comentário (comment-item)
        let actualParentId = parentCommentId;
        const replyElement = document.querySelector(`.reply-item[data-comment-id="${parentCommentId}"]`);
        
        if (replyElement) {
            // Se é uma resposta, encontrar o comentário pai original (container de respostas)
            const repliesContainer = replyElement.closest('.comment-replies');
            if (repliesContainer) {
                actualParentId = repliesContainer.dataset.commentId;
            }
        }
        
        // Encontrar o container de respostas do comentário pai
        let repliesContainer = document.querySelector(`.comment-replies[data-comment-id="${actualParentId}"]`);
        let wasVisible = false;
        
        if (!repliesContainer) {
            // Se não existe container de respostas, criar um
            const parentComment = document.querySelector(`.comment-item[data-comment-id="${actualParentId}"]`);
            if (!parentComment) return;
            
            const actionsDiv = parentComment.querySelector('.comment-actions');
            if (!actionsDiv) return;
            
            // Criar container de respostas (COLAPSADO por padrão se não for autoExpand)
            const repliesDiv = document.createElement('div');
            repliesDiv.className = 'comment-replies';
            repliesDiv.setAttribute('data-comment-id', actualParentId);
            repliesDiv.style.display = autoExpand ? 'block' : 'none';
            
            actionsDiv.parentNode.appendChild(repliesDiv);
            repliesContainer = repliesDiv;
            wasVisible = autoExpand;
        } else {
            // Se existe, verificar se estava visível
            wasVisible = repliesContainer.style.display !== 'none';
            
            // Se autoExpand for true, forçar visível. Senão, manter estado atual
            if (autoExpand) {
                repliesContainer.style.display = 'block';
            }
            // Se autoExpand for false, não mudar nada (respeitar estado atual)
        }
        
        // Verificar se a resposta já existe no DOM
        const existingReply = repliesContainer.querySelector(`.reply-item[data-comment-id="${reply.id}"]`);
        if (existingReply) {
            // Já existe, não adicionar novamente
            return;
        }
        
        // Atualizar cache de respostas do comentário pai
        if (!this.allRepliesCache) this.allRepliesCache = {};
        if (!this.allRepliesCache[actualParentId]) this.allRepliesCache[actualParentId] = [];
        // Evitar duplicatas
        const replyExists = this.allRepliesCache[actualParentId].some(r => r.id == reply.id);
        if (!replyExists) {
            this.allRepliesCache[actualParentId].push(reply);
            // Ordenar por data de criação (caso venha fora de ordem)
            this.allRepliesCache[actualParentId].sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
        }
        
        // Criar HTML da resposta
        const replyHTML = this.createReplyHTML(reply);
        
        // Encontrar a posição correta para inserir (ordenado por data)
        const replyDate = new Date(reply.created_at);
        const existingReplies = repliesContainer.querySelectorAll('.reply-item');
        let insertBefore = null;
        
        for (const existingItem of existingReplies) {
            const existingId = existingItem.dataset.commentId;
            // Buscar no cache a data deste item
            const cachedReply = this.allRepliesCache[actualParentId]?.find(r => r.id == existingId);
            if (cachedReply) {
                const existingDate = new Date(cachedReply.created_at);
                if (replyDate < existingDate) {
                    insertBefore = existingItem;
                    break;
                }
            }
        }
        
        // Inserir na posição correta
        if (insertBefore) {
            insertBefore.insertAdjacentHTML('beforebegin', replyHTML);
        } else {
            // Encontrar onde inserir (antes do botão "Ver mais" se existir, senão no final)
            const loadMoreBtn = repliesContainer.querySelector('.load-more-replies');
            if (loadMoreBtn) {
                loadMoreBtn.insertAdjacentHTML('beforebegin', replyHTML);
            } else {
                repliesContainer.insertAdjacentHTML('beforeend', replyHTML);
            }
        }
        
        // Encontrar o elemento recém-inserido
        const newReplyElement = repliesContainer.querySelector(`.reply-item[data-comment-id="${reply.id}"]`);
        
        // Animar entrada da resposta
        if (newReplyElement) {
            newReplyElement.style.opacity = '0';
            newReplyElement.style.transform = 'translateX(-20px)';
            
            setTimeout(() => {
                newReplyElement.style.transition = 'all 0.3s ease';
                newReplyElement.style.opacity = '1';
                newReplyElement.style.transform = 'translateX(0)';
            }, 10);
        }
        
        // Atualizar ou criar botão "Ver respostas"
        let toggleBtn = document.querySelector(`.replies-toggle-btn[data-comment-id="${actualParentId}"]`);
        
        if (toggleBtn) {
            // Botão já existe, apenas atualizar
            const countText = toggleBtn.querySelector('.replies-count');
            if (countText) {
                const currentCount = parseInt(countText.textContent.match(/\d+/)?.[0] || '0');
                const newCount = currentCount + 1;
                const repliesText = newCount === 1 ? 'resposta' : 'respostas';
                
                // Atualizar texto baseado no estado expandido
                if (toggleBtn.dataset.expanded === 'true') {
                    countText.textContent = 'Ocultar ' + newCount + ' ' + repliesText;
                } else {
                    countText.textContent = 'Ver ' + newCount + ' ' + repliesText;
                }
            }
            
            // Se autoExpand for true E estava colapsado, expandir
            if (autoExpand && toggleBtn.dataset.expanded !== 'true') {
                toggleBtn.dataset.expanded = 'true';
                const icon = toggleBtn.querySelector('i');
                if (icon) {
                    icon.className = 'fas fa-chevron-up';
                }
            }
        } else {
            // Botão não existe, criar pela primeira vez
            const parentComment = document.querySelector(`.comment-item[data-comment-id="${actualParentId}"]`);
            if (parentComment) {
                // Contar quantas respostas existem agora
                const repliesCount = repliesContainer.querySelectorAll('.reply-item').length;
                const repliesText = repliesCount === 1 ? 'resposta' : 'respostas';
                
                // Criar container e botão de toggle (FORA do comment-actions)
                // Estado inicial baseado em wasVisible
                const isExpanded = wasVisible;
                const toggleContainer = document.createElement('div');
                toggleContainer.className = 'replies-toggle-container';
                toggleContainer.innerHTML = `
                    <button class="replies-toggle-btn" data-comment-id="${actualParentId}" data-expanded="${isExpanded}">
                        <i class="fas fa-chevron-${isExpanded ? 'up' : 'down'}"></i>
                        <span class="replies-count">${isExpanded ? 'Ocultar' : 'Ver'} ${repliesCount} ${repliesText}</span>
                    </button>
                `;
                
                // Inserir ANTES do container de respostas
                repliesContainer.parentNode.insertBefore(toggleContainer, repliesContainer);
                
                toggleBtn = toggleContainer.querySelector('.replies-toggle-btn');
            }
        }
        
        // Scroll suave até a nova resposta (APENAS se autoExpand for true)
        if (autoExpand) {
            setTimeout(() => {
                newReplyElement?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 350);
        }
        
        // Rebind eventos
        this.startEditTimer();
    }
    
    toggleReplies(commentId, button) {
        if (button.dataset.toggling === 'true') return;
        button.dataset.toggling = 'true';
        
        let repliesContainer = document.querySelector('.comment-replies[data-comment-id="' + commentId + '"]');
        const icon = button.querySelector('i');
        const text = button.querySelector('.replies-count');
        const isExpanded = button.dataset.expanded === 'true';
        
        if (!icon || !text) {
            button.dataset.toggling = 'false';
            return;
        }
        
        if (isExpanded) {
            // Colapsar
            if (repliesContainer) repliesContainer.style.display = 'none';
            icon.className = 'fas fa-chevron-down';
            const repliesCount = text.textContent.match(/\d+/)?.[0] || '0';
            const repliesText = repliesCount === '1' ? 'resposta' : 'respostas';
            text.textContent = 'Ver ' + repliesCount + ' ' + repliesText;
            button.dataset.expanded = 'false';
            setTimeout(() => { button.dataset.toggling = 'false'; }, 200);
        } else {
            // Expandir — buscar do servidor se ainda não carregou
            if (!repliesContainer || !repliesContainer.dataset.loaded) {
                // Primeiro clique: criar container e buscar respostas
                if (!repliesContainer) {
                    const parentComment = document.querySelector('.comment-item[data-comment-id="' + commentId + '"]');
                    if (parentComment) {
                        const div = document.createElement('div');
                        div.className = 'comment-replies';
                        div.setAttribute('data-comment-id', commentId);
                        div.style.display = 'block';
                        div.innerHTML = '<div class="replies-loading" style="padding:10px;color:#888;font-size:13px;">Carregando respostas...</div>';
                        parentComment.querySelector('.comment-content').appendChild(div);
                        repliesContainer = div;
                    }
                } else {
                    repliesContainer.style.display = 'block';
                    repliesContainer.innerHTML = '<div class="replies-loading" style="padding:10px;color:#888;font-size:13px;">Carregando respostas...</div>';
                }
                
                icon.className = 'fas fa-chevron-up';
                const totalCount = text.textContent.match(/\d+/)?.[0] || '0';
                const repliesText2 = totalCount === '1' ? 'resposta' : 'respostas';
                text.textContent = 'Ocultar ' + totalCount + ' ' + repliesText2;
                button.dataset.expanded = 'true';
                
                this.fetchReplies(commentId, 0).then(() => {
                    button.dataset.toggling = 'false';
                }).catch(() => {
                    button.dataset.toggling = 'false';
                });
            } else {
                // Já carregou antes: apenas mostrar
                repliesContainer.style.display = 'block';
                icon.className = 'fas fa-chevron-up';
                const repliesCount = text.textContent.match(/\d+/)?.[0] || '0';
                const repliesText = repliesCount === '1' ? 'resposta' : 'respostas';
                text.textContent = 'Ocultar ' + repliesCount + ' ' + repliesText;
                button.dataset.expanded = 'true';
                setTimeout(() => { button.dataset.toggling = 'false'; }, 200);
            }
        }
    }
    
    /**
     * Buscar respostas do servidor (paginado, 6 por vez)
     */
    async fetchReplies(commentId, offset) {
        const repliesContainer = document.querySelector('.comment-replies[data-comment-id="' + commentId + '"]');
        if (!repliesContainer) return;
        
        try {
            const response = await fetch('api/get_replies.php?comment_id=' + commentId + '&offset=' + offset + '&limit=6');
            const data = await response.json();
            
            if (!data.success) return;
            
            // Remover loading
            const loadingEl = repliesContainer.querySelector('.replies-loading');
            if (loadingEl) loadingEl.remove();
            
            // Marcar como carregado
            repliesContainer.dataset.loaded = 'true';
            
            // Guardar no cache local
            if (!this.allRepliesCache) this.allRepliesCache = {};
            if (!this.allRepliesCache[commentId]) this.allRepliesCache[commentId] = [];
            data.replies.forEach(r => {
                if (!this.allRepliesCache[commentId].some(c => c.id === r.id)) {
                    this.allRepliesCache[commentId].push(r);
                }
            });
            
            // Remover botão "Ver mais" existente
            const existingLoadMore = repliesContainer.querySelector('.load-more-replies');
            if (existingLoadMore) existingLoadMore.remove();
            
            // Renderizar respostas
            let html = '';
            data.replies.forEach(reply => {
                if (!repliesContainer.querySelector('.reply-item[data-comment-id="' + reply.id + '"]')) {
                    html += this.createReplyHTML(reply);
                }
            });
            repliesContainer.insertAdjacentHTML('beforeend', html);
            
            // Botão "Ver mais" se há mais
            if (data.has_more) {
                const nextOffset = offset + data.replies.length;
                const loadMoreDiv = document.createElement('div');
                loadMoreDiv.className = 'load-more-replies';
                loadMoreDiv.innerHTML = '<button class="load-more-replies-btn" data-comment-id="' + commentId + '" data-offset="' + nextOffset + '">Ver mais respostas</button>';
                repliesContainer.appendChild(loadMoreDiv);
            }
            
            this.startEditTimer();
            
        } catch (error) {
            const loadingEl = repliesContainer.querySelector('.replies-loading');
            if (loadingEl) loadingEl.textContent = 'Erro ao carregar respostas';
        }
    }
    
    /**
     * Carregar mais respostas do servidor
     */
    loadMoreReplies(button) {
        const commentId = button.dataset.commentId;
        const offset = parseInt(button.dataset.offset) || 0;
        
        button.disabled = true;
        button.textContent = 'Carregando...';
        
        this.fetchReplies(commentId, offset).then(() => {
            // fetchReplies já remove o botão antigo e insere um novo se necessário
        }).catch(() => {
            button.disabled = false;
            button.textContent = 'Tentar novamente';
        });
    }
    
    editComment(commentId) {
        const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentId}"], .reply-item[data-comment-id="${commentId}"]`);
        if (!commentItem) return;
        
        const commentTextDiv = commentItem.querySelector('.comment-text');
        if (!commentTextDiv) return;
        
        const currentText = commentTextDiv.textContent.trim();
        
        // Determinar o input principal (mobile ou desktop)
        const inputId = this.isMobile ? 'commentInputMobile' : 'commentInput';
        const input = document.getElementById(inputId);
        if (!input) return;
        
        // Cancelar edição anterior se houver
        if (input.dataset.editMode === 'true') {
            this.cancelEdit();
        }
        
        // Ativar modo edição no input principal
        input.dataset.editMode = 'true';
        input.dataset.editCommentId = commentId;
        input.value = currentText;
        input.placeholder = 'Editando comentário...';
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        
        // Destacar o comentário sendo editado
        commentItem.classList.add('editing-highlight');
        
        // Mostrar indicador visual de edição no footer
        this._showEditIndicator(commentId, commentItem);
    }
    
    _showEditIndicator(commentId, commentItem) {
        const inputId = this.isMobile ? 'commentInputMobile' : 'commentInput';
        const input = document.getElementById(inputId);
        if (!input) return;
        
        const inputContainer = input.closest('.comment-input-container') || input.closest('.modal-footer') || input.closest('.comment-form');
        if (!inputContainer) return;
        
        // Remover indicador anterior se existir
        const existingIndicator = inputContainer.parentElement.querySelector('.edit-indicator');
        if (existingIndicator) existingIndicator.remove();
        
        // Criar indicador
        const indicator = document.createElement('div');
        indicator.className = 'edit-indicator';
        
        // Capturar o nome do autor
        const authorName = commentItem.querySelector('.comment-username')?.textContent?.trim() || '';
        
        indicator.innerHTML = `
            <div class="edit-indicator-content">
                <i class="fas fa-edit"></i>
                <span>Editando comentário${authorName ? ' de ' + authorName : ''}</span>
            </div>
            <button class="edit-indicator-cancel" title="Cancelar edição">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        // Inserir antes do input container
        inputContainer.parentElement.insertBefore(indicator, inputContainer);
        
        // Bind cancelar
        indicator.querySelector('.edit-indicator-cancel').addEventListener('click', () => {
            this.cancelEdit();
        });
    }
    
    cancelEdit() {
        const inputId = this.isMobile ? 'commentInputMobile' : 'commentInput';
        const input = document.getElementById(inputId);
        if (!input) return;
        
        // Limpar estado de edição
        delete input.dataset.editMode;
        delete input.dataset.editCommentId;
        input.value = '';
        
        // Restaurar placeholder baseado no modo atual
        if (input.dataset.replyMode === 'true') {
            input.placeholder = 'Mete dica...';
        } else {
            input.placeholder = 'Mete dica...';
        }
        
        // Remover highlight do comentário
        document.querySelectorAll('.editing-highlight').forEach(el => el.classList.remove('editing-highlight'));
        
        // Remover indicador de edição
        document.querySelectorAll('.edit-indicator').forEach(el => el.remove());
    }
    
    async submitEdit(input) {
        const commentId = input.dataset.editCommentId;
        const newText = input.value.trim();
        
        if (!newText) {
            alert('O comentário não pode estar vazio');
            return;
        }
        
        if (!commentId) return;
        
        this._closeMentionAutocomplete();
        
        input.disabled = true;
        const submitBtn = input.closest('.comment-input-container')?.querySelector('.submit-comment');
        if (submitBtn) submitBtn.disabled = true;
        
        try {
            const response = await fetch('api/edit_comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    comment_id: parseInt(commentId),
                    comment_text: newText
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Atualizar o texto na UI
                const commentItem = document.querySelector(`.comment-item[data-comment-id="${commentId}"], .reply-item[data-comment-id="${commentId}"]`);
                if (commentItem) {
                    const commentTextDiv = commentItem.querySelector('.comment-text');
                    if (commentTextDiv) {
                        const savedText = data.comment?.comment_text ?? newText;
                        commentTextDiv.innerHTML = this.formatMentions(savedText);
                    }
                    
                    // Remover botão de editar se o tempo expirou
                    const editBtn = commentItem.querySelector('.comment-edit-btn');
                    if (editBtn && data.edit_time_left <= 0) {
                        editBtn.remove();
                    }
                }
                
                // Limpar modo edição
                this.cancelEdit();
            } else {
                alert(data.error || 'Erro ao editar comentário');
            }
        } catch (error) {
            console.error('Erro ao editar:', error);
            alert('Erro de conexão ao editar comentário');
        } finally {
            input.disabled = false;
            if (submitBtn) submitBtn.disabled = false;
        }
    }
    
    createCommentHTML(comment) {
        let html = '<div class="comment-item" data-comment-id="' + comment.id + '">';
        html += '<img src="assets/images/avatars/' + (comment.profile_picture || 'default.webp') + '" alt="' + comment.full_name + '" class="comment-avatar" loading="lazy">';
        html += '<div class="comment-content">';
        html += '<div class="comment-header">';
        html += '<a href="perfil.php?id=' + comment.user_id + '" class="comment-username" data-username="' + (comment.username || '') + '">' + (comment.full_name || comment.username) + '</a>';
        if (comment.is_verified) {
            html += '<i class="fas fa-check-circle verified-badge"></i>';
        }
        html += '<span class="comment-time">' + comment.time_ago + '</span>';
        html += '</div>';
        html += '<div class="comment-text">' + this.formatMentions(comment.comment_text) + '</div>';
        html += '<div class="comment-actions">';
        html += '<button class="comment-like-btn ' + (comment.user_liked ? 'liked' : '') + '" data-comment-id="' + comment.id + '">';
        html += '<i class="fas fa-heart"></i>';
        html += '<span class="like-count">' + (comment.likes_count || 0) + '</span>';
        html += '</button>';
        html += '<button class="comment-reply-btn" data-comment-id="' + comment.id + '">';
        html += '<i class="fas fa-reply"></i> Responder';
        html += '</button>';
        if (comment.can_edit) {
            const timeLeft = comment.edit_time_left || 0;
            const isExpiringSoon = timeLeft <= 30;
            
            html += '<button class="comment-edit-btn' + (isExpiringSoon ? ' expiring' : '') + '" data-comment-id="' + comment.id + '" data-time-left="' + timeLeft + '">';
            html += '<i class="fas fa-edit"></i> Editar';
            if (isExpiringSoon && timeLeft > 0) {
                html += ' (' + timeLeft + 's)';
            }
            html += '</button>';
        }
        html += '</div>';
        
        // Botão "Ver respostas" — respostas carregam sob demanda via API
        if (comment.replies_count > 0) {
            const repliesText = comment.replies_count === 1 ? 'resposta' : 'respostas';
            html += '<div class="replies-toggle-container">';
            html += '<button class="replies-toggle-btn" data-comment-id="' + comment.id + '" data-expanded="false">';
            html += '<i class="fas fa-chevron-down"></i>';
            html += '<span class="replies-count">Ver ' + comment.replies_count + ' ' + repliesText + '</span>';
            html += '</button>';
            html += '</div>';
        }
        
        html += '</div>';
        html += '</div>';
        
        return html;
    }
    
    createReplyHTML(reply) {
        let html = '<div class="reply-item" data-comment-id="' + reply.id + '">';
        html += '<img src="assets/images/avatars/' + (reply.profile_picture || 'default.webp') + '" alt="' + reply.full_name + '" class="reply-avatar" loading="lazy">';
        html += '<div class="comment-content">';
        html += '<div class="comment-header">';
        html += '<a href="perfil.php?id=' + reply.user_id + '" class="comment-username" data-username="' + (reply.username || '') + '">' + (reply.full_name || reply.username) + '</a>';
        if (reply.is_verified) {
            html += '<i class="fas fa-check-circle verified-badge"></i>';
        }
        html += '<span class="comment-time">' + reply.time_ago + '</span>';
        html += '</div>';
        html += '<div class="comment-text">' + this.formatMentions(reply.comment_text) + '</div>';
        html += '<div class="comment-actions">';
        html += '<button class="comment-like-btn ' + (reply.user_liked ? 'liked' : '') + '" data-comment-id="' + reply.id + '">';
        html += '<i class="fas fa-heart"></i>';
        html += '<span class="like-count">' + (reply.likes_count || 0) + '</span>';
        html += '</button>';
        html += '<button class="comment-reply-btn" data-comment-id="' + reply.id + '">';
        html += '<i class="fas fa-reply"></i> Responder';
        html += '</button>';
        if (reply.can_edit) {
            const timeLeft = reply.edit_time_left || 0;
            const isExpiringSoon = timeLeft <= 30;
            
            html += '<button class="comment-edit-btn' + (isExpiringSoon ? ' expiring' : '') + '" data-comment-id="' + reply.id + '" data-time-left="' + timeLeft + '">';
            html += '<i class="fas fa-edit"></i> Editar';
            if (isExpiringSoon && timeLeft > 0) {
                html += ' (' + timeLeft + 's)';
            }
            html += '</button>';
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        return html;
    }
    
    async submitComment(platform) {
        const inputId = platform === 'mobile' ? 'commentInputMobile' : 'commentInput';
        const input = document.getElementById(inputId);
        
        if (!input || !this.currentVideoId) return;

        this._closeCommentEmojiPicker();
        
        // If in edit mode, submit edit instead
        if (input.dataset.editMode === 'true') {
            await this.submitEdit(input);
            return;
        }
        
        // If in reply mode (replies thread), submit as reply
        if (input.dataset.replyMode === 'true') {
            await this.submitThreadReply(input);
            return;
        }
        
        let text = input.value.trim();
        if (!text) return;

        // Fechar autocomplete se aberto
        this._closeMentionAutocomplete();

        // Validar menções (remove inexistentes, bloqueia se > 5)
        text = await this._validateMentions(text);
        if (text === null) return;
        
        // Desabilitar input enquanto envia
        input.disabled = true;
        
        fetch('api/add_comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                video_id: parseInt(this.currentVideoId),
                comment_text: text
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                
                // Adicionar comentário na UI sem recarregar
                this.addCommentToUI(data.comment, platform);
                
                // Atualizar contador de comentários em tempo real
                this.updateCommentCountInUI(this.currentVideoId, 1);
            } else {
                alert(data.error || 'Erro ao adicionar comentário');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro de conexão ao adicionar comentário');
        })
        .finally(() => {
            input.disabled = false;
        });
    }
    
    /**
     * Adicionar comentário na UI em tempo real (sem reload)
     */
    addCommentToUI(comment, platform = 'desktop') {
        const containerSelector = platform === 'mobile' ? '#commentsListMobile' : '#commentsList';
        const container = document.querySelector(containerSelector);
        
        if (!container) return;
        
        // Remover mensagem de "nenhum comentário" se existir
        const emptyMessage = container.querySelector('.empty-comments');
        if (emptyMessage) {
            emptyMessage.remove();
        }
        
        // Criar HTML do novo comentário
        const commentHTML = this.createCommentHTML(comment);
        
        // Adicionar no topo da lista
        container.insertAdjacentHTML('afterbegin', commentHTML);
        
        // Animar entrada do comentário
        const newCommentElement = container.firstElementChild;
        if (newCommentElement) {
            newCommentElement.style.opacity = '0';
            newCommentElement.style.transform = 'translateY(-20px)';
            
            setTimeout(() => {
                newCommentElement.style.transition = 'all 0.3s ease';
                newCommentElement.style.opacity = '1';
                newCommentElement.style.transform = 'translateY(0)';
            }, 10);
        }
        
        // Rebind eventos se necessário
        this.startEditTimer();
    }
    
    /**
     * Atualizar contador de comentários no vídeo (incremento/decremento)
     */
    updateCommentCountInUI(videoId, increment = 0) {
        // Buscar o botão de comentários do vídeo atual
        const commentButton = document.querySelector(`.comment-btn[data-video-id="${videoId}"]`);
        if (commentButton) {
            const countElement = commentButton.querySelector('.action-count');
            if (countElement) {
                const currentCount = parseInt(countElement.textContent.replace(/[^0-9]/g, '')) || 0;
                const newCount = Math.max(0, currentCount + increment);
                countElement.textContent = this.formatCount(newCount);
                
                console.log(`🔄 Comentários do vídeo ${videoId}: ${currentCount} → ${newCount}`);
                
                // Animação sutil
                countElement.style.transition = 'transform 0.3s ease';
                countElement.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    countElement.style.transform = 'scale(1)';
                }, 300);
            }
        }
    }
    
    /**
     * Formatar contador (1K, 1M, etc)
     */
    formatCount(count) {
        if (count >= 1000000) {
            return (count / 1000000).toFixed(1).replace('.0', '') + 'M';
        } else if (count >= 1000) {
            return (count / 1000).toFixed(1).replace('.0', '') + 'K';
        }
        return count.toString();
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Formatar texto com menções (@username) destacadas
     */
    formatMentions(text) {
        const normalizedText = String(text ?? '')
            .replace(/\r\n?/g, '\n')
            .replace(/\n{3,}/g, '\n\n');

        // Primeiro escape HTML
        const escaped = this.escapeHtml(normalizedText);
        // Depois encontrar @menções e converter em links clicáveis
        return escaped.replace(/@(\w+)/g, (match, username) => {
            return `<span class="mention" data-username="${username}" onclick="window.location.href='perfil.php?username=${username}'" title="@${username}">@${username}</span>`;
        });
    }

    // =============================================
    //  SISTEMA DE MENÇÕES — Autocomplete
    // =============================================

    /**
     * Configuração inicial: escuta input/keydown em todos os textareas de comentário
     * Usa event delegation para capturar textareas criados dinamicamente (replies)
     */
    setupMentionAutocomplete() {
        // Delegate: qualquer textarea que seja input de comentário/reply
        document.addEventListener('input', (e) => {
            const textarea = e.target;
            if (this._isMentionableTextarea(textarea)) {
                this._handleMentionInput(textarea);
            }
        });

        document.addEventListener('keydown', (e) => {
            const textarea = e.target;
            if (this._isMentionableTextarea(textarea) && this.activeMentionInput === textarea) {
                this._handleMentionKeydown(e, textarea);
            }
        });

        // Fechar autocomplete ao clicar fora
        document.addEventListener('mousedown', (e) => {
            if (!e.target.closest('#mentionAutocompleteDropdown') && !this._isMentionableTextarea(e.target)) {
                this._closeMentionAutocomplete();
            }
        });
    }

    /**
     * Criar o dropdown de autocomplete uma vez no body (evita overflow:hidden)
     */
    _createMentionDropdown() {
        if (document.getElementById('mentionAutocompleteDropdown')) return;
        const dropdown = document.createElement('div');
        dropdown.id = 'mentionAutocompleteDropdown';
        dropdown.className = 'mention-autocomplete';
        document.body.appendChild(dropdown);
    }

    /**
     * Verifica se o elemento é um textarea de comentário/reply
     */
    _isMentionableTextarea(el) {
        if (!el || el.tagName !== 'TEXTAREA') return false;
        return el.id === 'commentInput' || 
               el.id === 'commentInputMobile' || 
               el.classList.contains('reply-input') ||
               el.classList.contains('edit-input');
    }

    /**
     * Extrai a palavra de menção atual na posição do cursor
     * Retorna { query, startPos, endPos } ou null
     */
    _getMentionQuery(textarea) {
        const cursorPos = textarea.selectionStart;
        const text = textarea.value;

        // Buscar para trás a partir do cursor até encontrar @ ou espaço/início
        let start = cursorPos - 1;
        while (start >= 0 && text[start] !== ' ' && text[start] !== '\n') {
            if (text[start] === '@') {
                // Verificar se antes do @ é início ou espaço (não no meio de palavra)
                if (start === 0 || text[start - 1] === ' ' || text[start - 1] === '\n') {
                    const query = text.substring(start + 1, cursorPos);
                    return { query, startPos: start, endPos: cursorPos };
                }
                return null;
            }
            start--;
        }
        return null;
    }

    /**
     * Conta quantas menções @ já existem no texto
     */
    _countMentions(text) {
        const matches = text.match(/@\w+/g);
        return matches ? matches.length : 0;
    }

    /**
     * Handler principal: quando o usuário digita no textarea
     */
    _handleMentionInput(textarea) {
        const mentionData = this._getMentionQuery(textarea);

        if (!mentionData) {
            this._closeMentionAutocomplete();
            return;
        }

        // Verificar se já atingiu o limite de menções
        const currentMentions = this._countMentions(textarea.value);
        // A menção sendo digitada conta como uma "em progresso", mas as completadas
        // são contadas. Se já tem MAX, fechar.
        // Contar menções já completas (excluindo a que está sendo digitada)
        const textBefore = textarea.value.substring(0, mentionData.startPos);
        const textAfter = textarea.value.substring(mentionData.endPos);
        const completedMentions = this._countMentions(textBefore + textAfter);
        if (completedMentions >= this.MAX_MENTIONS) {
            this._closeMentionAutocomplete();
            return;
        }

        this.activeMentionInput = textarea;
        this.selectedMentionIndex = -1;

        // Debounce de 250ms para evitar muitas requests
        clearTimeout(this.mentionDebounceTimer);
        this.mentionDebounceTimer = setTimeout(() => {
            this._fetchMentionUsers(mentionData.query, textarea);
        }, 250);
    }

    /**
     * Buscar usuários (com cache)
     */
    async _fetchMentionUsers(query, textarea) {
        const cacheKey = query.toLowerCase();

        // Verificar cache
        if (this.mentionCache[cacheKey] && 
            (Date.now() - this.mentionCache[cacheKey].time) < this.mentionCacheTTL) {
            this.mentionResults = this.mentionCache[cacheKey].data;
            this._renderMentionAutocomplete(textarea);
            return;
        }

        try {
            const resp = await fetch(`api/search_mention_users.php?q=${encodeURIComponent(query)}`);
            const data = await resp.json();

            if (data.success) {
                this.mentionResults = data.users;
                this.mentionCache[cacheKey] = { data: data.users, time: Date.now() };
                
                // Só renderizar se o textarea ainda está ativo
                if (this.activeMentionInput === textarea) {
                    this._renderMentionAutocomplete(textarea);
                }
            }
        } catch (err) {
            console.error('Mention search error:', err);
        }
    }

    /**
     * Renderizar dropdown de autocomplete
     */
    _renderMentionAutocomplete(textarea) {
        // Usar dropdown fixo no body para evitar overflow:hidden dos containers-pai
        let dropdown = document.getElementById('mentionAutocompleteDropdown');
        if (!dropdown) {
            this._createMentionDropdown();
            dropdown = document.getElementById('mentionAutocompleteDropdown');
        }

        if (this.mentionResults.length === 0) {
            dropdown.classList.remove('active');
            dropdown.innerHTML = '';
            return;
        }

        dropdown.innerHTML = this.mentionResults.map((user, i) => {
            const badge = user.is_follower ? 'Seguindo' : '';
            const selectedClass = i === this.selectedMentionIndex ? 'selected' : '';
            return `
                <div class="mention-item ${selectedClass}" data-index="${i}" data-username="${this.escapeHtml(user.username)}">
                    <img class="mention-item-avatar" src="assets/images/avatars/${user.profile_picture}" alt=""
                         style="width:32px;height:32px;min-width:32px;max-width:32px;min-height:32px;max-height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;border:none" loading="lazy">
                    <div class="mention-item-info">
                        <span class="mention-item-username">@${this.escapeHtml(user.username)}${user.is_verified ? ' <i class="fas fa-check-circle verified-badge" style="font-size:0.7rem"></i>' : ''}</span>
                        <span class="mention-item-fullname">${this.escapeHtml(user.full_name)}</span>
                    </div>
                    ${badge ? `<span class="mention-item-badge">${badge}</span>` : ''}
                </div>
            `;
        }).join('');

        // Posicionar acima do textarea usando coordenadas fixas
        const rect = textarea.getBoundingClientRect();
        const dropdownWidth = Math.max(rect.width, 280);
        dropdown.style.left = rect.left + 'px';
        dropdown.style.width = dropdownWidth + 'px';
        dropdown.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
        dropdown.style.top = 'auto';

        dropdown.classList.add('active');

        // Bind click events nos itens
        dropdown.querySelectorAll('.mention-item').forEach(item => {
            item.addEventListener('mousedown', (e) => {
                e.preventDefault(); // Evitar que o textarea perca o foco
                const username = item.dataset.username;
                this._insertMention(textarea, username);
            });
        });
    }

    /**
     * Inserir a menção no textarea
     */
    _insertMention(textarea, username) {
        const mentionData = this._getMentionQuery(textarea);
        if (!mentionData) return;

        const before = textarea.value.substring(0, mentionData.startPos);
        const after = textarea.value.substring(mentionData.endPos);
        
        textarea.value = before + '@' + username + ' ' + after;
        
        // Posicionar cursor após a menção
        const newCursorPos = mentionData.startPos + username.length + 2; // @username + espaço
        textarea.setSelectionRange(newCursorPos, newCursorPos);
        textarea.focus();

        this._closeMentionAutocomplete();

        // Disparar evento input para que outros handlers detectem a mudança
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Fechar dropdown de autocomplete
     */
    _closeMentionAutocomplete() {
        const dropdown = document.getElementById('mentionAutocompleteDropdown');
        if (dropdown) {
            dropdown.classList.remove('active');
            dropdown.innerHTML = '';
        }
        this.activeMentionInput = null;
        this.selectedMentionIndex = -1;
        this.mentionResults = [];
        clearTimeout(this.mentionDebounceTimer);
    }

    /**
     * Navegação por teclado no autocomplete (↑ ↓ Enter Escape)
     */
    _handleMentionKeydown(e, textarea) {
        const dropdown = document.getElementById('mentionAutocompleteDropdown');
        if (!dropdown || !dropdown.classList.contains('active') || this.mentionResults.length === 0) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedMentionIndex = Math.min(this.selectedMentionIndex + 1, this.mentionResults.length - 1);
                this._updateMentionSelection(dropdown);
                break;

            case 'ArrowUp':
                e.preventDefault();
                this.selectedMentionIndex = Math.max(this.selectedMentionIndex - 1, 0);
                this._updateMentionSelection(dropdown);
                break;

            case 'Enter':
                if (this.selectedMentionIndex >= 0) {
                    e.preventDefault();
                    const user = this.mentionResults[this.selectedMentionIndex];
                    this._insertMention(textarea, user.username);
                }
                break;

            case 'Tab':
                if (this.selectedMentionIndex >= 0 || this.mentionResults.length === 1) {
                    e.preventDefault();
                    const idx = this.selectedMentionIndex >= 0 ? this.selectedMentionIndex : 0;
                    const user = this.mentionResults[idx];
                    this._insertMention(textarea, user.username);
                }
                break;

            case 'Escape':
                e.preventDefault();
                this._closeMentionAutocomplete();
                break;
        }
    }

    /**
     * Atualizar highlight visual no dropdown
     */
    _updateMentionSelection(dropdown) {
        dropdown.querySelectorAll('.mention-item').forEach((item, i) => {
            item.classList.toggle('selected', i === this.selectedMentionIndex);
        });

        // Scroll into view se necessário
        const selected = dropdown.querySelector('.mention-item.selected');
        if (selected) {
            selected.scrollIntoView({ block: 'nearest' });
        }
    }

    /**
     * Validar menções antes de enviar: remove menções de usuários inexistentes
     * Retorna o texto limpo ou null se as menções forem inválidas
     */
    async _validateMentions(text) {
        const mentions = text.match(/@(\w+)/g);
        if (!mentions || mentions.length === 0) return text;

        // Limitar a MAX_MENTIONS
        if (mentions.length > this.MAX_MENTIONS) {
            alert(`Máximo de ${this.MAX_MENTIONS} menções por comentário.`);
            return null;
        }

        // Extrair usernames únicos
        const usernames = [...new Set(mentions.map(m => m.substring(1)))];

        // Verificar se todos existem usando o endpoint de busca
        // Consultar cada username que não está no cache
        const invalid = [];
        for (const username of usernames) {
            const cacheKey = username.toLowerCase();
            let found = false;

            if (this.mentionCache[cacheKey] && 
                (Date.now() - this.mentionCache[cacheKey].time) < this.mentionCacheTTL) {
                found = this.mentionCache[cacheKey].data.some(
                    u => u.username.toLowerCase() === cacheKey
                );
            }

            if (!found) {
                // Buscar no servidor (uma única request com busca exata)
                try {
                    const resp = await fetch(`api/search_mention_users.php?q=${encodeURIComponent(username)}`);
                    const data = await resp.json();
                    if (data.success) {
                        this.mentionCache[cacheKey] = { data: data.users, time: Date.now() };
                        found = data.users.some(u => u.username.toLowerCase() === cacheKey);
                    }
                } catch (e) { /* noop */ }
            }

            if (!found) invalid.push(username);
        }

        if (invalid.length > 0) {
            // Remover as menções inválidas silenciosamente
            let cleaned = text;
            invalid.forEach(u => {
                cleaned = cleaned.replace(new RegExp(`@${u}\\b`, 'g'), u);
            });
            return cleaned;
        }

        return text;
    }
    
    startEditTimer() {
        if (this.editTimerInterval) {
            clearInterval(this.editTimerInterval);
        }
        
        this.editTimerInterval = setInterval(() => {
            const editButtons = document.querySelectorAll('.comment-edit-btn[data-time-left]');
            
            if (editButtons.length === 0) {
                clearInterval(this.editTimerInterval);
                this.editTimerInterval = null;
                return;
            }
            
            editButtons.forEach(button => {
                let timeLeft = parseInt(button.dataset.timeLeft);
                
                if (timeLeft <= 0) {
                    button.remove();
                    return;
                }
                
                timeLeft--;
                button.dataset.timeLeft = timeLeft;
                
                const isExpiringSoon = timeLeft <= 30;
                
                if (isExpiringSoon) {
                    button.classList.add('expiring');
                } else {
                    button.classList.remove('expiring');
                }
                
                const icon = button.querySelector('i');
                if (isExpiringSoon && timeLeft > 0) {
                    button.innerHTML = '<i class="fas fa-edit"></i> Editar (' + timeLeft + 's)';
                } else if (timeLeft > 30) {
                    button.innerHTML = '<i class="fas fa-edit"></i> Editar';
                }
            });
        }, 1000);
    }
}

// Inicializar
document.addEventListener('DOMContentLoaded', () => {
    try {
        window.commentsSystem = new CommentsSystem();
        console.log('CommentsSystem inicializado');
    } catch (error) {
        console.error('Erro ao inicializar CommentsSystem:', error);
    }
});

