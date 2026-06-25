/**
 * MyTube Admin Panel — boosted-videos.js
 * v2.0 — Sidebar tabs, Moderação, Boosted metrics, Rankings
 */
(function () {
    'use strict';

    // ── Helpers ───────────────────────────────────────────────
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function formatCompactNumber(n) {
        n = Number(n) || 0;
        if (n >= 1e9)  return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
        if (n >= 1e6)  return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (n >= 1e3)  return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
        return String(n);
    }

    function getCtrClass(ctr) {
        if (ctr >= 5)  return 'ctr-high';
        if (ctr >= 2)  return 'ctr-medium';
        return 'ctr-low';
    }

    function getCtrFillClass(ctr) {
        if (ctr >= 5)  return 'ctr-fill-high';
        if (ctr >= 2)  return 'ctr-fill-medium';
        return 'ctr-fill-low';
    }

    // ── Toast ─────────────────────────────────────────────────
    function showToast(message, type) {
        type = type || 'info';
        const container = document.getElementById('apToastContainer');
        if (!container) return;

        const iconMap = {
            success: 'fa-check-circle',
            error: 'fa-circle-xmark',
            warn: 'fa-triangle-exclamation',
            info: 'fa-circle-info',
        };

        const toast = document.createElement('div');
        toast.className = 'ap-toast ' + type;
        toast.innerHTML = '<i class="fas ' + (iconMap[type] || iconMap.info) + '"></i><span>' + message + '</span>';
        container.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(10px)';
            toast.style.transition = 'opacity .2s, transform .2s';
            setTimeout(function () { toast.remove(); }, 220);
        }, 2200);
    }

    // ── Tab / section switching ───────────────────────────────
    function switchSection(name) {
        // Sidebar nav items
        document.querySelectorAll('.ap-nav-item[data-section]').forEach(function (el) {
            el.classList.toggle('active', el.dataset.section === name);
        });

        // Mobile tabs
        document.querySelectorAll('.ap-mobile-tab[data-section]').forEach(function (el) {
            el.classList.toggle('active', el.dataset.section === name);
        });

        // Sections
        document.querySelectorAll('.ap-section').forEach(function (el) {
            el.classList.toggle('active', el.id === 'section-' + name);
        });

        // Persist in URL hash
        try { history.replaceState(null, '', '#' + name); } catch (_) {}
    }

    function initTabs() {
        // Sidebar nav items
        document.querySelectorAll('.ap-nav-item[data-section]').forEach(function (el) {
            el.addEventListener('click', function () {
                switchSection(el.dataset.section);
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    switchSection(el.dataset.section);
                }
            });
        });

        // Trigger buttons (inside sections)
        document.querySelectorAll('.ap-nav-trigger[data-section]').forEach(function (el) {
            el.addEventListener('click', function () {
                switchSection(el.dataset.section);
            });
        });

        // Mobile bottom tabs
        document.querySelectorAll('.ap-mobile-tab[data-section]').forEach(function (el) {
            el.addEventListener('click', function () {
                switchSection(el.dataset.section);
            });
        });

        // Restore from URL hash
        const hash = (window.location.hash || '').replace('#', '');
        const validSections = ['overview', 'moderation', 'boosted', 'rankings', 'premium', 'ads', 'users'];
        if (hash && validSections.indexOf(hash) !== -1) {
            switchSection(hash);
        }
    }

    // ── Sub-tabs (Rankings) ───────────────────────────────────
    function initSubtabs() {
        document.querySelectorAll('.ap-subtab[data-subtab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const name = btn.dataset.subtab;
                const container = btn.closest('[id]') || document.body;

                // Update button active state (within same .ap-subtabs container)
                btn.closest('.ap-subtabs').querySelectorAll('.ap-subtab').forEach(function (b) {
                    b.classList.toggle('active', b === btn);
                });

                // Show/hide panels
                document.querySelectorAll('.ap-subtab-panel').forEach(function (panel) {
                    panel.classList.toggle('active', panel.id === 'subtab-' + name);
                });
            });
        });
    }

    // ── Moderation actions ────────────────────────────────────
    function moderationAction(videoId, action) {
        const card = document.getElementById('modCard_' + videoId);
        if (!card) return;

        card.classList.add('is-resolving');

        const body = new URLSearchParams({
            action: action,
            video_id: videoId,
            csrf_token: getCsrfToken(),
        });

        fetch('api/admin_moderate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                // Animate card out
                card.classList.remove('is-resolving');
                card.classList.add('is-removing');

                setTimeout(function () {
                    card.remove();
                    updateModerationCounters(-1);

                    // If grid empty, show empty state
                    const grid = document.getElementById('moderationGrid');
                    if (grid && grid.querySelectorAll('.ap-mod-card').length === 0) {
                        grid.classList.add('ap-hidden');
                        const emptyState = document.getElementById('moderationEmptyState');
                        if (emptyState) emptyState.classList.remove('ap-hidden');
                    }
                }, 280);

                const msg = action === 'approve' ? 'Vídeo aprovado!' : 'Vídeo rejeitado.';
                const type = action === 'approve' ? 'success' : 'warn';
                showToast(msg, type);
            } else {
                card.classList.remove('is-resolving');
                showToast(data.error || 'Erro ao processar.', 'error');
            }
        })
        .catch(function () {
            card.classList.remove('is-resolving');
            showToast('Erro de ligação.', 'error');
        });
    }

    function updateModerationCounters(delta) {
        // Count pill
        const pill = document.getElementById('pendingCountPill');
        if (pill) {
            const current = parseInt(pill.textContent) || 0;
            const next = Math.max(0, current + delta);
            pill.textContent = next + ' vídeo' + (next !== 1 ? 's' : '') + ' pendente' + (next !== 1 ? 's' : '');
        }

        // Sidebar badge
        const badge = document.getElementById('apModerationBadge');
        if (badge) {
            const current = parseInt(badge.textContent) || 0;
            const next = Math.max(0, current + delta);
            if (next <= 0) {
                badge.remove();
            } else {
                badge.textContent = next;
            }
        }

        // Mobile badge
        const mobileBadge = document.getElementById('apModerationMobileBadge');
        if (mobileBadge) {
            const current = parseInt(mobileBadge.textContent) || 0;
            const next = Math.max(0, current + delta);
            if (next <= 0) mobileBadge.remove();
            else mobileBadge.textContent = next;
        }
    }

    function initModeration() {
        document.addEventListener('click', function (e) {
            const approveBtn = e.target.closest('.js-mod-approve');
            const rejectBtn  = e.target.closest('.js-mod-reject');

            if (approveBtn) {
                const vid = approveBtn.dataset.videoId;
                if (vid) moderationAction(vid, 'approve');
            } else if (rejectBtn) {
                const vid = rejectBtn.dataset.videoId;
                if (vid) moderationAction(vid, 'reject');
            }
        });
    }

    // ── Boost metrics ─────────────────────────────────────────
    function loadBoostMetrics() {
        fetch('api/boost_metrics.php?days=30')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;

                // Summary stats
                const avgCtrEl      = document.getElementById('boostedAvgCtr');
                const impressionsEl = document.getElementById('boostedImpressions');
                const reachEl       = document.getElementById('boostedReach');

                const avgCtr = data.avg_ctr != null ? parseFloat(data.avg_ctr) : null;

                if (avgCtrEl) {
                    avgCtrEl.textContent = avgCtr != null ? avgCtr.toFixed(1) + '%' : '—';
                    if (avgCtr != null) avgCtrEl.className = 'ap-stat-value ctr-value ' + getCtrClass(avgCtr);
                }
                if (impressionsEl) impressionsEl.textContent = formatCompactNumber(data.total_impressions || 0);
                if (reachEl)       reachEl.textContent       = formatCompactNumber(data.unique_reach || 0);

                // Per-video CTR bars
                if (Array.isArray(data.videos)) {
                    data.videos.forEach(function (v) {
                        updateVideoCtr(v.video_id, v.ctr, v.impressions, v.unique_reach);
                    });
                }
            })
            .catch(function () {
                // Silently ignore metrics errors
            });
    }

    function updateVideoCtr(videoId, ctr, impressions, reach) {
        const ctrVal  = document.getElementById('ctrValue_' + videoId);
        const ctrFill = document.getElementById('ctrFill_' + videoId);
        const ctrImp  = document.getElementById('ctrImpressions_' + videoId);
        const ctrReach = document.getElementById('ctrReach_' + videoId);

        const ctrNum = parseFloat(ctr) || 0;

        if (ctrVal) {
            ctrVal.textContent = ctrNum.toFixed(1) + '%';
            ctrVal.className = 'ctr-value ' + getCtrClass(ctrNum);
        }

        if (ctrFill) {
            const pct = Math.min(100, ctrNum * 5); // scale: 20% CTR = 100% bar
            ctrFill.style.width = pct + '%';
            ctrFill.className = 'ctr-bar-fill ' + getCtrFillClass(ctrNum);
        }

        if (ctrImp)   ctrImp.textContent   = formatCompactNumber(impressions || 0) + ' impressões';
        if (ctrReach) ctrReach.textContent  = formatCompactNumber(reach || 0) + ' users';
    }

    // ── Remove boost ──────────────────────────────────────────
    function removeBoost(videoId) {
        const card = document.querySelector('.boosted-video-card[data-video-id="' + videoId + '"]');
        if (!card) return;

        const btn = card.querySelector('.js-remove-boost');
        if (btn) { btn.disabled = true; btn.textContent = 'A remover…'; }

        const body = new URLSearchParams({
            video_id: videoId,
            action: 'remove',
            csrf_token: getCsrfToken(),
        });

        fetch('api/toggle_boost.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                card.classList.add('is-removing');
                setTimeout(function () {
                    card.remove();
                    updateBoostedSummary(-1);
                    showToast('Boost removido.', 'info');
                }, 270);
            } else {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> Remover boost'; }
                showToast(data.error || 'Erro ao remover boost.', 'error');
            }
        })
        .catch(function () {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> Remover boost'; }
            showToast('Erro de ligação.', 'error');
        });
    }

    function updateBoostedSummary(delta) {
        const countEl    = document.getElementById('boostedCount');
        const metaEl     = document.getElementById('boostedListMeta');
        const grid       = document.getElementById('boostedGrid');
        const emptyState = document.getElementById('boostedEmptyState');

        if (countEl) {
            const next = Math.max(0, (parseInt(countEl.textContent) || 0) + delta);
            countEl.textContent = next;

            if (metaEl) metaEl.textContent = next + ' vídeo' + (next !== 1 ? 's' : '') + ' com boost ativo';

            // Sidebar badge for boosted
            const boostBadge = document.querySelector('.ap-nav-item[data-section="boosted"] .ap-badge');
            if (boostBadge) {
                if (next <= 0) boostBadge.remove();
                else boostBadge.textContent = next;
            }

            if (next === 0) {
                if (grid) grid.classList.add('ap-hidden');
                if (emptyState) emptyState.classList.remove('ap-hidden');
            }
        }
    }

    function initBoostActions() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.js-remove-boost');
            if (btn) {
                const videoId = btn.dataset.videoId;
                if (videoId) removeBoost(videoId);
            }
        });
    }

    // ── Mobile tabs (insert into DOM if needed) ───────────────
    function initMobileTabs() {
        if (document.getElementById('apMobileTabs')) return;

        const pendingCount = parseInt(
            (document.getElementById('apModerationBadge') || {}).textContent || '0'
        );
        const boostedCount = parseInt(
            (document.querySelector('.ap-nav-item[data-section="boosted"] .ap-badge') || {}).textContent || '0'
        );

        const html = '<div class="ap-mobile-tabs" id="apMobileTabs">' +
            '<div class="ap-mobile-tabs-inner">' +
            '<button class="ap-mobile-tab active" data-section="overview"><i class="fas fa-chart-line"></i><span>Geral</span></button>' +
            '<button class="ap-mobile-tab" data-section="moderation">' +
                '<i class="fas fa-shield-halved"></i><span>Mod.</span>' +
                (pendingCount > 0 ? '<span class="ap-badge" id="apModerationMobileBadge">' + pendingCount + '</span>' : '') +
            '</button>' +
            '<button class="ap-mobile-tab" data-section="boosted"><i class="fas fa-bolt"></i><span>Boosted</span></button>' +
            '<button class="ap-mobile-tab" data-section="rankings"><i class="fas fa-trophy"></i><span>Rank</span></button>' +
            '</div></div>';

        document.body.insertAdjacentHTML('beforeend', html);

        // Bind events on newly created buttons
        document.querySelectorAll('.ap-mobile-tab[data-section]').forEach(function (el) {
            el.addEventListener('click', function () {
                switchSection(el.dataset.section);
            });
        });
    }

    // ── Init ──────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initSubtabs();
        initModeration();
        initBoostActions();
        initMobileTabs();
        loadBoostMetrics();
        initPremium();
    });

    // ── Premium section ───────────────────────────────────────
    function initPremium() {
        var searchInput = document.getElementById('premiumSearchInput');
        var searchResults = document.getElementById('premiumSearchResults');
        if (!searchInput) return;

        // Load current premium users on first activation
        var listLoaded = false;
        document.querySelectorAll('.ap-nav-item[data-section="premium"]').forEach(function (el) {
            el.addEventListener('click', function () {
                if (!listLoaded) {
                    listLoaded = true;
                    loadPremiumList();
                }
            });
        });
        // Also load if section is already active (direct URL hash)
        if ((window.location.hash || '').replace('#', '') === 'premium') {
            listLoaded = true;
            loadPremiumList();
        }

        // Search debounce
        var searchTimer;
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = searchInput.value.trim();
            if (q.length < 2) {
                searchResults.innerHTML = '';
                searchResults.classList.add('ap-hidden');
                return;
            }
            searchTimer = setTimeout(function () { runPremiumSearch(q); }, 300);
        });

        // Close dropdown on outside click
        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.add('ap-hidden');
            }
        });
    }

    function loadPremiumList() {
        fetch('api/admin_premium.php?action=list', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var list = document.getElementById('premiumUsersList');
            if (!list) return;
            if (!data.success || !data.users.length) {
                list.innerHTML = '<div class="ap-empty-state" style="padding:40px 20px;">' +
                    '<i class="fas fa-star" style="font-size:2rem;color:#f59e0b;opacity:.3;"></i>' +
                    '<p>Nenhum utilizador premium ainda.</p></div>';
                return;
            }
            list.innerHTML = renderPremiumTable(data.users);
        })
        .catch(function () {
            var list = document.getElementById('premiumUsersList');
            if (list) list.innerHTML = '<div class="ap-empty-state" style="padding:40px 20px;"><p>Erro ao carregar.</p></div>';
        });
    }

    function runPremiumSearch(q) {
        var results = document.getElementById('premiumSearchResults');
        if (!results) return;
        results.innerHTML = '<div class="premium-sr-loading"><i class="fas fa-spinner fa-spin"></i></div>';
        results.classList.remove('ap-hidden');

        fetch('api/admin_premium.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.users || !data.users.length) {
                results.innerHTML = '<div class="premium-sr-empty">Nenhum utilizador encontrado.</div>';
                return;
            }
            results.innerHTML = data.users.map(function (u) {
                var isPrem = parseInt(u.is_premium) === 1;
                return '<div class="premium-sr-item" data-user-id="' + u.id + '" data-is-premium="' + (isPrem ? 1 : 0) + '">' +
                    '<div class="premium-sr-avatar">' +
                        (u.profile_picture ? '<img src="assets/images/avatars/' + escHtml(u.profile_picture) + '" onerror="apAvatarFallback(this)">' : '<i class="fas fa-user"></i>') +
                    '</div>' +
                    '<div class="premium-sr-info">' +
                        '<strong>' + escHtml(u.full_name || u.username) + '</strong>' +
                        '<span>@' + escHtml(u.username) + '</span>' +
                    '</div>' +
                    (isPrem
                        ? '<button class="ap-btn ap-btn-sm" style="background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.3);" onclick="setPremium(' + u.id + ',0,this)"><i class="fas fa-star"></i> Remover</button>'
                        : '<button class="ap-btn ap-btn-sm ap-btn-primary" onclick="setPremium(' + u.id + ',1,this)"><i class="far fa-star"></i> Tornar Premium</button>') +
                    '</div>';
            }).join('');
        })
        .catch(function () {
            results.innerHTML = '<div class="premium-sr-empty">Erro ao pesquisar.</div>';
        });
    }

    function setPremium(userId, value, triggerEl) {
        if (triggerEl) { triggerEl.disabled = true; triggerEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
        fetch('api/admin_premium.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken(),
            },
            body: JSON.stringify({ user_id: userId, value: value, csrf_token: getCsrfToken() }),
            credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast(value ? '⭐ @' + data.username + ' é agora Premium!' : '@' + data.username + ' removido do Premium.', value ? 'success' : 'info');
                loadPremiumList();
                updatePremiumCounter(value ? 1 : -1);
                // Refresh the search result row
                var item = triggerEl && triggerEl.closest('.premium-sr-item');
                if (item) {
                    item.dataset.isPremium = value ? '1' : '0';
                    var btn = item.querySelector('button');
                    if (btn) {
                        if (value) {
                            btn.style = 'background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.3);';
                            btn.innerHTML = '<i class="fas fa-star"></i> Remover';
                            btn.setAttribute('onclick', 'setPremium(' + userId + ',0,this)');
                        } else {
                            btn.style = '';
                            btn.className = 'ap-btn ap-btn-sm ap-btn-primary';
                            btn.innerHTML = '<i class="far fa-star"></i> Tornar Premium';
                            btn.setAttribute('onclick', 'setPremium(' + userId + ',1,this)');
                        }
                        btn.disabled = false;
                    }
                }
            } else {
                showToast(data.error || 'Erro desconhecido.', 'error');
                if (triggerEl) { triggerEl.disabled = false; triggerEl.innerHTML = value ? '<i class="far fa-star"></i> Tornar Premium' : '<i class="fas fa-star"></i> Remover'; }
            }
        })
        .catch(function () {
            showToast('Erro de rede.', 'error');
            if (triggerEl) { triggerEl.disabled = false; }
        });
    }

    function updatePremiumCounter(delta) {
        var el = document.getElementById('premiumCount');
        if (el) el.textContent = Math.max(0, (parseInt(el.textContent) || 0) + delta);
        var badge = document.querySelector('.ap-nav-item[data-section="premium"] .ap-badge');
        if (badge) {
            var n = Math.max(0, (parseInt(badge.textContent) || 0) + delta);
            badge.textContent = n;
            badge.style.display = n ? '' : 'none';
        }
    }

    function renderPremiumTable(users) {
        return '<table class="ap-table">' +
            '<thead><tr>' +
            '<th>Utilizador</th>' +
            '<th style="text-align:right;">Ações</th>' +
            '</tr></thead>' +
            '<tbody>' +
            users.map(function (u) {
                return '<tr>' +
                    '<td>' +
                        '<div style="display:flex;align-items:center;gap:10px;">' +
                            '<div style="width:36px;height:36px;border-radius:50%;overflow:hidden;background:#1e293b;flex-shrink:0;display:flex;align-items:center;justify-content:center;">' +
                                (u.profile_picture ? '<img src="assets/images/avatars/' + escHtml(u.profile_picture) + '" style="width:36px;height:36px;object-fit:cover;" onerror="apAvatarFallback(this)">' : '<i class="fas fa-user" style="color:#475569;"></i>') +
                            '</div>' +
                            '<div>' +
                                '<div style="font-weight:700;">' + escHtml(u.full_name || u.username) +
                                    (parseInt(u.is_verified) ? ' <i class="fas fa-check-circle" style="color:#3b82f6;font-size:.8em"></i>' : '') +
                                '</div>' +
                                '<div style="color:#64748b;font-size:.85rem;">@' + escHtml(u.username) + '</div>' +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                    '<td style="text-align:right;">' +
                        '<button class="ap-btn ap-btn-sm" style="background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.3);" onclick="setPremium(' + u.id + ',0,this)">' +
                            '<i class="fas fa-star"></i> Remover Premium' +
                        '</button>' +
                    '</td>' +
                '</tr>';
            }).join('') +
            '</tbody></table>';
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Expose to global scope for inline onclick handlers
    window.setPremium = setPremium;

})();

// ══════════════════════════════════════════════════════════════════════════════
// USERS MODULE — Aba Utilizadores
// ══════════════════════════════════════════════════════════════════════════════
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────────────────
    let usrState = {
        search: '', status: 'all', role: 'all', videos: 'all', verified: 'all',
        sort: 'newest', page: 1, totalPages: 1, total: 0,
        loading: false, retLoading: false, statsLoaded: false,
        activeSubtab: 'list',
    };
    let usrSearchTimer = null;
    let usrRefreshTimer = null;

    // ── Helpers ──────────────────────────────────────────────────────────────
    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtNum(n) {
        n = parseInt(n) || 0;
        if (n >= 1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'') + 'M';
        if (n >= 1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'') + 'k';
        return n.toString();
    }
    function roleBadge(role) {
        const map = { admin:'admin', moderator:'moderator', vip:'vip', user:'user' };
        const r = map[role] || 'user';
        const labels = { admin:'Admin', moderator:'Mod', vip:'VIP', user:'User' };
        return `<span class="usr-role usr-role--${r}">${labels[r]}</span>`;
    }
    function activityBadge(cls) {
        const map = {
            active:   ['🟢','Ativo'],
            casual:   ['🔵','Casual'],
            inactive: ['🟡','Inativo'],
            churned:  ['🔴','Churned'],
            unknown:  ['⚪','—'],
        };
        const [ico, lbl] = map[cls] || map.unknown;
        return `<span class="usr-activity usr-activity--${cls}">${ico} ${lbl}</span>`;
    }
    function fmtHours(h) {
        if (h === null || h === undefined) return '—';
        h = parseFloat(h);
        if (h < 1)   return Math.round(h * 60) + ' min';
        if (h < 24)  return h.toFixed(1) + 'h';
        if (h < 168) return (h / 24).toFixed(1) + ' dias';
        return (h / 168).toFixed(1) + ' semanas';
    }
    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ','T'));
        return d.toLocaleDateString('pt-PT', { day:'2-digit', month:'short', year:'numeric' })
             + ' ' + d.toLocaleTimeString('pt-PT', { hour:'2-digit', minute:'2-digit' });
    }
    function timeDiff(a, b) {
        // returns human gap between two datetime strings (a before b)
        const secs = (new Date(b.replace(' ','T')) - new Date(a.replace(' ','T'))) / 1000;
        if (secs < 60)    return Math.round(secs) + 's depois';
        if (secs < 3600)  return Math.round(secs/60) + 'min depois';
        if (secs < 86400) return Math.round(secs/3600) + 'h depois';
        return Math.round(secs/86400) + 'd depois';
    }

    // ── DOM refs (lazy, only when section is rendered) ───────────────────────
    function el(id) { return document.getElementById(id); }

    // ── Stats cards ──────────────────────────────────────────────────────────
    async function loadUsrStats(force) {
        if (usrState.statsLoaded && !force) return;
        try {
            const r = await fetch('api/admin_users.php?action=stats');
            const d = await r.json();
            if (!d.success) return;
            const s = d.data;
            setStatVal('usrStatTotal',      fmtNum(s.total_users));
            setStatVal('usrStatOnline',     fmtNum(s.online_now));
            setStatVal('usrStatVideos',     fmtNum(s.with_videos));
            setStatVal('usrStatToday',      '+' + fmtNum(s.new_today));
            setStatVal('usrStatWeek',       '+' + fmtNum(s.new_this_week));
            setStatVal('usrStatRetention',  s.retention_rate !== null ? s.retention_rate + '%' : '—');
            setStatVal('usrStatRetained',   s.retained_users !== null ? fmtNum(s.retained_users) : '—');
            setStatVal('usrStatAvgReturn',  fmtHours(s.avg_hours_to_second_login));
            usrState.statsLoaded = true;
        } catch(e) { console.warn('usr stats error', e); }
    }
    function setStatVal(id, v) {
        const el2 = el(id);
        if (el2) el2.textContent = v;
    }

    // ── List ─────────────────────────────────────────────────────────────────
    async function loadUsrList() {
        if (usrState.loading) return;
        usrState.loading = true;

        const tbody = el('usrTbody');
        const countEl = el('usrCount');
        if (tbody) tbody.innerHTML = '<tr><td colspan="9"><div class="usr-loading"><i class="fas fa-spinner"></i>A carregar utilizadores…</div></td></tr>';

        const params = new URLSearchParams({
            action:   'list',
            search:   usrState.search,
            status:   usrState.status,
            role:     usrState.role,
            videos:   usrState.videos,
            verified: usrState.verified,
            sort:     usrState.sort,
            page:     usrState.page,
        });

        try {
            const r = await fetch('api/admin_users.php?' + params);
            const d = await r.json();
            if (!d.success) throw new Error('API error');

            usrState.total      = d.total;
            usrState.totalPages = d.total_pages;

            if (countEl) countEl.textContent = d.total + ' utilizador' + (d.total !== 1 ? 'es' : '');

            if (!d.users.length) {
                if (tbody) tbody.innerHTML = '<tr><td colspan="9"><div class="usr-empty"><i class="fas fa-users-slash"></i>Nenhum utilizador encontrado.</div></td></tr>';
            } else {
                if (tbody) tbody.innerHTML = d.users.map(u => renderUserRow(u)).join('');
            }

            renderPagination();
        } catch(e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="9"><div class="usr-empty"><i class="fas fa-triangle-exclamation"></i>Erro ao carregar. Tente de novo.</div></td></tr>';
        } finally {
            usrState.loading = false;
        }
    }

    function renderUserRow(u) {
        const online = u.is_really_online;
        const dot    = online
            ? '<span class="usr-dot usr-dot--online"></span> Online'
            : '<span class="usr-dot usr-dot--offline"></span> ' + (u.last_seen_ago ? u.last_seen_ago : '—');
        return `<tr data-uid="${u.id}" class="usr-row">
            <td>
                <div class="usr-td-user">
                    <img src="${esc(u.avatar_url)}" alt="" class="usr-td-avatar" loading="lazy">
                    <div>
                        <span class="usr-td-name">${esc(u.full_name || u.username)}
                            ${u.is_verified ? '<i class="fas fa-check-circle" style="color:#3b82f6;font-size:.8em;margin-left:3px"></i>' : ''}
                        </span>
                        <span class="usr-td-sub">@${esc(u.username)}</span>
                    </div>
                </div>
            </td>
            <td style="color:#64748b;font-size:.8rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(u.email)}</td>
            <td>${roleBadge(u.role)}</td>
            <td><span class="usr-online-dot">${dot}</span></td>
            <td style="color:#e2e8f0;font-weight:600">${fmtNum(u.videos_count)}</td>
            <td style="color:#94a3b8">${fmtNum(u.followers_count)}</td>
            <td style="color:#64748b;font-size:.8rem">${u.last_login_ago ?? '—'}</td>
            <td style="color:#64748b;font-size:.8rem">${fmtDate(u.created_at).split(' ').slice(0,2).join(' ')}</td>
            <td><button class="ap-btn ap-btn-sm usr-detail-btn" style="padding:5px 10px;font-size:.76rem" data-uid="${u.id}"><i class="fas fa-eye"></i></button></td>
        </tr>`;
    }

    function renderPagination() {
        const wrap = el('usrPagination');
        if (!wrap) return;
        if (usrState.totalPages <= 1) { wrap.innerHTML = ''; return; }

        let html = `<button class="usr-page-btn" id="usrPrevBtn" ${usrState.page <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;

        const start = Math.max(1, usrState.page - 2);
        const end   = Math.min(usrState.totalPages, start + 4);
        for (let p = start; p <= end; p++) {
            html += `<button class="usr-page-btn${p === usrState.page ? ' active' : ''}" data-page="${p}">${p}</button>`;
        }

        html += `<button class="usr-page-btn" id="usrNextBtn" ${usrState.page >= usrState.totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
        html += `<span class="usr-page-info">${usrState.page} / ${usrState.totalPages}</span>`;
        wrap.innerHTML = html;

        wrap.querySelectorAll('[data-page]').forEach(btn => {
            btn.addEventListener('click', () => { usrState.page = parseInt(btn.dataset.page); loadUsrList(); });
        });
        const prev = el('usrPrevBtn'), next = el('usrNextBtn');
        if (prev) prev.addEventListener('click', () => { if (usrState.page > 1) { usrState.page--; loadUsrList(); } });
        if (next) next.addEventListener('click', () => { if (usrState.page < usrState.totalPages) { usrState.page++; loadUsrList(); } });
    }

    // ── Detail Drawer ────────────────────────────────────────────────────────
    function openDrawer(userId) {
        const overlay = el('usrDrawerOverlay');
        const drawer  = el('usrDrawer');
        const body    = el('usrDrawerBody');
        if (!overlay || !drawer) return;

        overlay.classList.add('open');
        drawer.classList.add('open');
        document.body.style.overflow = 'hidden';

        if (body) body.innerHTML = '<div class="usr-drawer-loading"><i class="fas fa-spinner"></i><span>A carregar…</span></div>';

        fetch('api/admin_users.php?action=detail&id=' + userId)
            .then(r => r.json())
            .then(d => {
                if (!d.success) throw new Error('Not found');
                renderDrawer(d);
            })
            .catch(() => {
                if (body) body.innerHTML = '<div style="padding:24px;color:#ef4444">Erro ao carregar utilizador.</div>';
            });
    }

    function closeDrawer() {
        const overlay = el('usrDrawerOverlay');
        const drawer  = el('usrDrawer');
        if (overlay) overlay.classList.remove('open');
        if (drawer)  drawer.classList.remove('open');
        document.body.style.overflow = '';
    }

    function renderDrawer(d) {
        const u  = d.user;
        const m  = d.login_metrics;
        const h  = d.login_history || [];
        const header = el('usrDrawerHeader');
        const body   = el('usrDrawerBody');
        if (!header || !body) return;

        const online = u.is_really_online;

        header.innerHTML = `
            <img src="${esc(u.avatar_url)}" alt="" class="usr-drawer-avatar">
            <div>
                <span class="usr-drawer-title">${esc(u.full_name || u.username)}
                    ${u.is_verified ? '<i class="fas fa-check-circle" style="color:#3b82f6;font-size:.85em"></i>' : ''}
                </span>
                <span class="usr-drawer-sub">@${esc(u.username)} · ${online ? '<span style="color:#10b981">● Online agora</span>' : (u.last_seen ? 'Visto ' + esc(u.last_seen) : 'Nunca visto')}</span>
            </div>
            <button class="usr-drawer-close" id="usrDrawerCloseBtn"><i class="fas fa-xmark"></i></button>
        `;
        el('usrDrawerCloseBtn')?.addEventListener('click', closeDrawer);

        // ── Activity classification banner
        const actColors = { active:'#10b981', casual:'#3b82f6', inactive:'#f59e0b', churned:'#ef4444', unknown:'#475569' };
        const actLabels = { active:'Utilizador Ativo', casual:'Utilizador Casual', inactive:'Utilizador Inativo', churned:'Churned (Perdido)', unknown:'Sem dados suficientes' };
        const actIcons  = { active:'fa-signal', casual:'fa-chart-simple', inactive:'fa-battery-half', churned:'fa-user-slash', unknown:'fa-circle-question' };
        const ac = m.activity_class || 'unknown';
        const actBanner = `<div style="background:${actColors[ac]}18;border:1px solid ${actColors[ac]}30;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:20px">
            <i class="fas ${actIcons[ac]}" style="color:${actColors[ac]};font-size:1.1rem"></i>
            <div>
                <strong style="color:${actColors[ac]}">${actLabels[ac]}</strong>
                <span style="color:#64748b;font-size:.8rem;display:block">
                    ${m.days_since_last !== null ? 'Último login há ' + m.days_since_last + ' dia(s)' : 'Sem histórico de logins'}
                </span>
            </div>
        </div>`;

        // ── Metrics row
        const metricsRow = `<div class="usr-metrics-row">
            <div class="usr-metric-card"><strong>${m.total_logins ?? '—'}</strong><span>Logins totais</span></div>
            <div class="usr-metric-card"><strong>${m.logins_per_week !== null ? m.logins_per_week : '—'}</strong><span>Logins/semana</span></div>
            <div class="usr-metric-card"><strong>${fmtHours(m.time_to_second)}</strong><span>Tempo p/ 2.º login</span></div>
        </div>
        <div class="usr-metrics-row">
            <div class="usr-metric-card"><strong style="color:#34d399">${fmtNum(u.total_views)}</strong><span>Views totais</span></div>
            <div class="usr-metric-card"><strong style="color:#f43f5e">${fmtNum(u.total_likes)}</strong><span>Likes totais</span></div>
            <div class="usr-metric-card"><strong style="color:#a78bfa">${fmtNum(u.videos_count)}</strong><span>Vídeos</span></div>
        </div>`;

        // ── Info grid
        const infoGrid = `<div class="usr-info-grid">
            <div class="usr-info-item"><label>Email</label><span>${esc(u.email)}</span></div>
            <div class="usr-info-item"><label>Role</label><span>${roleBadge(u.role)}</span></div>
            <div class="usr-info-item"><label>Seguidores</label><span>${fmtNum(u.followers_count)}</span></div>
            <div class="usr-info-item"><label>A seguir</label><span>${fmtNum(u.following_count)}</span></div>
            <div class="usr-info-item"><label>Instituição</label><span>${esc(u.instituicao || '—')}</span></div>
            <div class="usr-info-item"><label>Membro desde</label><span>${fmtDate(u.created_at)}</span></div>
            ${m.first_login ? `<div class="usr-info-item"><label>1.º Login</label><span>${fmtDate(m.first_login)}</span></div>` : ''}
            ${m.last_login  ? `<div class="usr-info-item"><label>Último Login</label><span>${fmtDate(m.last_login)}</span></div>` : ''}
        </div>`;

        // ── Login timeline
        let timeline = '';
        if (h.length === 0) {
            timeline = '<p style="color:#475569;font-size:.85rem">Sem histórico de logins registados.</p>';
        } else {
            const realH = h.filter(x => x.user_agent !== 'seed:account_created');
            if (realH.length === 0) {
                timeline = '<p style="color:#475569;font-size:.85rem">Sem logins reais registados ainda.</p>';
            } else {
                timeline = '<div class="usr-timeline">' +
                    realH.map((item, i) => {
                        const prev   = realH[i + 1];
                        const gapStr = prev ? timeDiff(prev.logged_in_at, item.logged_in_at) : 'Primeiro login';
                        const ua     = item.user_agent || '';
                        const device = ua.includes('Mobile') ? '📱' : ua.includes('Tablet') ? '🖥' : '💻';
                        return `<div class="usr-timeline-item">
                            <span class="usr-timeline-date">${device} ${fmtDate(item.logged_in_at)}</span>
                            <span class="usr-timeline-gap">${gapStr}</span>
                            ${item.ip_address ? `<span class="usr-timeline-ip">IP: ${esc(item.ip_address)}</span>` : ''}
                        </div>`;
                    }).join('') +
                '</div>';
            }
        }

        body.innerHTML = actBanner + `
            <div class="usr-drawer-section">
                <div class="usr-drawer-section-title"><i class="fas fa-chart-bar"></i> Métricas de Atividade</div>
                ${metricsRow}
            </div>
            <div class="usr-drawer-section">
                <div class="usr-drawer-section-title"><i class="fas fa-circle-info"></i> Informação</div>
                ${infoGrid}
            </div>
            <div class="usr-drawer-section">
                <div class="usr-drawer-section-title"><i class="fas fa-clock-rotate-left"></i> Histórico de Sessões</div>
                ${timeline}
            </div>
            ${u.bio ? `<div class="usr-drawer-section"><div class="usr-drawer-section-title"><i class="fas fa-quote-left"></i> Bio</div><p style="color:#94a3b8;font-size:.88rem;line-height:1.6">${esc(u.bio)}</p></div>` : ''}
        `;
    }

    // ── Retention Tab ────────────────────────────────────────────────────────
    async function loadRetention() {
        if (usrState.retLoading) return;
        usrState.retLoading = true;
        const wrap = el('usrRetentionContent');
        if (!wrap) return;
        wrap.innerHTML = '<div class="usr-loading"><i class="fas fa-spinner"></i>A calcular métricas…</div>';
        try {
            const r = await fetch('api/admin_users.php?action=retention');
            const d = await r.json();
            if (d.table_missing) {
                wrap.innerHTML = `<div class="usr-empty"><i class="fas fa-database"></i>
                    <p>Tabela de histórico ainda não existe.<br>
                    <a href="install_user_login_history.php" target="_blank" style="color:#60a5fa">Executar migração →</a></p></div>`;
                return;
            }
            renderRetention(d, wrap);
        } catch(e) {
            wrap.innerHTML = '<div class="usr-empty"><i class="fas fa-triangle-exclamation"></i>Erro ao carregar retenção.</div>';
        } finally {
            usrState.retLoading = false;
        }
    }

    function renderRetention(d, wrap) {
        const dist  = d.distribution || {};
        const total_dist = (parseInt(dist.lt_1h)||0) + (parseInt(dist.lt_24h)||0) + (parseInt(dist.lt_3d)||0)
                         + (parseInt(dist.lt_7d)||0) + (parseInt(dist.lt_30d)||0) + (parseInt(dist.gt_30d)||0);
        const maxDist = Math.max(...Object.values(dist).map(v => parseInt(v)||0), 1);

        function distRow(label, val) {
            const n   = parseInt(val) || 0;
            const pct = total_dist > 0 ? Math.round(n / total_dist * 100) : 0;
            const w   = Math.round(n / maxDist * 100);
            return `<div class="ret-dist-row">
                <span class="ret-dist-label">${label}</span>
                <div class="ret-dist-bar-wrap"><div class="ret-dist-bar" style="width:${w}%"></div></div>
                <span class="ret-dist-count">${n} <small style="color:#475569">(${pct}%)</small></span>
            </div>`;
        }

        // Cohort table
        const cohortRows = (d.cohorts || []).map(c => {
            const pct    = parseFloat(c.retention_pct);
            const cls    = pct >= 50 ? 'high' : pct >= 20 ? 'medium' : 'low';
            const wkDate = new Date(c.week_start).toLocaleDateString('pt-PT', { day:'2-digit', month:'short' });
            return `<tr>
                <td>${wkDate}</td>
                <td>${c.cohort_size}</td>
                <td>${c.returned_users}</td>
                <td><span class="ret-pct-pill ret-pct-${cls}">${pct}%</span></td>
            </tr>`;
        }).join('');

        // At-risk
        const riskRows = (d.at_risk || []).map(r => `
            <div class="usr-risk-item">
                <img src="${esc(r.avatar_url)}" alt="" class="usr-risk-avatar">
                <div class="usr-risk-info">
                    <div class="usr-risk-name">@${esc(r.username)}</div>
                    <div class="usr-risk-sub">${fmtNum(r.videos_count)} vídeo(s)</div>
                </div>
                <span class="usr-risk-days">${r.days_away}d away</span>
            </div>
        `).join('');

        // Summary stats
        const churnPct = d.churn_rate !== null ? d.churn_rate + '%' : '—';

        wrap.innerHTML = `
            <!-- Summary stats -->
            <div class="usr-stats-grid" style="margin-bottom:24px">
                <div class="usr-stat-card">
                    <div class="usr-stat-icon" style="background:rgba(239,68,68,.15);color:#ef4444"><i class="fas fa-user-slash"></i></div>
                    <div class="usr-stat-body">
                        <span class="usr-stat-label">Churn Rate</span>
                        <strong class="usr-stat-value">${churnPct}</strong>
                        <span class="usr-stat-sub">Nunca voltaram</span>
                    </div>
                </div>
                <div class="usr-stat-card">
                    <div class="usr-stat-icon" style="background:rgba(239,68,68,.15);color:#ef4444"><i class="fas fa-ghost"></i></div>
                    <div class="usr-stat-body">
                        <span class="usr-stat-label">Sem 2.º login</span>
                        <strong class="usr-stat-value">${fmtNum(d.never_returned)}</strong>
                        <span class="usr-stat-sub">de ${fmtNum(d.total_seeded)} total</span>
                    </div>
                </div>
                <div class="usr-stat-card">
                    <div class="usr-stat-icon" style="background:rgba(16,185,129,.15);color:#10b981"><i class="fas fa-rotate-right"></i></div>
                    <div class="usr-stat-body">
                        <span class="usr-stat-label">Voltaram</span>
                        <strong class="usr-stat-value">${fmtNum(d.total_seeded - d.never_returned)}</strong>
                        <span class="usr-stat-sub">utilizadores retidos</span>
                    </div>
                </div>
            </div>

            <div class="ret-grid">
                <!-- Distribuição do tempo até 2.º login -->
                <div class="ret-card">
                    <div class="ret-card-title"><i class="fas fa-hourglass-half"></i> Tempo até 2.º Login</div>
                    ${distRow('< 1 hora',  dist.lt_1h)}
                    ${distRow('1h – 24h',  dist.lt_24h)}
                    ${distRow('1 – 3 dias',dist.lt_3d)}
                    ${distRow('3 – 7 dias',dist.lt_7d)}
                    ${distRow('7 – 30d',   dist.lt_30d)}
                    ${distRow('> 30 dias', dist.gt_30d)}
                </div>

                <!-- Cohorts semanais -->
                <div class="ret-card">
                    <div class="ret-card-title"><i class="fas fa-calendar-week"></i> Cohorts Semanais</div>
                    ${d.cohorts && d.cohorts.length ? `
                    <table class="ret-cohort-table">
                        <thead><tr><th>Semana</th><th>Novos</th><th>Voltaram</th><th>Retenção</th></tr></thead>
                        <tbody>${cohortRows}</tbody>
                    </table>` : '<p style="color:#475569;font-size:.85rem">Dados insuficientes.</p>'}
                </div>
            </div>

            <!-- Utilizadores em risco -->
            ${d.at_risk && d.at_risk.length ? `
            <div class="ret-card">
                <div class="ret-card-title"><i class="fas fa-triangle-exclamation"></i> Utilizadores em Risco (30–180 dias sem login)</div>
                ${riskRows}
            </div>` : ''}
        `;
    }

    // ── Sub-tabs ─────────────────────────────────────────────────────────────
    function initUsrSubtabs() {
        document.querySelectorAll('.usr-subtab').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.usr-subtab').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                usrState.activeSubtab = btn.dataset.subtab;

                el('usrListPane')      && (el('usrListPane').style.display      = usrState.activeSubtab === 'list'      ? '' : 'none');
                el('usrRetentionPane') && (el('usrRetentionPane').style.display = usrState.activeSubtab === 'retention' ? '' : 'none');

                if (usrState.activeSubtab === 'retention') loadRetention();
            });
        });
    }

    // ── Filter / Search events ────────────────────────────────────────────────
    function initUsrFilters() {
        const searchEl = el('usrSearch');
        if (searchEl) {
            searchEl.addEventListener('input', () => {
                clearTimeout(usrSearchTimer);
                usrSearchTimer = setTimeout(() => {
                    usrState.search = searchEl.value;
                    usrState.page   = 1;
                    loadUsrList();
                }, 350);
            });
        }

        ['usrFilterStatus','usrFilterRole','usrFilterVideos','usrFilterVerified','usrFilterSort'].forEach(id => {
            const el2 = el(id);
            if (!el2) return;
            el2.addEventListener('change', () => {
                usrState.page = 1;
                if (id === 'usrFilterStatus')   usrState.status   = el2.value;
                if (id === 'usrFilterRole')      usrState.role     = el2.value;
                if (id === 'usrFilterVideos')    usrState.videos   = el2.value;
                if (id === 'usrFilterVerified')  usrState.verified = el2.value;
                if (id === 'usrFilterSort')       usrState.sort     = el2.value;
                loadUsrList();
            });
        });

        // Refresh button
        const refreshBtn = el('usrRefreshBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                usrState.statsLoaded = false;
                loadUsrStats(true);
                loadUsrList();
            });
        }
    }

    // ── Table row / drawer click delegation ─────────────────────────────────
    function initUsrTable() {
        const tbody = el('usrTbody');
        if (tbody) {
            tbody.addEventListener('click', e => {
                const btn = e.target.closest('.usr-detail-btn');
                const row = e.target.closest('.usr-row');
                const uid = parseInt(btn?.dataset.uid || row?.dataset.uid);
                if (uid) openDrawer(uid);
            });
        }
        const overlay = el('usrDrawerOverlay');
        if (overlay) overlay.addEventListener('click', closeDrawer);

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeDrawer();
        });
    }

    // ── Auto-refresh when users section is active ─────────────────────────────
    function startUsrRefresh() {
        clearInterval(usrRefreshTimer);
        usrRefreshTimer = setInterval(() => {
            const section = document.getElementById('section-users');
            if (section && section.classList.contains('active')) {
                usrState.statsLoaded = false;
                loadUsrStats(true);
                if (usrState.activeSubtab === 'list') loadUsrList();
            }
        }, 60000);
    }

    // ── Init — run when section becomes active ────────────────────────────────
    function initUsers() {
        initUsrSubtabs();
        initUsrFilters();
        initUsrTable();
        loadUsrStats();
        loadUsrList();
        startUsrRefresh();
    }

    // Hook into sidebar nav click — lazy init on first open
    let usrInitDone = false;
    document.querySelectorAll('.ap-nav-item[data-section="users"]').forEach(btn => {
        btn.addEventListener('click', () => {
            if (usrInitDone) return;
            usrInitDone = true;
            setTimeout(initUsers, 60);
        });
    });

    // Also init immediately if page loads directly with #users hash
    if ((window.location.hash || '').replace('#', '') === 'users') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => setTimeout(initUsers, 100));
        } else {
            setTimeout(initUsers, 100);
        }
    }

})();
