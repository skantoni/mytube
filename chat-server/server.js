/**
 * MyTube Chat Server - Servidor Principal
 * Socket.IO para comunicação em tempo real
 */

require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const { pool, testConnection } = require('./config/database');

// Inicializar Express
const app = express();
const server = http.createServer(app);

// Configurar CORS
const corsOrigin = process.env.CORS_ORIGIN || 'http://localhost';
app.use(cors({
    origin: corsOrigin,
    credentials: true
}));

app.use(express.json());

// Inicializar Socket.IO
const io = new Server(server, {
    cors: {
        origin: corsOrigin,
        methods: ['GET', 'POST'],
        credentials: true
    },
    pingTimeout: 60000,
    pingInterval: 25000
});

// Armazenar usuários conectados
// Map: socketId -> { userId, username, socketId }
const connectedUsers = new Map();
// Map: userId -> Set<socketId>
const userSockets = new Map();
// Map: socketId -> Set<userId de contactos observados>
const socketContactSubscriptions = new Map();
// Map: userId observado -> Set<socketId de observadores>
const contactPresenceWatchers = new Map();
const MAX_CONTACT_PRESENCE_SUBSCRIPTIONS = 2000;

function normalizeUserId(userId) {
    return parseInt(userId, 10);
}

function normalizeUserIdList(userIds) {
    if (!Array.isArray(userIds)) {
        return [];
    }

    const seen = new Set();
    const normalized = [];

    userIds.forEach((userId) => {
        const parsed = normalizeUserId(userId);
        if (!Number.isInteger(parsed) || parsed <= 0 || seen.has(parsed)) {
            return;
        }

        seen.add(parsed);
        normalized.push(parsed);
    });

    return normalized;
}

function updateSocketContactSubscriptions(socketId, userIds) {
    const nextList = normalizeUserIdList(userIds);
    const previousSet = socketContactSubscriptions.get(socketId) || new Set();
    const nextSet = new Set(nextList);

    previousSet.forEach((contactUserId) => {
        if (nextSet.has(contactUserId)) {
            return;
        }

        const watchers = contactPresenceWatchers.get(contactUserId);
        if (!watchers) {
            return;
        }

        watchers.delete(socketId);
        if (watchers.size === 0) {
            contactPresenceWatchers.delete(contactUserId);
        }
    });

    nextSet.forEach((contactUserId) => {
        if (previousSet.has(contactUserId)) {
            return;
        }

        const watchers = contactPresenceWatchers.get(contactUserId) || new Set();
        watchers.add(socketId);
        contactPresenceWatchers.set(contactUserId, watchers);
    });

    socketContactSubscriptions.set(socketId, nextSet);
    return nextList;
}

function clearSocketContactSubscriptions(socketId) {
    const subscribedContacts = socketContactSubscriptions.get(socketId);
    if (!subscribedContacts) {
        return;
    }

    subscribedContacts.forEach((contactUserId) => {
        const watchers = contactPresenceWatchers.get(contactUserId);
        if (!watchers) {
            return;
        }

        watchers.delete(socketId);
        if (watchers.size === 0) {
            contactPresenceWatchers.delete(contactUserId);
        }
    });

    socketContactSubscriptions.delete(socketId);
}

function emitContactPresenceSnapshot(socket, contactIds) {
    const normalizedContactIds = normalizeUserIdList(contactIds);
    const statuses = getOnlineStatuses(normalizedContactIds);
    let onlineCount = 0;

    normalizedContactIds.forEach((contactUserId) => {
        if (statuses[contactUserId]) {
            onlineCount += 1;
        }
    });

    socket.emit('contact_presence_snapshot', {
        statuses,
        onlineCount,
        totalContacts: normalizedContactIds.length
    });
}

function notifyContactPresenceWatchers(userId, isOnline) {
    const normalizedUserId = normalizeUserId(userId);
    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
        return;
    }

    const watcherSocketIds = contactPresenceWatchers.get(normalizedUserId);
    if (!watcherSocketIds || watcherSocketIds.size === 0) {
        return;
    }

    watcherSocketIds.forEach((watcherSocketId) => {
        const watcherSocket = io.sockets.sockets.get(watcherSocketId);

        if (!watcherSocket) {
            const watchers = contactPresenceWatchers.get(normalizedUserId);
            if (watchers) {
                watchers.delete(watcherSocketId);
                if (watchers.size === 0) {
                    contactPresenceWatchers.delete(normalizedUserId);
                }
            }
            return;
        }

        watcherSocket.emit('contact_presence_update', {
            userId: normalizedUserId,
            isOnline: Boolean(isOnline)
        });
    });
}

function addUserSocket(userId, socketId) {
    const normalizedUserId = normalizeUserId(userId);
    const socketSet = userSockets.get(normalizedUserId) || new Set();
    socketSet.add(socketId);
    userSockets.set(normalizedUserId, socketSet);
    return socketSet.size;
}

function removeUserSocket(userId, socketId) {
    const normalizedUserId = normalizeUserId(userId);
    const socketSet = userSockets.get(normalizedUserId);

    if (!socketSet) {
        return 0;
    }

    socketSet.delete(socketId);

    if (socketSet.size === 0) {
        userSockets.delete(normalizedUserId);
        return 0;
    }

    return socketSet.size;
}

function getActiveUsersCount() {
    return userSockets.size;
}

function broadcastActiveUsersCount() {
    io.emit('active_users_count', { count: getActiveUsersCount() });
}

/**
 * Verificar se um usuário está online (conectado via Socket)
 */
function isUserOnline(userId) {
    const socketSet = userSockets.get(normalizeUserId(userId));
    return Boolean(socketSet && socketSet.size > 0);
}

/**
 * Buscar status online de múltiplos usuários
 */
function getOnlineStatuses(userIds) {
    const statuses = {};
    userIds.forEach(userId => {
        statuses[userId] = isUserOnline(userId);
    });
    return statuses;
}

/**
 * Atualizar status online do usuário no banco
 */
async function updateOnlineStatus(userId, isOnline) {
    try {
        await pool.execute(`
            INSERT INTO user_online_status (user_id, is_online, last_seen)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE is_online = ?, last_seen = NOW()
        `, [userId, isOnline, isOnline]);
    } catch (error) {
        console.error('Erro ao atualizar status online:', error.message);
    }
}

/**
 * Buscar dados do usuário
 */
async function getUserById(userId) {
    try {
        const [rows] = await pool.execute(
            'SELECT id, username, profile_picture as avatar, is_verified FROM users WHERE id = ?',
            [userId]
        );
        return rows[0] || null;
    } catch (error) {
        console.error('Erro ao buscar usuário:', error.message);
        return null;
    }
}

/**
 * Salvar mensagem no banco de dados
 */
async function saveMessage(conversationId, senderId, receiverId, content, replyToId = null) {
    try {
        const [result] = await pool.execute(`
            INSERT INTO messages (conversation_id, sender_id, receiver_id, message, reply_to_message_id, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'sent', NOW())
        `, [conversationId, senderId, receiverId, content, replyToId]);
        
        // Atualizar última mensagem da conversa
        await pool.execute(`
            UPDATE conversations 
            SET updated_at = NOW()
            WHERE id = ?
        `, [conversationId]);
        
        return result.insertId;
    } catch (error) {
        console.error('Erro ao salvar mensagem:', error.message);
        return null;
    }
}

/**
 * Buscar ou criar conversa entre dois usuários
 */
async function getOrCreateConversation(user1Id, user2Id) {
    try {
        // Verificar se já existe conversa
        const [existing] = await pool.execute(`
            SELECT id FROM conversations
            WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)
            LIMIT 1
        `, [user1Id, user2Id, user2Id, user1Id]);
        
        if (existing.length > 0) {
            return existing[0].id;
        }
        
        // Criar nova conversa
        const [result] = await pool.execute(`
            INSERT INTO conversations (user1_id, user2_id, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        `, [user1Id, user2Id]);
        
        return result.insertId;
    } catch (error) {
        console.error('Erro ao buscar/criar conversa:', error.message);
        return null;
    }
}

/**
 * Buscar mensagens de uma conversa
 */
async function getMessages(conversationId, limit = 50, beforeId = null, userId = null) {
    try {
        let query = `
            SELECT m.*, 
                   m.is_edited,
                   u.username as sender_username, 
                   u.profile_picture as sender_avatar,
                   rm.message as reply_content,
                   ru.username as reply_username
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN messages rm ON m.reply_to_message_id = rm.id
            LEFT JOIN users ru ON rm.sender_id = ru.id
            WHERE m.conversation_id = ?
              AND m.deleted_for_all = 0
        `;
        
        const params = [conversationId];
        
        // Filtrar mensagens apagadas para o utilizador específico
        if (userId) {
            query += ` AND NOT (m.sender_id = ? AND m.deleted_for_sender = 1)
                        AND NOT (m.receiver_id = ? AND m.deleted_for_receiver = 1)`;
            params.push(userId, userId);
        }
        
        if (beforeId) {
            query += ' AND m.id < ?';
            params.push(beforeId);
        }
        
        query += ' ORDER BY m.id DESC LIMIT ?';
        params.push(limit);
        
        const [rows] = await pool.execute(query, params);
        const messages = rows.reverse();
        
        // Buscar reações para cada mensagem
        for (const msg of messages) {
            msg.reactions = await getMessageReactions(msg.id);
        }
        
        return messages;
    } catch (error) {
        console.error('Erro ao buscar mensagens:', error.message);
        return [];
    }
}

/**
 * Buscar conversas do usuário
 * NOTA: is_online vem da memória (userSockets Map), não do DB — evita status stale
 */
async function getUserConversations(userId) {
    try {
        const [rows] = await pool.execute(`
            SELECT DISTINCT
                c.id as conversation_id,
                CASE 
                    WHEN c.user1_id = ? THEN c.user2_id 
                    ELSE c.user1_id 
                END as other_user_id,
                u.username,
                u.profile_picture as avatar,
                u.is_verified,
                (SELECT CASE
                    WHEN m2.type = 'image' THEN 'Foto'
                    WHEN m2.type = 'video' THEN 'Vídeo'
                    WHEN m2.type = 'audio' THEN 'Áudio'
                    WHEN m2.type = 'file' THEN 'Ficheiro'
                    WHEN m2.message IS NULL OR TRIM(m2.message) = '' THEN 'Mensagem'
                    ELSE m2.message
                 END
                 FROM messages m2 
                 WHERE m2.conversation_id = c.id 
                 ORDER BY m2.created_at DESC LIMIT 1) as last_message,
                (SELECT m3.created_at FROM messages m3 
                 WHERE m3.conversation_id = c.id 
                 ORDER BY m3.created_at DESC LIMIT 1) as last_message_time,
                uo.last_seen,
                (SELECT COUNT(*) FROM messages m4 
                 WHERE m4.conversation_id = c.id 
                 AND m4.receiver_id = ? 
                 AND m4.status != 'read') as unread_count
            FROM conversations c
            JOIN users u ON (CASE WHEN c.user1_id = ? THEN c.user2_id ELSE c.user1_id END) = u.id
            LEFT JOIN user_online_status uo ON u.id = uo.user_id
            WHERE c.user1_id = ? OR c.user2_id = ?
            ORDER BY c.updated_at DESC
        `, [userId, userId, userId, userId, userId]);
        
        // Enriquecer com status online da memória (fonte de verdade)
        rows.forEach(row => {
            row.is_online = isUserOnline(row.other_user_id) ? 1 : 0;
        });
        
        return rows;
    } catch (error) {
        console.error('Erro ao buscar conversas:', error.message);
        return [];
    }
}

/**
 * Buscar total de mensagens não lidas do usuário
 */
async function getTotalUnreadMessages(userId) {
    try {
        const normalizedUserId = normalizeUserId(userId);

        if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
            return 0;
        }

        const [rows] = await pool.execute(`
            SELECT COUNT(*) as unread_count
            FROM messages
            WHERE receiver_id = ?
              AND status != 'read'
              AND deleted_for_all = 0
              AND deleted_for_receiver = 0
        `, [normalizedUserId]);

        return Number(rows[0]?.unread_count || 0);
    } catch (error) {
        console.error('Erro ao buscar total de não lidas:', error.message);
        return 0;
    }
}

/**
 * Emitir total de não lidas para um socket específico
 */
async function emitUnreadMessagesCountToSocket(socket, userId) {
    const normalizedUserId = normalizeUserId(userId);

    if (!socket || !Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
        return 0;
    }

    const unreadCount = await getTotalUnreadMessages(normalizedUserId);
    socket.emit('unread_messages_count', {
        userId: normalizedUserId,
        unreadCount
    });

    return unreadCount;
}

/**
 * Emitir total de não lidas para todas as abas/conexões do usuário
 */
async function emitUnreadMessagesCountToUser(userId) {
    const normalizedUserId = normalizeUserId(userId);

    if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
        return 0;
    }

    const unreadCount = await getTotalUnreadMessages(normalizedUserId);
    io.to(`user_${normalizedUserId}`).emit('unread_messages_count', {
        userId: normalizedUserId,
        unreadCount
    });

    return unreadCount;
}

/**
 * Marcar mensagens como lidas
 */
async function markMessagesAsRead(conversationId, userId) {
    try {
        await pool.execute(`
            UPDATE messages 
            SET status = 'read'
            WHERE conversation_id = ? AND receiver_id = ? AND status != 'read'
        `, [conversationId, userId]);
    } catch (error) {
        console.error('Erro ao marcar mensagens como lidas:', error.message);
    }
}

/**
 * Atualizar status de uma mensagem específica
 */
async function updateMessageStatus(messageId, status) {
    try {
        await pool.execute(`
            UPDATE messages 
            SET status = ?
            WHERE id = ?
        `, [status, messageId]);
        return true;
    } catch (error) {
        console.error('Erro ao atualizar status da mensagem:', error.message);
        return false;
    }
}

/**
 * Deletar mensagem
 */
async function deleteMessage(messageId, userId) {
    try {
        // Verificar se o usuário é o dono da mensagem
        const [rows] = await pool.execute(
            'SELECT id, conversation_id FROM messages WHERE id = ? AND sender_id = ?',
            [messageId, userId]
        );
        
        if (rows.length === 0) {
            return { success: false, error: 'Mensagem não encontrada ou sem permissão' };
        }
        
        await pool.execute('UPDATE messages SET deleted_for_all = 1 WHERE id = ?', [messageId]);
        return { success: true, conversationId: rows[0].conversation_id };
    } catch (error) {
        console.error('Erro ao deletar mensagem:', error.message);
        return { success: false, error: error.message };
    }
}

/**
 * Atualizar status de digitação
 */
async function updateTypingStatus(userId, conversationId, isTyping) {
    try {
        await pool.execute(`
            INSERT INTO typing_status (user_id, conversation_id, is_typing, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE is_typing = ?, updated_at = NOW()
        `, [userId, conversationId, isTyping, isTyping]);
    } catch (error) {
        console.error('Erro ao atualizar status de digitação:', error.message);
    }
}

/**
 * Adicionar reação a uma mensagem
 */
async function addMessageReaction(messageId, userId, emoji) {
    try {
        await pool.execute(`
            INSERT INTO message_reactions (message_id, user_id, emoji)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE emoji = emoji
        `, [messageId, userId, emoji]);
        return true;
    } catch (error) {
        console.error('Erro ao adicionar reação:', error.message);
        return false;
    }
}

/**
 * Remover reação de uma mensagem
 */
async function removeMessageReaction(messageId, userId, emoji) {
    try {
        await pool.execute(`
            DELETE FROM message_reactions 
            WHERE message_id = ? AND user_id = ? AND emoji = ?
        `, [messageId, userId, emoji]);
        return true;
    } catch (error) {
        console.error('Erro ao remover reação:', error.message);
        return false;
    }
}

/**
 * Toggle reação (adicionar ou remover)
 */
async function toggleMessageReaction(messageId, userId, emoji) {
    try {
        // Verificar se já existe
        const [rows] = await pool.execute(`
            SELECT id FROM message_reactions 
            WHERE message_id = ? AND user_id = ? AND emoji = ?
        `, [messageId, userId, emoji]);
        
        if (rows.length > 0) {
            await removeMessageReaction(messageId, userId, emoji);
            return { action: 'removed' };
        } else {
            await addMessageReaction(messageId, userId, emoji);
            return { action: 'added' };
        }
    } catch (error) {
        console.error('Erro ao toggle reação:', error.message);
        return { action: 'error', error: error.message };
    }
}

/**
 * Buscar reações de uma mensagem
 */
async function getMessageReactions(messageId) {
    try {
        const [rows] = await pool.execute(`
            SELECT mr.emoji, mr.user_id, u.username
            FROM message_reactions mr
            JOIN users u ON mr.user_id = u.id
            WHERE mr.message_id = ?
        `, [messageId]);
        
        // Agrupar por emoji
        const reactions = {};
        rows.forEach(row => {
            if (!reactions[row.emoji]) {
                reactions[row.emoji] = [];
            }
            reactions[row.emoji].push({
                userId: row.user_id,
                username: row.username
            });
        });
        
        return reactions;
    } catch (error) {
        console.error('Erro ao buscar reações:', error.message);
        return {};
    }
}

/**
 * Buscar informações da mensagem incluindo conversation
 */
async function getMessageInfo(messageId) {
    try {
        const [rows] = await pool.execute(`
            SELECT m.id, m.conversation_id, m.sender_id, m.receiver_id
            FROM messages m
            WHERE m.id = ?
        `, [messageId]);
        return rows[0] || null;
    } catch (error) {
        console.error('Erro ao buscar info da mensagem:', error.message);
        return null;
    }
}

/**
 * Buscar usuários para nova conversa
 */
async function searchUsers(query, currentUserId, limit = 20) {
    try {
        const [rows] = await pool.execute(`
            SELECT id, username, profile_picture as avatar, is_verified
            FROM users
            WHERE id != ? AND username LIKE ?
            ORDER BY username
            LIMIT ?
        `, [currentUserId, `%${query}%`, limit]);
        
        return rows;
    } catch (error) {
        console.error('Erro ao buscar usuários:', error.message);
        return [];
    }
}

// ==========================================
// EVENTOS SOCKET.IO
// ==========================================

io.on('connection', (socket) => {
    console.log(`🔌 Nova conexão: ${socket.id}`);

    socket.on('request_active_users_count', () => {
        socket.emit('active_users_count', { count: getActiveUsersCount() });
    });

    socket.on('subscribe_contact_presence', (data = {}) => {
        const connectedUser = connectedUsers.get(socket.id);
        if (!connectedUser) {
            return;
        }

        const requestedUserId = normalizeUserId(data.userId);
        if (Number.isInteger(requestedUserId) && requestedUserId > 0 && requestedUserId !== connectedUser.userId) {
            return;
        }

        const contactIds = normalizeUserIdList(data.contactIds)
            .filter((contactUserId) => contactUserId !== connectedUser.userId)
            .slice(0, MAX_CONTACT_PRESENCE_SUBSCRIPTIONS);

        const subscribedContactIds = updateSocketContactSubscriptions(socket.id, contactIds);
        emitContactPresenceSnapshot(socket, subscribedContactIds);
    });

    socket.on('request_unread_messages_count', async (data = {}) => {
        const connectedUser = connectedUsers.get(socket.id);
        if (!connectedUser) {
            return;
        }

        const requestedUserId = normalizeUserId(data.userId);
        if (Number.isInteger(requestedUserId) && requestedUserId > 0 && requestedUserId !== connectedUser.userId) {
            return;
        }

        await emitUnreadMessagesCountToSocket(socket, connectedUser.userId);
    });
    
    /**
     * Autenticação do usuário
     */
    socket.on('authenticate', async (data) => {
        const normalizedUserId = normalizeUserId(data.userId);
        const username = data.username;
        
        if (!normalizedUserId) {
            socket.emit('error', { message: 'ID do usuário não fornecido' });
            return;
        }

        const wasOffline = !isUserOnline(normalizedUserId);
        
        // Registrar usuário conectado
        connectedUsers.set(socket.id, { userId: normalizedUserId, username, socketId: socket.id });
        addUserSocket(normalizedUserId, socket.id);
        
        // Atualizar status online
        await updateOnlineStatus(normalizedUserId, true);
        
        // Entrar na sala pessoal do usuário
        socket.join(`user_${normalizedUserId}`);
        
        if (wasOffline) {
            // Notificar todos sobre status online apenas na primeira aba/conexão
            console.log(`📡 Emitindo user_online para todos - userId: ${normalizedUserId}, username: ${username}`);
            io.emit('user_online', { userId: normalizedUserId, username, isOnline: true });
            notifyContactPresenceWatchers(normalizedUserId, true);
            
            // Marcar mensagens pendentes como 'delivered' e notificar remetentes
            try {
                const [pendingMessages] = await pool.execute(
                    `SELECT id, sender_id, conversation_id FROM messages WHERE receiver_id = ? AND status = 'sent'`,
                    [normalizedUserId]
                );
                
                if (pendingMessages.length > 0) {
                    await pool.execute(
                        `UPDATE messages SET status = 'delivered' WHERE receiver_id = ? AND status = 'sent'`,
                        [normalizedUserId]
                    );
                    
                    console.log(`📬 Marcadas ${pendingMessages.length} mensagens como DELIVERED para o usuário ${normalizedUserId}`);
                    
                    pendingMessages.forEach(msg => {
                        io.to(`user_${msg.sender_id}`).emit('message_status_update', {
                            messageId: msg.id,
                            conversationId: msg.conversation_id,
                            status: 'delivered'
                        });
                        io.to(`conversation_${msg.conversation_id}`).emit('message_status_update', {
                            messageId: msg.id,
                            conversationId: msg.conversation_id,
                            status: 'delivered'
                        });
                    });
                }
            } catch (err) {
                console.error('Erro ao marcar mensagens como delivered na autenticação:', err.message);
            }
        }
        
        // Enviar lista de conversas
        const conversations = await getUserConversations(normalizedUserId);
        socket.emit('conversations_list', conversations);
        
        // Enviar status online dos usuários nas conversas
        const otherUserIds = conversations.map(c => c.other_user_id);
        console.log(`📋 Enviando online_statuses para o usuário ${username}, outros usuários:`, otherUserIds);
        const onlineStatuses = getOnlineStatuses(otherUserIds);
        socket.emit('online_statuses', onlineStatuses);

        await emitUnreadMessagesCountToSocket(socket, normalizedUserId);

        socket.emit('active_users_count', { count: getActiveUsersCount() });
        broadcastActiveUsersCount();
        
        console.log(`✅ Usuário autenticado: ${username} (ID: ${normalizedUserId})`);
    });
    
    /**
     * Heartbeat — cliente envia a cada 30s para manter last_seen atualizado.
     * Isto permite ao cleanup periódico distinguir utilizadores ativos de stale.
     */
    socket.on('heartbeat', async () => {
        const user = connectedUsers.get(socket.id);
        if (!user) return;
        
        try {
            await pool.execute(
                'UPDATE user_online_status SET last_seen = NOW() WHERE user_id = ?',
                [user.userId]
            );
        } catch (error) {
            // Silenciar — heartbeat é best-effort
        }
    });
    
    /**
     * Entrar em uma conversa
     */
    socket.on('join_conversation', async (data) => {
        const { conversationId, userId, otherUserId } = data;
        const room = `conversation_${conversationId}`;
        
        socket.join(room);
        
        // Buscar IDs das mensagens que serão marcadas como lidas
        const [unreadMessages] = await pool.execute(`
            SELECT id, sender_id 
            FROM messages 
            WHERE conversation_id = ? AND receiver_id = ? AND status != 'read'
        `, [conversationId, userId]);
        
        // Marcar mensagens como lidas (✔✔ Azul = Lido)
        await markMessagesAsRead(conversationId, userId);
        
        // Buscar mensagens
        const messages = await getMessages(conversationId, 50, null, userId);
        socket.emit('messages_list', { conversationId, messages });
        
        // Enviar status online do outro usuário
        if (otherUserId) {
            const isOnline = isUserOnline(otherUserId);
            socket.emit('user_status', { userId: otherUserId, isOnline });
        }
        
        // Notificar o remetente que suas mensagens foram lidas (✔✔ Azul)
        if (unreadMessages.length > 0) {
            console.log(`📖 Marcando ${unreadMessages.length} mensagens como READ na conversa ${conversationId}`);
            
            unreadMessages.forEach(msg => {
                console.log(`  ✔✔ Mensagem ${msg.id} → status: READ (azul)`);
                
                // Emitir para o remetente da mensagem (tempo real)
                io.to(`user_${msg.sender_id}`).emit('message_status_update', {
                    messageId: msg.id,
                    conversationId,
                    status: 'read'
                });
                
                // Também emitir para todos na sala da conversa
                io.to(`conversation_${conversationId}`).emit('message_status_update', {
                    messageId: msg.id,
                    conversationId,
                    status: 'read'
                });
            });
            
            // Atualizar lista de conversas do usuário para refletir unread_count = 0
            // Isso corrige o bug onde start_conversation envia conversations_list
            // ANTES das mensagens serem marcadas como lidas, restaurando o badge
            const updatedConversations = await getUserConversations(userId);
            socket.emit('conversations_list', updatedConversations);
            await emitUnreadMessagesCountToUser(userId);
        }
        
        console.log(`👤 Usuário ${userId} entrou na conversa ${conversationId}`);
    });
    
    /**
     * Marcar mensagens como lidas em tempo real
     * (quando o destinatário está com a conversa aberta e recebe nova mensagem)
     */
    socket.on('mark_as_read', async (data) => {
        const { conversationId, userId, messageIds } = data;
        
        if (!conversationId || !userId) return;
        
        // Buscar mensagens não lidas enviadas pelo outro utilizador
        const [unreadMessages] = await pool.execute(`
            SELECT id, sender_id 
            FROM messages 
            WHERE conversation_id = ? AND receiver_id = ? AND status != 'read'
        `, [conversationId, userId]);
        
        if (unreadMessages.length === 0) return;
        
        // Marcar como lidas no banco
        await markMessagesAsRead(conversationId, userId);
        
        console.log(`📖 mark_as_read: ${unreadMessages.length} mensagens marcadas como READ na conversa ${conversationId}`);
        
        // Notificar o remetente que as mensagens foram lidas (✔✔ Azul)
        unreadMessages.forEach(msg => {
            io.to(`user_${msg.sender_id}`).emit('message_status_update', {
                messageId: msg.id,
                conversationId,
                status: 'read'
            });
            
            io.to(`conversation_${conversationId}`).emit('message_status_update', {
                messageId: msg.id,
                conversationId,
                status: 'read'
            });
        });
        
        // Atualizar lista de conversas para refletir unread_count = 0
        const updatedConversations = await getUserConversations(userId);
        socket.emit('conversations_list', updatedConversations);
        await emitUnreadMessagesCountToUser(userId);
    });
    
    /**
     * Sair de uma conversa
     */
    socket.on('leave_conversation', (data) => {
        const { conversationId, userId } = data;
        socket.leave(`conversation_${conversationId}`);
        
        // Parar de digitar ao sair
        updateTypingStatus(userId, conversationId, false);
        socket.to(`conversation_${conversationId}`).emit('typing_status', {
            conversationId,
            userId,
            isTyping: false
        });
    });
    
    /**
     * Enviar mensagem
     */
    socket.on('send_message', async (data) => {
        const { senderId, receiverId, content, replyToId, tempId } = data;
        
        console.log(`📥 Recebido send_message - tempId: ${tempId}, sender: ${senderId}, content: "${content?.substring(0, 30)}"`);
        
        if (!senderId || !receiverId || !content?.trim()) {
            socket.emit('error', { message: 'Dados inválidos para enviar mensagem' });
            return;
        }
        
        // Buscar ou criar conversa
        const conversationId = await getOrCreateConversation(senderId, receiverId);
        if (!conversationId) {
            socket.emit('error', { message: 'Erro ao criar conversa' });
            return;
        }
        
        // Salvar mensagem
        const messageId = await saveMessage(conversationId, senderId, receiverId, content.trim(), replyToId);
        if (!messageId) {
            socket.emit('error', { message: 'Erro ao salvar mensagem' });
            return;
        }
        
        console.log(`💾 Mensagem salva: ID ${messageId}, conversa ${conversationId}`);
        
        // Buscar dados completos da mensagem
        const [messageRows] = await pool.execute(`
            SELECT m.*, 
                   u.username as sender_username, 
                   u.profile_picture as sender_avatar,
                   rm.message as reply_content,
                   ru.username as reply_username
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN messages rm ON m.reply_to_message_id = rm.id
            LEFT JOIN users ru ON rm.sender_id = ru.id
            WHERE m.id = ?
        `, [messageId]);
        
        const message = messageRows[0];
        
        // Confirmar envio para o remetente (✔ Enviado)
        socket.emit('message_sent', {
            tempId: data.tempId,
            messageId,
            conversationId,
            status: 'sent',
            createdAt: message.created_at
        });
        
        // Verificar se o destinatário está online
        const isReceiverOnline = isUserOnline(receiverId);
        
        // Se destinatário está online, marcar como 'delivered' (✔✔ Entregue)
        if (isReceiverOnline) {
            await updateMessageStatus(messageId, 'delivered');
            message.status = 'delivered';
            
            console.log(`📬 Mensagem ${messageId} marcada como DELIVERED (destinatário online)`);
            
            // Notificar remetente que mensagem foi entregue (tempo real)
            io.to(`user_${senderId}`).emit('message_status_update', {
                messageId,
                conversationId,
                status: 'delivered'
            });
            
            // Também emitir para a sala da conversa
            io.to(`conversation_${conversationId}`).emit('message_status_update', {
                messageId,
                conversationId,
                status: 'delivered'
            });
        }
        
        // Enviar mensagem para a sala da conversa (EXCETO o remetente - ele já adicionou otimisticamente)
        socket.to(`conversation_${conversationId}`).emit('new_message', {
            conversationId,
            message
        });
        
        console.log(`📤 new_message emitido para sala conversation_${conversationId} (exceto remetente)`);
        
        // Notificar o destinatário (se não estiver na conversa)
        if (isReceiverOnline) {
            io.to(`user_${receiverId}`).emit('message_notification', {
                conversationId,
                senderId,
                senderUsername: message.sender_username,
                senderAvatar: message.sender_avatar,
                content: content.trim().substring(0, 100)
            });
        }
        
        // Atualizar lista de conversas para ambos
        const senderConversations = await getUserConversations(senderId);
        const receiverConversations = await getUserConversations(receiverId);
        
        io.to(`user_${senderId}`).emit('conversations_list', senderConversations);
        io.to(`user_${receiverId}`).emit('conversations_list', receiverConversations);
        await emitUnreadMessagesCountToUser(receiverId);
        
        // Parar de digitar
        await updateTypingStatus(senderId, conversationId, false);
        io.to(`conversation_${conversationId}`).emit('typing_status', {
            conversationId,
            userId: senderId,
            isTyping: false
        });
    });
    
    /**
     * Status de digitação
     */
    socket.on('typing', async (data) => {
        const { userId, conversationId, otherUserId, isTyping } = data;
        
        console.log(`⌨️ Typing: userId=${userId}, conversationId=${conversationId}, otherUserId=${otherUserId}, isTyping=${isTyping}`);
        
        await updateTypingStatus(userId, conversationId, isTyping);
        
        // Enviar para a sala da conversa (quem está com a conversa aberta)
        socket.to(`conversation_${conversationId}`).emit('typing_status', {
            conversationId,
            userId,
            isTyping
        });
        
        // Também enviar diretamente para o usuário (para mostrar na sidebar mesmo sem estar na conversa)
        if (otherUserId) {
            io.to(`user_${otherUserId}`).emit('typing_status', {
                conversationId,
                userId,
                isTyping
            });
        }
        
        console.log(`⌨️ Emitido typing_status para conversation_${conversationId} e user_${otherUserId}`);
    });

    /**
     * Broadcast de mensagem já salva via PHP (ex: áudio, ficheiro)
     * Não insere no DB - apenas notifica o receptor
     */
    socket.on('broadcast_uploaded_message', async (data) => {
        const { conversationId, receiverId, message } = data;
        
        if (!conversationId || !message) return;
        
        console.log(`📤 Broadcast uploaded message: ID ${message.id} para conversa ${conversationId}`);
        
        const senderId = message.sender_id;
        const messageId = message.id;
        
        // Verificar se o destinatário está online e marcar como delivered
        if (receiverId) {
            const isReceiverOnline = isUserOnline(receiverId);
            
            if (isReceiverOnline && messageId) {
                await updateMessageStatus(messageId, 'delivered');
                message.status = 'delivered';
                
                console.log(`📬 Ficheiro ${messageId} marcado como DELIVERED (destinatário online)`);
                
                // Notificar remetente que mensagem foi entregue
                io.to(`user_${senderId}`).emit('message_status_update', {
                    messageId,
                    conversationId: parseInt(conversationId),
                    status: 'delivered'
                });
                
                // Também emitir para a sala da conversa
                io.to(`conversation_${conversationId}`).emit('message_status_update', {
                    messageId,
                    conversationId: parseInt(conversationId),
                    status: 'delivered'
                });
            }
        }
        
        // Enviar para a sala da conversa (exceto o remetente)
        socket.to(`conversation_${conversationId}`).emit('new_message', {
            conversationId: parseInt(conversationId),
            message: message
        });
        
        // Enviar notificação para o receptor (caso não esteja na conversa)
        if (receiverId) {
            // Determinar conteúdo legível para a notificação
            let notifContent = '';
            if (message.type === 'audio') notifContent = '🎤 Mensagem de áudio';
            else if (message.type === 'image') notifContent = '📷 Foto';
            else if (message.type === 'video') notifContent = '🎥 Vídeo';
            else if (message.type === 'file') notifContent = '📎 Ficheiro';
            else notifContent = (message.message || '').substring(0, 100);
            
            io.to(`user_${receiverId}`).emit('message_notification', {
                conversationId: parseInt(conversationId),
                senderId: message.sender_id,
                senderUsername: message.sender_username || message.sender_picture,
                senderAvatar: message.sender_picture || message.sender_avatar,
                content: notifContent
            });
        }
        
        // Atualizar listas de conversas
        const senderConvs = await getUserConversations(senderId);
        io.to(`user_${senderId}`).emit('conversations_list', senderConvs);
        
        if (receiverId) {
            const receiverConvs = await getUserConversations(parseInt(receiverId));
            io.to(`user_${receiverId}`).emit('conversations_list', receiverConvs);
            await emitUnreadMessagesCountToUser(parseInt(receiverId));
        }
    });

    /**
     * Deletar mensagem
     */
    socket.on('delete_message', async (data) => {
        const { messageId, userId, deleteType } = data;
        
        if (deleteType === 'for_me') {
            // Apagar apenas para mim (via API PHP)
            // O cliente já faz a chamada HTTP, aqui só removemos do DOM local
            socket.emit('delete_success', { messageId, deleteType: 'for_me' });
        } else {
            // Apagar para todos (comportamento original)
            const result = await deleteMessage(messageId, userId);
            
            if (result.success) {
                io.to(`conversation_${result.conversationId}`).emit('message_deleted', {
                    messageId,
                    conversationId: result.conversationId
                });
                socket.emit('delete_success', { messageId, deleteType: 'for_all' });
            } else {
                socket.emit('error', { message: result.error });
            }
        }
    });

    /**
     * Editar mensagem
     */
    socket.on('edit_message', async (data) => {
        const { messageId, userId, content } = data;
        
        try {
            // Verificar se é o dono da mensagem
            const [rows] = await pool.execute(
                'SELECT id, conversation_id, sender_id, type, created_at FROM messages WHERE id = ? AND sender_id = ?',
                [messageId, userId]
            );
            
            if (rows.length === 0) {
                socket.emit('error', { message: 'Mensagem não encontrada ou sem permissão' });
                return;
            }
            
            const msg = rows[0];
            
            if (msg.type !== 'text') {
                socket.emit('error', { message: 'Apenas mensagens de texto podem ser editadas' });
                return;
            }
            
            // Limite de 5 minutos
            const createdTime = new Date(msg.created_at).getTime();
            const now = Date.now();
            if (now - createdTime > 300000) {
                socket.emit('error', { message: 'Tempo limite excedido para editar (5 minutos)' });
                return;
            }
            
            // Atualizar no banco
            await pool.execute(
                'UPDATE messages SET message = ?, is_edited = 1 WHERE id = ?',
                [content, messageId]
            );
            
            // Emitir para todos na conversa
            io.to(`conversation_${msg.conversation_id}`).emit('message_edited', {
                messageId,
                content,
                conversationId: msg.conversation_id
            });
            
        } catch (error) {
            console.error('Erro ao editar mensagem:', error.message);
            socket.emit('error', { message: 'Erro ao editar mensagem' });
        }
    });
    
    /**
     * Adicionar reação a mensagem
     */
    socket.on('add_reaction', async (data) => {
        const { messageId, emoji, userId } = data;
        
        const success = await addMessageReaction(messageId, userId, emoji);
        
        if (success) {
            const messageInfo = await getMessageInfo(messageId);
            const reactions = await getMessageReactions(messageId);
            
            if (messageInfo) {
                // Emitir para todos na conversa
                io.to(`conversation_${messageInfo.conversation_id}`).emit('reaction_updated', {
                    messageId,
                    reactions
                });
            }
        }
    });
    
    /**
     * Toggle reação (adicionar ou remover)
     */
    socket.on('toggle_reaction', async (data) => {
        const { messageId, emoji, userId } = data;
        
        await toggleMessageReaction(messageId, userId, emoji);
        
        const messageInfo = await getMessageInfo(messageId);
        const reactions = await getMessageReactions(messageId);
        
        if (messageInfo) {
            io.to(`conversation_${messageInfo.conversation_id}`).emit('reaction_updated', {
                messageId,
                reactions
            });
        }
    });
    
    /**
     * Buscar usuários
     */
    socket.on('search_users', async (data) => {
        const { query, userId } = data;
        
        // Não permitir pesquisa vazia ou com menos de 2 caracteres (performance)
        if (!query || query.trim().length < 2) {
            socket.emit('search_results', []);
            return;
        }
        
        const users = await searchUsers(query.trim(), userId);
        socket.emit('search_results', users);
    });
    
    /**
     * Buscar conversas
     */
    socket.on('get_conversations', async (data) => {
        const { userId } = data;
        
        const conversations = await getUserConversations(userId);
        socket.emit('conversations_list', conversations);
    });
    
    /**
     * Carregar mais mensagens (paginação)
     */
    socket.on('load_more_messages', async (data) => {
        const { conversationId, beforeId } = data;
        const user = connectedUsers.get(socket.id);
        const userId = user ? user.userId : null;
        
        const messages = await getMessages(conversationId, 50, beforeId, userId);
        socket.emit('more_messages', { conversationId, messages });
    });
    
    /**
     * Iniciar nova conversa
     */
    socket.on('start_conversation', async (data) => {
        const { userId, targetUserId } = data;
        
        const conversationId = await getOrCreateConversation(userId, targetUserId);
        const targetUser = await getUserById(targetUserId);
        
        socket.emit('conversation_started', {
            conversationId,
            otherUser: targetUser
        });
        
        // Atualizar lista de conversas
        const conversations = await getUserConversations(userId);
        socket.emit('conversations_list', conversations);
    });
    
    /**
     * Desconexão
     */
    socket.on('disconnect', async () => {
        const user = connectedUsers.get(socket.id);
        clearSocketContactSubscriptions(socket.id);
        connectedUsers.delete(socket.id);
        
        if (user) {
            // Limpar typing status em todas as conversas do utilizador
            try {
                const [rows] = await pool.query(
                    'SELECT conversation_id FROM typing_status WHERE user_id = ? AND is_typing = 1',
                    [user.userId]
                );
                for (const row of rows) {
                    await updateTypingStatus(user.userId, row.conversation_id, false);
                    io.to(`conversation_${row.conversation_id}`).emit('typing_status', {
                        conversationId: row.conversation_id,
                        userId: user.userId,
                        isTyping: false
                    });
                }
            } catch (e) {
                console.error('Erro ao limpar typing no disconnect:', e.message);
            }

            const remainingSockets = removeUserSocket(user.userId, socket.id);

            if (remainingSockets === 0) {
                await updateOnlineStatus(user.userId, false);

                // Notificar todos sobre status offline apenas quando a última aba fecha
                io.emit('user_offline', {
                    userId: user.userId,
                    username: user.username,
                    lastSeen: new Date()
                });

                notifyContactPresenceWatchers(user.userId, false);

                broadcastActiveUsersCount();
                console.log(`👋 Usuário desconectado: ${user.username} (ID: ${user.userId})`);
            }
        }
        
        console.log(`🔌 Conexão encerrada: ${socket.id}`);
    });
});

// ==========================================
// ROTAS HTTP (Fallback/Health Check)
// ==========================================

app.get('/', (req, res) => {
    res.json({
        name: 'MyTube Chat Server',
        version: '1.0.0',
        status: 'running',
        activeUsers: getActiveUsersCount(),
        socketConnections: connectedUsers.size
    });
});

app.get('/health', (req, res) => {
    res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

app.get('/stats', (req, res) => {
    res.json({
        activeUsers: getActiveUsersCount(),
        socketConnections: connectedUsers.size,
        uptime: process.uptime()
    });
});

// Rota para notificar nova mensagem via PHP (partilha de vídeo do feed)
app.post('/api/notify-message', async (req, res) => {
    try {
        const { message_id, conversation_id, sender_id, receiver_id, message, sender_username, sender_avatar } = req.body;
        
        if (!message_id || !conversation_id || !sender_id || !receiver_id) {
            return res.status(400).json({ success: false, error: 'Dados incompletos' });
        }
        
        console.log(`📨 Notificação PHP: msg ${message_id} de ${sender_id} para ${receiver_id}`);
        
        // Buscar dados do sender se não fornecidos
        let senderUsername = sender_username;
        let senderAvatar = sender_avatar;
        if (!senderUsername) {
            const sender = await getUserById(sender_id);
            if (sender) {
                senderUsername = sender.username;
                senderAvatar = sender.avatar;
            }
        }
        
        // Montar objeto da mensagem
        const messageObj = {
            id: message_id,
            conversation_id: conversation_id,
            sender_id: sender_id,
            receiver_id: receiver_id,
            message: message,
            content: message,
            sender_username: senderUsername,
            sender_avatar: senderAvatar,
            status: 'sent',
            created_at: new Date().toISOString(),
            type: 'text'
        };
        
        // Verificar se destinatário está online
        const receiverSocketId = userSockets.get(parseInt(receiver_id));
        
        if (receiverSocketId) {
            // Atualizar status para delivered
            await pool.execute('UPDATE messages SET status = ? WHERE id = ?', ['delivered', message_id]);
            messageObj.status = 'delivered';
            
            console.log(`📬 Destinatário ${receiver_id} está online, marcando como delivered`);
        }
        
        // Emitir para a sala da conversa
        io.to(`conversation_${conversation_id}`).emit('new_message', {
            conversationId: conversation_id,
            message: messageObj
        });
        
        // Notificar destinatário (sidebar)
        io.to(`user_${receiver_id}`).emit('message_notification', {
            conversationId: conversation_id,
            senderId: sender_id,
            senderUsername: senderUsername,
            senderAvatar: senderAvatar,
            content: message.substring(0, 100)
        });
        
        // Atualizar lista de conversas
        const senderConversations = await getUserConversations(sender_id);
        const receiverConversations = await getUserConversations(receiver_id);
        
        io.to(`user_${sender_id}`).emit('conversations_list', senderConversations);
        io.to(`user_${receiver_id}`).emit('conversations_list', receiverConversations);
        await emitUnreadMessagesCountToUser(receiver_id);
        
        // Emitir status update
        if (receiverSocketId) {
            io.to(`user_${sender_id}`).emit('message_status_update', {
                messageId: message_id,
                conversationId: conversation_id,
                status: 'delivered'
            });
        }
        
        res.json({ success: true, delivered: !!receiverSocketId });
        
    } catch (error) {
        console.error('❌ Erro em /api/notify-message:', error);
        res.status(500).json({ success: false, error: error.message });
    }
});

// ==========================================
// INICIAR SERVIDOR
// ==========================================

const PORT = process.env.PORT || 3001;

/**
 * Resetar todos os status online no banco ao iniciar.
 * Quando o servidor reinicia, nenhum utilizador está realmente conectado,
 * então qualquer is_online=1 no banco é stale e deve ser limpo.
 */
async function resetAllOnlineStatuses() {
    try {
        const [result] = await pool.execute('UPDATE user_online_status SET is_online = 0');
        console.log(`🧹 Status online resetados: ${result.affectedRows} registos limpos`);
    } catch (error) {
        console.error('⚠️ Erro ao resetar status online:', error.message);
    }
}

/**
 * Cleanup periódico: limpar status inconsistentes no banco.
 * Se is_online=1 mas last_seen é antigo (> 2 min), o utilizador não está realmente online.
 * Isto cobre edge cases como crash do servidor ou perda de rede sem disconnect.
 */
async function cleanupStaleOnlineStatuses() {
    try {
        const [result] = await pool.execute(`
            UPDATE user_online_status 
            SET is_online = 0 
            WHERE is_online = 1 
            AND last_seen < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        `);
        if (result.affectedRows > 0) {
            console.log(`🧹 Cleanup: ${result.affectedRows} status stale limpos`);
        }
    } catch (error) {
        console.error('⚠️ Erro no cleanup de status:', error.message);
    }
}

async function startServer() {
    const dbConnected = await testConnection();
    
    if (!dbConnected) {
        console.error('❌ Não foi possível conectar ao banco de dados. Encerrando...');
        process.exit(1);
    }
    
    // Resetar todos os status online antes de aceitar conexões
    await resetAllOnlineStatuses();
    
    server.listen(PORT, () => {
        console.log('');
        console.log('========================================');
        console.log('🚀 MyTube Chat Server');
        console.log('========================================');
        console.log(`📡 Servidor rodando na porta ${PORT}`);
        console.log(`🌐 CORS permitido para: ${corsOrigin}`);
        console.log(`🔌 Socket.IO pronto para conexões`);
        console.log(`💓 Heartbeat: 30s | Cleanup: cada 5min`);
        console.log('========================================');
        console.log('');
    });
}

startServer();

// Cleanup periódico a cada 5 minutos (1 query leve — custo negligível)
setInterval(cleanupStaleOnlineStatuses, 5 * 60 * 1000);
