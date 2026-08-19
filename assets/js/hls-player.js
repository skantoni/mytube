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

        const estimatedBps = _estimateInitialBandwidth();

        // ╔══════════════════════════════════════════════════════════════════╗
        // ║  ABORDAGEM MÁXIMA: PIN + RELEASE                                ║
        // ║                                                                  ║
        // ║  1. autoStartLoad: false  → Congela o HLS antes de qualquer     ║
        // ║     download de segmento.                                        ║
        // ║                                                                  ║
        // ║  2. No MANIFEST_PARSED:                                          ║
        // ║     a) autoLevelEnabled = false  → DESLIGA o ABR totalmente.     ║
        // ║        O motor de qualidade automática fica mudo.                ║
        // ║     b) Forçar o nível nos 3 pontos de controlo em simultâneo:   ║
        // ║        - hls.startLevel     → diz ao startLoad() qual usar      ║
        // ║        - hls.nextLoadLevel  → fila de download directa           ║
        // ║        - hls.currentLevel   → pin do nível actual               ║
        // ║     c) startLoad() → arranca. O PRIMEIRO segmento é descarregado ║
        // ║        GARANTIDAMENTE no nível escolhido. Sem excepções.         ║
        // ║                                                                  ║
        // ║  3. No FRAG_LOADED (1º fragmento descarregado com sucesso):      ║
        // ║     → Re-activar o ABR (autoLevelEnabled = true, currentLevel=-1)║
        // ║       para que o HLS volte a adaptar automaticamente a partir    ║
        // ║       deste momento, com base em medições REAIS de velocidade.   ║
        // ╚══════════════════════════════════════════════════════════════════╝
        const hls = new Hls({
            autoStartLoad: false,       // Passo 1: congelar até ao MANIFEST_PARSED
            capLevelToPlayerSize: false, // Não limitar qualidade pelo tamanho CSS do player
            abrEwmaDefaultEstimate: estimatedBps, // Seed de velocidade para o ABR (usado após release)
            maxBufferLength: 30,
            maxMaxBufferLength: 60,
            maxBufferHole: 0.5,
            fragLoadingTimeOut: 20000,
            levelLoadingTimeOut: 10000,
        });

        hls.loadSource(url);
        hls.attachMedia(videoEl);
        videoEl._hlsInstance = hls;

        // Calcular o nível alvo uma só vez
        let _targetLevel = 0;
        let _firstFragLoaded = false;

        hls.on(Hls.Events.MANIFEST_PARSED, function (event, data) {
            const totalLevels = data.levels.length;

            if (totalLevels > 1) {
                if (estimatedBps >= 8 * 1000 * 1000) {
                    _targetLevel = totalLevels - 1; // 720p
                } else if (estimatedBps >= 3 * 1000 * 1000) {
                    _targetLevel = totalLevels - 2; // 480p
                } else if (estimatedBps >= 1 * 1000 * 1000) {
                    _targetLevel = Math.max(0, totalLevels - 3); // 360p
                }
            }

            // Passo 2a: DESLIGAR o ABR completamente
            hls.autoLevelEnabled = false;

            // Passo 2b: Forçar o nível nos 3 pontos de controlo em simultâneo
            hls.startLevel    = _targetLevel;
            hls.nextLoadLevel = _targetLevel;
            hls.currentLevel  = _targetLevel;

            // Passo 2c: Agora o HLS pode arrancar — primeiro segmento será no nível correcto
            hls.startLoad();
        });

        // Passo 3: Assim que o primeiro fragmento for descarregado com sucesso,
        // RE-ACTIVAR o ABR para que o player adapte a qualidade de forma inteligente
        // a partir daqui, com base em medições reais de velocidade de download.
        hls.on(Hls.Events.FRAG_LOADED, function (event, data) {
            if (!_firstFragLoaded && data.frag.sn !== 'initSegment') {
                _firstFragLoaded = true;
                hls.autoLevelEnabled = true;  // Libertar o ABR
                hls.currentLevel = -1;         // Voltar ao modo automático
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