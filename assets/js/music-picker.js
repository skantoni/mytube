/**
 * Music Picker — Busca e seleção de músicas royalty-free (Jamendo).
 */

const MUSIC_SEARCH_DEBOUNCE_MS = 400;
const MUSIC_TAGS = ['pop', 'rock', 'electronic', 'hiphop', 'jazz', 'ambient', 'classical', 'lofi'];

let musicSearchTimer = null;
let musicAudioPreview = null;
let selectedMusic = null;

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
        let url = 'api/search_music.php?action=featured';
        if (tag) url += '&tag=' + encodeURIComponent(tag);
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
        html += `
            <div class="music-track ${isSelected ? 'selected' : ''}" data-id="${escHtml(track.id)}">
                <div class="music-track-cover">
                    ${track.image ? `<img src="${escHtml(track.image)}" alt="" loading="lazy">` : '<i class="fas fa-music"></i>'}
                </div>
                <div class="music-track-info">
                    <div class="music-track-name">${escHtml(track.name)}</div>
                    <div class="music-track-artist">${escHtml(track.artist)}</div>
                    <div class="music-track-meta">
                        <span class="music-track-duration"><i class="fas fa-clock"></i> ${escHtml(track.duration_fmt)}</span>
                        ${track.genre ? `<span class="music-track-genre">${escHtml(track.genre)}</span>` : ''}
                    </div>
                </div>
                <div class="music-track-actions">
                    <button type="button" class="music-btn-preview" onclick="toggleMusicPreview(this, '${escAttr(track.audio_url)}')" title="Ouvir prévia">
                        <i class="fas fa-play"></i>
                    </button>
                    <button type="button" class="music-btn-select ${isSelected ? 'active' : ''}" onclick="selectMusic(${escAttr(JSON.stringify(track))})" title="${isSelected ? 'Remover' : 'Selecionar'}">
                        <i class="fas ${isSelected ? 'fa-check' : 'fa-plus'}"></i>
                    </button>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// ============================================
// PREVIEW AUDIO
// ============================================

function toggleMusicPreview(btn, audioUrl) {
    // Parar qualquer preview anterior
    if (musicAudioPreview) {
        musicAudioPreview.pause();
        musicAudioPreview.currentTime = 0;
        document.querySelectorAll('.music-btn-preview').forEach(b => {
            b.innerHTML = '<i class="fas fa-play"></i>';
            b.classList.remove('playing');
        });
    }

    // Se o mesmo botão foi clicado, só parar
    if (btn.classList.contains('playing')) {
        musicAudioPreview = null;
        return;
    }

    musicAudioPreview = new Audio(audioUrl);
    musicAudioPreview.volume = 0.5;
    musicAudioPreview.play().catch(() => {});
    btn.innerHTML = '<i class="fas fa-pause"></i>';
    btn.classList.add('playing');

    musicAudioPreview.addEventListener('ended', () => {
        btn.innerHTML = '<i class="fas fa-play"></i>';
        btn.classList.remove('playing');
        musicAudioPreview = null;
    });
}

// ============================================
// SELECTION
// ============================================

function selectMusic(track) {
    const selectedPanel = document.getElementById('musicSelected');
    const hiddenInput = document.getElementById('musicTrackData');

    // Toggle: se já está selecionado o mesmo, desselecionar
    if (selectedMusic && selectedMusic.id === track.id) {
        clearMusicSelection();
        renderCurrentResults();
        return;
    }

    selectedMusic = track;

    // Preencher hidden inputs
    if (hiddenInput) {
        hiddenInput.value = JSON.stringify({
            id: track.id,
            name: track.name,
            artist: track.artist,
            download_url: track.download_url,
        });
    }

    // Mostrar banner de seleção
    if (selectedPanel) {
        selectedPanel.style.display = 'flex';
        selectedPanel.innerHTML = `
            <div class="music-selected-info">
                <div class="music-selected-icon"><i class="fas fa-music"></i></div>
                <div>
                    <div class="music-selected-name">${escHtml(track.name)}</div>
                    <div class="music-selected-artist">${escHtml(track.artist)} &bull; ${escHtml(track.duration_fmt)}</div>
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
    const hiddenInput = document.getElementById('musicTrackData');
    const selectedPanel = document.getElementById('musicSelected');
    if (hiddenInput) hiddenInput.value = '';
    if (selectedPanel) {
        selectedPanel.style.display = 'none';
        selectedPanel.innerHTML = '';
    }

    // Parar preview se estiver tocando
    if (musicAudioPreview) {
        musicAudioPreview.pause();
        musicAudioPreview = null;
    }
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
