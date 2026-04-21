# 📱 Push Notifications - Configuração no VPS

## ⚠️ Requisitos OBRIGATÓRIOS

### 1. **HTTPS Obrigatório**
Push Notifications e Service Workers **só funcionam em HTTPS** (exceto localhost).

No teu `.env` do VPS, garante:
```bash
SITE_URL=https://www.mytube.social
APP_ENV=production
```

**❌ ERRADO**: `http://www.mytube.social`  
**✅ CERTO**: `https://www.mytube.social`

---

### 2. **Chat-Server URL**
Adiciona ao `.env` do VPS (na raiz do projeto):

```bash
# === CHAT SERVER ===
CHAT_SERVER_URL=http://127.0.0.1:3001
```

**Importante**:
- Se PHP e Node.js estão na **mesma máquina** → `http://127.0.0.1:3001`
- Se estão em máquinas diferentes → `http://IP_INTERNO:3001` (não usar IP público!)

---

### 3. **VAPID Keys no Chat-Server**
No ficheiro `chat-server/.env` do VPS, garante que tens:

```bash
# Web Push VAPID Keys
VAPID_PUBLIC_KEY=BPKGw6iteKw2W2SFsX9srbu89oJjhho4AiytaZflJdz4-ZkdXMgzBZv4SvX-A3qeLmeZ3ZcD1g9cQmDVgYcNpVM
VAPID_PRIVATE_KEY=m70aPs4DkS2zCq8KuWumcaHA9tUkIOIvffoa6_TTo64
VAPID_EMAIL=mailto:admin@mytube.social
```

---

### 4. **Content Security Policy (CSP)**
Se as notificações não aparecem, verifica os headers CSP no browser:
- Abre DevTools → Console
- Procura erros tipo `Refused to connect to...`

Se encontrares, contacta-me para ajustar a CSP no [config.php](includes/config.php).

---

## 🚀 Passos de Instalação

### 1. Atualizar código
```bash
cd /var/www/mytube  # ou caminho do teu site
git pull
```

### 2. Atualizar `.env` principal
```bash
nano .env
```

Adiciona/corrige:
```bash
SITE_URL=https://www.mytube.social
APP_ENV=production
CHAT_SERVER_URL=http://127.0.0.1:3001
```

### 3. Instalar `web-push` no chat-server
```bash
cd chat-server
npm install web-push
```

### 4. Atualizar `chat-server/.env`
```bash
nano chat-server/.env
```

Confirma que tem as VAPID keys (as mesmas geradas localmente).

### 5. Reiniciar PM2
```bash
pm2 restart mytube-chat
pm2 logs mytube-chat --lines 20
```

**Deve aparecer**: `🔔 Web Push configurado com sucesso`

---

## 🧪 Testar

1. Abre o site **em HTTPS**: `https://www.mytube.social`
2. Aceita as notificações quando aparecer o banner
3. Pede a alguém para te enviar mensagem ou dar like
4. Deves receber notificação mesmo com o site fechado

---

## 🐛 Troubleshooting

### "Web Push não configurado"
**Causa**: VAPID keys não estão no `.env` do chat-server  
**Solução**: Verifica passo 4 acima

### Notificações não chegam
**Causas possíveis**:
1. ❌ SITE_URL está como HTTP (deve ser HTTPS)
2. ❌ CHAT_SERVER_URL aponta para localhost mas não está acessível
3. ❌ CSP bloqueia conexões
4. ❌ Firewall bloqueia porta 3001

**Debug**:
```bash
# 1. Verifica se chat-server responde
curl http://127.0.0.1:3001/health

# 2. Testa envio de push (substitui [ENDPOINT] por real)
curl -X POST http://127.0.0.1:3001/api/send-push \
  -H "Content-Type: application/json" \
  -d '{"subscriptions":[],"notification":{"title":"Test","body":"Test"}}'

# 3. Verifica logs
pm2 logs mytube-chat --lines 100
```

### Permission Denied ao acessar push notifications
**Causa**: Utilizador bloqueou notificações no browser  
**Solução**: 
1. Abre `chrome://settings/content/notifications` (Chrome)
2. Remove `mytube.social` da lista de bloqueados
3. Recarrega o site

---

## 📝 Notas Importantes

- **Porta 3001**: O chat-server não precisa estar exposto publicamente, apenas o PHP precisa aceder
- **Mesma máquina**: `127.0.0.1:3001` funciona se ambos os serviços estão no mesmo servidor
- **HTTPS**: Sem HTTPS, as notificações **não funcionam** (restrição do browser)
- **Service Worker**: Atualiza automaticamente, mas podes forçar com `Ctrl+Shift+R`
