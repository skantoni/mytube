# MyTube - Rede Social de Vídeos Estilo TikTok

![MyTube Logo](assets/images/logo.png)

Uma rede social moderna e completa para compartilhamento de vídeos, inspirada no TikTok, desenvolvida com PHP, MySQL, HTML5, CSS3 e JavaScript.

## 🚀 Características Principais

### ✨ Funcionalidades Implementadas
- ✅ **Sistema de Autenticação** - Cadastro, login e logout seguros
- ✅ **Upload de Vídeos** - Suporte a múltiplos formatos com validação
- ✅ **Feed Estilo TikTok** - Rolagem vertical com reprodução automática
- ✅ **Sistema de Likes** - Curtir/descurtir vídeos em tempo real
- ✅ **Sistema de Comentários** - Comentar e visualizar comentários
- ✅ **Sistema de Seguir** - Seguir outros usuários
- ✅ **Contadores Automáticos** - Views, likes e comentários atualizados via triggers
- ✅ **Interface Responsiva** - Mobile-first design
- ✅ **Tema Azul Moderno** - Design inovador e atrativo

### 🎯 Funcionalidades Adicionais
- ✅ **Chat Privado (1:1)** — Socket.IO com JWT
- ✅ **Chat de Grupo** — salas geridas pelo Node.js
- ✅ **Sistema de Amizades** — pedidos e aceitações
- ✅ **Notificações em Tempo Real** — push notifications (VAPID)
- ✅ **Rankings Semanais** — Best MyTuber por escola/global
- ✅ **Moderação de Conteúdo** — painel admin

## 🛠️ Tecnologias Utilizadas

- **Backend**: PHP 8.0+
- **Banco de Dados**: MySQL 8.0+
- **Frontend**: HTML5, CSS3 (Flexbox/Grid), JavaScript ES6+
- **Bibliotecas**: Font Awesome 6.0
- **Arquitetura**: MVC Pattern, RESTful APIs

## 📋 Pré-requisitos

- **XAMPP/WAMP/LAMP** - Servidor local com PHP e MySQL
- **PHP 8.0+** com extensões:
  - PDO
  - PDO_MySQL
  - JSON
  - Session
- **MySQL 8.0+**
- **Navegador moderno** (Chrome, Firefox, Safari, Edge)

## 🔧 Instalação

### 1. Clone o Repositório
```bash
git clone https://github.com/seu-usuario/mytube.git
cd mytube
```

### 2. Configurar Servidor Local
- Coloque o projeto na pasta `htdocs` (XAMPP) ou `www` (WAMP)
- Inicie Apache e MySQL

### 3. Criar a Base de Dados

```bash
# Via linha de comando (recomendado):
mysql -u root -p -e "CREATE DATABASE mytube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p mytube < database/install.sql
```

Ou via **phpMyAdmin**:
1. Clique em **"Novo"** → nomeie `mytube` → charset `utf8mb4`
2. Seleccione a base de dados → **"Importar"** → escolha `database/install.sql`

### 4. Configurar a Ligação
Copie o exemplo e edite `includes/config.php`:

```bash
cp includes/config.example.php includes/config.php
```

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube');
define('DB_USER', 'root');   // utilizador MySQL
define('DB_PASS', '');       // password MySQL
```

### 5. Configurar o Servidor de Chat (Node.js)
```bash
cd chat-server
cp .env.example .env          # editar credenciais DB e CHAT_JWT_SECRET
npm install
npm start                     # ou: pm2 start ecosystem.config.js
```

### 6. Permissões de Escrita (Linux/macOS)
```bash
chmod 755 uploads/ uploads/videos/ uploads/thumbnails/
```

### 7. Aceder à Aplicação
`http://localhost/mytube`

## 👤 Conta Padrão

Criada automaticamente pelo `install.sql`:
- **Utilizador**: `admin`
- **Password**: `admin123`  ⚠️ **Altere após a primeira entrada**
- **Email**: `admin@mytube.local`

Para alterar a password via MySQL:
```sql
UPDATE users
SET password = '<hash_gerado_por_php>'
WHERE username = 'admin';
```
Gerar hash: `php -r "echo password_hash('nova_senha', PASSWORD_BCRYPT);"`

## 📱 Como Usar

### Para Visitantes
1. Acesse a página inicial para ver vídeos públicos
2. Crie uma conta gratuita ou faça login
3. Explore o feed de vídeos com scroll vertical

### Para Usuários Registrados
1. **Upload de Vídeos**:
   - Clique em "Upload" no menu
   - Arraste e solte seu vídeo ou clique para selecionar
   - Adicione título, descrição e hashtags
   - Publique seu vídeo

2. **Interações**:
   - 👆 Clique para pausar/reproduzir vídeos
   - ❤️ Clique no coração para curtir
   - 💬 Clique no balão para comentar
   - 👥 Clique em "Seguir" para seguir usuários
   - 🔊 Clique no som para ativar/desativar áudio

3. **Navegação**:
   - 📱 **Mobile**: Deslize para cima/baixo para trocar vídeos
   - 💻 **Desktop**: Use scroll do mouse ou setas do teclado
   - ⌨️ **Atalhos**: Espaço (play/pause), M (som), L (like)

## 🎨 Estrutura do Projeto

```
mytube/
├── api/                    # APIs RESTful (PHP)
├── assets/
│   ├── css/               # Estilos globais e por módulo
│   ├── js/                # JavaScript do cliente
│   └── images/
├── chat-server/            # Servidor de chat (Node.js + Socket.IO)
│   ├── server.js
│   ├── .env.example
│   └── ecosystem.config.js
├── database/
│   └── install.sql        # Script de instalação limpa (usar este)
├── includes/              # Configuração e helpers PHP
│   ├── config.example.php # Exemplo de configuração
│   └── config.php         # Configuração real (não versionada)
├── migrations/            # Migrações incrementais (histórico)
├── uploads/               # Vídeos, thumbnails e avatares
├── chat.php               # Página de chat
├── index.php              # Feed principal
├── login.php              # Autenticação
└── profile.php            # Perfis de utilizadores
```

## 🔒 Segurança Implementada

- **Autenticação Segura**: Hash de senhas com `password_hash()`
- **Proteção CSRF**: Validação de origem das requisições
- **Sanitização**: Limpeza de dados de entrada
- **Validação de Arquivos**: Verificação de tipos e tamanhos
- **Prepared Statements**: Proteção contra SQL Injection
- **Sessões Seguras**: Controle de acesso baseado em sessões

## 🎯 Recursos Inovadores

### Design Diferenciado
- **Tema Azul Moderno**: Gradientes e cores harmoniosas
- **Animações Suaves**: Transições CSS3 avançadas
- **Glassmorphism**: Efeitos de vidro com backdrop-filter
- **Micro-interações**: Feedback visual em todas as ações

### Experiência do Usuário
- **Double Tap to Like**: Duplo toque para curtir (mobile)
- **Keyboard Shortcuts**: Atalhos de teclado para power users
- **Scroll Snap**: Navegação suave entre vídeos
- **Progressive Loading**: Carregamento otimizado
- **Auto-save Drafts**: Salvar rascunhos automaticamente

### Performance
- **Lazy Loading**: Carregamento sob demanda
- **Video Optimization**: Pré-carregamento inteligente
- **Database Triggers**: Atualizações automáticas de contadores
- **AJAX Real-time**: Interações sem recarregar página

## 📈 Roadmap Futuro

### Próximas Funcionalidades
- [ ] Sistema de Chat em Tempo Real (WebSockets)
- [ ] Stories temporários (24h)
- [ ] Filtros e efeitos nos vídeos
- [ ] Sistema de monetização
- [ ] Analytics detalhados
- [ ] API pública
- [ ] App mobile (React Native)

### Melhorias Técnicas
- [ ] Cache Redis
- [ ] CDN para vídeos
- [ ] Compressão automática
- [ ] Push notifications
- [ ] PWA (Progressive Web App)
- [ ] Testes automatizados

## 🐛 Solução de Problemas

### Erro de Conexão com Banco
1. Verifique se MySQL está rodando
2. Confirme as credenciais em `includes/config.php`
3. Certifique-se que o banco `mytube_db` foi criado

### Vídeos não aparecem
1. Verifique permissões da pasta `uploads/`
2. Confirme se o arquivo foi enviado corretamente
3. Verifique logs de erro do PHP

### Upload falha
1. Verifique `upload_max_filesize` no php.ini
2. Confirme se a pasta `uploads/videos/` existe
3. Teste com arquivos menores

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## 📄 Licença

Este projeto está sob a licença MIT. Veja o arquivo `LICENSE` para detalhes.

## 📞 Suporte

- **Email**: suporte@mytube.com
- **GitHub Issues**: [Reportar Bug](https://github.com/seu-usuario/mytube/issues)
- **Documentação**: [Wiki do Projeto](https://github.com/seu-usuario/mytube/wiki)

---

**MyTube** - Feito com ❤️ para conectar pessoas através de vídeos

*Desenvolvido usando as melhores práticas de desenvolvimento web moderno*