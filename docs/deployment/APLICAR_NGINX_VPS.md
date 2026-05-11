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

### 2️⃣ Ver a configuração SSL atual
```bash
sudo cat /etc/nginx/sites-available/mytube.social | grep -A 5 "ssl_certificate"
```

**IMPORTANTE:** Copia TODOS os caminhos SSL que aparecerem (ssl_certificate, ssl_certificate_key, etc.)

### 3️⃣ Editar a configuração
```bash
sudo nano /etc/nginx/sites-available/mytube.social
```

### 4️⃣ Substituir TODO o conteúdo pelo arquivo `nginx-config-complete.conf`

**CRÍTICO - ANTES DE SALVAR:**
1. Copiar TODO o conteúdo de `nginx-config-complete.conf`
2. Colar no editor
3. **PROCURAR por `/path/to/your/certificate` (aparece 4 vezes)**
4. **SUBSTITUIR pelos caminhos SSL reais** que copiaste no passo 2️⃣
5. Verificar se há outros parâmetros SSL (ssl_protocols, ssl_ciphers, etc.) e copiar também

O que a nova configuração faz:
- 🔄 HTTP → HTTPS (ambos domínios)
- 🔄 **www.mytube.social → mytube.social (NOVO)**
- ✅ Site principal funciona apenas em `mytube.social`

### 5️⃣ Verificar se a sintaxe está correta
```bash
sudo nginx -t
```

**Se aparecer erros:**
- ❌ `No such file or directory` nos certificados SSL → caminhos errados, voltar ao passo 2️⃣
- ❌ `duplicate listen` → remover linhas duplicadas
- ❌ Socket PHP não encontrado → verificar com: `ls /var/run/php/*.sock`

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
