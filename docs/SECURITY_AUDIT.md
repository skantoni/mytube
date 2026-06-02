# AUDITORIA DE SEGURANÇA — MyTube

**Data:** 02 de Junho, 2026
**Âmbito:** Revisão completa de código-fonte, APIs, autenticação, uploads, chat e configuração
**Metodologia:** Revisão estática de código, análise de fluxo de dados, verificação contra OWASP Top 10 (2021)

---

## SUMÁRIO EXECUTIVO

O MyTube é uma plataforma de partilha de vídeos com funcionalidades de rede social, chat em tempo real (Node.js + Socket.IO), upload para Cloudflare R2, e moderação de conteúdo. A aplicação foi sujeita a uma ronda de correções de segurança extensa entre Abril e Maio de 2026, que resolveu 21 de 42 vulnerabilidades identificadas inicialmente. Esta auditoria completa (Junho 2026) identificou 16 vulnerabilidades adicionais não documentadas anteriormente, elevando o total para 58 vulnerabilidades de que 37 permanecem pendentes.

**Estado geral de segurança:** Moderado — Fundação sólida, mas com gaps importantes.

---

## FICHEIROS ANALISADOS

| Categoria | Ficheiros |
|-----------|-----------|
| Configuração | `includes/config.php`, `includes/env_loader.php`, `includes/csrf_helpers.php` |
| Autenticação | `login.php`, `register.php`, `api/change_password.php`, `api/chat_token.php` |
| Upload | `upload.php`, `includes/upload_validation.php`, `includes/image_sanitizer.php`, `assets/js/upload.js` |
| APIs | `api/stream_video.php`, `api/search.php`, `api/get_comments.php`, `api/toggle_video_like.php` |
| APIs (cont.) | `api/send_friend_request.php`, `api/admin_moderate.php`, `api/push_subscribe.php` |
| Segurança | `includes/rate_limit.php`, `includes/ssrf_protection.php`, `includes/r2_storage.php` |
| Processamento | `includes/video_processing.php`, `includes/video_processing.php` |
| Chat | `chat-server/server.js`, `assets/js/chat-socket.js` |
| Frontend | `assets/js/tiktok.js`, `assets/js/comments-new.js`, `assets/js/csrf.js` |

---

## VULNERABILIDADES POR SEVERIDADE

---

### CRÍTICAS — Correção Imediata

---

#### CRIT-01 — Ficheiros de Debug no Repositório

**Severidade:** Crítico
**Status:** Pendente
**Ficheiros afetados:**

- `test_csrf.php`
- `test_rate_limit.php`
- `test_upload_validation.php`
- `check_session_browser.php`
- `debug_csrf.php`
- `debug_csrf_production.php`

**Impacto:** Estes ficheiros expõem estado interno da aplicação (session IDs, listagem de utilizadores, configurações de debug). Apesar de estarem bloqueados no Nginx em produção, a sua presença no repositório representa risco em qualquer ambiente onde o Nginx não esteja corretamente configurado (staging, desenvolvimento, deploys alternativos).

**Evidência:**

```
api/debug_csrf.php          → linha 1: expõe tokens CSRF ativos
api/debug_csrf_production.php → sem autenticação
check_session_browser.php   → lista session_id, user_id, role
test_*.php                  → testes sem autenticação
```

**Recomendação:**

```bash
git rm test_csrf.php test_rate_limit.php test_upload_validation.php
git rm check_session_browser.php debug_csrf.php debug_csrf_production.php
echo "test_*.php" >> .gitignore
echo "check_*.php" >> .gitignore
echo "debug_*.php" >> .gitignore
```

**Prioridade:** Fazer hoje, antes do próximo deploy.

---

#### CRIT-02 — Secret JWT com Fallback Inseguro

**Severidade:** Crítico
**Status:** Pendente
**Ficheiro:** `chat-server/server.js`

**Evidência:**

```javascript
// Linha ~25 em chat-server/server.js
const secret = process.env.CHAT_JWT_SECRET || 'CHANGE_ME_IN_PRODUCTION';
```

**Impacto:** Se `CHAT_JWT_SECRET` não estiver definido no ambiente (erro de configuração comum em novos deploys), qualquer atacante pode forjar tokens JWT usando a chave literal `'CHANGE_ME_IN_PRODUCTION'` e personificar qualquer utilizador no chat.

**Recomendação:**

```javascript
const secret = process.env.CHAT_JWT_SECRET;
if (!secret || secret.length < 32) {
    console.error('[FATAL] CHAT_JWT_SECRET não definido ou demasiado curto (mínimo 32 chars)');
    process.exit(1);
}
```

---

#### CRIT-03 — Path Traversal via URL Encoding

**Severidade:** Crítico
**Status:** Pendente
**Ficheiro:** `api/stream_video.php`

**Evidência:**

```php
// A verificação atual bloqueia ../ literais:
$suspicious_patterns = ['../', '..\\', '../', '..\\'];

// MAS não bloqueia variantes codificadas:
// ..%2F  → decodifica para ../
// ..%5C  → decodifica para ..\
// ..%2F%2F  → dupla codificação
// ../\0.mp4 → null byte + extensão enganosa
```

**Impacto:** Bypass da proteção existente de path traversal, potencialmente permitindo leitura de ficheiros arbitrários do sistema.

**Mitigação existente:** `realpath()` remove a maioria das variantes, mas não null bytes em alguns sistemas.

**Recomendação:**

```php
// Decodificar ANTES de verificar padrões:
$raw_path = $video['video_path'];
$decoded_path = urldecode(urldecode($raw_path)); // dupla decodificação
foreach ($suspicious_patterns as $pattern) {
    if (strpos($decoded_path, $pattern) !== false) {
        http_response_code(403);
        die('Acesso negado');
    }
}
// Remover null bytes:
$decoded_path = str_replace("\0", '', $decoded_path);
```

---

### ALTAS — Resolver na próxima sprint

---

#### HIGH-01 — CSP com unsafe-inline e unsafe-eval

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `includes/config.php`

**Evidência:**

```php
// Header CSP atual em produção:
"script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net ..."
```

**Impacto:** As diretivas `'unsafe-inline'` e `'unsafe-eval'` anulam a proteção XSS que o CSP deveria providenciar. Com `unsafe-inline`, qualquer tag `<script>` inline ou `onclick=` injetada executa sem restrição. O CSP atual é, na prática, decorativo.

**Recomendação — Migrar para nonces:**

```php
// Em includes/config.php, antes de enviar headers:
$csp_nonce = base64_encode(random_bytes(16));
$_SESSION['csp_nonce'] = $csp_nonce; // ou passar via globals

header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-$csp_nonce' https://cdn.jsdelivr.net; " .
    "style-src 'self' 'nonce-$csp_nonce' https://fonts.googleapis.com; " .
    "img-src 'self' data: blob: https:; " .
    "connect-src 'self' wss: https:; " .
    "media-src 'self' blob: https:;");
```

Em cada template PHP, adicionar o nonce aos scripts:

```html
<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
  // código JS inline aqui
</script>
```

---

#### HIGH-02 — Política de Senha Fraca

**Severidade:** Alto
**Status:** Pendente
**Ficheiros:** `login.php` (~linha 97), `register.php`

**Evidência:**

```php
// Validação atual: apenas 6 caracteres mínimos
if (strlen($password) < 6) {
    $error = "A senha deve ter pelo menos 6 caracteres";
}
```

**Impacto:** Senhas como `123456`, `abc123` ou `qwerty` são aceites. Vulnerável a ataques de dicionário e credential stuffing.

**Recomendação:**

```php
function validate_password_strength(string $password): ?string {
    if (strlen($password) < 12) {
        return "A senha deve ter pelo menos 12 caracteres";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return "A senha deve conter pelo menos uma letra maiúscula";
    }
    if (!preg_match('/[0-9]/', $password)) {
        return "A senha deve conter pelo menos um número";
    }
    return null; // válida
}
```

---

#### HIGH-03 — Nomes de Ficheiro Previsíveis

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `profile.php` (~linha 91)

**Evidência:**

```php
// Padrão atual: user_123_1748822400.jpg
$new_filename = 'user_' . $user_id . '_' . time() . '.' . $file_extension;
```

**Impacto:** Atacante que conheça o `user_id` de um utilizador (enumerável pela API) pode calcular URLs de avatares simplesmente tentando timestamps recentes. Expõe fotos de perfil mesmo em configurações "privadas".

**Recomendação:**

```php
// Gerar nome completamente aleatório:
$new_filename = bin2hex(random_bytes(16)) . '.' . $file_extension;
// Resultado: a7f3c921d5e8b4c6f1e3d9a2c8b61234.jpg
```

---

#### HIGH-04 — MIME type Octet-Stream Aceite para Vídeos

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `includes/upload_validation.php`

**Evidência:**

```php
// Mapeamento problemático:
'application/octet-stream' => ['mp4', 'avi', 'mov'] // Fallback para alguns servidores
```

**Impacto:** `application/octet-stream` é o tipo MIME genérico para qualquer ficheiro binário. Um ficheiro PHP, ELF ou PE executável pode ter este MIME type, contornando a validação de tipo de vídeo.

**Recomendação:** Remover completamente a entrada `octet-stream`. Se servidores específicos retornam este tipo para vídeos legítimos, usar `finfo_file()` com verificação adicional dos primeiros bytes (magic numbers) do ficheiro.

---

#### HIGH-05 — Avatares Antigos Não Eliminados

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `profile.php`

**Impacto:** Cada atualização de avatar deixa o ficheiro anterior acessível pela URL original. Isto expõe fotos antigas de utilizadores indefinidamente, viola privacidade e acumula storage desnecessariamente.

**Recomendação:**

```php
// Antes de salvar o novo avatar, guardar o caminho do atual:
$old_avatar = $user_data['profile_picture'];

// ... após upload bem-sucedido:
if ($old_avatar && $old_avatar !== 'default.webp') {
    $old_path = UPLOAD_DIR . 'avatars/' . basename($old_avatar);
    if (file_exists($old_path)) {
        unlink($old_path);
    }
}
```

---

#### HIGH-06 — Rate Limiting de Comentários Baseado em Sessão

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `api/add_comment.php`

**Evidência:**

```php
// Rate limit contornável com nova aba ou modo incógnito:
$rate_key = 'comment_last_' . $user_id;
if (isset($_SESSION[$rate_key]) && ...) { ... }
```

**Recomendação:** Usar o sistema `rate_limit_check()` já implementado em `includes/rate_limit.php`:

```php
$rate_check = rate_limit_check($pdo, 'add_comment', $user_id, 5, 60);
if (!$rate_check['allowed']) {
    http_response_code(429);
    echo json_encode(['error' => 'Demasiados comentários. Aguarde ' . $rate_check['seconds_remaining'] . 's.']);
    exit;
}
rate_limit_record($pdo, 'add_comment', $user_id);
```

---

#### HIGH-07 — Sem Rate Limiting em Pedidos de Amizade

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `api/send_friend_request.php`

**Impacto:** Utilizador pode enviar pedidos de amizade em massa para todos os outros utilizadores sem qualquer limitação, causando spam e abuso da plataforma.

**Recomendação:** Adicionar `rate_limit_check()` com limite de 20 pedidos por hora por utilizador.

---

#### HIGH-08 — Sem timeout em Processos FFmpeg

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `includes/video_processing.php`

**Evidência:**

```php
// exec() sem timeout - pode bloquear indefinidamente:
exec($command, $output, $exit_code);
```

**Impacto:** Upload de vídeo malformado pode bloquear o processo PHP indefinidamente, esgotando workers do servidor e causando DoS.

**Recomendação — Usar proc_open com timeout:**

```php
function exec_with_timeout(string $command, int $timeout = 120): array {
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!$proc) return ['exit' => 1, 'output' => ''];

    $start = time();
    while (proc_get_status($proc)['running']) {
        if (time() - $start > $timeout) {
            proc_terminate($proc, 9);
            return ['exit' => 1, 'output' => 'Timeout'];
        }
        usleep(100000); // 100ms
    }
    $output = stream_get_contents($pipes[1]);
    proc_close($proc);
    return ['exit' => 0, 'output' => $output];
}
```

---

#### HIGH-09 — Logs de Debug com Dados Sensíveis

**Severidade:** Alto
**Status:** Pendente
**Ficheiros:** `api/toggle_follow.php`, outros endpoints

**Impacto:** Dados de sessão, tokens e informações pessoais em logs de produção. Logs podem ser acedidos por atacante com acesso ao servidor ou expostos por rotação inadequada.

**Recomendação:**

```php
// ❌ Não fazer:
error_log("Session data: " . json_encode($_SESSION));

// ✅ Fazer:
error_log("Follow action: user_id=" . intval($user_id) . " target=" . intval($target_id));
```

---

#### HIGH-10 — Atualização de Ranking Fora de Transação

**Severidade:** Alto
**Status:** Pendente
**Ficheiro:** `api/toggle_video_like.php` (~linhas 109–121)

**Evidência:**

```php
$pdo->commit(); // Transação termina aqui

// ❌ Esta query é executada FORA da transação:
$ownerStmt = $pdo->prepare("SELECT user_id FROM videos WHERE id = ?");
$ownerStmt->execute([$video_id]);
$vid_owner = $ownerStmt->fetchColumn();
// ... UPDATE ranking_points ...
```

**Impacto:** Se a atualização de ranking falhar (erro de rede, timeout), o like fica registado mas os pontos não são atribuídos, criando inconsistência de dados permanente.

**Recomendação:** Mover toda a lógica de atualização de ranking para dentro do bloco `beginTransaction()`/`commit()`.

---

### MÉDIAS — Resolver em 2–4 semanas

---

#### MED-01 — session_regenerate_id() Não Chamado Após Mudança de Senha

**Ficheiro:** `api/change_password.php`
**Impacto:** Se a sessão anterior foi comprometida, o atacante mantém acesso mesmo após mudança de senha.
**Solução:** `session_regenerate_id(true);` após atualização bem-sucedida.

---

#### MED-02 — Notificações com HTML Injection

**Ficheiro:** `api/get_notifications.php` (~linha 67)
**Problema:** Campo `message` retornado sem `htmlspecialchars()` no JSON.
**Solução:** Sanitizar todos os campos de texto antes de incluir no JSON de resposta.

---

#### MED-03 — Enumeração de Emails via Timing (Reset)

**Ficheiro:** `api/send_reset_code.php`
**Problema:** Quando o email não existe, a resposta é mais rápida (sem envio de email), permitindo enumerar contas válidas por tempo de resposta.
**Solução:** Sempre executar operações equivalentes independentemente da existência do email. Usar `usleep()` para equalizar tempo de resposta.

---

#### MED-04 — Race Condition no Rate Limiting

**Ficheiro:** `includes/rate_limit.php`
**Problema:** SELECT + INSERT separados sem lock — janela de tempo para inserções concorrentes que contornam o limite.
**Solução:** `INSERT ... ON DUPLICATE KEY UPDATE attempts = attempts + 1` numa única query atómica.

---

#### MED-05 — Texto de Comentários Sem Sanitização Server-Side

**Ficheiro:** `api/get_comments.php`
**Problema:** Comentários retornados sem `htmlspecialchars()`, dependendo exclusivamente do JS para sanitização.
**Solução:**

```php
$comment['comment_text'] = htmlspecialchars($comment['comment_text'] ?? '', ENT_QUOTES, 'UTF-8');
```

---

#### MED-06 — SSRF — Domínios Não-Resolvíveis Aceites

**Ficheiro:** `includes/ssrf_protection.php`
**Problema:** Se DNS não resolve mas o domínio está na whitelist, o código aceita o request sem validar o IP de destino.
**Solução:** Rejeitar sempre que o DNS não resolver com sucesso. Nunca confiar em domínios sem resolução confirmada.

---

#### MED-07 — Sem Validação de URL nos Endpoints Push

**Ficheiro:** `api/push_subscribe.php`
**Problema:** `endpoint` aceite sem verificação de URL válida ou domínio de provider legítimo.
**Solução:**

```php
if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Endpoint inválido']);
    exit;
}
$parsed = parse_url($endpoint);
$allowed_push_hosts = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.apple.com'];
if (!in_array($parsed['host'] ?? '', $allowed_push_hosts, true)) {
    // Aceitar mas logar para revisão
}
```

---

#### MED-08 — Mensagens de Erro Verbosas em Produção

**Ficheiro:** Vários endpoints
**Problema:** Exceções PHP com stack traces e mensagens MySQL expostas em respostas JSON em produção.
**Solução:**

```php
if (env('APP_ENV') !== 'production') {
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
} else {
    echo json_encode(['error' => 'Ocorreu um erro interno. Tente novamente.']);
    error_log('Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
```

---

#### MED-09 — Ficheiros Temporários FFmpeg Não Limpos

**Ficheiro:** `includes/video_processing.php`
**Problema:** Crash durante processamento deixa ficheiros em `/tmp` indefinidamente.
**Solução:**

```php
$temp_files = [];
register_shutdown_function(function() use (&$temp_files) {
    foreach ($temp_files as $file) {
        if (file_exists($file)) @unlink($file);
    }
});
```

---

#### MED-10 — Sem Validação de Tamanho em Inputs de Busca

**Ficheiro:** `api/search.php`
**Problema:** Query de busca sem limite de tamanho — pode causar queries LIKE lentas com padrões enormes.
**Solução:**

```php
$query = substr(trim(strip_tags($_GET['q'] ?? '')), 0, 100);
```

---

### BAIXAS — Resolver a médio prazo

---

#### LOW-01 — Sem API Versioning

Todos os endpoints em `/api/` sem prefixo de versão. Migrar para `/api/v1/` para permitir deprecação controlada.

---

#### LOW-02 — Queries N+1 em Comentários

**Ficheiro:** `api/get_comments.php`
Contagem de replies em query separada. Resolver com `LEFT JOIN + COUNT() GROUP BY`.

---

#### LOW-03 — Pesquisa com LIKE '%query%' Sem Índice Full-Text

**Ficheiro:** `api/search.php`
`LIKE '%termo%'` causa full table scan. Implementar `FULLTEXT INDEX` + `MATCH() AGAINST()`.

---

#### LOW-04 — Cache de Vídeos Privados (7 dias)

**Ficheiro:** `api/stream_video.php`
Todos os vídeos recebem `Cache-Control: max-age=604800`. Vídeos privados devem ter `Cache-Control: private, no-cache`.

---

#### LOW-05 — Upload Polling Agressivo

**Ficheiro:** `assets/js/upload.js`
Polling a cada 2 segundos fixos. Implementar backoff exponencial (2s → 4s → 8s → máx 30s).

---

#### LOW-06 — Sem Paginação para Lista de Conversas

**Ficheiro:** `chat-server/server.js`
`getUserConversations()` retorna todas as conversas. Adicionar `LIMIT 20 OFFSET ?` com cursor pagination.

---

#### LOW-07 — Permissões de Diretório de Upload Abertas

**Ficheiro:** `upload.php`
`mkdir(..., 0755)` — reduzir para `0750`.

---

#### LOW-08 — Ficheiros de Configuração Example Expostos

**Ficheiros:** `config_advanced.example.php`, `includes/config.example.php`, `includes/r2_config.example.php`
Revelam arquitetura interna. Mover para `docs/examples/` ou proteger via Nginx.

---

#### LOW-09 — Sem Connection Pooling para Base de Dados

**Ficheiro:** `includes/config.php` (classe `LazyPDO`)
Nova conexão TCP por request em produção. Configurar `PDO::ATTR_PERSISTENT` ou usar PgBouncer/ProxySQL.

---

#### LOW-10 — Cleanup de Sessões Online Sem Índice

**Ficheiro:** `chat-server/server.js`
`cleanupStaleOnlineStatuses()` executa query em `updated_at` sem índice. Adicionar `INDEX (updated_at)` à tabela `user_online_status`.

---

## OWASP TOP 10 — ESTADO ATUAL (2026)

| Categoria | Status | Evidência |
|-----------|--------|-----------|
| A01 — Broken Access Control | Parcial | Role checks OK; sem expiração de sessão pós-mudança de senha |
| A02 — Cryptographic Failures | OK | `password_hash()`, HTTPS forçado, HSTS |
| A03 — Injection | OK | Prepared statements em todos os endpoints; SSRF mitigado |
| A04 — Insecure Design | Parcial | Constraint UNIQUE email a verificar; avatares previsíveis |
| A05 — Security Misconfiguration | Parcial | .env OK; CSP com unsafe-inline; ficheiros debug no repo |
| A06 — Vulnerable Components | Desconhecido | Sem `composer.json` ou auditoria de dependências |
| A07 — Auth & Session Failures | Parcial | JWT chat OK; sem 2FA; senha fraca; sem session_regenerate pós-password |
| A08 — Data Integrity Failures | Parcial | Transações na maioria dos casos; ranking fora de transação |
| A09 — Logging & Monitoring | Parcial | Rate limit logs OK; sem logging centralizado; logs com dados sensíveis |
| A10 — SSRF | Parcial | ssrf_protection.php implementado; DNS rebinding parcialmente vulnerável |

---

## BOAS PRÁTICAS OBSERVADAS

As seguintes práticas corretas foram identificadas e devem ser mantidas:

- Prepared statements PDO em 100% das queries identificadas
- `hash_equals()` para comparações de tokens (timing-safe)
- `random_bytes(32)` para geração de tokens CSRF
- `password_hash(PASSWORD_DEFAULT)` + `password_verify()`
- `session_regenerate_id(true)` após login
- `HttpOnly` + `SameSite=Lax` + `Secure` (em produção) nos cookies
- `finfo_file()` para validação real de MIME types
- Remoção de EXIF/GPS das imagens
- `CURLOPT_FOLLOWLOCATION => false` nas requisições curl
- Transações PDO para operações atómicas de contadores
- Validação de JWT no handshake Socket.IO
- `escapeshellarg()` nas chamadas FFmpeg
- Tokens CSRF em meta tag + interceptação global em JS

---

## PLANO DE REMEDIAÇÃO PRIORIZADO

### Semana 1 (Crítico)

| # | Tarefa | Ficheiro | Tempo |
|---|--------|----------|-------|
| 1 | Eliminar ficheiros debug do repositório | `test_*.php`, `check_*.php`, `debug_*.php` | 30 min |
| 2 | Fazer CHAT_JWT_SECRET obrigatório (falhar se ausente) | `chat-server/server.js` | 15 min |
| 3 | Remover octet-stream da validação de MIME | `includes/upload_validation.php` | 15 min |
| 4 | Decodificar URL antes de verificar path traversal | `api/stream_video.php` | 30 min |

### Semana 2 (Alto)

| # | Tarefa | Ficheiro | Tempo |
|---|--------|----------|-------|
| 5 | Fortalecer política de senhas (12 chars + maiúscula + número) | `login.php`, `register.php` | 1h |
| 6 | Nomes de ficheiro aleatórios para avatares | `profile.php` | 30 min |
| 7 | Eliminar avatares antigos após atualização | `profile.php` | 45 min |
| 8 | Rate limiting de comentários via DB | `api/add_comment.php` | 30 min |
| 9 | Rate limiting em pedidos de amizade | `api/send_friend_request.php` | 20 min |
| 10 | Timeout em FFmpeg | `includes/video_processing.php` | 2h |
| 11 | Mover ranking para dentro da transação | `api/toggle_video_like.php` | 30 min |

### Semanas 3–4 (Médio)

| # | Tarefa | Ficheiro | Tempo |
|---|--------|----------|-------|
| 12 | session_regenerate_id após mudança de senha | `api/change_password.php` | 15 min |
| 13 | Sanitizar HTML em notificações | `api/get_notifications.php` | 30 min |
| 14 | htmlspecialchars no texto de comentários | `api/get_comments.php` | 20 min |
| 15 | Limitar tamanho de input de busca | `api/search.php` | 15 min |
| 16 | Mensagens de erro seguras em produção | Vários endpoints | 2h |
| 17 | Validação de URL em push subscriptions | `api/push_subscribe.php` | 30 min |
| 18 | Migrar CSP para nonces | `includes/config.php` + templates | 4h |

### Mês 2 (Baixo/Estrutural)

| # | Tarefa | Estimativa |
|---|--------|-----------|
| 19 | Full-text search em MySQL | 1 dia |
| 20 | Redis para rate limiting e cache | 2 dias |
| 21 | API versioning (/api/v1/) | 2 dias |
| 22 | Auditoria de dependências (sem composer.json) | 1 dia |
| 23 | Centralizar logging (estruturado, sem dados pessoais) | 2 dias |

---

**Auditoria realizada por:** Claude Code — Análise estática de código
**Data:** 02/06/2026
**Próxima revisão recomendada:** 02/09/2026 ou após remediação de itens críticos
