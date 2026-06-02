# SUGESTÕES DE FUNCIONALIDADES — MyTube

**Data:** 02 de Junho, 2026
**Âmbito:** Análise do estado atual + benchmarking com plataformas modernas (TikTok, YouTube, Instagram Reels, Twitch)

---

## SUMÁRIO DE IMPACTO

| Área | Sugestões | Impacto Potencial |
|------|-----------|------------------|
| Funcionalidades Novas | 14 | Crescimento de utilizadores |
| UX/UI | 10 | Retenção + satisfação |
| Performance | 8 | Conversão + SEO |
| Segurança | 6 | Confiança + compliance |
| Monetização | 7 | Revenue |
| Retenção | 8 | DAU/MAU |

---

## 1. NOVAS FUNCIONALIDADES

---

### F-01 — Autenticação de Dois Fatores (2FA)

**Impacto:** Alto — Segurança + confiança da plataforma
**Inspiração:** Google, Twitter, Instagram

**Descrição:** Adicionar 2FA via TOTP (Google Authenticator) e/ou SMS/email para contas de utilizador e especialmente para admins e moderadores.

**Implementação sugerida:**
- Biblioteca: `OTPHP/OTPHP` (PHP) para TOTP
- Guardar `totp_secret` na tabela `users` (encriptado)
- 2FA obrigatório para `role = 'admin'` e `role = 'moderator'`
- 2FA opcional para utilizadores normais
- Códigos de recuperação gerados no momento da ativação

**Estimativa:** 3–5 dias de desenvolvimento

---

### F-02 — Painel de Analytics para Criadores

**Impacto:** Alto — Retenção de criadores
**Inspiração:** YouTube Studio, TikTok Creator Center

**Descrição:** Dashboard para criadores com métricas dos seus vídeos: visualizações por período, likes, comentários, partilhas, origem do tráfego, demografias.

**Dados já disponíveis no sistema:**
- `views_count`, `likes_count`, `comments_count` em `videos`
- `trend_score` calculado
- Tabela `video_views` com registo por utilizador
- Sistema de `boost_tracking` existente

**Métricas a exibir:**
- Views últimas 24h / 7 dias / 30 dias
- Gráfico de crescimento de seguidores
- Vídeos com melhor performance
- Taxa de engagement (likes/views)
- Partilhas por vídeo

**Estimativa:** 5–8 dias de desenvolvimento

---

### F-03 — Streaming em Direto (Live)

**Impacto:** Muito Alto — Diferenciação da plataforma
**Inspiração:** Twitch, Instagram Live, YouTube Live

**Descrição:** Permitir que utilizadores (premium ou verificados) façam transmissões ao vivo com chat em tempo real.

**Stack sugerida:**
- Protocolo: RTMP → HLS (usando `ffmpeg` já presente)
- Servidor: Nginx RTMP module ou MediaMTX
- Chat: reutilizar o Socket.IO existente com sala específica
- Gravação: opcional, salvar no R2 após stream

**Pré-requisitos:** Infraestrutura de servidor com suporte a RTMP (VPS atual pode suportar)

**Estimativa:** 15–20 dias de desenvolvimento

---

### F-04 — Legendas e Closed Captions

**Impacto:** Alto — Acessibilidade + SEO
**Inspiração:** YouTube, TikTok

**Descrição:** Suporte a legendas nos vídeos, geradas automaticamente ou enviadas pelo criador (formato SRT/VTT).

**Implementação:**
- Upload de ficheiro `.srt` ou `.vtt` junto com o vídeo
- Exibição via `<track>` HTML5 no player de vídeo
- Opcionalmente: integração com API de transcrição (Whisper/OpenAI) para geração automática

**Estimativa:** 3–5 dias (manual) / 8–12 dias (automático com IA)

---

### F-05 — Séries e Episódios de Vídeos

**Impacto:** Médio — Retenção + organização de conteúdo
**Inspiração:** YouTube Playlists, TikTok Series

**Descrição:** Permitir que criadores agrupem vídeos em séries ordenadas, com navegação automática para o próximo episódio.

**Tabelas necessárias:**
```sql
CREATE TABLE video_series (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    cover_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE series_videos (
    series_id INT NOT NULL,
    video_id INT NOT NULL,
    episode_number INT NOT NULL,
    PRIMARY KEY (series_id, video_id)
);
```

**Estimativa:** 4–6 dias de desenvolvimento

---

### F-06 — Playlists Colaborativas

**Impacto:** Médio — Engagement social
**Inspiração:** Spotify (playlists colaborativas), YouTube

**Descrição:** Utilizadores podem criar playlists e convidar outros a adicionar vídeos.

**Estimativa:** 4–6 dias de desenvolvimento

---

### F-07 — Página de Trending / Hashtags

**Impacto:** Alto — Descoberta de conteúdo
**Inspiração:** TikTok Trending, Twitter Trending

**Descrição:** Página dedicada de hashtags em alta com vídeos associados, ordenados por `trend_score`. O sistema de hashtags já existe (`install_hashtags.php` presente).

**Implementação:**
- Query: `SELECT hashtag, COUNT(*) as uses, SUM(v.trend_score) as total_score FROM video_hashtags vh JOIN videos v ON vh.video_id = v.id WHERE vh.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY hashtag ORDER BY total_score DESC LIMIT 20`
- Cache Redis com TTL de 5 minutos
- Página `/trending` com grid de vídeos por hashtag

**Estimativa:** 2–3 dias de desenvolvimento

---

### F-08 — Sistema de Comentários Pinnados

**Impacto:** Baixo/Médio — UX de criadores
**Inspiração:** YouTube, Instagram

**Descrição:** Criador pode fixar um comentário no topo do seu vídeo. Útil para créditos, respostas, ou avisos.

**Implementação:** Adicionar coluna `is_pinned TINYINT(1) DEFAULT 0` à tabela `comments`. Endpoint PATCH para toggle por owner do vídeo.

**Estimativa:** 1–2 dias de desenvolvimento

---

### F-09 — Reações Múltiplas em Vídeos

**Impacto:** Médio — Engagement + expressão
**Inspiração:** Facebook Reactions, YouTube (like/dislike)

**Descrição:** Em vez de apenas "like", permitir reações: 🔥 (incrível), 😂 (engraçado), ❤️ (amor), 😮 (surpresa).

**Implementação:** Adicionar coluna `reaction_type ENUM('like','fire','laugh','love','wow')` à tabela `likes`.

**Estimativa:** 2–3 dias de desenvolvimento

---

### F-10 — Histórico de Visualizações Pessoal

**Impacto:** Médio — Retenção
**Inspiração:** YouTube History, Netflix

**Descrição:** Utilizador pode ver os vídeos que viu recentemente, com possibilidade de limpar o histórico.

**Dados disponíveis:** Tabela `video_views` já existe com `user_id` e `video_id`.

**Implementação:** Página `/historico` com query sobre `video_views` JOIN `videos`. Endpoint para DELETE do histórico.

**Estimativa:** 1–2 dias de desenvolvimento

---

### F-11 — Modo de Conteúdo Adulto / Controlo Parental

**Impacto:** Alto — Compliance e responsabilidade
**Inspiração:** Twitter/X (NSFW), Patreon

**Descrição:** Sistema de marcação de conteúdo para adultos com verificação de idade e opção de ocultar para utilizadores não-verificados.

**Implementação:**
- Coluna `is_adult_content TINYINT(1) DEFAULT 0` em `videos`
- Coluna `age_verified TINYINT(1) DEFAULT 0` em `users`
- Filtro automático no feed para utilizadores não-verificados

**Estimativa:** 3–5 dias de desenvolvimento

---

### F-12 — Vídeos Programados (Agendamento de Publicação)

**Impacto:** Médio — Experiência do criador
**Inspiração:** YouTube Studio, Instagram

**Descrição:** Criador pode fazer upload do vídeo e definir data/hora de publicação futura.

**Implementação:**
- Coluna `scheduled_at TIMESTAMP NULL` em `videos`
- Coluna `status ENUM('draft','scheduled','published')` em `videos`
- Cron job que verifica e publica vídeos agendados a cada minuto

**Estimativa:** 2–4 dias de desenvolvimento

---

### F-13 — Relatório de Conteúdo Melhorado

**Impacto:** Alto — Segurança da comunidade
**Inspiração:** TikTok, YouTube, Instagram

**Descrição:** Sistema de reporte de conteúdo com categorias específicas (spam, nudez, violência, desinformação, hate speech) e fluxo de resolução por moderadores.

**Integração:** O sistema de moderação (`api/admin_moderate.php`, `includes/content_moderation.php`) já existe. Falta a interface de report do lado do utilizador e o dashboard de moderação completo.

**Estimativa:** 3–5 dias de desenvolvimento

---

### F-14 — Login Social (Google já em progresso)

**Impacto:** Alto — Conversão de novos utilizadores
**Inspiração:** Todas as plataformas modernas

**Descrição:** O `google_id` já existe na tabela `users`. Completar integração OAuth com Apple, GitHub e Microsoft para maximizar conversão no registo.

**Estimativa:** 2 dias por provider adicional

---

## 2. MELHORIAS DE UX/UI

---

### UX-01 — Modo Escuro (Dark Mode)

**Impacto:** Alto — Satisfação do utilizador, especialmente mobile
**Inspiração:** TikTok (dark by default), YouTube

**Implementação:**
- CSS custom properties: `--bg-color`, `--text-color`, `--card-bg`
- Toggle no perfil com `localStorage` para persistência
- `prefers-color-scheme` media query como padrão automático

**Estimativa:** 3–5 dias de desenvolvimento

---

### UX-02 — Melhorias de Acessibilidade (WCAG 2.1 AA)

**Impacto:** Alto — Compliance + utilizadores com necessidades especiais

**Problemas identificados:**
- Falta de `aria-label` em botões de ação (like, partilhar, comentar)
- Sem indicadores de foco visíveis em CSS
- Sem navegação por teclado no feed de vídeos
- Contraste de texto insuficiente em alguns elementos

**Implementação:**
- Adicionar `aria-label` a todos os botões
- Implementar estilos `:focus-visible` consistentes
- Suporte a `Tab` e `Enter/Space` para interações principais
- Verificar contraste com ferramentas WCAG

**Estimativa:** 3–5 dias de desenvolvimento

---

### UX-03 — Pré-visualização de Vídeo no Hover

**Impacto:** Médio — Descoberta de conteúdo
**Inspiração:** Netflix, YouTube

**Descrição:** No feed, ao fazer hover num vídeo (desktop), mostrar uma miniatura animada (GIF ou clip de 3s).

**Implementação:** A API `api/get_video_preview.php` já existe. Integrar com o event listener `mouseenter` no feed.

**Estimativa:** 1–2 dias de desenvolvimento

---

### UX-04 — Barra de Progresso de Visualização

**Impacto:** Médio — Retenção
**Inspiração:** Netflix "Continue a ver"

**Descrição:** Guardar posição de visualização e mostrar barra de progresso no thumbnail de vídeos parcialmente vistos.

**Estimativa:** 2–3 dias de desenvolvimento

---

### UX-05 — Navegação por Gestos no Mobile

**Impacto:** Alto — Experiência mobile (maioria dos utilizadores)
**Inspiração:** TikTok

**Descrição:** Swipe para a esquerda para comentários, swipe para direita para partilhar, swipe para baixo para fechar. Integrar com a classe `TikTokFeed` existente em `tiktok.js`.

**Estimativa:** 2–3 dias de desenvolvimento

---

### UX-06 — Sidebar de Navegação no Desktop

**Impacto:** Médio — Navegação no desktop
**Inspiração:** YouTube, Reddit

**Descrição:** Em ecrãs largos (>1200px), mostrar sidebar fixa com: Feed, Tendências, Subscrições, Perfil, Configurações.

**Estimativa:** 2–3 dias de desenvolvimento

---

### UX-07 — Notificações In-App em Tempo Real

**Impacto:** Alto — Engagement
**Descrição:** Mostrar notificações pop-up instantâneas (toast) quando alguém comenta, curte ou segue — sem necessidade de recarregar.

**Integração:** O sistema de Push Notifications já existe. Adicionar canal Socket.IO para notificações in-app enquanto o utilizador está ativo.

**Estimativa:** 2–3 dias de desenvolvimento

---

### UX-08 — Seletor de Qualidade de Vídeo

**Impacto:** Médio — Experiência em conexões lentas
**Inspiração:** YouTube

**Descrição:** Botão no player para escolher qualidade (1080p, 720p, 480p, 360p). Requer transcodificação múltipla no upload.

**Estimativa:** 8–12 dias (inclui transcodificação adaptativa)

---

### UX-09 — Descrições com Formatação Básica

**Impacto:** Baixo/Médio — Experiência do criador
**Descrição:** Suporte a negrito, itálico, links e listas em descrições de vídeos. Implementar com Markdown simples ou BBCode.

**Estimativa:** 2–3 dias de desenvolvimento

---

### UX-10 — Upload por Drag & Drop com Pré-visualização

**Impacto:** Médio — Facilidade de uso
**Descrição:** Interface de upload melhorada com área de drop, pré-visualização imediata do vídeo, e barra de progresso com estimativa de tempo restante.

O sistema de upload (`upload.js`) já é sofisticado. Melhorar o feedback visual.

**Estimativa:** 2–3 dias de desenvolvimento

---

## 3. MELHORIAS DE PERFORMANCE

---

### PERF-01 — Redis para Cache e Rate Limiting

**Impacto:** Alto — Escalabilidade e velocidade
**Descrição:** Substituir cache em PHP array e rate limiting em MySQL por Redis. Reduzir queries por request significativamente.

**Ganhos esperados:**
- Rate limiting: de ~2 queries/request para 1 operação Redis O(1)
- Cache de feed: TTL configurável, invalidação por evento
- Dados de utilizador: sem query por request após primeiro load

**Estimativa:** 5–8 dias de implementação

---

### PERF-02 — Full-Text Search com MySQL FULLTEXT

**Impacto:** Alto — Velocidade de pesquisa
**Descrição:** Substituir `LIKE '%query%'` por `MATCH() AGAINST()` com `FULLTEXT INDEX`.

```sql
-- Adicionar índices:
ALTER TABLE videos ADD FULLTEXT INDEX ft_video_search (title, description);
ALTER TABLE users ADD FULLTEXT INDEX ft_user_search (username, full_name);
ALTER TABLE hashtags ADD FULLTEXT INDEX ft_hashtag_search (name);

-- Query otimizada:
SELECT * FROM videos
WHERE MATCH(title, description) AGAINST(? IN BOOLEAN MODE)
ORDER BY trend_score DESC LIMIT 20;
```

**Estimativa:** 1–2 dias de implementação

---

### PERF-03 — Transcodificação Assíncrona em Background

**Impacto:** Alto — Experiência de upload
**Descrição:** O processamento FFmpeg atual bloqueia o request de upload. Mover para queue assíncrona.

**Stack sugerida:**
- Queue: Tabela MySQL `job_queue` ou Redis Queue
- Worker: Processo PHP CLI ou Node.js separado
- Status: Polling ou WebSocket para notificar quando o vídeo está pronto

**Estimativa:** 8–12 dias de implementação

---

### PERF-04 — Adaptive Bitrate Streaming (HLS)

**Impacto:** Alto — Experiência em redes lentas
**Descrição:** Converter vídeos para HLS (`.m3u8` + segmentos `.ts`) com múltiplas qualidades. O player escolhe automaticamente a qualidade com base na largura de banda.

**Ferramentas:** FFmpeg (já presente) + hls.js no frontend

**Estimativa:** 10–15 dias de implementação

---

### PERF-05 — CDN para Assets Estáticos

**Impacto:** Médio — Velocidade global
**Descrição:** Servir CSS, JS, imagens e fontes via CDN (Cloudflare Pages ou Bunny.net) em vez do servidor PHP.

**Implementação:**
- Configurar Cloudflare Cache Rules para `/assets/*`
- Adicionar fingerprinting a assets (hash no nome do ficheiro)
- Usar `SITE_CDN_URL` em vez de `SITE_URL` para assets

**Estimativa:** 2–3 dias de implementação

---

### PERF-06 — Índices Críticos na Base de Dados

**Impacto:** Alto — Velocidade de queries
**Descrição:** Adicionar índices nas colunas mais consultadas:

```sql
ALTER TABLE comments ADD INDEX idx_video_id (video_id);
ALTER TABLE comments ADD INDEX idx_parent_id (parent_comment_id);
ALTER TABLE videos ADD INDEX idx_trend_score (trend_score DESC);
ALTER TABLE videos ADD INDEX idx_created_at (created_at DESC);
ALTER TABLE video_views ADD INDEX idx_video_created (video_id, created_at);
ALTER TABLE user_online_status ADD INDEX idx_updated (updated_at);
```

**Estimativa:** Meio dia

---

### PERF-07 — Paginação com Cursor (em vez de OFFSET)

**Impacto:** Médio — Performance em feeds longos
**Descrição:** `OFFSET N` fica lento em tabelas grandes. Usar cursor-based pagination com `WHERE id < last_seen_id LIMIT 20`.

**Estimativa:** 3–5 dias (refactor de endpoints de feed)

---

### PERF-08 — Service Worker e Cache Offline

**Impacto:** Médio — PWA + redes lentas
**Descrição:** O `sw.js` já existe. Expandir para cache inteligente de: avatares, thumbnails, assets estáticos. Permitir navegar o feed mesmo sem conexão (mostrando últimos vídeos vistos).

**Estimativa:** 3–5 dias de desenvolvimento

---

## 4. MELHORIAS DE SEGURANÇA (Produto)

---

### SEC-01 — Log de Atividade de Conta

**Impacto:** Alto — Confiança do utilizador
**Descrição:** Utilizador pode ver no seu perfil: últimos logins (IP, dispositivo, data), tentativas de login falhadas, ações recentes (mudança de senha, email).

**Estimativa:** 2–3 dias de desenvolvimento

---

### SEC-02 — Sessões Ativas e Gestão de Dispositivos

**Impacto:** Médio — Segurança do utilizador
**Inspiração:** Google, Facebook

**Descrição:** Utilizador pode ver e terminar sessões ativas noutros dispositivos. Implementar tabela `user_sessions` com `device_info`, `ip`, `last_active`.

**Estimativa:** 3–5 dias de desenvolvimento

---

### SEC-03 — Verificação de Email Obrigatória

**Impacto:** Alto — Qualidade dos dados + segurança
**Descrição:** Tornar a verificação de email obrigatória antes de publicar vídeos ou interagir. O sistema de verificação por email já existe.

**Estimativa:** 1 dia de ajuste

---

### SEC-04 — CAPTCHA em Ações Sensíveis

**Impacto:** Médio — Proteção contra bots
**Descrição:** Integrar hCaptcha (privacy-friendly) ou Cloudflare Turnstile no registo, login após falhas, e envio de reset de senha.

**Estimativa:** 2–3 dias de implementação

---

### SEC-05 — Política de Privacidade e RGPD Compliant

**Impacto:** Alto — Compliance legal
**Descrição:**
- Página de preferências de privacidade
- Download dos dados pessoais (export JSON)
- Eliminação de conta com limpeza de dados associados
- Banner de consentimento de cookies (RGPD/ePrivacy)

**Estimativa:** 5–8 dias de desenvolvimento

---

### SEC-06 — Auditoria Automática de Vulnerabilidades

**Impacto:** Médio — Manutenção de segurança
**Descrição:** Integrar ferramentas de scanning automático no CI/CD:
- Dependabot para dependências PHP/Node.js
- PHPStan para análise estática de código
- OWASP Dependency-Check

**Estimativa:** 1–2 dias de configuração

---

## 5. MONETIZAÇÃO

---

### MON-01 — Subscrição Premium (já iniciado)

**Impacto:** Alto — Revenue direto
**Descrição:** O sistema de `is_premium` já existe na base de dados (`migrations/add_is_premium.php`). Implementar o fluxo completo:
- Página de planos (Free / Premium / Creator Pro)
- Integração com Stripe ou PayPal
- Benefícios: sem anúncios, upload de vídeos mais longos, badge premium
- Gestão de subscrição no perfil

**Estimativa:** 10–15 dias de implementação

---

### MON-02 — Creator Fund / Fundo de Criadores

**Impacto:** Alto — Atração e retenção de criadores
**Inspiração:** TikTok Creator Fund, YouTube Partner Program

**Descrição:** Remunerar criadores com base em visualizações verificadas. Calcular usando `video_views` + `trend_score`.

**Estimativa:** 15–20 dias (inclui sistema de pagamentos)

---

### MON-03 — Tips / Gorjetas para Criadores

**Impacto:** Médio — Revenue e engagement
**Inspiração:** YouTube Super Thanks, Twitch Bits

**Descrição:** Utilizadores podem enviar gorjetas monetárias para criadores diretamente nos vídeos ou no chat.

**Estimativa:** 8–12 dias de implementação

---

### MON-04 — Anúncios In-Feed (Boosts Pagos)

**Impacto:** Alto — Revenue
**Descrição:** O sistema de `boost_tracking` já existe. Implementar pagamento real para boosting de vídeos no feed.

**Estimativa:** 5–8 dias de implementação

---

### MON-05 — Marketplace de Stickers / Emojis Premium

**Impacto:** Baixo/Médio — Revenue de microtransações
**Inspiração:** Discord Nitro, LINE stickers

**Descrição:** Pack de stickers e reações premium disponíveis para compra.

**Estimativa:** 8–12 dias de implementação

---

### MON-06 — Links Afiliados e Merch

**Impacto:** Médio — Revenue indireto
**Inspiração:** TikTok Shop, YouTube Merch

**Descrição:** Permitir que criadores adicionem links de produto ou loja ao seu perfil e vídeos.

**Estimativa:** 3–5 dias de implementação

---

### MON-07 — Conteúdo Exclusivo (Paywall)

**Impacto:** Alto — Revenue + fidelização
**Inspiração:** Patreon, Fanhouse

**Descrição:** Criadores podem marcar vídeos como "apenas para subscritores" ou definir preço de acesso individual.

**Estimativa:** 10–15 dias de implementação

---

## 6. RETENÇÃO DE UTILIZADORES

---

### RET-01 — Digest Semanal por Email

**Impacto:** Médio — Re-engagement
**Descrição:** Email semanal personalizado com: top vídeos de pessoas que o utilizador segue, novas hashtags em alta, novos criadores recomendados.

O sistema de email (`includes/mail_helper.php`) já está implementado.

**Estimativa:** 3–5 dias de desenvolvimento

---

### RET-02 — Recomendações Personalizadas

**Impacto:** Muito Alto — Engagement e tempo de sessão
**Inspiração:** TikTok (algoritmo), YouTube Recommendations

**Descrição:** Algoritmo de recomendação baseado em: histórico de visualizações, likes, hashtags seguidas, criadores seguidos, e tempo de visualização.

O `trend_score` atual é global. Personalizar por utilizador.

**Estimativa:** 10–20 dias de desenvolvimento (ML simples = menos; ML avançado = mais)

---

### RET-03 — Sistema de Streaks (Sequências)

**Impacto:** Médio — Gamification
**Inspiração:** Duolingo, Snapchat

**Descrição:** Recompensar utilizadores que publicam vídeos ou interagem diariamente com badges de sequência.

**Estimativa:** 2–3 dias de desenvolvimento

---

### RET-04 — Badges e Conquistas

**Impacto:** Médio — Gamification
**Inspiração:** Reddit Awards, YouTube Creator Awards

**Descrição:** Badges automáticos por milestones: 100 seguidores, 1000 visualizações, 1 ano na plataforma, criador verificado.

**Estimativa:** 3–5 dias de desenvolvimento

---

### RET-05 — Desafios e Hashtag Challenges

**Impacto:** Alto — Viral growth
**Inspiração:** TikTok #challenges

**Descrição:** Criadores ou admins podem lançar desafios com hashtag específica, com destaque na página de trending.

**Estimativa:** 3–5 dias de desenvolvimento

---

### RET-06 — Continue a Ver

**Impacto:** Médio — Retenção
**Descrição:** Secção "Continue a ver" no feed com vídeos parcialmente assistidos. Requer guardar posição de reprodução.

**Estimativa:** 2–3 dias de desenvolvimento

---

### RET-07 — Modo Descoberta (Estilo TikTok)

**Impacto:** Alto — Descoberta de novos criadores
**Descrição:** Feed alternativo com conteúdo de criadores não-seguidos mas com alto `trend_score` e boa taxa de engagement, personalizado por preferências do utilizador.

**Estimativa:** 3–5 dias de desenvolvimento

---

### RET-08 — Notificações Push Melhoradas

**Impacto:** Alto — Re-engagement
**Descrição:** O sistema de Push Notifications (`push_subscribe.php`, `sw.js`) já existe. Melhorar com:
- Notificações de marcos (ex: "O teu vídeo atingiu 1000 views!")
- Resumo diário em hora configurável pelo utilizador
- Configuração granular (quais notificações receber)

**Estimativa:** 2–3 dias de melhorias

---

## ROADMAP DE IMPLEMENTAÇÃO RECOMENDADO

### Sprint 1 (2 semanas) — Quick wins de alto impacto

| # | Funcionalidade | Impacto | Esforço |
|---|---------------|---------|---------|
| 1 | Índices críticos na base de dados | Alto | Baixo |
| 2 | Full-Text Search | Alto | Baixo |
| 3 | Histórico de visualizações | Médio | Baixo |
| 4 | Pré-visualização de vídeo no hover | Médio | Baixo |
| 5 | Comentários pinnados | Baixo | Baixo |
| 6 | Página de Trending / Hashtags | Alto | Médio |

### Sprint 2 (2 semanas) — UX e engagement

| # | Funcionalidade | Impacto | Esforço |
|---|---------------|---------|---------|
| 7 | Modo Escuro | Alto | Médio |
| 8 | Notificações In-App em Tempo Real | Alto | Médio |
| 9 | Melhorias de Acessibilidade | Alto | Médio |
| 10 | Painel de Analytics para Criadores | Alto | Alto |

### Sprint 3–4 (1 mês) — Revenue e Retenção

| # | Funcionalidade | Impacto | Esforço |
|---|---------------|---------|---------|
| 11 | Subscrição Premium (completar) | Muito Alto | Alto |
| 12 | Recomendações Personalizadas (v1) | Muito Alto | Alto |
| 13 | 2FA para admins e premium | Alto | Médio |
| 14 | Digest Semanal por Email | Médio | Médio |

### Trimestre 2 — Funcionalidades avançadas

| # | Funcionalidade | Impacto | Esforço |
|---|---------------|---------|---------|
| 15 | Redis + Cache distribuído | Alto | Alto |
| 16 | HLS Adaptive Streaming | Alto | Muito Alto |
| 17 | Streaming em Direto (Live) | Muito Alto | Muito Alto |
| 18 | Creator Fund | Muito Alto | Muito Alto |

---

**Nota:** Estimativas de esforço: Baixo = <2 dias, Médio = 2–5 dias, Alto = 5–10 dias, Muito Alto = 10+ dias.
**Data:** 02/06/2026
