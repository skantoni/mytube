# MyTube Chat Server - Backend Node.js

Sistema de chat em tempo real para MyTube usando Socket.IO.

## 🚀 Requisitos

- Node.js 18+ (recomendado: 20 LTS)
- MySQL/MariaDB (já configurado com o MyTube)

## 📦 Instalação

### 1. Instalar Node.js

Se ainda não tem Node.js instalado:
- Windows: Baixe em https://nodejs.org/ (versão LTS)
- Ou use `winget install OpenJS.NodeJS.LTS`

### 2. Instalar dependências

Abra o terminal na pasta `chat-server` e execute:

```bash
cd c:\xampp\htdocs\my\chat-server
npm install
```

### 3. Configurar variáveis de ambiente

Edite o arquivo `.env` com suas configurações:

```env
PORT=3001
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=mytube
CORS_ORIGIN=http://localhost
```

### 4. Verificar estrutura do banco

Certifique-se de que as tabelas necessárias existem:
- `conversations`
- `messages`
- `typing_status`
- `user_online_status`

## 🏃 Executar

### Modo desenvolvimento (com auto-reload):
```bash
npm run dev
```

### Modo produção:
```bash
npm start
```

O servidor estará disponível em `http://localhost:3001`

## 📡 Eventos Socket.IO

### Eventos do Cliente → Servidor

| Evento | Dados | Descrição |
|--------|-------|-----------|
| `authenticate` | `{userId, username}` | Autenticar usuário |
| `join_conversation` | `{conversationId, userId}` | Entrar numa conversa |
| `leave_conversation` | `{conversationId, userId}` | Sair de uma conversa |
| `send_message` | `{senderId, receiverId, content, replyToId?}` | Enviar mensagem |
| `typing` | `{userId, conversationId, isTyping}` | Status de digitação |
| `delete_message` | `{messageId, userId}` | Deletar mensagem |
| `search_users` | `{query, userId}` | Buscar usuários |
| `get_conversations` | `{userId}` | Listar conversas |
| `load_more_messages` | `{conversationId, beforeId}` | Paginação |
| `start_conversation` | `{userId, targetUserId}` | Iniciar nova conversa |

### Eventos do Servidor → Cliente

| Evento | Dados | Descrição |
|--------|-------|-----------|
| `conversations_list` | `[conversas]` | Lista de conversas |
| `messages_list` | `{conversationId, messages}` | Mensagens da conversa |
| `new_message` | `{conversationId, message}` | Nova mensagem recebida |
| `message_sent` | `{tempId, messageId, conversationId}` | Confirmação de envio |
| `message_deleted` | `{messageId, conversationId}` | Mensagem deletada |
| `messages_read` | `{conversationId, readBy}` | Mensagens foram lidas |
| `typing_status` | `{conversationId, userId, isTyping}` | Alguém está digitando |
| `user_online` | `{userId, username, isOnline}` | Usuário ficou online |
| `user_offline` | `{userId, username, lastSeen}` | Usuário ficou offline |
| `search_results` | `[users]` | Resultado da busca |
| `error` | `{message}` | Erro ocorrido |

## 🔧 Estrutura do Projeto

```
chat-server/
├── package.json        # Dependências e scripts
├── .env               # Configurações (não versionar!)
├── .env.example       # Exemplo de configurações
├── server.js          # Servidor principal
├── config/
│   └── database.js    # Conexão MySQL
└── README.md          # Este arquivo
```

## 🐛 Troubleshooting

### Erro de conexão com MySQL
- Verifique se o MySQL está rodando (XAMPP)
- Confirme usuário/senha no `.env`

### CORS bloqueado
- Ajuste `CORS_ORIGIN` no `.env` para incluir a origem do seu front-end

### Porta em uso
- Mude a porta no `.env` ou encerre o processo usando a porta 3001

## 🔜 Próximos Passos

1. Executar `npm install` para instalar dependências
2. Iniciar servidor com `npm run dev`
3. Testar chat em http://localhost/my/chat.php

## 📝 Notas

- O servidor PHP existente continua funcionando para autenticação
- Socket.IO cuida apenas do chat em tempo real
- As mensagens são salvas no mesmo banco MySQL
