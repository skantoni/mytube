// Profile Page JavaScript - MyTube

// Função para alternar modal de edição
function toggleEditModal() {
    const modal = document.getElementById('editModal');
    modal.classList.toggle('active');
}

// Função para alternar modal de logout
function toggleLogoutModal() {
    const modal = document.getElementById('logoutModal');
    modal.classList.toggle('active');
}

// Função para confirmar logout
function confirmLogout() {
    toggleLogoutModal();
}

// Preview da imagem
function previewAvatar(input, previewId = 'avatarPreview') {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            const preview = document.getElementById(previewId);
            if (preview) {
                preview.src = e.target.result;
            }
        };
        
        reader.readAsDataURL(input.files[0]);
    }
}

// Função para abrir modal de vídeo
function openVideoModal(videoId) {
    const video = userVideos.find(v => v.id == videoId);
    if (!video) return;
    
    const modal = document.getElementById('videoModal');
    const videoElement = document.getElementById('modalVideo');
    const source = videoElement.querySelector('source');
    
    // Definir fonte do vídeo
    source.src = video.video_url || resolveVideoUrl(video.video_path);
    videoElement.load();
    
    // Preencher informações
    document.getElementById('modalVideoTitle').textContent = video.title;
    document.getElementById('modalViews').textContent = video.views_count;
    document.getElementById('modalLikes').textContent = video.likes_count;
    document.getElementById('modalComments').textContent = video.comments_count;
    
    // Mostrar modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Auto-play
    videoElement.play().catch(() => {});
}

function closeVideoModal() {
    const modal = document.getElementById('videoModal');
    const videoElement = document.getElementById('modalVideo');
    
    videoElement.pause();
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Animações e efeitos
document.addEventListener('DOMContentLoaded', function() {
    // Fechar modais ao clicar fora
    document.addEventListener('click', function(e) {
        const modals = document.querySelectorAll('.modal-overlay');
        modals.forEach(modal => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });

    // Escape para fechar modais
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const activeModals = document.querySelectorAll('.modal-overlay.active');
            activeModals.forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });

    // Animação de entrada dos elementos
    const animateElements = document.querySelectorAll('.profile-info, .user-videos');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    });

    animateElements.forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(30px)';
        element.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(element);
    });

    // Hover effects para vídeos
    const videoThumbnails = document.querySelectorAll('.video-thumbnail');
    videoThumbnails.forEach(thumbnail => {
        thumbnail.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
        });
        
        thumbnail.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Validação do formulário de edição
    const editForm = document.querySelector('form[method="POST"]');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            const fullName = document.getElementById('full_name');
            const bio = document.getElementById('bio');
            
            // Validar nome completo
            if (fullName.value.trim().length < 2) {
                e.preventDefault();
                showAlert('Nome completo deve ter pelo menos 2 caracteres.', 'error');
                fullName.focus();
                return;
            }
            
            // Validar biografia (máximo 500 caracteres)
            if (bio.value.length > 500) {
                e.preventDefault();
                showAlert('Biografia deve ter no máximo 500 caracteres.', 'error');
                bio.focus();
                return;
            }
            
            // Validar arquivo de imagem (foto de perfil)
            const fileInput = document.getElementById('profilePicture');
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/heif'];
            const maxSize = 10 * 1024 * 1024; // 10MB

            if (fileInput && fileInput.files.length > 0) {
                const file = fileInput.files[0];
                
                if (file.size > maxSize) {
                    e.preventDefault();
                    showAlert('A foto de perfil deve ter no máximo 10MB.', 'error');
                    return;
                }
                
                if (!allowedTypes.includes(file.type)) {
                    e.preventDefault();
                    showAlert('Formato de foto não suportado. Use JPG, PNG, GIF ou WEBP.', 'error');
                    return;
                }
            }

            // Validar arquivo do ícone de nome
            const iconInput = document.getElementById('nameIcon');
            if (iconInput && iconInput.files.length > 0) {
                const file = iconInput.files[0];
                
                if (file.size > maxSize) {
                    e.preventDefault();
                    showAlert('O ícone deve ter no máximo 5MB.', 'error');
                    return;
                }
                
                if (!allowedTypes.includes(file.type)) {
                    e.preventDefault();
                    showAlert('Formato de ícone não suportado. Use JPG, PNG, GIF ou WEBP.', 'error');
                    return;
                }
            }
        });
    }

    // Contador de caracteres para biografia
    const bioTextarea = document.getElementById('bio');
    if (bioTextarea) {
        const maxLength = 500;
        const counterElement = document.createElement('div');
        counterElement.className = 'char-counter';
        counterElement.style.cssText = 'text-align: right; color: #94a3b8; font-size: 0.8rem; margin-top: 4px;';
        bioTextarea.parentNode.appendChild(counterElement);
        
        function updateCounter() {
            const remaining = maxLength - bioTextarea.value.length;
            counterElement.textContent = `${remaining} caracteres restantes`;
            counterElement.style.color = remaining < 50 ? '#ef4444' : '#94a3b8';
        }
        
        bioTextarea.addEventListener('input', updateCounter);
        updateCounter(); // Initial update
    }
});

// Função para mostrar alertas
function showAlert(message, type = 'info') {
    // Redirecionar para showMessage que tem z-index alto e aparece sobre modais
    if (typeof showMessage === 'function') {
        showMessage(message, type);
    } else {
        alert(message);
    }
}

// Função para formatar números
function formatNumber(num) {
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

// Função para copiar link do perfil
function copyProfileLink() {
    const url = window.location.href;
    navigator.clipboard.writeText(url).then(() => {
        showAlert('Link do perfil copiado!', 'success');
    }).catch(() => {
        showAlert('Erro ao copiar link.', 'error');
    });
}

// Função para compartilhar perfil
function shareProfile() {
    if (navigator.share) {
        navigator.share({
            title: document.title,
            url: window.location.href
        });
    } else {
        copyProfileLink();
    }
}

// Lazy loading para thumbnails de vídeos
document.addEventListener('DOMContentLoaded', function() {
    const thumbnails = document.querySelectorAll('.video-thumbnail img');
    
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src || img.src;
                img.classList.remove('lazy');
                observer.unobserve(img);
            }
        });
    });
    
    thumbnails.forEach(img => imageObserver.observe(img));
});

// Função para deletar vídeo (futura implementação)
function deleteVideo(videoId) {
    if (confirm('Tem certeza que deseja deletar este vídeo? Esta ação não pode ser desfeita.')) {
        fetch(`api/delete_video.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `video_id=${videoId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('Vídeo deletado com sucesso!', 'success');
                // Remover o elemento da página
                const videoElement = document.querySelector(`[data-video-id="${videoId}"]`);
                if (videoElement) {
                    videoElement.remove();
                }
            } else {
                showAlert('Erro ao deletar vídeo.', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('Erro ao deletar vídeo.', 'error');
        });
    }
}

// Smooth scroll para âncoras
document.addEventListener('click', function(e) {
    if (e.target.matches('a[href^="#"]')) {
        e.preventDefault();
        const target = document.querySelector(e.target.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
        }
    }
});

// Funções para exclusão de vídeo
function confirmDeleteVideo(videoId) {
    const modal = document.getElementById('deleteModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Armazenar o ID do vídeo no modal para usar na confirmação
        modal.dataset.videoId = videoId;
    }
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        delete modal.dataset.videoId;
    }
}

// Event listeners para o modal de exclusão
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = document.getElementById('deleteModal');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    // Fechar modal ao clicar fora
    if (deleteModal) {
        deleteModal.addEventListener('click', function(e) {
            if (e.target === deleteModal) {
                closeDeleteModal();
            }
        });
    }
    
    // Confirmar exclusão
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            const videoId = deleteModal?.dataset.videoId;
            if (videoId && window.VideoDeleteManager) {
                // Usar o sistema existente de exclusão se disponível
                window.VideoDeleteManager.currentVideoId = videoId;
                window.VideoDeleteManager.executeDelete();
            } else if (videoId) {
                // Fallback simples
                executeDeleteVideo(videoId);
            }
        });
    }
    
    // Esc para fechar modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && deleteModal?.style.display === 'flex') {
            closeDeleteModal();
        }
    });
});

// Função de fallback para exclusão se o VideoDeleteManager não estiver disponível
async function executeDeleteVideo(videoId) {
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
                video_id: videoId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showMessage('Vídeo apagado com sucesso!', 'success');
            closeDeleteModal();
            
            // Remover vídeo da interface
            const videoElement = document.querySelector(`[data-video-id="${videoId}"]`)?.closest('.video-thumbnail');
            if (videoElement) {
                videoElement.style.transition = 'all 0.3s ease';
                videoElement.style.opacity = '0';
                videoElement.style.transform = 'scale(0.8)';
                setTimeout(() => {
                    videoElement.remove();
                    
                    // Atualizar contador de vídeos se existir
                    const videoCount = document.querySelector('.video-count');
                    if (videoCount) {
                        const currentCount = parseInt(videoCount.textContent.match(/\d+/)[0]);
                        const newCount = Math.max(0, currentCount - 1);
                        videoCount.textContent = `${newCount} vídeo${newCount !== 1 ? 's' : ''}`;
                    }
                    
                    // Mostrar mensagem de vazio se não houver mais vídeos
                    const videosGrid = document.querySelector('.videos-grid');
                    if (videosGrid && videosGrid.children.length === 0) {
                        location.reload(); // Reload para mostrar a mensagem de vazio
                    }
                }, 300);
            }
        } else {
            showMessage(result.message || 'Erro ao apagar vídeo', 'error');
        }
    } catch (error) {
        console.error('Erro:', error);
        showMessage('Erro de conexão. Tente novamente.', 'error');
    } finally {
        // Restaurar botão
        confirmBtn.innerHTML = originalText;
        confirmBtn.disabled = false;
    }
}

// Função para mostrar mensagens (se não existir)
function showMessage(message, type = 'info') {
    // Verificar se a função já existe
    if (typeof window.showMessage === 'function') {
        return window.showMessage(message, type);
    }
    
    const existingMessage = document.querySelector('.toast-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
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
    
    setTimeout(() => {
        if (messageEl.parentNode) {
            messageEl.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => messageEl.remove(), 300);
        }
    }, 3000);
}