/**
 * Push Notifications Manager — MyTube PWA
 * 
 * Gere a permissão do browser, subscrição ao push service,
 * e sincronização com o servidor.
 * 
 * Carregado automaticamente no header.php quando o utilizador está logado.
 */
const PushManager_ = (function() {
    let vapidPublicKey = null;
    let isSubscribed = false;
    let swRegistration = null;
    let initialized = false;

    /**
     * Inicializa o sistema de push.
     * Chamado automaticamente quando o Service Worker estiver pronto.
     */
    async function init() {
        if (initialized) return;
        initialized = true;

        // Verificar suporte a Push API
        if (!('PushManager' in window) || !('serviceWorker' in navigator)) {
            console.log('[Push] Browser não suporta Push Notifications');
            return;
        }

        try {
            // Verificar se push está habilitado no servidor e obter chave VAPID
            const response = await fetch('api/push_subscribe.php');
            const data = await response.json();

            if (!data.success || !data.enabled) {
                console.log('[Push] Push notifications desabilitadas no servidor');
                return;
            }

            vapidPublicKey = data.vapid_public_key;
            isSubscribed = data.subscribed;

            // Aguardar Service Worker
            swRegistration = await navigator.serviceWorker.ready;

            // Verificar estado da subscrição local
            const subscription = await swRegistration.pushManager.getSubscription();

            if (subscription && !isSubscribed) {
                // Tem subscrição local mas não no servidor → sincronizar
                await saveSubscription(subscription);
            } else if (!subscription && isSubscribed) {
                // Servidor diz que tem mas localmente não → limpar servidor
                await removeSubscription('');
                isSubscribed = false;
            }

            // Se ainda não pediu permissão, pedir após interação do utilizador
            if (!subscription && Notification.permission === 'default') {
                schedulePermissionPrompt();
            } else if (Notification.permission === 'granted' && !subscription) {
                // Permissão concedida mas sem subscrição → subscrever
                await subscribe();
            }

        } catch (err) {
            console.warn('[Push] Erro na inicialização:', err);
        }
    }

    /**
     * Agenda o pedido de permissão após o utilizador interagir com a página.
     * Não mostra popup imediatamente — espera 30s + interação.
     */
    function schedulePermissionPrompt() {
        let prompted = false;
        const DELAY = 30000; // 30 segundos

        function showPrompt() {
            if (prompted) return;
            prompted = true;
            showPermissionBanner();
        }

        // Mostrar após 30s + interação
        setTimeout(() => {
            // Só mostrar se houve pelo menos um scroll ou click
            const events = ['click', 'scroll', 'touchstart'];
            let interacted = false;

            function onInteraction() {
                if (interacted) return;
                interacted = true;
                events.forEach(e => document.removeEventListener(e, onInteraction));
                // Delay extra de 2s após interação
                setTimeout(showPrompt, 2000);
            }

            events.forEach(e => document.addEventListener(e, onInteraction, { once: false, passive: true }));
        }, DELAY);
    }

    /**
     * Mostra um banner discreto (não o popup nativo) a pedir permissão.
     */
    function showPermissionBanner() {
        // Não mostrar se já foi descartado nesta sessão
        if (sessionStorage.getItem('push_banner_dismissed')) return;

        const banner = document.createElement('div');
        banner.id = 'push-permission-banner';
        banner.innerHTML = `
            <div style="
                position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
                background: #1a1a2e; color: #fff; padding: 14px 20px;
                border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.4);
                display: flex; align-items: center; gap: 12px;
                z-index: 10000; max-width: 420px; width: calc(100% - 32px);
                font-size: 14px; border: 1px solid rgba(255,255,255,.1);
            ">
                <div style="font-size: 24px;">🔔</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 2px;">Ativar notificações?</div>
                    <div style="opacity: .75; font-size: 12px;">Receba alertas de mensagens, likes e comentários mesmo fora do app.</div>
                </div>
                <button id="push-accept-btn" style="
                    background: #6c5ce7; color: #fff; border: none; padding: 8px 16px;
                    border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 13px;
                    white-space: nowrap;
                ">Ativar</button>
                <button id="push-dismiss-btn" style="
                    background: transparent; color: #888; border: none;
                    cursor: pointer; font-size: 18px; padding: 4px;
                ">✕</button>
            </div>
        `;

        document.body.appendChild(banner);

        document.getElementById('push-accept-btn').addEventListener('click', async () => {
            banner.remove();
            await requestPermissionAndSubscribe();
        });

        document.getElementById('push-dismiss-btn').addEventListener('click', () => {
            banner.remove();
            sessionStorage.setItem('push_banner_dismissed', '1');
        });
    }

    /**
     * Pede permissão ao browser e subscreve se concedida.
     */
    async function requestPermissionAndSubscribe() {
        try {
            const permission = await Notification.requestPermission();
            if (permission === 'granted') {
                await subscribe();
            }
        } catch (err) {
            console.warn('[Push] Erro ao pedir permissão:', err);
        }
    }

    /**
     * Subscreve ao serviço de push e salva no servidor.
     */
    async function subscribe() {
        if (!swRegistration || !vapidPublicKey) return;

        try {
            const subscription = await swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
            });

            await saveSubscription(subscription);
            isSubscribed = true;
            console.log('[Push] Subscrição ativada com sucesso');
        } catch (err) {
            console.warn('[Push] Erro ao subscrever:', err);
        }
    }

    /**
     * Remove a subscrição push.
     */
    async function unsubscribe() {
        if (!swRegistration) return;

        try {
            const subscription = await swRegistration.pushManager.getSubscription();
            if (subscription) {
                const endpoint = subscription.endpoint;
                await subscription.unsubscribe();
                await removeSubscription(endpoint);
            }
            isSubscribed = false;
            console.log('[Push] Subscrição removida');
        } catch (err) {
            console.warn('[Push] Erro ao remover subscrição:', err);
        }
    }

    /**
     * Salva a subscrição no servidor.
     */
    async function saveSubscription(subscription) {
        try {
            const response = await fetch('api/push_subscribe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'subscribe',
                    subscription: subscription.toJSON()
                })
            });
            const data = await response.json();
            if (!data.success) {
                console.warn('[Push] Erro ao salvar subscrição:', data.error);
            }
        } catch (err) {
            console.warn('[Push] Erro ao salvar subscrição:', err);
        }
    }

    /**
     * Remove a subscrição do servidor.
     */
    async function removeSubscription(endpoint) {
        try {
            await fetch('api/push_subscribe.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'unsubscribe',
                    endpoint: endpoint
                })
            });
        } catch (err) {
            console.warn('[Push] Erro ao remover subscrição do servidor:', err);
        }
    }

    /**
     * Converte chave VAPID de base64 URL para Uint8Array.
     */
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    // ========== API Pública ==========
    return {
        init,
        subscribe,
        unsubscribe,
        isSubscribed: () => isSubscribed
    };
})();

// Auto-iniciar quando o DOM estiver pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => PushManager_.init());
} else {
    PushManager_.init();
}
