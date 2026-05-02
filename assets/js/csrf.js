/**
 * Sistema de proteção CSRF para requests AJAX
 * Adiciona automaticamente o token CSRF em todos os requests POST
 */

// Obter token CSRF do meta tag ou campo hidden
function getCsrfToken() {
    // Tentar pegar do meta tag
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
        return metaTag.getAttribute('content');
    }
    
    // Tentar pegar de campo hidden no formulário
    const hiddenInput = document.querySelector('input[name="csrf_token"]');
    if (hiddenInput) {
        return hiddenInput.value;
    }
    
    console.warn('CSRF token não encontrado');
    return null;
}

// Interceptar todos os fetch() para adicionar CSRF token
const originalFetch = window.fetch;
window.fetch = function(...args) {
    let [resource, config] = args;
    
    // Se não tem config, criar um objeto vazio
    if (!config) {
        config = {};
    }
    
    // Se é POST/PUT/DELETE/PATCH, adicionar CSRF token
    const method = (config.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
        const csrfToken = getCsrfToken();
        
        if (csrfToken) {
            // Se o body é FormData, adicionar o token como campo
            if (config.body instanceof FormData) {
                config.body.append('csrf_token', csrfToken);
            }
            // Se o body é JSON string, injetar csrf_token no JSON + header
            else if (typeof config.body === 'string') {
                try {
                    const parsed = JSON.parse(config.body);
                    if (typeof parsed === 'object' && parsed !== null) {
                        parsed.csrf_token = csrfToken;
                        config.body = JSON.stringify(parsed);
                    }
                } catch (e) {
                    // Não é JSON válido, ignorar
                }
                // Adicionar header como fallback
                if (!config.headers) config.headers = {};
                if (config.headers instanceof Headers) {
                    config.headers.set('X-CSRF-Token', csrfToken);
                } else {
                    config.headers['X-CSRF-Token'] = csrfToken;
                }
            }
            // Para todos os outros casos, usar header
            else {
                if (!config.headers) config.headers = {};
                if (config.headers instanceof Headers) {
                    config.headers.set('X-CSRF-Token', csrfToken);
                } else {
                    config.headers['X-CSRF-Token'] = csrfToken;
                }
            }
        }
    }
    
    return originalFetch.apply(this, [resource, config]);
};

// Interceptar XMLHttpRequest para adicionar CSRF token
(function() {
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        this._method = method.toUpperCase();
        this._url = url;
        return originalOpen.apply(this, [method, url, ...args]);
    };
    
    XMLHttpRequest.prototype.send = function(data) {
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(this._method)) {
            const csrfToken = getCsrfToken();
            
            if (csrfToken) {
                // Se o data é FormData, adicionar o token
                if (data instanceof FormData) {
                    data.append('csrf_token', csrfToken);
                }
                // Caso contrário, adicionar no header
                else {
                    this.setRequestHeader('X-CSRF-Token', csrfToken);
                }
            }
        }
        
        return originalSend.apply(this, [data]);
    };
})();

console.log('CSRF protection loaded');
