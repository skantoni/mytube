/**
 * MyTube Live Server — Servidor Principal
 * Socket.IO para streaming em tempo real via MediaRecorder API
 * Porta: 3002
 */

require('dotenv').config();
require('node:dns').setDefaultResultOrder('ipv4first');
const crypto = require('crypto');
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const { pool, testConnection } = require('./config/database');

// ─────────────────────────────────────────────
// JWT Verificação (mesmo padrão do chat-server)
// ─────────────────────────────────────────────
function verifyToken(token) {
    try {
        const secret = process.env.CHAT_JWT_SECRET || 'CHANGE_ME_IN_PRODUCTION';
        const parts = token.split('.');
        if (parts.length !== 3) return null;

        const [header, payload, sig] = parts;
        const expectedSig = crypto
            .createHmac('sha256', secret)
            .update(`${header}.${payload}`)
            .digest('base64url');

        if (expectedSig !== sig) return null;

        const data = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
        if (!data.exp || data.exp < Math.floor(Date.now() / 1000)) return null;
        if (!Number.isInteger(data.userId) || data.userId <= 0) return null;

        return data;
    } catch {
        return null;
    }
}

// ─────────────────────────────────────────────
// Express + Socket.IO
// ─────────────────────────────────────────────
const app = express();
const server = http.createServer(app);

const corsOrigin = process.env.CORS_ORIGIN || 'http://localhost';
app.use(cors({ origin: corsOrigin, credentials: true }));
app.use(express.json());

const io = new Server(server, {
    path: '/live-socket/',
    cors: {
        origin: corsOrigin,
        methods: ['GET', 'POST'],
        credentials: true
    },
    pingTimeout: 60000,
    pingInterval: 25000,
    maxHttpBufferSize: 10 * 1024 * 1024 // 10MB por chunk de vídeo
});

// ─────────────────────────────────────────────
// Estado em Memória
// ─────────────────────────────────────────────

// Map: streamId -> { streamerId, streamerUsername, title, viewers: Set<socketId>, startedAt }
const activeStreams = new Map();

// Map: socketId -> { userId, username, streamId (se viewer ou streamer) }
const connectedClients = new Map();

// Map: userId -> socketId (único por utilizador neste servidor)
const userSocket = new Map();

// ─────────────────────────────────────────────
// Funções de Base de Dados
// ─────────────────────────────────────────────

async function getUserById(userId) {
    try {
        const [rows] = await pool.execute(
            'SELECT id, username, full_name, profile_picture, is_verified, role FROM users WHERE id = ? LIMIT 1',
            [userId]
        );
        return rows[0] || null;
    } catch (err) {
        console.error('❌ getUserById:', err.message);
        return null;
    }
}

async function getStreamById(streamId) {
    try {
        const [rows] = await pool.execute(`
            SELECT ls.*, u.username, u.full_name, u.profile_picture, u.is_verified
            FROM livestreams ls
            JOIN users u ON u.id = ls.user_id
            WHERE ls.id = ? LIMIT 1
        `, [streamId]);
        return rows[0] || null;
    } catch (err) {
        console.error('❌ getStreamById:', err.message);
        return null;
    }
}

async function markStreamLive(streamId) {
    try {
        await pool.execute(
            "UPDATE livestreams SET status = 'live', started_at = NOW() WHERE id = ?",
            [streamId]
        );
    } catch (err) {
        console.error('❌ markStreamLive:', err.message);
    }
}

async function markStreamEnded(streamId, peakViewers) {
    try {
        await pool.execute(
            "UPDATE livestreams SET status = 'ended', ended_at = NOW(), peak_viewers = ? WHERE id = ?",
            [peakViewers, streamId]
        );
    } catch (err) {
        console.error('❌ markStreamEnded:', err.message);
    }
}

async function updateViewerCount(streamId, count) {
    try {
        await pool.execute(
            'UPDATE livestreams SET viewers_count = ? WHERE id = ?',
            [count, streamId]
        );
    } catch (err) {
        console.error('❌ updateViewerCount:', err.message);
    }
}

async function saveLivestreamMessage(streamId, userId, message, type = 'text') {
    try {
        const [result] = await pool.execute(
            'INSERT INTO livestream_messages (stream_id, user_id, message, type, created_at) VALUES (?, ?, ?, ?, NOW())',
            [streamId, userId, message, type]
        );
        return result.insertId;
    } catch (err) {
        console.error('❌ saveLivestreamMessage:', err.message);
        return null;
    }
}

async function recordViewer(streamId, userId) {
    try {
        await pool.execute(`
            INSERT IGNORE INTO livestream_viewers (stream_id, user_id, joined_at)
            VALUES (?, ?, NOW())
        `, [streamId, userId]);
        // Incrementar total_views
        await pool.execute(
            'UPDATE livestreams SET total_views = total_views + 1 WHERE id = ?',
            [streamId]
        );
    } catch (err) {
        console.error('❌ recordViewer:', err.message);
    }
}

async function notifyFollowers(streamId, streamerId, streamerUsername, streamTitle) {
    try {
        // Buscar todos os seguidores
        const [followers] = await pool.execute(
            'SELECT follower_id FROM follows WHERE following_id = ?',
            [streamerId]
        );

        if (followers.length === 0) return;

        // Inserir notificação no DB para cada seguidor (para quando ficarem online)
        const values = followers.map(f => [
            f.follower_id,  // user_id
            streamerId,     // actor_id
            'livestream_start',
            streamId,
            `@${streamerUsername} está ao vivo: ${streamTitle}`
        ]);

        // Inserir em batch
        for (const v of values) {
            await pool.execute(
                'INSERT INTO notifications (user_id, actor_id, type, reference_id, message) VALUES (?, ?, ?, ?, ?)',
                v
            );
        }

        // Notificar seguidores que estão online agora via Socket.IO
        followers.forEach(f => {
            const followerSocketId = userSocket.get(f.follower_id);
            if (followerSocketId) {
                const followerSocket = io.sockets.sockets.get(followerSocketId);
                if (followerSocket) {
                    followerSocket.emit('livestream_notification', {
                        streamId,
                        streamerId,
                        streamerUsername,
                        streamTitle,
                        message: `@${streamerUsername} está ao vivo!`
                    });
                }
            }
        });

        console.log(`✅ Notificados ${followers.length} seguidores de @${streamerUsername}`);
    } catch (err) {
        console.error('❌ notifyFollowers:', err.message);
    }
}

// ─────────────────────────────────────────────
// Middleware Socket.IO — Autenticação JWT
// ─────────────────────────────────────────────
io.use((socket, next) => {
    const token = socket.handshake.auth?.token;
    
    // Visitante (Não autenticado)
    if (!token) {
        socket.userId = null;
        socket.username = 'Visitante';
        return next();
    }

    const payload = verifyToken(token);
    
    // Token inválido -> Tratar como Visitante
    if (!payload) {
        socket.userId = null;
        socket.username = 'Visitante';
        return next();
    }

    // Utilizador autenticado
    socket.userId = payload.userId;
    socket.username = payload.username || 'Utilizador';
    next();
});

// ─────────────────────────────────────────────
// Conexão Socket.IO
// ─────────────────────────────────────────────
io.on('connection', async (socket) => {
    const { userId, username } = socket;
    console.log(`🔌 Conectado: @${username} (userId=${userId}, socketId=${socket.id})`);

    // Registar cliente
    connectedClients.set(socket.id, { userId, username, streamId: null });
    userSocket.set(userId, socket.id);

    // ─── LIVE FEED (Página Inicial de Lives) ─────────────────
    socket.on('join_live_feed', () => {
        socket.join('live_feed');
    });

    socket.on('leave_live_feed', () => {
        socket.leave('live_feed');
    });


    // ─── STREAMER: Iniciar Transmissão (chat/eventos — vídeo via LiveKit) ───
    socket.on('go_live', async ({ streamId }) => {
        try {
            const stream = await getStreamById(streamId);

            if (!stream) {
                socket.emit('error', { message: 'Stream não encontrada' });
                return;
            }

            if (stream.user_id !== userId) {
                socket.emit('error', { message: 'Não tens permissão para esta stream' });
                return;
            }

            // Nota: autenticação de vídeo é feita pelo LiveKit.
            // Este handler trata apenas chat e eventos de ciclo de vida.

            // Criar sala da stream
            const roomName = `stream_${streamId}`;
            socket.join(roomName);

            // Registar no estado (sem ring buffer — vídeo é via LiveKit)
            activeStreams.set(streamId, {
                streamId,
                streamerId: userId,
                streamerUsername: username,
                streamerSocketId: socket.id,
                title: stream.title,
                viewers: new Set(),
                peakViewers: 0,
                startedAt: Date.now()
            });

            // Atualizar socket
            const client = connectedClients.get(socket.id);
            if (client) client.streamId = streamId;

            // Marcar como live no DB
            await markStreamLive(streamId);

            // Notificar seguidores
            await notifyFollowers(streamId, userId, username, stream.title);

            socket.emit('go_live_success', {
                streamId,
                roomName,
                message: 'Estás ao vivo! 🔴'
            });

            // 📢 Broadcast para a página de Feed (live.php)
            io.to('live_feed').emit('feed_stream_started', {
                id: streamId,
                title: stream.title,
                category: stream.category,
                thumbnail_path: stream.thumbnail_path,
                viewers_count: 0,
                streamer: {
                    id: stream.user_id,
                    username: stream.username,
                    full_name: stream.full_name,
                    profile_picture: stream.profile_picture,
                    is_verified: stream.is_verified
                }
            });

            console.log(`🔴 Stream iniciada: #${streamId} por @${username}`);
        } catch (err) {
            console.error('❌ go_live:', err.message);
            socket.emit('error', { message: 'Erro ao iniciar stream' });
        }
    });

    // ─── STREAMER: Enviar Chunk de Vídeo ────────────────
    socket.on('stream_chunk', (data) => {
        const client = connectedClients.get(socket.id);
        if (!client?.streamId) return;

        const stream = activeStreams.get(client.streamId);
        if (!stream || stream.streamerId !== userId) return;

        if (!data) return; // ← Proteção contra crash se o chunk vier vazio
        const buf = Buffer.from(data);

        // Guardar os primeiros 2 chunks como init segment (cabeçalho EBML + Tracks)
        if (stream.initSegmentCount < 2) {
            stream.initSegmentCount++;
            stream.initSegment = stream.initSegment
                ? Buffer.concat([stream.initSegment, buf])
                : buf;
        }

        // Ring buffer: manter os últimos 40 chunks (~20s de vídeo a 500ms/chunk)
        stream.recentChunks.push(buf);
        if (stream.recentChunks.length > 40) {
            stream.recentChunks.shift(); // Remover o mais antigo
        }

        // Rebroadcast para todos os viewers na sala (exceto o streamer)
        socket.to(`stream_${client.streamId}`).emit('stream_chunk', data);
    });

    // ─── STREAMER: Terminar Stream ───────────────────────────
    socket.on('end_stream', async () => {
        const client = connectedClients.get(socket.id);
        if (!client?.streamId) return;

        const streamId = client.streamId;
        const stream = activeStreams.get(streamId);

        if (!stream || stream.streamerId !== userId) return;

        // Notificar todos os viewers
        io.to(`stream_${streamId}`).emit('stream_ended', {
            streamId,
            message: `@${username} terminou o stream`
        });

        // Atualizar DB
        await markStreamEnded(streamId, stream.peakViewers);
        await updateViewerCount(streamId, 0);

        // 📢 Broadcast para a página de Feed
        io.to('live_feed').emit('feed_stream_ended', { streamId });

        // Limpar estado
        activeStreams.delete(streamId);
        client.streamId = null;

        socket.leave(`stream_${streamId}`);

        socket.emit('end_stream_success', { streamId });
        console.log(`⏹️ Stream terminada: #${streamId} por @${username}`);
    });

    // ─── VIEWER: Entrar na Stream ────────────────────────────
    socket.on('join_stream', async ({ streamId }) => {
        try {
            const sid = parseInt(streamId, 10);
            if (!Number.isInteger(sid) || sid <= 0) return;

            const stream = await getStreamById(sid);
            if (!stream || stream.status !== 'live') {
                socket.emit('stream_not_found', { streamId: sid });
                return;
            }

            const roomName = `stream_${sid}`;
            socket.join(roomName);

            // Atualizar estado
            const client = connectedClients.get(socket.id);
            if (client) client.streamId = sid;

            const liveStream = activeStreams.get(sid);
            if (liveStream) {
                liveStream.viewers.add(socket.id);
                const viewerCount = liveStream.viewers.size;

                // Actualizar pico
                if (viewerCount > liveStream.peakViewers) {
                    liveStream.peakViewers = viewerCount;
                }

                // Emitir contagem atualizada para todos na sala e no feed
                io.to(roomName).emit('viewer_count', { count: viewerCount });
                io.to('live_feed').emit('feed_viewers_update', { streamId: sid, count: viewerCount });

                // Actualizar DB
                await updateViewerCount(sid, viewerCount);

                // 🎬 Enviar init segment + ring buffer ao novo viewer
                // O browser vai encontrar um keyframe no ring buffer e arrancar a descodificar
                if (liveStream.initSegment) {
                    // 1. Enviar o cabeçalho EBML (init segment)
                    socket.emit('stream_init', liveStream.initSegment);
                    
                    // 2. Enviar os últimos chunks em batch (ring buffer)
                    const recent = liveStream.recentChunks;
                    if (recent.length > 0) {
                        // Enviar com pequeno delay para o MSE ter tempo de processar o init
                        setTimeout(() => {
                            recent.forEach(chunk => socket.emit('stream_chunk', chunk));
                            console.log(`🎬 Ring buffer enviado: ${recent.length} chunks ao viewer @${username}`);
                        }, 100);
                    } else {
                        console.log(`🎬 Init segment enviado ao viewer @${username} (${liveStream.initSegment.length} bytes)`);
                    }
                }
            }

            // Registar viewer no DB
            await recordViewer(sid, userId);

            // Enviar info da stream ao viewer
            socket.emit('join_stream_success', {
                streamId: sid,
                streamerUsername: stream.username,
                title: stream.title,
                viewerCount: liveStream?.viewers.size || 0
            });

            // Anunciar no chat que alguém entrou (mensagem de sistema)
            socket.to(roomName).emit('system_message', {
                message: `@${username} entrou no stream`,
                type: 'join'
            });

            console.log(`👁️ Viewer @${username} entrou na stream #${sid}`);
        } catch (err) {
            console.error('❌ join_stream:', err.message);
        }
    });

    // ─── VIEWER: Sair da Stream ──────────────────────────────
    socket.on('leave_stream', async () => {
        await handleViewerLeave(socket);
    });

    // ─── CHAT: Enviar Mensagem ───────────────────────────────
    socket.on('live_chat_message', async ({ streamId, message }) => {
        try {
            const sid = parseInt(streamId, 10);
            if (!Number.isInteger(sid) || sid <= 0) return;
            if (!message || typeof message !== 'string') return;

            const clean = message.trim().substring(0, 500); // max 500 chars
            if (!clean) return;

            // Verificar se stream está activa
            const stream = activeStreams.get(sid);
            if (!stream) return;

            // Buscar info do utilizador
            const user = await getUserById(userId);
            if (!user) return;

            // Salvar no DB
            const msgId = await saveLivestreamMessage(sid, userId, clean);

            // Broadcast para todos na sala
            io.to(`stream_${sid}`).emit('live_chat_message', {
                id: msgId,
                userId,
                username: user.username,
                profilePicture: user.profile_picture,
                isVerified: user.is_verified,
                message: clean,
                createdAt: new Date().toISOString()
            });
        } catch (err) {
            console.error('❌ live_chat_message:', err.message);
        }
    });

    // ─── DESCONEXÃO ──────────────────────────────────────────
    socket.on('disconnect', async () => {
        console.log(`🔌 Desconectado: @${username} (socketId=${socket.id})`);

        const client = connectedClients.get(socket.id);

        if (client?.streamId) {
            const stream = activeStreams.get(client.streamId);

            if (stream) {
                if (stream.streamerId === userId) {
                    // ─ Streamer desconectou — terminar stream automaticamente
                    io.to(`stream_${client.streamId}`).emit('stream_ended', {
                        streamId: client.streamId,
                        message: `@${username} perdeu a ligação`
                    });
                    await markStreamEnded(client.streamId, stream.peakViewers);
                    await updateViewerCount(client.streamId, 0);
                    
                    // 📢 Broadcast feed
                    io.to('live_feed').emit('feed_stream_ended', { streamId: client.streamId });
                    activeStreams.delete(client.streamId);
                    console.log(`⏹️ Stream #${client.streamId} terminada por desconexão do streamer`);
                } else {
                    // ─ Viewer desconectou
                    stream.viewers.delete(socket.id);
                    const viewerCount = stream.viewers.size;
                    io.to(`stream_${client.streamId}`).emit('viewer_count', { count: viewerCount });
                    io.to('live_feed').emit('feed_viewers_update', { streamId: client.streamId, count: viewerCount });
                    await updateViewerCount(client.streamId, viewerCount);
                }
            }
        }

        // Limpar estado
        connectedClients.delete(socket.id);
        if (userSocket.get(userId) === socket.id) {
            userSocket.delete(userId);
        }
    });
});

// Helper: processar saída de viewer
async function handleViewerLeave(socket) {
    const client = connectedClients.get(socket.id);
    if (!client?.streamId) return;

    const sid = client.streamId;
    const stream = activeStreams.get(sid);
    const roomName = `stream_${sid}`;

    socket.leave(roomName);
    client.streamId = null;

    if (stream && stream.streamerId !== socket.userId) {
        stream.viewers.delete(socket.id);
        const viewerCount = stream.viewers.size;
        io.to(roomName).emit('viewer_count', { count: viewerCount });
        io.to('live_feed').emit('feed_viewers_update', { streamId: sid, count: viewerCount });
        await updateViewerCount(sid, viewerCount);
    }
}

// ─────────────────────────────────────────────
// REST API Endpoints
// ─────────────────────────────────────────────

// Health check
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        activeStreams: activeStreams.size,
        connectedClients: connectedClients.size,
        uptime: process.uptime()
    });
});

// Lista de streams ativas (para o PHP chamar)
app.get('/api/active-streams', (req, res) => {
    const streams = [];
    activeStreams.forEach((s, id) => {
        streams.push({
            streamId: id,
            streamerUsername: s.streamerUsername,
            title: s.title,
            viewers: s.viewers.size,
            startedAt: s.startedAt
        });
    });
    res.json({ streams });
});

// ─────────────────────────────────────────────
// Arrancar Servidor
// ─────────────────────────────────────────────
const PORT = process.env.PORT || 3002;

async function start() {
    const dbOk = await testConnection();
    if (!dbOk) {
        console.error('❌ Não foi possível conectar ao banco. Servidor não iniciado.');
        process.exit(1);
    }

    server.listen(PORT, () => {
        console.log(`🔴 MyTube Live Server a correr na porta ${PORT}`);
        console.log(`📡 CORS permitido para: ${corsOrigin}`);
        console.log(`🌐 Health check: http://localhost:${PORT}/health`);
    });
}

start();
