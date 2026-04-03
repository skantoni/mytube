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
    $stmt = $pdo->prepare("SELECT id, username, profile_picture, is_verified FROM users WHERE id = ?");
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
$stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                    <div class="chat-header-user">
                        <img src="<?php echo htmlspecialchars($chat_user_info['profile_picture'] ?: 'assets/images/default-avatar.svg'); ?>" 
                             alt="<?php echo htmlspecialchars($chat_user_info['username']); ?>">
                        <div class="chat-header-info">
                            <h3>
                                <span class="chat-header-name"><?php echo htmlspecialchars($chat_user_info['username']); ?></span>
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
                    </div>
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
        let chatWithUserId = <?php echo $chat_with_user_id ?? 'null'; ?>;
        let chatWithUsername = <?php echo $chat_user_info ? "'" . addslashes($chat_user_info['username']) . "'" : 'null'; ?>;
        let chatWithIsVerified = <?php echo $chat_user_info ? (!empty($chat_user_info['is_verified']) ? 'true' : 'false') : 'false'; ?>;
        const fromPage = '<?php echo $from_page; ?>';
        
        // Event delegation é feito no chat-socket.js (setupConversationClickListener)
    </script>
    
    <!-- Socket.IO Client -->
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    
    <!-- Chat Socket.IO Client -->
    <script src="<?php echo asset('assets/js/chat-socket.js'); ?>"></script>
</body>
</html>
