/**
 * MyTube WhatsApp Bot - server.js
 *
 * Serviço interno que conecta ao WhatsApp pessoal via Baileys (pairing code)
 * e expõe uma rota HTTP local para o PHP enviar mensagens de verificação.
 *
 * Uso: node server.js
 */

import express from 'express';
import { fileURLToPath } from 'url';
import path from 'path';
import fs from 'fs';
import readline from 'readline';
import pino from 'pino';

import makeWASocket, {
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    Browsers,
} from '@whiskeysockets/baileys';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ── Configuração ─────────────────────────────────────────────────────────────
const PORT     = 3002;
const AUTH_DIR = path.join(__dirname, 'auth_info');
const logger   = pino({ level: 'silent' }); // muda para 'info' para ver logs detalhados

// ── Estado global ─────────────────────────────────────────────────────────────
let sock    = null;
let isReady = false;

// ── Utilitários ───────────────────────────────────────────────────────────────

function normalizePhoneToJid(phone) {
    let digits = phone.replace(/\D/g, '');
    if (digits.startsWith('244') && digits.length === 12) return `${digits}@s.whatsapp.net`;
    if (digits.length === 9) return `244${digits}@s.whatsapp.net`;
    return `${digits}@s.whatsapp.net`;
}

function askPhoneNumber() {
    return new Promise((resolve) => {
        const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
        rl.question('\n📱 Insira o número do seu WhatsApp (só dígitos, ex: 933751074 ou 244933751074): ', (answer) => {
            rl.close();
            resolve(answer.trim());
        });
    });
}

// ── Conexão ao WhatsApp ───────────────────────────────────────────────────────

async function connectToWhatsApp() {
    if (!fs.existsSync(AUTH_DIR)) fs.mkdirSync(AUTH_DIR, { recursive: true });

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    // 1. Pedir o número ANTES de criar o socket (se ainda não estiver registado)
    let phoneToRegister = null;
    if (!state.creds.registered) {
        const raw  = await askPhoneNumber();
        let digits = raw.replace(/\D/g, '');
        if (digits.length === 9) digits = '244' + digits;
        phoneToRegister = digits;
        console.log(`\n📞 A usar número: +${phoneToRegister}`);
    }

    // 2. Buscar a versão mais recente do WhatsApp Web (evita rejeição do servidor)
    const { version } = await fetchLatestBaileysVersion();
    console.log(`🔍 Versão WhatsApp Web: ${version.join('.')}`);

    // 3. Criar o socket — passar auth: state diretamente (não usar makeCacheableSignalKeyStore
    //    durante o pairing code pois causa falha 401 no handshake)
    sock = makeWASocket({
        version,
        auth: state,
        logger,
        printQRInTerminal: false,
        browser: Browsers.ubuntu('Chrome'),
        syncFullHistory: false,
        generateHighQualityLinkPreview: false,
        connectTimeoutMs: 60000,
    });

    // 4. Registar eventos ANTES de pedir o código
    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect } = update;

        if (connection === 'close') {
            isReady = false;
            const reason = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = reason !== DisconnectReason.loggedOut;
            console.log(`\n⚠️  Conexão encerrada (código: ${reason}). Reconectar: ${shouldReconnect}`);
            if (shouldReconnect) {
                console.log('🔄 A reconectar em 5 segundos...');
                setTimeout(connectToWhatsApp, 5000);
            } else {
                console.log('🚪 Sessão encerrada. Apague a pasta auth_info/ e reinicie.');
                process.exit(0);
            }
        }

        if (connection === 'open') {
            isReady = true;
            console.log('✅ Bot conectado ao WhatsApp com sucesso!');
            console.log(`🌐 API interna disponível em http://localhost:${PORT}`);
            console.log('💬 Pronto para enviar mensagens de verificação!\n');
        }
    });

    // 5. Pedir o código de emparelhamento IMEDIATAMENTE após criar o socket
    if (phoneToRegister) {
        try {
            // Pequena espera para o handshake inicial (não para a conexão abrir)
            await new Promise(r => setTimeout(r, 1500));
            const code = await sock.requestPairingCode(phoneToRegister);
            console.log('\n' + '═'.repeat(52));
            console.log(`🔑  CÓDIGO DE EMPARELHAMENTO:  ${code}`);
            console.log('═'.repeat(52));
            console.log('📲  No seu telemóvel:');
            console.log('    WhatsApp → Configurações');
            console.log('    → Aparelhos conectados');
            console.log('    → Conectar um aparelho');
            console.log('    → Conectar com número de telefone');
            console.log('    → Insira o código acima');
            console.log('═'.repeat(52) + '\n');
        } catch (err) {
            console.error('❌ Erro ao gerar código:', err.message);
            console.log('💡 Dica: Apague a pasta auth_info/ e tente novamente.');
            process.exit(1);
        }
    }

    // Ignorar mensagens recebidas
    sock.ev.on('messages.upsert', () => {});
}

// ── Servidor Express (API interna para o PHP) ─────────────────────────────────

const app = express();
app.use(express.json());

// Segurança: só aceita chamadas de localhost
app.use((req, res, next) => {
    const ip = req.ip || req.connection?.remoteAddress || '';
    if (!['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(ip)) {
        return res.status(403).json({ success: false, message: 'Acesso negado.' });
    }
    next();
});

/**
 * POST /send-message
 * Body JSON: { "phone": "244933751074", "message": "Seu código é 123456" }
 */
app.post('/send-message', async (req, res) => {
    const { phone, message } = req.body;

    if (!phone || !message) {
        return res.status(400).json({ success: false, message: '"phone" e "message" são obrigatórios.' });
    }
    if (!isReady || !sock) {
        return res.status(503).json({ success: false, message: 'Bot não conectado. Verifique o terminal.' });
    }

    try {
        const jid = normalizePhoneToJid(String(phone));
        await sock.sendMessage(jid, { text: message });
        console.log(`📤 Mensagem enviada → ${phone}`);
        return res.json({ success: true, message: 'Mensagem enviada.' });
    } catch (err) {
        console.error(`❌ Erro ao enviar para ${phone}:`, err.message);
        return res.status(500).json({ success: false, message: 'Erro ao enviar: ' + err.message });
    }
});

/**
 * GET /status
 * Diagnóstico: verifica se o bot está conectado.
 */
app.get('/status', (_req, res) => {
    res.json({ success: true, connected: isReady, message: isReady ? 'Bot conectado.' : 'Bot não conectado.' });
});

// ── Inicialização ─────────────────────────────────────────────────────────────

app.listen(PORT, '127.0.0.1', () => {
    console.log(`🚀 MyTube WhatsApp Bot a iniciar...\n`);
});

connectToWhatsApp().catch((err) => {
    console.error('Erro fatal:', err.message);
    process.exit(1);
});

process.on('unhandledRejection', (reason) => {
    console.error('⚠️  Erro não tratado:', reason);
});
