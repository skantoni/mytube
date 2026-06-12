# MyTube - Rede Social de Videos Estilo TikTok

![MyTube Logo](assets/images/logo.png)

Uma rede social moderna e completa para compartilhamento de videos, inspirada no TikTok, desenvolvida com PHP, MySQL, Node.js e JavaScript.

## Funcionalidades

### Core
- **Feed Estilo TikTok** - Rolagem vertical com reproducao automatica e scroll snap
- **Upload de Videos** - Suporte a multiplos formatos com validacao MIME, EXIF sanitization e streaming adaptativo
- **Sistema de Likes** - Curtir/descurtir videos em tempo real com double tap (mobile)
- **Sistema de Comentarios** - Comentarios em tempo real com contadores via DB triggers
- **Sistema de Seguir** - Seguir outros utilizadores
- **Contadores Automaticos** - Views, likes e comentarios atualizados via triggers MySQL

### Social
- **Chat Privado (1:1)** - Socket.IO com autenticacao JWT, read receipts, typing indicators
- **Chat de Grupo** - Salas geridas pelo Node.js com imagens de grupo
- **Sistema de Amizades** - Pedidos e aceitacoes
- **Notificacoes Push** - Web push notifications via VAPID
- **Rankings Semanais** - Best MyTuber por escola/global

### Seguranca e Admin
- **Autenticacao Segura** - bcrypt, CSRF protection, rate limiting, sessoes HttpOnly/SameSite
- **Moderacao de Conteudo** - Painel admin com roles (admin/moderator/vip/user)
- **Protecao SSRF** - Whitelist de dominios, validacao de IPs privados
- **Sanitizacao de Imagens** - Remocao de EXIF/GPS metadata
- **Prepared Statements** - 100% PDO com parametros bindados

### UX
- **Interface Responsiva** - Mobile-first design com tema azul moderno
- **Glassmorphism** - Efeitos de vidro com backdrop-filter
- **Keyboard Shortcuts** - Espaco (play/pause), M (som), L (like)
- **Progressive Loading** - Lazy loading e pre-carregamento inteligente de videos

## Tecnologias

| Camada | Tecnologia |
|--------|-----------|
| Backend | PHP 8.0+, PDO, PSR-4 autoloading |
| Base de Dados | MySQL 8.0+ (triggers, prepared statements) |
| Frontend | HTML5, CSS3 (Flexbox/Grid), JavaScript ES6+ |
| Chat | Node.js + Socket.IO + JWT |
| Storage | Cloudflare R2 (opcional), local filesystem |
| Icons | Font Awesome 6.0 |
| Arquitetura | MVC (Repository/Service/Controller), RESTful APIs |

## Pre-requisitos

- **XAMPP/WAMP/LAMP/Laragon** - Servidor local com PHP e MySQL
- **PHP 8.0+** com extensoes: PDO, PDO_MySQL, JSON, Session, GD/Imagick
- **MySQL 8.0+**
- **Node.js 18+** (para o servidor de chat)
- **Navegador moderno** (Chrome, Firefox, Safari, Edge)

## Instalacao

> Para instrucoes detalhadas por SO, consulte [docs/started/INSTALL.md](docs/started/INSTALL.md)

### 1. Clonar o Repositorio

```bash
git clone https://github.com/skantoni/mytube.git
cd mytube
```

### 2. Configurar Servidor Local

- Coloque o projeto na pasta `htdocs` (XAMPP) ou `www` (WAMP)
- Inicie Apache e MySQL

### 3. Criar a Base de Dados

```bash
mysql -u root -p -e "CREATE DATABASE mytube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p mytube < database/install.sql
```

Ou via **phpMyAdmin**:
1. Clique em **"Novo"** → nomeie `mytube` → charset `utf8mb4`
2. Seleccione a base de dados → **"Importar"** → escolha `database/install.sql`

### 4. Configurar Variaveis de Ambiente

```bash
cp .env.example .env
```

Edite o `.env` com as suas credenciais (DB, SMTP, R2, etc.).

### 5. Configurar o Servidor de Chat (Node.js)

```bash
cd chat-server
cp .env.example .env          # editar credenciais DB e CHAT_JWT_SECRET
npm install
npm start                     # ou: pm2 start ecosystem.config.js
```

Gerar JWT secret:
```bash
node -e "console.log(require('crypto').randomBytes(40).toString('hex'))"
```

### 6. Permissoes de Escrita (Linux/macOS)

```bash
chmod 755 uploads/ uploads/videos/ uploads/thumbnails/ uploads/avatars/
```

### 7. Aceder a Aplicacao

`http://localhost/mytube`

## Conta Padrao

Criada automaticamente pelo `install.sql`:
- **Utilizador**: `admin`
- **Password**: `admin123`
- **Email**: `admin@mytube.local`

> **Altere a password imediatamente apos a primeira entrada.**

## Estrutura do Projeto

```
mytube/
├── api/                    # APIs RESTful (PHP)
├── app/                    # Classes MVC (Repositories, Services, Validators)
├── assets/
│   ├── css/               # Estilos globais e por modulo
│   ├── js/                # JavaScript do cliente
│   └── images/
├── chat-server/            # Servidor de chat (Node.js + Socket.IO)
│   ├── server.js
│   ├── .env.example
│   └── ecosystem.config.js
├── database/
│   └── install.sql        # Script de instalacao limpa
├── docs/                   # Documentacao completa (ver docs/README.md)
├── includes/              # Configuracao e helpers PHP
│   ├── config.php         # Configuracao (via .env, nao versionada)
│   ├── csrf_helpers.php   # Protecao CSRF
│   ├── rate_limit.php     # Rate limiting
│   ├── upload_validation.php
│   └── ssrf_protection.php
├── migrations/            # Migracoes incrementais (historico)
├── uploads/               # Videos, thumbnails e avatares
├── index.php              # Feed principal
├── chat.php               # Pagina de chat
├── login.php / register.php
├── profile.php / perfil.php
├── ranking.php            # Rankings semanais
├── settings.php           # Definicoes do utilizador
└── upload.php             # Upload de videos
```

## Documentacao

Toda a documentacao esta organizada em `docs/` — consulte o [indice de documentacao](docs/README.md).

Destaques:
- [Guia de Instalacao](docs/started/INSTALL.md)
- [Deploy para Producao](docs/deployment/DEPLOY.md)
- [Sistema de Chat](docs/features/CHAT_README.md)
- [Auditoria de Seguranca](docs/SECURITY_AUDIT.md)
- [Sugestoes de Funcionalidades](docs/FEATURE_SUGGESTIONS.md)

## Roadmap

### Proximas Funcionalidades
- [ ] Autenticacao de dois fatores (2FA/TOTP)
- [ ] Painel de analytics para criadores
- [ ] Stories temporarios (24h)
- [ ] Filtros e efeitos nos videos
- [ ] Sistema de monetizacao
- [ ] API publica documentada

### Melhorias Tecnicas
- [ ] Cache Redis
- [ ] CDN para videos
- [ ] Migracao CSP para nonces (remover unsafe-inline)
- [ ] Testes automatizados (PHPUnit + Jest)
- [ ] CI/CD com GitHub Actions
- [ ] Migracao completa para TypeScript (frontend)

## Solucao de Problemas

### Erro de Conexao com Banco
1. Verifique se MySQL esta rodando
2. Confirme as credenciais no `.env`
3. Certifique-se que o banco `mytube` foi criado

### Videos nao aparecem
1. Verifique permissoes da pasta `uploads/`
2. Confirme se o arquivo foi enviado corretamente
3. Verifique logs de erro do PHP (`error_log`)

### Upload falha
1. Verifique `upload_max_filesize` e `post_max_size` no `php.ini`
2. Confirme se a pasta `uploads/videos/` existe e tem permissoes de escrita
3. Teste com arquivos menores (limite: 100MB)

### Chat nao conecta
1. Verifique se o servidor Node.js esta rodando (`npm start` em `chat-server/`)
2. Confirme que `CHAT_JWT_SECRET` esta configurado no `.env` do PHP e do chat-server
3. Verifique a consola do browser (F12) para erros de WebSocket

## Contribuicao

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudancas (`git commit -m 'feat: Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## Licenca

Este projeto esta sob a licenca MIT. Veja o arquivo `LICENSE` para detalhes.

## Suporte

- **GitHub Issues**: [Reportar Bug](https://github.com/skantoni/mytube/issues)
- **Documentacao**: [docs/README.md](docs/README.md)

---

**MyTube** - Feito com amor para conectar pessoas atraves de videos
