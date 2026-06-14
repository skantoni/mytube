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
        // dot is now only controlled by notifications.js for general notifications

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
    
    let presenceHeartbeatInterval = null;

    async function fetchPresenceToken() {
        try {
            const res = await fetch('api/chat_token.php');
            if (res.ok) {
                const json = await res.json();
                return json.token || null;
            }
        } catch (e) {
            console.error('Erro ao buscar token para presença:', e);
        }
        return null;
    }

    async function connectPresence() {
        // Verificar se Socket.IO está carregado
        if (typeof io === 'undefined') {
            console.warn('Socket.IO não carregado para presença');
            return;
        }

        const token = await fetchPresenceToken();

        if (!token) {
            // Sem token o middleware do servidor rejeita a ligação — tentar de novo em 5s
            console.warn('Token de presença não disponível, a tentar novamente em 5s...');
            setTimeout(connectPresence, 5000);
            return;
        }

        presenceSocket = io(SOCKET_SERVER, {
            auth: { token },
            transports: ['websocket', 'polling'],
            reconnection: true,
            reconnectionAttempts: 10,
            reconnectionDelay: 2000,
            reconnectionDelayMax: 10000
        });
        
        presenceSocket.on('connect', () => {
            console.log('🟢 Presença conectada');
            
            // Autenticar
            presenceSocket.emit('authenticate', {
                userId: mytubeUserId,
                username: mytubeUsername || 'user'
            });

            if (shouldShowActiveUsersChip()) {
                presenceSocket.emit('request_active_users_count');
            }

            requestUnreadCount();

            // Iniciar heartbeat para manter o last_seen actualizado no servidor
            // (o servidor espera heartbeat a cada 30s de todos os clientes)
            if (presenceHeartbeatInterval) clearInterval(presenceHeartbeatInterval);
            presenceHeartbeatInterval = setInterval(() => {
                if (presenceSocket && presenceSocket.connected) {
                    presenceSocket.emit('heartbeat');
                }
            }, 30000);
        });
        
        presenceSocket.on('disconnect', () => {
            console.log('🔴 Presença desconectada');
            if (presenceHeartbeatInterval) {
                clearInterval(presenceHeartbeatInterval);
                presenceHeartbeatInterval = null;
            }
        });
        
        presenceSocket.on('connect_error', (error) => {
            // Token expirado → buscar novo e reconectar
            if (error && (error.message === 'Token inválido ou expirado' || error.message === 'Token de autenticação necessário')) {
                console.warn('Token de presença expirado, a renovar...');
                if (presenceHeartbeatInterval) {
                    clearInterval(presenceHeartbeatInterval);
                    presenceHeartbeatInterval = null;
                }
                presenceSocket.disconnect();
                presenceSocket = null;
                setTimeout(connectPresence, 1000);
            }
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
            // Pedir contagem real ao servidor em vez de incrementar localmente
            // (evita derivação quando mensagens chegam de conversas escondidas ou de remetentes eliminados)
            requestUnreadCount();
            try {
                const audio = new Audio('assets/sounds/recive.mp3?v=' + Date.now());
                audio.play().catch(e => console.log('Audio autoplay prevented:', e));
            } catch (e) {}
        });

        // Notificação de mensagem de grupo — actualizar badge E tocar som
        presenceSocket.on('group_message_notification', () => {
            requestUnreadCount();
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
        if (presenceHeartbeatInterval) {
            clearInterval(presenceHeartbeatInterval);
            presenceHeartbeatInterval = null;
        }
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
