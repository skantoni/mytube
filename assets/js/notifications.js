/**
 * Sistema de Notificações Inteligente
 * 
 * - Cache em memória (evita re-fetch se dados têm <30s)
 * - Paginação com "Carregar mais"
 * - Polling otimizado (30s, apenas count_only, pausa quando tab invisível)
 * - Código centralizado para todas as páginas
 */
const NotificationSystem = (function() {
    // Estado interno
    let cache = {
        notifications: [],
        unreadCount: 0,
        lastFetchTime: 0,
        offset: 0,
        hasMore: false,
        fullyLoaded: false
    };

    const CACHE_TTL = 30000;       // 30s cache antes de re-fetch
    const POLL_BASE = 45000;       // 45s intervalo base
    const POLL_IDLE = 90000;       // 90s quando idle
    const POLL_ZERO = 120000;      // 2min sem notificações novas
    const IDLE_TIMEOUT = 120000;   // 2min sem interação = idle
    const PAGE_SIZE = 20;

    let pollTimer = null;
    let isModalOpen = false;
    let lastActivity = Date.now();
    let consecutiveZeros = 0;

    // ========== FUNÇÕES INTERNAS ==========

    function isCacheValid() {
        return (Date.now() - cache.lastFetchTime) < CACHE_TTL && cache.notifications.length > 0;
    }

    function fetchCount() {
        return fetch('api/get_notifications.php?count_only=1')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    cache.unreadCount = data.unread_count;
                    updateBadgeUI(data.unread_count);
                    consecutiveZeros = data.unread_count === 0 ? consecutiveZeros + 1 : 0;
                }
                return data;
            })
            .catch(() => {});
    }

    function getSmartInterval() {
        if ((Date.now() - lastActivity) > IDLE_TIMEOUT) return POLL_IDLE;
        if (consecutiveZeros >= 3) return POLL_ZERO;
        return POLL_BASE;
    }

    function fetchNotifications(offset = 0, append = false) {
        const list = document.getElementById('notificationsList');
        if (!list) return Promise.resolve();

        // Mostrar loading apenas na primeira carga
        if (!append) {
            list.innerHTML = `
                <div class="notifications-empty">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Carregando...</p>
                </div>
            `;
        }

        return fetch(`api/get_notifications.php?offset=${offset}&limit=${PAGE_SIZE}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (append) {
                        cache.notifications = cache.notifications.concat(data.notifications);
                    } else {
                        cache.notifications = data.notifications;
                    }
                    cache.unreadCount = data.unread_count;
                    cache.offset = data.offset;
                    cache.hasMore = data.has_more;
                    cache.lastFetchTime = Date.now();
                    cache.fullyLoaded = !data.has_more;

                    renderNotifications(append);
                    updateBadgeUI(data.unread_count);
                }
                return data;
            })
            .catch(error => {
                console.error('Erro ao carregar notificações:', error);
                if (!append) {
                    list.innerHTML = `
                        <div class="notifications-empty">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Erro ao carregar notificações</p>
                        </div>
                    `;
                }
            });
    }

    function renderNotifications(append = false) {
        const list = document.getElementById('notificationsList');
        if (!list) return;

        const notifs = cache.notifications;

        if (!notifs || notifs.length === 0) {
            list.innerHTML = `
                <div class="notifications-empty">
                    <i class="fas fa-bell"></i>
                    <p>Nenhuma notificação</p>
                </div>
            `;
            return;
        }

        // Se append, renderizar apenas os novos itens
        const itemsToRender = append 
            ? notifs.slice(notifs.length - PAGE_SIZE) 
            : notifs;

        const html = itemsToRender.map(notif => {
            const iconClass = (notif.type === 'like' || notif.type === 'comment_like') ? 'like' : 
                              (notif.type === 'mention' || notif.type === 'bio_mention') ? 'mention' :
                              (notif.type === 'comment' || notif.type === 'reply') ? 'comment' : 
                              notif.type === 'unfollow' ? 'unfollow' : 
                              (notif.type === 'friend_accept' || notif.type === 'friend_request') ? 'follow' : 'follow';
            const icon = notif.type === 'like' ? 'fa-heart' : 
                         notif.type === 'comment' ? 'fa-comment' : 
                         notif.type === 'reply' ? 'fa-reply' : 
                         (notif.type === 'mention' || notif.type === 'bio_mention') ? 'fa-at' :
                         notif.type === 'comment_like' ? 'fa-heart' : 
                         notif.type === 'friend_request' ? 'fa-user-plus' :
                         notif.type === 'friend_accept' ? 'fa-user-check' :
                         notif.type === 'unfollow' ? 'fa-user-minus' : 'fa-user-plus';
            
            return `
                <div class="notification-item ${notif.is_read ? '' : 'unread'}" 
                     data-notif-id="${notif.id}"
                     onclick="NotificationSystem.handleClick(${notif.id}, '${notif.type}', ${notif.reference_id || 'null'}, ${notif.comment_id || 'null'}, '${notif.actor_username}', '${notif.notif_scope || 'personal'}')">
                    <img src="assets/images/avatars/${notif.actor_avatar || 'default.webp'}" 
                         alt="" class="notification-avatar" loading="lazy">
                    <div class="notification-content">
                        <div class="notification-text">
                            <strong>@${notif.actor_username}</strong> ${notif.message}
                        </div>
                        <div class="notification-time">${notif.time_ago}</div>
                    </div>
                    <div class="notification-icon ${iconClass}">
                        <i class="fas ${icon}"></i>
                    </div>
                </div>
            `;
        }).join('');

        if (append) {
            // Remover botão "carregar mais" antigo antes de adicionar novos itens
            const oldBtn = list.querySelector('.notif-load-more-wrapper');
            if (oldBtn) oldBtn.remove();
            list.insertAdjacentHTML('beforeend', html);
        } else {
            list.innerHTML = html;
        }

        // Botão "Carregar mais" se houver mais
        if (cache.hasMore) {
            const existing = list.querySelector('.notif-load-more-wrapper');
            if (existing) existing.remove();
            list.insertAdjacentHTML('beforeend', `
                <div class="notif-load-more-wrapper" style="padding: 12px 20px;">
                    <button class="notif-load-more-btn" onclick="NotificationSystem.loadMore()">
                        Carregar mais
                    </button>
                </div>
            `);
        }
    }

    function getChatUnreadCount() {
        if (window.ChatUnreadSystem && typeof window.ChatUnreadSystem.getCount === 'function') {
            const count = Number.parseInt(window.ChatUnreadSystem.getCount(), 10);
            return Number.isFinite(count) ? Math.max(0, count) : 0;
        }

        const fallback = Number.parseInt(window.chatUnreadCount || 0, 10);
        return Number.isFinite(fallback) ? Math.max(0, fallback) : 0;
    }

    function updateBadgeUI(count) {
        // Suporta diferentes formatos de badge (dot, count badge)
        const badge = document.getElementById('notificationBadge');
        const dot = document.getElementById('notificationDot');
        const socialCount = Number.parseInt(count, 10) || 0;

        if (badge) {
            if (socialCount > 0) {
                badge.textContent = socialCount > 99 ? '99+' : socialCount;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }

        if (dot) {
            const hasAnyUnread = socialCount > 0 || getChatUnreadCount() > 0;
            dot.style.display = hasAnyUnread ? 'block' : 'none';
        }
    }

    // ========== API PÚBLICA ==========

    function init() {
        // Buscar contagem inicial
        fetchCount();

        // Iniciar polling adaptativo
        startPolling();

        // Rastrear actividade para ajustar intervalo de polling
        ['click', 'scroll', 'keydown'].forEach(evt => {
            document.addEventListener(evt, () => { lastActivity = Date.now(); }, { passive: true });
        });

        // Parar/retomar polling com visibilidade da tab
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopPolling();
            } else {
                lastActivity = Date.now();
                consecutiveZeros = 0;
                fetchCount(); // Check imediato ao voltar
                startPolling();
            }
        });

        // Fechar modal com ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isModalOpen) closeModal();
        });

        // Fechar ao clicar fora
        const modal = document.getElementById('notificationsModal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target.id === 'notificationsModal') closeModal();
            });
        }

        document.addEventListener('chat-unread-updated', () => {
            updateBadgeUI(cache.unreadCount);
        });

        updateBadgeUI(cache.unreadCount);
    }

    function startPolling() {
        stopPolling();
        const tick = () => {
            pollTimer = setTimeout(() => {
                fetchCount().finally(tick);
            }, getSmartInterval());
        };
        tick();
    }

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function openModal() {
        const modal = document.getElementById('notificationsModal');
        if (!modal) return;

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.top = `-${window.scrollY}px`;
        isModalOpen = true;

        // Usar cache se válido, senão buscar
        if (isCacheValid()) {
            renderNotifications(false);
        } else {
            cache.offset = 0;
            cache.fullyLoaded = false;
            fetchNotifications(0, false);
        }
    }

    function closeModal() {
        const modal = document.getElementById('notificationsModal');
        if (!modal) return;

        modal.classList.remove('active');
        const scrollY = document.body.style.top;
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
        window.scrollTo(0, parseInt(scrollY || '0') * -1);
        isModalOpen = false;
    }

    function loadMore() {
        if (!cache.hasMore) return;

        const btn = document.querySelector('.notif-load-more-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Carregando...';
        }

        fetchNotifications(cache.offset, true).finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Carregar mais';
            }
        });
    }

    function handleClick(notifId, type, refId, commentId, actorUsername, notifScope) {
        // Marcar como lida no servidor
        fetch('api/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: notifId, notif_scope: notifScope })
        }).catch(() => {});

        // Atualizar cache local
        const notif = cache.notifications.find(n => n.id === notifId);
        if (notif && !notif.is_read) {
            notif.is_read = true;
            cache.unreadCount = Math.max(0, cache.unreadCount - 1);
            updateBadgeUI(cache.unreadCount);

            // Atualizar UI do item
            const el = document.querySelector(`[data-notif-id="${notifId}"]`);
            if (el) el.classList.remove('unread');
        }

        closeModal();

        // Navegar para o conteúdo
        if (type === 'like') {
            if (refId) window.location.href = `index.php?video_id=${refId}`;
        } else if (type === 'comment' || type === 'reply' || type === 'comment_like' || type === 'mention') {
            if (refId) {
                let url = `index.php?video_id=${refId}`;
                if (commentId) url += `&comment_id=${commentId}`;
                window.location.href = url;
            }
        } else if (type === 'bio_mention') {
            // Redirecionar para o perfil de quem mencionou
            if (actorUsername) window.location.href = `perfil.php?username=${actorUsername}`;
        } else if (type === 'friend_request') {
            // Abrir chat onde pode aceitar o pedido
            if (refId) window.location.href = `chat.php?from=feed`;
        } else if (type === 'follow' || type === 'unfollow' || type === 'friend_accept') {
            if (refId) window.location.href = `perfil.php?id=${refId}`;
        } else if (type === 'best_mytuber_global') {
            window.location.href = `ranking.php`;
        }
    }

    function markAllAsRead() {
        fetch('api/mark_all_notifications_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Atualizar cache
                cache.notifications.forEach(n => n.is_read = true);
                cache.unreadCount = 0;
                updateBadgeUI(0);

                // Atualizar UI
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                });
            }
        })
        .catch(() => {});
    }

    function invalidateCache() {
        cache.lastFetchTime = 0;
    }

    // Expor API pública
    return {
        init,
        openModal,
        closeModal,
        loadMore,
        handleClick,
        markAllAsRead,
        invalidateCache,
        updateBadge: updateBadgeUI
    };
})();

// Inicializar quando DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    NotificationSystem.init();
});
