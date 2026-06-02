# CHECKLIST DE SEGURANÇA — MyTube

**Última atualização:** 02 de Junho, 2026
**Estado:** Auditoria completa realizada — ver [SECURITY_AUDIT.md](./SECURITY_AUDIT.md) para detalhe total

---

## ESTADO RESUMIDO

| Severidade | Total | Resolvido | Pendente |
|-----------|-------|-----------|---------|
| Crítico   | 16    | 13        | 3       |
| Alto      | 17    | 7         | 10      |
| Médio     | 15    | 1         | 14      |
| Baixo     | 10    | 0         | 10      |
| **Total** | **58**| **21**    | **37**  |

---

## AÇÕES IMEDIATAS (Esta semana)

- [ ] **Eliminar do repositório:** `test_csrf.php`, `test_rate_limit.php`, `test_upload_validation.php`, `check_session_browser.php`, `debug_csrf.php`, `debug_csrf_production.php`
- [ ] **Chat-server:** Fazer `CHAT_JWT_SECRET` obrigatório — falhar com `process.exit(1)` se não definido
- [ ] **Upload:** Remover `application/octet-stream` da validação de MIME de vídeos
- [ ] **Amizades:** Adicionar rate limiting em `api/send_friend_request.php`
- [ ] **Streaming:** Decodificar URL antes de verificar padrões de path traversal em `api/stream_video.php`

---

## AUTENTICAÇÃO E SESSÕES

### Login

- [x] Proteção contra timing attacks (hash dummy quando utilizador não existe)
- [x] `password_verify()` sempre executado
- [x] Mensagens de erro genéricas (não revela se utilizador existe)
- [x] `session_regenerate_id(true)` após login bem-sucedido
- [x] Prepared statements (proteção SQL injection)
- [x] Rate limiting: 5 tentativas/utilizador + 15 tentativas/IP em 15 minutos
- [ ] CAPTCHA após N tentativas falhadas
- [ ] Autenticação de dois fatores (2FA)

### Registo

- [x] Validação de email com `filter_var()`
- [x] Username: 3–12 caracteres, apenas alphanumeric + `- _`
- [ ] **Senha: mínimo 12 caracteres + maiúscula + número** (atualmente apenas 6 chars)
- [x] `password_hash(PASSWORD_DEFAULT)` (bcrypt)
- [x] Proteção contra email duplicado (constraint UNIQUE)
- [x] Proteção contra username duplicado
- [x] Verificação de email por código de 6 dígitos

### Reset de Senha

- [x] Usa `user_id` (não email) para atualização
- [x] Token apenas em `$_SESSION` (não retornado ao cliente)
- [x] Códigos expiram em 15 minutos
- [x] Rate limiting: 10 tentativas/IP + 5 tentativas/email em 15 minutos
- [x] Códigos marcados como `used` após utilização
- [ ] Prevenir enumeração de emails via timing (equalizar tempo de resposta)

### Sessões

- [x] `HttpOnly`, `SameSite=Lax`, `Secure` (produção) nos cookies
- [x] `session.use_only_cookies = 1`
- [x] Sessão expira em 2 horas (via `SESSION_LIFETIME` no `.env`)
- [x] `$_SESSION = []` antes de popular após login
- [ ] `session_regenerate_id()` após mudança de senha (`api/change_password.php`)
- [ ] Gestão de sessões ativas em múltiplos dispositivos

---

## PROTEÇÃO CONTRA INJEÇÃO

### SQL Injection

- [x] 100% Prepared Statements (PDO com `?` ou named parameters)
- [x] Nenhuma concatenação de SQL com dados do utilizador
- [x] Parâmetros sempre bindados

### XSS (Cross-Site Scripting)

- [x] `htmlspecialchars(ENT_QUOTES, 'UTF-8')` em output PHP
- [x] `escapeHtml()` em output JavaScript
- [x] CSP header implementado
- [ ] **CSP com `unsafe-inline` e `unsafe-eval` — migrar para nonces** (atual CSP é ineficaz)
- [ ] Texto de comentários sanitizado no servidor (atualmente apenas no JS)
- [ ] Notificações — campo `message` sanitizado no servidor

### CSRF

- [x] Tokens de 64 caracteres via `random_bytes(32)`
- [x] Verificação com `hash_equals()` (timing-safe)
- [x] CSRF em todos os 47+ endpoints POST
- [x] Interceptação global em `fetch()` e `XMLHttpRequest` via `csrf.js`
- [x] Meta tag `<meta name="csrf-token">` em todas as páginas

---

## UPLOADS E FICHEIROS

### Validação de Uploads

- [x] MIME type real via `finfo_file()` (não apenas extensão)
- [x] `getimagesize()` para validação adicional de imagens
- [x] Lista whitelist de tipos permitidos
- [ ] **Remover `application/octet-stream` do mapeamento de vídeos**
- [x] Limite de tamanho verificado no servidor
- [x] Nomes de ficheiro sanitizados (remove caracteres perigosos)
- [ ] **Nomes de ficheiro aleatórios** (`bin2hex(random_bytes(16))`) — atualmente previsíveis

### Segurança de Imagens

- [x] Remoção de EXIF/GPS via `includes/image_sanitizer.php`
- [x] Suporte para JPEG, PNG, GIF, WebP
- [x] Log de uploads com GPS (para revisão)
- [ ] Avatares antigos eliminados após atualização

### Path Traversal

- [x] `realpath()` para resolver caminhos absolutos
- [x] Validação de prefixo de caminho (`strpos($path, $uploads_dir) === 0`)
- [x] Logs de tentativas de path traversal
- [ ] **Decodificação URL antes de verificar padrões** (`..%2F`, `..%5C`, null bytes)

### Diretórios

- [ ] Permissões `0750` em vez de `0755` nos diretórios de upload

---

## PROTEÇÃO DE API E RATE LIMITING

### Rate Limiting

- [x] Sistema via tabela MySQL `rate_limits`
- [x] Login: 5 tentativas/utilizador + 15 tentativas/IP em 15 minutos
- [x] Reset de senha: 10 tentativas/IP + 5 tentativas/email em 15 minutos
- [ ] **Comentários: migrar de SESSION para DB** (atualmente contornável)
- [ ] **Pedidos de amizade: sem limite** — adicionar rate limiting
- [ ] Mensagens no chat: sem limite por utilizador
- [x] Deteção de IP real (Cloudflare, proxies)

### SSRF

- [x] `includes/ssrf_protection.php` com whitelist de domínios
- [x] Bloqueio de IPs privados/reservados (IPv4 e IPv6)
- [x] `CURLOPT_FOLLOWLOCATION => false`
- [x] Whitelist: apenas `dzcdn.net` e `deezer.com`
- [ ] **Domínios não-resolvíveis aceites se na whitelist** — DNS rebinding parcial

### Chat / Socket.IO

- [x] Autenticação JWT no handshake
- [x] Verificação de assinatura HMAC-SHA256
- [x] Expiração de token validada
- [x] `socket.userId` em vez de `data.userId` (não confia no cliente)
- [ ] **`CHAT_JWT_SECRET` sem fallback seguro** — deve falhar se não configurado
- [ ] Rate limiting de mensagens por utilizador

---

## HEADERS E CONFIGURAÇÃO HTTP

### Headers de Segurança

- [x] `Strict-Transport-Security` (HSTS): max-age=1 ano + includeSubDomains
- [x] `X-Frame-Options: SAMEORIGIN`
- [x] `X-Content-Type-Options: nosniff`
- [x] `Referrer-Policy: strict-origin-when-cross-origin`
- [x] `Permissions-Policy: geolocation=(), microphone=(), camera=()`
- [x] `Content-Security-Policy` configurado
- [ ] **CSP sem `unsafe-inline` e `unsafe-eval`** — migrar para nonces

### HTTPS e Certificados

- [x] Redirect automático HTTP → HTTPS em produção
- [x] Deteção de Cloudflare proxy para SSL

---

## CONFIGURAÇÃO E SEGREDOS

### Ficheiros de Configuração

- [x] Credenciais em `.env` (não no código)
- [x] `.env` em `.gitignore`
- [x] Permissões `640` no `.env` (`admin:www-data`)
- [x] `includes/env_loader.php` para carregar variáveis
- [ ] **Ficheiros debug no repositório** — eliminar `test_*.php`, `check_*.php`, `debug_*.php`
- [ ] Ficheiros `*.example.php` protegidos por Nginx

### Segredos

- [x] Credenciais R2 em `.env` (antigas revogadas)
- [x] Credenciais SMTP em `.env`
- [x] CRON_SECRET em `.env`
- [ ] **CHAT_JWT_SECRET — verificar configuração em produção**

---

## PROCESSAMENTO DE VÍDEO

- [x] Validação da existência do binário FFmpeg antes de usar
- [x] `escapeshellarg()` em todos os argumentos de shell
- [x] Validação de MIME type do vídeo
- [ ] **Timeout em processos FFmpeg** — vulnerável a DoS via vídeo malformado
- [ ] **Limpeza de ficheiros temporários** em caso de crash

---

## LOGS E MONITORIZAÇÃO

- [x] Tentativas de login falhadas logadas
- [x] Tentativas de path traversal logadas
- [x] Rate limit exceeded logado
- [ ] **Logs com dados sensíveis** — remover sessão/tokens dos logs
- [ ] Logging centralizado e estruturado
- [ ] Alertas para padrões de ataque (múltiplas falhas do mesmo IP)

---

## PRIVACIDADE E COMPLIANCE (RGPD/LGPD)

- [x] Email único por conta
- [x] Senhas nunca armazenadas em texto plano
- [x] Remoção de EXIF/GPS das imagens
- [ ] Mecanismo de export de dados pessoais
- [ ] Mecanismo de eliminação de conta (com limpeza de dados)
- [ ] Banner de consentimento de cookies
- [ ] Política de retenção de dados (mensagens, logs, views)

---

## REFERÊNCIAS

- Auditoria completa: [SECURITY_AUDIT.md](./SECURITY_AUDIT.md)
- Lista de vulnerabilidades: [SECURITY_VULNERABILITIES.md](./SECURITY_VULNERABILITIES.md)
- Ações de rotação de credenciais: [SECURITY_ACTIONS.md](./SECURITY_ACTIONS.md)
- Relatório executivo: [PROJECT_AUDIT_REPORT.md](./PROJECT_AUDIT_REPORT.md)
