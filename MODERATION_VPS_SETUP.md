# Moderação de Conteúdo Adulto — Setup na VPS

Guia completo para activar o sistema de moderação automática com NudeNet na VPS de produção.

---

## Como funciona (resumo)

```
Upload → FFmpeg processa → Python/NudeNet analisa 8 frames
    ↓                              ↓
  Limpo → aprovado              Duvidoso → pending (fila do admin)
                                    ↓
                             Admin aprova ou rejeita no painel
                             (boosted_videos.php → Moderação)
```

**Nota importante:** O NudeNet nunca apaga vídeos automaticamente.  
Quando deteta conteúdo suspeito, coloca o vídeo em `pending`.  
O admin tem sempre a decisão final no painel de moderação.

---

## 1. Pré-requisitos na VPS

### Sistema operativo
- Ubuntu 20.04+ ou Debian 11+ (qualquer distro com `apt`)

### Dependências obrigatórias
| Pacote | Para quê | Instalação |
|--------|----------|------------|
| `python3` (≥ 3.8) | Correr o script de análise | `apt install python3` |
| `python3-pip` | Instalar NudeNet | `apt install python3-pip` |
| `ffmpeg` | Extrair frames dos vídeos | `apt install ffmpeg` |
| `ffprobe` | Obter duração do vídeo | Incluído no pacote `ffmpeg` |

Verificar se já existem:
```bash
python3 --version
pip3 --version
ffmpeg -version | head -1
ffprobe -version | head -1
```

### PHP
- PHP 7.4+ com `exec()` activado (ver secção 4)
- Extensão `mbstring` (necessária para AWS SDK / R2)

---

## 2. Instalar NudeNet

Na raiz do projecto na VPS:
```bash
cd /var/www/mytube.social   # ou o teu caminho
bash moderation/install.sh
```

O script faz:
1. Verifica Python3 e pip3
2. Instala `nudenet>=3.4.0` via pip
3. Faz download do modelo IA (≈ 50 MB, só na primeira vez)

**Confirmar que funcionou:**
```bash
python3 -c "from nudenet import NudeDetector; print('OK')"
```

Testar com um vídeo real:
```bash
python3 moderation/analyze_video.py uploads/videos/SEU_VIDEO.mp4
```
Deverá retornar JSON com `{"status": "clean", ...}` ou `{"status": "nsfw", ...}`.

---

## 3. Configurar o ficheiro `.env`

O ficheiro `.env` **nunca está no git** — tem de ser criado/editado manualmente na VPS.

### Variáveis relacionadas com moderação

```dotenv
# Ambiente — OBRIGATÓRIO para activar a moderação automática
APP_ENV=production

# Secret para o cron de moderação (gera um valor aleatório seguro)
CRON_SECRET=coloca_aqui_uma_string_aleatoria_longa
```

> **`APP_ENV=production`** é a chave: em `development` os uploads são aprovados automaticamente sem NudeNet.  
> Em `production` sem NudeNet instalado → todos os uploads ficam em `pending` para revisão manual.

Gerar um CRON_SECRET seguro:
```bash
openssl rand -hex 32
```

---

## 4. Activar `exec()` no PHP-FPM

O sistema usa `exec()` para chamar o Python. Verificar se está desactivado:
```bash
php -r "echo ini_get('disable_functions');"
```

Se aparecer `exec` na lista, remover no ficheiro de configuração:
```bash
# Encontrar o ficheiro correcto
grep -r "disable_functions" /etc/php/
```

Editar (exemplo para PHP 8.3):
```bash
nano /etc/php/8.3/fpm/php.ini
# ou
nano /etc/php/8.3/fpm/pool.d/www.conf
```

Remover `exec` (e `proc_open` se presente) da linha `disable_functions`.

Reiniciar PHP-FPM:
```bash
sudo systemctl restart php8.3-fpm
```

---

## 5. Executar a migração de base de dados

Apenas na **primeira vez** (adiciona colunas `moderation_status`, `moderation_score`, `moderation_checked_at`):

```bash
php migrations/add_moderation_status.php
```

Verificar:
```sql
SHOW COLUMNS FROM videos LIKE 'moderation%';
```

Deverá mostrar 3 colunas. Todos os vídeos existentes ficam automaticamente como `approved`.

---

## 6. Configurar o Cron Job (processamento automático)

O cron chama `api/run_moderation.php` que analisa até 10 vídeos pendentes de cada vez.

### Opção A — cron via curl (recomendado)
```bash
crontab -e
```
Adicionar (a cada 5 minutos):
```cron
*/5 * * * * curl -s -H "X-Cron-Secret: SEU_CRON_SECRET" "https://mytube.social/api/run_moderation.php?limit=10" >> /var/log/mytube-moderation.log 2>&1
```

### Opção B — cron via PHP CLI
```cron
*/5 * * * * cd /var/www/mytube.social && php api/run_moderation.php >> /var/log/mytube-moderation.log 2>&1
```
> Nesta opção não há autenticação por header — o PHP CLI não tem sessão, usa `CRON_SECRET` directamente na query string ou remove a verificação de auth para CLI.

### Verificar logs
```bash
tail -f /var/log/mytube-moderation.log
```

---

## 7. Configurar Nginx — timeout para uploads longos

O processamento do NudeNet pode demorar vários segundos por vídeo. Confirmar que o `fastcgi_read_timeout` está alto o suficiente:

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_read_timeout 300;
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
}
```

Após editar:
```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 8. Painel de Administração

Após tudo configurado, aceder ao painel:
```
https://mytube.social/boosted_videos.php#moderation
```

- **Visão Geral** mostra o estado do NudeNet (verde = activo, amarelo = inactivo)
- **Moderação** lista todos os vídeos pendentes com botões Aprovar / Rejeitar
- Vídeos rejeitados pelo NudeNet aparecem aqui com o score de confiança

---

## 9. Fluxo completo após deploy

```bash
# 1. Pull do código
cd /var/www/mytube.social
git pull origin main

# 2. Instalar NudeNet (se ainda não instalado)
bash moderation/install.sh

# 3. Editar .env
nano .env
# Garantir: APP_ENV=production e CRON_SECRET=...

# 4. Executar migração
php migrations/add_moderation_status.php

# 5. Reiniciar PHP-FPM
sudo systemctl restart php8.3-fpm

# 6. Configurar cron
crontab -e
# Adicionar linha do cron (ver secção 6)

# 7. Testar
curl -s -H "X-Cron-Secret: SEU_SECRET" "https://mytube.social/api/run_moderation.php"
# Deve retornar: {"success":true, "processed":0, ...}
```

---

## 10. Resolução de problemas

| Problema | Causa provável | Solução |
|----------|---------------|---------|
| NudeNet aparece amarelo no painel | NudeNet não instalado | `bash moderation/install.sh` |
| Uploads ficam sempre `pending` | `APP_ENV=production` sem NudeNet | Instalar NudeNet ou mudar para `development` temporariamente |
| Erro "exec() has been disabled" | `disable_functions` no php.ini | Remover `exec` do `disable_functions` |
| Cron não processa nada | CRON_SECRET errado ou cron não activo | Verificar `.env` e `crontab -l` |
| Análise muito lenta | Modelo NudeNet a descarregar | Normal na 1ª execução; subsequentes são rápidas |
| "ffmpeg não encontrado" | FFmpeg não instalado | `apt install ffmpeg` |
| Vídeos do R2 não analisados | `aws.phar` em falta | `wget` do SDK (ver `vps_nginx_phpfpm_config.md`) |

---

## Ficheiros relevantes no projecto

```
moderation/
  analyze_video.py          ← script Python de análise (NudeNet)
  requirements.txt          ← nudenet>=3.4.0
  install.sh                ← instalador para VPS

includes/
  content_moderation.php    ← funções PHP de moderação

api/
  run_moderation.php        ← endpoint do cron (processa fila pending)
  admin_moderate.php        ← API para o admin aprovar/rejeitar

migrations/
  add_moderation_status.php ← adiciona colunas à tabela videos

upload.php                  ← integra moderação no fluxo de upload
boosted_videos.php          ← painel admin (secção Moderação)
```
