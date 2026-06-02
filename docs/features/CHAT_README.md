# 💬 Sistema de Chat em Tempo Real

Sistema completo de chat com todas as funcionalidades modernas, estilo WhatsApp!

## ✨ Funcionalidades Implementadas

### ✅ Mensagens em Tempo Real
- **Mensagens instantâneas** com verificação automática a cada 2 segundos
- **Notificações** de novas mensagens
- **Som de notificação** (estrutura pronta)

### ✅ Status de Digitação
- **"Fulano está digitando..."** em tempo real
- Indicador animado com 3 pontos
- Desaparece automaticamente após 3 segundos sem digitar

### ✅ Status Online/Offline
- Indicador verde quando usuário está **online**
- Mostra **última visualização** quando offline
- Atualização automática a cada 5 segundos
- "Visto há X minutos" ou "Visto há X horas"

### ✅ Confirmações de Mensagem (Tipo WhatsApp)
- **✔ Enviado** - Um check cinza
- **✔✔ Entregue** - Dois checks cinza
- **✔✔ Lido** - Dois checks azuis
- Atualização automática do status

### ✅ Responder Mensagens (Reply)
- **Responder mensagem específica** tipo WhatsApp
- Preview da mensagem original
- Destaque visual da mensagem respondida
- Botão para cancelar resposta

### ✅ Apagar Mensagens
- **Apagar para mim** - Remove apenas para você
- **Apagar para todos** - Remove para todos (até 1 hora após envio)
- Mensagem "Mensagem apagada" aparece no lugar
- Confirmação antes de apagar

### ✅ Interface Moderna
- **Layout tipo WhatsApp** com design responsivo
- **Modo claro** com cores suaves
- **Animações** fluidas
- **Mobile-friendly** - funciona perfeitamente em smartphones

### ✅ Funcionalidades Extras
- **Lista de conversas** ordenada por última mensagem
- **Contador de mensagens não lidas**
- **Buscar conversas** por nome
- **Iniciar novo chat** com qualquer usuário
- **Separadores de data** (Hoje, Ontem, etc.)
- **Scroll automático** para última mensagem
- **Textarea expansível** até 100px

## 🎨 Componentes do Layout

### Botões Disponíveis:
1. **📎 Anexar** - Abrir menu de anexos
2. **😊 Emoji** - Seletor de emojis (estrutura pronta)
3. **🎤 Áudio** - Gravar mensagem de voz
4. **✉️ Enviar** - Enviar mensagem (aparece ao digitar)
5. **⋯ Mais Opções** - Menu adicional

### Menu de Anexos:
- 🖼️ **Foto** - Enviar imagens
- 🎥 **Vídeo** - Enviar vídeos
- 📄 **Documento** - Enviar arquivos
- 🎨 **Sticker** - Enviar stickers

### Opções de Mensagem:
- ↩️ **Responder** - Reply na mensagem
- 🗑️ **Apagar para mim**
- 🗑️ **Apagar para todos** (apenas para mensagens próprias)

## 📦 Instalação

### 1. Criar as Tabelas do Banco de Dados

Execute o arquivo SQL:

```bash
mysql -u root mytube < database/install_chat.sql
```

Ou via phpMyAdmin:
1. Abra o phpMyAdmin
2. Selecione o banco de dados `mytube`
3. Vá em "Importar"
4. Selecione o arquivo `database/install_chat.sql`
5. Clique em "Executar"

### 2. Verificar Arquivos

Certifique-se de que os seguintes arquivos existem:

**PHP:**
- `chat.php` - Página principal do chat
- `api/send_message.php` - Enviar mensagens
- `api/get_messages.php` - Buscar mensagens
- `api/update_typing_status.php` - Atualizar digitação
- `api/check_typing_status.php` - Verificar digitação
- `api/mark_messages_read.php` - Marcar como lido
- `api/delete_message.php` - Apagar mensagens
- `api/update_online_status.php` - Atualizar status online
- `api/check_online_status.php` - Verificar status online
- `api/search_chat_users.php` - Buscar usuários

**CSS:**
- `assets/css/chat.css` - Estilos do chat

**JavaScript:**
- `assets/js/chat.js` - Funcionalidades em tempo real

### 3. Testar

1. Acesse: `http://localhost/my/chat.php`
2. Faça login com dois usuários diferentes em navegadores diferentes
3. Teste todas as funcionalidades!

## 🚀 Como Usar

### Iniciar uma Conversa
1. Clique no botão **✏️ (Nova conversa)** no canto superior direito
2. Busque um usuário pelo nome
3. Clique no usuário para abrir o chat

### Enviar Mensagem
1. Digite a mensagem no campo de texto
2. Pressione **Enter** ou clique no botão **✉️ Enviar**
3. Shift+Enter para quebra de linha

### Responder uma Mensagem
1. Passe o mouse sobre a mensagem
2. Clique no ícone **↩️ Responder**
3. Digite sua resposta
4. Clique em **X** para cancelar

### Apagar Mensagem
1. Passe o mouse sobre a mensagem
2. Clique no ícone **⋮ (Mais opções)**
3. Escolha "Apagar para mim" ou "Apagar para todos"
4. Confirme a ação

### Gravar Áudio
1. Clique no botão **🎤 Microfone**
2. Permita acesso ao microfone
3. Clique novamente para parar e enviar

### Anexar Arquivo
1. Clique no botão **📎 Anexar**
2. Escolha o tipo: Foto, Vídeo, Documento ou Sticker
3. Selecione o arquivo
4. Aguarde o upload

## 📱 Recursos Mobile

- Interface adaptada para telas pequenas
- Botão de voltar visível no mobile
- Menu de anexos em grid responsivo
- Touch-friendly - botões maiores
- Scroll otimizado

## ⚡ Performance

- **Polling eficiente** a cada 2 segundos
- Carrega apenas **novas mensagens** (last_id)
- **Índices no banco** para queries rápidas
- Atualização de status **assíncrona**
- Lazy loading de conversas

## 🔒 Segurança

- **Validação de sessão** em todas as APIs
- **Prepared statements** contra SQL Injection
- **Escape de HTML** contra XSS
- **Validação de permissões** ao apagar
- **Limite de tempo** para apagar para todos (1 hora)

## 🎯 Próximas Melhorias (Opcionais)

### Para implementar no futuro:
- [ ] Upload real de arquivos e imagens
- [ ] Seletor de emojis completo
- [ ] Stickers animados
- [ ] Chamadas de voz/vídeo
- [ ] Envio de localização
- [ ] Mensagens de áudio (WAV/MP3)
- [ ] Criptografia end-to-end
- [ ] Backup de conversas
- [ ] Temas (escuro/claro)
- [ ] Mensagens programadas
- [ ] Grupos de chat

## Arquitetura de Status de Mensagens

O sistema implementa confirmações de leitura estilo WhatsApp via Socket.IO.

### Estados e Ícones

| Estado | Ícone | CSS | Quando |
|--------|-------|-----|--------|
| Enviando | ⏰ | `.sending` | Durante o envio |
| Enviado | ✔ cinza | `.sent` | Salvo no servidor |
| Entregue | ✔✔ cinza | `.delivered` | Destinatário online |
| Lido | ✔✔ azul | `.read` | Destinatário abriu conversa |

### Fluxo (Destinatário Online)

```
Utilizador A envia mensagem
  → status: 'sent' (✔)
  → Salvo no banco de dados

Servidor verifica se B está online
  → status: 'delivered' (✔✔ cinza)
  → Emite 'message_status_update' para A

Utilizador B abre a conversa
  → join_conversation event
  → status: 'read' (✔✔ azul)
  → Emite 'message_status_update' para A
```

### Eventos Socket.IO

**Emitidos pelo cliente:**

| Evento | Dados | Descrição |
|--------|-------|-----------|
| `send_message` | `{ senderId, receiverId, content, replyToId, tempId }` | Envia mensagem |
| `join_conversation` | `{ conversationId, userId, otherUserId }` | Marca como lido |

**Recebidos pelo cliente:**

| Evento | Dados | Descrição |
|--------|-------|-----------|
| `message_sent` | `{ tempId, messageId, status }` | Confirma envio |
| `message_status_update` | `{ messageId, conversationId, status }` | Atualiza estado |
| `new_message` | `{ conversationId, message }` | Nova mensagem |

### Esquema da Base de Dados (tabela messages)

```sql
status ENUM('sent', 'delivered', 'read') DEFAULT 'sent'
```

### CSS dos Estados

```css
.message-status.sent     { color: #8696a0; }
.message-status.delivered{ color: #8696a0; }
.message-status.read     { color: #53bdeb; }  /* azul */
```

---

## 🐛 Troubleshooting

### Chat não carrega mensagens
- Verifique se as tabelas foram criadas
- Confirme que o usuário está logado
- Veja o console do navegador (F12)

### "Está digitando" não aparece
- Verifique se ambos usuários estão na mesma conversa
- Confirme que o polling está funcionando
- Teste em abas diferentes do navegador

### Mensagens não são marcadas como lidas
- Verifique se a API `mark_messages_read.php` está sendo chamada
- Confirme que o usuário tem uma conversa ativa
- Veja os logs do PHP

### Status online não atualiza
- Verifique se `update_online_status.php` está funcionando
- Confirme que o polling de 5 segundos está ativo
- Teste fechando e abrindo o navegador

## 💡 Dicas de Uso

1. **Testar com dois navegadores** diferentes (Chrome + Firefox)
2. **Abrir em abas anônimas** para simular usuários diferentes
3. **Monitorar o Network** (F12) para ver as chamadas API
4. **Verificar o Console** para erros JavaScript
5. **Usar dois dispositivos** (PC + celular) para testar melhor

## 📞 Suporte

Se tiver problemas:
1. Verifique os logs do PHP
2. Veja o console do navegador (F12)
3. Confirme que todas as tabelas foram criadas
4. Teste com `var_dump()` nas APIs

## 🎉 Pronto!

Seu sistema de chat está completo e funcional! Aproveite todas as funcionalidades tipo WhatsApp! 💬✨

---

**Desenvolvido com ❤️ para MyTube**
