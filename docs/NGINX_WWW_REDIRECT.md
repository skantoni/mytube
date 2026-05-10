# Configuração Nginx - Redirect WWW para Sem WWW (ou vice-versa)

## Problema Identificado (2026-05-01)
Links compartilhados com `www.mytube.social` não mantinham sessão para usuários logados em `mytube.social` (sem www). Navegadores tratam `www.mytube.social` e `mytube.social` como domínios **completamente diferentes** para cookies.

## Solução Aplicada

### 1. PHP Cookie Domain (.mytube.social)
Configurado em `includes/config.php` para usar `.mytube.social` (com ponto no início), o que faz o cookie funcionar em:
- ✅ `mytube.social`
- ✅ `www.mytube.social`
- ✅ `qualquer.subdominio.mytube.social`

### 2. Nginx Redirect (Recomendado)
**Escolha uma das duas opções:**

#### Opção A: Redirecionar WWW → Sem WWW (Recomendado)
```nginx
# Redirect www.mytube.social → mytube.social
server {
    listen 80;
    listen 443 ssl http2;
    server_name www.mytube.social;

    # Certificados SSL (se HTTPS)
    ssl_certificate /etc/letsencrypt/live/mytube.social/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mytube.social/privkey.pem;

    # Redirect permanente (301)
    return 301 https://mytube.social$request_uri;
}

# Site principal (sem www)
server {
    listen 80;
    listen 443 ssl http2;
    server_name mytube.social;

    # ... resto da configuração normal do site
}
```

#### Opção B: Redirecionar Sem WWW → WWW (Alternativa)
```nginx
# Redirect mytube.social → www.mytube.social
server {
    listen 80;
    listen 443 ssl http2;
    server_name mytube.social;

    ssl_certificate /etc/letsencrypt/live/mytube.social/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mytube.social/privkey.pem;

    return 301 https://www.mytube.social$request_uri;
}

# Site principal (com www)
server {
    listen 80;
    listen 443 ssl http2;
    server_name www.mytube.social;

    # ... resto da configuração normal do site
}
```

## Como Aplicar no VPS

### 1. Editar configuração Nginx
```bash
sudo nano /etc/nginx/sites-available/mytube.social
```

### 2. Adicionar bloco de redirect ANTES do bloco principal
Escolha a Opção A (sem www) ou Opção B (com www) e cole no topo do arquivo.

### 3. Verificar sintaxe
```bash
sudo nginx -t
```

### 4. Recarregar Nginx
```bash
sudo systemctl reload nginx
```

### 5. Atualizar certificados SSL (se necessário)
Se escolheu adicionar/remover www, pode precisar atualizar o certificado:
```bash
sudo certbot --nginx -d mytube.social -d www.mytube.social
```

## Atualizar Links Compartilhados

### Onde os links são gerados:
- `index.php` - Botão de compartilhar vídeos
- `api/get_feed.php` - Links na API
- Qualquer lugar que use `SITE_URL`

### Verificar SITE_URL no .env
```bash
# No VPS
nano /var/www/mytube.social/.env
```

Garantir que `SITE_URL` usa o domínio escolhido:
```
SITE_URL=https://mytube.social  # Sem www (Opção A)
# OU
SITE_URL=https://www.mytube.social  # Com www (Opção B)
```

## Resultado Esperado
✅ Usuário faz login em `mytube.social` (ou `www.mytube.social`)  
✅ Cookie configurado para `.mytube.social` (funciona em ambos)  
✅ Clica em link com `www.mytube.social` (ou `mytube.social`)  
✅ Nginx redireciona para domínio consistente  
✅ Cookie é enviado corretamente  
✅ Sessão mantida, usuário continua logado  

## Debug
Após aplicar, testar:
```bash
# Testar redirect
curl -I https://www.mytube.social
# Deve retornar: HTTP/1.1 301 Moved Permanently
# Location: https://mytube.social/

# Verificar cookie domain (com navegador)
1. Fazer login
2. Abrir DevTools → Application → Cookies
3. Verificar que o cookie tem Domain=.mytube.social (com ponto)
```

## Referência
- Data: 2026-05-01
- Issue: Sessão perdida ao clicar em links externos com www
- Root Cause: Cookie domain mismatch (www vs sem www)
- Fix: PHP cookie domain + Nginx redirect
