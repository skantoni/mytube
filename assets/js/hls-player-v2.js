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
        console.log('[HLS Debug] 💾 Warm start guardado: ' + Math.round(safeBps / 1000) + ' kbps');
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
        console.log('[HLS Debug] 🔥 Warm start ativo: usando ' + Math.round(warmBps / 1000) + ' kbps da sessão anterior');
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
        console.log(`[HLS Debug] 1. Init: URL=${url}, estimatedBps=${estimatedBps}, warmStart=${warmActive}, downlink=${navigator.connection ? navigator.connection.downlink : 'N/A'}`);

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

        const hls = new Hls({
            autoStartLoad: false,
            capLevelToPlayerSize: false,
            abrEwmaDefaultEstimate: estimatedBps,
            maxBufferLength: 30,
            maxMaxBufferLength: 60,
            maxBufferHole: 0.5,
            fragLoadingTimeOut: 20000,
            levelLoadingTimeOut: 10000,
            debug: false, // Pode ser alterado para true se quisermos log de tudo
            pLoader: CacheBustingLoader, // Playlist Loader (master.m3u8, etc)
            fLoader: CacheBustingLoader  // Fragment Loader (.ts)
        });

        hls.loadSource(url);
        hls.attachMedia(videoEl);
        videoEl._hlsInstance = hls;

        let _targetLevel = 0;
        let _minLevel = 0;     // Piso mínimo real — calculado no MANIFEST_PARSED
        let _firstFragLoaded = false;

        hls.on(Hls.Events.MANIFEST_PARSED, function (event, data) {
            const totalLevels = data.levels.length;
            console.log(`[HLS Debug] 2. MANIFEST_PARSED: totalLevels=${totalLevels}`);
            data.levels.forEach((lvl, i) => {
                console.log(`[HLS Debug]    Level ${i}: ${lvl.width}x${lvl.height} @ ${lvl.bitrate} bps`);
            });

            // ── Calcular o piso mínimo real para ESTE vídeo ────────────────────
            // Vídeos antigos têm 144p como level 0 — não podemos assumir que
            // level 0 é sempre 360p. Procuramos o primeiro nível com height >= 360.
            // Se não existir nenhum (vídeo muito antigo só com 240p/144p), usamos o
            // nível mais alto disponível como mínimo aceitável.
            _minLevel = totalLevels - 1; // fallback: nível mais alto disponível
            for (let i = 0; i < totalLevels; i++) {
                if (data.levels[i].height >= 360) {
                    _minLevel = i;
                    break;
                }
            }
            console.log(`[HLS Debug] 2b. Piso mínimo calculado: level ${_minLevel} (${data.levels[_minLevel].width}x${data.levels[_minLevel].height})`);

            // ── Selecionar nível inicial com base na largura de banda estimada ──
            // Usa _minLevel como piso — nunca inicia abaixo de 360p.
            _targetLevel = _minLevel; // default: piso mínimo aceitável
            if (totalLevels > 1) {
                if (estimatedBps >= 15 * 1000 * 1000) {
                    _targetLevel = totalLevels - 1;           // 1080p
                } else if (estimatedBps >= 8 * 1000 * 1000) {
                    _targetLevel = Math.max(_minLevel, totalLevels - 2); // 720p
                } else if (estimatedBps >= 3 * 1000 * 1000) {
                    _targetLevel = Math.max(_minLevel, totalLevels - 3); // 480p
                } else {
                    _targetLevel = _minLevel; // 360p — piso mínimo
                }
            }

            console.log(`[HLS Debug] 3. Locking targetLevel to ${_targetLevel} (Bitrate alvo: ${data.levels[_targetLevel].bitrate})`);

            hls.autoLevelEnabled = false;
            hls.startLevel    = _targetLevel;
            hls.nextLoadLevel = _targetLevel;
            hls.currentLevel  = _targetLevel;

            hls.startLoad();
        });

        // ── Enforçar o piso mínimo no ABR livre ───────────────────────────────
        // Quando o hls.js tenta descer abaixo do piso (ex: 144p em vídeos antigos),
        // forçamos de volta para _minLevel. Funciona tanto em vídeos novos (360p
        // como nível 0) como em vídeos antigos (que ainda têm 144p).
        hls.on(Hls.Events.LEVEL_SWITCHING, function (event, data) {
            if (_firstFragLoaded && data.level < _minLevel) {
                console.log(`[HLS Debug] 🚫 ABR tentou descer para level ${data.level} (abaixo do piso). A forçar level ${_minLevel}.`);
                hls.nextLoadLevel = _minLevel;
            }
        });

        hls.on(Hls.Events.LEVEL_SWITCHED, function (event, data) {
            const levelInfo = hls.levels[data.level];
            const resolution = levelInfo ? `${levelInfo.width}x${levelInfo.height}` : 'desconhecida';
            const kbps = levelInfo ? Math.round(levelInfo.bitrate / 1000) : 0;
            console.log(`[HLS Debug] 🔄 LEVEL_SWITCHED: Agora no level ${data.level} (${resolution} @ ${kbps} kbps)`);
        });

        hls.on(Hls.Events.FRAG_LOADING, function (event, data) {
            if (!_firstFragLoaded) {
                console.log(`[HLS Debug] 4. FRAG_LOADING (1º segmento): Pedindo level ${data.frag.level}`);
            }
        });

        hls.on(Hls.Events.FRAG_LOADED, function (event, data) {
            if (data.frag.sn === 'initSegment') return;

            // ── Warm Start: guardar a largura de banda real medida pelo hls.js ──
            // Fazemos isto em TODOS os segmentos (não só o primeiro) para que a
            // estimativa fique cada vez mais precisa ao longo da reprodução.
            if (hls.bandwidthEstimate && hls.bandwidthEstimate > 0) {
                _saveWarmBandwidth(hls.bandwidthEstimate);
            }

            if (!_firstFragLoaded) {
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