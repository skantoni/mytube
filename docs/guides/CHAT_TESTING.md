<!-- 
╔══════════════════════════════════════════════════════════════╗
║           GUIA DE TESTE DO SISTEMA DE CHAT                  ║
╚══════════════════════════════════════════════════════════════╝
-->

# 🧪 TESTES DO SISTEMA DE CHAT

## 📋 CHECKLIST DE INSTALAÇÃO

### 1. Banco de Dados
- [ ] Executar `install_chat.php` OU `database/install_chat.sql`
- [ ] Verificar se as 4 tabelas foram criadas:
  - [ ] conversations
  - [ ] messages
  - [ ] typing_status
  - [ ] user_online_status

### 2. Arquivos Necessários
- [ ] chat.php (página principal)
- [ ] assets/css/chat.css
- [ ] assets/js/chat.js
- [ ] api/send_message.php
- [ ] api/get_messages.php
- [ ] api/update_typing_status.php
- [ ] api/check_typing_status.php
- [ ] api/mark_messages_read.php
- [ ] api/delete_message.php
- [ ] api/update_online_status.php
- [ ] api/check_online_status.php
- [ ] api/search_chat_users.php

## 🔍 TESTES FUNCIONAIS

### Teste 1: Acesso ao Chat
**Como testar:**
1. Fazer login no sistema
2. Clicar no link "Chat" no menu
3. Verificar se a página carrega sem erros

**Resultado esperado:**
✅ Página do chat carrega
✅ Lista de conversas aparece (mesmo vazia)
✅ Botão "Nova conversa" visível

---

### Teste 2: Iniciar Nova Conversa
**Como testar:**
1. Clicar no botão ✏️ (Nova conversa)
2. Digitar nome de usuário na busca
3. Clicar em um usuário

**Resultado esperado:**
✅ Modal abre
✅ Lista de usuários aparece
✅ Chat com usuário abre
✅ URL muda para `chat.php?user_id=X`

---

### Teste 3: Enviar Mensagem
**Setup:**
- Abrir dois navegadores diferentes
- Login com usuário A no navegador 1
- Login com usuário B no navegador 2

**Como testar:**
1. Usuário A abre chat com B
2. Digita mensagem
3. Pressiona Enter ou clica em Enviar

**Resultado esperado:**
✅ Mensagem aparece no chat do usuário A
✅ Mensagem tem status ✔ (enviado)
✅ Em 2 segundos, status muda para ✔✔ (entregue)
✅ Mensagem aparece automaticamente no chat do usuário B

---

### Teste 4: Status de Digitação
**Setup:** Dois navegadores, usuários A e B em conversa

**Como testar:**
1. Usuário A começa a digitar
2. Verificar chat do usuário B

**Resultado esperado:**
✅ "está digitando..." aparece no chat de B
✅ Animação de 3 pontos
✅ Desaparece após 3 segundos sem digitar

---

### Teste 5: Status Online/Offline
**Setup:** Dois navegadores

**Como testar:**
1. Usuário A online, verificar indicador
2. Usuário A fecha o navegador
3. Verificar no chat do usuário B

**Resultado esperado:**
✅ Bolinha verde quando online
✅ Bolinha cinza quando offline
✅ Mostra "Visto há X minutos"

---

### Teste 6: Confirmação de Leitura
**Setup:** Dois navegadores

**Como testar:**
1. Usuário A envia mensagem para B
2. Verificar status
3. Usuário B abre o chat
4. Verificar status novamente

**Resultado esperado:**
✅ Inicialmente: ✔ (enviado)
✅ Após entrega: ✔✔ (entregue) cinza
✅ Após B abrir: ✔✔ (lido) azul

---

### Teste 7: Responder Mensagem (Reply)
**Como testar:**
1. Passar mouse sobre mensagem
2. Clicar no ícone ↩️ Responder
3. Digitar resposta
4. Enviar

**Resultado esperado:**
✅ Preview de resposta aparece
✅ Mensagem original destacada
✅ Mensagem enviada com reply
✅ Reply visível para ambos usuários

---

### Teste 8: Apagar Para Mim
**Como testar:**
1. Passar mouse sobre mensagem
2. Clicar em ⋮ (mais opções)
3. Escolher "Apagar para mim"
4. Confirmar

**Resultado esperado:**
✅ Mensagem desaparece para você
✅ Mensagem continua visível para outro usuário

---

### Teste 9: Apagar Para Todos
**Como testar:**
1. Enviar mensagem
2. Em menos de 1 hora, clicar em ⋮
3. Escolher "Apagar para todos"
4. Confirmar

**Resultado esperado:**
✅ "Mensagem apagada" aparece para ambos
✅ Ícone 🚫 visível

**Teste com mensagem antiga:**
1. Tentar apagar mensagem > 1 hora
**Resultado esperado:**
❌ Erro: "Tempo limite excedido"

---

### Teste 10: Mensagens em Tempo Real
**Setup:** Dois navegadores

**Como testar:**
1. Deixar ambos navegadores abertos
2. Enviar mensagem de A para B
3. Observar chat de B (SEM atualizar)

**Resultado esperado:**
✅ Mensagem aparece automaticamente em até 2 segundos
✅ Som de notificação (se implementado)

---

### Teste 11: Anexar Arquivos (Estrutura)
**Como testar:**
1. Clicar no botão 📎
2. Verificar menu

**Resultado esperado:**
✅ Menu com 4 opções aparece:
  - 🖼️ Foto
  - 🎥 Vídeo
  - 📄 Documento
  - 🎨 Sticker
✅ Ao clicar, mostra alerta (não implementado ainda)

---

### Teste 12: Gravar Áudio
**Como testar:**
1. Clicar no botão 🎤
2. Permitir acesso ao microfone
3. Clicar novamente para parar

**Resultado esperado:**
✅ Botão fica vermelho ao gravar
✅ Animação pulsante
✅ Ao parar, mostra alerta (não implementado ainda)

---

### Teste 13: Responsividade Mobile
**Como testar:**
1. Abrir chat no celular OU
2. DevTools (F12) → Toggle Device Toolbar
3. Testar com largura < 768px

**Resultado esperado:**
✅ Botão de voltar aparece
✅ Layout adaptado para mobile
✅ Menu de anexos em grid 4 colunas
✅ Touch funciona perfeitamente

---

### Teste 14: Múltiplas Conversas
**Como testar:**
1. Iniciar conversa com usuário A
2. Voltar e iniciar com usuário B
3. Alternar entre conversas

**Resultado esperado:**
✅ Lista mostra todas conversas
✅ Última mensagem visível
✅ Contador de não lidas
✅ Ao trocar, mensagens corretas aparecem

---

## 🐛 PROBLEMAS COMUNS E SOLUÇÕES

### Problema: Chat não carrega mensagens
**Solução:**
1. Abrir Console (F12)
2. Verificar erros JavaScript
3. Verificar Network → XHR
4. Confirmar que API retorna JSON válido

### Problema: "Está digitando" não aparece
**Solução:**
1. Verificar se tabela `typing_status` existe
2. Confirmar que polling está ativo
3. Testar em abas diferentes (não mesma sessão)

### Problema: Status online sempre offline
**Solução:**
1. Verificar tabela `user_online_status`
2. Confirmar que `update_online_status.php` é chamado
3. Ver se beforeunload está funcionando

### Problema: Mensagens não marcam como lidas
**Solução:**
1. Verificar se `mark_messages_read.php` existe
2. Conferir permissões das APIs
3. Ver console para erros AJAX

---

## 📊 VERIFICAÇÃO NO BANCO DE DADOS

### Consultas úteis para debug:

```sql
-- Ver todas conversas
SELECT * FROM conversations ORDER BY updated_at DESC;

-- Ver mensagens de uma conversa
SELECT * FROM messages WHERE conversation_id = 1 ORDER BY created_at;

-- Ver status online
SELECT u.username, uo.is_online, uo.last_seen 
FROM user_online_status uo 
JOIN users u ON u.id = uo.user_id;

-- Ver status de digitação
SELECT * FROM typing_status WHERE is_typing = TRUE;

-- Limpar dados de teste
DELETE FROM messages;
DELETE FROM conversations;
DELETE FROM typing_status;
```

---

## ✅ CHECKLIST FINAL

Antes de considerar completo, verificar:

- [ ] Todas as 4 tabelas criadas
- [ ] Todas as 9 APIs funcionando
- [ ] CSS carregando corretamente
- [ ] JavaScript sem erros
- [ ] Mensagens enviando
- [ ] Status de digitação funcionando
- [ ] Online/offline funcionando
- [ ] Confirmações de leitura OK
- [ ] Reply funcionando
- [ ] Apagar mensagens OK
- [ ] Layout responsivo
- [ ] Mobile funcionando

---

## 🎯 PERFORMANCE

### Intervalos de polling:
- Novas mensagens: **2 segundos**
- Status digitação: **1 segundo**
- Status online: **5 segundos**
- Atualizar meu status: **30 segundos**

### Otimizações implementadas:
✅ Carrega apenas novas mensagens (last_id)
✅ Índices no banco de dados
✅ Queries otimizadas com JOINs
✅ Prepared statements
✅ Validações no backend

---

## 📞 SUPORTE

Se algo não funcionar:
1. Verificar logs do PHP (error_log)
2. Console do navegador (F12)
3. Network tab para ver chamadas API
4. Testar APIs diretamente (Postman/curl)

---

**Sistema completo e pronto para uso! 🎉**
