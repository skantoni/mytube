# PLANO DE REFATORACAO — MyTube

**Data:** 13 de Junho, 2026  
**Branch:** Refactoring  
**Objetivo:** Melhorar a qualidade, seguranca, performance e manutenibilidade do codigo sem quebrar funcionalidades existentes.

---

## FASE 1 — Seguranca (Prioridade Maxima)

### 1.1 CSP com Nonces (remover unsafe-inline/unsafe-eval)

**Ficheiro:** `includes/config.php` (linhas 186-191)

**Problema:** O Content-Security-Policy atual usa `unsafe-inline` e `unsafe-eval`, anulando a protecao contra XSS.

**Plano:**
1. Gerar nonce por request: `$csp_nonce = base64_encode(random_bytes(16))`
2. Passar nonce para a sessao ou variavel global
3. Atualizar header CSP para usar `'nonce-$csp_nonce'` em vez de `'unsafe-inline'`
4. Adicionar `nonce="<?= htmlspecialchars($csp_nonce) ?>"` a TODOS os `<script>` e `<style>` inline
5. Mover inline JS para ficheiros externos onde possivel
6. Remover `unsafe-eval` (verificar se alguma dependencia requer eval)

**Ficheiros afetados:** Todos os `.php` com `<script>` inline — index.php, chat.php, ranking.php, profile.php, perfil.php, settings.php, upload.php, header.php, seo_meta.php

**Estimativa:** 2-3 dias

### 1.2 Sanitizacao Server-Side nos JSON Responses

**Problema:** APIs retornam dados crus em JSON, dependendo do frontend para escapar HTML. Viola defense-in-depth.

**Plano:**
1. Criar helper `json_escape_output(array $data, array $fields): array` em `includes/config.php`
2. Aplicar `htmlspecialchars()` aos campos de texto (title, description, username, comment_text, message) antes do `json_encode`
3. Aplicar nos endpoints principais: `get_feed.php`, `get_comments.php`, `get_notifications.php`, `search.php`

**Estimativa:** 1 dia

### 1.3 Rate Limiting Baseado em DB (substituir session-based)

**Problema:** Rate limiting via `$_SESSION` e bypassed com nova sessao/aba anonima.

**Plano:**
1. Migrar `api/add_comment.php` para usar `rate_limit_check()` do `includes/rate_limit.php` (ja baseado em DB)
2. Adicionar rate limiting a endpoints que faltam: `toggle_like.php`, `toggle_follow.php`, `send_message` (socket)
3. Considerar Redis para rate limiting de alta frequencia (fase futura)

**Estimativa:** 1 dia

---

## FASE 2 — Consistencia de API (1-2 semanas)

### 2.1 Padronizar Response Format

**Problema:** Endpoints retornam estruturas diferentes (`success/error`, `success/message`, codigos HTTP inconsistentes).

**Plano:**
1. Criar classe `ApiResponse` em `app/Http/ApiResponse.php`:
   ```php
   class ApiResponse {
       static function success($data = null, int $code = 200): never
       static function error(string $message, int $code = 400, $details = null): never
   }
   ```
2. Migrar endpoints gradualmente (comecando pelos mais usados)
3. Manter backward compatibility — o JSON output nao muda, so a forma de gerar

### 2.2 Middleware de Autenticacao

**Problema:** Cada endpoint repete `if (!isLoggedIn()) { ... exit; }` e `if (!csrf_verify()) { ... exit; }`.

**Plano:**
1. Criar `includes/api_middleware.php` com:
   - `require_auth()` — verifica sessao, retorna 401
   - `require_csrf()` — verifica token, retorna 403
   - `require_admin()` — verifica role, retorna 403
2. Substituir blocos repetidos nos 76 endpoints por uma linha

### 2.3 Validacao de Input Centralizada

**Problema:** Cada endpoint valida inputs de forma diferente (intval, isset, filter_var, etc.).

**Plano:**
1. Criar `InputValidator` em `app/Validators/InputValidator.php`:
   ```php
   $v = new InputValidator($_POST);
   $v->required('video_id')->int()->min(1);
   $v->optional('comment')->string()->maxLength(2000);
   if (!$v->passes()) return ApiResponse::error($v->firstError(), 422);
   ```
2. Migrar endpoints de alta prioridade primeiro (upload, comments, likes)

---

## FASE 3 — JavaScript (2-3 semanas)

### 3.1 Consolidar escapeHtml()

**Estado atual:** 4 copias em chat-socket.js, notifications.js, ranking.js, comments-new.js + feed-ajax.js inline.

**Ja feito:** Criado `assets/js/utils.js` com `MyTubeUtils.escapeHtml()`.

**Proximo passo:**
1. Substituir `escapeHtml()` em `chat-socket.js` (global) por `MyTubeUtils.escapeHtml()`
2. Os modulos internos (notifications.js IIFE, ranking.js IIFE, comments-new.js class) podem manter a copia local por agora
3. Na migracao para ES modules, importar de um unico modulo

### 3.2 Modularizacao com ES Modules

**Problema:** `tiktok.js` tem 2000+ linhas, `chat-socket.js` tem 3300+ linhas. Dificil de manter.

**Plano:**
1. Instalar esbuild como bundler (rapido, zero-config)
2. Separar em modulos:
   - `tiktok.js` → `modules/player.js`, `modules/feed.js`, `modules/gestures.js`, `modules/shortcuts.js`
   - `chat-socket.js` → `modules/chat/connection.js`, `modules/chat/messages.js`, `modules/chat/ui.js`
3. Bundle para um ficheiro final por pagina
4. Manter compatibilidade com browsers antigos via esbuild target

**Estimativa:** 1-2 semanas

### 3.3 Debouncing e Throttling

**Problema:** Search, scroll handlers, e resize listeners sem debounce.

**Plano:**
1. Usar `MyTubeUtils.debounce()` (ja criado em utils.js)
2. Aplicar a: search input, scroll handlers, resize listeners, like button (double-tap)

---

## FASE 4 — Base de Dados (1 semana)

### 4.1 Indices em Falta

```sql
-- Comments: queries frequentes por video_id e parent_id
CREATE INDEX IF NOT EXISTS idx_comments_video_created 
  ON comments(video_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_comments_parent 
  ON comments(parent_comment_id);

-- Videos: feed ordering
CREATE INDEX IF NOT EXISTS idx_videos_trend 
  ON videos(trend_score DESC, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_videos_user_created 
  ON videos(user_id, created_at DESC);

-- Follows: lookup rapido
CREATE INDEX IF NOT EXISTS idx_follows_pair 
  ON follows(follower_id, following_id);
CREATE INDEX IF NOT EXISTS idx_follows_reverse 
  ON follows(following_id, follower_id);

-- Friend requests: lookup bidirecional
CREATE INDEX IF NOT EXISTS idx_friend_requests_pair 
  ON friend_requests(sender_id, receiver_id, status);

-- Notifications: por user + nao lidas
CREATE INDEX IF NOT EXISTS idx_notifications_user_read 
  ON notifications(user_id, is_read, created_at DESC);

-- Rate limit: cleanup e lookup
CREATE INDEX IF NOT EXISTS idx_rate_limit_action 
  ON rate_limit_attempts(action, identifier, attempted_at);
```

### 4.2 Soft Deletes

**Problema:** Hard deletes podem quebrar foreign keys e perder historico.

**Plano:**
1. Adicionar `deleted_at TIMESTAMP NULL DEFAULT NULL` a: videos, comments, users
2. Adicionar `WHERE deleted_at IS NULL` as queries de listagem
3. Mover logica de delete para "soft delete" (UPDATE SET deleted_at = NOW())

### 4.3 Full-Text Search

**Problema:** Search usa `LIKE '%term%'` — nao usa indices, lento em tabelas grandes.

**Plano:**
1. Adicionar FULLTEXT index em `videos(title, description)`
2. Adicionar FULLTEXT index em `users(username, full_name)`
3. Migrar `api/search.php` para `MATCH() AGAINST()` com fallback para LIKE

---

## FASE 5 — Arquitetura PHP (3-4 semanas)

### 5.1 Completar Migracao MVC

**Estado atual:** Ja existe `app/` com Repositories, Services, Validators. Parcialmente implementado.

**Plano:**
1. Migrar dominios restantes:
   - `VideoRepository` — consolidar queries de videos dispersas em api/*.php
   - `CommentRepository` — consolidar queries de comments
   - `NotificationRepository` — consolidar queries de notifications
2. Criar Services para logica de negocio:
   - `VideoService` — upload, moderacao, streaming
   - `FeedService` — algoritmo de feed (extrair de get_feed.php)
3. Manter endpoints PHP como "controllers finos" que chamam services

### 5.2 PSR-4 Autoloading

**Estado atual:** Alguns ficheiros ja usam `namespace MyTube\*` e autoloader.

**Plano:**
1. Adicionar `composer.json` com autoload PSR-4 se nao existir
2. Migrar `require_once` para autoloading onde possivel
3. Manter backward compatibility com includes existentes

### 5.3 Error Handling

**Problema:** Erros inconsistentes — alguns endpoints retornam mensagens de erro MySQL em producao.

**Plano:**
1. Criar `app/Exceptions/` com excecoes customizadas
2. Verificar `APP_ENV` antes de incluir detalhes de erro em respostas
3. Log detalhado em `error_log()`, resposta generica ao cliente

---

## FASE 6 — Performance (2 semanas)

### 6.1 Cache Layer

**Plano:**
1. **Curto prazo:** File-based cache para rankings, feed trending (ja existe `cache/rankings/`)
2. **Medio prazo:** Redis para session storage, rate limiting, cache de queries
3. **Longo prazo:** Redis pub/sub para invalidacao de cache cross-server

### 6.2 Feed Optimization

**Problema:** `api/get_feed.php` tem 1093 linhas com algoritmo complexo. Guest feed recalcula a cada request.

**Plano:**
1. Cache do guest feed por 5 minutos (file ou Redis)
2. Pre-calcular trend_score via cron em vez de calcular inline
3. Usar cursor-based pagination em vez de offset

### 6.3 Asset Pipeline

**Plano:**
1. Minificar CSS e JS em producao (esbuild)
2. Gerar hashes para cache-busting (ja existe `asset()` helper)
3. Concatenar CSS criticos por pagina

---

## FASE 7 — Testes (ongoing)

### 7.1 PHPUnit

**Plano:**
1. Instalar PHPUnit via Composer
2. Criar testes para Repositories (com DB de teste)
3. Criar testes para Validators (unit tests puros)
4. Criar testes para API endpoints (integration tests)

### 7.2 JavaScript Tests

**Plano:**
1. Instalar Jest
2. Testar utils.js, validators, formatters
3. E2E com Playwright para fluxos criticos (login, upload, like, comment)

### 7.3 CI/CD

**Plano:**
1. GitHub Actions workflow:
   - PHP lint + PHPUnit
   - JS lint + Jest
   - CSS lint
2. Deploy automatico para staging em PR merge
3. Deploy manual para producao

---

## PRIORIDADE DE EXECUCAO

| Prioridade | Fase | Estimativa | Impacto |
|-----------|------|-----------|---------|
| 1 (Urgente) | 1.1 CSP Nonces | 2-3 dias | Seguranca critica |
| 1 (Urgente) | 1.2 Sanitizacao Server-Side | 1 dia | Seguranca |
| 1 (Urgente) | 1.3 Rate Limiting DB | 1 dia | Seguranca |
| 2 (Alto) | 4.1 Indices DB | 1 dia | Performance |
| 2 (Alto) | 2.1-2.3 API Consistency | 1-2 semanas | Manutenibilidade |
| 3 (Medio) | 3.1-3.3 JS Improvements | 2-3 semanas | Qualidade |
| 3 (Medio) | 5.1-5.3 MVC Migration | 3-4 semanas | Arquitetura |
| 4 (Baixo) | 6.1-6.3 Performance | 2 semanas | Escalabilidade |
| 5 (Futuro) | 7.1-7.3 Testes + CI/CD | Ongoing | Confiabilidade |

---

## REGRAS DE REFATORACAO

1. **Nunca quebrar funcionalidades existentes** — testar manualmente apos cada mudanca
2. **Commits pequenos e frequentes** — um commit por mudanca logica
3. **Branch por fase** — nao misturar seguranca com refatoracao de JS
4. **Backward compatibility** — APIs existentes devem continuar a funcionar
5. **Documentar decisoes** — porque escolhemos X em vez de Y
