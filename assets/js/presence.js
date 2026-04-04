/**
 * MyTube Presence System
 * Mantém o status online do usuário em todas as páginas
 */

(function() {
    'use strict';
    
    const SOCKET_SERVER = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' 
        ? 'http://localhost:3001' 
        : 'https://mytube.social';
    let presenceSocket = null;
    let chatUnreadCount = 0;

    function normalizeUnreadCount(count) {
        const parsed = Number.parseInt(count, 10);
        return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
    }

    function getChatUnreadCount() {
        return chatUnreadCount;
    }

    function updateChatUnreadUI(count) {
        const safeCount = normalizeUnreadCount(count);
        chatUnreadCount = safeCount;
        window.chatUnreadCount = safeCount;

        const chatBadge = document.getElementById('chatUnreadBadge');
        if (chatBadge) {
            if (safeCount > 0) {
                chatBadge.textContent = safeCount > 99 ? '99+' : String(safeCount);
                chatBadge.style.display = 'flex';
            } else {
                chatBadge.style.display = 'none';
            }
        }

        const hasNotificationSystem = typeof window.NotificationSystem !== 'undefined';
        const dot = document.getElementById('notificationDot');
        if (dot && !hasNotificationSystem) {
            dot.style.display = safeCount > 0 ? 'block' : 'none';
        }

        document.dispatchEvent(new CustomEvent('chat-unread-updated', {
            detail: { unreadCount: safeCount }
        }));
    }

    function requestUnreadCount() {
        if (!presenceSocket || !presenceSocket.connected || typeof mytubeUserId === 'undefined' || !mytubeUserId) {
            return;
        }

        presenceSocket.emit('request_unread_messages_count', {
            userId: mytubeUserId
        });
    }

    window.ChatUnreadSystem = window.ChatUnreadSystem || {};
    window.ChatUnreadSystem.getCount = getChatUnreadCount;
    window.ChatUnreadSystem.setCount = updateChatUnreadUI;
    window.ChatUnreadSystem.refresh = requestUnreadCount;

    function isFeedPage() {
        const path = (window.location.pathname || '').toLowerCase();
        const segments = path.split('/').filter(Boolean);
        const lastSegment = segments.length > 0 ? segments[segments.length - 1] : '';

        if (typeof window.feedMode !== 'undefined') {
            return true;
        }

        return lastSegment === '' || lastSegment === 'index.php';
    }

    function shouldShowActiveUsersChip() {
        return Boolean(window.mytubeIsAdmin) && isFeedPage();
    }

    function ensureActiveUsersCounter() {
        if (!shouldShowActiveUsersChip()) {
            document.querySelectorAll('[data-active-users-count="global"]').forEach((chip) => chip.remove());
            return null;
        }

        let chip = document.querySelector('[data-active-users-count]');

        if (chip) {
            return chip;
        }

        chip = document.createElement('div');
        chip.className = 'active-users-chip active-users-floating';
        chip.setAttribute('data-active-users-count', 'global');
        chip.innerHTML = `
            <span class="active-users-dot"></span>
            <span class="active-users-label">0 ativos agora</span>
        `;

        document.body.appendChild(chip);
        return chip;
    }

    function updateActiveUsersCount(count) {
        if (!shouldShowActiveUsersChip()) {
            return;
        }

        ensureActiveUsersCounter();

        const total = Number.isFinite(Number(count)) ? Math.max(0, parseInt(count, 10)) : 0;
        const label = `${total} ativo${total === 1 ? '' : 's'} agora`;

        document.querySelectorAll('[data-active-users-count]').forEach((chip) => {
            const labelNode = chip.querySelector('.active-users-label');
            if (labelNode) {
                labelNode.textContent = label;
            }
        });
    }
    
    // Só inicializa se o usuário estiver logado
    function init() {
        // Verifica se está na página de chat (que já tem seu próprio Socket.IO)
        if (window.location.pathname.includes('chat.php')) {
            return; // Não duplicar conexão na página de chat
        }

        ensureActiveUsersCounter();
        updateChatUnreadUI(window.chatUnreadCount || 0);
        
        // Verifica se há dados do usuário disponíveis
        if (typeof mytubeUserId === 'undefined' || !mytubeUserId) {
            return;
        }
        
        connectPresence();
    }
    
    function connectPresence() {
        // Verificar se Socket.IO está carregado
        if (typeof io === 'undefined') {
            console.warn('Socket.IO não carregado para presença');
            return;
        }
        
        presenceSocket = io(SOCKET_SERVER, {
            transports: ['websocket', 'polling'],
            reconnection: true,
            reconnectionAttempts: 10,
            reconnectionDelay: 2000,
            reconnectionDelayMax: 10000
        });
        
        presenceSocket.on('connect', () => {
            console.log('🟢 Presença conectada');
            
            // Autenticar apenas para presença
            presenceSocket.emit('authenticate', {
                userId: mytubeUserId,
                username: mytubeUsername || 'user'
            });

            if (shouldShowActiveUsersChip()) {
                presenceSocket.emit('request_active_users_count');
            }

            requestUnreadCount();
        });
        
        presenceSocket.on('disconnect', () => {
            console.log('🔴 Presença desconectada');
        });
        
        presenceSocket.on('connect_error', (error) => {
            // Silenciar erros de conexão para não poluir o console
        });
        
        // Eventos de status que podem ser úteis em outras páginas
        presenceSocket.on('user_online', (data) => {
            // Pode ser usado para atualizar indicadores de status em outras páginas
            updatePresenceIndicators(data.userId, true);
        });
        
        presenceSocket.on('user_offline', (data) => {
            updatePresenceIndicators(data.userId, false);
        });

        presenceSocket.on('active_users_count', (data) => {
            updateActiveUsersCount(data && data.count);
        });

        presenceSocket.on('unread_messages_count', (data = {}) => {
            updateChatUnreadUI(data.unreadCount);
        });

        presenceSocket.on('message_notification', () => {
            updateChatUnreadUI(chatUnreadCount + 1);
            try {
                const audio = new Audio('assets/sounds/recive.mp3?v=' + Date.now());
                audio.play().catch(e => console.log('Audio autoplay prevented:', e));
            } catch (e) {}
        });
    }
    
    function updatePresenceIndicators(userId, isOnline) {
        // Atualizar qualquer indicador de presença na página
        const indicators = document.querySelectorAll(`[data-presence-user="${userId}"]`);
        indicators.forEach(indicator => {
            if (isOnline) {
                indicator.classList.add('online');
                indicator.classList.remove('offline');
            } else {
                indicator.classList.remove('online');
                indicator.classList.add('offline');
            }
        });
    }
    
    // Desconectar ao sair da página
    window.addEventListener('beforeunload', () => {
        if (presenceSocket && presenceSocket.connected) {
            presenceSocket.disconnect();
        }
    });
    
    // Reconectar ao voltar para a aba
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' || !presenceSocket) {
            return;
        }

        if (!presenceSocket.connected) {
            presenceSocket.connect();
            return;
        }

        requestUnreadCount();
    });
    
    // Inicializar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
