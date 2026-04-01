# 🚀 Guia de Instalação Completo - MyTube

Este guia irá te ajudar a instalar e configurar o MyTube do zero em diferentes ambientes.

## 📦 Opção 1: Instalação com XAMPP (Windows)

### Passo 1: Baixar e Instalar XAMPP
1. Acesse https://www.apachefriends.org/
2. Baixe XAMPP para Windows (PHP 8.0+)
3. Execute o instalador como Administrador
4. Instale em `C:\xampp` (padrão)

### Passo 2: Configurar XAMPP
1. Abra o XAMPP Control Panel
2. Inicie os serviços **Apache** e **MySQL**
3. Aguarde os indicadores ficarem verdes

### Passo 3: Baixar MyTube
1. Extraia o projeto MyTube em `C:\xampp\htdocs\mytube`
2. A estrutura deve ficar: `C:\xampp\htdocs\mytube\index.php`

### Passo 4: Configurar Banco de Dados
1. Abra http://localhost/phpmyadmin
2. Clique em **"Novo"** no lado esquerdo
3. Digite o nome: `mytube_db`
4. Clique em **"Criar"**
5. Selecione a aba **"Importar"**
6. Clique em **"Escolher arquivo"**
7. Navegue até `C:\xampp\htdocs\mytube\database\mytube_structure.sql`
8. Clique em **"Executar"**

### Passo 5: Configurar Conexão
1. Abra `C:\xampp\htdocs\mytube\includes\config.php`
2. Edite as configurações:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // Deixe vazio para XAMPP padrão
```

### Passo 6: Testar Instalação
1. Abra http://localhost/mytube
2. Se ver a página inicial, está funcionando! 🎉
3. Use a conta padrão: `admin` / `admin123`

---

## 🐧 Opção 2: Instalação no Linux (Ubuntu/Debian)

### Passo 1: Instalar Dependências
```bash
sudo apt update
sudo apt install apache2 mysql-server php8.1 php8.1-mysql php8.1-curl php8.1-gd php8.1-mbstring
```

### Passo 2: Configurar Apache
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Passo 3: Configurar MySQL
```bash
sudo mysql_secure_installation
sudo mysql -u root -p
```

No MySQL, execute:
```sql
CREATE DATABASE mytube_db;
CREATE USER 'mytube_user'@'localhost' IDENTIFIED BY 'senha_segura';
GRANT ALL PRIVILEGES ON mytube_db.* TO 'mytube_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Passo 4: Instalar MyTube
```bash
cd /var/www/html
sudo git clone https://github.com/seu-usuario/mytube.git
sudo chown -R www-data:www-data mytube
sudo chmod -R 755 mytube
sudo chmod -R 775 mytube/uploads
```

### Passo 5: Configurar Banco
```bash
cd /var/www/html/mytube
mysql -u mytube_user -p mytube_db < database/mytube_structure.sql
```

### Passo 6: Configurar Aplicação
```bash
sudo nano includes/config.php
```

Edite:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube_db');
define('DB_USER', 'mytube_user');
define('DB_PASS', 'senha_segura');
```

---

## 🍎 Opção 3: Instalação no macOS

### Passo 1: Instalar Homebrew
```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

### Passo 2: Instalar Dependências
```bash
brew install php mysql
brew services start mysql
```

### Passo 3: Configurar MySQL
```bash
mysql_secure_installation
mysql -u root -p
```

Execute no MySQL:
```sql
CREATE DATABASE mytube_db;
EXIT;
```

### Passo 4: Instalar MyTube
```bash
cd /usr/local/var/www
git clone https://github.com/seu-usuario/mytube.git
cd mytube
mysql -u root -p mytube_db < database/mytube_structure.sql
```

### Passo 5: Iniciar Servidor
```bash
php -S localhost:8000 -t /usr/local/var/www/mytube
```

Acesse: http://localhost:8000

---

## 🔧 Configurações Avançadas

### Configurar FFmpeg (Opcional - para thumbnails automáticos)

**Windows:**
1. Baixe FFmpeg: https://ffmpeg.org/download.html
2. Extraia para `C:\ffmpeg`
3. Adicione `C:\ffmpeg\bin` ao PATH
4. Configure em `config_advanced.php`:
```php
define('FFMPEG_PATH', 'C:\ffmpeg\bin\ffmpeg.exe');
define('ENABLE_VIDEO_PROCESSING', true);
```

**Linux:**
```bash
sudo apt install ffmpeg
```

**macOS:**
```bash
brew install ffmpeg
```

### Configurar SSL/HTTPS (Produção)

**Apache (.htaccess já configurado):**
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name seu-dominio.com;
    return 301 https://$server_name$request_uri;
}
```

### Configurar Email (SMTP)

Edite `includes/config.php`:
```php
// Configurações de email
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'seu-email@gmail.com');
define('SMTP_PASSWORD', 'senha-do-app');
define('SMTP_ENCRYPTION', 'tls');
```

### Otimizar Performance

**PHP (php.ini):**
```ini
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
memory_limit = 256M
opcache.enable = 1
```

**MySQL (my.cnf):**
```ini
[mysqld]
innodb_buffer_pool_size = 256M
query_cache_type = 1
query_cache_size = 64M
```

---

## ❗ Solução de Problemas Comuns

### Erro: "Cannot connect to database"
1. Verifique se MySQL está rodando
2. Confirme credenciais em `config.php`
3. Teste conexão: `mysql -u root -p`

### Erro: "Permission denied" nos uploads
```bash
# Linux/Mac
chmod -R 775 uploads/
chown -R www-data:www-data uploads/

# Windows: Propriedades > Segurança > Dar controle total ao IIS_IUSRS
```

### Erro: "File too large"
**php.ini:**
```ini
upload_max_filesize = 50M
post_max_size = 50M
max_input_time = 300
```

Reinicie Apache após mudanças.

### Vídeos não reproduzem
1. Verifique se o arquivo existe em `uploads/videos/`
2. Confirme permissões de leitura
3. Teste formato de vídeo (MP4 é mais compatível)

### Página em branco
1. Ative display_errors no php.ini:
```ini
display_errors = On
error_reporting = E_ALL
```
2. Verifique logs de erro do Apache

---

## 🔒 Configurações de Segurança

### 1. Alterar Senhas Padrão
```sql
UPDATE users SET password = '$2y$10$novo_hash_aqui' WHERE username = 'admin';
```

### 2. Configurar Firewall
```bash
# Ubuntu/Debian
sudo ufw enable
sudo ufw allow 80
sudo ufw allow 443
sudo ufw allow 22
```

### 3. Configurar Backup Automático
```bash
# Script de backup (salve como backup.sh)
#!/bin/bash
mysqldump -u root -p mytube_db > backup_$(date +%Y%m%d).sql
tar -czf backup_files_$(date +%Y%m%d).tar.gz uploads/
```

### 4. Monitoramento de Logs
```bash
# Monitorar logs do Apache
tail -f /var/log/apache2/error.log

# Monitorar logs do MySQL
tail -f /var/log/mysql/error.log
```

---

## 📊 Testes de Funcionalidade

### Checklist de Testes:
- [ ] ✅ Página inicial carrega
- [ ] ✅ Cadastro de usuário funciona
- [ ] ✅ Login funciona
- [ ] ✅ Upload de vídeo funciona
- [ ] ✅ Reprodução de vídeo funciona
- [ ] ✅ Sistema de likes funciona
- [ ] ✅ Sistema de comentários funciona
- [ ] ✅ Navegação responsiva (mobile)
- [ ] ✅ Logout funciona

### Comandos de Teste:
```bash
# Testar conexão de banco
php -r "try { new PDO('mysql:host=localhost;dbname=mytube_db', 'root', ''); echo 'Conexão OK'; } catch(Exception $e) { echo 'Erro: ' . $e->getMessage(); }"

# Testar permissões de upload
touch uploads/videos/teste.txt && echo "Permissões OK" || echo "Erro de permissões"

# Testar configuração do Apache
apache2ctl configtest

# Testar PHP
php -m | grep -E "(pdo|mysql|gd|curl)"
```

---

## 🚀 Deploy em Produção

### 1. Configurar Domínio
- Aponte seu domínio para o IP do servidor
- Configure Virtual Host no Apache
- Instale certificado SSL (Let's Encrypt)

### 2. Otimizações para Produção
```php
// config.php - Produção
define('DEBUG_MODE', false);
define('MINIFY_ASSETS', true);
define('ENABLE_CACHE', true);
```

### 3. Configurar CDN (Opcional)
- Cloudflare para cache global
- AWS S3 para armazenamento de vídeos
- Configure URLs no `config.php`

---

**✨ Parabéns! Seu MyTube está pronto para uso!**

Para suporte adicional, consulte:
- 📖 [Documentação Completa](README.md)
- 🐛 [Reportar Problemas](https://github.com/seu-usuario/mytube/issues)
- 💬 [Comunidade Discord](#)

*Desenvolvido com ❤️ para conectar pessoas através de vídeos*