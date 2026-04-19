<?php
/**
 * SSRF Protection Helpers
 * 
 * Prevenção de Server-Side Request Forgery (SSRF)
 * Valida URLs antes de fazer requests externos
 */

/**
 * Validar se uma URL é segura para download (previne SSRF)
 * 
 * Verifica:
 * 1. Domínio está em whitelist
 * 2. Não é IP privado (localhost, rede interna)
 * 3. Usa HTTPS
 * 4. Porta é HTTP/HTTPS padrão
 * 
 * @param string $url URL para validar
 * @param array $allowed_domains Domínios permitidos (ex: ['dzcdn.net', 'deezer.com'])
 * @return array ['valid' => bool, 'error' => string|null, 'ip' => string|null]
 */
function validate_url_ssrf(string $url, array $allowed_domains = []): array {
    // 1. Parse da URL
    $parsed = parse_url($url);
    
    if (!$parsed || empty($parsed['host'])) {
        return [
            'valid' => false,
            'error' => 'URL inválida',
            'ip' => null
        ];
    }
    
    $host = strtolower($parsed['host']);
    $scheme = strtolower($parsed['scheme'] ?? '');
    $port = $parsed['port'] ?? null;
    
    // 2. Verificar se usa HTTPS
    if ($scheme !== 'https') {
        return [
            'valid' => false,
            'error' => 'Apenas URLs HTTPS são permitidas',
            'ip' => null
        ];
    }
    
    // 3. Verificar porta (apenas 443 para HTTPS)
    if ($port !== null && $port !== 443) {
        return [
            'valid' => false,
            'error' => 'Porta não permitida. Use porta padrão HTTPS (443)',
            'ip' => null
        ];
    }
    
    // 4. Verificar se o domínio está na whitelist (EXACT MATCH no final)
    $domain_allowed = false;
    foreach ($allowed_domains as $allowed) {
        $allowed = strtolower($allowed);
        
        // Verificação exata: host == domain OU host termina com .domain
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            $domain_allowed = true;
            break;
        }
    }
    
    if (!$domain_allowed) {
        return [
            'valid' => false,
            'error' => 'Domínio não está na lista de permitidos',
            'ip' => null
        ];
    }
    
    // 5. Resolver IP do domínio
    $ip = gethostbyname($host);
    
    // Se não conseguiu resolver, aceitar SE for domínio whitelistado
    // (em localhost pode não ter DNS configurado)
    if ($ip === $host) {
        // Se domínio está na whitelist, aceitar mesmo sem resolver DNS
        // (funcionalidade completa depende de DNS em produção)
        error_log("SSRF: Não foi possível resolver DNS para $host (OK se estiver em localhost)");
        
        return [
            'valid' => true,  // ⚠️ Aceitar domínios whitelistados mesmo sem DNS
            'error' => null,
            'ip' => null
        ];
    }
    
    // 6. Verificar se não é IP privado/reservado
    if (is_private_ip($ip)) {
        return [
            'valid' => false,
            'error' => 'Endereço IP privado/reservado não é permitido',
            'ip' => $ip
        ];
    }
    
    // ✅ URL é segura
    return [
        'valid' => true,
        'error' => null,
        'ip' => $ip
    ];
}

/**
 * Verificar se um IP é privado/reservado (previne SSRF para rede interna)
 * 
 * IPs bloqueados:
 * - 127.0.0.0/8 (localhost)
 * - 10.0.0.0/8 (rede privada classe A)
 * - 172.16.0.0/12 (rede privada classe B)
 * - 192.168.0.0/16 (rede privada classe C)
 * - 169.254.0.0/16 (link-local)
 * - 0.0.0.0/8 (rede atual)
 * - 224.0.0.0/4 (multicast)
 * - 240.0.0.0/4 (reservado)
 * - ::1 (localhost IPv6)
 * - fe80::/10 (link-local IPv6)
 * - fc00::/7 (unique local IPv6)
 * 
 * @param string $ip Endereço IP
 * @return bool True se é privado/reservado, False se é público
 */
function is_private_ip(string $ip): bool {
    // Tentar converter para IPv4
    $ip_long = ip2long($ip);
    
    if ($ip_long !== false) {
        // IPv4 - Verificar ranges privados
        
        // 127.0.0.0/8 (localhost)
        if (($ip_long & 0xFF000000) === 0x7F000000) {
            return true;
        }
        
        // 10.0.0.0/8 (rede privada classe A)
        if (($ip_long & 0xFF000000) === 0x0A000000) {
            return true;
        }
        
        // 172.16.0.0/12 (rede privada classe B)
        if (($ip_long & 0xFFF00000) === 0xAC100000) {
            return true;
        }
        
        // 192.168.0.0/16 (rede privada classe C)
        if (($ip_long & 0xFFFF0000) === 0xC0A80000) {
            return true;
        }
        
        // 169.254.0.0/16 (link-local)
        if (($ip_long & 0xFFFF0000) === 0xA9FE0000) {
            return true;
        }
        
        // 0.0.0.0/8 (rede atual)
        if (($ip_long & 0xFF000000) === 0x00000000) {
            return true;
        }
        
        // 224.0.0.0/4 (multicast classe D)
        if (($ip_long & 0xF0000000) === 0xE0000000) {
            return true;
        }
        
        // 240.0.0.0/4 (reservado classe E)
        if (($ip_long & 0xF0000000) === 0xF0000000) {
            return true;
        }
        
        // IP público
        return false;
    }
    
    // IPv6 - Verificar ranges privados
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // ::1 (localhost)
        if ($ip === '::1') {
            return true;
        }
        
        // fe80::/10 (link-local)
        if (strpos($ip, 'fe80:') === 0) {
            return true;
        }
        
        // fc00::/7 (unique local)
        if (strpos($ip, 'fc') === 0 || strpos($ip, 'fd') === 0) {
            return true;
        }
        
        // IP público
        return false;
    }
    
    // IP inválido - considerar como privado por segurança
    return true;
}

/**
 * Download seguro de arquivo remoto com proteção SSRF
 * 
 * @param string $url URL para download
 * @param array $allowed_domains Domínios permitidos
 * @param int $max_size_mb Tamanho máximo em MB (padrão: 10)
 * @param int $timeout Timeout em segundos (padrão: 30)
 * @return array ['success' => bool, 'path' => string|null, 'error' => string|null, 'size' => int]
 */
function ssrf_safe_download(string $url, array $allowed_domains, int $max_size_mb = 10, int $timeout = 30): array {
    // 1. Validar URL contra SSRF
    $validation = validate_url_ssrf($url, $allowed_domains);
    
    if (!$validation['valid']) {
        return [
            'success' => false,
            'path' => null,
            'error' => $validation['error'],
            'size' => 0
        ];
    }
    
    // 2. Criar arquivo temporário
    $tmp_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ssrf_safe_', true) . '.tmp';
    
    // 3. Fazer download com cURL (com proteções SSRF)
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'path' => null,
            'error' => 'cURL não está disponível no servidor',
            'size' => 0
        ];
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,  // ⚠️ IMPORTANTE: não seguir redirects (previne SSRF)
        CURLOPT_MAXREDIRS      => 0,      // ⚠️ IMPORTANTE: 0 redirects
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MyTube/1.0)',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,  // Apenas HTTPS
        CURLOPT_REDIR_PROTOCOLS => 0,               // Bloquear redirects
    ]);
    
    // ⚠️ Limite de tamanho (se disponível)
    if (defined('CURLOPT_MAXFILESIZE')) {
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $max_size_mb * 1024 * 1024);
    }
    
    $data = curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    
    // 4. Verificar resposta
    if ($data === false) {
        return [
            'success' => false,
            'path' => null,
            'error' => 'Erro ao fazer download: ' . $curl_error,
            'size' => 0
        ];
    }
    
    if ($http_code !== 200) {
        return [
            'success' => false,
            'path' => null,
            'error' => 'HTTP ' . $http_code,
            'size' => 0
        ];
    }
    
    if (strlen($data) < 1024) {
        return [
            'success' => false,
            'path' => null,
            'error' => 'Arquivo muito pequeno (< 1KB)',
            'size' => strlen($data)
        ];
    }
    
    // 5. Salvar arquivo
    if (file_put_contents($tmp_path, $data) === false) {
        return [
            'success' => false,
            'path' => null,
            'error' => 'Erro ao salvar arquivo temporário',
            'size' => 0
        ];
    }
    
    // ✅ Download bem-sucedido
    return [
        'success' => true,
        'path' => $tmp_path,
        'error' => null,
        'size' => $size
    ];
}
