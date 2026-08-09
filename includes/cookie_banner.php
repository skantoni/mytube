<?php
// includes/cookie_banner.php
if (isset($_COOKIE['mytube_cookies_accepted']) && $_COOKIE['mytube_cookies_accepted'] === 'true') {
    return; // Se já aceitou os cookies, nem renderiza o HTML do banner
}
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
        Usamos cookies para melhorar a tua experiência na plataforma e personalizar conteúdos. 
        Ao continuares, concordas com a nossa <a href="privacidade.php">Política de Privacidade</a>.
    </p>
    <button class="cookie-btn" onclick="acceptCookies()">Aceitar e Continuar</button>
</div>

<script>
    function acceptCookies() {
        // Define localStorage por garantia
        localStorage.setItem('mytube_cookies_accepted', 'true');
        
        // Define um cookie válido por 1 ano para o servidor também ler
        let d = new Date();
        d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = "mytube_cookies_accepted=true; expires=" + d.toUTCString() + "; path=/";
        
        let banner = document.getElementById('cookie-banner');
        if(banner) {
            banner.classList.remove('show');
            setTimeout(() => banner.remove(), 300); // Remove do DOM após animação
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        // Verifica se o cookie ou localStorage existe
        let hasCookie = document.cookie.split('; ').find(row => row.startsWith('mytube_cookies_accepted='));
        if (!hasCookie && !localStorage.getItem('mytube_cookies_accepted')) {
            setTimeout(function() {
                let banner = document.getElementById('cookie-banner');
                if(banner) banner.classList.add('show');
            }, 1000); // Mostra 1 segundo após carregar
        }
    });
</script>
