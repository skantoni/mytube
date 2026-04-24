<?php
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Página de origem (para voltar corretamente)
$from_page = isset($_GET['from']) ? $_GET['from'] : 'index';
$back_url = 'index.php';
if ($from_page === 'profile') {
    $back_url = 'profile.php';
} elseif ($from_page === 'perfil') {
    $back_url = isset($_GET['user_id']) ? 'perfil.php?id=' . intval($_GET['user_id']) : 'perfil.php';
}

// Pegar usuário do chat (se especificado na URL)
$chat_with_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$chat_user_info = null;

if ($chat_with_user_id) {
    $stmt = $pdo->prepare("SELECT id, username, full_name, profile_picture, is_verified FROM users WHERE id = ?");
    $stmt->execute([$chat_with_user_id]);
    $chat_user_info = $stmt->fetch();
    
    // Ajustar caminho da imagem
    if ($chat_user_info && !empty($chat_user_info['profile_picture'])) {
        if (!str_starts_with($chat_user_info['profile_picture'], 'http') && 
            !str_starts_with($chat_user_info['profile_picture'], 'assets/')) {
            $chat_user_info['profile_picture'] = 'assets/images/avatars/' . basename($chat_user_info['profile_picture']);
        }
    } else if ($chat_user_info) {
        $chat_user_info['profile_picture'] = 'assets/images/default-avatar.svg';
    }
}

// Pegar informações do usuário atual
$stmt = $pdo->prepare("SELECT username, full_name, profile_picture FROM users WHERE id = ?");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    <title>Chat - MyTube</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset('assets/css/chat.css'); ?>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            overflow: hidden;
        }
    </style>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <!-- CSRF Token para proteção de formulários AJAX -->
    <?php echo csrf_meta(); ?>
    <script src="<?php echo asset('assets/js/csrf.js'); ?>"></script>
</head>
<body>
    <div class="chat-container">
        <!-- Sidebar com lista de conversas -->
        <div class="chat-sidebar" id="chatSidebar">
            <div class="chat-sidebar-header">
                <button class="back-to-home-btn" onclick="window.location.href='<?php echo $back_url; ?>'">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h2>Mensagens</h2>
                <button class="friend-requests-btn" onclick="showFriendRequestsModal()" title="Pedidos de amizade">
                    <i class="fas fa-user-friends"></i>
                    <span class="friend-requests-badge" id="friendRequestsBadge" style="display:none;">0</span>
                </button>
                <button class="new-chat-btn" onclick="showNewChatModal()">
                    <i class="fas fa-edit"></i>
                </button>
            </div>

            <div class="chat-sidebar-presence">
                <div class="active-users-chip" data-active-users-count="chat">
                    <span class="active-users-dot"></span>
                    <span class="active-users-label">0 ativos no chat</span>
                </div>
            </div>
            
            <div class="chat-search">
                <input type="text" placeholder="Pesquisar conversas..." id="searchConversations">
                <i class="fas fa-search"></i>
            </div>
            
            <div class="conversations-list" id="conversationsList">
                <!-- Conversas carregadas via Socket.IO -->
                <div class="conversations-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Carregando conversas...</p>
                </div>
            </div>
        </div>
        
        <!-- Área principal do chat -->
        <div class="chat-main" id="chatMain">
            <?php if ($chat_user_info): ?>
                <!-- Header do chat -->
                <div class="chat-header">
                    <button class="back-btn" onclick="closeMobileChat()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <a class="chat-header-user" href="perfil.php?id=<?php echo $chat_with_user_id; ?>" style="text-decoration:none;cursor:pointer;">
                        <img src="<?php echo htmlspecialchars($chat_user_info['profile_picture'] ?: 'assets/images/default-avatar.svg'); ?>" 
                             alt="<?php echo htmlspecialchars($chat_user_info['username']); ?>">
                        <div class="chat-header-info">
                            <h3>
                                <span class="chat-header-name"><?php echo htmlspecialchars($chat_user_info['full_name'] ?: $chat_user_info['username']); ?></span>
                                <?php if (!empty($chat_user_info['is_verified'])): ?>
                                    <i class="fas fa-check-circle chat-verified-icon" aria-label="Verificado"></i>
                                <?php endif; ?>
                            </h3>
                            <span class="user-status" id="userStatus">
                                <span class="status-indicator" data-user-id="<?php echo $chat_with_user_id; ?>"></span>
                                <span class="status-text">Offline</span>
                            </span>
                            <span class="typing-indicator" id="typingIndicator" style="display: none;">
                                <i class="fas fa-circle"></i>
                                <i class="fas fa-circle"></i>
                                <i class="fas fa-circle"></i>
                                digitando...
                            </span>
                        </div>
                    </a>
                    <div class="chat-header-actions">
                        <button onclick="makeVideoCall(<?php echo $chat_with_user_id; ?>)">
                            <i class="fas fa-video"></i>
                        </button>
                        <button onclick="makeVoiceCall(<?php echo $chat_with_user_id; ?>)">
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
                
                <!-- Edit preview -->
                <div class="edit-preview" id="editPreview" style="display: none;">
                    <div class="edit-content">
                        <i class="fas fa-pen"></i>
                        <div class="edit-info">
                            <strong class="edit-label">A editar</strong>
                            <p class="edit-message-text"></p>
                        </div>
                    </div>
                    <button class="cancel-edit" onclick="cancelEdit()">
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
                            placeholder="Mete dica..." 
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
                
            <?php else: ?>
                <div class="no-chat-selected">
                    <i class="fas fa-comments"></i>
                    <h3>Selecione uma conversa</h3>
                    <p>Escolha uma conversa da lista ou inicie um novo chat</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal para novo chat -->
    <div class="modal" id="newChatModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Nova conversa</h3>
                <button onclick="closeNewChatModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <input type="text" id="searchUsers" placeholder="Pesquisar usuários..." oninput="searchUsers(this.value)">
                <div id="usersList" class="users-list"></div>
            </div>
        </div>
    </div>
    
    <script>
        // Configuração para o Socket.IO
        const currentUserId = <?php echo $current_user_id; ?>;
        const currentUsername = '<?php echo addslashes($current_user['username']); ?>';
        const currentFullName = '<?php echo addslashes($current_user['full_name'] ?: $current_user['username']); ?>';
        let chatWithUserId = <?php echo $chat_with_user_id ?? 'null'; ?>;
        let chatWithUsername = <?php echo $chat_user_info ? "'" . addslashes($chat_user_info['full_name'] ?: $chat_user_info['username']) . "'" : 'null'; ?>;
        let chatWithIsVerified = <?php echo $chat_user_info ? (!empty($chat_user_info['is_verified']) ? 'true' : 'false') : 'false'; ?>;
        const fromPage = '<?php echo $from_page; ?>';

        // Guardar userId no estado do histórico para que o botão voltar
        // do browser dispare popstate com o userId correto
        if (chatWithUserId) {
            history.replaceState({ userId: chatWithUserId }, '', window.location.href);
        }
        
        // Event delegation é feito no chat-socket.js (setupConversationClickListener)
    </script>
    
    <!-- Socket.IO Client -->
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    
    <!-- Chat Socket.IO Client -->
    <script src="<?php echo asset('assets/js/chat-socket.js'); ?>"></script>

    <!-- Modal de Pedidos de Amizade -->
    <div class="modal" id="friendRequestsModal" style="display:none;" onclick="if(event.target===this)closeFriendRequestsModal()">
        <div class="modal-content friend-requests-modal">
            <div class="modal-header">
                <h3><i class="fas fa-user-friends"></i> Pedidos de Amizade</h3>
                <button onclick="closeFriendRequestsModal()">&times;</button>
            </div>
            <div class="modal-tabs">
                <button class="modal-tab active" onclick="switchFRTab('received', this)">Recebidos</button>
                <button class="modal-tab" onclick="switchFRTab('sent', this)">Enviados</button>
                <button class="modal-tab" onclick="switchFRTab('friends', this)">Amigos</button>
            </div>
            <div class="modal-body" id="friendRequestsList">
                <div class="fr-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
            </div>
        </div>
    </div>

    <!-- Modal de Reencaminhamento de Mensagem -->
    <div class="modal" id="forwardModal" style="display:none;" onclick="if(event.target===this)closeForwardModal()">
        <div class="modal-content forward-modal">
            <div class="modal-header">
                <h3><i class="fas fa-share"></i> Reencaminhar para...</h3>
                <button onclick="closeForwardModal()">&times;</button>
            </div>
            <div class="forward-message-preview" id="forwardMessagePreview">
                <!-- Preview da mensagem a reencaminhar -->
            </div>
            <div class="modal-body">
                <input type="text" id="forwardSearchUsers" placeholder="Pesquisar contactos..." oninput="searchForwardUsers(this.value)">
                <div class="forward-selected-users" id="forwardSelectedUsers" style="display:none;">
                    <!-- Chips dos utilizadores selecionados -->
                </div>
                <div id="forwardUsersList" class="users-list">
                    <div class="fr-loading"><i class="fas fa-spinner fa-spin"></i> Carregando contactos...</div>
                </div>
            </div>
            <div class="forward-modal-footer">
                <span class="forward-count" id="forwardCount">0 selecionados</span>
                <button class="forward-send-btn" id="forwardSendBtn" onclick="executeForward()" disabled>
                    <i class="fas fa-paper-plane"></i> Enviar
                </button>
            </div>
        </div>
    </div>

    <script>
    // ========================================
    // SISTEMA DE PEDIDOS DE AMIZADE
    // ========================================
    let currentFRTab = 'received';

    function showFriendRequestsModal() {
        document.getElementById('friendRequestsModal').style.display = 'flex';
        currentFRTab = 'received';
        loadFriendRequests('received');
    }

    function closeFriendRequestsModal() {
        document.getElementById('friendRequestsModal').style.display = 'none';
    }

    function switchFRTab(tab, btn) {
        currentFRTab = tab;
        document.querySelectorAll('.modal-tabs .modal-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        loadFriendRequests(tab);
    }

    function loadFriendRequests(type) {
        const container = document.getElementById('friendRequestsList');
        container.innerHTML = '<div class="fr-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

        fetch(`api/get_friend_requests.php?type=${type}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.requests.length) {
                    const emptyMsg = type === 'friends' ? 'Nenhum amigo ainda' : 'Nenhum pedido';
                    const emptyIcon = type === 'friends' ? 'fa-user-friends' : 'fa-inbox';
                    container.innerHTML = `<div class="fr-empty"><i class="fas ${emptyIcon}"></i><p>${emptyMsg}</p></div>`;
                    return;
                }
                let html = '';
                data.requests.forEach(req => {
                    const avatar = getAvatarUrl(req.profile_picture);
                    const verified = req.is_verified ? '<i class="fas fa-check-circle chat-verified-icon"></i>' : '';
                    const time = formatFRTime(req.created_at || req.accepted_at);

                    if (type === 'received') {
                        html += `
                            <div class="fr-item" id="fr-${req.id}">
                                <img src="${avatar}" alt="${req.username}" class="fr-avatar" onerror="this.src='assets/images/default-avatar.svg'" onclick="window.location.href='perfil.php?id=${req.sender_id}'">
                                <div class="fr-info">
                                    <span class="fr-name">${escapeHtml(req.full_name || req.username)} ${verified}</span>
                                    <span class="fr-time">${time}</span>
                                </div>
                                <div class="fr-actions">
                                    <button class="fr-accept" onclick="respondFriendRequest(${req.id}, 'accept')">
                                        <i class="fas fa-check"></i> Aceitar
                                    </button>
                                    <button class="fr-reject" onclick="respondFriendRequest(${req.id}, 'reject')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>`;
                    } else if (type === 'sent') {
                        html += `
                            <div class="fr-item">
                                <img src="${avatar}" alt="${req.username}" class="fr-avatar" onerror="this.src='assets/images/default-avatar.svg'" onclick="window.location.href='perfil.php?id=${req.receiver_id}'">
                                <div class="fr-info">
                                    <span class="fr-name">${escapeHtml(req.full_name || req.username)} ${verified}</span>
                                    <span class="fr-time">${time}</span>
                                </div>
                                <div class="fr-status-label">Pendente</div>
                            </div>`;
                    } else if (type === 'friends') {
                        html += `
                            <div class="fr-item">
                                <img src="${avatar}" alt="${req.username}" class="fr-avatar" onerror="this.src='assets/images/default-avatar.svg'" onclick="window.location.href='perfil.php?id=${req.friend_id}'">
                                <div class="fr-info">
                                    <span class="fr-name">${escapeHtml(req.full_name || req.username)} ${verified}</span>
                                    <span class="fr-time">${time}</span>
                                </div>
                                <div class="fr-actions">
                                    <button class="fr-message-btn" onclick="window.location.href='chat.php?user_id=${req.friend_id}'">
                                        <i class="fas fa-comment"></i> Mensagem
                                    </button>
                                </div>
                            </div>`;
                    }
                });
                container.innerHTML = html;
            })
            .catch(() => {
                container.innerHTML = '<div class="fr-empty"><p>Erro ao carregar</p></div>';
            });
    }

    function respondFriendRequest(requestId, action) {
        const item = document.getElementById('fr-' + requestId);
        if (item) item.style.opacity = '0.5';

        const formData = new FormData();
        formData.append('request_id', requestId);
        formData.append('action', action);

        fetch('api/respond_friend_request.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (item) {
                        if (action === 'accept' && data.sender) {
                            // Mostrar info do utilizador com botão de mensagem
                            const avatar = getAvatarUrl(data.sender.profile_picture);
                            const verified = data.sender.is_verified ? '<i class="fas fa-check-circle chat-verified-icon"></i>' : '';
                            item.innerHTML = `
                                <img src="${avatar}" alt="${escapeHtml(data.sender.full_name || data.sender.username)}" class="fr-avatar" onerror="this.src='assets/images/default-avatar.svg'" onclick="window.location.href='perfil.php?id=${data.sender.id}'">
                                <div class="fr-info">
                                    <span class="fr-name">${escapeHtml(data.sender.full_name || data.sender.username)} ${verified}</span>
                                    <span class="fr-accepted-label"><i class="fas fa-check"></i> Amigos agora!</span>
                                </div>
                                <div class="fr-actions">
                                    <button class="fr-message-btn" onclick="window.location.href='chat.php?user_id=${data.sender.id}'">
                                        <i class="fas fa-comment"></i> Mensagem
                                    </button>
                                </div>
                            `;
                            item.style.opacity = '1';
                        } else {
                            item.innerHTML = `<div class="fr-responded">❌ Rejeitado</div>`;
                            item.style.opacity = '1';
                            setTimeout(() => item.remove(), 1500);
                        }
                    }
                    loadFriendRequestsCount();
                } else {
                    if (item) item.style.opacity = '1';
                    alert(data.error || 'Erro ao processar pedido');
                }
            })
            .catch(() => { if (item) item.style.opacity = '1'; });
    }

    function loadFriendRequestsCount() {
        fetch('api/get_friend_requests.php?type=received')
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('friendRequestsBadge');
                if (data.success && data.pending_count > 0) {
                    badge.textContent = data.pending_count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(() => {});
    }

    function formatFRTime(dateStr) {
        const d = new Date(dateStr);
        const now = new Date();
        const diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'Agora';
        if (diff < 3600) return Math.floor(diff / 60) + ' min';
        if (diff < 86400) return Math.floor(diff / 3600) + ' h';
        return Math.floor(diff / 86400) + ' d';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Carregar contagem ao abrir o chat
    document.addEventListener('DOMContentLoaded', loadFriendRequestsCount);
    // Atualizar a cada 30s
    setInterval(loadFriendRequestsCount, 30000);
    </script>
</body>
</html>
