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
        if (videoEl._hlsInstance) {
            videoEl._hlsInstance.destroy();
            videoEl._hlsInstance = null;
        }

        const estimatedBps = _estimateInitialBandwidth();
        console.log(`[HLS Debug] 1. Init: URL=${url}, estimatedBps=${estimatedBps}, downlink=${navigator.connection ? navigator.connection.downlink : 'N/A'}`);

        const hls = new Hls({
            autoStartLoad: false,
            capLevelToPlayerSize: false,
            abrEwmaDefaultEstimate: estimatedBps,
            maxBufferLength: 30,
            maxMaxBufferLength: 60,
            maxBufferHole: 0.5,
            fragLoadingTimeOut: 20000,
            levelLoadingTimeOut: 10000,
            debug: false // Pode ser alterado para true se quisermos log de tudo
        });

        hls.loadSource(url);
        hls.attachMedia(videoEl);
        videoEl._hlsInstance = hls;

        let _targetLevel = 0;
        let _firstFragLoaded = false;

        hls.on(Hls.Events.MANIFEST_PARSED, function (event, data) {
            const totalLevels = data.levels.length;
            console.log(`[HLS Debug] 2. MANIFEST_PARSED: totalLevels=${totalLevels}`);
            data.levels.forEach((lvl, i) => {
                console.log(`[HLS Debug]    Level ${i}: ${lvl.width}x${lvl.height} @ ${lvl.bitrate} bps`);
            });

            if (totalLevels > 1) {
                if (estimatedBps >= 8 * 1000 * 1000) {
                    _targetLevel = totalLevels - 1;
                } else if (estimatedBps >= 3 * 1000 * 1000) {
                    _targetLevel = totalLevels - 2;
                } else if (estimatedBps >= 1 * 1000 * 1000) {
                    _targetLevel = Math.max(0, totalLevels - 3);
                }
            }

            console.log(`[HLS Debug] 3. Locking targetLevel to ${_targetLevel} (Bitrate alvo: ${data.levels[_targetLevel].bitrate})`);

            hls.autoLevelEnabled = false;
            hls.startLevel    = _targetLevel;
            hls.nextLoadLevel = _targetLevel;
            hls.currentLevel  = _targetLevel;

            hls.startLoad();
        });

        hls.on(Hls.Events.LEVEL_SWITCHED, function (event, data) {
            console.log(`[HLS Debug] LEVEL_SWITCHED: Agora no level ${data.level}`);
        });

        hls.on(Hls.Events.FRAG_LOADING, function (event, data) {
            if (!_firstFragLoaded) {
                console.log(`[HLS Debug] 4. FRAG_LOADING (1º segmento): Pedindo level ${data.frag.level}`);
            }
        });

        hls.on(Hls.Events.FRAG_LOADED, function (event, data) {
            if (!_firstFragLoaded && data.frag.sn !== 'initSegment') {
                _firstFragLoaded = true;
                console.log(`[HLS Debug] 5. FRAG_LOADED (1º segmento concluído): Reativando ABR`);
                hls.autoLevelEnabled = true;
                hls.currentLevel = -1;
            }
        });

        hls.on(Hls.Events.ERROR, function (event, data) {
            if (data.fatal) {
                console.error(`[HLS Debug] FATAL ERROR: ${data.type}`);
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