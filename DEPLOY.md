# MyTube — Guia de Deployment para Produção

## Ficheiros criados automaticamente

| Ficheiro | O que faz |
|---|---|
| `chat-server/ecosystem.config.js` | PM2: reinício automático, logs, gestão de memória |
| `chat-server/.env.production.example` | Template do `.env` de produção do chat |
| `logs/` + `chat-server/logs/` | Pastas de logs (com `.gitkeep`) |
| `.htaccess` (atualizado) | Bloqueia acesso web a `includes/`, `logs/`, `*.env`, `*.log` |
| `.gitignore` (atualizado) | Protege `.env`, logs e ficheiros de produção do git |

---

## 🚨 URGENTE — Credenciais reais expostas

> Se fizeres push para GitHub **antes de revogar**, ficam comprometidas para sempre
> (o git guarda o histórico mesmo depois de apagar o ficheiro).

| Ficheiro | O que está exposto | Ação |
|---|---|---|
| `includes/r2_config.php` | Access Key + Secret Key do Cloudflare R2 | Revogar em dash.cloudflare.com → R2 → Manage API Tokens |
| `includes/mail_config.php` | Gmail App Password | Revogar em myaccount.google.com/apppasswords |
| `chat-server/.env` | SESSION_SECRET fraco e público | Substituir por string aleatória |

---

## O que tens de configurar manualmente

### 1. `includes/config.php`

```bash
cp includes/config.example.php includes/config.php
```

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube_db');
define('DB_USER', 'utilizador_bd');    // NÃO usar 'root' em produção!
define('DB_PASS', 'password_forte');

define('SITE_URL', 'https://www.teu-dominio.com'); // COM https://, SEM barra no fim
```

> **SITE_URL é crítico** — usado em emails, redirects e cookies de sessão.

---

### 2. `includes/r2_config.php`

Revogar chaves antigas → criar novas no painel Cloudflare → preencher:

```bash
cp includes/r2_config.example.php includes/r2_config.php
```

```php
define('R2_ACCESS_KEY_ID',     'NOVA_ACCESS_KEY');
define('R2_SECRET_ACCESS_KEY', 'NOVA_SECRET_KEY');
define('R2_ACCOUNT_ID',        'SEU_ACCOUNT_ID');       // não muda
define('R2_BUCKET_NAME',       'mytube-videos');        // não muda
define('R2_PUBLIC_URL',        'https://pub-XXXXX.r2.dev'); // não muda
define('R2_ENABLED',            true);
```

---

### 3. `includes/mail_config.php`

Revogar App Password → criar nova em myaccount.google.com/apppasswords → preencher:

```bash
cp includes/mail_config.example.php includes/mail_config.php
```

```php
define('MAIL_USERNAME',     'teu-email@gmail.com');
define('MAIL_PASSWORD',     'NOVA_PASSWORD_16_CHARS');
define('MAIL_FROM_ADDRESS', 'teu-email@gmail.com');
define('MAIL_DEBUG',        0); // SEMPRE 0 em produção
```

---

### 4. `chat-server/.env`

```bash
cd chat-server
cp .env.production.example .env
```

Preencher tudo:
```env
NODE_ENV=production
PORT=3001
DB_USER=utilizador_bd_producao
DB_PASSWORD=password_muito_segura
DB_NAME=mytube_db
CORS_ORIGIN=https://www.teu-dominio.com
SESSION_SECRET=<gerar com comando abaixo>
LOG_LEVEL=warn
```

Gerar SESSION_SECRET seguro:
```bash
node -e "console.log(require('crypto').randomBytes(64).toString('hex'))"
```

---

### 5. `config_advanced.php` (recomendado)

```bash
cp config_advanced.example.php config_advanced.php
```

```php
define('DEBUG_MODE', false);   // OBRIGATÓRIO false em produção
define('LOG_ERRORS', true);
define('LOG_FILE_PATH', __DIR__ . '/logs/mytube.log');
define('JWT_SECRET_KEY', 'outra-string-aleatoria-unica');
```

---

### 6. HTTPS — Ativar no `.htaccess`

Descomentar estas 2 linhas (já existem, estão comentadas com `#`):

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

> Só ativar depois de ter SSL instalado no hosting (Let's Encrypt gratuito em todos os cPanel/hPanel).

---

### 7. PM2 — Chat server em produção (via SSH)

```bash
# Uma só vez — instalar PM2 globalmente
npm install -g pm2

# Na pasta do chat
cd /caminho/para/mytube/chat-server
npm install --production

# Iniciar em modo produção
pm2 start ecosystem.config.js --env production

# Verificar
pm2 list

# Auto-iniciar no boot do servidor
pm2 startup
pm2 save
```

Comandos do dia a dia:
```bash
pm2 logs mytube-chat           # logs em tempo real
pm2 logs mytube-chat --lines 100
pm2 restart mytube-chat        # após deploy de updates
pm2 monit                      # dashboard CPU/RAM
```

---

## Checklist antes de lançar

```
[ ] Revogar chaves R2 antigas → criar novas
[ ] Revogar Gmail App Password → criar nova
[ ] includes/config.php     → SITE_URL correto, utilizador BD sem 'root'
[ ] includes/r2_config.php  → novas chaves Cloudflare
[ ] includes/mail_config.php → nova App Password Gmail
[ ] chat-server/.env        → SESSION_SECRET forte e aleatório
[ ] config_advanced.php     → DEBUG_MODE=false
[ ] SSL instalado no hosting
[ ] .htaccess → descomentar redirecionamento HTTP→HTTPS
[ ] PM2 instalado e chat a correr (pm2 list mostra "online")
[ ] pm2 startup && pm2 save executados
[ ] logs/ com permissão de escrita (chmod 755 logs/)
[ ] Testar: registo, login, upload de vídeo, chat em tempo real
[ ] Testar no telemóvel (PWA / instalação)
```

---

## Resumo: o que vai e o que não vai para git

```
mytube/
├── includes/
│   ├── config.php              ← ❌ NÃO (credenciais reais) — no .gitignore
│   ├── config.example.php      ← ✅ SIM (template)
│   ├── r2_config.php           ← ❌ NÃO — no .gitignore
│   ├── r2_config.example.php   ← ✅ SIM
│   ├── mail_config.php         ← ❌ NÃO — no .gitignore
│   └── mail_config.example.php ← ✅ SIM
├── chat-server/
│   ├── .env                    ← ❌ NÃO — no .gitignore
│   ├── .env.example            ← ✅ SIM
│   ├── .env.production.example ← ✅ SIM
│   ├── ecosystem.config.js     ← ✅ SIM (sem credenciais)
│   └── logs/                   ← ❌ NÃO (só o .gitkeep vai)
├── logs/                       ← ❌ NÃO (só o .gitkeep vai)
└── DEPLOY.md                   ← ✅ SIM (este ficheiro)
```
