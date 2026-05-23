# MyTube - Versão 1.1.2

## 📋 Resumo das Alterações

Esta versão corrige duas falhas críticas:

1. **Vulnerabilidade de segurança no chat** — Qualquer utilizador podia personificar outros através da consola do browser
2. **SQL de instalação incompleto** — Scripts de instalação referenciavam ficheiros inexistentes e faltavam tabelas essenciais.

---

## 🔐 1. Correção de Segurança: Autenticação JWT no Chat

### Problema Identificado

O servidor Socket.IO confiava cegamente no `userId` enviado pelo cliente. Qualquer utilizador podia abrir a consola do browser, alterar `currentUserId` e enviar mensagens como outra pessoa.

### Solução Implementada

Implementação de autenticação JWT (JSON Web Token) com as seguintes características:

- **Token gerado no servidor** com base na sessão PHP autenticada
- **Validação obrigatória** no handshake do Socket.IO
- **Validade de 2 horas** com renovação automática
- **Algoritmo HMAC-SHA256** para assinatura

### Ficheiros Alterados

| Ficheiro | Alterações |
|----------|------------|
| **api/chat_token.php** *(novo)* | Endpoint que gera JWT com `userId` da sessão PHP |
| **chat-server/server.js** | Middleware `io.use()` para validação do token no handshake<br>13 eventos corrigidos para usar `socket.userId` em vez de `data.userId` |
| **assets/js/chat-socket.js** | Função `initializeSocket()` agora assíncrona<br>Busca token antes de conectar<br>Renovação automática ao expirar |
| **chat-server/.env.example** | Adicionada variável `CHAT_JWT_SECRET` |

---

## 🗄️ 2. SQL de Instalação Completo

### Problema Identificado

- `README.md` e `INSTALL.md` referenciavam `database/mytube_structure.sql` (ficheiro inexistente)
- O ficheiro existente (`mytube_structure_only.sql`) estava incompleto
- Faltavam tabelas e colunas utilizadas pela aplicação

### Solução Implementada

Criado novo script unificado: **`database/install.sql`**

#### Inclui:

**Colunas adicionadas:**
- `users.role` — Perfis de utilizador (user/admin/moderator/vip)
- `users.google_id` — Login social com Google
- `users.open_inbox` — Preferência de mensagens
- `videos.moderation_status` — Estado de moderação
- `chats.group_picture` — Imagem de grupos

**Novas tabelas:**
- `friend_requests` — Sistema de amizades
- `hidden_conversations` — Conversas ocultas
- `push_subscriptions` — Notificações web push
- `group_messages` — Mensagens de grupo

**Dados iniciais:**
- Utilizador admin pré-configurado

### Ficheiros Alterados

| Ficheiro | Alterações |
|----------|------------|
| **database/install.sql** *(novo)* | Script completo para instalação limpa |
| **README.md** | Instruções corrigidas para usar `install.sql`<br>Passos do chat-server adicionados |
| **INSTALL.md** | Guia reescrito com suporte para XAMPP/Linux/macOS<br>Tabela de resolução de problemas |

---

## 🚀 Instruções de Deploy

### ⚠️ Ordem de Execução (não saltar passos)

### 1️⃣ Atualizar o Código

```bash
cd /var/www/html/mytube  # ajustar para o caminho do projeto
git pull origin fix/security-leak

cd chat-server
npm install  # garantir dependências atualizadas
```

### 2️⃣ Configurar JWT Secret

#### Gerar chave secreta:

```bash
node -e "console.log(require('crypto').randomBytes(40).toString('hex'))"
```

#### Adicionar ao `.env` do chat-server:

```bash
nano chat-server/.env
```

```env
CHAT_JWT_SECRET=sua_chave_gerada_aqui_minimo_32_caracteres
```

#### Configurar no PHP:

Adicionar em `includes/config.php`:

```php
define('CHAT_JWT_SECRET', 'a_mesma_chave_do_env_acima');
```

### 3️⃣ Reiniciar Chat Server

```bash
pm2 restart chat-server
# ou
pm2 reload ecosystem.config.js
```

### 4️⃣ Atualizar Base de Dados (Produção)

⚠️ **Não executar `install.sql` em produção** (apagaria dados existentes)

Execute apenas as alterações incrementais:

```sql
-- Verificar antes: SHOW COLUMNS FROM users;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS open_inbox TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS role ENUM('user','admin','moderator','vip') NOT NULL DEFAULT 'user',
  ADD COLUMN IF NOT EXISTS google_id VARCHAR(50) NULL DEFAULT NULL;

ALTER TABLE videos
  ADD COLUMN IF NOT EXISTS moderation_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  ADD COLUMN IF NOT EXISTS moderation_score DECIMAL(5,4) NULL,
  ADD COLUMN IF NOT EXISTS moderation_checked_at TIMESTAMP NULL;

ALTER TABLE chats
  ADD COLUMN IF NOT EXISTS group_picture VARCHAR(255) DEFAULT NULL;

-- Criar tabelas novas (copiar do database/install.sql):
-- friend_requests, hidden_conversations, push_subscriptions, group_messages
```

**Nota:** `ADD COLUMN IF NOT EXISTS` requer MySQL 8.0+. Em versões anteriores, verificar primeiro com `SHOW COLUMNS FROM users LIKE 'role';`

### 5️⃣ Limpar Cache do Browser

Após o deploy, instruir utilizadores a fazer **hard reload**:

- **Windows/Linux:** `Ctrl + Shift + R`
- **macOS:** `Cmd + Shift + R`

O chat não conectará até o novo `chat-socket.js` ser carregado.

---

## ✅ Checklist de Verificação

Após deploy, confirmar:

- [ ] Chat-server reiniciado com `CHAT_JWT_SECRET` configurado
- [ ] PHP tem acesso ao mesmo `CHAT_JWT_SECRET`
- [ ] Base de dados atualizada (novas colunas/tabelas)
- [ ] Utilizadores conseguem conectar ao chat
- [ ] Mensagens aparecem com o remetente correto
- [ ] Não há erros na consola do browser (F12)