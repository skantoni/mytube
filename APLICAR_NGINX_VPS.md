# 🚀 PASSO A PASSO - Aplicar Configuração Nginx no VPS

## ✅ O que já fizeste:
1. ✓ Mudou `.env` para `SITE_URL=https://mytube.social`
2. ✓ Links agora vêm sem www

## 📋 O que falta fazer no VPS:

### 1️⃣ Fazer backup da configuração atual
```bash
cd /etc/nginx/sites-available
sudo cp mytube.social mytube.social.backup
```

### 2️⃣ Editar a configuração
```bash
sudo nano /etc/nginx/sites-available/mytube.social
```

### 3️⃣ Substituir TODO o conteúdo pelo arquivo `nginx-config-complete.conf`

**IMPORTANTE:** Copiar TODO o conteúdo de `nginx-config-complete.conf` e colar no editor.

O que a nova configuração faz:
- 🔄 HTTP → HTTPS (ambos domínios)
- 🔄 **www.mytube.social → mytube.social (NOVO)**
- ✅ Site principal funciona apenas em `mytube.social`

### 4️⃣ Verificar se a sintaxe está correta
```bash
sudo nginx -t
```

Se aparecer erros, verificar:
- Caminhos dos certificados SSL
- Socket do PHP-FPM (pode ser `php8.1-fpm.sock` ou `php8.2-fpm.sock`)

### 5️⃣ Recarregar Nginx
```bash
sudo systemctl reload nginx
```

### 6️⃣ Testar

#### Teste 1: Verificar redirect www → sem www
```bash
curl -I https://www.mytube.social
```
**Esperado:** 
```
HTTP/2 301
location: https://mytube.social/
```

#### Teste 2: Verificar site sem www funciona
```bash
curl -I https://mytube.social
```
**Esperado:**
```
HTTP/2 200
```

#### Teste 3: Testar no navegador
1. Abrir: `https://www.mytube.social`
2. Deve redirecionar automaticamente para: `https://mytube.social`
3. URL no navegador deve mudar para sem www

#### Teste 4: Testar link compartilhado (CRÍTICO)
1. Fazer login em `https://mytube.social`
2. Copiar link de vídeo (já vem sem www)
3. Enviar para ti no WhatsApp
4. Clicar no link vindo do WhatsApp
5. **✅ DEVE MANTER A SESSÃO! (Não redirecionar para login)**

### 7️⃣ Se algo der errado, reverter:
```bash
sudo cp /etc/nginx/sites-available/mytube.social.backup /etc/nginx/sites-available/mytube.social
sudo systemctl reload nginx
```

## 🎯 Resultado Final

Depois desta configuração:
- ✅ Todos os links compartilhados usam `mytube.social` (sem www)
- ✅ Se alguém digitar `www.mytube.social`, é redirecionado para sem www
- ✅ Cookie configurado para `.mytube.social` funciona em ambos
- ✅ Sessão mantida ao clicar em links externos
- ✅ Um único domínio consistente em todo o site

## 📌 Notas Importantes

### Certificado SSL
Se o certificado atual NÃO incluir ambos os domínios, renovar com:
```bash
sudo certbot --nginx -d mytube.social -d www.mytube.social
```

### Verificar certificado
```bash
sudo certbot certificates
```

Deve mostrar:
```
Domains: mytube.social www.mytube.social
```

Se só tiver um, renovar conforme comando acima.
