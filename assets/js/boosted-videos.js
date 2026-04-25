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
        const validSections = ['overview', 'moderation', 'boosted', 'rankings', 'premium'];
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
