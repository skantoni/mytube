# 🚀 APLICAR CONFIGURAÇÃO NGINX - INSTRUÇÕES FINAIS

## ✅ O que foi mudado:

1. **BLOCO 1** (HTTP → HTTPS): Mudado `$server_name` para `$host` (melhor prática)
2. **BLOCO 2** (NOVO): Redireciona `www.mytube.social` → `mytube.social`
3. **BLOCO 3** (Principal): Agora aceita APENAS `mytube.social` (sem www)

## 📋 Passos no VPS:

### 1️⃣ Fazer backup
```bash
sudo cp /etc/nginx/sites-available/mytube.social /etc/nginx/sites-available/mytube.social.backup
```

### 2️⃣ Copiar nova configuração
No teu computador, abrir: `nginx-mytube-final.conf`

**Selecionar TUDO** (Ctrl+A ou Cmd+A) e **Copiar** (Ctrl+C)

### 3️⃣ Editar no VPS
```bash
sudo nano /etc/nginx/sites-available/mytube.social
```

**Apagar tudo** (Ctrl+K várias vezes até limpar)

**Colar** a nova configuração (Ctrl+Shift+V ou botão direito → Paste)

**Salvar** (Ctrl+O, Enter, Ctrl+X)

### 4️⃣ Testar configuração
```bash
sudo nginx -t
```

**Deve aparecer:**
```
nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
nginx: configuration file /etc/nginx/nginx.conf test is successful
```

### 5️⃣ Aplicar
```bash
sudo systemctl reload nginx
```

### 6️⃣ Testar redirects

**Teste 1: www → sem www**
```bash
curl -I https://www.mytube.social
```
**Esperado:** `HTTP/2 301` com `location: https://mytube.social/`

**Teste 2: Site funciona sem www**
```bash
curl -I https://mytube.social
```
**Esperado:** `HTTP/2 200`

### 7️⃣ Testar no navegador

1. Abrir: `https://www.mytube.social` 
   - Deve redirecionar para `https://mytube.social`
   - URL na barra deve mudar

2. Fazer login em `https://mytube.social`

3. Copiar link de vídeo e enviar no WhatsApp

4. Clicar no link vindo do WhatsApp

5. **✅ DEVE MANTER A SESSÃO (não redirecionar para login)**

## 🔄 Se algo der errado, reverter:
```bash
sudo cp /etc/nginx/sites-available/mytube.social.backup /etc/nginx/sites-available/mytube.social
sudo systemctl reload nginx
```

## 🎯 Resultado Final

Depois desta configuração:
- ✅ `http://mytube.social` → redireciona para `https://mytube.social`
- ✅ `http://www.mytube.social` → redireciona para `https://mytube.social`
- ✅ `https://www.mytube.social` → redireciona para `https://mytube.social`
- ✅ `https://mytube.social` → site principal (único domínio ativo)
- ✅ Cookie PHP configurado para `.mytube.social` (funciona em ambos)
- ✅ Sessão mantida ao clicar em links externos
