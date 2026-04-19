/**
 * Modal Mobile Helper
 * Corrige problemas de viewport e teclado no mobile
 */

(function() {
    'use strict';
    
    // Detectar se é dispositivo mobile (inclui "Solicitar site desktop" ativo)
    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent)
        || ('ontouchstart' in window && screen.width <= 1024);
    
    if (!isMobile) return;
    
    // Função para calcular altura real do viewport
    function setViewportHeight() {
        // Usar innerHeight ao invés de 100vh para evitar problemas com barra de endereço
        let vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', `${vh}px`);
    }
    
    // Atualizar no load e resize
    window.addEventListener('load', setViewportHeight);
    window.addEventListener('resize', setViewportHeight);
    window.addEventListener('orientationchange', setViewportHeight);
    
    // Prevenir zoom ao focar inputs
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('input, textarea, select');
        
        inputs.forEach(input => {
            // Garantir font-size mínimo de 16px para prevenir zoom no iOS
            const fontSize = window.getComputedStyle(input).fontSize;
            if (parseFloat(fontSize) < 16) {
                input.style.fontSize = '16px';
            }
        });
    });
    
    // Gerenciar Visual Viewport API (melhor detecção de teclado)
    if ('visualViewport' in window) {
        const viewport = window.visualViewport;
        
        viewport.addEventListener('resize', function() {
            const modal = document.getElementById('commentsModal');
            const modalContent = modal?.querySelector('.modal-content');
            
            if (!modal || !modalContent) return;
            if (!modal.classList.contains('open')) return;
            
            // Calcular diferença de altura
            const viewportHeight = viewport.height;
            const windowHeight = window.innerHeight;
            const diff = windowHeight - viewportHeight;
            
            // Se diferença > 150px, teclado está aberto
            if (diff > 150) {
                modalContent.style.maxHeight = `${viewportHeight}px`;
                modalContent.classList.add('keyboard-open');
            } else {
                modalContent.style.maxHeight = '';
                modalContent.classList.remove('keyboard-open');
            }
        });
    }
    
    // Prevenir body scroll quando modal está aberto
    let scrollPosition = 0;
    
    window.addEventListener('openModal', function() {
        scrollPosition = window.pageYOffset;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${scrollPosition}px`;
        document.body.style.width = '100%';
    });
    
    window.addEventListener('closeModal', function() {
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        window.scrollTo(0, scrollPosition);
    });
    
})();
