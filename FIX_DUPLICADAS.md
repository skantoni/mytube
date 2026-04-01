# 🔧 Correções: Mensagens Duplicadas e Badge

## ✅ Correções Aplicadas

### 1. **Mensagens Duplicadas - CORRIGIDO**

#### Causa:
- `sendMessage()` adicionava mensagem otimisticamente
- Servidor emitia `new_message` para TODOS na sala
- `handleNewMessage()` recebia e adicionava novamente

#### Solução:
**[chat-socket.js](chat-socket.js#L390-L407)** - Ignorar mensagens próprias:
```javascript
function handleNewMessage(data) {
    // Ignorar se for mensagem própria (já foi adicionada otimisticamente)
    if (data.message.sender_id === currentUserId) {
        console.log('📨 Ignorando new_message própria');
        return;
    }
    
    console.log('📨 Nova mensagem recebida de outro usuário');
    appendMessage(data.message);
}
```

**[chat-socket.js](chat-socket.js#L985-L1001)** - Verificar duplicatas no DOM:
```javascript
function appendMessage(msg, isTemp = false) {
    // Verificar se mensagem já existe
    const messageId = isTemp ? msg.tempId : msg.id;
    const selector = isTemp ? `[data-temp-id="${messageId}"]` : `[data-message-id="${messageId}"]`;
    const existingMessage = chatMessages.querySelector(selector);
    
    if (existingMessage) {
        console.log('⚠️ Mensagem já existe no DOM, ignorando:', messageId);
        return;
    }
    
    console.log('➕ Adicionando mensagem ao DOM:', messageId);
    chatMessages.appendChild(createMessageElement(msg, isTemp));
}
```

### 2. **Badge Não Desaparece - CORRIGIDO**

#### Causa:
- Event listener duplicado no `chat.php` chamava `openChat()` múltiplas vezes
- Badge não era removido do DOM ao abrir conversa

#### Solução:
**[chat.php](chat.php#L247)** - Removido event listener duplicado:
```javascript
// REMOVIDO:
// document.addEventListener('click', function(e) { ... });

// Agora só há um listener em chat-socket.js
```

**[chat-socket.js](chat-socket.js#L156-L169)** - Event delegation centralizado:
```javascript
function setupConversationClickListener() {
    const conversationsList = document.getElementById('conversationsList');
    conversationsList.addEventListener('click', function(e) {
        const conversationItem = e.target.closest('.conversation-item');
        if (conversationItem) {
            const userId = parseInt(conversationItem.dataset.userId);
            console.log('🖱️ Clique na conversa:', userId);
            openChat(userId);  // Chamado apenas uma vez
        }
    });
}
```

**[chat-socket.js](chat-socket.js#L1354-L1360)** - Limpar badge ao abrir:
```javascript
function openChat(userId) {
    // ...
    if (conversationItem) {
        conversationItem.classList.add('active');
        
        // Limpar badge de mensagens não lidas
        const badge = conversationItem.querySelector('.unread-badge');
        if (badge) {
            badge.remove();
        }
    }
}
```

### 3. **Logs de Debug Adicionados**

**Cliente (chat-socket.js):**
```javascript
console.log('📤 ENVIANDO mensagem:', message);
console.log('🚀 Socket.emit send_message com tempId:', tempId);
console.log('📨 Ignorando new_message própria');
console.log('📨 Nova mensagem recebida de outro usuário');
console.log('⚠️ Mensagem já existe no DOM, ignorando:', messageId);
console.log('➕ Adicionando mensagem ao DOM:', messageId);
console.log('🖱️ Clique na conversa:', userId);
```

**Servidor (server.js):**
```javascript
console.log(`📥 Recebido send_message - tempId: ${tempId}`);
console.log(`💾 Mensagem salva: ID ${messageId}`);
```

## 🧪 Como Testar

### Teste 1: Mensagens Duplicadas
1. Abra console (F12)
2. Envie uma mensagem
3. **Resultado esperado:**
   ```
   📤 ENVIANDO mensagem: teste
   🚀 Socket.emit send_message com tempId: 1
   ✉️ Confirmação de envio recebida
   📨 Ignorando new_message própria
   ```
4. **Apenas UMA mensagem aparece no chat**

### Teste 2: Badge
1. Usuário A envia mensagem para B
2. Usuário B vê badge com "1"
3. Usuário B clica na conversa
4. **Resultado esperado:**
   ```
   🖱️ Clique na conversa: 1
   ```
5. **Badge desaparece IMEDIATAMENTE**

### Teste 3: Destinatário Recebe Mensagem
1. Usuário A envia: "olá"
2. Console do Usuário B mostra:
   ```
   📨 Nova mensagem recebida de outro usuário
   ➕ Adicionando mensagem ao DOM: [id]
   ```
3. **Mensagem aparece apenas UMA vez**

## 📊 Logs Esperados

### Ao Enviar Mensagem

**Console do Remetente:**
```
📤 ENVIANDO mensagem: teste
🚀 Socket.emit send_message com tempId: 1
✉️ Confirmação de envio recebida: {tempId: 1, messageId: 103}
✅ Mensagem temporária 1 → ID real: 103
📨 Ignorando new_message própria (já adicionada otimisticamente)
```

**Console do Servidor:**
```
📥 Recebido send_message - tempId: 1, sender: 3, content: "teste"
💾 Mensagem salva: ID 103, conversa 14
📬 Mensagem 103 marcada como DELIVERED (destinatário online)
```

**Console do Destinatário:**
```
📨 Nova mensagem recebida de outro usuário
➕ Adicionando mensagem ao DOM: 103
```

### Ao Clicar em Conversa

**Console:**
```
🖱️ Clique na conversa: 3
📖 Marcando N mensagens como READ na conversa 14
```

**Badge desaparece do DOM!**

## ✅ Resultado Final

- ✅ Mensagens aparecem apenas UMA vez
- ✅ Badge desaparece na primeira vez que abre conversa
- ✅ Status funciona em tempo real (✔ → ✔✔ → ✔✔ azul)
- ✅ Sem event listeners duplicados
- ✅ Sistema PHP antigo desabilitado (.OLD)

---

**Teste agora com Ctrl+F5 para limpar cache!** 🚀
