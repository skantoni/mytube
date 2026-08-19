/**
 * hls-player.js — MyTube HLS Player
 *
 * Usa a biblioteca hls.js para tocar vídeos no formato HLS (.m3u8).
 * Compatível com vídeos .mp4 antigos (usa o player nativo nesses casos).
 *
 * Estratégia de qualidade inicial (como as Big Techs):
 *   - Usa abrEwmaDefaultEstimate para "pré-aquecer" o ABR controller com uma
 *     estimativa de largura de banda ANTES de qualquer medição real.
 *   - Valor é lido da Network Information API se disponível, caso contrário usa
 *     10 Mbps como default (suficiente para começar em 720p na maioria dos casos).
 *   - O ABR controller continua a ajustar automaticamente após a primeira medição.
 */

/**
 * Estima a velocidade inicial da net para o ABR controller do hls.js.
 * @returns {number} Estimativa em bits por segundo (bps)
 */
function _estimateInitialBandwidth() {
    // Não disponível em Safari/iOS — retornar default alto
    if (!navigator.connection) {
        return 10 * 1000 * 1000; // 10 Mbps default
    }

    const conn = navigator.connection;
    const mbps = conn.downlink;

    // A Network Information API serve para dar um "chute inicial"
    if (mbps >= 8 || conn.effectiveType === '4g') {
        return 10 * 1000 * 1000; // 10 Mbps → forçar início em 720p
    } else if (mbps >= 3) {
        return 4 * 1000 * 1000;  // 4 Mbps → início em 480p
    } else if (mbps >= 1) {
        return 1.5 * 1000 * 1000; // 1.5 Mbps → início em 360p
    } else {
        return 500 * 1000; // 500 Kbps → início em 144p
    }
}

/**
 * Inicializa o player de vídeo com suporte a HLS ou nativo.
 * @param {HTMLVideoElement} videoEl - O elemento <video>
 * @param {string} url - URL do vídeo (pode ser .m3u8 ou .mp4)
 */
function initHlsPlayer(videoEl, url) {
    if (!videoEl || !url) return;

    const isHls = url.includes('.m3u8');

    if (!isHls) {
        // Vídeo .mp4 antigo: usar o player nativo normalmente
        videoEl.src = url;
        return;
    }

    // Verificar se o browser suporta HLS nativamente (Safari / iOS)
    if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
        videoEl.src = url;
        return;
    }

    // Verificar se a biblioteca hls.js está carregada
    if (typeof Hls === 'undefined') {
        console.warn('⚠️ hls.js não carregado — a tentar fallback para src nativo:', url);
        videoEl.src = url;
        return;
    }

    if (Hls.isSupported()) {
        // Destruir instância anterior se existir (evitar memory leaks e sobreposição de áudio)
        if (videoEl._hlsInstance) {
            videoEl._hlsInstance.destroy();
            videoEl._hlsInstance = null;
        }

        // --- A TÉCNICA DEFINITIVA: autoStartLoad: false ---
        //
        // Com autoStartLoad: true (o default), o hls.js começa a descarregar o
        // primeiro segmento IMEDIATAMENTE, antes mesmo do MANIFEST_PARSED disparar.
        // Isso significa que qualquer tentativa de definir o nível no MANIFEST_PARSED
        // é IGNORADA — o download já está a decorrer.
        //
        // A solução: autoStartLoad: false congela o hls.js após carregar o manifest,
        // dando-nos controlo total para forçar o nível antes de qualquer download.
        // Depois chamamos hls.startLoad() manualmente dentro do MANIFEST_PARSED.
        // O abrEwmaDefaultEstimate é crucial porque diz ao hls.js qual a velocidade 
        // esperada antes de descarregar os primeiros pacotes de teste.
        const estimatedBps = _estimateInitialBandwidth();

        const hls = new Hls({
            autoStartLoad: false, // Congelar até definirmos o nível
            
            // ---- Configurações de ABR (Adaptive Bitrate) ----
            startLevel: -1,
            abrEwmaDefaultEstimate: estimatedBps,
            abrBandWidthFactor: 0.85,
            abrBandWidthUpFactor: 0.7,
            
            // Garantir que não limita a qualidade ao tamanho do ecrã no telemóvel
            // (onde a densidade de pixeis exige resoluções maiores que os pixeis CSS)
            capLevelToPlayerSize: false,

            // ---- Buffer e robustez ----
            maxBufferLength: 30,
            maxMaxBufferLength: 60,
            maxBufferHole: 0.5,
            fragLoadingTimeOut: 20000,
            levelLoadingTimeOut: 10000,
        });

        hls.loadSource(url);
        hls.attachMedia(videoEl);

        videoEl._hlsInstance = hls;

        // MANIFEST_PARSED: O manifest foi descarregado e os níveis estão disponíveis.
        hls.on(Hls.Events.MANIFEST_PARSED, function (event, data) {
            const totalLevels = data.levels.length; // ex: 4 (144p, 360p, 480p, 720p)
            const bps = estimatedBps;

            let targetLevel = 0; // fallback: qualidade mais baixa

            if (totalLevels > 1) {
                if (bps >= 8 * 1000 * 1000) {
                    // ≥ 8 Mbps → Máxima qualidade (ex: 720p = índice 3)
                    targetLevel = totalLevels - 1;
                } else if (bps >= 3 * 1000 * 1000) {
                    // ≥ 3 Mbps → Qualidade alta-média (ex: 480p = índice 2)
                    targetLevel = totalLevels - 2;
                } else if (bps >= 1 * 1000 * 1000) {
                    // ≥ 1 Mbps → Qualidade média (ex: 360p = índice 1)
                    targetLevel = Math.max(0, totalLevels - 3);
                }
            }

            // A TÉCNICA CORRETA: hls.startLevel define qual o índice que o 
            // startLoad() vai usar para pedir o PRIMEIRO segmento.
            // (Usar hls.currentLevel aqui seria ignorado porque o stream não arrancou)
            hls.startLevel = targetLevel;

            // Agora sim, autorizamos o HLS a descarregar o primeiro segmento (já na qualidade certa)
            hls.startLoad();
        });

        hls.on(Hls.Events.ERROR, function (event, data) {
            if (data.fatal) {
                switch (data.type) {
                    case Hls.ErrorTypes.NETWORK_ERROR:
                        hls.startLoad();
                        break;
                    case Hls.ErrorTypes.MEDIA_ERROR:
                        hls.recoverMediaError();
                        break;
                    default:
                        hls.destroy();
                        break;
                }
            }
        });
    } else {
        videoEl.src = url;
    }
}

/**
 * Destruir a instância HLS de um elemento de vídeo (ao mudar de vídeo ou fechar modal).
 * Limpa também o buffer interno do browser para evitar sobreposição de áudio.
 * @param {HTMLVideoElement} videoEl
 */
function destroyHlsPlayer(videoEl) {
    if (videoEl && videoEl._hlsInstance) {
        videoEl._hlsInstance.destroy();
        videoEl._hlsInstance = null;
    }
    if (videoEl) {
        videoEl.pause();
        videoEl.removeAttribute('src');
        videoEl.load(); // Limpa o buffer interno do browser
    }
}