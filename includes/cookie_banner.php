<?php
// includes/cookie_banner.php
?>
<style>
    #cookie-banner {
        position: fixed;
        bottom: 20px;
        left: 20px;
        right: 20px;
        background: #111827;
        color: #f9fafb;
        padding: 16px 24px;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        z-index: 999999;
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        line-height: 1.5;
        max-width: 900px;
        margin: 0 auto;
        opacity: 0;
        visibility: hidden;
        transform: translateY(20px);
        transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease;
    }
    #cookie-banner.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    #cookie-banner p { margin: 0; }
    #cookie-banner a { color: #60a5fa; text-decoration: underline; font-weight: 500; }
    .cookie-btn {
        background: #3b82f6;
        color: #fff;
        border: none;
        padding: 8px 18px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
        white-space: nowrap;
    }
    .cookie-btn:hover { background: #2563eb; }
    
    @media (max-width: 600px) {
        #cookie-banner {
            flex-direction: column;
            align-items: stretch;
            bottom: 10px; left: 10px; right: 10px;
            padding: 16px;
        }
        .cookie-btn { text-align: center; }
    }
</style>

<div id="cookie-banner">
    <p>
        Usamos cookies para melhorar a tua experiência e personalizar os anúncios (Google AdSense). 
        Ao continuares, concordas com a nossa <a href="privacidade.php">Política de Privacidade</a>.
    </p>
    <button class="cookie-btn" onclick="acceptCookies()">Aceitar e Continuar</button>
</div>

<script>
    function acceptCookies() {
        localStorage.setItem('mytube_cookies_accepted', 'true');
        document.getElementById('cookie-banner').classList.remove('show');
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (!localStorage.getItem('mytube_cookies_accepted')) {
            setTimeout(function() {
                document.getElementById('cookie-banner').classList.add('show');
            }, 1000); // Mostra 1 segundo após carregar
        }
    });
</script>
