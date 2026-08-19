/**
 * hls-player.js — MyTube HLS Player
 *
 * Usa a biblioteca hls.js para tocar vídeos no formato HLS (.m3u8).
 * Compatível com vídeos .mp4 antigos (usa o player nativo nesses casos).
 */

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
        // Destruir instância anterior se existir (evitar memory leaks)
        if (videoEl._hlsInstance) {
            videoEl._hlsInstance.destroy();
            videoEl._hlsInstance = null;
        }

        const hls = new Hls({
            startLevel: -1,
            autoLevelEnabled: true,
            maxBufferLength: 30,
            maxMaxBufferLength: 60,
            fragLoadingTimeOut: 20000,
        });

        hls.loadSource(url);
        hls.attachMedia(videoEl);

        videoEl._hlsInstance = hls;

        // --- MAGIA DAS BIG TECHS: Escolher a qualidade inicial baseada na net ---
        hls.on(Hls.Events.MANIFEST_PARSED, function (event, data) {
            // data.levels tem as qualidades ordenadas por bitrate (0 = pior, ex: 144p | N = melhor, ex: 720p)
            const totalLevels = data.levels.length;
            
            if (navigator.connection && totalLevels > 1) {
                const conn = navigator.connection;
                // downlink é a velocidade de download estimada em Mbps
                const mbps = conn.downlink || 0;
                
                let targetLevel = -1; // -1 = Auto (hls.js escolhe a mais baixa por padrão)

                if (mbps >= 8 || conn.effectiveType === '4g') {
                    // Net muito boa: Arranca na Máxima (ex: 720p)
                    targetLevel = totalLevels - 1;
                } else if (mbps >= 3) {
                    // Net média: Arranca na qualidade do meio (ex: 360p / 480p)
                    targetLevel = Math.floor(totalLevels / 2);
                }
                
                if (targetLevel !== -1) {
                    hls.startLevel = targetLevel;
                }
            }
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
 * @param {HTMLVideoElement} videoEl
 */
function destroyHlsPlayer(videoEl) {
    if (videoEl && videoEl._hlsInstance) {
        videoEl._hlsInstance.destroy();
        videoEl._hlsInstance = null;
    }
}