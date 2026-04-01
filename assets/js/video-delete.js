// Sistema de Exclusão de Vídeos
class VideoDeleteManager {
    constructor() {
        this.currentVideoId = null;
        this.init();
    }

    init() {
        // Vincular eventos aos botões de deletar no feed
        document.addEventListener('click', (e) => {
            if (e.target.closest('.delete-btn')) {
                e.preventDefault();
                e.stopPropagation();
                const videoId = e.target.closest('.delete-btn').dataset.videoId;
                this.confirmDelete(videoId);
            }
        });

        // Vincular eventos aos botões de boost no feed
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.boost-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const videoId = btn.dataset.videoId;
                const currentBoosted = btn.dataset.boosted === '1';
                this.toggleBoost(videoId, currentBoosted, btn);
            }
        });

        // Vincular evento ao botão de confirmar exclusão
        document.getElementById('confirmDeleteBtn')?.addEventListener('click', () => {
            this.executeDelete();
        });

        // Fechar modal ao clicar fora
        document.getElementById('deleteModal')?.addEventListener('click', (e) => {
            if (e.target.id === 'deleteModal') {
                this.closeModal();
            }
        });

        // Esc para fechar modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.getElementById('deleteModal').style.display === 'flex') {
                this.closeModal();
            }
        });
    }

    confirmDelete(videoId) {
        this.currentVideoId = videoId;
        const modal = document.getElementById('deleteModal');
        if (modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden'; // Prevenir scroll
        }
    }

    closeModal() {
        const modal = document.getElementById('deleteModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = ''; // Restaurar scroll
        }
        this.currentVideoId = null;
    }

    async executeDelete() {
        if (!this.currentVideoId) return;

        const confirmBtn = document.getElementById('confirmDeleteBtn');
        const originalText = confirmBtn.innerHTML;
        
        // Loading state
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Apagando...';
        confirmBtn.disabled = true;

        try {
            const response = await fetch('api/delete_video.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    video_id: this.currentVideoId
                })
            });

            const result = await response.json();

            if (result.success) {
                this.showMessage('Vídeo apagado com sucesso!', 'success');
                this.closeModal();
                
                // Remover vídeo da interface
                this.removeVideoFromUI(this.currentVideoId);
                
                // Se estiver no feed, ir para o próximo vídeo
                if (window.location.pathname.includes('index.php') || window.location.pathname === '/') {
                    this.navigateToNextVideo();
                }
            } else {
                this.showMessage(result.message || 'Erro ao apagar vídeo', 'error');
            }
        } catch (error) {
            console.error('Erro:', error);
            this.showMessage('Erro de conexão. Tente novamente.', 'error');
        } finally {
            // Restaurar botão
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        }
    }

    async toggleBoost(videoId, currentBoosted, btn) {
        const newBoosted = currentBoosted ? 0 : 1;

        btn.disabled = true;
        const svgEl = btn.querySelector('svg');
        const spanEl = btn.querySelector('.action-count');

        try {
            const formData = new FormData();
            formData.append('video_id', videoId);
            formData.append('boosted', newBoosted);

            const response = await fetch('api/toggle_boost.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                btn.dataset.boosted = newBoosted ? '1' : '0';
                btn.classList.toggle('boosted', !!newBoosted);
                btn.title = newBoosted ? 'Remover boost' : 'Dar boost';
                if (svgEl) svgEl.setAttribute('fill', newBoosted ? 'currentColor' : 'none');
                if (spanEl) spanEl.textContent = newBoosted ? 'Boosted' : 'Boost';

                // Show/hide the boosted badge on the video overlay
                const videoItem = btn.closest('.video-item');
                if (videoItem) {
                    const overlay = videoItem.querySelector('.video-info-overlay');
                    if (overlay) {
                        const existingBadge = overlay.querySelector('.boosted-badge');
                        if (newBoosted && !existingBadge) {
                            const badge = document.createElement('div');
                            badge.className = 'boosted-badge';
                            badge.innerHTML = '<i class="fas fa-bolt"></i> Em destaque';
                            const authorRow = overlay.querySelector('.video-author-row');
                            if (authorRow) authorRow.after(badge);
                            else overlay.prepend(badge);
                        } else if (!newBoosted && existingBadge) {
                            existingBadge.remove();
                        }
                    }
                }

                this.showMessage(newBoosted ? '⚡ Boost ativado!' : 'Boost removido', 'success');
            } else {
                this.showMessage(result.error || 'Erro ao alterar boost', 'error');
            }
        } catch (err) {
            console.error('toggleBoost error:', err);
            this.showMessage('Erro de conexão. Tente novamente.', 'error');
        } finally {
            btn.disabled = false;
        }
    }

    removeVideoFromUI(videoId) {
        const videoItem = document.querySelector(`[data-video-id="${videoId}"]`);
        if (videoItem) {
            videoItem.style.animation = 'fadeOut 0.3s ease-out';
            setTimeout(() => {
                videoItem.remove();
            }, 300);
        }

        // Remover do perfil (perfil.php)
        const videoCard = document.querySelector(`[data-video-id="${videoId}"]`);
        if (videoCard && videoCard.classList.contains('video-card')) {
            videoCard.style.animation = 'fadeOut 0.3s ease-out';
            setTimeout(() => {
                videoCard.remove();
                this.checkEmptyProfile();
            }, 300);
        }
    }

    navigateToNextVideo() {
        // No feed, ir para o próximo vídeo disponível
        const remainingVideos = document.querySelectorAll('.video-item');
        if (remainingVideos.length > 0) {
            const firstVideo = remainingVideos[0];
            const videoElement = firstVideo.querySelector('video');
            if (videoElement) {
                videoElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                videoElement.play().catch(console.log);
            }
        } else {
            // Se não há mais vídeos, recarregar a página
            window.location.reload();
        }
    }

    checkEmptyProfile() {
        const videoGrid = document.querySelector('.videos-grid');
        if (videoGrid && videoGrid.children.length === 0) {
            videoGrid.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-video"></i>
                    <h3>Nenhum vídeo</h3>
                    <p>Os vídeos aparecerão aqui quando forem postados.</p>
                </div>
            `;
        }
    }

    showMessage(message, type = 'info') {
        // Remover mensagem existente
        const existingMessage = document.querySelector('.toast-message');
        if (existingMessage) {
            existingMessage.remove();
        }

        // Criar nova mensagem
        const messageEl = document.createElement('div');
        messageEl.className = 'toast-message toast-' + type;
        messageEl.textContent = message;
        
        const bgColor = type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#3b82f6';
        messageEl.style.cssText = `
            position: fixed; 
            top: 20px; 
            right: 20px; 
            background: ${bgColor}; 
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

        // Auto remover após 3 segundos
        setTimeout(() => {
            if (messageEl.parentNode) {
                messageEl.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => messageEl.remove(), 300);
            }
        }, 3000);
    }
}

// Funções globais para compatibilidade com onclick
function confirmDeleteVideo(videoId) {
    if (window.videoDeleteManager) {
        window.videoDeleteManager.confirmDelete(videoId);
    }
}

function closeDeleteModal() {
    if (window.videoDeleteManager) {
        window.videoDeleteManager.closeModal();
    }
}

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    window.videoDeleteManager = new VideoDeleteManager();
});

// CSS para animações (injetado via JavaScript)
if (!document.getElementById('delete-animations-css')) {
    const style = document.createElement('style');
    style.id = 'delete-animations-css';
    style.textContent = `
        @keyframes fadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        .delete-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .delete-modal-content {
            background: white;
            border-radius: 12px;
            max-width: 400px;
            width: 90%;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalShow 0.3s ease-out;
        }
        
        @keyframes modalShow {
            from { opacity: 0; transform: scale(0.9) translateY(-20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        .delete-modal-header {
            padding: 20px;
            border-bottom: 1px solid #e5e5e5;
        }
        
        .delete-modal-header h3 {
            margin: 0;
            color: #dc2626;
            font-size: 18px;
        }
        
        .delete-modal-body {
            padding: 20px;
        }
        
        .delete-modal-body p {
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .delete-modal-body small {
            color: #666;
        }
        
        .delete-modal-footer {
            padding: 20px;
            background: #f8f8f8;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn-cancel, .btn-delete {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .btn-cancel {
            background: #e5e5e5;
            color: #333;
        }
        
        .btn-cancel:hover {
            background: #d4d4d4;
        }
        
        .btn-delete {
            background: #dc2626;
            color: white;
        }
        
        .btn-delete:hover:not(:disabled) {
            background: #b91c1c;
        }
        
        .btn-delete:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .delete-video-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            padding: 8px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 10;
        }
        
        .delete-video-btn:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        
        .video-card {
            position: relative;
        }
        
        .action-btn.delete-btn {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        .action-btn.delete-btn:hover {
            background: rgba(220, 38, 38, 0.2);
            border-color: #dc2626;
        }
        
        .action-btn.delete-btn i {
            color: #dc2626;
        }
        
        .action-btn.delete-btn .action-count {
            color: #dc2626;
            font-size: 11px;
        }

        .action-btn.boost-btn {
            background: rgba(234, 179, 8, 0.1);
            border: 1px solid rgba(234, 179, 8, 0.2);
        }

        .action-btn.boost-btn:hover {
            background: rgba(234, 179, 8, 0.2);
            border-color: #eab308;
        }

        .action-btn.boost-btn svg {
            color: #eab308;
        }

        .action-btn.boost-btn .action-count {
            color: #eab308;
            font-size: 11px;
        }

        .action-btn.boost-btn.boosted {
            background: rgba(234, 179, 8, 0.25);
            border-color: #eab308;
        }
    `;
    document.head.appendChild(style);
}