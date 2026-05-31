<!-- Favicon -->
<link rel="shortcut icon" href="assets/images/logo_icon.png" type="image/x-icon">
<link rel="icon" type="image/png" sizes="192x192" href="assets/images/logo_icon.png">

<?php
$swVersion = @filemtime(__DIR__ . '/../sw.js') ?: time();
$manifestVersion = @filemtime(__DIR__ . '/../manifest.json') ?: time();
?>

<!-- PWA Manifest -->
<link rel="manifest" href="/manifest.json?v=<?php echo $manifestVersion; ?>">

<!-- PWA Meta Tags -->
<meta name="theme-color" content="#111111">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MyTube">
<link rel="apple-touch-icon" href="/assets/images/logo_icon.png">
    <!-- <link rel="apple-touch-startup-image" href="assets/images/logo.png">
 -->
<!-- PWA: Service Worker Registration -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js?v=<?php echo $swVersion; ?>', { scope: '/' })
            .then(function(reg) {
                console.log('[PWA] Service Worker registrado com sucesso.', reg.scope);
                
                // Forçar a checagem manual de novas versões do sw.js no servidor!
                reg.update();

                // Verificar por atualizações
                reg.addEventListener('updatefound', function() {
                    const newSW = reg.installing;
                    newSW.addEventListener('statechange', function() {
                        if (newSW.state === 'installed' && navigator.serviceWorker.controller) {
                            console.log('[PWA] Nova versão disponível!');
                        }
                    });
                });
            })
            .catch(function(err) {
                console.warn('[PWA] Falha ao registrar Service Worker:', err);
            });
    });
}

// ─── Prompt de instalação (botão "Instalar App") ──────────────────────────
window._pwaInstallPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    window._pwaInstallPrompt = e;
    // Mostrar botão de instalação se existir na página
    const installBtn = document.getElementById('pwaInstallBtn');
    if (installBtn) installBtn.style.display = 'flex';
});

window.addEventListener('appinstalled', function() {
    window._pwaInstallPrompt = null;
    const installBtn = document.getElementById('pwaInstallBtn');
    if (installBtn) installBtn.style.display = 'none';
    console.log('[PWA] App instalado com sucesso!');
});

// Função global para disparar o prompt de instalação
window.installPWA = function() {
    if (window._pwaInstallPrompt) {
        window._pwaInstallPrompt.prompt();
        window._pwaInstallPrompt.userChoice.then(function(result) {
            console.log('[PWA] Usuário escolheu:', result.outcome);
            window._pwaInstallPrompt = null;
        });
    }
};
</script>

<?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
<script src="assets/js/push-notifications.js" defer></script>
<?php endif; ?>
