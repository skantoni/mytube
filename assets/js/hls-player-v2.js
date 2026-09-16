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
 *   - Qualidade mínima: 360p (144p foi descartada). O player prefere buffering
 *     a degradar demasiado a imagem.
 *   - Warm Start: a largura de banda real medida durante a reprodução é guardada
 *     em sessionStorage e reutilizada como estimativa inicial no próximo vídeo.
 *     Assim, o 2º, 3º, ... vídeo arrancam sempre na qualidade certa em vez de
 *     voltarem ao "chute inicial" da Network Information API.
 */

// ─── Warm Start: memória de largura de banda entre vídeos ─────────────────────
// Chave usada no sessionStorage (não persiste após fechar o browser)
var _WARM_START_KEY = 'mytube_warm_bps';

/**
 * Guarda a largura de banda real medida pelo hls.js para uso no próximo vídeo.
 * Aplicamos um factor de segurança de 80% para absorver picos momentâneos.
 * @param {number} measuredBps - Valor em bps reportado por hls.bandwidthEstimate
 */
function _saveWarmBandwidth(measuredBps) {
    if (!measuredBps || measuredBps <= 0) return;
    // Factor de segurança: usamos 80% do pico medido para não sermos demasiado
    // optimistas (a rede pode ter variado durante a reprodução)
    var safeBps = Math.round(measuredBps * 0.80);
    try {
        sessionStorage.setItem(_WARM_START_KEY, safeBps);
    } catch (e) {
        // sessionStorage pode estar bloqueado em modo privado — ignorar silenciosamente
    }
}

/**
 * Lê a largura de banda guardada da sessão anterior (ou do vídeo anterior).
 * @returns {number|null} Valor em bps, ou null se não houver memória
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
 * Estima a velocidade inicial da net para o ABR controller do hls.js.
 * Prioridade: Warm Start (medido no vídeo anterior) > Network Information API > Default.
 * @returns {number} Estimativa em bits por segundo (bps)
 */
function _estimateInitialBandwidth() {
    // ── 1ª prioridade: Warm Start (memória da sessão) ──────────────────────────
    var warmBps = _readWarmBandwidth();
    if (warmBps && warmBps > 0) {
        return warmBps;
    }

    // ── 2ª prioridade: Network Information API (arranque frio) ─────────────────
    // Não disponível em Safari/iOS — retornar default alto
    if (!navigator.connection) {
        return 10 * 1000 * 1000; // 10 Mbps default
    }

    const conn = navigator.connection;
    const mbps = conn.downlink;

    // A Network Information API serve para dar um "chute inicial"
    if (mbps >= 15 || conn.effectiveType === '4g') {
        return 15 * 1000 * 1000; // 15 Mbps → forçar início em 1080p
    } else if (mbps >= 8) {
        return 10 * 1000 * 1000; // 8-15 Mbps → início em 720p
    } else if (mbps >= 3) {
        return 4 * 1000 * 1000;  // 3-8 Mbps → início em 480p
    } else {
        return 800 * 1000; // ≤ 3 Mbps → início em 360p (piso mínimo, 144p descartada)
    }
}

/**
 * Inicializa o player de vídeo com suporte a HLS ou nativo.
 * @param {HTMLVideoElement} videoEl - O elemento <video>
 * @param {string} url - URL do vídeo (pode ser .m3u8 ou .mp4)
 */
function initHlsPlayer(videoEl, url) {
    if (!videoEl || !url) {
        return;
    }

    const isHls = url.includes('.m3u8');

    if (!isHls) {
        // Vídeo antigo .mp4, usar nativo
        videoEl.src = url;
        return;
    }

    // ─── ORDEM CORRETA (padrão oficial hls.js) ────────────────────────────────
    // 1º: Verificar Hls.isSupported() — usa MSE (Chrome, Edge, Firefox)
    // 2º: Fallback para HLS nativo — apenas Safari/iOS retorna "probably"
    // ─────────────────────────────────────────────────────────────────────────

    const hlsDefined = typeof Hls !== 'undefined';

    if (hlsDefined && Hls.isSupported()) {
        // Chrome, Edge, Firefox → usar hls.js (via MediaSource API)
        if (videoEl._hlsInstance) {
            videoEl._hlsInstance.destroy();
            videoEl._hlsInstance = null;
        }

        const estimatedBps = _estimateInitialBandwidth();
        const warmActive = !!_readWarmBandwidth();

        // ── Cache Buster Estático: Contornar caches agressivas de ISPs em Angola ──
        // Usamos uma string estática (ex: 'v2_cors') em vez de Date.now().
        // Motivo: Date.now() destruiria a cache da Cloudflare (cada pedido seria único),
        // causando sobrecarga no R2. Uma string estática obriga os ISPs a ignorar 
        // a versão antiga sem CORS que eles têm presa na proxy deles, mas permite 
        // que a Cloudflare faça cache da nova versão corretamente.
        class CacheBustingLoader extends Hls.DefaultConfig.loader {
            load(context, config, callbacks) {
                const cacheBuster = `cb=v2_cors`;
                const separator = context.url.includes('?') ? '&' : '?';
                context.url += `${separator}${cacheBuster}`;
                super.load(context, config, callbacks);
            }
        }

        // ─── Calcular o nível de arranque ANTES de criar a instância ─────────
        // Com autoStartLoad:true o hls.js começa imediatamente após o manifesto,
        // por isso o startLevel tem de estar na config — não no MANIFEST_PARSED.
        // Mapeamos a estimativa de largura de banda para um nível fixo:
        //   levels[0]=360p | levels[1]=480p | levels[2]=720p | levels[3]=1080p
        // Como os níveis são sempre ordenados do menor para o maior pelo hls.js,
        // usamos índices negativos a partir do topo (totalLevels é desconhecido
        // aqui, por isso usamos -1 = topo, e confiamos no abrEwmaDefaultEstimate
        // para o ABR escolher o nível certo automaticamente no arranque).
        //
        // Estratégia: startLevel=-1 (auto ABR desde o início) + abrEwmaDefaultEstimate
        // já semeado com o warm start ou navigator.connection. O hls.js usa o EWMA
        // para escolher o nível de arranque internamente — sem delay manual.

        const hls = new Hls({
            autoStartLoad: true,             // ← Arranque imediato sem delay manual
            startLevel: -1,                  // ← ABR escolhe com base no EWMA semeado
            capLevelToPlayerSize: false,
            abrEwmaDefaultEstimate: estimatedBps, // ← Warm start / navigator.connection
            maxBufferLength: 30,
            maxMaxBufferLength: 60,
            maxBufferHole: 0.5,
            fragLoadingTimeOut: 20000,
            levelLoadingTimeOut: 10000,
            debug: false,
            pLoader: CacheBustingLoader, // Playlist Loader (master.m3u8, etc)
            fLoader: CacheBustingLoader  // Fragment Loader (.ts)
        });

        hls.loadSource(url);
        hls.attachMedia(videoEl);
        videoEl._hlsInstance = hls;

        let _firstFragLoaded = false;

        hls.on(Hls.Events.MANIFEST_PARSED, function (event, data) {
            const totalLevels = data.levels.length;
            data.levels.forEach((lvl, i) => {
            });
            // Nota: com autoStartLoad:true o hls.js já começou a carregar.
            // Não chamamos hls.startLoad() nem manipulamos o nível aqui —
            // o abrEwmaDefaultEstimate já guiou o ABR para o nível correto.
        });

        hls.on(Hls.Events.LEVEL_SWITCHED, function (event, data) {
            const levelInfo = hls.levels[data.level];
            const resolution = levelInfo ? `${levelInfo.width}x${levelInfo.height}` : 'desconhecida';
            const kbps = levelInfo ? Math.round(levelInfo.bitrate / 1000) : 0;
        });

        hls.on(Hls.Events.FRAG_LOADING, function (event, data) {
            if (!_firstFragLoaded) {
                
                // Opção 1 (Estilo TikTok): Trancar a qualidade no nível escolhido inicialmente!
                // Assim que o HLS pede o 1º fragmento (baseado na estimativa inicial), desligamos o ABR.
                if (hls.autoLevelEnabled) {
                    hls.autoLevelEnabled = false;
                    hls.currentLevel = data.frag.level;
                }
            }
        });

        hls.on(Hls.Events.FRAG_LOADED, function (event, data) {
            if (data.frag.sn === 'initSegment') return;

            // ── Warm Start: guardar a largura de banda real medida pelo hls.js ──
            if (hls.bandwidthEstimate && hls.bandwidthEstimate > 0) {
                _saveWarmBandwidth(hls.bandwidthEstimate);
            }

            if (!_firstFragLoaded) {
                _firstFragLoaded = true;
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
        // ── Warm Start: salvar a BW final antes de destruir ────────────────────
        // Garante que mesmo que o utilizador passe de vídeo a meio, guardamos
        // a última medição válida.
        var hls = videoEl._hlsInstance;
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
