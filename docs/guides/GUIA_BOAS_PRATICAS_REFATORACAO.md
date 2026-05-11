# 🔧 GUIA DE BOAS PRÁTICAS E REFATORAÇÃO - MyTube

## 1. RESTRUTURAÇÃO DE DOCUMENTAÇÃO

### 1.1 Nova Estrutura de Pastas
```bash
# Criar estrutura de documentação organizada
mkdir -p docs/{guides,fixes,api,deployment}

# Mover e consolidar documentos
mv README.md docs/README.md  # Principal (índice)
mv INSTALL.md docs/INSTALLATION.md
mv DEPLOY.md docs/deployment/DEPLOY.md
mv CHAT_README.md docs/guides/CHAT.md
mv CHAT_TESTING.md docs/guides/CHAT_TESTING.md
mv COMENTARIOS_REALTIME_README.md docs/guides/COMMENTS.md
mv MESSAGE_STATUS_README.md docs/guides/MESSAGE_STATUS.md
mv MODERATION_VPS_SETUP.md docs/deployment/MODERATION.md
mv PUSH_NOTIFICATIONS_VPS_SETUP.md docs/deployment/NOTIFICATIONS.md

# Consolidar FIX_*.md em histórico
mkdir -p docs/fixes/archive
mv FIX_*.md docs/fixes/archive/
mv NGINX_*.md docs/deployment/nginx/
```

### 1.2 Novo docs/README.md (Índice)
```markdown
# MyTube Documentation

## 📖 Quick Links

### Getting Started
- [Installation](./INSTALLATION.md)
- [Quick Start](./QUICK_START.md)

### Features
- [Chat System](./guides/CHAT.md)
- [Comments & Replies](./guides/COMMENTS.md)
- [Real-time Notifications](./guides/MESSAGE_STATUS.md)
- [Content Moderation](./guides/MODERATION.md)

### Deployment
- [Deployment Guide](./deployment/DEPLOY.md)
- [Nginx Configuration](./deployment/nginx/)
- [Performance Optimization](./QUICK_START_PERFORMANCE.md)
- [SSL/HTTPS Setup](./deployment/SSL.md)

### API Reference
- [REST API](./api/REST.md)
- [WebSocket Events](./api/WEBSOCKET.md)
- [Authentication](./api/AUTH.md)

### Troubleshooting
- [Common Issues](./TROUBLESHOOTING.md)
- [Bug Fixes](./fixes/archive/)
```

---

## 2. MELHORAS DE CÓDIGO PHP

### 2.1 Criar Estrutura MVC
```bash
mkdir -p app/{Controllers,Services,Repositories,Models,Exceptions}
mkdir -p config
mkdir -p routes

# Relocate config
mkdir -p app/Config
mv includes/config.example.php app/Config/config.example.php
```

### 2.2 Exemplo: Refatoração de add_comment.php

**Antes (304 linhas monolíticas)**:
```php
// api/add_comment.php - Tudo aqui
```

**Depois (Separado em camadas)**:

#### app/Models/Comment.php
```php
namespace App\Models;

class Comment {
    public int $id;
    public int $user_id;
    public int $video_id;
    public string $comment_text;
    public ?int $parent_comment_id;
    public int $likes_count;
    public \DateTime $created_at;
    
    public function getReplyThread() {
        // Lógica de obtenção de respostas
    }
    
    public function isEditableBy(int $userId, int $maxAgeSeconds = 120): bool {
        $age = time() - $this->created_at->getTimestamp();
        return $this->user_id === $userId && $age <= $maxAgeSeconds;
    }
}
```

#### app/Repositories/CommentRepository.php
```php
namespace App\Repositories;

use App\Models\Comment;
use PDO;

class CommentRepository {
    public function __construct(private PDO $pdo) {}
    
    public function create(
        int $userId,
        int $videoId,
        string $text,
        ?int $parentCommentId = null
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO comments (user_id, video_id, comment_text, parent_comment_id)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$userId, $videoId, $text, $parentCommentId]);
        return (int)$this->pdo->lastInsertId();
    }
    
    public function getById(int $commentId): ?Comment {
        $stmt = $this->pdo->prepare("
            SELECT * FROM comments WHERE id = ?
        ");
        $stmt->execute([$commentId]);
        
        if (!$row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return null;
        }
        
        return $this->hydrateComment($row);
    }
    
    public function getByVideoId(int $videoId, int $offset = 0, int $limit = 8): array {
        $stmt = $this->pdo->prepare("
            SELECT c.*, u.username, u.profile_picture
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.video_id = ? AND c.parent_comment_id IS NULL
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$videoId, $limit, $offset]);
        return array_map([$this, 'hydrateComment'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    private function hydrateComment(array $data): Comment {
        $comment = new Comment();
        $comment->id = (int)$data['id'];
        $comment->user_id = (int)$data['user_id'];
        // ... mapeamento
        return $comment;
    }
}
```

#### app/Services/CommentService.php
```php
namespace App\Services;

use App\Repositories\CommentRepository;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use Psr\Log\LoggerInterface;

class CommentService {
    private const MAX_LENGTH = 500;
    private const MAX_MENTIONS = 5;
    private const RATE_LIMIT_INTERVAL = 3; // segundos
    
    public function __construct(
        private CommentRepository $repository,
        private NotificationService $notificationService,
        private RateLimiter $rateLimiter,
        private LoggerInterface $logger
    ) {}
    
    public function createComment(
        int $userId,
        int $videoId,
        string $text,
        ?int $parentCommentId = null
    ): array {
        try {
            // 1. Validação
            $this->validateComment($userId, $text);
            
            // 2. Rate limiting
            $this->rateLimiter->check("comment:$userId", self::RATE_LIMIT_INTERVAL);
            
            // 3. Persistência
            $commentId = $this->repository->create(
                $userId,
                $videoId,
                $text,
                $parentCommentId
            );
            
            // 4. Orquestração de notificações (não-bloqueante)
            $this->dispatchNotifications($userId, $videoId, $commentId, $parentCommentId, $text);
            
            // 5. Buscar comentário formatado
            $comment = $this->repository->getById($commentId);
            
            $this->logger->info('Comment created', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'video_id' => $videoId
            ]);
            
            return [
                'success' => true,
                'comment' => $this->formatComment($comment)
            ];
            
        } catch (ValidationException $e) {
            $this->logger->warning('Comment validation failed', ['error' => $e->getMessage()]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Comment creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    private function validateComment(int $userId, string $text): void {
        $text = trim($text);
        
        if (empty($text)) {
            throw new ValidationException('Comentário não pode estar vazio');
        }
        
        if (strlen($text) > self::MAX_LENGTH) {
            throw new ValidationException("Comentário muito longo (máximo " . self::MAX_LENGTH . " caracteres)");
        }
    }
    
    private function dispatchNotifications(
        int $userId,
        int $videoId,
        int $commentId,
        ?int $parentCommentId,
        string $text
    ): void {
        // Usar fila (queue job) para não bloquear resposta HTTP
        $this->notificationService->enqueue(new CommentNotificationJob(
            userId: $userId,
            videoId: $videoId,
            commentId: $commentId,
            parentCommentId: $parentCommentId,
            text: $text
        ));
    }
    
    private function formatComment($comment): array {
        return [
            'id' => $comment->id,
            'text' => $comment->comment_text,
            'username' => $comment->username,
            'user_id' => $comment->user_id,
            'created_at' => $comment->created_at->format(\DateTime::ATOM),
            'time_ago' => timeAgo($comment->created_at),
            'can_edit' => $comment->isEditableBy(currentUserId()),
            'can_delete' => $comment->user_id === currentUserId(),
            'likes_count' => $comment->likes_count,
            'replies_count' => count($comment->getReplyThread())
        ];
    }
}
```

#### api/comments.php (Nova API cleanly designed)
```php
<?php
namespace MyTube\Api;

require_once '../vendor/autoload.php';
require_once '../bootstrap.php';

use App\Services\CommentService;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;

header('Content-Type: application/json');

try {
    // 1. Autenticação
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    // 2. CSRF
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }
    
    // 3. Parsing
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // 4. Rotear para handlers
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    $service = container(CommentService::class);
    
    switch ($method) {
        case 'POST':
            $result = $service->createComment(
                userId: (int)$_SESSION['user_id'],
                videoId: (int)($input['video_id'] ?? 0),
                text: (string)($input['text'] ?? ''),
                parentCommentId: isset($input['parent_id']) ? (int)$input['parent_id'] : null
            );
            echo json_encode($result);
            break;
            
        case 'DELETE':
            $commentId = (int)($input['id'] ?? 0);
            $result = $service->deleteComment($commentId, (int)$_SESSION['user_id']);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (ValidationException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RateLimitException $e) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log($e);
}
?>
```

---

## 3. MELHORAS DE CÓDIGO JAVASCRIPT

### 3.1 Refatoração de comments-new.js

**Estrutura Modular Proposta**:
```javascript
// modules/CommentAPI.js
export class CommentAPI {
    constructor(baseUrl = '/api') {
        this.baseUrl = baseUrl;
    }
    
    async create(videoId, text, parentId = null) {
        const response = await fetch(`${this.baseUrl}/comments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({
                video_id: videoId,
                text,
                parent_id: parentId
            })
        });
        
        if (!response.ok) {
            throw new Error(await response.text());
        }
        
        return response.json();
    }
    
    async delete(commentId) {
        // ...
    }
    
    async edit(commentId, text) {
        // ...
    }
    
    async list(videoId, offset = 0, limit = 8) {
        // ...
    }
}

// modules/CommentValidator.js
export class CommentValidator {
    static MAX_LENGTH = 500;
    static MIN_LENGTH = 1;
    
    static validate(text) {
        const errors = [];
        
        text = text.trim();
        
        if (text.length < this.MIN_LENGTH) {
            errors.push('Comentário não pode estar vazio');
        }
        
        if (text.length > this.MAX_LENGTH) {
            errors.push(`Máximo ${this.MAX_LENGTH} caracteres`);
        }
        
        return {
            valid: errors.length === 0,
            errors
        };
    }
}

// modules/CommentRenderer.js
export class CommentRenderer {
    constructor(container) {
        this.container = container;
    }
    
    render(comments) {
        return comments.map(comment => this.renderComment(comment)).join('');
    }
    
    renderComment(comment) {
        const isEditable = comment.can_edit;
        const isDeletable = comment.can_delete;
        
        return `
            <div class="comment" data-comment-id="${comment.id}">
                <div class="comment__header">
                    <img src="${comment.profile_picture}" alt="avatar" class="comment__avatar">
                    <div class="comment__author">
                        <strong>${comment.username}</strong>
                        <span class="comment__time">${comment.time_ago}</span>
                    </div>
                    ${isEditable || isDeletable ? this.renderOptionsMenu(comment) : ''}
                </div>
                
                <p class="comment__text">${escapeHtml(comment.text)}</p>
                
                <div class="comment__actions">
                    <button class="comment__like" data-comment-id="${comment.id}">
                        👍 ${comment.likes_count}
                    </button>
                    <button class="comment__reply" data-comment-id="${comment.id}">
                        💬 Responder
                    </button>
                </div>
                
                ${comment.replies_count > 0 ? `
                    <button class="comment__show-replies" data-comment-id="${comment.id}">
                        Ver ${comment.replies_count} respostas
                    </button>
                ` : ''}
            </div>
        `;
    }
    
    renderOptionsMenu(comment) {
        const { can_edit, can_delete, id } = comment;
        
        return `
            <div class="comment__options">
                <button class="comment__options-btn" aria-label="Opções">⋮</button>
                <div class="comment__options-menu" hidden>
                    ${can_edit ? `<button class="comment__edit" data-comment-id="${id}">Editar</button>` : ''}
                    ${can_delete ? `<button class="comment__delete" data-comment-id="${id}">Deletar</button>` : ''}
                </div>
            </div>
        `;
    }
}

// modules/CommentManager.js
export class CommentManager {
    constructor(containerId, videoId, api, renderer, validator) {
        this.container = document.getElementById(containerId);
        this.videoId = videoId;
        this.api = api;
        this.renderer = renderer;
        this.validator = validator;
        this.comments = [];
        this.offset = 0;
        this.limit = 8;
        
        this.init();
    }
    
    init() {
        this.setupEventDelegation();
        this.loadComments();
    }
    
    setupEventDelegation() {
        this.container.addEventListener('click', (e) => {
            const target = e.target.closest('[class*="comment__"]');
            
            if (target.classList.contains('comment__reply')) {
                this.showReplyForm(target.dataset.commentId);
            } else if (target.classList.contains('comment__delete')) {
                this.deleteComment(target.dataset.commentId);
            } else if (target.classList.contains('comment__like')) {
                this.likeComment(target.dataset.commentId);
            } else if (target.classList.contains('comment__edit')) {
                this.showEditForm(target.dataset.commentId);
            }
        });
    }
    
    async loadComments() {
        try {
            const data = await this.api.list(this.videoId, this.offset, this.limit);
            this.comments = data.comments;
            this.render();
        } catch (error) {
            console.error('Failed to load comments:', error);
            this.showError('Não foi possível carregar comentários');
        }
    }
    
    async addComment(text, parentId = null) {
        const validation = this.validator.validate(text);
        if (!validation.valid) {
            validation.errors.forEach(err => this.showError(err));
            return;
        }
        
        try {
            const result = await this.api.create(this.videoId, text, parentId);
            
            if (parentId) {
                this.addReply(result.comment, parentId);
            } else {
                this.comments.unshift(result.comment);
            }
            
            this.render();
            this.showSuccess('Comentário adicionado');
        } catch (error) {
            this.showError('Erro ao adicionar comentário');
        }
    }
    
    async deleteComment(commentId) {
        if (!confirm('Deletar este comentário?')) return;
        
        try {
            await this.api.delete(commentId);
            this.comments = this.comments.filter(c => c.id !== parseInt(commentId));
            this.render();
            this.showSuccess('Comentário deletado');
        } catch (error) {
            this.showError('Erro ao deletar comentário');
        }
    }
    
    render() {
        this.container.innerHTML = this.renderer.render(this.comments);
    }
    
    showError(message) {
        // Toast/notification
        console.error(message);
    }
    
    showSuccess(message) {
        console.log(message);
    }
}

// main.js - Usage
import { CommentAPI } from './modules/CommentAPI.js';
import { CommentValidator } from './modules/CommentValidator.js';
import { CommentRenderer } from './modules/CommentRenderer.js';
import { CommentManager } from './modules/CommentManager.js';

document.addEventListener('DOMContentLoaded', () => {
    const api = new CommentAPI();
    const validator = new CommentValidator();
    const renderer = new CommentRenderer();
    
    new CommentManager('comments-container', videoId, api, renderer, validator);
});
```

---

## 4. OTIMIZAÇÕES DE PERFORMANCE

### 4.1 Implementar Query Caching
```php
// app/Services/CacheService.php
class CacheService {
    public function __construct(private Redis $redis) {}
    
    public function rememberQuery(
        string $key,
        int $ttl,
        callable $query
    ) {
        $cached = $this->redis->get($key);
        
        if ($cached !== null) {
            return json_decode($cached, true);
        }
        
        $result = $query();
        $this->redis->setex($key, $ttl, json_encode($result));
        
        return $result;
    }
}

// Uso em Repository
public function getPopularVideos(): array {
    return $this->cache->rememberQuery(
        'videos:popular:week',
        3600, // 1 hora
        fn() => $this->queryPopularVideos()
    );
}
```

### 4.2 Implementar Pagination Automática
```javascript
// modules/PaginatedList.js
export class PaginatedList {
    constructor(container, loader, options = {}) {
        this.container = container;
        this.loader = loader;
        this.items = [];
        this.offset = options.offset || 0;
        this.limit = options.limit || 10;
        this.hasMore = true;
        
        this.setupIntersectionObserver();
    }
    
    setupIntersectionObserver() {
        this.observer = new IntersectionObserver(
            entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && this.hasMore) {
                        this.loadMore();
                    }
                });
            },
            { rootMargin: '100px' }
        );
    }
    
    async loadMore() {
        const newItems = await this.loader(this.offset, this.limit);
        
        if (newItems.length < this.limit) {
            this.hasMore = false;
        }
        
        this.items.push(...newItems);
        this.offset += this.limit;
        this.render();
    }
    
    render() {
        // Renderizar items
    }
}
```

---

## 5. CHECKLIST DE IMPLEMENTAÇÃO

### Fase 1: Documentação (1-2 dias)
- [ ] Consolidar docs em `/docs` folder
- [ ] Criar docs/README.md como índice
- [ ] Atualizar links internos

### Fase 2: Backend Refatoração (2-4 semanas)
- [ ] Criar estrutura MVC básica
- [ ] Extrair Repositories para 3 entidades principais (Comments, Videos, Users)
- [ ] Criar Services para lógica de negócio
- [ ] Implementar Dependency Injection container
- [ ] Migrar api/add_comment.php para novo padrão

### Fase 3: Frontend Refatoração (2-3 semanas)
- [ ] Modularizar comments-new.js
- [ ] Criar FormValidator reutilizável
- [ ] Implementar EventManager centralizado
- [ ] Converter para módulos ES6

### Fase 4: Performance (1-2 semanas)
- [ ] Adicionar Redis cache
- [ ] Implementar query optimization e índices
- [ ] Adicionar pagination Intersection Observer
- [ ] Setup Redis para estado distribuído (chat-server)

### Fase 5: Testing (Ongoing)
- [ ] PHPUnit tests para Repositories
- [ ] Jest tests para módulos JS
- [ ] Postman collections para APIs
- [ ] Load testing com JMeter

---

**Próximos passos**: Começar pela **Fase 1** (documentação), depois **Backend Refatoração** para máximo impacto.
