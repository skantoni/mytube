/**
 * hls-player-v2.js — MyTube HLS Player
 *
 * Usa a biblioteca hls.js para tocar vídeos no formato HLS (.m3u8).
 * Compatível com vídeos .mp4 antigos (usa o player nativo nesses casos).
 *
 * Estratégia de qualidade (ABR):
 *   - abrEwmaDefaultEstimate: "semeia" o ABR controller com uma estimativa de
 *     largura de banda antes de qualquer medição real.
 *   - Warm Start: a BW medida no vídeo anterior é guardada em sessionStorage e
 *     reutilizada no próximo. O 2º, 3º, ... vídeo arranc sempre na qualidade certa.
 *   - Cold Start: se não houver warm start, usamos 1.5 Mbps como default conservador
 *     (adequado para 3G angolano). É melhor começar em 360p e subir do que começar
 *     em 720p, bloquear e mostrar ecrã preto.
 *
 * Gestão de banda (stopLoad / startLoad):
 *   - Quando um vídeo sai do viewport, o tiktok.js chama hls.stopLoad() para parar
 *     de consumir banda no background.
 *   - Quando volta ao viewport, playVideo() chama hls.startLoad() para retomar.
 *   - O buffer já construído é preservado — não há re-download.
 */

// ─── Warm Start: memória de largura de banda entre vídeos ─────────────────────
var _WARM_START_KEY = 'mytube_warm_bps';

/**
 * Guarda a BW real (80% do pico medido como margem de segurança).
 * @param {number} measuredBps
 */
function _saveWarmBandwidth(measuredBps) {
    if (!measuredBps || measuredBps <= 0) return;
    var safeBps = Math.round(measuredBps * 0.80);
    try {
        sessionStorage.setItem(_WARM_START_KEY, safeBps);
    } catch (e) {
        // sessionStorage pode estar bloqueado em modo privado — ignorar silenciosamente
    }
}

/**
 * Lê a BW guardada da sessão.
 * @returns {number|null}
 */
function _readWarmBandwidth() {
    try {
        var val = sessionStorage.getItem(_WARM_START_KEY);
        return val ? parseInt(val, 10) : null;
    } catch (e) {
        return null;
    }
}

/**
 * Estima a BW inicial para semear o ABR controller.
 * Prioridade: Warm Start > Network Information API > Default conservador.
 * @returns {number} bps
 */
function _estimateInitialBandwidth() {
    // 1ª prioridade: Warm Start (medição real do vídeo anterior)
    var warmBps = _readWarmBandwidth();
    if (warmBps && warmBps > 0) {
        return warmBps;
    }

    // 2ª prioridade: Network Information API
    if (!navigator.connection) {
        // Fix A: default conservador de 1.5 Mbps (3G angolano típico)
        // Melhor começar em 360p e subir do que começar em 720p e bloquear.
        return 1500 * 1000;
    }

    const conn = navigator.connection;
    const mbps = conn.downlink;

    // Mapeamento cuidadoso: 4g genérico pode ser 5 Mbps ou 50 Mbps
    if (conn.effectiveType === '4g' && mbps >= 15) {
        return 15 * 1000 * 1000; // 4G rápido → 1080p
    } else if (mbps >= 8 || conn.effectiveType === '4g') {
        return 8 * 1000 * 1000;  // 4G médio → 720p
    } else if (mbps >= 3) {
        return 3 * 1000 * 1000;  // 3G+ → 480p
    } else {
        return 800 * 1000;       // 3G lento / 2G → 360p (mínimo)
    }
}

/**
 * Inicializa o player HLS ou nativo.
 * @param {HTMLVideoElement} videoEl
 * @param {string} url — URL do .m3u8 ou .mp4
 */
function initHlsPlayer(videoEl, url) {
    if (!videoEl || !url) return;

    const isHls = url.includes('.m3u8');

    if (!isHls) {
        // MP4 legado — nativo do browser
        videoEl.src = url;
        return;
    }

    // Chrome / Edge / Firefox → hls.js (MSE)
    // Safari / iOS           → HLS nativo (fallback no else)
    const hlsDefined = typeof Hls !== 'undefined';

    if (hlsDefined && Hls.isSupported()) {
        // Destruir instância anterior se existir
        if (videoEl._hlsInstance) {
            videoEl._hlsInstance.destroy();
            videoEl._hlsInstance = null;
        }

        const estimatedBps = _estimateInitialBandwidth();

        // ── Cache Buster Estático ───────────────────────────────────────────────
        // String estática 'v2_cors' em vez de Date.now() para não destruir a cache
        // da Cloudflare. Apenas contorna a cache das ISPs angolanas (Unitel/Africell)
        // que guardaram versões antigas sem CORS.
        class CacheBustingLoader extends Hls.DefaultConfig.loader {
            load(context, config, callbacks) {
                const sep = context.url.includes('?') ? '&' : '?';
                context.url += `${sep}cb=v2_cors`;
                super.load(context, config, callbacks);
            }
        }

        // Fix B: Buffer adaptativo por qualidade de rede
        // Redes lentas (3G): 10s de buffer suficiente para arrancar e poupar RAM
        // Redes rápidas (4G/WiFi): 30s para experiência suave sem re-buffering
        const nq = window.networkQuality;
        const isLow = nq && nq.quality === 'low';
        const maxBufLen    = isLow ? 10 : 30;  // Fix B — era sempre 30
        const maxMaxBufLen = isLow ? 20 : 60;  // Fix B — era sempre 60

        const hls = new Hls({
            autoStartLoad: true,
            startLevel: -1,                        // ABR decide com base no EWMA semeado
            capLevelToPlayerSize: false,
            abrEwmaDefaultEstimate: estimatedBps,  // Warm start / Network API / 1.5 Mbps
            maxBufferLength:    maxBufLen,          // Fix B
            maxMaxBufferLength: maxMaxBufLen,       // Fix B
            maxBufferHole: 0.5,
            fragLoadingTimeOut:  8000,              // Fix D — era 20000 (20s!) → agora 8s
            levelLoadingTimeOut: 6000,              // Fix D — era 10000 → agora 6s
            debug: false,
            pLoader: CacheBustingLoader,
            fLoader: CacheBustingLoader
        });

        hls.loadSource(url);
        hls.attachMedia(videoEl);
        videoEl._hlsInstance = hls;

        hls.on(Hls.Events.LEVEL_SWITCHED, function (event, data) {
            const lvl = hls.levels[data.level];
            if (lvl) {
                console.log(`[HLS] 🔄 ${lvl.width}x${lvl.height} @ ${Math.round(lvl.bitrate / 1000)} kbps`);
            }
        });

        hls.on(Hls.Events.FRAG_LOADED, function (event, data) {
            if (data.frag.sn === 'initSegment') return;
            // Warm Start: actualizar a estimativa de BW após cada fragmento
            if (hls.bandwidthEstimate && hls.bandwidthEstimate > 0) {
                _saveWarmBandwidth(hls.bandwidthEstimate);
            }
        });

        hls.on(Hls.Events.ERROR, function (event, data) {
            if (data.fatal) {
                switch (data.type) {
                    case Hls.ErrorTypes.NETWORK_ERROR:
                        // Tentar retomar o download em vez de destruir
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

    } else if (videoEl.canPlayType('application/vnd.apple.mpegurl')) {
        // Safari / iOS — HLS nativo
        videoEl.src = url;
    }
}

/**
 * Destrói a instância HLS e limpa o elemento de vídeo.
 * Deve ser chamado ao virtualizar um vídeo distante ou ao fechar o feed.
 * @param {HTMLVideoElement} videoEl
 */
function destroyHlsPlayer(videoEl) {
    if (videoEl && videoEl._hlsInstance) {
        const hls = videoEl._hlsInstance;
        // Guardar BW antes de destruir (para warm start do próximo vídeo)
        if (hls.bandwidthEstimate && hls.bandwidthEstimate > 0) {
            _saveWarmBandwidth(hls.bandwidthEstimate);
        }
        hls.destroy();
        videoEl._hlsInstance = null;
    }
    if (videoEl) {
        videoEl.pause();
        videoEl.removeAttribute('src');
        videoEl.load(); // Limpa o buffer interno do browser
    }
}
