class BoostedVideosPanel {
    constructor() {
        this.grid = document.getElementById('boostedGrid');
        this.emptyState = document.getElementById('boostedEmptyState');
        this.countEl = document.getElementById('boostedCount');
        this.creatorsEl = document.getElementById('boostedCreatorsCount');
        this.viewsEl = document.getElementById('boostedViewsCount');
        this.metaEl = document.getElementById('boostedListMeta');

        this.bindEvents();
        this.updateSummary();
        this.loadMetrics();
    }

    async loadMetrics() {
        try {
            const response = await fetch('api/boost_metrics.php?days=30', { credentials: 'same-origin' });
            const data = await response.json();
            
            if (!data.success) {
                this.setMetricsUnavailable();
                return;
            }

            // Atualizar cards de resumo
            const impressionsEl = document.getElementById('boostedImpressions');
            const reachEl = document.getElementById('boostedReach');
            const avgCtrEl = document.getElementById('boostedAvgCtr');

            if (impressionsEl) impressionsEl.textContent = this.formatCompactNumber(data.summary.total_impressions || 0);
            if (reachEl) reachEl.textContent = this.formatCompactNumber(data.summary.total_unique_users || 0);

            // Calcular CTR médio
            if (data.metrics && data.metrics.length > 0) {
                const totalCtr = data.metrics.reduce((sum, m) => sum + (m.ctr || 0), 0);
                const avgCtr = (totalCtr / data.metrics.length).toFixed(1);
                if (avgCtrEl) {
                    avgCtrEl.textContent = avgCtr + '%';
                    avgCtrEl.className = 'boosted-stat-value ' + this.getCtrClass(parseFloat(avgCtr));
                }

                // Atualizar barras CTR por vídeo
                data.metrics.forEach(metric => {
                    this.updateVideoCtr(metric);
                });
            } else {
                if (avgCtrEl) avgCtrEl.textContent = '—';
            }
        } catch (error) {
            console.error('Erro ao carregar métricas:', error);
            this.setMetricsUnavailable();
        }
    }

    setMetricsUnavailable() {
        const ids = ['boostedImpressions', 'boostedReach', 'boostedAvgCtr'];
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = '—';
        });
    }

    updateVideoCtr(metric) {
        const ctrValue = document.getElementById('ctrValue_' + metric.video_id);
        const ctrFill = document.getElementById('ctrFill_' + metric.video_id);
        const ctrImpressions = document.getElementById('ctrImpressions_' + metric.video_id);
        const ctrReach = document.getElementById('ctrReach_' + metric.video_id);

        if (ctrValue) {
            ctrValue.textContent = metric.ctr + '%';
            ctrValue.className = 'ctr-value ' + this.getCtrClass(metric.ctr);
        }

        if (ctrFill) {
            const fillWidth = Math.min(100, metric.ctr * 5); // 20% CTR = 100% bar
            ctrFill.style.width = fillWidth + '%';
            ctrFill.className = 'ctr-bar-fill ' + this.getCtrFillClass(metric.ctr);
        }

        if (ctrImpressions) {
            ctrImpressions.textContent = this.formatCompactNumber(metric.total_impressions) + ' impressões';
        }

        if (ctrReach) {
            ctrReach.textContent = this.formatCompactNumber(metric.unique_users) + ' users';
        }
    }

    getCtrClass(ctr) {
        if (ctr >= 8) return 'ctr-high';
        if (ctr >= 3) return 'ctr-medium';
        return 'ctr-low';
    }

    getCtrFillClass(ctr) {
        if (ctr >= 8) return 'ctr-fill-high';
        if (ctr >= 3) return 'ctr-fill-medium';
        return 'ctr-fill-low';
    }

    bindEvents() {
        document.addEventListener('click', (event) => {
            const button = event.target.closest('.js-remove-boost');
            if (!button) {
                return;
            }

            event.preventDefault();
            this.removeBoost(button);
        });
    }

    async removeBoost(button) {
        const videoId = button.dataset.videoId;
        if (!videoId) {
            return;
        }

        button.disabled = true;
        const originalHtml = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removendo...';

        try {
            const formData = new FormData();
            formData.append('video_id', videoId);
            formData.append('boosted', '0');

            const response = await fetch('api/toggle_boost.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.error || 'Erro ao remover boost');
            }

            this.removeCard(videoId);
            this.showToast('Boost removido com sucesso.', 'success');
        } catch (error) {
            console.error('Erro ao remover boost:', error);
            this.showToast(error.message || 'Erro ao remover boost.', 'error');
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }

    removeCard(videoId) {
        const card = document.querySelector(`.boosted-video-card[data-video-id="${videoId}"]`);
        if (!card) {
            this.updateSummary();
            return;
        }

        card.classList.add('is-removing');
        setTimeout(() => {
            card.remove();
            this.updateSummary();
        }, 250);
    }

    updateSummary() {
        const cards = Array.from(document.querySelectorAll('.boosted-video-card'));
        const total = cards.length;
        const creators = new Set(cards.map((card) => card.dataset.userId).filter(Boolean)).size;
        const views = cards.reduce((sum, card) => sum + (parseInt(card.dataset.views || '0', 10) || 0), 0);

        if (this.countEl) {
            this.countEl.textContent = String(total);
        }

        if (this.creatorsEl) {
            this.creatorsEl.textContent = String(creators);
        }

        if (this.viewsEl) {
            this.viewsEl.textContent = this.formatCompactNumber(views);
        }

        if (this.metaEl) {
            this.metaEl.textContent = `${total} vídeo${total === 1 ? '' : 's'} com boost ativo`;
        }

        if (this.grid) {
            this.grid.classList.toggle('boosted-hidden', total === 0);
        }

        if (this.emptyState) {
            this.emptyState.classList.toggle('boosted-hidden', total !== 0);
        }
    }

    formatCompactNumber(value) {
        const num = Number(value) || 0;
        if (num >= 1000000000) {
            const result = (num / 1000000000).toFixed(1);
            return (result.endsWith('.0') ? result.slice(0, -2) : result) + 'B';
        }
        if (num >= 1000000) {
            const result = (num / 1000000).toFixed(1);
            return (result.endsWith('.0') ? result.slice(0, -2) : result) + 'M';
        }
        if (num >= 1000) {
            const result = (num / 1000).toFixed(1);
            return (result.endsWith('.0') ? result.slice(0, -2) : result) + 'k';
        }
        return String(num);
    }

    showToast(message, type) {
        const existing = document.querySelector('.boosted-toast');
        if (existing) {
            existing.remove();
        }

        const toast = document.createElement('div');
        toast.className = `boosted-toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            setTimeout(() => toast.remove(), 200);
        }, 2200);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('boostedGrid') || document.getElementById('boostedEmptyState')) {
        window.boostedVideosPanel = new BoostedVideosPanel();
    }
});