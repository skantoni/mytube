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

### 🎯 Funcionalidades em Desenvolvimento
- ⏳ Chat Privado (1:1)
- ⏳ Chat Público/Grupo
- ⏳ Páginas de Perfil Completas
- ⏳ Sistema de Busca
- ⏳ Notificações em Tempo Real

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

### 3. Configurar Banco de Dados

1. Acesse phpMyAdmin (http://localhost/phpmyadmin)
2. Crie um novo banco chamado `mytube_db`
3. Importe o arquivo `database/mytube_structure.sql`

```sql
-- Ou execute via linha de comando:
mysql -u root -p mytube_db < database/mytube_structure.sql
```

### 4. Configurar Conexão
Edite o arquivo `includes/config.php`:

```php
// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube_db');
define('DB_USER', 'root');        // Seu usuário MySQL
define('DB_PASS', '');            // Sua senha MySQL
```

### 5. Configurar Permissões
Certifique-se que as pastas tenham permissão de escrita:

```bash
chmod 755 uploads/
chmod 755 uploads/videos/
chmod 755 uploads/thumbnails/
```

### 6. Acessar a Aplicação
Abra seu navegador e acesse: `http://localhost/mytube`

## 👤 Conta Padrão

O sistema cria automaticamente uma conta de administrador:
- **Usuário**: `admin`
- **Senha**: `admin123`
- **Email**: `admin@mytube.com`

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
├── api/                    # APIs RESTful
│   ├── add_comment.php
│   ├── get_comments.php
│   ├── toggle_like.php
│   ├── toggle_follow.php
│   └── update_views.php
├── assets/                 # Recursos estáticos
│   ├── css/
│   │   ├── main.css       # Estilos globais
│   │   ├── auth.css       # Estilos de autenticação
│   │   ├── feed.css       # Estilos do feed
│   │   └── upload.css     # Estilos de upload
│   ├── js/
│   │   ├── auth.js        # JavaScript de autenticação
│   │   ├── feed.js        # JavaScript do feed
│   │   ├── upload.js      # JavaScript de upload
│   │   └── interactions.js # JavaScript de interações
│   └── images/            # Imagens do sistema
├── database/              # Scripts de banco
│   └── mytube_structure.sql
├── includes/              # Arquivos compartilhados
│   ├── config.php         # Configurações globais
│   └── header.php         # Header compartilhado
├── uploads/               # Arquivos enviados
│   ├── videos/            # Vídeos dos usuários
│   └── thumbnails/        # Miniaturas dos vídeos
├── index.php              # Página principal
├── login.php              # Página de login/cadastro
├── logout.php             # Script de logout
├── upload.php             # Página de upload
└── README.md              # Este arquivo
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