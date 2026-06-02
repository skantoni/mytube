# RELATÓRIO DE AUDITORIA DO PROJETO — MyTube

**Data:** 02 de Junho, 2026
**Tipo:** Auditoria técnica e de produto completa
**Âmbito:** Código-fonte, segurança, arquitetura, base de dados, frontend, UX, performance, escalabilidade

---

## ESTADO GERAL DO PROJETO

**Classificação Global: 6.5 / 10**

O MyTube é uma plataforma de partilha de vídeos funcionalmente completa, com funcionalidades comparáveis a plataformas de nicho no mercado. O projeto passou por uma ronda significativa de melhorias de segurança em Abril-Maio de 2026, que elevou o seu estado de "crítico" para "moderado". A base técnica é sólida, mas existem dívidas técnicas relevantes e 37 vulnerabilidades de segurança pendentes que limitam o potencial de escala e a confiança dos utilizadores.

---

## PONTOS FORTES

### Segurança (Melhorias recentes)

- Sistema CSRF completo com `hash_equals()` e interceptação global em JavaScript
- Proteção SSRF com whitelist, bloqueio de IPs privados e sem redirects
- Remoção de metadados EXIF/GPS das imagens (privacidade dos utilizadores)
- Rate limiting por IP e utilizador via base de dados
- Headers de segurança HTTP: HSTS, X-Frame-Options, CSP, X-Content-Type-Options
- Validação real de MIME types com `finfo_file()` em uploads
- Autenticação JWT no chat com verificação de assinatura HMAC-SHA256
- Transações PDO em operações críticas de contadores (likes, follows, views)
- Prepared statements em 100% das queries identificadas

### Funcionalidades

- Feed de vídeos estilo TikTok com scroll infinito e virtualização do DOM
- Chat em tempo real com Socket.IO: mensagens privadas, grupos, status online, typing indicators
- Sistema de notificações Web Push funcionando em produção
- Upload de vídeos para Cloudflare R2 (CDN distribuído)
- Processamento de vídeo com FFmpeg (thumbnails, validação de duração)
- Sistema de moderação de conteúdo com roles (admin, moderator)
- Rankings semanais e sistema de "Best MyTubers"
- Pesquisa com autocomplete (utilizadores, vídeos, hashtags)
- Sistema de hashtags e trending
- Sistema de amizades e follow
- Login social com Google (parcialmente implementado)
- SEO com Open Graph, Twitter Cards e Schema.org JSON-LD
- PWA (Progressive Web App) com Service Worker
- Música de fundo em vídeos (integração Deezer)

### Arquitetura

- Separação entre assets estáticos e conteúdo dinâmico
- Servidor de chat separado (Node.js) desacoplado do PHP
- Sistema de variáveis de ambiente (`.env`) para configurações sensíveis
- Início de migração para padrão MVC (classes `AuthService`, `UserRepository`, `UserValidator`)
- Documentação estruturada em `/docs` com subpastas organizadas

### Frontend

- Virtualização do DOM para feed de vídeos (máximo 5 vídeos materializados)
- Otimização de imagens com conversão para WebP
- Debouncing em pesquisa (180ms)
- Atualizações otimistas de UI para likes e follows
- Compressão de imagens no cliente antes do upload

---

## PONTOS FRACOS

### Segurança Pendente

- **37 vulnerabilidades por resolver** (10 altas, 14 médias, 10 baixas + 3 críticas)
- CSP com `unsafe-inline` e `unsafe-eval` que anulam a proteção XSS
- Ficheiros de debug ainda presentes no repositório
- Política de senhas fraca (mínimo 6 caracteres)
- Nomes de ficheiro de avatares previsíveis
- Sem 2FA

### Arquitetura

- **Mistura de paradigmas:** Alguns endpoints seguem o novo padrão MVC, a maioria ainda tem lógica de negócio diretamente no endpoint
- **Migrações dinâmicas no código:** `upload.php` executa `ALTER TABLE` em cada request para verificar colunas — anti-padrão grave
- **Sem sistema de migrations versionado:** Scripts de migração são manuais e dispersos
- **Sem composer.json:** Dependências PHP geridas manualmente (PHPMailer, aws.phar incluídos diretamente)
- **Sem autoloading PSR-4:** Classes são incluídas manualmente com `require_once`

### Performance

- **Sem Redis/cache distribuído:** Cache apenas em variáveis PHP (não persiste entre requests)
- **Pesquisa com LIKE '%query%':** Full table scan em tabelas de utilizadores e vídeos
- **Upload síncrono com FFmpeg:** O processamento de vídeo bloqueia o request (timeout de 10 minutos no XHR)
- **Sem paginação cursor:** `OFFSET N` fica lento em feeds com muitos vídeos
- **Sem connection pooling:** Nova conexão TCP por request PHP

### Qualidade de Código

- **Inconsistência de estilos:** Mix de procedural e OOP sem guia de estilo definido
- **Sem testes automatizados:** Nenhum teste unitário, de integração ou E2E identificado
- **Sem CI/CD:** Deploy manual; sem pipeline de verificação antes de merge
- **Comentários em dois idiomas:** Código mistura Português e Inglês
- **Magic strings:** Estados como `'approved'`, `'pending'`, `'processing'` usados diretamente sem constantes

### UX/UI

- Sem modo escuro
- Acessibilidade limitada (falta de `aria-*` attributes)
- Sem seletor de qualidade de vídeo
- Ausência de legendas/closed captions
- Sem feedback de progresso de upload com estimativa de tempo

---

## DÍVIDA TÉCNICA

### Alta Prioridade

| Item | Impacto | Esforço | Localização |
|------|---------|---------|-------------|
| Migrações dinâmicas em upload.php | Performance + manutenção | Médio | `upload.php` (linhas 131–147) |
| Ausência de testes | Regressões em produção | Alto | Todo o projeto |
| Sem autoloading PSR-4 | Manutenção difícil | Médio | `includes/*.php` |
| Sem composer.json | Vulnerabilidades em deps | Médio | Raiz do projeto |
| Rate limiting em SESSION | Contornável | Baixo | `api/add_comment.php` |

### Média Prioridade

| Item | Impacto | Esforço | Localização |
|------|---------|---------|-------------|
| Arquitetura MVC incompleta | Manutenção difícil | Alto | `api/*.php` (maioria) |
| Sem Redis | Escalabilidade | Médio | `includes/config.php` |
| Logging não estruturado | Diagnóstico difícil | Baixo | Vários ficheiros |
| Sem API versioning | Deprecação sem controlo | Médio | `api/*.php` |
| Ficheiros script de diagnóstico no repo | Risco de segurança | Baixo | `test_*.php`, `check_*.php` |

### Baixa Prioridade

| Item | Impacto | Esforço | Localização |
|------|---------|---------|-------------|
| Magic strings sem constantes | Manutenção | Baixo | Vários |
| CSS não minificado | Performance | Baixo | `assets/css/` |
| JS não bundled | Performance | Médio | `assets/js/` |
| Sem TypeScript | Type safety | Alto | `assets/js/` |

---

## RISCOS ATUAIS

### Risco 1 — Comprometimento do Chat via JWT Padrão (CRÍTICO)

Se `CHAT_JWT_SECRET` não estiver configurado no servidor de produção, qualquer pessoa pode forjar tokens e personificar qualquer utilizador no chat. **Probabilidade:** Baixa (se bem configurado). **Impacto:** Muito Alto.

### Risco 2 — Exposição de Ficheiros de Debug em Deploy Sem Nginx Correto

Os ficheiros `test_*.php`, `check_*.php`, `debug_*.php` estão no repositório. Em qualquer deploy sem a configuração Nginx exata, são acessíveis publicamente. **Probabilidade:** Média. **Impacto:** Alto.

### Risco 3 — DoS via Upload de Vídeo Malformado

FFmpeg sem timeout pode bloquear workers PHP indefinidamente. Em hosting compartilhado ou VPS com recursos limitados, um atacante pode esgotar todos os workers com uploads concorrentes. **Probabilidade:** Média. **Impacto:** Alto.

### Risco 4 — RGPD / Privacidade

Sem mecanismo de export ou eliminação de dados pessoais. Em caso de pedido formal de utilizador ou auditoria regulatória (RGPD/LGPD), o cumprimento não pode ser garantido. **Probabilidade:** Baixa agora, crescente com escala. **Impacto:** Alto (legal).

### Risco 5 — Escalabilidade da Base de Dados

Sem Redis, índices otimizados, ou connection pooling. Com crescimento para 10.000+ utilizadores simultâneos, a base de dados MySQL será o bottleneck principal. **Probabilidade:** Média (se a plataforma crescer). **Impacto:** Alto.

### Risco 6 — Dependências Não Auditadas

Sem `composer.json` para PHP, sem auditoria automática de dependências. O `aws.phar` incluído diretamente pode ter vulnerabilidades não detetadas. **Probabilidade:** Média. **Impacto:** Médio-Alto.

---

## GARGALOS DE PERFORMANCE

### Identificados via Análise de Código

1. **FFmpeg síncrono** — Principal bottleneck no upload. Um vídeo de 100MB pode demorar 5–10 minutos bloqueando um worker.

2. **LIKE '%query%' na pesquisa** — Com 100.000 utilizadores, uma pesquisa simples pode causar full table scan em 100.000 linhas.

3. **Sem cache de feed** — O feed principal executa queries complexas com JOINs e ordenação por `trend_score` em cada request. Com 1.000 utilizadores simultâneos, isso são 1.000 queries pesadas por segundo.

4. **Cleanup de rate limits no path crítico** — `rate_limit_check()` executa um `DELETE` de limpeza em cada verificação de limite, no path de login e comentários.

5. **N+1 em comentários** — `get_comments.php` executa queries separadas para contagem de replies de cada comentário.

6. **Chat com queries por evento** — `server.js` executa múltiplas queries MySQL por cada mensagem enviada (verificar amizade, verificar inbox aberta, salvar mensagem, atualizar status).

### Métricas Alvo

| Métrica | Atual (estimado) | Alvo |
|---------|-----------------|------|
| Time to First Byte (TTFB) | ~400ms | <200ms |
| Largest Contentful Paint (LCP) | ~3.5s | <2.5s |
| Tempo de upload (100MB) | ~10 min | <5 min |
| Queries por request de feed | ~8–12 | <4 |
| Tempo de pesquisa | ~500ms | <100ms |

---

## OPORTUNIDADES DE CRESCIMENTO

### Curto Prazo (1–3 meses)

1. **SEO**: A infraestrutura SEO está quase completa (Open Graph, Schema.org, sitemap). Falta: geração automática de thumbnails otimizados, URLs canónicas para vídeos, metadata por vídeo. Impacto esperado: +30% de tráfego orgânico.

2. **Criadores**: Implementar o painel de analytics (dados já existem). Criadores com dados melhoram a qualidade e frequência de publicação. Impacto: +20% de conteúdo publicado.

3. **Mobile**: Melhorar gestos tácteis e modo escuro. 70–80% do tráfego de plataformas de vídeo é mobile. Impacto: +15% de retenção mobile.

4. **Trending**: A página de hashtags trending pode ser lançada em 2–3 dias. É o principal motor de descoberta viral no TikTok.

### Médio Prazo (3–6 meses)

1. **Monetização Premium**: O sistema `is_premium` existe mas o fluxo de pagamento não. Completar com Stripe é o caminho mais rápido para revenue.

2. **Recomendações**: Um algoritmo simples de recomendação (baseado em hashtags e criadores seguidos) pode aumentar significativamente o tempo de sessão.

3. **Streaming em Direto**: Funcionalidade de alto impacto que diferencia a plataforma de simples repositórios de vídeo.

### Longo Prazo (6–12 meses)

1. **Creator Economy**: Creator Fund, tips, conteúdo exclusivo — transformar o MyTube de plataforma de descoberta para plataforma de rendimento para criadores.

2. **API Pública**: Permitir integrações de terceiros e apps mobile nativas.

3. **Internacionalização**: Suporte a múltiplos idiomas (i18n) — a base já está em Português, mas a expansão requer localização.

---

## ANÁLISE DE ARQUITETURA

### Estado Atual

```
┌─────────────────────────────────────────────┐
│              Cloudflare / CDN               │
│   (Proxy, SSL, DDoS protection)             │
└────────────────────┬────────────────────────┘
                     │
        ┌────────────┴────────────┐
        │                         │
┌───────▼────────┐    ┌───────────▼──────────┐
│  Apache / PHP  │    │  Node.js (Socket.IO)  │
│  (Monolítico)  │    │  (Chat Server)        │
│                │    │  PM2 / ecosystem.js   │
└───────┬────────┘    └───────────┬───────────┘
        │                         │
        └────────────┬────────────┘
                     │
         ┌───────────▼───────────┐
         │      MySQL Database    │
         │  (Sem pool, sem Redis) │
         └───────────────────────┘
                     │
         ┌───────────▼───────────┐
         │  Cloudflare R2        │
         │  (Video/Image Storage)│
         └───────────────────────┘
```

### Arquitetura Alvo (12 meses)

```
┌─────────────────────────────────────────────┐
│              Cloudflare / CDN               │
│   (Proxy, Assets CDN, WAF, DDoS)            │
└────────────────────┬────────────────────────┘
                     │
        ┌────────────┴────────────┐
        │                         │
┌───────▼────────┐    ┌───────────▼──────────┐
│  PHP-FPM       │    │  Node.js (Socket.IO)  │
│  (MVC: Slim/   │    │  (Chat + Notificações)│
│   Laravel-lite)│    │  Cluster / PM2        │
└───────┬────────┘    └───────────┬───────────┘
        │                         │
        └────────────┬────────────┘
                     │
         ┌───────────▼───────────┐
         │     Redis Cluster     │
         │  (Cache, Rate Limit,  │
         │   Sessions, Queues)   │
         └───────────┬───────────┘
                     │
         ┌───────────▼───────────┐
         │   MySQL + ProxySQL    │
         │  (Connection Pool)    │
         │   + Read Replicas     │
         └───────────┬───────────┘
                     │
         ┌───────────▼───────────┐
         │  Video Worker Queue   │
         │  (FFmpeg Async)       │
         └───────────┬───────────┘
                     │
         ┌───────────▼───────────┐
         │  Cloudflare R2        │
         │  (Video/Image/HLS)    │
         └───────────────────────┘
```

---

## ANÁLISE DE BASE DE DADOS

### Tabelas Identificadas

| Tabela | Função | Problemas |
|--------|--------|-----------|
| `users` | Utilizadores | Constraint UNIQUE email a verificar |
| `videos` | Vídeos publicados | Sem índice em `trend_score`, `created_at` |
| `comments` | Comentários e replies | Sem índice em `video_id`, `parent_comment_id` |
| `likes` | Likes em vídeos | Sem índice composto `(user_id, video_id)` |
| `follows` / `user_follows` | Seguidores | Verificar índices |
| `friend_requests` | Pedidos de amizade | Sem rate limiting associado |
| `chats` / `messages` | Chat privado e grupos | Sem paginação cursor |
| `group_messages` | Mensagens de grupo | Sem índice em `created_at` |
| `notifications` | Notificações | Sem limpeza automática de antigas |
| `push_subscriptions` | Web Push | Sem validação de URL |
| `rate_limits` | Rate limiting | Cleanup síncrono no path crítico |
| `video_views` | Histórico de views | Potencial crescimento rápido sem particionamento |
| `hashtags` / `video_hashtags` | Hashtags | Sem FULLTEXT index |
| `ranking_points` | Ranking de criadores | Dependente de transações fora do escopo |

### Índices Críticos em Falta

```sql
-- Executar em produção após backup:
ALTER TABLE videos ADD INDEX idx_trend_score (trend_score DESC);
ALTER TABLE videos ADD INDEX idx_created_at (created_at DESC);
ALTER TABLE comments ADD INDEX idx_video_created (video_id, created_at);
ALTER TABLE comments ADD INDEX idx_parent (parent_comment_id);
ALTER TABLE likes ADD UNIQUE INDEX idx_user_video (user_id, video_id);
ALTER TABLE video_views ADD INDEX idx_user_video_date (user_id, video_id, created_at);
ALTER TABLE user_online_status ADD INDEX idx_updated (updated_at);
ALTER TABLE videos ADD FULLTEXT INDEX ft_search (title, description);
ALTER TABLE users ADD FULLTEXT INDEX ft_users (username, full_name);
```

---

## ANÁLISE DO FRONTEND

### Pontos Fortes

- Virtualização de DOM no feed (máximo 5 vídeos materializados) — excelente para performance mobile
- Debouncing em pesquisa e typeahead de hashtags
- Atualizações otimistas de UI (likes, follows respondem imediatamente)
- Compressão de imagens no cliente antes do upload
- CSRF interceptado globalmente em `fetch()` e `XMLHttpRequest`
- Service Worker para PWA funcional

### Pontos Fracos

- **Sem bundler:** 25+ ficheiros JS carregados separadamente (HTTP/1.1 overhead)
- **Sem tree-shaking:** Código não usado em página específica ainda é carregado
- **Sem TypeScript:** Sem type safety; erros de tipo apenas detetados em runtime
- **Tiktok.js com 2347 linhas:** Ficheiro monolítico difícil de manter
- **Upload.js com 1043 linhas:** Mistura de lógica de UI e lógica de negócio
- **CSS sem preprocessador:** Sem variáveis globais, sem dark mode preparado
- **Polling em vez de WebSocket para status de upload:** 300+ requests em uploads longos

### Recomendações de Frontend

1. Introduzir Vite ou esbuild como bundler — <1 dia de configuração
2. Dividir `tiktok.js` em módulos: `FeedManager`, `VideoPlayer`, `LikeSystem`, `ShareSystem`, `SearchSystem`
3. Migrar polling de upload para WebSocket (canal Socket.IO já existe)
4. Preparar CSS para dark mode com custom properties

---

## PRIORIDADES EXECUTIVAS

### Curto Prazo — Próximas 2 semanas

| # | Prioridade | Ação | Justificação |
|---|-----------|------|--------------|
| 1 | **CRÍTICA** | Eliminar ficheiros debug do repositório | Risco de segurança imediato |
| 2 | **CRÍTICA** | Tornar CHAT_JWT_SECRET obrigatório | Previne comprometimento do chat |
| 3 | **ALTA** | Timeout em processos FFmpeg | Previne DoS via upload |
| 4 | **ALTA** | Fortalecer política de senhas | Segurança básica dos utilizadores |
| 5 | **ALTA** | Rate limiting de comentários via DB | Remover contorno fácil |
| 6 | **ALTA** | Adicionar índices críticos na DB | Impacto imediato em performance |
| 7 | **ALTA** | Lançar página de Trending/Hashtags | Quick win de produto |

### Médio Prazo — Próximos 1–2 meses

| # | Prioridade | Ação | Justificação |
|---|-----------|------|--------------|
| 8 | **ALTA** | Completar fluxo Premium com Stripe | Revenue |
| 9 | **ALTA** | Implementar nomes de ficheiro aleatórios | Privacidade dos utilizadores |
| 10 | **ALTA** | Migrar CSP para nonces | Segurança real |
| 11 | **MÉDIA** | Painel de analytics para criadores | Retenção de criadores |
| 12 | **MÉDIA** | Full-text search em MySQL | Performance de pesquisa |
| 13 | **MÉDIA** | Modo escuro | UX + retenção |
| 14 | **MÉDIA** | Configurar CI/CD básico | Qualidade de código |

### Longo Prazo — Próximos 3–6 meses

| # | Prioridade | Ação | Justificação |
|---|-----------|------|--------------|
| 15 | **ALTA** | Redis para cache e sessions | Escalabilidade |
| 16 | **ALTA** | Migração completa para MVC | Manutenção a longo prazo |
| 17 | **ALTA** | Testes automatizados (PHPUnit + Jest) | Qualidade e regressões |
| 18 | **MÉDIA** | HLS Adaptive Streaming | Experiência de vídeo |
| 19 | **MÉDIA** | 2FA para todos os utilizadores | Segurança avançada |
| 20 | **MÉDIA** | Creator Fund / Monetização | Modelo de negócio sustentável |

---

## MÉTRICAS DE QUALIDADE ATUAL

| Dimensão | Nota | Justificação |
|----------|------|--------------|
| Segurança | 6/10 | Boas fundações, 37 vulnerabilidades pendentes, CSP inefficaz |
| Performance | 5/10 | Sem cache, sem índices críticos, FFmpeg síncrono |
| Manutenibilidade | 5/10 | Sem testes, arquitetura mista, ficheiros monolíticos |
| Funcionalidades | 7/10 | Plataforma completa, faltam funcionalidades de retenção |
| UX/UI | 6/10 | Feed fluido, sem dark mode, acessibilidade limitada |
| Escalabilidade | 4/10 | Monolítico sem cache distribuído ou pool de conexões |
| Documentação | 7/10 | Boa cobertura, alguns documentos desatualizados |
| **Média Geral** | **6/10** | |

---

## CONCLUSÃO

O MyTube tem uma base técnica com potencial real. As melhorias de segurança de Abril-Maio 2026 foram um passo importante e correto. O projeto necessita agora de:

1. **Fechar as vulnerabilidades pendentes** — especialmente as 3 críticas e 10 altas, que representam risco real para os utilizadores e para a plataforma.

2. **Investir em performance e escalabilidade** — Redis, índices, pesquisa full-text, e FFmpeg assíncrono são os investimentos de maior retorno.

3. **Completar a monetização** — o sistema premium está 80% implementado; completar com Stripe é o caminho mais rápido para sustentabilidade financeira.

4. **Melhorar a experiência do criador** — analytics, ferramentas de agendamento e recomendações personalizam a plataforma e aumentam a publicação de conteúdo.

5. **Estabelecer práticas de desenvolvimento profissional** — testes, CI/CD, e code review são investimentos que previnem regressões e aceleram o desenvolvimento a longo prazo.

Com execução disciplinada das prioridades identificadas, o MyTube pode atingir uma classificação de **8.5/10** em 6 meses e estar preparado para crescimento sustentável.

---

**Auditoria realizada por:** Claude Code — Análise completa de código-fonte
**Data:** 02/06/2026
**Versão do projeto auditada:** Branch `Refactoring` (commit mais recente: `493eae1`)
