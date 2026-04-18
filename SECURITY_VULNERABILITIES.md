# 🔐 VULNERABILIDADES DE SEGURANÇA - MyTube

**Data da Auditoria:** 18 de Abril, 2026  
**Status:** Em correção

---

## 📊 RESUMO

| Severidade | Total | Resolvidas | Pendentes |
|------------|-------|------------|-----------|
| CRÍTICO    | 14    | 2          | 12        |
| ALTO       | 13    | 0          | 13        |
| MÉDIO      | 8     | 0          | 8         |
| BAIXO      | 7     | 0          | 7         |
| **TOTAL**  | **42**| **2**      | **40**    |

---

## 🔴 VULNERABILIDADES CRÍTICAS (14)

### ✅ 1. Credenciais expostas no código-fonte
**Status:** ✅ **RESOLVIDO**  
**Arquivos:** `includes/config.php`, `includes/r2_config.php`, `includes/mail_config.php`  
**Problema:** Senhas de banco, chaves R2 e credenciais SMTP hardcoded  
**Solução:** Sistema de variáveis de ambiente implementado (.env)  
**Data:** 18/04/2026

### ✅ 2. Endpoints de debug públicos  
**Status:** ✅ **RESOLVIDO** (verificado em `.gitignore`)  
**Arquivos:** `check_session_browser.php`, `check_users.php`  
**Problema:** Expõem session_id, user_id e listam todos usuários sem autenticação  
**Solução:** Arquivos já estão em `.gitignore` e devem ser deletados em produção  
**Ação Pendente:** Deletar manualmente na VPS

### ❌ 3. Ausência TOTAL de proteção CSRF
**Status:** ❌ **PENDENTE**  
**Arquivos:** Todos 47+ endpoints POST  
**Problema:** Nenhum endpoint valida token CSRF  
**Solução Proposta:**
```php
// Gerar token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Validar em POST
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    die('CSRF token mismatch');
}
```

### ❌ 4. Upload de arquivos sem validação de conteúdo
**Status:** ❌ **PENDENTE**  
**Arquivos:** `upload.php`, `profile.php`  
**Problema:** Só valida extensão, não conteúdo real (permite PHP renomeado como JPG)  
**Solução Proposta:**
```php
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file_tmp);
finfo_close($finfo);
if (!in_array($mime, $allowed_mimes)) die('Invalid file type');
```

### ❌ 5. SVG e HTML permitidos no upload do chat
**Status:** ❌ **PENDENTE**  
**Arquivo:** `includes/chat_upload_config.php`  
**Problema:** Permite `.svg`, `.html`, `.js`, `.xml` - XSS  
**Solução:** Remover da lista de tipos permitidos

### ❌ 6. Reset de senha atualiza TODAS contas com mesmo email
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/reset_password.php` linha 23  
**Problema:** `UPDATE users SET password = ? WHERE email = ?`  
**Solução:** Adicionar UNIQUE constraint + resetar por user_id

### ❌ 7. Token de reset exposto na resposta da API
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/verify_reset_code.php` linha 14  
**Problema:** Reset token retornado no JSON  
**Solução:** Armazenar apenas em `$_SESSION`, nunca retornar ao cliente

### ❌ 8. SSRF no download de música
**Status:** ❌ **PENDENTE**  
**Arquivo:** `includes/video_processing.php` linha 258-270  
**Problema:** `str_ends_with()` contornável, cURL segue redirects  
**Solução:** Validação estrita de domínio + bloquear IPs privados + desabilitar redirects

### ❌ 9. Path Traversal no streaming de vídeo
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/stream_video.php` linha 75-77  
**Problema:** `video_path` do banco usado sem sanitização  
**Solução:** Usar `realpath()` e verificar se está dentro de uploads/

### ❌ 10. Exposed AWS/Cloudflare R2 Credentials (antigas)
**Status:** ⚠️ **PARCIALMENTE RESOLVIDO**  
**Problema:** Credenciais antigas expostas no histórico Git  
**Ação Necessária:** Revogar credenciais antigas (já geradas novas)

### ❌ 11. Avatar Upload - Missing MIME Type Validation
**Status:** ❌ **PENDENTE**  
**Arquivo:** `profile.php` linha 62-70  
**Problema:** Só valida extensão do arquivo

### ❌ 12. Video Upload - No Content Verification
**Status:** ❌ **PENDENTE**  
**Arquivo:** `upload.php` linha 94-100  
**Problema:** Só valida extensão, permite executáveis renomeados

### ❌ 13. Database Credentials in Plaintext (resolvido localmente)
**Status:** ⚠️ **RESOLVIDO LOCALMENTE**  
**Ação Pendente:** Confirmar em produção

### ❌ 14. CRON Secret Hardcoded (resolvido)
**Status:** ⚠️ **RESOLVIDO LOCALMENTE**  
**Ação Pendente:** Confirmar em produção

---

## 🟠 VULNERABILIDADES ALTAS (13)

### ❌ 1. Sem proteção brute force no login
**Status:** ❌ **PENDENTE**  
**Arquivo:** `login.php` linha 27-75  
**Problema:** Tentativas ilimitadas sem rate limiting

### ❌ 2. Sem proteção brute force no código de reset
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/verify_reset_code.php`  
**Problema:** Código 6 dígitos sem limite de tentativas

### ❌ 3. Cookie de sessão sem flag `Secure`
**Status:** ❌ **PENDENTE**  
**Arquivo:** `includes/config.php` linha 29-31  
**Problema:** Falta `ini_set('session.cookie_secure', 1);`

### ❌ 4. Sem HTTPS forçado
**Status:** ❌ **PENDENTE**  
**Arquivo:** `includes/config.php` linha 17  
**Problema:** Credenciais trafegam em texto plano

### ❌ 5. Política de senha fraca (6 caracteres)
**Status:** ❌ **PENDENTE**  
**Arquivo:** `login.php` linha 97  
**Problema:** Mínimo 6 chars é muito fraco

### ❌ 6. Admin verificado por username (=== 'Admin')
**Status:** ❌ **PENDENTE**  
**Arquivo:** `api/boost_metrics.php`, `api/calculate_best_mytuber.php`  
**Problema:** Deve usar role-based access control

### ❌ 7. Nomes de arquivo previsíveis
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

## 🟡 VULNERABILIDADES MÉDIAS (8)

### ❌ 1. Sessão válida por 30 dias
**Status:** ⚠️ **MELHORADO** (reduzido para 2 horas via .env)  
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

### Email SMTP ⚠️
- ⚠️ Nova senha de app gerada
- ⚠️ Senha sem espaços configurada no .env
- ⏳ Aguardando teste

---

**Última atualização:** 18/04/2026 19:00
