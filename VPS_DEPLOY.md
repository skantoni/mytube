# 🚀 Guia de Deploy para VPS

Este arquivo contém as instruções para aplicar mudanças do código local para o servidor de produção (VPS).

---

## 📋 PARA ESTA ATUALIZAÇÃO ESPECÍFICA (Variáveis de Ambiente)

### Passo 1: Conectar na VPS
```bash
ssh seu-usuario@seu-servidor-ip
cd /caminho/do/site
```

### Passo 2: Fazer backup das credenciais antigas
```bash
# Backup dos arquivos de config antigos (caso precise reverter)
cp includes/config.php includes/config.php.backup
cp includes/r2_config.php includes/r2_config.php.backup
cp includes/mail_config.php includes/mail_config.php.backup
```

### Passo 3: Puxar as alterações do Git
```bash
git pull origin main
# ou: git pull origin master (dependendo do nome da branch)
```

### Passo 4: Criar o arquivo .env em produção
```bash
# Copiar o template
cp .env.example .env

# Editar com as credenciais de PRODUÇÃO
nano .env
```

**⚠️ IMPORTANTE:** No arquivo `.env` da VPS, você deve colocar:
- As credenciais de **produção** (não localhost)
- URL real do site (não http://localhost)
- Credenciais R2 **NOVAS** (depois de revogar as antigas)
- Senha de app Gmail **NOVA** (depois de revogar a antiga)

Exemplo de `.env` para produção:
```env
# DATABASE (credenciais do banco de produção)
DB_HOST=localhost
DB_NAME=mytube_db_prod
DB_USER=seu_usuario_db
DB_PASS=senha_segura_aqui

# SITE (URL real)
SITE_URL=https://seu-dominio.com

# CLOUDFLARE R2 (chaves NOVAS)
R2_ENDPOINT=https://ed25991d995278a947d7f91d80dab70e.r2.cloudflarestorage.com
R2_ACCESS_KEY_ID=nova_chave_r2_aqui
R2_SECRET_ACCESS_KEY=novo_secret_r2_aqui
R2_BUCKET=mytube-videos
R2_PUBLIC_URL=https://pub-8a68dfd4c2504827a549f70e9606ae3e.r2.dev

# EMAIL (senha NOVA)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=skenito2@gmail.com
MAIL_PASSWORD=nova_senha_app_16_chars
MAIL_FROM_EMAIL=skenito2@gmail.com
MAIL_FROM_NAME=MyTube

# SECURITY (gerar novos secrets)
CRON_SECRET=gere_um_secret_aleatorio_64_chars
JWT_SECRET=gere_outro_secret_aleatorio_64_chars

# SESSION (2 horas em produção)
SESSION_LIFETIME=7200
```

Para gerar os secrets no servidor:
```bash
# Gerar CRON_SECRET
openssl rand -hex 32

# Gerar JWT_SECRET
openssl rand -hex 32
```

### Passo 5: Proteger o arquivo .env
```bash
# Permissões corretas: dono lê/escreve, grupo lê (www-data precisa ler)
chmod 640 .env
sudo chown skeny:www-data .env

# Verificar permissões
ls -la .env
# Deve mostrar: -rw-r----- 1 skeny www-data (640)

# Testar se www-data consegue ler
sudo -u www-data cat .env
```

### Passo 6: Testar se carrega corretamente
```bash
# Verificar se não há erros de PHP
php -l includes/env_loader.php
php -l includes/config.php
php -l includes/r2_config.php
php -l includes/mail_config.php
```

### Passo 7: Reiniciar PHP-FPM
```bash
sudo systemctl restart php8.1-fpm
# ou
sudo systemctl restart php-fpm

# Verificar status
sudo systemctl status php8.1-fpm
```

### Passo 8: Limpar cache (se houver)
```bash
rm -rf cache/rankings/*
```

### Passo 9: Testar o site
Acesse o site no navegador e teste:
- ✅ Login/Logout
- ✅ Upload de vídeo (testa R2)
- ✅ Reset de senha (testa email)
- ✅ Qualquer funcionalidade crítica

### Passo 10: Verificar logs de erro
```bash
# Logs do PHP
tail -f /var/log/php8.1-fpm/error.log

# Logs do Nginx
tail -f /var/log/nginx/error.log

# Logs da aplicação (se existir)
tail -f logs/*.log
```

---

## 📋 DEPLOY: Validação MIME de Uploads (19/04/2026)

### O que mudou:
- ✅ Criado sistema de validação segura de uploads (MIME type + extensão)
- ✅ Arquivo criado: `includes/upload_validation.php`
- ✅ Modificados: `profile.php`, `upload.php`, `includes/chat_upload_config.php`
- ✅ Removidos tipos de arquivo perigosos do chat (svg, html, js, xml)

### Passo 1: Conectar na VPS
```bash
ssh skeny@seu-servidor
cd /var/www/mytube.social
```

### Passo 2: Puxar as alterações
```bash
git pull origin main
```

### Passo 3: Verificar arquivo novo
```bash
# Verificar se o arquivo foi criado
ls -la includes/upload_validation.php

# Verificar sintaxe
php -l includes/upload_validation.php
php -l profile.php
php -l upload.php
php -l includes/chat_upload_config.php
```

### Passo 4: Atualizar config.php manualmente
O arquivo `includes/config.php` NÃO está no git (.gitignore).

**Certifique-se que estas linhas estão presentes (devem estar do deploy anterior):**

```bash
nano includes/config.php
```

Verificar se tem estas 2 linhas **DEPOIS de session_start()**:
```php
// Carregar helpers de CSRF (proteção contra Cross-Site Request Forgery)
require_once __DIR__ . '/csrf_helpers.php';
```

Se não tiver, adicionar manualmente. Salvar: Ctrl+X, Y, Enter.

### Passo 5: Reiniciar PHP-FPM
```bash
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm
```

### Passo 6: Testar uploads
Acesse o site e teste:

1. **Upload de Avatar:**
   - Ir para perfil
   - Tentar fazer upload de imagem legítima (JPG, PNG) → ✅ deve funcionar
   - Tentar fazer upload de arquivo .txt renomeado para .jpg → ❌ deve ser rejeitado

2. **Upload de Vídeo:**
   - Tentar fazer upload de vídeo legítimo → ✅ deve funcionar
   - Vídeo deve processar normalmente

3. **Chat (se aplicável):**
   - Enviar imagem no chat → ✅ deve funcionar
   - Tipos perigosos (svg, html) → ❌ não são mais aceitos

### Passo 7: Verificar logs
```bash
# Ver se não há erros
tail -f /var/log/php8.3-fpm/error.log
tail -f /var/log/nginx/error.log
```

### ⚠️ Problemas comuns:

**Erro: "Call to undefined function finfo_open()"**
- Causa: Extensão fileinfo não instalada
- Solução:
```bash
# Verificar se fileinfo está habilitado
php -m | grep fileinfo

# Se não estiver, instalar
sudo apt install php8.3-fileinfo
sudo systemctl restart php8.3-fpm
```

**Upload rejeitado com erro "MIME type não permitido"**
- Isso é normal se o arquivo for suspeito
- Verifique se está enviando imagem/vídeo legítimo
- Logs devem mostrar o MIME type detectado

**Avatar não aparece após upload**
- Verificar permissões da pasta:
```bash
chmod 755 assets/images/avatars/
chown -R www-data:www-data assets/images/avatars/
```

---

## 📋 DEPLOY: Proteção Rate Limiting (20/04/2026)

### O que mudou:
- ✅ Proteção contra brute force em login e reset de senha
- ✅ Arquivo criado: `includes/rate_limit.php`
- ✅ Arquivos modificados: `login.php`, `api/verify_reset_code.php`
- ✅ Migração: `database/migration_rate_limits.sql` (tabela rate_limits)

### Passo 1: Conectar na VPS
```bash
ssh skeny@mytube.social
cd /var/www/mytube.social
```

### Passo 2: Backup
```bash
# Backup dos arquivos antes de atualizar
sudo cp login.php login.php.backup-$(date +%Y%m%d)
sudo cp api/verify_reset_code.php api/verify_reset_code.php.backup-$(date +%Y%m%d)
```

### Passo 3: Puxar as alterações
```bash
git pull origin main
```

### Passo 4: Criar tabela rate_limits
```bash
# Executar migração do banco
mysql -u seu_usuario -p mytube_db < database/migration_rate_limits.sql

# OU manualmente via MySQL
mysql -u seu_usuario -p
```

```sql
USE mytube_db;

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `action` VARCHAR(50) NOT NULL COMMENT 'Tipo: login, login_user, reset_code, reset_code_email',
    `identifier` VARCHAR(255) NOT NULL COMMENT 'IP ou email',
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_action_identifier` (`action`, `identifier`),
    KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verificar se foi criada
SHOW TABLES LIKE 'rate_limits';
DESCRIBE rate_limits;
exit
```

### Passo 5: Verificar sintaxe
```bash
php -l includes/rate_limit.php
php -l login.php
php -l api/verify_reset_code.php
```

### Passo 6: Reiniciar PHP-FPM
```bash
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm
```

### Passo 7: Testar rate limiting

**Teste 1 - Login:**
1. Ir para https://mytube.social/login.php
2. Tentar fazer login 6 vezes com senha ERRADA
3. Após 5 tentativas, deve mostrar:
   - "Usuário ou senha incorretos. (1 tentativas restantes)"
   - "Muitas tentativas de login. Tente novamente em 15 minutos."
4. ✅ Login deve estar bloqueado

**Teste 2 - Reset de senha:**
1. "Esqueci minha senha" → inserir email
2. Tentar 6 códigos ERRADOS
3. Após 5 tentativas, deve mostrar:
   - "Muitas tentativas. Tente novamente em 15 minutos."
4. ✅ Verificação de código deve estar bloqueada

**Teste 3 - Desbloqueio:**
1. Aguardar 15 minutos (ou limpar banco)
2. Tentar login com credenciais CORRETAS
3. ✅ Login deve funcionar normalmente

### Passo 8: Limpar rate limits manualmente (se necessário)
```bash
# Para desbloquear durante testes
mysql -u seu_usuario -p mytube_db -e "DELETE FROM rate_limits WHERE identifier = 'SEU_IP';"

# Ver bloqueios ativos
mysql -u seu_usuario -p mytube_db -e "SELECT * FROM rate_limits ORDER BY attempted_at DESC LIMIT 10;"
```

### Passo 9: Configurar limpeza automática (cron)
```bash
# Adicionar ao crontab para limpar registros antigos (1x por dia)
crontab -e

# Adicionar linha:
0 3 * * * php /var/www/mytube.social/cleanup_rate_limits.php >> /var/log/mytube_cleanup.log 2>&1
```

Criar `cleanup_rate_limits.php`:
```php
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/rate_limit.php';

$deleted = rate_limit_cleanup($pdo, 7); // Manter 7 dias
echo "Deleted $deleted old rate limit records\n";
```

### ⚠️ Problemas comuns:

**Erro: "Argument #1 ($pdo) must be of type PDO, LazyPDO given"**
- Causa: Type hint estrito `PDO` em função que recebe LazyPDO wrapper
- Solução: CORRIGIDO - Type hints PDO removidos do rate_limit.php
- Se acontecer em outros arquivos: remover `PDO` do parâmetro, deixar apenas `$pdo`
- Referência: Este projeto usa LazyPDO (includes/config.php)

**Erro: "Table 'rate_limits' doesn't exist"**
- Causa: Migração não foi executada
- Solução: Executar migration_rate_limits.sql manualmente

**Sempre bloqueado mesmo após 15 minutos:**
- Verificar fuso horário do servidor: `date`
- Verificar registros no banco:
```sql
SELECT * FROM rate_limits WHERE identifier = 'SEU_IP' ORDER BY attempted_at DESC;
```
- Se necessário, deletar manualmente

**Rate limiting não funciona (sem bloqueio):**
- Verificar se tabela existe: `SHOW TABLES LIKE 'rate_limits';`
- Verificar logs PHP: `sudo tail -f /var/log/php8.3-fpm/error.log`
- Verificar se includes/rate_limit.php foi carregado

**IP sempre diferente (Cloudflare/proxy):**
- Verificar se `HTTP_CF_CONNECTING_IP` está sendo capturado
- Testar: `php -r "echo rate_limit_get_client_ip();"`

---

## 📋 DEPLOY: Correção Token Reset Exposto (20/04/2026)

### O que mudou:
- ✅ Token de reset NÃO é mais exposto ao cliente
- ✅ Arquivos modificados: `api/verify_reset_code.php`, `api/reset_password.php`, `assets/js/auth.js`
- ✅ Validação 100% baseada em sessão (servidor-side)
- ✅ Previne interceptação, XSS, logs

### Passo 1: Conectar na VPS
```bash
ssh skeny@mytube.social
cd /var/www/mytube.social
```

### Passo 2: Backup
```bash
# Backup dos arquivos antes de atualizar
sudo cp api/verify_reset_code.php api/verify_reset_code.php.backup-$(date +%Y%m%d)
sudo cp api/reset_password.php api/reset_password.php.backup-$(date +%Y%m%d)
```

### Passo 3: Puxar as alterações
```bash
git pull origin main
```

### Passo 4: Verificar sintaxe
```bash
php -l api/verify_reset_code.php
php -l api/reset_password.php
```

### Passo 5: Reiniciar PHP-FPM (se necessário)
```bash
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm
```

### Passo 6: Testar reset de senha
Acesse o site e teste o fluxo completo:

1. **Solicitar reset:**
   - Ir para https://mytube.social/login.php
   - Clicar em "Esqueci minha senha"
   - Inserir email
   - ✅ Deve receber código por email

2. **Verificar código:**
   - Inserir código de 6 dígitos
   - ✅ Deve aceitar e avançar para próxima etapa
   - **Importante:** Abrir DevTools → Network → Verificar resposta JSON
   - ❌ NÃO deve conter `reset_token` na resposta

3. **Redefinir senha:**
   - Inserir nova senha
   - Confirmar senha
   - ✅ Deve atualizar senha com sucesso
   - ✅ Deve poder fazer login com nova senha

### Passo 7: Verificar segurança
```bash
# Verificar se token não está sendo logado
sudo tail -f /var/log/nginx/access.log | grep reset_token
# Não deve mostrar nada (token não está mais no JSON)
```

### ⚠️ Problemas comuns:

**Reset de senha não funciona:**
- Verificar se sessão está iniciada: `session_start()` em reset_password.php
- Verificar logs: `sudo tail -f /var/log/php8.3-fpm/error.log`
- Sessão pode ter expirado (2 horas) - reiniciar processo

**Erro: "Sessão de redefinição inválida"**
- Causa: Sessão expirou ou foi limpa
- Solução: Usuário precisa reiniciar o processo (solicitar novo código)

**Frontend envia reset_token mesmo após atualização:**
- Causa: Cache do navegador
- Solução: Limpar cache (Ctrl+Shift+R) ou forçar reload do JS:
```bash
# Adicionar versão ao JS para invalidar cache
# <script src="assets/js/auth.js?v=20240420"></script>
```

---

## 📋 DEPLOY: Proteção Path Traversal (19/04/2026)

### O que mudou:
- ✅ Proteção contra path traversal em streaming de vídeo
- ✅ Arquivo modificado: `api/stream_video.php`
- ✅ Validação com realpath() + verificação de diretório
- ✅ Bloqueio de MIME types que não sejam vídeo

### Passo 1: Conectar na VPS
```bash
ssh skeny@mytube.social
cd /var/www/mytube.social
```

### Passo 2: Backup
```bash
# Backup do arquivo antes de atualizar
sudo cp api/stream_video.php api/stream_video.php.backup-$(date +%Y%m%d)
```

### Passo 3: Puxar as alterações
```bash
git pull origin main
```

### Passo 4: Verificar sintaxe
```bash
php -l api/stream_video.php
```

### Passo 5: Reiniciar PHP-FPM (se necessário)
```bash
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm
```

### Passo 6: Testar streaming de vídeo
Acesse o site e teste:

1. **Reproduzir vídeo normal:**
   - Ir para https://mytube.social
   - Clicar em qualquer vídeo
   - ✅ Deve reproduzir normalmente

2. **Verificar logs (não deve haver erros):**
```bash
sudo tail -f /var/log/nginx/error.log | grep stream_video
```

3. **Testar proteção (opcional - NÃO RECOMENDADO EM PRODUÇÃO):**
   - Modificar manualmente um video_path no banco para `../../etc/passwd`
   - Tentar reproduzir o vídeo
   - ❌ Deve retornar 403 Forbidden
   - Logs devem mostrar: "TENTATIVA DE PATH TRAVERSAL BLOQUEADA"

### ⚠️ Problemas comuns:

**Vídeos não reproduzem:**
- Verificar se caminho está correto no banco: `SELECT video_path FROM videos LIMIT 5;`
- Verificar se arquivos existem: `ls -lh uploads/videos/`
- Verificar permissões: `chmod 644 uploads/videos/*.mp4`

**Erro: "realpath(): open_basedir restriction"**
- Causa: PHP open_basedir configurado muito restritivo
- Solução: Verificar php.ini: `open_basedir` deve incluir `/var/www/mytube.social/uploads`

**Erro 403 em vídeos legítimos:**
- Verificar se uploads_dir está correto
- Debug: adicionar `error_log($file_path)` antes da validação
- Verificar logs: `sudo tail -f /var/log/php8.3-fpm/error.log`

---

## 📋 DEPLOY: Proteção SSRF (19/04/2026)

### O que mudou:
- ✅ Criado sistema de proteção contra SSRF (Server-Side Request Forgery)
- ✅ Arquivo criado: `includes/ssrf_protection.php`
- ✅ Modificado: `includes/video_processing.php` (função video_download_music)
- ✅ Agora bloqueia: localhost, IPs privados, redirects maliciosos, portas arbitrárias

### Passo 1: Conectar na VPS
```bash
ssh skeny@mytube.social
cd /var/www/mytube.social
```

### Passo 2: Backup
```bash
# Backup antes de atualizar
sudo cp includes/video_processing.php includes/video_processing.php.backup-$(date +%Y%m%d)
```

### Passo 3: Puxar as alterações
```bash
git pull origin main
```

### Passo 4: Verificar arquivo novo
```bash
# Verificar se o arquivo foi criado
ls -lh includes/ssrf_protection.php

# Verificar sintaxe PHP
php -l includes/ssrf_protection.php
php -l includes/video_processing.php
```

### Passo 5: Ajustar permissões
```bash
sudo chown skeny:www-data includes/ssrf_protection.php
sudo chmod 640 includes/ssrf_protection.php
```

### Passo 6: Reiniciar PHP-FPM
```bash
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm
```

### Passo 7: Testar proteção SSRF
Acesse o site e teste:

1. **Upload de vídeo com música (normal):**
   - Ir para https://mytube.social/upload.php
   - Adicionar música do Deezer (URL legítima)
   - Fazer upload
   - ✅ Deve funcionar normalmente

2. **Verificar logs:**
```bash
# Logs devem mostrar "video_download_music: OK"
sudo tail -f /var/log/nginx/error.log | grep video_download_music
```

3. **Testar bloqueio (opcional - apenas em ambiente de teste):**
   - URLs maliciosas devem ser bloqueadas:
     - `http://127.0.0.1` → ❌ Bloqueado (localhost)
     - `http://192.168.1.1` → ❌ Bloqueado (IP privado)
     - `http://evil.dzcdn.net.attacker.com` → ❌ Bloqueado (domínio inválido)

### ⚠️ Problemas comuns:

**Erro: "Cannot modify header information - headers already sent"**
- Causa: Espaços em branco antes de `<?php` em ssrf_protection.php
- Solução: Verificar se não há BOM ou espaços no início do arquivo

**Música não faz download:**
- Verificar logs: `sudo tail -f /var/log/php8.3-fpm/error.log`
- Logs devem mostrar erro específico (domínio não permitido, IP privado, etc)
- Se URL for legítima do Deezer (dzcdn.net), verificar se DNS resolve corretamente

**Erro: "Call to undefined function gethostbyname()"**
- Raro, mas verificar se extensão sockets está instalada:
```bash
php -m | grep sockets
# Se não estiver:
sudo apt install php8.3-sockets
sudo systemctl restart php8.3-fpm
```

### Passo 8: Verificar segurança
```bash
# Testar se bloqueia localhost (deve falhar)
# curl -X POST https://mytube.social/api/test_music_download.php -d "url=http://127.0.0.1"
# Resposta deve ser: "Domínio não está na lista de permitidos"
```

---

## 📋 DEPLOY: Proteção CSRF (18/04/2026)

### O que mudou:
- ✅ Criado sistema completo de proteção CSRF
- ✅ Arquivos criados: `includes/csrf_helpers.php`, `assets/js/csrf.js`
- ✅ Modificados: 4 páginas principais + 26 endpoints API
- ✅ Adicionada validação em todos formulários e requests AJAX

### Passo 1: Conectar na VPS
```bash
ssh skeny@seu-servidor
cd /var/www/mytube.social
```

### Passo 2: Puxar as alterações
```bash
git pull origin main
```

### Passo 3: Verificar arquivos novos
```bash
# Verificar se os arquivos foram criados
ls -la includes/csrf_helpers.php
ls -la assets/js/csrf.js

# Verificar sintaxe PHP
php -l includes/csrf_helpers.php
```

### Passo 4: Limpar cache (se houver cache de assets)
```bash
# Se tiver cache de CSS/JS
rm -rf cache/assets/*

# Se usar CDN/Cloudflare, purge do cache
```

### Passo 5: Reiniciar PHP-FPM
```bash
sudo systemctl restart php8.3-fpm
sudo systemctl status php8.3-fpm
```

### Passo 6: Testar CSRF Protection
Acesse o site e teste:

1. **Login/Cadastro:**
   - Ir para https://mytube.social/login.php
   - Tentar fazer login
   - Tentar criar conta
   - ✅ Deve funcionar normalmente

2. **Upload:**
   - Fazer upload de um vídeo
   - ✅ Deve funcionar normalmente

3. **Profile:**
   - Editar perfil
   - Trocar foto
   - ✅ Deve funcionar normalmente

4. **Interações (AJAX):**
   - Dar like em vídeo
   - Comentar
   - Seguir usuário
   - ✅ Tudo deve funcionar normalmente

5. **Testar proteção:**
   - Abrir DevTools (F12)
   - Console deve mostrar: "CSRF protection loaded"
   - Não deve haver erros de "Token de segurança inválido"

### Passo 7: Verificar logs
```bash
# Verificar se não há erros
tail -f /var/log/php8.3-fpm/error.log
tail -f /var/log/nginx/error.log
```

### ⚠️ Problemas comuns:

**Erro: "CSRF token inválido"**
- Causa: Sessão não iniciada antes de carregar csrf_helpers.php
- Solução: Verificar se `session_start()` vem antes de `require_once 'csrf_helpers.php'`

**Erro: "getCsrfToken is not defined"**
- Causa: csrf.js não carregou
- Solução: Verificar se `<script src="assets/js/csrf.js">` está no `<head>` das páginas

**AJAX requests falhando:**
- Verificar no DevTools → Network se o token está sendo enviado
- Headers devem conter `X-CSRF-Token` ou FormData deve ter `csrf_token`

---

## 📋 PROCESSO PADRÃO PARA QUALQUER DEPLOY FUTURO

### 1. Antes de fazer git pull:
```bash
# Sempre fazer backup do banco (caso precise reverter)
mysqldump -u usuario -p mytube_db > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup de arquivos críticos (se houver alterações)
cp arquivo_importante.php arquivo_importante.php.backup
```

### 2. Pull das alterações:
```bash
git pull origin main
```

### 3. Se houver mudanças no banco de dados:
```bash
# Executar scripts de migração (se existirem)
php update_database.php

# Ou executar SQL manualmente
mysql -u usuario -p mytube_db < migration.sql
```

### 4. Sempre depois do pull:
```bash
# Reiniciar PHP-FPM
sudo systemctl restart php8.1-fpm

# Limpar cache se existir
rm -rf cache/*

# Verificar permissões de diretórios
chmod 755 uploads/videos
chmod 755 uploads/chat
chmod 755 cache
```

### 5. Verificar se tudo funciona:
- Testar funcionalidades principais
- Verificar logs de erro
- Monitorar por alguns minutos

---

## ⚠️ CHECKLIST DE SEGURANÇA NA VPS

- [ ] Arquivo `.env` existe e tem permissões 600
- [ ] `.env` NÃO está versionado no Git (`git status` não deve mostrar .env)
- [ ] Credenciais de produção diferentes das de desenvolvimento
- [ ] HTTPS configurado (SSL/TLS)
- [ ] Firewall ativo (UFW ou similar)
- [ ] Arquivos de debug deletados ou protegidos:
  ```bash
  rm check_session_browser.php
  rm check_users.php
  rm test_r2.php
  ```

---

## 🆘 TROUBLESHOOTING

### Erro: "Failed to load .env file"
```bash
# Verificar se .env existe
ls -la .env

# Verificar se env_loader.php existe
ls -la includes/env_loader.php

# Verificar logs
tail -f /var/log/nginx/error.log
```

### Erro: "Permission denied" ao ler .env
```bash
# Verificar dono do arquivo
ls -la .env

# Ajustar dono (substitua www-data pelo usuário do PHP-FPM)
sudo chown www-data:www-data .env
chmod 600 .env
```

### Site em branco / Erro 500
```bash
# Ver erro exato
tail -f /var/log/php8.1-fpm/error.log
tail -f /var/log/nginx/error.log

# Verificar sintaxe PHP
php -l arquivo_com_erro.php
```

### Banco de dados não conecta
```bash
# Testar conexão manualmente
mysql -h localhost -u usuario_db -p

# Verificar credenciais no .env
cat .env | grep DB_
```

---

## 📞 CONTATO EM CASO DE EMERGÊNCIA

Se algo der errado e o site cair:

### 1. Reverter para versão anterior:
```bash
git log --oneline  # Ver commits recentes
git reset --hard HASH_DO_COMMIT_ANTERIOR
sudo systemctl restart php8.1-fpm
```

### 2. Restaurar banco de dados:
```bash
mysql -u usuario -p mytube_db < backup_YYYYMMDD_HHMMSS.sql
```

### 3. Modo de manutenção:
Criar arquivo `maintenance.html` na raiz e configurar Nginx para mostrar ele.

---

**Última atualização:** 18 de Abril, 2026
**Versão do guia:** 1.0
