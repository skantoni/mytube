# Guia de Instalação — MyTube

> **Script de base de dados único:** `database/install.sql`
> Contém todas as tabelas, colunas e dados iniciais necessários.

---

## Pré-requisitos

| Componente | Versão mínima |
|---|---|
| PHP | 8.0 |
| MySQL / MariaDB | 8.0 / 10.5 |
| Node.js | 18 LTS |
| Apache / Nginx | qualquer recente |

Extensões PHP necessárias: `pdo_mysql`, `gd`, `mbstring`, `json`, `session`, `openssl`

---

## Opção 1 — XAMPP (Windows)

### 1. Preparar o XAMPP
1. Instale o [XAMPP](https://www.apachefriends.org/) com PHP 8.0+
2. Abra o XAMPP Control Panel e inicie **Apache** e **MySQL**
3. Extraia o projecto em `C:\xampp\htdocs\mytube`

### 2. Criar a base de dados
Abra `http://localhost/phpmyadmin`:
1. **"Novo"** → nome `mytube` → charset `utf8mb4_unicode_ci` → **Criar**
2. Clique em `mytube` → **"Importar"** → **"Escolher ficheiro"**
3. Seleccione `C:\xampp\htdocs\mytube\database\install.sql` → **"Executar"**

### 3. Configurar a ligação
```bash
copy includes\config.example.php includes\config.php
```
Edite `includes\config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube');
define('DB_USER', 'root');
define('DB_PASS', '');  // vazio no XAMPP por defeito
```

### 4. Configurar o servidor de chat
```bash
cd chat-server
copy .env.example .env
```
Edite `.env` (mínimo obrigatório):
```
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=mytube
CHAT_JWT_SECRET=<string-aleatoria-longa>
CORS_ORIGIN=http://localhost
```
```bash
npm install
npm start
```

### 5. Testar
Aceda a `http://localhost/mytube`
- Utilizador: `admin` | Password: `admin123` → **altere imediatamente**

---

## Opção 2 — Linux (Ubuntu/Debian)

### 1. Instalar dependências
```bash
sudo apt update
sudo apt install apache2 mysql-server php8.1 php8.1-mysql php8.1-gd \
                 php8.1-mbstring php8.1-curl php8.1-xml nodejs npm
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 2. Criar base de dados e utilizador MySQL
```bash
sudo mysql -u root <<'SQL'
CREATE DATABASE mytube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mytube_user'@'localhost' IDENTIFIED BY 'senha_segura';
GRANT ALL PRIVILEGES ON mytube.* TO 'mytube_user'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### 3. Instalar o projecto
```bash
cd /var/www/html
sudo git clone https://github.com/seu-usuario/mytube.git
sudo chown -R www-data:www-data mytube
sudo chmod -R 755 mytube
sudo chmod -R 775 mytube/uploads
```

### 4. Importar a base de dados
```bash
mysql -u mytube_user -p mytube < /var/www/html/mytube/database/install.sql
```

### 5. Configurar a aplicação PHP
```bash
cd /var/www/html/mytube
sudo cp includes/config.example.php includes/config.php
sudo nano includes/config.php
```
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mytube');
define('DB_USER', 'mytube_user');
define('DB_PASS', 'senha_segura');
```

### 6. Configurar e iniciar o servidor de chat
```bash
cd chat-server
cp .env.example .env && nano .env
npm install
# Para produção com PM2:
npm install -g pm2
pm2 start ecosystem.config.js
pm2 save && pm2 startup
```

---

## Opção 3 — macOS (Homebrew)

```bash
brew install php mysql node
brew services start mysql

# Criar base de dados
mysql -u root -e "CREATE DATABASE mytube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root mytube < database/install.sql

# Configurar app
cp includes/config.example.php includes/config.php
# editar config.php com o editor preferido

# Servidor de chat
cd chat-server && cp .env.example .env
npm install && npm start

# Servidor PHP local
php -S localhost:8000 -t /usr/local/var/www/mytube
```
Aceda a `http://localhost:8000`

---

## Configurações opcionais

### PHP (php.ini) — para upload de vídeos grandes
```ini
upload_max_filesize = 100M
post_max_size       = 100M
max_execution_time  = 300
memory_limit        = 256M
```

### Email / SMTP (`includes/config.php`)
```php
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
define('SMTP_USERNAME',   'seu-email@gmail.com');
define('SMTP_PASSWORD',   'senha-de-app');
define('SMTP_ENCRYPTION', 'tls');
```

### Push Notifications (VAPID)
```bash
cd chat-server
npx web-push generate-vapid-keys
```
Adicione as chaves ao `.env` e a `includes/push_config.php`.

### SSL / HTTPS (produção)
```nginx
server {
    listen 443 ssl;
    server_name mytube.social;
    # ... configuração Nginx no ficheiro DEPLOY.md
}
```

---

## Segurança pós-instalação

1. **Alterar a password do admin**
   ```sql
   UPDATE users
   SET password = '<hash>'
   WHERE username = 'admin';
   ```
   Gerar hash: `php -r "echo password_hash('nova_senha', PASSWORD_BCRYPT);"`

2. **Configurar `CHAT_JWT_SECRET`** com uma string aleatória forte (≥ 32 caracteres)

3. **Desactivar o modo debug** em `includes/config.php`:
   ```php
   define('DEBUG_MODE', false);
   ```

4. **Permissões de ficheiros** (Linux):
   ```bash
   chmod 640 includes/config.php
   chmod 640 chat-server/.env
   ```

---

## Resolução de problemas

| Erro | Solução |
|---|---|
| `Cannot connect to database` | Verifique credenciais em `includes/config.php` e se o MySQL está a correr |
| `Table 'mytube.X' doesn't exist` | Re-importe `database/install.sql` na base de dados correcta |
| Upload falha | Aumente `upload_max_filesize` no `php.ini` e verifique permissões de `uploads/` |
| Chat não liga | Verifique se o servidor Node está a correr (`npm start` em `chat-server/`) e confirme `CORS_ORIGIN` |
| Password do admin não funciona | Gere um novo hash com PHP e actualize via SQL (ver secção Segurança) |

```bash
# Testar ligação ao MySQL
php -r "try { new PDO('mysql:host=localhost;dbname=mytube', 'root', ''); echo 'OK'; } catch(Exception \$e) { echo \$e->getMessage(); }"

# Verificar extensões PHP
php -m | grep -E 'pdo|mysql|gd|mbstring'
```

---

*Para mais detalhes sobre deploy em produção consulte [DEPLOY.md](DEPLOY.md) e [VPS_DEPLOY.md](VPS_DEPLOY.md).*
