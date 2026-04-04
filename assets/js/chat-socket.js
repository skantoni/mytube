/**
 * MyTube Chat - Cliente Socket.IO
 * Sistema de chat em tempo real
 */

// ========================================
// CONFIGURAÇÃO E VARIÁVEIS GLOBAIS
// ========================================

//const SOCKET_SERVER = 'http://localhost:3001';
const SOCKET_SERVER = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' 
    ? 'http://localhost:3001' 
    : 'https://mytube.social'; // A apontar para o domínio de produção com proxy ou porta SSL
const DEFAULT_AVATAR = 'assets/images/default-avatar.svg';
let socket = null;
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 5;

// Estado do chat
let currentConversationId = null;
let tempMessageId = 0;
let isTyping = false;
let typingDebounce = null;
let replyToMessageId = null;

// Estado de edição de mensagem
let editingMessageId = null;

// Heartbeat para manter last_seen atualizado (evita status online fantasma)
let heartbeatInterval = null;

// Map para guardar quem está digitando (userId -> true/false)
const usersTyping = new Map();
// Map para timeout de segurança do typing (userId -> timeoutId)
const typingTimeouts = new Map();

// Cache das conversas para filtragem local
let cachedConversations = [];
const onlineUsers = new Set();
let lastContactPresenceSubscriptionKey = null;

// Lazy loading observer para media do chat
let chatMediaObserver = null;

function initChatMediaObserver() {
    if (chatMediaObserver) chatMediaObserver.disconnect();
    
    const chatMessages = document.getElementById('chatMessages');
    if (!chatMessages) return;
    
    chatMediaObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                
                // Lazy load de imagens
                if (el.hasAttribute('data-src')) {
                    const realSrc = el.getAttribute('data-src');
                    el.src = realSrc;
                    el.removeAttribute('data-src');
                    el.addEventListener('load', () => {
                        el.classList.add('loaded');
                        el.closest('.chat-image-container')?.classList.add('loaded');
                    }, { once: true });
                    el.addEventListener('error', () => {
                        el.classList.add('loaded');
                        el.closest('.chat-image-container')?.classList.add('loaded');
                    }, { once: true });
                }
                
                chatMediaObserver.unobserve(el);
            }
        });
    }, {
        root: chatMessages,
        rootMargin: '400px 0px',
        threshold: 0.01
    });
}

// Variáveis vindas do PHP (definidas em chat.php)
// currentUserId, currentUsername, chatWithUserId, chatWithUsername, fromPage

// ========================================
// FUNÇÃO HELPER PARA AVATAR
// ========================================

function getAvatarUrl(avatar) {
    if (!avatar || avatar === 'null' || avatar === 'undefined') {
        return DEFAULT_AVATAR;
    }
    // Se já começa com http ou assets/, retorna como está
    if (avatar.startsWith('http') || avatar.startsWith('assets/')) {
        return avatar;
    }
    // Caso contrário, adiciona o prefixo correto
    return 'assets/images/avatars/' + avatar;
}

function getVerifiedBadgeMarkup(isVerified, className = 'chat-verified-icon') {
    return isVerified ? `<i class="fas fa-check-circle ${className}" aria-label="Verificado"></i>` : '';
}

function normalizeChatUserId(userId) {
    const parsedId = parseInt(userId, 10);
    return Number.isFinite(parsedId) ? parsedId : null;
}

function setUserOnlineState(userId, isOnline) {
    const normalizedUserId = normalizeChatUserId(userId);
    if (normalizedUserId === null) {
        return;
    }

    if (isOnline) {
        onlineUsers.add(normalizedUserId);
    } else {
        onlineUsers.delete(normalizedUserId);
    }
}

function getChatContactIds() {
    const contactIds = new Set();

    cachedConversations.forEach((conversation) => {
        const contactUserId = normalizeChatUserId(conversation.other_user_id);
        if (contactUserId !== null) {
            contactIds.add(contactUserId);
        }
    });

    const selectedUserId = normalizeChatUserId(chatWithUserId);
    if (selectedUserId !== null) {
        contactIds.add(selectedUserId);
    }

    return contactIds;
}

function getChatOnlineUsersCount() {
    const chatContactIds = getChatContactIds();
    let total = 0;

    chatContactIds.forEach((userId) => {
        if (onlineUsers.has(userId)) {
            total += 1;
        }
    });

    return total;
}

function subscribeContactPresence() {
    if (!socket || !socket.connected) {
        return;
    }

    const contactIds = Array.from(getChatContactIds()).sort((a, b) => a - b);
    const subscriptionKey = `${socket.id || 'disconnected'}:${contactIds.join(',')}`;

    if (subscriptionKey === lastContactPresenceSubscriptionKey) {
        return;
    }

    lastContactPresenceSubscriptionKey = subscriptionKey;

    socket.emit('subscribe_contact_presence', {
        userId: currentUserId,
        contactIds
    });
}

function handleContactPresenceSnapshot(data) {
    if (!data || typeof data.statuses !== 'object' || data.statuses === null) {
        return;
    }

    Object.entries(data.statuses).forEach(([userId, isOnline]) => {
        const normalizedUserId = normalizeChatUserId(userId);
        if (normalizedUserId === null) {
            return;
        }

        const isContactOnline = Boolean(isOnline);
        setUserOnlineState(normalizedUserId, isContactOnline);
        updateOnlineIndicator(normalizedUserId, isContactOnline);
    });

    updateActiveUsersCountUI();
}

function handleContactPresenceUpdate(data) {
    if (!data) {
        return;
    }

    const normalizedUserId = normalizeChatUserId(data.userId);
    if (normalizedUserId === null) {
        return;
    }

    const isContactOnline = Boolean(data.isOnline);
    setUserOnlineState(normalizedUserId, isContactOnline);
    updateOnlineIndicator(normalizedUserId, isContactOnline);
    updateActiveUsersCountUI();
}

function ensureChatActiveUsersCounter() {
    let chip = document.querySelector('[data-active-users-count]');

    if (chip) {
        return chip;
    }

    const chatSidebar = document.querySelector('.chat-sidebar');
    if (!chatSidebar) {
        return null;
    }

    const presenceBar = document.createElement('div');
    presenceBar.className = 'chat-sidebar-presence';
    presenceBar.innerHTML = `
        <div class="active-users-chip" data-active-users-count="chat">
            <span class="active-users-dot"></span>
            <span class="active-users-label">0 ativos no chat</span>
        </div>
    `;

    const searchBox = chatSidebar.querySelector('.chat-search');
    if (searchBox) {
        chatSidebar.insertBefore(presenceBar, searchBox);
    } else {
        chatSidebar.prepend(presenceBar);
    }

    return presenceBar.querySelector('[data-active-users-count]');
}

function updateActiveUsersCountUI() {
    const chip = ensureChatActiveUsersCounter();
    if (!chip) {
        return;
    }

    const labelNode = chip.querySelector('.active-users-label');
    if (!labelNode) {
        return;
    }

    const total = getChatOnlineUsersCount();
    labelNode.textContent = `${total} ativo${total === 1 ? '' : 's'} no chat`;
}

// ========================================
// INICIALIZAÇÃO
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    ensureChatActiveUsersCounter();
    updateActiveUsersCountUI();
    initializeSocket();
    setupEventListeners();
    
    // Setup scroll infinito
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.addEventListener('scroll', function() {
            if (this.scrollTop === 0 && currentConversationId) {
                loadMoreMessages();
            }
        });
    }
    
    // Pedir permissão para notificações
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
    
    // No mobile, esconder sidebar quando há conversa aberta
    if (chatWithUserId) {
        hideSidebarOnMobile();
    }
});

function initializeSocket() {
    // Conectar ao servidor Socket.IO
    socket = io(SOCKET_SERVER, {
        transports: ['websocket', 'polling'],
        reconnection: true,
        reconnectionAttempts: MAX_RECONNECT_ATTEMPTS,
        reconnectionDelay: 1000,
        reconnectionDelayMax: 5000
    });
    
    // Eventos de conexão
    socket.on('connect', handleConnect);
    socket.on('disconnect', handleDisconnect);
    socket.on('connect_error', handleConnectError);
    
    // Eventos de chat
    socket.on('conversations_list', handleConversationsList);
    socket.on('messages_list', handleMessagesList);
    socket.on('new_message', handleNewMessage);
    socket.on('message_sent', handleMessageSent);
    socket.on('message_status_update', handleMessageStatusUpdate);
    socket.on('message_deleted', handleMessageDeleted);
    socket.on('message_edited', handleMessageEdited);
    socket.on('messages_read', handleMessagesRead);
    socket.on('typing_status', handleTypingStatus);
    socket.on('more_messages', handleMoreMessages);
    socket.on('reaction_updated', handleReactionUpdated);
    
    console.log('✅ Eventos Socket.IO registrados, incluindo message_status_update');
    
    // Eventos de usuário e status online
    socket.on('user_online', handleUserOnline);
    socket.on('user_offline', handleUserOffline);
    socket.on('user_status', handleUserStatus);
    socket.on('online_statuses', handleOnlineStatuses);
    socket.on('search_results', handleSearchResults);
    socket.on('conversation_started', handleConversationStarted);
    socket.on('message_notification', handleMessageNotification);
    socket.on('active_users_count', handleActiveUsersCount);
    socket.on('contact_presence_snapshot', handleContactPresenceSnapshot);
    socket.on('contact_presence_update', handleContactPresenceUpdate);
    
    // Erros
    socket.on('error', handleError);
}

function setupEventListeners() {
    // Marcar como offline ao sair da página
    window.addEventListener('beforeunload', () => {
        if (socket && socket.connected) {
            socket.disconnect();
        }
    });
    
    // Reconectar ao focar na janela (se desconectado)
    window.addEventListener('focus', () => {
        if (socket && !socket.connected) {
            socket.connect();
        }
    });
    
    // Tratar navegação pelo histórico (botão voltar/avançar)
    window.addEventListener('popstate', (event) => {
        if (event.state && event.state.userId) {
            // Abrir conversa sem adicionar ao histórico novamente
            openChatFromHistory(event.state.userId);
        } else {
            // Voltar para lista de conversas
            showConversationsList();
        }
    });
    
    // Setup do input de mensagem
    setupMessageInput();
    
    // Setup cliques nas conversas (event delegation)
    setupConversationClickListener();
    
    // Setup pesquisa de conversas
    setupConversationSearch();
}

function setupConversationClickListener() {
    const conversationsList = document.getElementById('conversationsList');
    if (conversationsList) {
        conversationsList.addEventListener('click', function(e) {
            const conversationItem = e.target.closest('.conversation-item');
            if (conversationItem && conversationItem.dataset.userId) {
                const userId = parseInt(conversationItem.dataset.userId);
                console.log('🖱️ Clique na conversa:', userId);
                openChat(userId);
            }
        });
    }
}

function setupConversationSearch() {
    const searchInput = document.getElementById('searchConversations');
    if (!searchInput || searchInput.dataset.listenersAdded) return;
    searchInput.dataset.listenersAdded = 'true';
    
    searchInput.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        filterConversations(query);
    });
}

function filterConversations(query) {
    if (!cachedConversations || cachedConversations.length === 0) return;
    
    const conversationsList = document.querySelector('.conversations-list');
    if (!conversationsList) return;
    
    if (!query) {
        // Sem filtro — mostrar todas
        renderConversationsList(cachedConversations);
        refreshSidebarTypingIndicators();
        return;
    }
    
    // Filtrar pelo nome do utilizador ou pela última mensagem
    const filtered = cachedConversations.filter(conv => 
        conv.username.toLowerCase().includes(query) ||
        (conv.last_message && conv.last_message.toLowerCase().includes(query))
    );
    
    if (filtered.length === 0) {
        conversationsList.innerHTML = `
            <div class="no-conversations">
                <i class="fas fa-search"></i>
                <p>Nenhuma conversa encontrada</p>
            </div>
        `;
        return;
    }
    
    renderConversationsList(filtered);
    refreshSidebarTypingIndicators();
}

function setupMessageInput() {
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    
    if (messageInput && !messageInput.dataset.listenersAdded) {
        // Marcar que os listeners foram adicionados
        messageInput.dataset.listenersAdded = 'true';
        
        // Evento de teclado (Enter para enviar)
        messageInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        });
        
        // Evento de input (typing e resize)
        messageInput.addEventListener('input', function() {
            handleTyping();
            autoResizeTextarea(this);
        });
        
        console.log('✅ Event listeners configurados no messageInput');
    }
    
    // Evento de click no botão enviar
    if (sendBtn && !sendBtn.dataset.listenersAdded) {
        sendBtn.dataset.listenersAdded = 'true';
        sendBtn.addEventListener('click', function() {
            sendMessage();
        });
        console.log('✅ Event listener configurado no sendBtn');
    }
}

function openChatFromHistory(userId) {
    chatWithUserId = userId;
    updateActiveUsersCountUI();
    subscribeContactPresence();
    
    const conversationItem = document.querySelector(`.conversation-item[data-user-id="${userId}"]`);
    const username = conversationItem ? conversationItem.dataset.username : 'Usuário';
    const avatar = conversationItem ? (conversationItem.dataset.avatar || conversationItem.querySelector('img')?.src) : DEFAULT_AVATAR;
    const isVerified = conversationItem ? conversationItem.dataset.isVerified === '1' : false;
    chatWithUsername = username;
    chatWithIsVerified = isVerified;
    
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('active');
    });
    if (conversationItem) {
        conversationItem.classList.add('active');
    }
    
    showChatArea();
    updateChatHeader(userId, username, avatar, isVerified);
    startConversation(userId);
    hideSidebarOnMobile();
}

function showConversationsList() {
    // Parar todos os vídeos e áudios em reprodução
    stopAllChatMedia();
    
    chatWithUserId = null;
    currentConversationId = null;
    updateActiveUsersCountUI();
    subscribeContactPresence();
    
    // Remover seleção ativa
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('active');
    });
    
    const chatMain = document.getElementById('chatMain');
    const sidebar = document.querySelector('.chat-sidebar');
    
    // Em mobile, esconder chat e mostrar sidebar
    if (window.innerWidth <= 768) {
        if (chatMain) {
            chatMain.classList.remove('active');
            chatMain.style.display = 'none';
        }
        if (sidebar) {
            sidebar.classList.remove('hidden');
            sidebar.style.display = 'flex';
        }
    } else {
        // Em desktop, mostrar mensagem "Selecione uma conversa"
        const noConversation = document.querySelector('.no-chat-selected');
        if (noConversation) {
            noConversation.style.display = 'flex';
        }
    }
}

// ========================================
// HANDLERS DE CONEXÃO
// ========================================

function handleConnect() {
    console.log('✅ Conectado ao servidor de chat');
    reconnectAttempts = 0;
    lastContactPresenceSubscriptionKey = null;
    
    // Autenticar usuário
    socket.emit('authenticate', {
        userId: currentUserId,
        username: currentUsername
    });

    updateActiveUsersCountUI();
    subscribeContactPresence();
    
    // Se há um chat aberto, entrar na conversa
    if (chatWithUserId) {
        startConversation(chatWithUserId);
    }
    
    // Atualizar indicador de conexão
    updateConnectionStatus(true);
    
    // Iniciar heartbeat (atualiza last_seen no servidor a cada 30s)
    if (heartbeatInterval) clearInterval(heartbeatInterval);
    heartbeatInterval = setInterval(() => {
        if (socket && socket.connected) {
            socket.emit('heartbeat');
        }
    }, 30000);
}

function handleDisconnect(reason) {
    console.log('❌ Desconectado do servidor de chat:', reason);
    lastContactPresenceSubscriptionKey = null;
    updateConnectionStatus(false);
    updateActiveUsersCountUI();
    
    // Parar heartbeat ao desconectar
    if (heartbeatInterval) {
        clearInterval(heartbeatInterval);
        heartbeatInterval = null;
    }
    
    // Limpar todos os indicadores de typing ao desconectar
    clearAllTypingIndicators();
    
    if (reason === 'io server disconnect') {
        // Servidor forçou desconexão, tentar reconectar
        socket.connect();
    }
}

function handleConnectError(error) {
    console.error('Erro de conexão:', error);
    reconnectAttempts++;
    
    if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
        showConnectionError();
    }
}

function updateConnectionStatus(connected) {
    const statusIndicator = document.getElementById('connectionStatus');
    if (statusIndicator) {
        statusIndicator.className = connected ? 'connected' : 'disconnected';
        statusIndicator.title = connected ? 'Conectado' : 'Desconectado - Tentando reconectar...';
    }
}

function showConnectionError() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'connection-error';
        errorDiv.innerHTML = `
            <i class="fas fa-exclamation-triangle"></i>
            <span>Sem conexão com o servidor. <button onclick="retryConnection()">Tentar novamente</button></span>
        `;
        chatMessages.prepend(errorDiv);
    }
}

function handleActiveUsersCount(data) {
    updateActiveUsersCountUI();
}

function retryConnection() {
    reconnectAttempts = 0;
    socket.connect();
    
    // Remover mensagem de erro
    const errorDiv = document.querySelector('.connection-error');
    if (errorDiv) errorDiv.remove();
}

// ========================================
// HANDLERS DE CONVERSAS
// ========================================

function handleConversationsList(conversations) {
    const conversationsList = document.querySelector('.conversations-list');
    if (!conversationsList) return;
    
    // Remover loading
    const loading = conversationsList.querySelector('.conversations-loading');
    if (loading) loading.remove();
    
    // Guardar em cache para filtragem local
    cachedConversations = conversations;

    conversations.forEach((conversation) => {
        setUserOnlineState(conversation.other_user_id, Boolean(conversation.is_online));
    });
    updateActiveUsersCountUI();
    subscribeContactPresence();
    
    if (conversations.length === 0) {
        conversationsList.innerHTML = `
            <div class="no-conversations">
                <i class="fas fa-comments"></i>
                <p>Nenhuma conversa ainda</p>
                <button onclick="showNewChatModal()">Iniciar uma conversa</button>
            </div>
        `;
        return;
    }
    
    // Aplicar filtro de pesquisa se houver texto no campo
    const searchInput = document.getElementById('searchConversations');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    
    if (query) {
        const filtered = conversations.filter(conv => 
            conv.username.toLowerCase().includes(query) ||
            (conv.last_message && conv.last_message.toLowerCase().includes(query))
        );
        renderConversationsList(filtered);
    } else {
        renderConversationsList(conversations);
    }
    refreshSidebarTypingIndicators();
}

function renderConversationsList(conversations) {
    const conversationsList = document.querySelector('.conversations-list');
    if (!conversationsList) return;
    
    conversationsList.innerHTML = conversations.map(conv => {
        const isActive = chatWithUserId == conv.other_user_id;
        const isOnline = conv.is_online ? 'online' : '';
        const verifiedBadge = getVerifiedBadgeMarkup(conv.is_verified);
        // If this conversation is currently active/open, suppress the unread badge
        // (messages are being read in real time)
        const unreadBadge = (!isActive && conv.unread_count > 0) ? 
            `<span class="unread-badge">${conv.unread_count > 99 ? '99+' : conv.unread_count}</span>` : '';
        
        let lastMessagePreview = conv.last_message || 'Nenhuma mensagem';
        if (lastMessagePreview.length > 30) {
            lastMessagePreview = lastMessagePreview.substring(0, 30) + '...';
        }
        
        const lastTime = conv.last_message_time ? formatMessageTime(conv.last_message_time) : '';
        
        return `
            <div class="conversation-item ${isActive ? 'active' : ''}" 
                 data-user-id="${conv.other_user_id}"
                 data-conversation-id="${conv.conversation_id}"
                 data-username="${escapeHtml(conv.username)}"
                 data-avatar="${escapeHtml(getAvatarUrl(conv.avatar))}"
                 data-is-verified="${conv.is_verified ? '1' : '0'}">
                <div class="conversation-avatar">
                    <img src="${getAvatarUrl(conv.avatar)}" 
                         onerror="this.src='${DEFAULT_AVATAR}'" 
                         alt="${escapeHtml(conv.username)}">
                    <span class="online-status ${isOnline}" data-user-id="${conv.other_user_id}"></span>
                </div>
                <div class="conversation-info">
                    <div class="conversation-header">
                        <h4><span>${escapeHtml(conv.username)}</span>${verifiedBadge}</h4>
                        <span class="conversation-time">${lastTime}</span>
                    </div>
                    <div class="conversation-preview">
                        <p>${escapeHtml(lastMessagePreview)}</p>
                        ${unreadBadge}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function handleConversationStarted(data) {
    currentConversationId = data.conversationId;

    if (data.otherUser) {
        chatWithUsername = data.otherUser.username || chatWithUsername;
        chatWithIsVerified = Boolean(data.otherUser.is_verified);
        updateChatHeader(
            chatWithUserId,
            chatWithUsername || 'Usuário',
            getAvatarUrl(data.otherUser.avatar),
            chatWithIsVerified
        );
    }
    
    // Entrar na sala da conversa
    socket.emit('join_conversation', {
        conversationId: data.conversationId,
        userId: currentUserId,
        otherUserId: chatWithUserId
    });
}

// ========================================
// HANDLERS DE MENSAGENS
// ========================================

function handleMessagesList(data) {
    currentConversationId = data.conversationId;
    displayMessages(data.messages);
}

function handleNewMessage(data) {
    if (data.conversationId !== currentConversationId) {
        // Mensagem de outra conversa - mostrar notificação
        return;
    }
    
    // Ignorar se for mensagem própria (já foi adicionada otimisticamente)
    if (data.message.sender_id === currentUserId) {
        console.log('📨 Ignorando new_message própria (já adicionada otimisticamente)');
        return;
    }
    
    console.log('📨 Nova mensagem recebida de outro usuário');
    appendMessage(data.message);
    scrollToBottom();
    
    // Marcar mensagem como lida imediatamente (estamos com a conversa aberta)
    if (socket && currentConversationId) {
        socket.emit('mark_as_read', {
            conversationId: currentConversationId,
            userId: currentUserId,
            messageIds: [data.message.id]
        });
        console.log('✔✔ Enviado mark_as_read para mensagem', data.message.id);
    }
    
    // Reproduzir som
    playNotificationSound();
}

function handleMessageSent(data) {
    console.log('✉️ Confirmação de envio recebida:', data);
    
    // Atualizar mensagem temporária com ID real
    const tempMessage = document.querySelector(`[data-temp-id="${data.tempId}"]`);
    if (tempMessage) {
        tempMessage.setAttribute('data-message-id', data.messageId);
        tempMessage.removeAttribute('data-temp-id');
        
        console.log(`✅ Mensagem temporária ${data.tempId} → ID real: ${data.messageId}`);
        
        // Atualizar status baseado no retorno do servidor
        const statusElement = tempMessage.querySelector('.message-status');
        if (statusElement) {
            updateMessageStatusIcon(statusElement, data.status || 'sent');
        }
    } else {
        console.warn(`⚠️ Mensagem temporária ${data.tempId} não encontrada`);
    }
}

function handleMessageStatusUpdate(data) {
    console.log('📩 Status update recebido:', data);
    
    // Atualizar status de mensagem específica (delivered/read)
    const messageElement = document.querySelector(`[data-message-id="${data.messageId}"]`);
    if (messageElement) {
        const statusElement = messageElement.querySelector('.message-status');
        if (statusElement) {
            console.log(`✅ Atualizando status da mensagem ${data.messageId} para ${data.status}`);
            updateMessageStatusIcon(statusElement, data.status);
        }
    }
    // Não mostrar warning se mensagem não está no DOM (normal para mensagens antigas)
}

function updateMessageStatusIcon(statusElement, status) {
    // Limpar classes anteriores
    statusElement.className = 'message-status';
    
    if (status === 'sent') {
        // ✔ Enviado (cinza)
        statusElement.innerHTML = '<i class="fas fa-check"></i>';
        statusElement.classList.add('sent');
        console.log('🔵 Status: Enviado (1 check cinza)');
    } else if (status === 'delivered') {
        // ✔✔ Entregue (cinza)
        statusElement.innerHTML = '<i class="fas fa-check-double"></i>';
        statusElement.classList.add('delivered');
        console.log('🔵 Status: Entregue (2 checks cinza)');
    } else if (status === 'read') {
        // ✔✔ Lido (azul)
        statusElement.innerHTML = '<i class="fas fa-check-double"></i>';
        statusElement.classList.add('read');
        console.log('💙 Status: Lido (2 checks azul)');
    }
}

function handleMessageDeleted(data) {
    const messageElement = document.querySelector(`[data-message-id="${data.messageId}"]`);
    if (messageElement) {
        const messageBubble = messageElement.querySelector('.message-bubble');
        if (messageBubble) {
            messageBubble.innerHTML = `
                <div class="message-text">
                    <span class="message-deleted"><i class="fas fa-ban"></i> Mensagem apagada</span>
                </div>
                <div class="message-meta">
                    <span class="message-time">${messageElement.querySelector('.message-time')?.textContent || ''}</span>
                </div>
            `;
        }
        
        // Remover opções da mensagem
        const options = messageElement.querySelector('.message-options');
        if (options) options.remove();
        
        // Remover botão de mais opções
        const moreBtn = messageElement.querySelector('.msg-more-btn');
        if (moreBtn) moreBtn.remove();
    }
}

function handleReactionUpdated(data) {
    const { messageId, reactions } = data;
    
    // Encontrar o container de reações da mensagem
    const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageElement) return;
    
    const reactionsContainer = messageElement.querySelector('.message-reactions');
    if (reactionsContainer) {
        // Reconstruir as reações
        let html = '';
        for (const [emoji, users] of Object.entries(reactions)) {
            const count = users.length;
            if (count > 0) {
                html += `<span class="reaction" onclick="toggleReaction(${messageId}, '${emoji}')">${emoji}${count > 1 ? ' ' + count : ''}</span>`;
            }
        }
        reactionsContainer.innerHTML = html;
    }
}

function handleMessagesRead(data) {
    if (data.conversationId !== currentConversationId) return;
    
    // Atualizar todos os status para "lido"
    document.querySelectorAll('.message.sent .message-status').forEach(status => {
        status.innerHTML = '<i class="fas fa-check-double"></i>';
        status.className = 'message-status read';
    });
}

function handleMoreMessages(data) {
    if (data.conversationId !== currentConversationId) return;
    
    const chatMessages = document.getElementById('chatMessages');
    const scrollHeight = chatMessages.scrollHeight;
    
    // Adicionar mensagens no início
    data.messages.forEach(msg => {
        const messageElement = createMessageElement(msg);
        chatMessages.insertBefore(messageElement, chatMessages.firstChild);
    });
    
    // Manter posição do scroll
    chatMessages.scrollTop = chatMessages.scrollHeight - scrollHeight;
}

// ========================================
// HANDLERS DE STATUS
// ========================================

function handleTypingStatus(data) {
    // Normalizar userId para número
    const oderId = parseInt(data.userId);
    console.log('⌨️ Typing status recebido:', data, '-> userId normalizado:', oderId);
    
    // Limpar timeout de segurança anterior para este utilizador
    if (typingTimeouts.has(oderId)) {
        clearTimeout(typingTimeouts.get(oderId));
        typingTimeouts.delete(oderId);
    }
    
    // Guardar estado de digitação no Map
    if (data.isTyping) {
        usersTyping.set(oderId, true);
        console.log('⌨️ Adicionado ao Map usersTyping:', oderId, '- Total:', usersTyping.size);
        
        // Timeout de segurança: limpar após 5s se não receber novo typing
        const safetyTimeout = setTimeout(() => {
            usersTyping.delete(oderId);
            typingTimeouts.delete(oderId);
            updateSidebarTypingIndicator(oderId, false);
            updateHeaderTypingIndicator(oderId, false);
            console.log('⌨️ Typing limpo por timeout de segurança para:', oderId);
        }, 5000);
        typingTimeouts.set(oderId, safetyTimeout);
    } else {
        usersTyping.delete(oderId);
        console.log('⌨️ Removido do Map usersTyping:', oderId, '- Total:', usersTyping.size);
    }
    
    // Atualizar indicador na sidebar (lista de conversas)
    updateSidebarTypingIndicator(oderId, data.isTyping);
    
    // Atualizar indicador no header do chat (se for o usuário atual)
    updateHeaderTypingIndicator(oderId, data.isTyping);
}

function clearAllTypingIndicators() {
    // Limpar todos os timeouts de segurança
    for (const [userId, timeoutId] of typingTimeouts) {
        clearTimeout(timeoutId);
        updateSidebarTypingIndicator(userId, false);
        updateHeaderTypingIndicator(userId, false);
    }
    typingTimeouts.clear();
    usersTyping.clear();
    
    // Esconder indicador no header
    const typingIndicator = document.getElementById('typingIndicator');
    const userStatus = document.getElementById('userStatus');
    if (typingIndicator) typingIndicator.style.display = 'none';
    if (userStatus) userStatus.style.display = 'flex';
    
    console.log('⌨️ Todos os indicadores de typing limpos');
}

function updateHeaderTypingIndicator(userId, isTyping) {
    // Só atualiza se for o usuário da conversa atual
    if (userId != chatWithUserId) return;
    
    const typingIndicator = document.getElementById('typingIndicator');
    const userStatus = document.getElementById('userStatus');
    
    if (!typingIndicator) return;
    
    console.log('⌨️ Atualizando header typing, isTyping:', isTyping);
    
    if (isTyping) {
        typingIndicator.style.display = 'flex';
        if (userStatus) userStatus.style.display = 'none';
    } else {
        typingIndicator.style.display = 'none';
        if (userStatus) userStatus.style.display = 'flex';
    }
}

// Armazenar mensagens originais da prévia para restaurar depois
const originalPreviews = new Map();

function updateSidebarTypingIndicator(userId, isTyping) {
    // Normalizar userId para string para o seletor
    userId = String(userId);
    
    // Encontrar a conversa na sidebar pelo userId
    const conversationItem = document.querySelector(`.conversation-item[data-user-id="${userId}"]`);
    if (!conversationItem) {
        console.log('⌨️ Sidebar: conversation-item não encontrado para userId:', userId);
        return;
    }
    
    const previewText = conversationItem.querySelector('.conversation-preview p');
    if (!previewText) {
        console.log('⌨️ Sidebar: preview text não encontrado');
        return;
    }
    
    console.log('⌨️ Sidebar: atualizando indicador para userId:', userId, 'isTyping:', isTyping);
    
    // Usar número como chave no Map de originalPreviews
    const userIdNum = parseInt(userId);
    
    if (isTyping) {
        // Salvar o texto original se ainda não foi salvo
        if (!originalPreviews.has(userIdNum)) {
            originalPreviews.set(userIdNum, previewText.textContent);
        }
        // Mostrar indicador de digitando com animação
        previewText.innerHTML = '<span class="typing-dots"><i class="fas fa-circle"></i><i class="fas fa-circle"></i><i class="fas fa-circle"></i></span> digitando...';
        previewText.classList.add('typing-preview');
    } else {
        // Restaurar texto original
        const originalText = originalPreviews.get(userIdNum);
        if (originalText) {
            previewText.textContent = originalText;
            originalPreviews.delete(userIdNum);
        }
        previewText.classList.remove('typing-preview');
    }
}

// Restaurar todos os indicadores de typing na sidebar baseado no Map
function refreshSidebarTypingIndicators() {
    console.log('🔄 Refreshing sidebar typing indicators, usersTyping:', [...usersTyping.keys()]);
    
    // Para cada usuário que está digitando, atualizar a sidebar
    usersTyping.forEach((value, oderId) => {
        updateSidebarTypingIndicator(oderId, true);
    });
}

function handleUserOnline(data) {
    console.log('🟢 Usuário ficou online:', data.userId, data.username);
    setUserOnlineState(data.userId, true);
    updateActiveUsersCountUI();
    updateOnlineIndicator(data.userId, true);
    
    if (data.userId == chatWithUserId) {
        const statusText = document.querySelector('.status-text');
        if (statusText) {
            statusText.textContent = 'Online';
        }
    }
}

function handleUserOffline(data) {
    console.log('🔴 Usuário ficou offline:', data.userId, data.username);
    setUserOnlineState(data.userId, false);
    updateActiveUsersCountUI();
    updateOnlineIndicator(data.userId, false);
    
    if (data.userId == chatWithUserId) {
        const statusText = document.querySelector('.status-text');
        if (statusText) {
            statusText.textContent = formatLastSeen(data.lastSeen);
        }
    }
}

function handleUserStatus(data) {
    // Status online de um usuário específico (quando entra na conversa)
    console.log('📡 Status do usuário:', data.userId, data.isOnline ? 'online' : 'offline');
    setUserOnlineState(data.userId, Boolean(data.isOnline));
    updateActiveUsersCountUI();
    updateOnlineIndicator(data.userId, data.isOnline);
    
    if (data.userId == chatWithUserId) {
        const statusText = document.querySelector('.status-text');
        const statusIndicator = document.querySelector('.chat-header .status-indicator');
        
        if (statusText) {
            statusText.textContent = data.isOnline ? 'Online' : 'Offline';
        }
        if (statusIndicator) {
            if (data.isOnline) {
                statusIndicator.classList.add('online');
            } else {
                statusIndicator.classList.remove('online');
            }
        }
    }
}

function handleOnlineStatuses(statuses) {
    // Status online de múltiplos usuários (ao carregar lista de conversas)
    console.log('📋 Statuses recebidos:', statuses);
    for (const [userId, isOnline] of Object.entries(statuses)) {
        setUserOnlineState(userId, Boolean(isOnline));
        updateOnlineIndicator(parseInt(userId), isOnline);
    }
    updateActiveUsersCountUI();
}

function updateOnlineIndicator(userId, isOnline) {
    // Garantir que userId é string para comparação com data-user-id
    userId = String(userId);
    console.log('🔄 Atualizando indicador:', userId, isOnline);
    
    // Atualizar indicadores na sidebar (online-status é a bolinha na conversa)
    const sidebarIndicators = document.querySelectorAll(`.online-status[data-user-id="${userId}"]`);
    console.log(`  - Encontrados ${sidebarIndicators.length} indicadores de sidebar`);
    sidebarIndicators.forEach(indicator => {
        console.log('  - Sidebar indicator encontrado, adicionando classe online:', isOnline);
        if (isOnline) {
            indicator.classList.add('online');
        } else {
            indicator.classList.remove('online');
        }
    });
    
    // Atualizar indicadores do header (status-indicator)
    const headerIndicators = document.querySelectorAll(`.status-indicator[data-user-id="${userId}"]`);
    console.log(`  - Encontrados ${headerIndicators.length} indicadores de header`);
    headerIndicators.forEach(indicator => {
        console.log('  - Header indicator encontrado, adicionando classe online:', isOnline);
        if (isOnline) {
            indicator.classList.add('online');
        } else {
            indicator.classList.remove('online');
        }
    });
    
    // Atualizar também o status no header do chat se for o usuário atual
    if (userId == chatWithUserId) {
        const headerIndicator = document.querySelector('.chat-header .status-indicator');
        const statusText = document.querySelector('.status-text');
        
        if (headerIndicator) {
            if (isOnline) {
                headerIndicator.classList.add('online');
            } else {
                headerIndicator.classList.remove('online');
            }
        }
        if (statusText) {
            statusText.textContent = isOnline ? 'Online' : 'Offline';
        }
    }
}

// ========================================
// HANDLERS DE BUSCA
// ========================================

function handleSearchResults(users) {
    displaySearchResults(users);
}

function handleMessageNotification(data) {
    // Extrair dados (suporta formato direto e formato com message object)
    const senderUsername = data.senderUsername || (data.message && data.message.sender_username) || 'Alguém';
    const senderAvatar = data.senderAvatar || (data.message && (data.message.sender_avatar || data.message.sender_picture)) || '';
    const senderId = data.senderId || (data.message && data.message.sender_id);
    const content = data.content || (data.message && data.message.message) || 'Nova mensagem';
    
    // Mostrar notificação de nova mensagem
    if (Notification.permission === 'granted' && document.hidden) {
        new Notification(`Nova mensagem de ${senderUsername}`, {
            body: content,
            icon: getAvatarUrl(senderAvatar)
        });
    }
    
    // Atualizar badge na lista de conversas
    const convItem = document.querySelector(`[data-user-id="${senderId}"]`);
    if (convItem) {
        let badge = convItem.querySelector('.unread-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'unread-badge';
            convItem.querySelector('.conversation-preview').appendChild(badge);
        }
        const currentCount = parseInt(badge.textContent) || 0;
        badge.textContent = currentCount + 1 > 99 ? '99+' : currentCount + 1;
    }
    
    // Tocar som se não for a conversa atual
    if (senderId != chatWithUserId) {
        playNotificationSound();
    }
}

// ========================================
// HANDLERS DE ERRO
// ========================================

function handleError(data) {
    console.error('Erro do servidor:', data.message);
    showToast(data.message, 'error');
}

// ========================================
// AÇÕES DO USUÁRIO
// ========================================

function startConversation(userId) {
    // Parar todos os vídeos e áudios antes de trocar de conversa
    stopAllChatMedia();
    
    socket.emit('start_conversation', {
        userId: currentUserId,
        targetUserId: userId
    });
}

function getReplyPreviewData(replyMessageId) {
    let replyContent = null;
    let replyUsername = null;

    if (replyMessageId) {
        const replyEl = document.querySelector(`[data-message-id="${replyMessageId}"]`);
        if (replyEl) {
            replyContent = replyEl.querySelector('.message-text')?.textContent || '';
            const isSentReply = replyEl.classList.contains('sent');
            replyUsername = isSentReply ? currentUsername : chatWithUsername;
        }
    }

    return { replyContent, replyUsername };
}

function stopTypingStatus() {
    if (!isTyping || !currentConversationId) {
        return;
    }

    isTyping = false;
    socket.emit('typing', {
        userId: currentUserId,
        conversationId: currentConversationId,
        otherUserId: chatWithUserId,
        isTyping: false
    });
}

function sendTextMessage(rawMessage, options = {}) {
    const {
        clearInput = false,
        allowEdit = true,
        replyToId = replyToMessageId,
        silent = false
    } = options;

    const message = typeof rawMessage === 'string' ? rawMessage.trim() : '';

    if (!message || !chatWithUserId) {
        if (!silent) {
            console.warn('⚠️ Mensagem não enviada:', { message, chatWithUserId });
        }
        return false;
    }

    if (emojiPickerOpen) closeEmojiPicker();

    if (allowEdit && editingMessageId) {
        socket.emit('edit_message', {
            messageId: editingMessageId,
            userId: currentUserId,
            content: message
        });

        cancelEdit();
        return true;
    }

    console.log('📤 ENVIANDO mensagem:', message.substring(0, 50));

    const tempId = ++tempMessageId;
    const { replyContent, replyUsername } = getReplyPreviewData(replyToId);
    
    const tempMessage = {
        id: null,
        tempId: tempId,
        sender_id: currentUserId,
        sender_username: currentUsername,
        content: message,
        created_at: new Date().toISOString(),
        reply_to_id: replyToId,
        reply_content: replyContent,
        reply_username: replyUsername,
        status: 'sending'
    };
    
    appendMessage(tempMessage, true);
    
    console.log('🚀 Socket.emit send_message com tempId:', tempId);
    
    // Enviar pelo socket
    socket.emit('send_message', {
        tempId: tempId,
        senderId: currentUserId,
        receiverId: chatWithUserId,
        content: message,
        replyToId: replyToId
    });

    if (clearInput) {
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.value = '';
            autoResizeTextarea(messageInput);
        }

        cancelReply();
        toggleSendButton();
        stopTypingStatus();
    }

    return true;
}

function sendMessage() {
    const messageInput = document.getElementById('messageInput');
    if (!messageInput) return;

    sendTextMessage(messageInput.value, {
        clearInput: true,
        allowEdit: true,
        replyToId: replyToMessageId
    });
}

function deleteMessage(messageId, deleteType = 'for_all') {
    const confirmMsg = deleteType === 'for_me' 
        ? 'Apagar esta mensagem para si?' 
        : 'Apagar esta mensagem para todos?';
    
    if (!confirm(confirmMsg)) return;
    
    if (deleteType === 'for_me') {
        // Apagar para mim via API PHP
        fetch('api/delete_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `message_id=${messageId}&delete_type=for_me`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Remover do DOM localmente
                const msgEl = document.querySelector(`[data-message-id="${messageId}"]`);
                if (msgEl) {
                    msgEl.style.transition = 'opacity 0.3s, transform 0.3s';
                    msgEl.style.opacity = '0';
                    msgEl.style.transform = 'scale(0.8)';
                    setTimeout(() => msgEl.remove(), 300);
                }
                showToast('Mensagem apagada para si');
            } else {
                showToast(data.error || 'Erro ao apagar mensagem');
            }
        })
        .catch(() => showToast('Erro ao apagar mensagem'));
    } else {
        // Apagar para todos via Socket
        socket.emit('delete_message', {
            messageId: messageId,
            userId: currentUserId,
            deleteType: 'for_all'
        });
    }
}

// ========================================
// EDITAR MENSAGEM
// ========================================

function startEditMessage(messageId) {
    closeMessageOptions();
    closeLongPressMenu();
    
    const msgEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!msgEl) return;
    
    const messageText = msgEl.querySelector('.message-text')?.textContent?.trim() || '';
    
    // Verificar se é áudio ou arquivo (não permite editar)
    if (msgEl.querySelector('.audio-message')) {
        showToast('Não é possível editar mensagens de áudio');
        return;
    }
    
    // Cancelar reply se ativo
    cancelReply();
    
    // Ativar modo de edição
    editingMessageId = messageId;
    
    // Mostrar preview de edição
    const editPreview = document.getElementById('editPreview');
    if (editPreview) {
        editPreview.style.display = 'flex';
        editPreview.querySelector('.edit-message-text').textContent = messageText.substring(0, 80) + (messageText.length > 80 ? '...' : '');
    }
    
    // Colocar texto no input
    const messageInput = document.getElementById('messageInput');
    messageInput.value = messageText;
    messageInput.focus();
    autoResizeTextarea(messageInput);
    toggleSendButton();
    
    // Destacar mensagem sendo editada
    msgEl.classList.add('editing-highlight');
}

function cancelEdit() {
    editingMessageId = null;
    
    const editPreview = document.getElementById('editPreview');
    if (editPreview) {
        editPreview.style.display = 'none';
    }
    
    // Remover destaque
    document.querySelectorAll('.editing-highlight').forEach(el => {
        el.classList.remove('editing-highlight');
    });
    
    // Limpar input
    const messageInput = document.getElementById('messageInput');
    messageInput.value = '';
    autoResizeTextarea(messageInput);
    toggleSendButton();
}

function handleMessageEdited(data) {
    const { messageId, content } = data;
    
    const msgEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!msgEl) return;
    
    // Atualizar texto
    const textEl = msgEl.querySelector('.message-text');
    if (textEl) {
        textEl.innerHTML = escapeHtml(content);
    }
    
    // Adicionar indicador de editada
    const metaEl = msgEl.querySelector('.message-meta');
    if (metaEl && !metaEl.querySelector('.edited-indicator')) {
        const timeEl = metaEl.querySelector('.message-time');
        if (timeEl) {
            const editedSpan = document.createElement('span');
            editedSpan.className = 'edited-indicator';
            editedSpan.textContent = 'editada';
            timeEl.parentNode.insertBefore(editedSpan, timeEl);
        }
    }
    
    // Remover destaque de edição
    msgEl.classList.remove('editing-highlight');
}

function handleTyping() {
    const messageInput = document.getElementById('messageInput');
    const hasText = messageInput.value.trim().length > 0;
    
    toggleSendButton();
    
    if (hasText && !isTyping && currentConversationId) {
        isTyping = true;
        socket.emit('typing', {
            userId: currentUserId,
            conversationId: currentConversationId,
            otherUserId: chatWithUserId,
            isTyping: true
        });
    }
    
    // Cancelar timeout anterior
    if (typingDebounce) {
        clearTimeout(typingDebounce);
    }
    
    // Parar de digitar após 3 segundos sem digitar
    typingDebounce = setTimeout(() => {
        if (isTyping && currentConversationId) {
            isTyping = false;
            socket.emit('typing', {
                userId: currentUserId,
                conversationId: currentConversationId,
                otherUserId: chatWithUserId,
                isTyping: false
            });
        }
    }, 3000);
}

function loadMoreMessages() {
    const chatMessages = document.getElementById('chatMessages');
    const firstMessage = chatMessages.querySelector('[data-message-id]');
    
    if (firstMessage && currentConversationId) {
        const beforeId = firstMessage.getAttribute('data-message-id');
        socket.emit('load_more_messages', {
            conversationId: currentConversationId,
            beforeId: parseInt(beforeId)
        });
    }
}

let _searchUsersTimer = null;
function searchUsers(query) {
    // Limpar timer anterior (debounce)
    if (_searchUsersTimer) clearTimeout(_searchUsersTimer);
    
    if (!query || query.trim().length < 2) {
        const usersList = document.getElementById('usersList');
        if (usersList) {
            usersList.innerHTML = '<p style="text-align: center; color: #65676b; padding: 20px 0;">Digite pelo menos 2 caracteres para pesquisar usuários</p>';
        }
        return;
    }
    
    // Debounce de 300ms para não sobrecarregar o servidor
    _searchUsersTimer = setTimeout(() => {
        socket.emit('search_users', { query: query.trim(), userId: currentUserId });
    }, 300);
}

// ========================================
// EXIBIÇÃO DE MENSAGENS
// ========================================

function displayMessages(messages) {
    const chatMessages = document.getElementById('chatMessages');
    
    // Verificar se o elemento existe
    if (!chatMessages) {
        console.warn('⚠️ chatMessages element not found, skipping displayMessages');
        return;
    }
    
    chatMessages.innerHTML = '';
    
    if (messages.length === 0) {
        chatMessages.innerHTML = `
            <div class="no-messages">
                <i class="fas fa-comments"></i>
                <p>Nenhuma mensagem ainda</p>
                <p>Envie uma mensagem para começar a conversa</p>
            </div>
        `;
        return;
    }
    
    let currentDate = null;
    
    messages.forEach(msg => {
        // Adicionar separador de data se necessário
        const msgDate = new Date(msg.created_at).toLocaleDateString('pt-BR');
        if (msgDate !== currentDate) {
            currentDate = msgDate;
            chatMessages.appendChild(createDateSeparator(msgDate));
        }
        
        chatMessages.appendChild(createMessageElement(msg));
    });
    
    // Scroll to bottom once - layout is stable because containers have fixed size
    scrollToBottom();
    
    // Inicializar lazy loading observer
    initChatMediaObserver();
    
    // Observar todas as imagens lazy
    const lazyImages = chatMessages.querySelectorAll('.chat-image[data-src]');
    lazyImages.forEach(img => {
        if (chatMediaObserver) chatMediaObserver.observe(img);
    });
    
    // Observar cards de preview de vídeo
    observeVideoPreviewCards();
    
    // Init mobile gestures after rendering messages
    initMobileGestures();
}

function appendMessage(msg, isTemp = false) {
    const chatMessages = document.getElementById('chatMessages');
    
    // Verificar se mensagem já existe (evitar duplicatas)
    const messageId = isTemp ? msg.tempId : msg.id;
    const selector = isTemp ? `[data-temp-id="${messageId}"]` : `[data-message-id="${messageId}"]`;
    const existingMessage = chatMessages.querySelector(selector);
    
    if (existingMessage) {
        console.log('⚠️ Mensagem já existe no DOM, ignorando:', messageId);
        return;
    }
    
    // Remover mensagem "sem mensagens" se existir
    const noMessages = chatMessages.querySelector('.no-messages');
    if (noMessages) noMessages.remove();
    
    console.log('➕ Adicionando mensagem ao DOM:', messageId);
    const msgElement = createMessageElement(msg, isTemp);
    chatMessages.appendChild(msgElement);
    
    if (isTemp) {
        playSentSound();
    }
    
    // Observar imagens lazy na nova mensagem
    const lazyImgs = msgElement.querySelectorAll('.chat-image[data-src]');
    lazyImgs.forEach(img => {
        if (chatMediaObserver) {
            chatMediaObserver.observe(img);
        } else {
            // Fallback: carregar directamente se observer não existe
            const src = img.getAttribute('data-src');
            if (src) {
                img.src = src;
                img.removeAttribute('data-src');
                img.addEventListener('load', () => {
                    img.classList.add('loaded');
                    img.closest('.chat-image-container')?.classList.add('loaded');
                }, { once: true });
            }
        }
    });
    
    scrollToBottom();
    
    // Observar cards de preview de vídeo
    observeVideoPreviewCards();
}

function createDateSeparator(date) {
    const today = new Date().toLocaleDateString('pt-BR');
    const yesterday = new Date(Date.now() - 86400000).toLocaleDateString('pt-BR');
    
    let displayDate = date;
    if (date === today) displayDate = 'Hoje';
    else if (date === yesterday) displayDate = 'Ontem';
    
    const div = document.createElement('div');
    div.className = 'message-date';
    div.innerHTML = `<span>${displayDate}</span>`;
    return div;
}

function createMessageElement(msg, isTemp = false) {
    const isSent = msg.sender_id == currentUserId;
    const messageClass = isSent ? 'sent' : 'received';
    const time = new Date(msg.created_at).toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
    
    const div = document.createElement('div');
    div.className = `message ${messageClass}`;
    
    if (isTemp) {
        div.setAttribute('data-temp-id', msg.tempId);
    } else {
        div.setAttribute('data-message-id', msg.id);
    }
    
    let replyHTML = '';
    const replyId = msg.reply_to_id || msg.reply_to_message_id;
    if (replyId && msg.reply_content) {
        replyHTML = `
            <div class="message-reply" onclick="scrollToMessage(${replyId})">
                <span class="reply-user">~${escapeHtml(msg.reply_username || 'Usuário')}</span>
                <span class="reply-text">${escapeHtml(msg.reply_content)}</span>
            </div>
        `;
    }
    
    const content = msg.content || msg.message || '';
    let messageContent = linkify(escapeHtml(content));
    
    // Verificar se é mensagem de áudio
    const audioMatch = content ? content.match(/\[audio:(.*?):(.*?)\]/) : null;
    const isAudioMessage = msg.type === 'audio' || audioMatch;
    
    // Verificar se é mensagem de imagem
    const isImageMessage = (msg.type === 'image' && msg.file_url) || 
        (msg.file_url && /\.(jpg|jpeg|png|gif|webp|bmp|svg|avif|heic)$/i.test(msg.file_url));
    
    // Verificar se é mensagem de vídeo
    const isVideoMessage = (msg.type === 'video' && msg.file_url) ||
        (msg.file_url && /\.(mp4|webm|mov|avi|mkv|3gp)$/i.test(msg.file_url) && !isImageMessage);
    
    // Verificar se é mensagem de ficheiro
    const fileMatch = content ? content.match(/\[file:(.*?):(.*?)\]/) : null;
    const isFileMessage = (msg.type === 'file' && msg.file_url) || fileMatch;
    
    if (isAudioMessage) {
        const audioUrl = audioMatch ? audioMatch[1] : (msg.file_url || '');
        let displayDuration = '0:00';
        
        if (audioMatch) {
            displayDuration = formatDuration(parseInt(audioMatch[2]));
        } else if (content) {
            const durationMatch = content.match(/\d+:\d{2}/);
            if (durationMatch) {
                displayDuration = durationMatch[0];
            }
        }
        
        messageContent = `
            <div class="audio-message">
                <button class="audio-play-btn" onclick="playAudioMessage(this, '${audioUrl}')">
                    <i class="fas fa-play"></i>
                </button>
                <div class="audio-wave">${generateWaveBars(30)}</div>
                <span class="audio-duration">${displayDuration}</span>
            </div>
        `;
    } else if (isImageMessage) {
        messageContent = `
            <div class="chat-image-container">
                <img data-src="${msg.file_url}" class="chat-image" alt="Imagem" onclick="openImageViewer('${msg.file_url}')">
            </div>
        `;
    } else if (isVideoMessage) {
        messageContent = `
            <div class="chat-video-container">
                <div class="video-thumbnail-wrapper" onclick="loadAndPlayVideo(this, '${msg.file_url}')">
                    <div class="video-play-overlay">
                        <i class="fas fa-play"></i>
                    </div>
                    <div class="video-placeholder-bg">
                        <i class="fas fa-film"></i>
                    </div>
                </div>
            </div>
        `;
    } else if (isFileMessage) {
        const fileName = fileMatch ? fileMatch[1] : (msg.file_url ? msg.file_url.split('/').pop() : 'Ficheiro');
        const fileSize = fileMatch ? formatFileSize(parseInt(fileMatch[2])) : '';
        const fileUrl = msg.file_url || '';
        messageContent = `
            <div class="chat-file-container" onclick="downloadFile('${fileUrl}', '${escapeHtml(fileName)}')">
                <div class="chat-file-icon"><i class="fas ${getFileIcon(fileName)}"></i></div>
                <div class="chat-file-info">
                    <span class="chat-file-name">${escapeHtml(fileName)}</span>
                    <span class="chat-file-size">${fileSize}</span>
                </div>
                <div class="chat-file-download"><i class="fas fa-download"></i></div>
            </div>
        `;
    }
    
    let statusHTML = '';
    if (isSent) {
        let statusIcon = '<i class="fas fa-clock"></i>';
        let statusClass = 'sending';
        
        if (!isTemp) {
            const status = msg.status || 'sent';
            
            if (status === 'read') {
                // ✔✔ Lido (azul)
                statusIcon = '<i class="fas fa-check-double"></i>';
                statusClass = 'read';
            } else if (status === 'delivered') {
                // ✔✔ Entregue (cinza)
                statusIcon = '<i class="fas fa-check-double"></i>';
                statusClass = 'delivered';
            } else {
                // ✔ Enviado (cinza)
                statusIcon = '<i class="fas fa-check"></i>';
                statusClass = 'sent';
            }
        }
        
        statusHTML = `<span class="message-status ${statusClass}">${statusIcon}</span>`;
    }
    
    // Reações existentes na mensagem
    const reactionsHTML = msg.reactions ? renderReactions(msg.id, msg.reactions) : '<div class="message-reactions" data-message-id="' + msg.id + '"></div>';
    
    // Botão de opções (três pontos)
    const moreOptionsHTML = !isTemp ? `
        <button class="msg-more-btn" onclick="showMessageOptions(event, ${msg.id}, '${escapeHtml(content).replace(/'/g, "\\'")}', ${isSent})">
            <i class="fas fa-ellipsis-v"></i>
        </button>
    ` : '';
    
    // Indicador de mensagem editada
    const editedHTML = (msg.is_edited == 1 || msg.is_edited === true) ? '<span class="edited-indicator">editada</span>' : '';
    
    div.innerHTML = `
        <div class="message-content">
            ${moreOptionsHTML}
            <div class="message-bubble-wrapper">
                <div class="message-bubble">
                    ${replyHTML}
                    <div class="message-text">${messageContent}</div>
                    <div class="message-meta">
                        ${editedHTML}
                        <span class="message-time">${time}</span>
                        ${statusHTML}
                    </div>
                </div>
                ${reactionsHTML}
            </div>
        </div>
        <div class="swipe-reply-icon"><i class="fas fa-reply"></i></div>
    `;
    
    return div;
}

// Emojis de reação disponíveis
const REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

function renderReactions(messageId, reactions) {
    if (!reactions || Object.keys(reactions).length === 0) {
        return `<div class="message-reactions" data-message-id="${messageId}"></div>`;
    }
    
    let html = `<div class="message-reactions" data-message-id="${messageId}">`;
    for (const [emoji, users] of Object.entries(reactions)) {
        const count = users.length;
        if (count > 0) {
            html += `<span class="reaction" onclick="toggleReaction(${messageId}, '${emoji}')">${emoji}${count > 1 ? ' ' + count : ''}</span>`;
        }
    }
    html += '</div>';
    return html;
}

function showReactionPicker(event, messageId) {
    event.stopPropagation();
    
    // Remover picker existente
    const existingPicker = document.querySelector('.reaction-picker');
    if (existingPicker) existingPicker.remove();
    
    // Criar picker
    const picker = document.createElement('div');
    picker.className = 'reaction-picker';
    picker.innerHTML = REACTION_EMOJIS.map(emoji => 
        `<button onclick="addReaction(${messageId}, '${emoji}')">${emoji}</button>`
    ).join('');
    
    // Posicionar perto da mensagem
    const bubble = event.currentTarget || event.target.closest('.message-bubble') || event.target;
    const rect = bubble.getBoundingClientRect();
    
    picker.style.position = 'fixed';
    picker.style.top = (rect.top - 50) + 'px';
    picker.style.left = Math.max(10, Math.min(rect.left, window.innerWidth - 220)) + 'px';
    
    document.body.appendChild(picker);
    
    // Fechar ao clicar fora
    setTimeout(() => {
        document.addEventListener('click', closeReactionPicker);
    }, 10);
}

function closeReactionPicker() {
    const picker = document.querySelector('.reaction-picker');
    if (picker) picker.remove();
    document.removeEventListener('click', closeReactionPicker);
}

function addReaction(messageId, emoji) {
    closeReactionPicker();
    socket.emit('add_reaction', {
        messageId: messageId,
        emoji: emoji,
        userId: currentUserId
    });
}

function toggleReaction(messageId, emoji) {
    socket.emit('toggle_reaction', {
        messageId: messageId,
        emoji: emoji,
        userId: currentUserId
    });
}

function showMessageOptions(event, messageId, content, isSent) {
    event.stopPropagation();
    
    // Remover menu existente
    const existingMenu = document.querySelector('.message-options-menu');
    if (existingMenu) existingMenu.remove();
    
    // Criar menu
    const menu = document.createElement('div');
    menu.className = 'message-options-menu';
    
    // Linha de emojis no topo (estilo WhatsApp)
    const reactionsHTML = REACTION_EMOJIS.map(emoji => 
        `<button onclick="addReactionFromMenu(${messageId}, '${emoji}')">${emoji}</button>`
    ).join('');
    
    let actionsHTML = `
        <button onclick="replyToMessageFromMenu(${messageId})">
            <i class="fas fa-reply"></i> Responder
        </button>
        <button onclick="copyMessageText('${content.replace(/'/g, "\\'")}')">
            <i class="fas fa-copy"></i> Copiar
        </button>
        <button onclick="forwardMessage(${messageId})">
            <i class="fas fa-share"></i> Reencaminhar
        </button>
    `;
    
    if (isSent) {
        // Verificar se a mensagem tem menos de 5 minutos (pode editar)
        const msgEl = document.querySelector(`[data-message-id="${messageId}"]`);
        const msgTimeText = msgEl?.querySelector('.message-time')?.textContent;
        let canEdit = false;
        if (msgTimeText) {
            const today = new Date();
            const [h, m] = msgTimeText.split(':').map(Number);
            const msgDate = new Date(today.getFullYear(), today.getMonth(), today.getDate(), h, m);
            canEdit = (Date.now() - msgDate.getTime()) < 300000; // 5 min
        }
        
        // Mensagens enviadas: Editar (se < 5min) + Apagar
        if (canEdit) {
            actionsHTML += `
                <button onclick="startEditMessage(${messageId})">
                    <i class="fas fa-pen"></i> Editar
                </button>
            `;
        }
        actionsHTML += `
            <button onclick="deleteMessage(${messageId}, 'for_all')" class="delete-option">
                <i class="fas fa-trash"></i> Apagar para todos
            </button>
            <button onclick="deleteMessage(${messageId}, 'for_me')" class="delete-option">
                <i class="fas fa-trash-alt"></i> Apagar para mim
            </button>
        `;
    } else {
        // Mensagens recebidas: Apagar para mim
        actionsHTML += `
            <button onclick="deleteMessage(${messageId}, 'for_me')" class="delete-option">
                <i class="fas fa-trash-alt"></i> Apagar para mim
            </button>
        `;
    }
    
    menu.innerHTML = `
        <div class="menu-reactions">${reactionsHTML}</div>
        <div class="menu-actions">${actionsHTML}</div>
    `;
    
    // Posicionar acima do botão (estilo WhatsApp)
    const btn = event.currentTarget;
    const rect = btn.getBoundingClientRect();
    
    menu.style.position = 'fixed';
    document.body.appendChild(menu);
    
    const menuRect = menu.getBoundingClientRect();
    let top = rect.top - menuRect.height - 5;
    if (top < 10) top = rect.bottom + 5;
    
    menu.style.top = top + 'px';
    menu.style.left = Math.max(10, Math.min(rect.left - 80, window.innerWidth - menuRect.width - 10)) + 'px';
    
    // Fechar ao clicar fora
    setTimeout(() => {
        document.addEventListener('click', closeMessageOptions);
    }, 10);
}

function closeMessageOptions() {
    const menu = document.querySelector('.message-options-menu');
    if (menu) menu.remove();
    document.removeEventListener('click', closeMessageOptions);
}

function addReactionFromMenu(messageId, emoji) {
    closeMessageOptions();
    socket.emit('add_reaction', {
        messageId: messageId,
        emoji: emoji,
        userId: currentUserId
    });
}

function replyToMessageFromMenu(messageId) {
    closeMessageOptions();
    const msgElement = document.querySelector(`[data-message-id="${messageId}"]`);
    if (msgElement) {
        const content = msgElement.querySelector('.message-text')?.textContent || '';
        const isSent = msgElement.classList.contains('sent');
        const username = isSent ? currentUsername : chatWithUsername;
        replyToMessage(messageId, content, username);
    }
}

function copyMessageText(text) {
    closeMessageOptions();
    navigator.clipboard.writeText(text).then(() => {
        showToast('Texto copiado!');
    }).catch(() => {
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('Texto copiado!');
    });
}

function forwardMessage(messageId) {
    closeMessageOptions();
    // Guardar mensagem para reencaminhar
    const msgElement = document.querySelector(`[data-message-id="${messageId}"]`);
    if (msgElement) {
        const content = msgElement.querySelector('.message-text')?.textContent || '';
        sessionStorage.setItem('forwardMessage', content);
        showToast('Selecione uma conversa para reencaminhar');
        // Mostrar lista de conversas para selecionar
        closeMobileChat();
    }
}

function checkForForwardMessage() {
    const forwardContent = sessionStorage.getItem('forwardMessage');
    if (forwardContent) {
        // Colocar o texto no input
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.value = forwardContent;
            messageInput.focus();
            autoResizeTextarea(messageInput);
        }
        // Limpar do storage
        sessionStorage.removeItem('forwardMessage');
        showToast('Mensagem pronta para reencaminhar');
    }
}

function showToast(message) {
    const existingToast = document.querySelector('.toast-message');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast-message';
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 2000);
}

function displaySearchResults(users) {
    const usersList = document.getElementById('usersList');
    if (!usersList) return;
    
    if (users.length === 0) {
        usersList.innerHTML = '<p style="text-align: center; color: #65676b;">Nenhum usuário encontrado</p>';
        return;
    }
    
    usersList.innerHTML = users.map(user => `
        <div class="user-item"
             data-user-id="${user.id}"
             data-username="${escapeHtml(user.username)}"
             data-avatar="${escapeHtml(getAvatarUrl(user.avatar))}"
             data-is-verified="${user.is_verified ? '1' : '0'}"
             onclick="openChat(${user.id})">
            <img src="${getAvatarUrl(user.avatar)}" 
                 onerror="this.src='${DEFAULT_AVATAR}'" 
                 alt="${escapeHtml(user.username)}">
            <div class="user-item-info">
                <h4><span>${escapeHtml(user.username)}</span>${getVerifiedBadgeMarkup(user.is_verified)}</h4>
            </div>
        </div>
    `).join('');
}

// ========================================
// RESPONDER MENSAGEM
// ========================================

function scrollToMessage(messageId) {
    const msgEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!msgEl) return;
    
    msgEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Highlight temporário
    msgEl.style.transition = 'background 0.3s';
    msgEl.style.background = 'rgba(59, 130, 246, 0.15)';
    setTimeout(() => {
        msgEl.style.background = '';
    }, 1500);
}

function replyToMessage(messageId, messageText, username) {
    replyToMessageId = messageId;
    
    const replyPreview = document.getElementById('replyPreview');
    replyPreview.style.display = 'flex';
    
    replyPreview.querySelector('.reply-user').textContent = '~' + username;
    replyPreview.querySelector('.reply-message').textContent = messageText.substring(0, 50) + (messageText.length > 50 ? '...' : '');
    
    document.getElementById('messageInput').focus();
}

function cancelReply() {
    replyToMessageId = null;
    const replyPreview = document.getElementById('replyPreview');
    if (replyPreview) {
        replyPreview.style.display = 'none';
    }
}

// ========================================
// MODAL DE NOVO CHAT
// ========================================

function showNewChatModal() {
    document.getElementById('newChatModal').style.display = 'flex';
    // Limpar campo de pesquisa e mostrar mensagem inicial
    const searchInput = document.getElementById('searchUsers');
    if (searchInput) searchInput.value = '';
    const usersList = document.getElementById('usersList');
    if (usersList) {
        usersList.innerHTML = '<p style="text-align: center; color: #65676b; padding: 20px 0;">Digite pelo menos 2 caracteres para pesquisar usuários</p>';
    }
}

function closeNewChatModal() {
    document.getElementById('newChatModal').style.display = 'none';
}

// ========================================
// NAVEGAÇÃO
// ========================================

function openChat(userId) {
    closeNewChatModal();
    
    // Atualizar URL sem recarregar a página
    const fromParam = typeof fromPage !== 'undefined' && fromPage ? `&from=${fromPage}` : '';
    const newUrl = `chat.php?user_id=${userId}${fromParam}`;
    window.history.pushState({ userId: userId }, '', newUrl);
    
    // Atualizar variável global
    chatWithUserId = userId;
    updateActiveUsersCountUI();
    subscribeContactPresence();
    
    // Buscar informações do usuário da conversa selecionada
    const conversationItem = document.querySelector(`.conversation-item[data-user-id="${userId}"]`);
    const searchUserItem = document.querySelector(`.user-item[data-user-id="${userId}"]`);
    const sourceItem = conversationItem || searchUserItem;
    const username = sourceItem ? sourceItem.dataset.username : 'Usuário';
    const avatar = sourceItem ? (sourceItem.dataset.avatar || sourceItem.querySelector('img')?.src) : DEFAULT_AVATAR;
    const isVerified = sourceItem ? sourceItem.dataset.isVerified === '1' : false;
    chatWithUsername = username;
    chatWithIsVerified = isVerified;
    
    // Marcar conversa como ativa na sidebar
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('active');
    });
    if (conversationItem) {
        conversationItem.classList.add('active');
        
        // Limpar badge de mensagens não lidas
        const badge = conversationItem.querySelector('.unread-badge');
        if (badge) {
            badge.remove();
        }
    }
    
    // Mostrar área de chat PRIMEIRO (cria o HTML se necessário)
    showChatArea();
    
    // DEPOIS atualizar o header com as informações do usuário
    updateChatHeader(userId, username, avatar, isVerified);
    
    // Iniciar/entrar na conversa via Socket
    startConversation(userId);
    
    // Verificar se há mensagem para reencaminhar
    checkForForwardMessage();
    
    // Verificar se o usuário está digitando (do Map guardado)
    const normalizedUserId = parseInt(userId);
    const isUserTyping = usersTyping.has(normalizedUserId);
    console.log('🔄 Mudou de conversa para userId:', normalizedUserId, '- Está digitando?', isUserTyping, '- Map:', [...usersTyping.keys()]);
    updateHeaderTypingIndicator(normalizedUserId, isUserTyping);
    
    // Atualizar indicadores de typing na sidebar para todos os usuários
    refreshSidebarTypingIndicators();
    
    // Em mobile, esconder sidebar
    hideSidebarOnMobile();
}

function updateChatHeader(userId, username, avatar, isVerified = false) {
    const chatHeader = document.querySelector('.chat-header');
    if (!chatHeader) return;
    
    // Atualizar avatar
    const headerImg = chatHeader.querySelector('.chat-header-user img');
    if (headerImg) {
        headerImg.src = avatar;
        headerImg.alt = username;
    }
    
    // Atualizar nome
    const headerName = chatHeader.querySelector('.chat-header-info h3');
    if (headerName) {
        headerName.innerHTML = `<span class="chat-header-name">${escapeHtml(username)}</span>${getVerifiedBadgeMarkup(isVerified)}`;
    }
    
    // Atualizar data-user-id do status indicator
    const statusIndicator = chatHeader.querySelector('.status-indicator');
    if (statusIndicator) {
        statusIndicator.setAttribute('data-user-id', userId);
    }
    
    // Resetar status para "Offline" até receber status real
    const statusText = chatHeader.querySelector('.status-text');
    if (statusText) {
        statusText.textContent = 'Offline';
    }
    
    // Esconder typing indicator
    const typingIndicator = document.getElementById('typingIndicator');
    if (typingIndicator) {
        typingIndicator.style.display = 'none';
    }
    const userStatus = document.getElementById('userStatus');
    if (userStatus) {
        userStatus.style.display = 'flex';
    }
}

function showChatArea() {
    const chatMain = document.getElementById('chatMain');
    const noConversation = document.querySelector('.no-chat-selected');
    
    // Se não existe área de chat, criar dinamicamente
    if (noConversation && chatMain) {
        chatMain.innerHTML = createChatAreaHTML();
        // Configurar event listeners do input após criar o HTML
        setupMessageInput();
    }
    
    // Mostrar área de chat
    if (chatMain) {
        chatMain.style.display = 'flex';
        chatMain.classList.add('active');
    }
    
    // Limpar mensagens anteriores e mostrar loading
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.innerHTML = `
            <div class="loading-messages">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Carregando mensagens...</p>
            </div>
        `;
    }
    
    // Limpar input de mensagem
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.value = '';
        autoResizeTextarea(messageInput);
    }
    
    // Cancelar reply se tiver
    if (typeof cancelReply === 'function') {
        cancelReply();
    }
}

function createChatAreaHTML() {
    return `
        <!-- Header do chat -->
        <div class="chat-header">
            <button class="back-btn" onclick="closeMobileChat()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="chat-header-user">
                <img src="${DEFAULT_AVATAR}" alt="Usuário">
                <div class="chat-header-info">
                    <h3>Carregando...</h3>
                    <span class="user-status" id="userStatus">
                        <span class="status-indicator" data-user-id=""></span>
                        <span class="status-text">Offline</span>
                    </span>
                    <span class="typing-indicator" id="typingIndicator" style="display: none;">
                        <i class="fas fa-circle"></i>
                        <i class="fas fa-circle"></i>
                        <i class="fas fa-circle"></i>
                        digitando...
                    </span>
                </div>
            </div>
            <div class="chat-header-actions">
                <button onclick="makeVideoCall()">
                    <i class="fas fa-video"></i>
                </button>
                <button onclick="makeVoiceCall()">
                    <i class="fas fa-phone"></i>
                </button>
                <button onclick="showChatOptions()">
                    <i class="fas fa-ellipsis-v"></i>
                </button>
            </div>
        </div>
        
        <!-- Mensagens -->
        <div class="chat-messages" id="chatMessages">
            <div class="loading-messages">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Carregando mensagens...</p>
            </div>
        </div>
        
        <!-- Reply preview -->
        <div class="reply-preview" id="replyPreview" style="display: none;">
            <div class="reply-content">
                <i class="fas fa-reply"></i>
                <div class="reply-info">
                    <strong class="reply-user"></strong>
                    <p class="reply-message"></p>
                </div>
            </div>
            <button class="cancel-reply" onclick="cancelReply()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <!-- Input de mensagem -->
        <div class="chat-input">
            <button class="attach-btn" onclick="showAttachOptions()">
                <i class="fas fa-paperclip"></i>
            </button>
            <div class="input-wrapper">
                <textarea 
                    id="messageInput" 
                    placeholder="Digite uma mensagem..." 
                    rows="1"></textarea>
                <button class="emoji-btn" onclick="showEmojiPicker()">
                    <i class="fas fa-smile"></i>
                </button>
            </div>
            <button class="voice-btn" id="voiceBtn" onclick="toggleVoiceRecording()">
                <i class="fas fa-microphone"></i>
            </button>
            <button class="send-btn" id="sendBtn" style="display: none;">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
        
        <!-- Menu de opções de anexo -->
        <div class="attach-menu" id="attachMenu" style="display: none;">
            <button onclick="selectFile('image')">
                <i class="fas fa-image"></i>
                <span>Foto</span>
            </button>
            <button onclick="selectFile('video')">
                <i class="fas fa-video"></i>
                <span>Vídeo</span>
            </button>
            <button onclick="selectFile('document')">
                <i class="fas fa-file"></i>
                <span>Documento</span>
            </button>
            <button onclick="showStickerPicker()">
                <i class="fas fa-sticky-note"></i>
                <span>Sticker</span>
            </button>
        </div>
        
        <input type="file" id="fileInput" style="display: none;" onchange="handleFileSelect(event)">
    `;
}

function closeMobileChat() {
    // Parar todos os vídeos e áudios em reprodução
    stopAllChatMedia();
    
    // Em vez de recarregar, apenas esconder a área de chat e mostrar a sidebar
    const chatMain = document.getElementById('chatMain');
    const sidebar = document.querySelector('.chat-sidebar');
    
    // Esconder área de chat
    if (chatMain) {
        chatMain.classList.remove('active');
        // Em mobile, esconder completamente
        if (window.innerWidth <= 768) {
            chatMain.style.display = 'none';
        }
    }
    
    // Mostrar sidebar
    if (sidebar) {
        sidebar.classList.remove('hidden');
        sidebar.style.display = 'flex';
    }
    
    // Limpar conversa atual
    chatWithUserId = null;
    currentConversationId = null;
    updateActiveUsersCountUI();
    subscribeContactPresence();
    
    // Remover seleção ativa da lista
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('active');
    });
    
    // Atualizar URL sem recarregar
    const fromParam = typeof fromPage !== 'undefined' && fromPage ? `?from=${fromPage}` : '';
    window.history.pushState({}, '', `chat.php${fromParam}`);
}

function showSidebarOnMobile() {
    const sidebar = document.querySelector('.chat-sidebar');
    if (sidebar && window.innerWidth <= 768) {
        sidebar.classList.remove('hidden');
        sidebar.style.display = 'flex';
    }
}

function hideSidebarOnMobile() {
    if (window.innerWidth <= 768) {
        const sidebar = document.querySelector('.chat-sidebar');
        const chatMain = document.getElementById('chatMain');
        
        if (sidebar) {
            sidebar.classList.add('hidden');
            sidebar.style.display = 'none';
        }
        
        // Garantir que a área de chat está visível
        if (chatMain) {
            chatMain.style.display = 'flex';
        }
    }
}

// ========================================
// UTILITÁRIOS
// ========================================

function toggleSendButton() {
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const voiceBtn = document.getElementById('voiceBtn');
    
    if (messageInput && messageInput.value.trim().length > 0) {
        if (sendBtn) sendBtn.style.display = 'flex';
        if (voiceBtn) voiceBtn.style.display = 'none';
    } else {
        if (sendBtn) sendBtn.style.display = 'none';
        if (voiceBtn) voiceBtn.style.display = 'flex';
    }
}

function autoResizeTextarea(textarea) {
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
}

function scrollToBottom() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        // Use rAF to ensure scroll happens after all renders
        requestAnimationFrame(() => {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        });
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Converter URLs em links clicáveis (com preview para vídeos MyTube)
function linkify(text) {
    if (!text) return '';
    
    // Detectar links do MyTube com video_id (incluindo texto contextual de compartilhamento)
    const mytubeRegex = /(?:🎬[^h]*?)?(https?:\/\/[^\s]*index\.php\?[^\s]*video_id=(\d+)[^\s]*)/gi;
    let result = text;
    let hasMyTubeLink = false;
    
    // Substituir links do MyTube por placeholders de preview
    result = result.replace(mytubeRegex, (fullMatch, url, videoId) => {
        hasMyTubeLink = true;
        return `<div class="video-preview-card" data-video-id="${videoId}" data-video-url="${escapeHtml(url)}">
            <div class="video-preview-loading">
                <div class="spinner-small"></div>
            </div>
        </div>`;
    });
    
    // Para outros links, converter normalmente
    if (!hasMyTubeLink) {
        const urlRegex = /(https?:\/\/[^\s<]+|www\.[^\s<]+)/gi;
        result = result.replace(urlRegex, (url) => {
            const href = url.startsWith('www.') ? 'https://' + url : url;
            return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="chat-link">${url}</a>`;
        });
    }
    
    return result;
}

// Observer para lazy load de previews de vídeo
let videoPreviewObserver = null;
const videoPreviewCache = new Map();

function initVideoPreviewObserver() {
    if (videoPreviewObserver) return;
    
    videoPreviewObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const card = entry.target;
                const videoId = card.dataset.videoId;
                if (videoId && !card.dataset.loaded) {
                    loadVideoPreview(card, videoId);
                }
                videoPreviewObserver.unobserve(card);
            }
        });
    }, { rootMargin: '100px' });
}

function loadVideoPreview(card, videoId) {
    // Verificar cache primeiro
    if (videoPreviewCache.has(videoId)) {
        renderVideoPreview(card, videoPreviewCache.get(videoId));
        return;
    }
    
    fetch(`api/get_video_preview.php?id=${videoId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.video) {
                videoPreviewCache.set(videoId, data.video);
                renderVideoPreview(card, data.video);
            } else {
                // Fallback para link simples se vídeo não existe
                const url = card.dataset.videoUrl;
                card.outerHTML = `<a href="${url}" target="_blank" class="chat-link">${url}</a>`;
            }
        })
        .catch(() => {
            const url = card.dataset.videoUrl;
            card.outerHTML = `<a href="${url}" target="_blank" class="chat-link">${url}</a>`;
        });
}

function renderVideoPreview(card, video) {
    const url = card.dataset.videoUrl;
    const views = video.views >= 1000 ? (video.views / 1000).toFixed(1) + 'K' : video.views;
    
    // Usar thumbnail se existir, senão usar frame do vídeo
    let mediaHtml = '';
    if (video.thumbnail) {
        mediaHtml = `<img src="${video.thumbnail}" alt="" loading="lazy">`;
    } else if (video.video_src) {
        // Usar vídeo com preload mínimo para mostrar primeiro frame
        mediaHtml = `<video src="${video.video_src}#t=0.5" preload="metadata" muted playsinline></video>`;
    } else {
        mediaHtml = `<div class="video-preview-placeholder"><i class="fas fa-film"></i></div>`;
    }
    
    card.dataset.loaded = 'true';
    card.innerHTML = `
        <a href="${url}" class="video-preview-link">
            <div class="video-preview-thumb">
                ${mediaHtml}
                <div class="video-preview-play"><i class="fas fa-play"></i></div>
            </div>
            <div class="video-preview-info">
                <div class="video-preview-title">${escapeHtml(video.title)}</div>
                <div class="video-preview-meta">
                    <span class="video-preview-author">@${escapeHtml(video.author)}</span>
                    <span class="video-preview-views"><i class="fas fa-eye"></i> ${views}</span>
                </div>
            </div>
        </a>
    `;
}

// Observar novos cards de preview no DOM
function observeVideoPreviewCards() {
    initVideoPreviewObserver();
    document.querySelectorAll('.video-preview-card:not([data-observed])').forEach(card => {
        card.dataset.observed = 'true';
        videoPreviewObserver.observe(card);
    });
}

function formatMessageTime(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diffMs = now - date;
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffDays === 0) {
        return date.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
    } else if (diffDays === 1) {
        return 'Ontem';
    } else if (diffDays < 7) {
        return date.toLocaleDateString('pt-BR', {weekday: 'short'});
    } else {
        return date.toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit'});
    }
}

function formatLastSeen(timestamp) {
    if (!timestamp) return 'Offline';
    
    const lastSeen = new Date(timestamp);
    const now = new Date();
    const diffMs = now - lastSeen;
    const diffMinutes = Math.floor(diffMs / 60000);
    
    if (diffMinutes < 1) {
        return 'Visto agora mesmo';
    } else if (diffMinutes < 60) {
        return `Visto há ${diffMinutes} min`;
    } else {
        const hours = Math.floor(diffMinutes / 60);
        if (hours < 24) {
            return `Visto há ${hours}h`;
        } else {
            return `Visto em ${lastSeen.toLocaleDateString('pt-BR')}`;
        }
    }
}
function playNotificationSound() {
    try {
        const audio = new Audio('assets/sounds/recive.mp3?v=' + Date.now());
        audio.play().catch(e => console.log('Audio autoplay prevented:', e));
    } catch (e) {
        console.error('Erro ao tocar som:', e);
    }
}

function playSentSound() {
    try {
        const audio = new Audio('assets/sounds/send.mp3?v=' + Date.now());
        audio.play().catch(e => console.log('Audio autoplay prevented:', e));
    } catch (e) {
        console.error('Erro ao tocar som:', e);
    }
}

// showToast já definida anteriormente — removida duplicata com classe CSS incorreta

// ========================================
// SCROLL INFINITO (integrado no DOMContentLoaded principal)
// ========================================

// ========================================
// NOTIFICAÇÕES DO NAVEGADOR (integrado no DOMContentLoaded principal)
// ========================================

// ========================================
// FUNÇÕES PARA CHAMADAS (placeholder)
// ========================================

function buildVideoCallInvite() {
    const participantIds = [currentUserId, chatWithUserId]
        .map((id) => parseInt(id, 10))
        .filter((id) => Number.isFinite(id))
        .sort((left, right) => left - right);

    const roomName = `MyTube-${participantIds.join('-')}-${Date.now().toString(36)}`;
    return {
        roomName,
        url: `https://meet.jit.si/${encodeURIComponent(roomName)}`
    };
}

function makeVideoCall(userId = chatWithUserId) {
    alert('Chamadas de vídeo serão implementadas em breve!');
    /* const targetUserId = parseInt(userId || chatWithUserId, 10);

    if (!targetUserId || !chatWithUserId) {
        showToast('Selecione uma conversa primeiro');
        return;
    }

    if (!currentConversationId) {
        showToast('Aguarde a conversa carregar para iniciar o vídeo');
        return;
    }

    const { url } = buildVideoCallInvite();
    const popup = window.open(url, '_blank', 'noopener,noreferrer');
    const inviteMessage = `🎥 Convite para vídeo chamada\n${url}`;

    const sent = sendTextMessage(inviteMessage, {
        clearInput: false,
        allowEdit: false,
        replyToId: null,
        silent: true
    });

    if (!sent) {
        if (popup && !popup.closed) {
            popup.close();
        }

        showToast('Não foi possível enviar o convite de vídeo');
        return;
    }

    showToast(popup ? 'Sala de vídeo aberta e convite enviado' : 'Convite enviado no chat');
 */}

function makeVoiceCall(userId) {
    alert('Chamadas de voz serão implementadas em breve!');
}

function showChatOptions() {
    alert('Menu de opções será implementado em breve!');
}

// ========================================
// ANEXOS (placeholder)
// ========================================

function showAttachOptions() {
    // Fechar emoji picker se aberto
    if (emojiPickerOpen) closeEmojiPicker();
    
    let attachMenu = document.getElementById('attachMenu');
    
    // Se o menu não existe (chat criado dinamicamente), criar
    if (!attachMenu) {
        const chatInput = document.querySelector('.chat-input');
        if (!chatInput) return;
        
        attachMenu = document.createElement('div');
        attachMenu.className = 'attach-menu';
        attachMenu.id = 'attachMenu';
        attachMenu.style.display = 'none';
        attachMenu.innerHTML = `
            <button onclick="selectFile('image')">
                <i class="fas fa-image"></i>
                <span>Foto</span>
            </button>
            <button onclick="selectFile('video')">
                <i class="fas fa-video"></i>
                <span>Vídeo</span>
            </button>
            <button onclick="selectFile('document')">
                <i class="fas fa-file"></i>
                <span>Documento</span>
            </button>
            <button onclick="showStickerPicker()">
                <i class="fas fa-sticky-note"></i>
                <span>Sticker</span>
            </button>
        `;
        chatInput.parentNode.insertBefore(attachMenu, chatInput.nextSibling);
    }
    
    // Garantir que fileInput existe
    if (!document.getElementById('fileInput')) {
        const input = document.createElement('input');
        input.type = 'file';
        input.id = 'fileInput';
        input.style.display = 'none';
        input.onchange = handleFileSelect;
        attachMenu.parentNode.appendChild(input);
    }
    
    attachMenu.style.display = attachMenu.style.display === 'none' ? 'grid' : 'none';
}

function selectFile(type) {
    const fileInput = document.getElementById('fileInput');
    if (!fileInput) return;
    
    // Guardar o tipo selecionado
    fileInput.dataset.uploadType = type;
    
    // Definir accept baseado no tipo
    switch (type) {
        case 'image':
            fileInput.accept = 'image/*';
            break;
        case 'video':
            fileInput.accept = 'video/*';
            break;
        case 'document':
            fileInput.accept = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar,.7z,.ppt,.pptx,.odt,.ods,.odp,.rtf,.json,.xml,.html,.css,.js,.py,.java,.c,.cpp,.sql';
            break;
        default:
            fileInput.accept = '';
    }
    
    fileInput.value = '';
    fileInput.click();
    
    const attachMenu = document.getElementById('attachMenu');
    if (attachMenu) attachMenu.style.display = 'none';
}

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const uploadType = event.target.dataset.uploadType || 'file';
    
    // Determinar tipo real baseado no MIME
    let fileType = uploadType;
    if (uploadType === 'document') fileType = 'file';
    if (file.type.startsWith('image/') && uploadType === 'image') fileType = 'image';
    if (file.type.startsWith('video/') && uploadType === 'video') fileType = 'video';
    
    // Extensões permitidas (mesmo que o servidor)
    const allowedExtensions = {
        image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff', 'tif', 'heic', 'heif', 'avif'],
        video: ['mp4', 'webm', 'mov', 'avi', 'mkv', '3gp'],
        audio: ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'aac', 'flac'],
        file: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'rar', '7z', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'json', 'xml', 'html', 'css', 'js', 'py', 'java', 'c', 'cpp', 'sql']
    };
    
    // Limites de tamanho
    const sizeLimits = {
        image: { max: 50 * 1024 * 1024, label: '50MB' },
        video: { max: 6 * 1024 * 1024, label: '6MB' },
        audio: { max: 10 * 1024 * 1024, label: '10MB' },
        file: { max: 25 * 1024 * 1024, label: '25MB' }
    };
    
    // Validar extensão
    const ext = file.name.split('.').pop().toLowerCase();
    const allowed = allowedExtensions[fileType] || [];
    if (allowed.length > 0 && !allowed.includes(ext)) {
        const typeLabels = { image: 'imagem', video: 'vídeo', audio: 'áudio', file: 'documento' };
        showToast(`Tipo de ${typeLabels[fileType] || 'ficheiro'} não permitido (.${ext}). Formatos aceites: ${allowed.join(', ')}`);
        event.target.value = '';
        return;
    }
    
    // Validar tamanho
    const limit = sizeLimits[fileType];
    if (limit && file.size > limit.max) {
        const typeLabels = { image: 'Imagem', video: 'Vídeo', audio: 'Áudio', file: 'Ficheiro' };
        showToast(`${typeLabels[fileType] || 'Ficheiro'} muito grande! Máximo ${limit.label}. (${formatFileSize(file.size)})`);
        event.target.value = '';
        return;
    }
    
    // Mostrar preview antes de enviar
    showFilePreview(file, fileType);
}

function showFilePreview(file, fileType) {
    // Remover preview anterior
    const existing = document.getElementById('filePreviewOverlay');
    if (existing) existing.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'filePreviewOverlay';
    overlay.className = 'file-preview-overlay';
    
    let previewContent = '';
    const fileSize = formatFileSize(file.size);
    
    if (fileType === 'image') {
        const objectUrl = URL.createObjectURL(file);
        previewContent = `<img src="${objectUrl}" class="file-preview-image" alt="Preview">`;
    } else if (fileType === 'video') {
        const objectUrl = URL.createObjectURL(file);
        previewContent = `<video src="${objectUrl}" class="file-preview-video" controls></video>`;
    } else {
        const ext = file.name.split('.').pop().toUpperCase();
        previewContent = `
            <div class="file-preview-doc">
                <i class="fas ${getFileIcon(file.name)}"></i>
                <span class="file-preview-ext">${ext}</span>
            </div>
        `;
    }
    
    overlay.innerHTML = `
        <div class="file-preview-modal">
            <div class="file-preview-header">
                <span class="file-preview-name" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
                <button class="file-preview-close" onclick="closeFilePreview()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="file-preview-body">
                ${previewContent}
            </div>
            <div class="file-preview-footer">
                <span class="file-preview-size">${fileSize}</span>
                <div class="file-preview-actions">
                    <button class="file-preview-cancel" onclick="closeFilePreview()">Cancelar</button>
                    <button class="file-preview-send" id="sendFileBtn">
                        <i class="fas fa-paper-plane"></i> Enviar
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    
    // Fechar ao clicar fora
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeFilePreview();
    });
    
    // Botão enviar
    document.getElementById('sendFileBtn').addEventListener('click', () => {
        closeFilePreview();
        uploadAndSendFile(file, fileType);
    });
}

function closeFilePreview() {
    const overlay = document.getElementById('filePreviewOverlay');
    if (overlay) {
        // Limpar object URLs
        const img = overlay.querySelector('.file-preview-image');
        const vid = overlay.querySelector('.file-preview-video');
        if (img) URL.revokeObjectURL(img.src);
        if (vid) { vid.pause(); URL.revokeObjectURL(vid.src); }
        overlay.remove();
    }
}

async function uploadAndSendFile(file, fileType) {
    if (!chatWithUserId) return;
    
    const tempId = 'file_' + Date.now();
    const fileSize = formatFileSize(file.size);
    const fileName = file.name;
    
    // Criar mensagem temporária no DOM
    const tempDiv = document.createElement('div');
    tempDiv.className = 'message sent';
    tempDiv.setAttribute('data-temp-id', tempId);
    
    let tempContent = '';
    if (fileType === 'image') {
        const objectUrl = URL.createObjectURL(file);
        tempContent = `
            <div class="chat-image-container loaded">
                <img src="${objectUrl}" class="chat-image loaded loading" alt="Enviando...">
                <div class="upload-progress-overlay">
                    <div class="upload-progress-spinner"></div>
                </div>
            </div>
        `;
    } else if (fileType === 'video') {
        tempContent = `
            <div class="chat-video-container">
                <div class="video-upload-placeholder">
                    <i class="fas fa-video"></i>
                    <span>A enviar vídeo...</span>
                </div>
                <div class="upload-progress-overlay">
                    <div class="upload-progress-spinner"></div>
                </div>
            </div>
        `;
    } else {
        tempContent = `
            <div class="chat-file-container">
                <div class="chat-file-icon"><i class="fas ${getFileIcon(fileName)}"></i></div>
                <div class="chat-file-info">
                    <span class="chat-file-name">${escapeHtml(fileName)}</span>
                    <span class="chat-file-size">${fileSize} · A enviar...</span>
                </div>
                <div class="upload-progress-spinner small"></div>
            </div>
        `;
    }
    
    tempDiv.innerHTML = `
        <div class="message-content">
            <div class="message-bubble-wrapper">
                <div class="message-bubble">
                    <div class="message-text">${tempContent}</div>
                    <div class="message-meta">
                        <span class="message-time">${new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'})}</span>
                        <span class="message-status sending"><i class="fas fa-clock"></i></span>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.appendChild(tempDiv);
        scrollToBottom();
        playSentSound();
    }
    
    // Fazer upload via fetch
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', fileType);
    formData.append('receiver_id', chatWithUserId);
    formData.append('message', fileType === 'file' ? `[file:${fileName}:${file.size}]` : '');
    
    try {
        const response = await fetch('api/upload_chat_file.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            console.log('✅ Arquivo enviado:', result.message.id);
            
            // Atualizar mensagem temporária
            const tempMsg = document.querySelector(`[data-temp-id="${tempId}"]`);
            if (tempMsg) {
                tempMsg.setAttribute('data-message-id', result.message.id);
                tempMsg.removeAttribute('data-temp-id');
                
                // Reconstruir conteúdo com dados reais
                const bubble = tempMsg.querySelector('.message-bubble');
                if (bubble) {
                    const fileUrl = result.file_info.url;
                    let realContent = '';
                    
                    if (fileType === 'image') {
                        realContent = `
                            <div class="chat-image-container loaded">
                                <img src="${fileUrl}" class="chat-image loaded" alt="Imagem" onclick="openImageViewer('${fileUrl}')">
                            </div>
                        `;
                    } else if (fileType === 'video') {
                        realContent = `
                            <div class="chat-video-container">
                                <div class="video-thumbnail-wrapper" onclick="loadAndPlayVideo(this, '${fileUrl}')">
                                    <div class="video-play-overlay">
                                        <i class="fas fa-play"></i>
                                    </div>
                                    <div class="video-placeholder-bg">
                                        <i class="fas fa-film"></i>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        realContent = `
                            <div class="chat-file-container" onclick="downloadFile('${fileUrl}', '${escapeHtml(fileName)}')">
                                <div class="chat-file-icon"><i class="fas ${getFileIcon(fileName)}"></i></div>
                                <div class="chat-file-info">
                                    <span class="chat-file-name">${escapeHtml(fileName)}</span>
                                    <span class="chat-file-size">${fileSize}</span>
                                </div>
                                <div class="chat-file-download"><i class="fas fa-download"></i></div>
                            </div>
                        `;
                    }
                    
                    bubble.querySelector('.message-text').innerHTML = realContent;
                    
                    const statusEl = bubble.querySelector('.message-status');
                    if (statusEl) updateMessageStatusIcon(statusEl, result.message.status || 'sent');
                    
                    // Adicionar botão de opções
                    const msgContent = tempMsg.querySelector('.message-content');
                    if (msgContent && !msgContent.querySelector('.msg-more-btn')) {
                        const moreBtn = document.createElement('button');
                        moreBtn.className = 'msg-more-btn';
                        moreBtn.innerHTML = '<i class="fas fa-ellipsis-v"></i>';
                        moreBtn.onclick = (e) => showMessageOptions(e, result.message.id, '', true);
                        msgContent.insertBefore(moreBtn, msgContent.firstChild);
                    }
                }
            }
            
            // Broadcast via socket
            socket.emit('broadcast_uploaded_message', {
                conversationId: currentConversationId,
                receiverId: chatWithUserId,
                message: result.message
            });
            
        } else {
            showToast('Erro: ' + (result.error || 'Falha no upload'));
            const tempMsg = document.querySelector(`[data-temp-id="${tempId}"]`);
            if (tempMsg) tempMsg.remove();
        }
    } catch (error) {
        console.error('Erro no upload:', error);
        showToast('Erro ao enviar arquivo');
        const tempMsg = document.querySelector(`[data-temp-id="${tempId}"]`);
        if (tempMsg) tempMsg.remove();
    }
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function getFileIcon(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    const iconMap = {
        'pdf': 'fa-file-pdf',
        'doc': 'fa-file-word', 'docx': 'fa-file-word', 'odt': 'fa-file-word', 'rtf': 'fa-file-word',
        'xls': 'fa-file-excel', 'xlsx': 'fa-file-excel', 'ods': 'fa-file-excel', 'csv': 'fa-file-csv',
        'ppt': 'fa-file-powerpoint', 'pptx': 'fa-file-powerpoint', 'odp': 'fa-file-powerpoint',
        'zip': 'fa-file-archive', 'rar': 'fa-file-archive', '7z': 'fa-file-archive',
        'txt': 'fa-file-alt',
        'html': 'fa-file-code', 'css': 'fa-file-code', 'js': 'fa-file-code', 
        'py': 'fa-file-code', 'java': 'fa-file-code', 'c': 'fa-file-code', 
        'cpp': 'fa-file-code', 'sql': 'fa-file-code', 'json': 'fa-file-code', 'xml': 'fa-file-code'
    };
    return iconMap[ext] || 'fa-file';
}

function loadAndPlayVideo(wrapper, videoUrl) {
    const container = wrapper.closest('.chat-video-container');
    if (!container) return;
    
    // Parar qualquer vídeo já em reprodução
    stopAllChatMedia();
    
    // Replace the thumbnail with a real video element
    const video = document.createElement('video');
    video.src = videoUrl;
    video.className = 'chat-video';
    video.controls = true;
    video.preload = 'auto';
    video.autoplay = true;
    
    // Clear the container and insert the video
    container.innerHTML = '';
    container.appendChild(video);
    
    video.play().catch(() => {
        // Autoplay blocked - user can use controls
    });
}

// Parar todos os vídeos e áudios em reprodução no chat
function stopAllChatMedia() {
    // Parar todos os vídeos
    document.querySelectorAll('.chat-video, .chat-video-container video').forEach(video => {
        if (video && !video.paused) {
            video.pause();
            video.currentTime = 0;
        }
    });
    
    // Parar todos os áudios
    document.querySelectorAll('.audio-play-btn.playing').forEach(btn => {
        if (btn._audio) {
            btn._audio.pause();
            btn._audio.currentTime = 0;
        }
        btn.innerHTML = '<i class="fas fa-play"></i>';
        btn.classList.remove('playing');
    });
}

function openImageViewer(url) {
    const overlay = document.createElement('div');
    overlay.className = 'image-viewer-overlay';
    overlay.innerHTML = `
        <div class="image-viewer-content">
            <button class="image-viewer-close" onclick="this.closest('.image-viewer-overlay').remove()">
                <i class="fas fa-times"></i>
            </button>
            <img src="${url}" class="image-viewer-img" alt="Imagem">
            <a href="${url}" download class="image-viewer-download">
                <i class="fas fa-download"></i> Descarregar
            </a>
        </div>
    `;
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) overlay.remove();
    });
    document.body.appendChild(overlay);
}

function downloadFile(url, filename) {
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || '';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

// ========================================
// EMOJI PICKER
// ========================================

const EMOJI_CATEGORIES = {
    recent: { icon: '🕐', label: 'Recentes', emojis: [] },
    smileys: { icon: '😀', label: 'Smileys', emojis: [
        '😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩',
        '😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🫡',
        '🤐','🤨','😐','😑','😶','🫥','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴',
        '😷','🤒','🤕','🤢','🤮','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐',
        '😕','🫤','😟','🙁','😮','😯','😲','😳','🥺','🥹','😦','😧','😨','😰','😥','😢',
        '😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀',
        '☠️','💩','🤡','👹','👺','👻','👽','👾','🤖'
    ]},
    gestures: { icon: '👋', label: 'Gestos', emojis: [
        '👋','🤚','🖐️','✋','🖖','🫱','🫲','🫳','🫴','👌','🤌','🤏','✌️','🤞','🫰','🤟',
        '🤘','🤙','👈','👉','👆','🖕','👇','☝️','🫵','👍','👎','✊','👊','🤛','🤜','👏',
        '🙌','🫶','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦾','🦿','🦵','🦶','👂','🦻',
        '👃','🧠','🫀','🫁','🦷','🦴','👀','👁️','👅','👄','🫦'
    ]},
    people: { icon: '👤', label: 'Pessoas', emojis: [
        '👶','👧','🧒','👦','👩','🧑','👨','👩‍🦱','🧑‍🦱','👨‍🦱','👩‍🦰','🧑‍🦰','👨‍🦰','👱‍♀️','👱','👱‍♂️',
        '👩‍🦳','🧑‍🦳','👨‍🦳','👩‍🦲','🧑‍🦲','👨‍🦲','🧔‍♀️','🧔','🧔‍♂️','👵','🧓','👴','👲','👳‍♀️','👳','👳‍♂️',
        '🧕','👮‍♀️','👮','👮‍♂️','👷‍♀️','👷','👷‍♂️','💂‍♀️','💂','💂‍♂️','🕵️‍♀️','🕵️','🕵️‍♂️','👩‍⚕️','🧑‍⚕️','👨‍⚕️',
        '👩‍🌾','🧑‍🌾','👨‍🌾','👩‍🍳','🧑‍🍳','👨‍🍳','👩‍🎓','🧑‍🎓','👨‍🎓','👩‍🎤','🧑‍🎤','👨‍🎤','👩‍🏫','🧑‍🏫','👨‍🏫'
    ]},
    animals: { icon: '🐶', label: 'Animais', emojis: [
        '🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐻‍❄️','🐨','🐯','🦁','🐮','🐷','🐽','🐸',
        '🐵','🙈','🙉','🙊','🐒','🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺',
        '🐗','🐴','🦄','🐝','🪱','🐛','🦋','🐌','🐞','🐜','🪰','🪲','🪳','🦟','🦗','🕷️',
        '🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋',
        '🦈','🐊','🐅','🐆','🦓','🦍','🦧','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🦬'
    ]},
    food: { icon: '🍔', label: 'Comida', emojis: [
        '🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥',
        '🥝','🍅','🥑','🥦','🥬','🥒','🌽','🥕','🫒','🧄','🧅','🥔','🍠','🥐','🥯','🍞',
        '🥖','🥨','🧀','🥚','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕',
        '🫓','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘','🫕','🥫','🍝','🍜','🍲','🍛','🍣',
        '🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥠','🥮','🍢','🍡','🍧','🍨','🍦','🥧',
        '🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍩','🍪','🌰','🥜','🫘','🍯'
    ]},
    activities: { icon: '⚽', label: 'Atividades', emojis: [
        '⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍',
        '🏏','🪃','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸️','🥌',
        '🎿','⛷️','🏂','🪂','🏋️','🤸','🤺','⛹️','🤾','🏌️','🏇','🧘','🏄','🏊','🤽','🚣',
        '🧗','🚴','🚵','🎖️','🏆','🥇','🥈','🥉','🏅','🎪','🎭','🎨','🎬','🎤','🎧','🎼',
        '🎹','🥁','🪘','🎷','🎺','🪗','🎸','🪕','🎻','🎲','♟️','🎯','🎳','🎮','🕹️'
    ]},
    travel: { icon: '✈️', label: 'Viagem', emojis: [
        '🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🛵','🏍️',
        '🛺','🚲','🛴','🚏','🛞','🚨','🚔','🚍','🚘','🚖','🛩️','✈️','🛫','🛬','🪂','💺',
        '🚀','🛸','🚁','🛶','⛵','🚤','🛥️','🛳️','⛴️','🚢','⚓','🪝','⛽','🚧','🚦','🚥',
        '🗺️','🗿','🗽','🗼','🏰','🏯','🏟️','🎡','🎢','🎠','⛲','⛱️','🏖️','🏝️','🏜️','🌋',
        '⛰️','🏔️','🗻','🏕️','🛖','🏠','🏡','🏘️','🏚️','🏗️','🏢','🏭','🏣','🏤','🏥','🏦'
    ]},
    objects: { icon: '💡', label: 'Objetos', emojis: [
        '⌚','📱','💻','⌨️','🖥️','🖨️','🖱️','🖲️','💽','💾','💿','📀','📷','📸','📹','🎥',
        '📽️','🎞️','📞','☎️','📟','📠','📺','📻','🎙️','🎚️','🎛️','🧭','⏱️','⏲️','⏰','🕰️',
        '💰','🪙','💴','💵','💶','💷','💳','💎','⚖️','🪜','🧰','🪛','🔧','🔨','⚒️','🛠️',
        '⛏️','🪚','🔩','⚙️','🪤','🧱','⛓️','🧲','🔫','💣','🪓','🔪','🗡️','⚔️','🛡️','🚬',
        '⚰️','⚱️','🏺','🔮','📿','🧿','🪬','💈','⚗️','🔭','🔬','🕳️','🩹','🩺','🩻','🩼',
        '💊','💉','🧬','🦠','🧫','🧪','🌡️','🧹','🪠','🧺','🧻','🚽','🚰','🚿','🛁','🛀',
        '🪥','🪒','🧴','🧷','🧹','🧬'
    ]},
    symbols: { icon: '❤️', label: 'Símbolos', emojis: [
        '❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖',
        '💘','💝','💟','☮️','✝️','☪️','🕉️','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈',
        '♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️',
        '📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹',
        '🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️',
        '🚷','🚱','🚳','🚯','🚭','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸',
        '🔱','⚜️','🔰','♻️','✅','🈯','💹','❇️','✳️','❎','🌐','💠','Ⓜ️','🌀','💤','🏧',
        '🚾','♿','🅿️','🛗','🈳','🈂️','🛂','🛃','🛄','🛅','🚹','🚺','🚼','⚧️','🚻','🚮',
        '🎦','📶','🈁','🔣','ℹ️','🔤','🔡','🔠','🆖','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣',
        '2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','🔢','#️⃣','*️⃣','⏏️','▶️','⏸️','⏯️',
        '⏹️','⏺️','⏭️','⏮️','⏩','⏪','🔀','🔁','🔂','🔼','🔽','⏫','⏬','➡️','⬅️','⬆️',
        '⬇️','↗️','↘️','↙️','↖️','↕️','↔️','↩️','↪️','⤴️','⤵️','🔃','🔄','🔙','🔚','🔛',
        '🔜','🔝'
    ]},
    flags: { icon: '🏳️', label: 'Bandeiras', emojis: [
        '🏳️','🏴','🏁','🚩','🏳️‍🌈','🏳️‍⚧️','🇧🇷','🇺🇸','🇵🇹','🇪🇸','🇫🇷','🇩🇪','🇮🇹','🇬🇧','🇯🇵','🇰🇷',
        '🇨🇳','🇮🇳','🇷🇺','🇲🇽','🇦🇷','🇨🇴','🇨🇱','🇵🇪','🇻🇪','🇪🇨','🇧🇴','🇵🇾','🇺🇾','🇨🇦','🇦🇺','🇳🇿'
    ]}
};

let emojiPickerOpen = false;
let recentEmojis = JSON.parse(localStorage.getItem('recentEmojis') || '[]');

// Preencher recentes
EMOJI_CATEGORIES.recent.emojis = recentEmojis;

function showEmojiPicker() {
    if (emojiPickerOpen) {
        closeEmojiPicker();
        return;
    }
    
    // Fechar attach menu se estiver aberto
    const attachMenu = document.querySelector('.attach-menu');
    if (attachMenu) attachMenu.remove();
    
    const chatMain = document.querySelector('.chat-main');
    if (!chatMain) return;
    
    // Remover picker anterior se existir
    const existingPicker = document.querySelector('.emoji-picker');
    if (existingPicker) existingPicker.remove();
    
    // Atualizar recentes
    EMOJI_CATEGORIES.recent.emojis = recentEmojis;
    
    const picker = document.createElement('div');
    picker.className = 'emoji-picker';
    
    // Categorias (tabs)
    const categories = Object.keys(EMOJI_CATEGORIES).filter(k => 
        k === 'recent' ? recentEmojis.length > 0 : true
    );
    
    const headerHTML = `
        <div class="emoji-picker-header">
            ${categories.map(key => `
                <button class="emoji-category-btn ${key === (recentEmojis.length > 0 ? 'recent' : 'smileys') ? 'active' : ''}" 
                        data-category="${key}" 
                        title="${EMOJI_CATEGORIES[key].label}">
                    ${EMOJI_CATEGORIES[key].icon}
                </button>
            `).join('')}
        </div>
    `;
    
    // Busca
    const searchHTML = `
        <div class="emoji-search-wrapper">
            <input type="text" class="emoji-search" placeholder="Buscar emoji..." autocomplete="off">
        </div>
    `;
    
    // Corpo (emojis)
    const defaultCategory = recentEmojis.length > 0 ? 'recent' : 'smileys';
    const bodyHTML = `<div class="emoji-picker-body">${renderEmojiCategory(defaultCategory)}</div>`;
    
    // Footer (preview)
    const footerHTML = `
        <div class="emoji-picker-footer">
            <span class="emoji-preview"></span>
            <span class="emoji-preview-name"></span>
        </div>
    `;
    
    picker.innerHTML = headerHTML + searchHTML + bodyHTML + footerHTML;
    chatMain.appendChild(picker);
    emojiPickerOpen = true;
    
    // Atualizar botão
    const emojiBtn = chatMain.querySelector('.emoji-btn');
    if (emojiBtn) emojiBtn.style.color = '#3b82f6';
    
    // Eventos das tabs
    picker.querySelectorAll('.emoji-category-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const cat = btn.dataset.category;
            picker.querySelectorAll('.emoji-category-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            const body = picker.querySelector('.emoji-picker-body');
            body.innerHTML = renderEmojiCategory(cat);
            attachEmojiItemEvents(picker);
            
            // Limpar busca
            const searchInput = picker.querySelector('.emoji-search');
            if (searchInput) searchInput.value = '';
        });
    });
    
    // Evento de busca
    const searchInput = picker.querySelector('.emoji-search');
    let searchDebounce = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => {
            const query = searchInput.value.trim().toLowerCase();
            const body = picker.querySelector('.emoji-picker-body');
            
            if (!query) {
                // Voltar à categoria ativa
                const activeBtn = picker.querySelector('.emoji-category-btn.active');
                const cat = activeBtn ? activeBtn.dataset.category : 'smileys';
                body.innerHTML = renderEmojiCategory(cat);
            } else {
                body.innerHTML = renderEmojiSearch(query);
            }
            attachEmojiItemEvents(picker);
        }, 150);
    });
    
    // Prevenir que feche ao clicar dentro do picker
    picker.addEventListener('click', (e) => e.stopPropagation());
    
    // Attach events iniciais
    attachEmojiItemEvents(picker);
    
    // Fechar ao clicar fora
    setTimeout(() => {
        document.addEventListener('click', closeEmojiPickerOnClickOutside);
    }, 10);
}

function renderEmojiCategory(categoryKey) {
    const cat = EMOJI_CATEGORIES[categoryKey];
    if (!cat || cat.emojis.length === 0) {
        return '<div class="emoji-no-results">Nenhum emoji recente</div>';
    }
    
    return `
        <div class="emoji-group-label">${cat.label}</div>
        <div class="emoji-grid">
            ${cat.emojis.map(e => `<button class="emoji-item" data-emoji="${e}">${e}</button>`).join('')}
        </div>
    `;
}

function renderAllCategories() {
    let html = '';
    for (const [key, cat] of Object.entries(EMOJI_CATEGORIES)) {
        if (key === 'recent' && cat.emojis.length === 0) continue;
        html += `
            <div class="emoji-group-label" data-group="${key}">${cat.label}</div>
            <div class="emoji-grid">
                ${cat.emojis.map(e => `<button class="emoji-item" data-emoji="${e}">${e}</button>`).join('')}
            </div>
        `;
    }
    return html;
}

function renderEmojiSearch(query) {
    const allEmojis = [];
    // Busca simples: mostrar todos os emojis que possuem o texto no nome da categoria
    for (const [key, cat] of Object.entries(EMOJI_CATEGORIES)) {
        if (key === 'recent') continue;
        if (cat.label.toLowerCase().includes(query)) {
            allEmojis.push(...cat.emojis);
        }
    }
    
    // Se não achou por categoria, buscar nos emojis (para busca por emoji literal)
    if (allEmojis.length === 0) {
        for (const [key, cat] of Object.entries(EMOJI_CATEGORIES)) {
            if (key === 'recent') continue;
            for (const emoji of cat.emojis) {
                if (emoji.includes(query)) {
                    allEmojis.push(emoji);
                }
            }
        }
    }
    
    // Busca mais esperta: mapear termos comuns em PT-BR
    if (allEmojis.length === 0) {
        const searchMap = {
            'corac': 'symbols', 'amor': 'symbols', 'love': 'symbols',
            'riso': 'smileys', 'rir': 'smileys', 'feliz': 'smileys', 'triste': 'smileys',
            'raiva': 'smileys', 'chora': 'smileys', 'medo': 'smileys',
            'gato': 'animals', 'cao': 'animals', 'cachorro': 'animals', 'dog': 'animals', 'cat': 'animals',
            'comida': 'food', 'pizza': 'food', 'hambur': 'food', 'fruta': 'food',
            'bola': 'activities', 'jogo': 'activities', 'music': 'activities',
            'carro': 'travel', 'aviao': 'travel', 'casa': 'travel',
            'bandeira': 'flags', 'brasil': 'flags', 'flag': 'flags',
            'mao': 'gestures', 'polegar': 'gestures', 'dedo': 'gestures',
            'fone': 'objects', 'celular': 'objects', 'computador': 'objects'
        };
        
        for (const [term, catKey] of Object.entries(searchMap)) {
            if (term.includes(query) || query.includes(term)) {
                allEmojis.push(...EMOJI_CATEGORIES[catKey].emojis);
                break;
            }
        }
    }
    
    if (allEmojis.length === 0) {
        return '<div class="emoji-no-results">Nenhum emoji encontrado</div>';
    }
    
    // Remover duplicatas
    const unique = [...new Set(allEmojis)];
    
    return `
        <div class="emoji-group-label">Resultados</div>
        <div class="emoji-grid">
            ${unique.map(e => `<button class="emoji-item" data-emoji="${e}">${e}</button>`).join('')}
        </div>
    `;
}

function attachEmojiItemEvents(picker) {
    picker.querySelectorAll('.emoji-item').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const emoji = btn.dataset.emoji;
            insertEmoji(emoji);
        });
        
        btn.addEventListener('mouseenter', () => {
            const preview = picker.querySelector('.emoji-preview');
            const previewName = picker.querySelector('.emoji-preview-name');
            if (preview) preview.textContent = btn.dataset.emoji;
            if (previewName) previewName.textContent = '';
        });
    });
}

function insertEmoji(emoji) {
    const input = document.getElementById('messageInput');
    if (!input) return;
    
    // Inserir na posição do cursor
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const text = input.value;
    input.value = text.substring(0, start) + emoji + text.substring(end);
    
    // Mover cursor para depois do emoji
    const newPos = start + emoji.length;
    input.setSelectionRange(newPos, newPos);
    input.focus();
    
    // Disparar input event para ajustar altura e exibir botão enviar
    input.dispatchEvent(new Event('input', { bubbles: true }));
    
    // Salvar nos recentes
    addToRecentEmojis(emoji);
}

function addToRecentEmojis(emoji) {
    // Remover se já existir
    recentEmojis = recentEmojis.filter(e => e !== emoji);
    // Adicionar no início
    recentEmojis.unshift(emoji);
    // Limitar a 32
    if (recentEmojis.length > 32) recentEmojis = recentEmojis.slice(0, 32);
    // Salvar
    localStorage.setItem('recentEmojis', JSON.stringify(recentEmojis));
    EMOJI_CATEGORIES.recent.emojis = recentEmojis;
}

function closeEmojiPicker() {
    const picker = document.querySelector('.emoji-picker');
    if (picker) picker.remove();
    emojiPickerOpen = false;
    
    const emojiBtn = document.querySelector('.chat-main .emoji-btn');
    if (emojiBtn) emojiBtn.style.color = '';
    
    document.removeEventListener('click', closeEmojiPickerOnClickOutside);
}

function closeEmojiPickerOnClickOutside(e) {
    const picker = document.querySelector('.emoji-picker');
    const emojiBtn = document.querySelector('.emoji-btn');
    if (picker && !picker.contains(e.target) && (!emojiBtn || !emojiBtn.contains(e.target))) {
        closeEmojiPicker();
    }
}

function showStickerPicker() {
    alert('Seletor de stickers será implementado em breve!');
}

// Gravação de áudio
let isRecordingVoice = false;
let mediaRecorder = null;
let audioChunks = [];
let recordingStartTime = null;
let recordingTimerInterval = null;

function toggleVoiceRecording() {
    if (isRecordingVoice) {
        stopAndSendVoiceRecording();
    } else {
        startVoiceRecording();
    }
}

async function startVoiceRecording() {
    if (!chatWithUserId) {
        showToast('Selecione uma conversa primeiro', 'error');
        return;
    }
    
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        
        // Detectar formato suportado
        const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') 
            ? 'audio/webm;codecs=opus' 
            : MediaRecorder.isTypeSupported('audio/webm') 
                ? 'audio/webm' 
                : 'audio/ogg';
        
        mediaRecorder = new MediaRecorder(stream, { mimeType });
        audioChunks = [];
        
        mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) audioChunks.push(e.data);
        };
        
        mediaRecorder.onstop = () => {
            // Parar todas as tracks do microfone
            stream.getTracks().forEach(track => track.stop());
        };
        
        mediaRecorder.start();
        isRecordingVoice = true;
        recordingStartTime = Date.now();
        
        // Mostrar UI de gravação
        showRecordingUI();
        
        // Timer
        recordingTimerInterval = setInterval(updateRecordingTimer, 1000);
        
        console.log('🎤 Gravação de áudio iniciada');
        
    } catch (err) {
        console.error('Erro ao aceder ao microfone:', err);
        if (err.name === 'NotAllowedError') {
            showToast('Permissão de microfone negada. Ative nas configurações do navegador.', 'error');
        } else {
            showToast('Não foi possível aceder ao microfone', 'error');
        }
    }
}

function showRecordingUI() {
    const chatInput = document.querySelector('.chat-input');
    if (!chatInput) return;
    
    // Guardar conteúdo original
    chatInput.dataset.originalHTML = chatInput.innerHTML;
    
    chatInput.innerHTML = `
        <div class="recording-overlay">
            <button class="cancel-recording-btn" onclick="cancelVoiceRecording()" title="Cancelar">
                <i class="fas fa-trash"></i>
            </button>
            <div class="recording-indicator">
                <span class="recording-dot"></span>
                <span class="recording-timer" id="recordingTimer">0:00</span>
            </div>
            <button class="send-recording-btn" onclick="stopAndSendVoiceRecording()" title="Enviar áudio">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    `;
}

function hideRecordingUI() {
    const chatInput = document.querySelector('.chat-input');
    if (!chatInput || !chatInput.dataset.originalHTML) return;
    
    chatInput.innerHTML = chatInput.dataset.originalHTML;
    delete chatInput.dataset.originalHTML;
    
    // Limpar flags de listeners (os elementos são novos, precisam de novos listeners)
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    if (messageInput) delete messageInput.dataset.listenersAdded;
    if (sendBtn) delete sendBtn.dataset.listenersAdded;
    
    // Re-setup do input de mensagem
    setupMessageInput();
    toggleSendButton();
}

function updateRecordingTimer() {
    const timer = document.getElementById('recordingTimer');
    if (!timer || !recordingStartTime) return;
    
    const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
    const minutes = Math.floor(elapsed / 60);
    const seconds = elapsed % 60;
    timer.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    
    // Limite de 5 minutos
    if (elapsed >= 300) {
        stopAndSendVoiceRecording();
    }
}

function cancelVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    
    clearInterval(recordingTimerInterval);
    isRecordingVoice = false;
    mediaRecorder = null;
    audioChunks = [];
    recordingStartTime = null;
    
    hideRecordingUI();
    console.log('❌ Gravação cancelada');
}

function stopAndSendVoiceRecording() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    
    clearInterval(recordingTimerInterval);
    const duration = Math.floor((Date.now() - recordingStartTime) / 1000);
    
    mediaRecorder.onstop = async () => {
        // Parar microfone
        mediaRecorder.stream.getTracks().forEach(track => track.stop());
        
        isRecordingVoice = false;
        hideRecordingUI();
        
        if (audioChunks.length === 0) {
            showToast('Nenhum áudio gravado', 'error');
            return;
        }
        
        // Criar blob do áudio
        const mimeType = mediaRecorder.mimeType;
        const audioBlob = new Blob(audioChunks, { type: mimeType });
        audioChunks = [];
        
        // Ignorar gravações muito curtas (< 1 segundo)
        if (duration < 1) {
            showToast('Áudio muito curto', 'error');
            return;
        }
        
        console.log(`🎤 Áudio gravado: ${duration}s, ${(audioBlob.size / 1024).toFixed(1)}KB`);
        
        // Enviar via upload
        await uploadVoiceMessage(audioBlob, duration, mimeType);
    };
    
    mediaRecorder.stop();
}

async function uploadVoiceMessage(audioBlob, duration, mimeType) {
    // Determinar extensão pelo mime type
    const ext = mimeType.includes('webm') ? 'webm' : mimeType.includes('ogg') ? 'ogg' : 'mp3';
    
    const formData = new FormData();
    formData.append('file', audioBlob, `voice_${Date.now()}.${ext}`);
    formData.append('type', 'audio');
    formData.append('receiver_id', chatWithUserId);
    formData.append('message', `🎤 Áudio (${formatDuration(duration)})`);
    
    // Adicionar mensagem temporária no chat
    const tempId = ++tempMessageId;
    const tempDiv = document.createElement('div');
    tempDiv.className = 'message sent';
    tempDiv.setAttribute('data-temp-id', tempId);
    tempDiv.innerHTML = `
        <div class="message-content">
            <div class="message-bubble-wrapper">
                <div class="message-bubble">
                    <div class="message-text">
                        <div class="audio-message">
                            <button class="audio-play-btn" disabled>
                                <i class="fas fa-spinner fa-spin"></i>
                            </button>
                            <div class="audio-wave">${generateWaveBars(30)}</div>
                            <span class="audio-duration">${formatDuration(duration)}</span>
                        </div>
                    </div>
                    <div class="message-meta">
                        <span class="message-time">${new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'})}</span>
                        <span class="message-status sending"><i class="fas fa-clock"></i></span>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.appendChild(tempDiv);
        scrollToBottom();
        playSentSound();
    }
    
    try {
        const response = await fetch('api/upload_chat_file.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            console.log('✅ Áudio enviado com sucesso:', result.message.id);
            
            // Atualizar mensagem temporária com dados reais
            const tempMsg = document.querySelector(`[data-temp-id="${tempId}"]`);
            if (tempMsg) {
                tempMsg.setAttribute('data-message-id', result.message.id);
                tempMsg.removeAttribute('data-temp-id');
                
                const statusEl = tempMsg.querySelector('.message-status');
                if (statusEl) {
                    updateMessageStatusIcon(statusEl, result.message.status || 'sent');
                }
                
                // Atualizar botão de play com áudio real
                const playBtn = tempMsg.querySelector('.audio-play-btn');
                if (playBtn) {
                    playBtn.disabled = false;
                    playBtn.innerHTML = '<i class="fas fa-play"></i>';
                    playBtn.onclick = () => playAudioMessage(playBtn, result.file_info.url);
                }
            }
            
            // Notificar via socket para o receptor ver em tempo real
            // NÃO usar send_message (que criaria duplicata no DB)
            socket.emit('broadcast_uploaded_message', {
                conversationId: currentConversationId,
                receiverId: chatWithUserId,
                message: result.message
            });
            
        } else {
            showToast('Erro ao enviar áudio: ' + result.error, 'error');
            // Remover mensagem temporária
            const tempMsg = document.querySelector(`[data-temp-id="${tempId}"]`);
            if (tempMsg) tempMsg.remove();
        }
    } catch (error) {
        console.error('Erro no upload do áudio:', error);
        showToast('Erro ao enviar áudio', 'error');
        const tempMsg = document.querySelector(`[data-temp-id="${tempId}"]`);
        if (tempMsg) tempMsg.remove();
    }
}

function generateWaveBars(count) {
    let html = '';
    for (let i = 0; i < count; i++) {
        const h = Math.floor(Math.random() * 20) + 4;
        html += `<div class="wave-bar" style="height: ${h}px;"></div>`;
    }
    return html;
}

function formatDuration(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

function playAudioMessage(btn, url) {
    // Parar qualquer áudio em reprodução
    const currentlyPlaying = document.querySelector('.audio-play-btn.playing');
    if (currentlyPlaying && currentlyPlaying !== btn) {
        const prevAudio = currentlyPlaying._audio;
        if (prevAudio) { prevAudio.pause(); prevAudio.currentTime = 0; }
        currentlyPlaying.innerHTML = '<i class="fas fa-play"></i>';
        currentlyPlaying.classList.remove('playing');
    }
    
    // Toggle play/pause
    if (btn._audio && !btn._audio.paused) {
        btn._audio.pause();
        btn.innerHTML = '<i class="fas fa-play"></i>';
        btn.classList.remove('playing');
        return;
    }
    
    const audio = btn._audio || new Audio(url);
    btn._audio = audio;
    
    btn.innerHTML = '<i class="fas fa-pause"></i>';
    btn.classList.add('playing');
    
    // Atualizar barras de wave durante reprodução
    const waveContainer = btn.closest('.audio-message')?.querySelector('.audio-wave');
    const bars = waveContainer ? waveContainer.querySelectorAll('.wave-bar') : [];
    
    audio.ontimeupdate = () => {
        if (bars.length > 0 && audio.duration) {
            const progress = audio.currentTime / audio.duration;
            const playedBars = Math.floor(progress * bars.length);
            bars.forEach((bar, i) => {
                bar.classList.toggle('played', i < playedBars);
            });
        }
    };
    
    audio.onended = () => {
        btn.innerHTML = '<i class="fas fa-play"></i>';
        btn.classList.remove('playing');
        bars.forEach(bar => bar.classList.remove('played'));
    };
    
    audio.onerror = () => {
        showToast('Erro ao reproduzir áudio', 'error');
        btn.innerHTML = '<i class="fas fa-play"></i>';
        btn.classList.remove('playing');
    };
    
    audio.play();
}

// ========================================
// MOBILE: SWIPE-TO-REPLY & LONG-PRESS MENU
// ========================================

const isMobile = () => window.innerWidth <= 768 || 'ontouchstart' in window;

// --- Swipe-to-reply ---
let swipeState = {
    active: false,
    startX: 0,
    startY: 0,
    messageEl: null,
    direction: null, // 'left' or 'right'
    triggered: false
};

const SWIPE_THRESHOLD = 60;

function initMobileGestures() {
    const chatMessages = document.getElementById('chatMessages');
    if (!chatMessages || chatMessages.dataset.gesturesInit) return;
    chatMessages.dataset.gesturesInit = 'true';
    
    chatMessages.addEventListener('touchstart', handleTouchStart, { passive: true });
    chatMessages.addEventListener('touchmove', handleTouchMove, { passive: false });
    chatMessages.addEventListener('touchend', handleTouchEnd, { passive: true });
}

function handleTouchStart(e) {
    if (!isMobile()) return;
    
    const messageEl = e.target.closest('.message');
    if (!messageEl) return;
    
    const touch = e.touches[0];
    swipeState = {
        active: true,
        startX: touch.clientX,
        startY: touch.clientY,
        messageEl: messageEl,
        direction: null,
        triggered: false
    };
    
    // Long-press detection
    messageEl._longPressTimer = setTimeout(() => {
        if (swipeState.active && !swipeState.direction) {
            swipeState.active = false;
            triggerLongPress(messageEl, touch);
        }
    }, 500);
}

function handleTouchMove(e) {
    if (!swipeState.active || !swipeState.messageEl) return;
    
    const touch = e.touches[0];
    const dx = touch.clientX - swipeState.startX;
    const dy = touch.clientY - swipeState.startY;
    
    // Cancel long-press if moving
    if (Math.abs(dx) > 10 || Math.abs(dy) > 10) {
        clearTimeout(swipeState.messageEl._longPressTimer);
    }
    
    // Determine direction lock
    if (!swipeState.direction) {
        if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 10) {
            // Vertical scroll — cancel swipe
            swipeState.active = false;
            return;
        }
        if (Math.abs(dx) > 10) {
            const isSent = swipeState.messageEl.classList.contains('sent');
            // Sent messages: swipe left (negative dx). Received: swipe right (positive dx)
            if ((isSent && dx < 0) || (!isSent && dx > 0)) {
                swipeState.direction = isSent ? 'left' : 'right';
            } else {
                swipeState.active = false;
                return;
            }
        } else {
            return;
        }
    }
    
    e.preventDefault();
    
    // Limit swipe distance
    const maxSwipe = 80;
    const absDx = Math.min(Math.abs(dx), maxSwipe);
    const sign = swipeState.direction === 'left' ? -1 : 1;
    const translateX = sign * absDx;
    
    swipeState.messageEl.style.transform = `translateX(${translateX}px)`;
    swipeState.messageEl.classList.toggle('swiping', absDx > 20);
    
    // Haptic feedback at threshold
    if (absDx >= SWIPE_THRESHOLD && !swipeState.triggered) {
        swipeState.triggered = true;
        try { navigator.vibrate?.(15); } catch(e) {}
    }
}

function handleTouchEnd(e) {
    if (!swipeState.messageEl) return;
    
    clearTimeout(swipeState.messageEl._longPressTimer);
    
    const el = swipeState.messageEl;
    el.style.transform = '';
    el.classList.remove('swiping');
    
    if (swipeState.triggered) {
        // Trigger reply
        const messageId = el.getAttribute('data-message-id');
        if (messageId) {
            replyToMessageFromMenu(parseInt(messageId));
        }
    }
    
    swipeState = { active: false, startX: 0, startY: 0, messageEl: null, direction: null, triggered: false };
}

// --- Long-press context menu ---
function triggerLongPress(messageEl, touch) {
    try { navigator.vibrate?.(30); } catch(e) {}
    
    messageEl.classList.add('longpress-active');
    
    const messageId = messageEl.getAttribute('data-message-id');
    if (!messageId) {
        messageEl.classList.remove('longpress-active');
        return;
    }
    
    const isSent = messageEl.classList.contains('sent');
    const messageText = messageEl.querySelector('.message-text')?.textContent?.trim() || '';
    
    showLongPressMenu(parseInt(messageId), messageText, isSent, messageEl);
}

function showLongPressMenu(messageId, content, isSent, messageEl) {
    // Remove any existing menus
    closeLongPressMenu();
    closeMessageOptions();
    closeReactionPicker();
    
    const overlay = document.createElement('div');
    overlay.className = 'longpress-overlay';
    overlay.onclick = (e) => {
        if (e.target === overlay) closeLongPressMenu();
    };
    
    let extraBtns = '';
    if (isSent) {
        // Verificar se a mensagem tem menos de 5 minutos (pode editar)
        const msgTimeText = messageEl?.querySelector('.message-time')?.textContent;
        let canEdit = false;
        if (msgTimeText) {
            const today = new Date();
            const [h, m] = msgTimeText.split(':').map(Number);
            const msgDate = new Date(today.getFullYear(), today.getMonth(), today.getDate(), h, m);
            canEdit = (Date.now() - msgDate.getTime()) < 300000;
        }
        
        if (canEdit) {
            extraBtns += `
                <button onclick="closeLongPressMenu(); startEditMessage(${messageId})">
                    <i class="fas fa-pen"></i> Editar
                </button>
            `;
        }
        extraBtns += `
            <button class="delete-action" onclick="closeLongPressMenu(); deleteMessage(${messageId}, 'for_all')">
                <i class="fas fa-trash"></i> Apagar para todos
            </button>
            <button class="delete-action" onclick="closeLongPressMenu(); deleteMessage(${messageId}, 'for_me')">
                <i class="fas fa-trash-alt"></i> Apagar para mim
            </button>
        `;
    } else {
        extraBtns = `
            <button class="delete-action" onclick="closeLongPressMenu(); deleteMessage(${messageId}, 'for_me')">
                <i class="fas fa-trash-alt"></i> Apagar para mim
            </button>
        `;
    }
    
    overlay.innerHTML = `
        <div class="longpress-menu">
            <div class="longpress-reactions">
                ${REACTION_EMOJIS.map(emoji => 
                    `<button onclick="closeLongPressMenu(); addReaction(${messageId}, '${emoji}')">${emoji}</button>`
                ).join('')}
            </div>
            <div class="longpress-actions">
                <button onclick="closeLongPressMenu(); replyToMessageFromMenu(${messageId})">
                    <i class="fas fa-reply"></i> Responder
                </button>
                <button onclick="closeLongPressMenu(); copyMessageText(\`${content.replace(/`/g, '\\`').replace(/\\/g, '\\\\')}\`)">
                    <i class="fas fa-copy"></i> Copiar
                </button>
                <button onclick="closeLongPressMenu(); forwardMessage(${messageId})">
                    <i class="fas fa-share"></i> Reencaminhar
                </button>
                ${extraBtns}
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
}

function closeLongPressMenu() {
    const overlay = document.querySelector('.longpress-overlay');
    if (overlay) overlay.remove();
    
    document.querySelectorAll('.message.longpress-active').forEach(el => {
        el.classList.remove('longpress-active');
    });
}

// Re-init gestures when chat area is shown
const _origShowChatArea = showChatArea;
showChatArea = function() {
    _origShowChatArea();
    setTimeout(initMobileGestures, 100);
};

// Also init on first load
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(initMobileGestures, 500);
});

// Desktop: right-click on message bubble shows reaction picker
document.addEventListener('contextmenu', function(e) {
    const bubble = e.target.closest('.message-bubble');
    if (bubble && !isMobile()) {
        e.preventDefault();
        const msgEl = bubble.closest('.message');
        const messageId = msgEl?.getAttribute('data-message-id');
        if (messageId) {
            showReactionPicker(e, parseInt(messageId));
        }
    }
});

// ========================================
// FECHAR MENUS AO CLICAR FORA
// ========================================

document.addEventListener('click', function(e) {
    const attachMenu = document.getElementById('attachMenu');
    if (attachMenu && !e.target.closest('.attach-btn') && !e.target.closest('.attach-menu')) {
        attachMenu.style.display = 'none';
    }
});
