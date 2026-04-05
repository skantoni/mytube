/**
 * Music Picker — Busca e seleção de músicas (Deezer).
 */

const MUSIC_SEARCH_DEBOUNCE_MS = 400;

let musicSearchTimer = null;
let musicAudioPreview = null;
let musicPreviewTrackId = null;   // id da track cujo player está aberto
let musicStartOffset = 0;         // offset escolhido pelo user (segundos)
let selectedMusic = null;
let seekDragging = false;

document.addEventListener('DOMContentLoaded', function () {
    initMusicPicker();
});

function initMusicPicker() {
    const toggle = document.getElementById('musicToggle');
    const panel = document.getElementById('musicPanel');
    const searchInput = document.getElementById('musicSearch');

    if (!toggle || !panel) return;

    // Toggle do painel
    toggle.addEventListener('change', function () {
        panel.style.display = this.checked ? 'block' : 'none';
        if (!this.checked) {
            clearMusicSelection();
        }
    });

    // Busca com debounce
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(musicSearchTimer);
            const q = this.value.trim();
            if (q.length < 2) {
                document.getElementById('musicResults').innerHTML = '';
                return;
            }
            musicSearchTimer = setTimeout(() => searchMusic(q), MUSIC_SEARCH_DEBOUNCE_MS);
        });
    }

    // Tags rápidas
    document.querySelectorAll('.music-tag-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const tag = this.dataset.tag;
            document.querySelectorAll('.music-tag-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            loadFeatured(tag);
        });
    });

    // Carregar featured ao abrir
    toggle.addEventListener('change', function () {
        if (this.checked && document.getElementById('musicResults').innerHTML.trim() === '') {
            loadFeatured('');
        }
    });

    // Volume slider
    const volumeSlider = document.getElementById('musicVolume');
    const volumeLabel = document.getElementById('musicVolumeLabel');
    if (volumeSlider && volumeLabel) {
        volumeSlider.addEventListener('input', function () {
            volumeLabel.textContent = this.value + '%';
        });
    }
}

// ============================================
// API CALLS
// ============================================

async function searchMusic(query) {
    const results = document.getElementById('musicResults');
    results.innerHTML = '<div class="music-loading"><i class="fas fa-spinner fa-spin"></i> Buscando músicas...</div>';

    try {
        const resp = await fetch('api/search_music.php?action=search&q=' + encodeURIComponent(query));
        const data = await resp.json();
        if (data.success && data.tracks) {
            renderMusicResults(data.tracks);
        } else {
            results.innerHTML = '<div class="music-empty"><i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Nenhum resultado.') + '</div>';
        }
    } catch (e) {
        results.innerHTML = '<div class="music-empty"><i class="fas fa-wifi"></i> Erro de conexão.</div>';
    }
}

async function loadFeatured(tag) {
    const results = document.getElementById('musicResults');
    results.innerHTML = '<div class="music-loading"><i class="fas fa-spinner fa-spin"></i> Carregando músicas...</div>';

    try {
        let url = tag
            ? 'api/search_music.php?action=genre&tag=' + encodeURIComponent(tag)
            : 'api/search_music.php?action=featured';
        const resp = await fetch(url);
        const data = await resp.json();
        if (data.success && data.tracks) {
            renderMusicResults(data.tracks);
        } else {
            results.innerHTML = '<div class="music-empty">' + (data.error || 'Nenhuma música disponível.') + '</div>';
        }
    } catch (e) {
        results.innerHTML = '<div class="music-empty"><i class="fas fa-wifi"></i> Erro de conexão.</div>';
    }
}

// ============================================
// RENDER
// ============================================

function renderMusicResults(tracks) {
    const container = document.getElementById('musicResults');

    if (!tracks.length) {
        container.innerHTML = '<div class="music-empty"><i class="fas fa-music"></i> Nenhuma música encontrada. Tente outro termo.</div>';
        return;
    }

    let html = '';
    tracks.forEach(track => {
        const isSelected = selectedMusic && selectedMusic.id === track.id;
        const isPlaying = musicPreviewTrackId === track.id;
        html += `
            <div class="music-track ${isSelected ? 'selected' : ''}" data-id="${escHtml(track.id)}" data-audio="${escAttr(track.audio_url)}">
                <div class="music-track-main">
                    <div class="music-track-cover">
                        ${track.image ? `<img src="${escHtml(track.image)}" alt="" loading="lazy">` : '<i class="fas fa-music"></i>'}
                    </div>
                    <div class="music-track-info">
                        <div class="music-track-name">${escHtml(track.name)}</div>
                        <div class="music-track-artist">${escHtml(track.artist)}</div>
                        <div class="music-track-meta">
                            <span class="music-track-duration"><i class="fas fa-clock"></i> ${escHtml(track.duration_fmt)}</span>
                        </div>
                    </div>
                    <div class="music-track-actions">
                        <button type="button" class="music-btn-preview ${isPlaying ? 'playing' : ''}" data-track-id="${escAttr(track.id)}" data-audio="${escAttr(track.audio_url)}" onclick="toggleMusicPreview(this)" title="Ouvir">
                            <i class="fas ${isPlaying ? 'fa-pause' : 'fa-play'}"></i>
                        </button>
                        <button type="button" class="music-btn-select ${isSelected ? 'active' : ''}" onclick="selectMusic(${escAttr(JSON.stringify(track))})" title="${isSelected ? 'Remover' : 'Selecionar'}">
                            <i class="fas ${isSelected ? 'fa-check' : 'fa-plus'}"></i>
                        </button>
                    </div>
                </div>
                <div class="music-seekbar-wrap" id="seekbar-${escHtml(track.id)}" style="display:${isPlaying ? 'block' : 'none'}">
                    <div class="music-seekbar" data-track-id="${escAttr(track.id)}">
                        <div class="music-seekbar-played" id="seekPlayed-${escHtml(track.id)}"></div>
                        <div class="music-seekbar-start" id="seekStart-${escHtml(track.id)}" title="Arraste para escolher onde a música começa no vídeo"></div>
                    </div>
                    <div class="music-seekbar-labels">
                        <span class="music-seekbar-current" id="seekTime-${escHtml(track.id)}">0:00</span>
                        <span class="music-seekbar-hint" id="seekHint-${escHtml(track.id)}">
                            <i class="fas fa-scissors"></i> Início: <strong id="seekStartLabel-${escHtml(track.id)}">0:00</strong>
                        </span>
                        <span class="music-seekbar-total" id="seekTotal-${escHtml(track.id)}">0:30</span>
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;

    // Bind seek bar drag events
    container.querySelectorAll('.music-seekbar').forEach(bar => {
        bindSeekEvents(bar);
    });
}

// ============================================
// PREVIEW AUDIO + SEEK BAR
// ============================================

function toggleMusicPreview(btn) {
    const trackId = btn.dataset.trackId;
    const audioUrl = btn.dataset.audio;
    const wasSame = musicPreviewTrackId === trackId;

    // Parar qualquer preview anterior
    stopMusicPreview();

    if (wasSame) {
        // Toggle off — just stop
        return;
    }

    // Esconder todas as seekbars, mostrar esta
    document.querySelectorAll('.music-seekbar-wrap').forEach(w => w.style.display = 'none');
    const seekWrap = document.getElementById('seekbar-' + trackId);
    if (seekWrap) seekWrap.style.display = 'block';

    musicPreviewTrackId = trackId;
    musicStartOffset = 0;

    // Se a track selecionada tem offset guardado, usar
    if (selectedMusic && selectedMusic.id === trackId && selectedMusic.startOffset) {
        musicStartOffset = selectedMusic.startOffset;
    }

    musicAudioPreview = new Audio(audioUrl);
    musicAudioPreview.volume = 0.5;
    musicAudioPreview.currentTime = musicStartOffset;

    btn.innerHTML = '<i class="fas fa-pause"></i>';
    btn.classList.add('playing');

    musicAudioPreview.addEventListener('loadedmetadata', () => {
        const totalEl = document.getElementById('seekTotal-' + trackId);
        if (totalEl) totalEl.textContent = fmtSec(musicAudioPreview.duration);
        updateSeekStartMarker(trackId);
    });

    musicAudioPreview.addEventListener('timeupdate', () => {
        if (seekDragging) return;
        const dur = musicAudioPreview.duration || 30;
        const pct = (musicAudioPreview.currentTime / dur) * 100;
        const playedEl = document.getElementById('seekPlayed-' + trackId);
        const timeEl = document.getElementById('seekTime-' + trackId);
        if (playedEl) playedEl.style.width = pct + '%';
        if (timeEl) timeEl.textContent = fmtSec(musicAudioPreview.currentTime);
    });

    musicAudioPreview.addEventListener('ended', () => {
        // Loop: voltar ao offset escolhido
        if (musicAudioPreview) {
            musicAudioPreview.currentTime = musicStartOffset;
            musicAudioPreview.play().catch(() => {});
        }
    });

    musicAudioPreview.play().catch(() => {});
}

function stopMusicPreview() {
    if (musicAudioPreview) {
        musicAudioPreview.pause();
        musicAudioPreview = null;
    }
    musicPreviewTrackId = null;
    document.querySelectorAll('.music-btn-preview').forEach(b => {
        b.innerHTML = '<i class="fas fa-play"></i>';
        b.classList.remove('playing');
    });
}

// ============================================
// SEEK BAR DRAG — escolher ponto de início
// ============================================

function bindSeekEvents(bar) {
    const trackId = bar.dataset.trackId;

    function onPointerDown(e) {
        seekDragging = true;
        updateSeekFromPointer(bar, trackId, e);
        const onMove = (ev) => updateSeekFromPointer(bar, trackId, ev);
        const onUp = () => {
            seekDragging = false;
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchend', onUp);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchend', onUp);
    }

    bar.addEventListener('mousedown', onPointerDown);
    bar.addEventListener('touchstart', function (e) {
        e.preventDefault();
        onPointerDown(e.touches[0]);
    }, { passive: false });
}

function updateSeekFromPointer(bar, trackId, e) {
    const rect = bar.getBoundingClientRect();
    const clientX = e.clientX ?? e.pageX;
    let pct = (clientX - rect.left) / rect.width;
    pct = Math.max(0, Math.min(1, pct));

    const dur = (musicAudioPreview && musicAudioPreview.duration) || 30;
    musicStartOffset = pct * dur;

    // Mover marcador
    updateSeekStartMarker(trackId);

    // Saltar audio para esse ponto
    if (musicAudioPreview) {
        musicAudioPreview.currentTime = musicStartOffset;
    }

    // Atualizar label
    const label = document.getElementById('seekStartLabel-' + trackId);
    if (label) label.textContent = fmtSec(musicStartOffset);

    // Guardar no hiddenInput se esta track está selecionada
    updateStartOffsetInSelection();
}

function updateSeekStartMarker(trackId) {
    const dur = (musicAudioPreview && musicAudioPreview.duration) || 30;
    const pct = (musicStartOffset / dur) * 100;
    const marker = document.getElementById('seekStart-' + trackId);
    if (marker) marker.style.left = pct + '%';
    const label = document.getElementById('seekStartLabel-' + trackId);
    if (label) label.textContent = fmtSec(musicStartOffset);
}

function updateStartOffsetInSelection() {
    if (!selectedMusic) return;
    selectedMusic.startOffset = musicStartOffset;
    const hiddenInput = document.getElementById('musicTrackData');
    if (hiddenInput) {
        hiddenInput.value = JSON.stringify({
            id: selectedMusic.id,
            name: selectedMusic.name,
            artist: selectedMusic.artist,
            download_url: selectedMusic.download_url,
            start_offset: musicStartOffset,
        });
    }
    const startInput = document.getElementById('musicStartOffset');
    if (startInput) startInput.value = musicStartOffset.toFixed(2);
}

function fmtSec(s) {
    s = Math.max(0, s || 0);
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    return m + ':' + String(sec).padStart(2, '0');
}

// ============================================
// SELECTION
// ============================================

function selectMusic(track) {
    const selectedPanel = document.getElementById('musicSelected');
    const hiddenInput = document.getElementById('musicTrackData');
    const startInput = document.getElementById('musicStartOffset');

    // Toggle: se já está selecionado o mesmo, desselecionar
    if (selectedMusic && selectedMusic.id === track.id) {
        clearMusicSelection();
        renderCurrentResults();
        return;
    }

    // Guardar offset actual se a track é a que está em preview
    const offset = (musicPreviewTrackId === track.id) ? musicStartOffset : 0;
    selectedMusic = { ...track, startOffset: offset };

    // Preencher hidden inputs
    if (hiddenInput) {
        hiddenInput.value = JSON.stringify({
            id: track.id,
            name: track.name,
            artist: track.artist,
            download_url: track.download_url,
            start_offset: offset,
        });
    }
    if (startInput) startInput.value = offset.toFixed(2);

    // Mostrar banner de seleção
    if (selectedPanel) {
        selectedPanel.style.display = 'flex';
        selectedPanel.innerHTML = `
            <div class="music-selected-info">
                <div class="music-selected-icon"><i class="fas fa-music"></i></div>
                <div>
                    <div class="music-selected-name">${escHtml(track.name)}</div>
                    <div class="music-selected-artist">${escHtml(track.artist)} &bull; ${escHtml(track.duration_fmt)}</div>
                    <div class="music-selected-offset"><i class="fas fa-scissors"></i> Início: ${fmtSec(offset)}</div>
                </div>
            </div>
            <button type="button" class="music-selected-remove" onclick="clearMusicSelection(); renderCurrentResults();" title="Remover música">
                <i class="fas fa-times"></i>
            </button>
        `;
    }

    // Atualizar botões na lista
    renderCurrentResults();
}

function clearMusicSelection() {
    selectedMusic = null;
    musicStartOffset = 0;
    const hiddenInput = document.getElementById('musicTrackData');
    const selectedPanel = document.getElementById('musicSelected');
    const startInput = document.getElementById('musicStartOffset');
    if (hiddenInput) hiddenInput.value = '';
    if (startInput) startInput.value = '0';
    if (selectedPanel) {
        selectedPanel.style.display = 'none';
        selectedPanel.innerHTML = '';
    }

    stopMusicPreview();
    document.querySelectorAll('.music-seekbar-wrap').forEach(w => w.style.display = 'none');
}

function renderCurrentResults() {
    // Re-render results to update selected state
    const container = document.getElementById('musicResults');
    if (!container) return;
    const trackEls = container.querySelectorAll('.music-track');
    trackEls.forEach(el => {
        const id = el.dataset.id;
        const isSelected = selectedMusic && selectedMusic.id === id;
        el.classList.toggle('selected', isSelected);
        const selectBtn = el.querySelector('.music-btn-select');
        if (selectBtn) {
            selectBtn.classList.toggle('active', isSelected);
            selectBtn.innerHTML = isSelected ? '<i class="fas fa-check"></i>' : '<i class="fas fa-plus"></i>';
            selectBtn.title = isSelected ? 'Remover' : 'Selecionar';
        }
    });
}

// ============================================
// HELPERS
// ============================================

function escHtml(str) {
    if (typeof str !== 'string') str = String(str ?? '');
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escAttr(str) {
    if (typeof str !== 'string') str = String(str ?? '');
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
