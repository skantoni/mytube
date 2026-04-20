# 🔐 VULNERABILIDADES DE SEGURANÇA - MyTube

**Data da Auditoria:** 18 de Abril, 2026  
**Status:** Em correção

---

## 📊 RESUMO

| Severidade | Total | Resolvidas | Pendentes |
|------------|-------|------------|-----------|
| CRÍTICO    | 14    | 14         | 0         |
| ALTO       | 13    | 5          | 8         |
| MÉDIO      | 8     | 1          | 7         |
| BAIXO      | 7     | 0          | 7         |
| **TOTAL**  | **42**| **20**     | **22**    |

---

## 🔴 VULNERABILIDADES CRÍTICAS (14)

### ✅ 1. Credenciais expostas no código-fonte
**Status:** ✅ **RESOLVIDO**  
**Arquivos:** `includes/config.php`, `includes/r2_config.php`, `includes/mail_config.php`  
**Problema:** Senhas de banco, chaves R2 e credenciais SMTP hardcoded  
**Solução:** Sistema de variáveis de ambiente implementado (.env)  
**Data:** 18/04/2026

### ✅ 2. Endpoints de debug públicos  
**Status:** ✅ **RESOLVIDO**  
**Arquivos:** `check_session_browser.php`, `check_users.php`, `test_*.php`  
**Problema:** Expõem session_id, user_id e listam todos usuários sem autenticação  
**Solução:** Arquivos deletados + proteção no Nginx contra padrões `check_*` e `test_*`  
**Data:** 18/04/2026

### ✅ 3. Ausência TOTAL de proteção CSRF
**Status:** ✅ **RESOLVIDO**  
**Arquivos:** Todos 47+ endpoints POST  
**Problema:** Nenhum endpoint validava token CSRF  
**Solução Implementada:**
- Sistema completo em `includes/csrf_helpers.php`:
  - `csrf_token()` - gera/retorna token de 64 caracteres
  - `csrf_field()` - campo hidden HTML
  - `csrf_verify()` - valida de POST/headers com hash_equals()
  - `csrf_verify_or_die()` - valida ou retorna 403
- Proteção adicionada em:
  - Formulários HTML: login.php, profile.php, upload.php, settings.php
  - 26 endpoints API validando CSRF
  - JavaScript global (csrf.js) intercepta fetch() e XMLHttpRequest
  - Meta tag `<meta name="csrf-token">` em todas páginas
**Data:** 18/04/2026

### ✅ 4. Upload de arquivos sem validação de conteúdo
**Status:** ✅ **RESOLVIDO**  
**Arquivos:** `profile.php`, `upload.php`, `includes/chat_upload_config.php`  
**Problema:** Só valida extensão, não conteúdo real (permite PHP renomeado como JPG)  
**Solução Implementada:**
- Criado `includes/upload_validation.php` com funções:
  - `validate_image_upload()` - Usa finfo_file() + getimagesize()
  - `validate_video_upload()` - Verifica MIME type real
  - `sanitize_filename()` - Remove caracteres perigosos
- Validação MIME integrada em:
  - profile.php (avatar)
  - upload.php (vídeos)
  - chat_upload_config.php (imagens/vídeos do chat)
- Previne: PHP executável disfarçado, XXE, XSS em SVG
**Data:** 19/04/2026

### ✅ 5. SVG e HTML permitidos no upload do chat
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `includes/chat_upload_config.php`  
**Problema:** Permitia `.svg`, `.html`, `.js`, `.xml`, `.json` - XSS/Code execution  
**Solução:** Removidos tipos perigosos da lista $ALLOWED_FILE_TYPES:
- ❌ Removidos: svg, html, css, js, xml, json, py, java, c, cpp, sql, ico
- ✅ Mantidos: pdf, doc, docx, xls, xlsx, txt, zip, ppt (documentos seguros)
- Adicionada validação MIME type para imagens e vídeos
**Data:** 19/04/2026

### ✅ 6. Reset de senha atualiza TODAS contas com mesmo email
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `api/reset_password.php`, `database/mytube_structure_only.sql`  
**Problema:** Se múltiplas contas tivessem mesmo email, UPDATE afetaria todas  
**Solução:** Já implementado:
- Tabela `users` tem `UNIQUE KEY email` - previne duplicatas
- reset_password.php usa `WHERE id = ?` (não WHERE email = ?)
- Reset vinculado a user_id específico via sessão
**Verificado:** 19/04/2026

### ✅ 7. Token de reset exposto na resposta da API
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `api/verify_reset_code.php`, `api/reset_password.php`, `assets/js/auth.js`  
**Problema:** Reset token retornado no JSON para o cliente
- Atacante poderia interceptar token (MITM, XSS, logs)
- Token podia vazar em histórico do navegador
- Validação redundante (POST + sessão)
**Solução Implementada:**
- Token NÃO é mais retornado no JSON (apenas em `$_SESSION`)
- reset_password.php valida apenas `$_SESSION['reset_token']` (não aceita token via POST)
- Frontend (auth.js) não envia nem armazena token
- Fluxo 100% baseado em sessão servidor-side
- Previne: interceptação, XSS, session hijacking, logs
**Data:** 20/04/2026

### ✅ 8. SSRF no download de música
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `includes/video_processing.php`, `includes/ssrf_protection.php`  
**Problema:** Atacante poderia forçar servidor a acessar:
- Localhost (127.0.0.1, ::1)
- Rede interna (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
- Portas arbitrárias (22-SSH, 3306-MySQL, etc)
- Redirects maliciosos via `CURLOPT_FOLLOWLOCATION => true`
- Subdomínios fake como `evil.dzcdn.net.attacker.com`
**Solução Implementada:**
- Criado `includes/ssrf_protection.php` com:
  - `validate_url_ssrf()` - Valida domínio exato (não suffix), HTTPS only, porta 443
  - `is_private_ip()` - Bloqueia todos IPs privados/reservados (IPv4 e IPv6)
  - `ssrf_safe_download()` - Download com `CURLOPT_FOLLOWLOCATION => false`
- video_download_music() reescrito para usar proteção SSRF
- Testa IP antes de conectar (previne DNS rebinding)
- Whitelist: apenas dzcdn.net e deezer.com
**Data:** 19/04/2026

### ✅ 9. Path Traversal no streaming de vídeo
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `api/stream_video.php`  
**Problema:** Atacante poderia acessar arquivos arbitrários do sistema:
- video_path no banco: `../../../etc/passwd`
- Servidor leria: `/var/www/mytube.social/etc/passwd`
- Exposição de: senhas, configurações, código-fonte, chaves privadas
**Solução Implementada:**
- Usado `realpath()` para resolver caminho absoluto (remove ../, symlinks)
- Validação: `strpos($file_path, $uploads_dir) === 0` (caminho DEVE começar com uploads/videos/)
- Bloqueia qualquer arquivo fora do diretório permitido
- Validação MIME type adicional (apenas video/*)
- Logs de tentativas de path traversal
**Testes:**
- ✅ Vídeo normal: funciona
- ✅ Path traversal `../../etc/passwd`: bloqueado (403)
- ✅ Symlink fora de uploads/: bloqueado
**Data:** 19/04/2026

### ✅ 10. Exposed AWS/Cloudflare R2 Credentials (antigas)
**Status:** ✅ **RESOLVIDO**  
**Problema:** Credenciais antigas expostas no histórico Git  
**Solução:** Credenciais antigas revogadas, novas geradas e funcionando  
**Data:** 18/04/2026

### ❌ 11. XSS em comentários e bio (HTML não escapado)
**Status:** ❌ **PENDENTE**  
**Arquivo:** Vários (comentários, bio, descrições)  
**Problema:** Conteúdo de usuário exibido sem htmlspecialchars()  
**Solução:** Sanitizar TODAS saídas com htmlspecialchars() ou strip_tags()

### ❌ 12. SQL Injection em pesquisa
**Status:** ❌ **PENDENTE**
✅ 13. Database Credentials in Plaintext
**Status:** ✅ **RESOLVIDO**  
**Solução:** Migrado para variáveis de ambiente (.env) em produção  
**Data:** 18/04/2026

### ✅ 14. CRON Secret Hardcoded
**Status:** ✅ **RESOLVIDO**  
**Solução:** Migrado para variáveis de ambiente (.env) em produção  
**Data:** 18/04/2026 
**Ação Pendente:** Confirmar em produção

---

## 🟠 VULNERABILIDADES ALTAS (13)

### ✅ 1. Sem proteção brute force no login
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `login.php`, `includes/rate_limit.php`  
**Problema:** Tentativas ilimitadas sem rate limiting (atacante pode testar milhões de senhas)
**Solução Implementada:**
- Criado `includes/rate_limit.php` com sistema completo de rate limiting:
  - `rate_limit_check()` - Verifica se IP/usuário está bloqueado
  - `rate_limit_record()` - Registra tentativas (limpa em sucesso)
  - `rate_limit_get_client_ip()` - Detecta IP real (Cloudflare, proxies)
  - `rate_limit_format_time_remaining()` - Formata tempo restante
- Tabela `rate_limits` criada automaticamente
- Limites implementados em login.php:
  - 5 tentativas por IP em 15 minutos
  - 3 tentativas por usuário em 15 minutos
  - Mensagens mostram tentativas restantes
  - Bloqueio temporário com contagem regressiva
- Previne: Brute force, credential stuffing, password spraying
**Data:** 20/04/2026

### ✅ 2. Sem proteção brute force no código de reset
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `api/verify_reset_code.php`  
**Problema:** Código 6 dígitos sem limite de tentativas (1 milhão de combinações)
**Solução Implementada:**
- Rate limiting em verify_reset_code.php:
  - 10 tentativas por IP em 15 minutos
  - 5 tentativas por email em 15 minutos
  - Limpa rate limit em código válido
- Com 5 tentativas, chance de brute force cai de 0.001% para 0.0005%
- Atacante precisaria de ~200.000 IPs diferentes
**Data:** 20/04/2026

### ✅ 3. Cookie de sessão sem flag `Secure`
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `includes/config.php`  
**Problema:** Falta `ini_set('session.cookie_secure', 1);`
**Solução Implementada:**
- Adicionado `ini_set('session.cookie_secure', 1)` em produção
- Controlado por variável `APP_ENV` no .env
- Em desenvolvimento: cookie_secure = 0 (funciona HTTP)
- Em produção: cookie_secure = 1 (só HTTPS)
- Previne: Session hijacking via man-in-the-middle
**Data:** 20/04/2026

### ✅ 4. Sem HTTPS forçado
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `includes/config.php`  
**Problema:** Credenciais trafegam em texto plano
**Solução Implementada:**
- Redirect 301 HTTP → HTTPS em produção
- Verifica `$_SERVER['HTTPS']` antes de processar request
- Controlado por `APP_ENV=production` no .env
- Em desenvolvimento: permite HTTP (localhost)
- Em produção: força HTTPS automático
- Previne: Man-in-the-middle, credential theft
**Data:** 20/04/2026

### ❌ 5. Política de senha fraca (6 caracteres)
**Status:** ❌ **PENDENTE**  
**Arquivo:** `login.php` linha 97  
**Problema:** Mínimo 6 chars é muito fraco

### ❌ 6. Admin verificado por username (=== 'Admin')
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/boost_metrics.php`, `api/calculate_best_mytuber.php`  
**Problema:** Deve usar role-based access control

### ✅ 7. Sem headers de segurança HTTP
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `includes/config.php`  
**Problema:** Faltam X-Frame-Options, CSP, HSTS
**Solução Implementada:**
- **HSTS** (Strict-Transport-Security): max-age=1ano, includeSubDomains, preload (produção)
- **X-Frame-Options**: SAMEORIGIN (previne clickjacking)
- **X-Content-Type-Options**: nosniff (previne MIME sniffing)
- **X-XSS-Protection**: 1; mode=block (legacy browsers)
- **Referrer-Policy**: strict-origin-when-cross-origin
- **Permissions-Policy**: desabilita geolocation, microphone, camera
- **Content-Security-Policy**: Configuração restritiva em produção, permissiva em dev
- Todos headers aplicados globalmente via includes/config.php
**Data:** 20/04/2026

### ❌ 8. Nomes de arquivo previsíveis
**Status:** ❌ **PENDENTE**  
**Arquivo:** `profile.php` linha 66  
**Problema:** `user_{id}_{timestamp}.jpg` previsível

### ❌ 8. Imagens não sanitizadas (EXIF com GPS)
**Status:** ❌ **PENDENTE**  
**Arquivo:** `profile.php` linha 70  
**Problema:** EXIF não removido

### ❌ 9. Avatares antigos não deletados
**Status:** ❌ **PENDENTE**  
**Arquivo:** `profile.php` linha 75-127  
**Problema:** Acúmulo de arquivos + privacidade

### ❌ 10. Rate limiting baseado em sessão
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/add_comment.php` linha 52-58  
**Problema:** Contornável abrindo nova aba/incognito

### ❌ 11. Race conditions em contadores
**Status:** ❌ **PENDENTE**  
**Arquivos:** `api/toggle_like.php`, `api/toggle_follow.php`  
**Problema:** Likes/follows sem transação

### ❌ 12. Logs de debug expondo dados sensíveis
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/toggle_follow.php`  
**Problema:** Loga dados completos de sessão

### ❌ 13. Diretórios com permissão 0755
**Status:** ❌ **PENDENTE**  
**Arquivo:** `upload.php`  
**Problema:** Muito aberto

---

## ✅ 1. Sessão válida por 30 dias
**Status:** ✅ **RESOLVIDO**  
**Arquivo:** `includes/config.php`  
**Solução:** Reduzido para 2 horas via SESSION_LIFETIME no .env  
**Data:** 18/04/2026eduzido para 2 horas via .env)  
**Arquivo:** `includes/config.php`  
**Ação:** Confirmar em produção

### ❌ 2. `session_regenerate_id()` não chamado após troca de senha
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/change_password.php`

### ❌ 3. Notificações com possível HTML injection
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/get_notifications.php` linha 67

### ❌ 4. Sem constraint UNIQUE no email
**Status:** ❌ **PENDENTE**  
**Database:** `users` table

### ❌ 5. Sem validação de tamanho máximo em inputs de busca
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/search.php`

### ❌ 6. Enumeração de emails por timing no reset
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/send_reset_code.php`

### ❌ 7. Sem headers de segurança HTTP
**Status:** ❌ **PENDENTE**  
**Problema:** Faltam X-Frame-Options, CSP, HSTS

### ❌ 8. Mensagens de erro verbosas em produção
**Status:** ❌ **PENDENTE**  
**Problema:** Expõe informações internas

---

## 🔵 VULNERABILIDADES BAIXAS (7)

### ❌ 1. Missing HTTP Security Headers
**Status:** ❌ **PENDENTE**

### ❌ 2. Verbose Error Messages
**Status:** ❌ **PENDENTE**

### ❌ 3. No API Versioning
**Status:** ❌ **PENDENTE**

### ❌ 4. Unoptimized N+1 Queries
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/get_comments.php`

### ❌ 5. Weak Cache TTL
**Status:** ❌ **PENDENTE**

### ❌ 6. No Request Signing
**Status:** ❌ **PENDENTE**

### ❌ 7. Hardcoded Configurations
**Status:** ❌ **PENDENTE**

---

## 🎯 TOP 5 PRIORIDADES (PRÓXIMAS)

1. ⬜ **Deletar/proteger** `check_session_browser.php` e `check_users.php` na VPS
2. ⬜ **Implementar CSRF tokens** em todos endpoints POST
3. ⬜ **Validar conteúdo real dos uploads** com `finfo_file()`
4. ⬜ **Bloquear SVG/HTML/JS** no chat upload
5. ⬜ **Adicionar rate limiting por IP/user_id** no banco

---

## 📝 NOTAS DE IMPLEMENTAÇÃO

### Sistema de Variáveis de Ambiente ✅
- ✅ Arquivo `.env.example` criado
- ✅ Loader `includes/env_loader.php` implementado
- ✅ `.env` adicionado ao `.gitignore`
- ✅ Arquivos de config migrados para usar `env()`
- ⚠️ Permissões do `.env` ajustadas (640 skeny:www-data)

### R2 Storage ✅
- ✅ Correção de `use_path_style_endpoint` para R2
- ✅ Credenciais antigas revogadas
- ✅ Novas credenciais geradas e configuradas

### Email SMTP ✅
- ✅ Nova senha de app gerada
- ✅ Senha sem espaços configurada no .env
- ✅ Testado e funcionando em produção

### R2 Storage ✅
- ✅ Correção de `use_path_style_endpoint` para R2
- ✅ Credenciais antigas revogadas
- ✅ Novas credenciais geradas e configuradas
- ✅ Upload de vídeos funcionando em produção

### Permissões .env ✅
- ✅ Permissões corretas: 640 (skeny:www-data)
- ✅ PHP-FPM consegue ler o arquivo
- ✅ Site funcionando em produção

---

**Última atualização:** 18/04/2026 19:00
