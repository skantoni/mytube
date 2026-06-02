# VULNERABILIDADES DE SEGURANÇA — MyTube

**Data da Auditoria Inicial:** 18 de Abril, 2026
**Data da Auditoria Completa:** 02 de Junho, 2026
**Status:** Em resolução contínua

---

## RESUMO EXECUTIVO

| Severidade | Total | Resolvidas | Pendentes |
|------------|-------|------------|-----------|
| CRÍTICO    | 16    | 13         | 3         |
| ALTO       | 17    | 7          | 10        |
| MÉDIO      | 15    | 1          | 14        |
| BAIXO      | 10    | 0          | 10        |
| **TOTAL**  | **58**| **21**     | **37**    |

> Notas da auditoria completa de 02/06/2026 adicionaram 16 novas vulnerabilidades às 42 originais.
> Algumas vulnerabilidades originais tiveram estado corrigido (ex: SQL Injection em pesquisa era falso positivo).

---

## VULNERABILIDADES CRÍTICAS (16)

### ✅ 1. Credenciais expostas no código-fonte
**Status:** RESOLVIDO (18/04/2026)
**Arquivos:** `includes/config.php`, `includes/r2_config.php`, `includes/mail_config.php`
**Problema:** Senhas de banco, chaves R2 e credenciais SMTP hardcoded
**Solução:** Sistema de variáveis de ambiente (.env) implementado

---

### ✅ 2. Endpoints de debug públicos (original — VPS)
**Status:** RESOLVIDO VIA NGINX (18/04/2026) — Verificar presença em código local
**Arquivos:** `check_session_browser.php`, `check_users.php`, `test_*.php`
**Problema:** Expõem session_id, user_id e listam todos usuários sem autenticação
**Solução:** Proteção via Nginx contra padrões `check_*` e `test_*` na VPS
**ATENÇÃO:** Os ficheiros ainda existem no repositório local. Devem ser eliminados do código.

---

### ❌ 3. Ficheiros de debug presentes no repositório (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivos:** `test_csrf.php`, `test_rate_limit.php`, `test_upload_validation.php`, `check_session_browser.php`, `debug_csrf.php`, `debug_csrf_production.php`
**Problema:** Estes ficheiros estão presentes no repositório e seriam expostos publicamente em qualquer deploy sem a proteção Nginx configurada exatamente.
**Impacto:** Exposição de informações de sessão, listas de utilizadores, estado interno da aplicação.
**Solução:** Eliminar permanentemente do repositório. Adicionar padrões ao `.gitignore`.
```bash
git rm test_csrf.php test_rate_limit.php test_upload_validation.php
git rm check_session_browser.php debug_csrf.php debug_csrf_production.php
```

---

### ✅ 4. Ausência total de proteção CSRF
**Status:** RESOLVIDO (18/04/2026)
**Solução:** `includes/csrf_helpers.php` com `hash_equals()`, meta tags e JS global

---

### ✅ 5. Upload de arquivos sem validação de conteúdo
**Status:** RESOLVIDO (19/04/2026)
**Solução:** `includes/upload_validation.php` com `finfo_file()` + `getimagesize()`

---

### ✅ 6. SVG e HTML permitidos no upload do chat
**Status:** RESOLVIDO (19/04/2026)
**Solução:** Removidos svg, html, js, xml, json de `includes/chat_upload_config.php`

---

### ✅ 7. Reset de senha atualizava todas as contas com mesmo email
**Status:** RESOLVIDO (19/04/2026)
**Solução:** Reset vinculado a `user_id` via sessão; constraint UNIQUE no email

---

### ✅ 8. Token de reset exposto na resposta da API
**Status:** RESOLVIDO (20/04/2026)
**Solução:** Token apenas em `$_SESSION`; frontend não envia nem armazena o token

---

### ✅ 9. SSRF no download de música
**Status:** RESOLVIDO (19/04/2026)
**Solução:** `includes/ssrf_protection.php` com whitelist, bloqueio de IPs privados, sem redirects

---

### ❌ 10. SSRF — DNS Rebinding parcial (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `includes/ssrf_protection.php` (linhas 77–91 aproximadamente)
**Problema:** Quando um domínio está na whitelist mas o DNS não resolve, o código aceita o pedido sem validar o IP de destino:
```php
// ❌ Código problemático:
if ($ip === $host) {
    // Aceita domínio não-resolvível se estiver na whitelist
    return ['valid' => true, 'error' => null, 'ip' => null];
}
```
**Impacto:** Atacante poderia registar domínio com estrutura semelhante ao whitelist, e após aprovação, redirecionar DNS para IP interno.
**Solução:** Sempre exigir resolução DNS bem-sucedida. Nunca aceitar domínio não-resolvível mesmo que esteja na whitelist.

---

### ✅ 11. Path Traversal no streaming de vídeo
**Status:** RESOLVIDO (19/04/2026)
**Solução:** `realpath()` + validação de prefixo de caminho em `api/stream_video.php`

---

### ❌ 12. Path Traversal via URL Encoding (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `api/stream_video.php`
**Problema:** O filtro de padrões suspeitos verifica literalmente `../` e `..\` mas não contempla variantes codificadas:
- `..%2F` (barra codificada)
- `..%5C` (backslash codificado)
- `..%00.mp4` (null byte injection)
**Impacto:** Possível bypass da proteção existente e acesso a ficheiros fora de `uploads/videos/`.
**Mitigação Existente:** `realpath()` mitiga parcialmente, mas é melhor eliminar a entrada antes.
**Solução:** Decodificar URL antes de verificar padrões; usar `basename()` como camada adicional.

---

### ✅ 13. Credenciais AWS/R2 antigas expostas
**Status:** RESOLVIDO (18/04/2026)
**Solução:** Credenciais antigas revogadas; novas geradas e configuradas

---

### ✅ 14. XSS em comentários e bio (HTML não escapado)
**Status:** RESOLVIDO (22/04/2026)
**Solução:** `htmlspecialchars(ENT_QUOTES, 'UTF-8')` em todos os endpoints relevantes

---

### ✅ 15. Credenciais de base de dados em texto plano
**Status:** RESOLVIDO (18/04/2026)
**Solução:** Migrado para variáveis de ambiente (.env)

---

### ✅ 16. CRON Secret hardcoded
**Status:** RESOLVIDO (18/04/2026)
**Solução:** Migrado para `.env`

---

## VULNERABILIDADES ALTAS (17)

### ✅ 1. Sem proteção brute force no login
**Status:** RESOLVIDO (20/04/2026)
**Solução:** `includes/rate_limit.php` — 5 tentativas/utilizador + 15 tentativas/IP em 15 min

---

### ✅ 2. Sem proteção brute force no reset de senha
**Status:** RESOLVIDO (20/04/2026)
**Solução:** 10 tentativas/IP + 5 tentativas/email em 15 min em `api/verify_reset_code.php`

---

### ✅ 3. Cookie de sessão sem flag Secure
**Status:** RESOLVIDO (20/04/2026)
**Solução:** `session.cookie_secure = 1` controlado por `APP_ENV` em `.env`

---

### ✅ 4. Sem HTTPS forçado
**Status:** RESOLVIDO (20/04/2026)
**Solução:** Redirect 301 HTTP→HTTPS em produção via `includes/config.php`

---

### ❌ 5. Política de senha fraca (6 caracteres)
**Status:** PENDENTE
**Arquivo:** `login.php`, linha ~97; `register.php`
**Problema:** Mínimo 6 caracteres permite senhas como `123456`, `abc123`
**Solução:**
```php
// Substituir validação atual por:
if (strlen($password) < 12 ||
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[0-9]/', $password)) {
    $error = "A senha deve ter pelo menos 12 caracteres, 1 maiúscula e 1 número.";
}
```

---

### ✅ 6. Admin verificado por username (=== 'Admin')
**Status:** RESOLVIDO (22/04/2026)
**Solução:** Coluna `role` ENUM + função `isAdminUser()` em `includes/config.php`

---

### ✅ 7. Sem headers de segurança HTTP
**Status:** RESOLVIDO (20/04/2026)
**Solução:** HSTS, X-Frame-Options, CSP, X-Content-Type-Options via `includes/config.php`

---

### ❌ 8. CSP com unsafe-inline e unsafe-eval (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `includes/config.php` (header Content-Security-Policy)
**Problema:** A política CSP atual em produção contém `'unsafe-inline'` e `'unsafe-eval'`:
```
script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net ...
```
Estas diretivas anulam a proteção XSS que o CSP deveria providenciar. Com `unsafe-inline`, qualquer script inline pode executar, tornando o CSP ineficaz contra XSS.
**Impacto:** Alto — CSP não protege efetivamente contra injeção de scripts
**Solução:** Migrar para nonces ou hashes:
```php
$csp_nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: script-src 'self' 'nonce-$csp_nonce' https://cdn.jsdelivr.net");
```

---

### ❌ 9. Nomes de ficheiro previsíveis
**Status:** PENDENTE
**Arquivo:** `profile.php`, linha ~91
**Problema:** Formato `user_{id}_{timestamp}.jpg` é previsível — atacante pode inferir URLs de avatares de outros utilizadores
**Solução:**
```php
// Substituir:
$new_filename = 'user_' . $user_id . '_' . time() . '.' . $file_extension;
// Por:
$new_filename = bin2hex(random_bytes(16)) . '.' . $file_extension;
```

---

### ✅ 10. Imagens não sanitizadas (EXIF com GPS)
**Status:** RESOLVIDO (30/04/2026)
**Solução:** `includes/image_sanitizer.php` — remove todos os metadados EXIF via GD

---

### ❌ 11. Avatares antigos não eliminados
**Status:** PENDENTE
**Arquivo:** `profile.php`
**Problema:** Cada atualização de avatar cria novo ficheiro sem eliminar o anterior; acúmulo de ficheiros + risco de privacidade
**Solução:** Guardar caminho do avatar atual antes de atualizar; eliminar ficheiro anterior após upload bem-sucedido

---

### ❌ 12. Rate limiting baseado em sessão (comentários)
**Status:** PENDENTE
**Arquivo:** `api/add_comment.php`
**Problema:** Rate limit via `$_SESSION` é contornável abrindo nova aba ou modo incógnito
**Solução:** Usar `rate_limit_check()` (já implementado para login) com ação `'add_comment'`

---

### ❌ 13. Logs de debug expondo dados sensíveis
**Status:** PENDENTE
**Arquivo:** `api/toggle_follow.php` e outros
**Problema:** `error_log()` loga dados completos de sessão
**Solução:** Logar apenas IDs e ações; nunca logar tokens, senhas ou dados pessoais

---

### ❌ 14. Permissões de diretório 0755 (uploads)
**Status:** PENDENTE
**Arquivo:** `upload.php`
**Problema:** `mkdir(..., 0755)` — demasiado permissivo para diretórios de upload
**Solução:** Usar `0750` ou `0700`; garantir que o servidor web tem acesso mas não outros processos

---

### ❌ 15. Secret JWT com valor padrão inseguro (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `chat-server/server.js`
**Problema:** O servidor de chat usa um fallback literal se `CHAT_JWT_SECRET` não estiver definido:
```javascript
const secret = process.env.CHAT_JWT_SECRET || 'CHANGE_ME_IN_PRODUCTION';
```
Se o ficheiro `.env` não estiver configurado corretamente, qualquer pessoa pode forjar tokens JWT usando a chave `'CHANGE_ME_IN_PRODUCTION'`.
**Impacto:** Comprometimento total da autenticação do chat — atacante pode personificar qualquer utilizador
**Solução:** Fazer o servidor falhar imediatamente se o secret não estiver definido:
```javascript
const secret = process.env.CHAT_JWT_SECRET;
if (!secret || secret.length < 32) {
    console.error('FATAL: CHAT_JWT_SECRET não configurado ou fraco');
    process.exit(1);
}
```

---

### ❌ 16. MIME type application/octet-stream aceite para vídeos (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `includes/upload_validation.php`
**Problema:** `application/octet-stream` é aceite como MIME válido para vídeos (fallback para "alguns servidores"):
```php
'application/octet-stream' => ['mp4', 'avi', 'mov'] // Fallback para alguns servidores
```
Qualquer ficheiro binário pode retornar `application/octet-stream`, incluindo executáveis PHP.
**Impacto:** Bypass parcial da validação de tipo de ficheiro
**Solução:** Remover `octet-stream` do mapa de MIME. Usar apenas tipos de vídeo específicos.

---

### ❌ 17. Sem rate limiting em pedidos de amizade (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `api/send_friend_request.php`
**Problema:** Utilizador pode enviar pedidos de amizade em massa sem qualquer limitação
**Impacto:** Spam, abuso da plataforma
**Solução:** Adicionar `rate_limit_check($pdo, 'friend_request', ...)` antes de processar

---

## VULNERABILIDADES MÉDIAS (15)

### ✅ 1. Sessão válida por 30 dias
**Status:** RESOLVIDO (18/04/2026)
**Solução:** Reduzido para 2 horas via `SESSION_LIFETIME` no `.env`

---

### ❌ 2. session_regenerate_id() não chamado após mudança de senha
**Status:** PENDENTE
**Arquivo:** `api/change_password.php`
**Solução:** Adicionar `session_regenerate_id(true)` após atualização bem-sucedida da senha

---

### ❌ 3. Notificações com possível HTML injection
**Status:** PENDENTE
**Arquivo:** `api/get_notifications.php`, linha ~67
**Solução:** Aplicar `htmlspecialchars()` ao campo `message` antes de retornar no JSON

---

### ❌ 4. Verificar constraint UNIQUE no email
**Status:** VERIFICAÇÃO PENDENTE
**Arquivo:** Tabela `users`
**Nota:** Documentação afirma resolvido mas `SECURITY_VULNERABILITIES.md` ainda lista como pendente. Verificar:
```sql
SHOW KEYS FROM users WHERE Column_name='email' AND Non_unique=0;
```

---

### ❌ 5. Sem validação de tamanho máximo em inputs de busca
**Status:** PENDENTE
**Arquivo:** `api/search.php`
**Solução:**
```php
$query = substr(trim($_GET['q'] ?? ''), 0, 100);
```

---

### ❌ 6. Enumeração de emails por timing no reset
**Status:** PENDENTE
**Arquivo:** `api/send_reset_code.php`
**Problema:** Resposta diferente quando email não existe (timing distinguível)
**Solução:** Sempre executar as mesmas operações independentemente do email existir

---

### ❌ 7. Mensagens de erro verbosas em produção
**Status:** PENDENTE
**Problema:** Exceções MySQL e stack traces expostos em respostas JSON
**Solução:** Verificar `APP_ENV` antes de incluir detalhes de erro na resposta

---

### ❌ 8. Race condition no rate limiting (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `includes/rate_limit.php`
**Problema:** SELECT + INSERT separados sem transação — janela para inserções concorrentes
**Impacto:** Atacante pode fazer múltiplas tentativas simultâneas e contornar o bloqueio
**Solução:** Usar `INSERT ... ON DUPLICATE KEY UPDATE` ou transação com `SELECT ... FOR UPDATE`

---

### ❌ 9. Sem timeout em processos FFmpeg (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `includes/video_processing.php`
**Problema:** `exec($command)` sem timeout — vídeo malformado pode bloquear o processo indefinidamente
**Impacto:** DoS via upload de vídeo corrompido
**Solução:** Usar `proc_open()` com verificação de timeout; adicionar `-t 60` ao comando FFmpeg

---

### ❌ 10. Atualização de ranking fora de transação (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `api/toggle_video_like.php`, linhas ~109–121
**Problema:** Após `$pdo->commit()`, são feitas queries adicionais para atualizar `ranking_points` do dono do vídeo fora da transação
**Impacto:** Se a query de ranking falhar, o like fica registado mas os pontos não são atualizados
**Solução:** Mover a atualização de `ranking_points` para dentro da transação

---

### ❌ 11. Validação de URL de endpoint push ausente (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `api/push_subscribe.php`
**Problema:** O `endpoint` enviado pelo cliente é aceite sem verificar se é uma URL válida ou se pertence a um serviço push legítimo
**Solução:** Validar com `filter_var($endpoint, FILTER_VALIDATE_URL)` + verificar que o domínio é de um provider push reconhecido (fcm.googleapis.com, etc.)

---

### ❌ 12. Logs EXIF em ficheiro de acesso público (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `includes/image_sanitizer.php`
**Problema:** Coordenadas GPS são logadas via `error_log()` ou em ficheiro, potencialmente acessível
**Solução:** Logar apenas presença de GPS (sem coordenadas), ou garantir que o ficheiro de log tem permissões `0600`

---

### ❌ 13. Texto de comentários sem sanitização server-side (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `api/get_comments.php`
**Problema:** Apesar do comentário anterior afirmar que o XSS foi resolvido, `get_comments.php` tem nota no código indicando que sanitização é feita no JS:
```
// NOTA: Campos de texto são retornados em bruto no JSON.
// A sanitização XSS é feita no JS (escapeHtml / formatMentions)
```
**Impacto:** Se o CSP falhar ou JS for contornado, comentários com HTML são renderizados diretamente
**Solução:** Aplicar `htmlspecialchars()` também no PHP antes de retornar no JSON

---

### ❌ 14. Sem paginação para lista de conversas no chat (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `chat-server/server.js`, função `getUserConversations()`
**Problema:** Todas as conversas são carregadas de uma vez; utilizadores com muitas conversas podem causar queries lentas
**Solução:** Adicionar `LIMIT` + `OFFSET` com cursor pagination

---

### ❌ 15. Ficheiros temporários FFmpeg não limpos em falha (NOVO — 02/06/2026)
**Status:** PENDENTE
**Arquivo:** `includes/video_processing.php`
**Problema:** Se o processo crashar, ficheiros temporários ficam em `/tmp` indefinidamente
**Solução:** Usar `register_shutdown_function()` para limpar ficheiros temporários

---

## VULNERABILIDADES BAIXAS (10)

### ❌ 1. Sem versionamento de API
**Status:** PENDENTE
**Problema:** Todos os endpoints em `/api/` sem prefixo de versão — sem mecanismo de deprecação controlada
**Solução:** Migrar para `/api/v1/` como prefixo base

---

### ❌ 2. Queries N+1 não otimizadas
**Status:** PENDENTE
**Arquivo:** `api/get_comments.php`
**Problema:** Contagem de replies feita em query separada por comentário
**Solução:** SQL com `COUNT()` + `GROUP BY` numa única query

---

### ❌ 3. Cache TTL fraco (5 minutos para dados de utilizador)
**Status:** PENDENTE
**Arquivo:** `includes/config.php`, função `ensureUserData()`
**Problema:** Cache de 5 minutos em array PHP — não persiste entre requests; não invalida em mudança de role
**Solução:** Redis com invalidação por evento

---

### ❌ 4. Sem connection pooling para base de dados
**Status:** PENDENTE
**Arquivo:** `includes/config.php`, classe `LazyPDO`
**Problema:** Nova conexão por request; sem pool
**Solução:** PgBouncer ou configuração de persistent connections no PDO

---

### ❌ 5. Pesquisa com LIKE '%query%' (sem índice full-text)
**Status:** PENDENTE
**Arquivo:** `api/search.php`
**Problema:** `LIKE '%termo%'` faz full table scan; lento em tabelas grandes
**Solução:** `FULLTEXT INDEX` + `MATCH() AGAINST()`

---

### ❌ 6. Cache de 7 dias para todos os vídeos (incluindo privados)
**Status:** PENDENTE
**Arquivo:** `api/stream_video.php`
**Problema:** Todos os vídeos recebem `Cache-Control: max-age=604800` independentemente de visibilidade
**Solução:** Vídeos privados devem ter `Cache-Control: private, no-cache`

---

### ❌ 7. Upload polling agressivo (2 segundos fixos)
**Status:** PENDENTE
**Arquivo:** `assets/js/upload.js`
**Problema:** Polling a cada 2 segundos sem backoff exponencial — 300+ requests em uploads longos
**Solução:** Implementar backoff exponencial ou WebSocket para estado de upload

---

### ❌ 8. Sem assinatura de requests para APIs críticas
**Status:** PENDENTE
**Problema:** APIs como `api/admin_moderate.php` dependem apenas de sessão e CSRF sem HMAC adicional
**Solução:** Para operações admin, considerar assinatura HMAC de requests

---

### ❌ 9. Ficheiros de configuração example expostos
**Status:** PENDENTE
**Arquivos:** `config_advanced.example.php`, `includes/config.example.php`, `includes/r2_config.example.php`
**Problema:** Estes ficheiros listam estrutura de configuração e comentários que revelam arquitetura interna
**Solução:** Mover para `docs/examples/` ou proteger via Nginx

---

### ❌ 10. Cleanup de sessões online não eficiente
**Status:** PENDENTE
**Arquivo:** `chat-server/server.js`, função `cleanupStaleOnlineStatuses()`
**Problema:** Cleanup corre a cada 5 minutos com query sem índice em `user_online_status`
**Solução:** Adicionar índice em `updated_at`; considerar TTL no Redis

---

## PRIORIDADES IMEDIATAS

### Esta semana (Crítico/Alto)
1. Eliminar `test_csrf.php`, `test_rate_limit.php`, `test_upload_validation.php`, `check_session_browser.php`, `debug_csrf.php`, `debug_csrf_production.php` do repositório
2. Configurar `CHAT_JWT_SECRET` obrigatório no servidor de chat (falhar se ausente)
3. Remover `application/octet-stream` da validação de MIME de vídeos
4. Adicionar rate limiting em `api/send_friend_request.php`
5. Implementar `session_regenerate_id()` após mudança de senha

### Próximas sprints (Alto/Médio)
1. Fortalecer política de senhas (mínimo 12 caracteres)
2. Migrar para nomes de ficheiro aleatórios (`bin2hex(random_bytes(16))`)
3. Implementar timeout para processos FFmpeg
4. Mover atualização de ranking para dentro das transações de like
5. Sanitizar `message` em notificações no servidor
6. Corrigir rate limiting de comentários (usar DB em vez de SESSION)
7. Implementar CSP baseado em nonces (remover `unsafe-inline`)

---

**Última atualização:** 02/06/2026 — Auditoria completa
**Responsável:** Auditoria Claude Code
