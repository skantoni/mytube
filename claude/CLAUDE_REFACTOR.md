# MyTube — MVC Refactoring Script for Claude Code

## Goal
Refactor the MyTube PHP codebase from a flat procedural structure into a clean
MVC-style architecture using Repository, Service, and Controller layers.
Do NOT break existing functionality. Refactor incrementally, one domain at a time.

---

## Rules (follow strictly)

- Never delete original files — move them to `legacy/` with a `.bak` extension
- After each file creation, verify it is syntactically valid PHP (`php -l <file>`)
- Never introduce new external dependencies (no Composer packages unless already present)
- Preserve all existing SQL queries exactly — only move them, do not rewrite logic
- All new classes must use strict types: `declare(strict_types=1);`
- Follow PSR-4 autoloading: namespace `MyTube\` maps to `app/`
- Every public method must have a PHPDoc block
- Never log sensitive data (passwords, emails, tokens, $_SESSION contents)

---

## Target Directory Structure

```
/
├── app/
│   ├── Repositories/       # Database access only — no business logic
│   │   ├── UserRepository.php
│   │   ├── VideoRepository.php
│   │   ├── CommentRepository.php
│   │   └── ChatRepository.php
│   │
│   ├── Services/           # Business logic + orchestration
│   │   ├── AuthService.php
│   │   ├── VideoService.php
│   │   ├── CommentService.php
│   │   ├── NotificationService.php
│   │   └── EmailVerificationService.php
│   │
│   ├── Validators/         # Input validation — reusable
│   │   ├── UserValidator.php
│   │   └── VideoValidator.php
│   │
│   └── Core/
│       ├── Database.php    # PDO singleton
│       ├── Response.php    # Standardised JSON responses
│       └── Auth.php        # Session/JWT helpers
│
├── api/                    # HTTP entry points — thin, no business logic
│   ├── add_comment.php
│   ├── upload_video.php
│   └── ...
│
├── legacy/                 # Original files (do not delete, keep as reference)
└── includes/               # Existing config/helpers (keep untouched initially)
```

---

## Step 1 — Scan the codebase

```bash
find . -name "*.php" -not -path "./legacy/*" -not -path "./vendor/*" | sort
```

Then identify and list:
1. All files that contain `$pdo->prepare(` — these need a Repository
2. All files that contain `$_POST` or `$_GET` — these are HTTP entry points
3. All files that contain `INSERT INTO notifications` — these need NotificationService
4. All files that contain `error_log(` or `file_put_contents(` with session/email data

Report findings before proceeding.

---

## Step 2 — Create Core infrastructure

### 2a. app/Core/Database.php
PDO singleton. Read credentials from existing `includes/config.php`.
Do NOT hardcode credentials.

```php
<?php
declare(strict_types=1);
namespace MyTube\Core;

class Database {
    private static ?Database $instance = null;
    private \PDO $pdo;

    private function __construct() {
        // Load from existing config
        require_once dirname(__DIR__, 2) . '/includes/config.php';
        $this->pdo = $pdo; // reuse existing $pdo from config
    }

    public static function getInstance(): self { ... }
    public function getConnection(): \PDO { return $this->pdo; }
}
```

### 2b. app/Core/Response.php
Standardised JSON response — replaces ad-hoc `echo json_encode(...)` across all APIs.

```php
<?php
declare(strict_types=1);
namespace MyTube\Core;

class Response {
    public static function success(mixed $data = null, string $message = 'OK', int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
        exit;
    }

    public static function error(string $message, int $code = 400, array $errors = []): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message, 'errors' => $errors]);
        exit;
    }
}
```

### 2c. app/Core/Auth.php
Extract all session/auth helpers from `includes/config.php` or wherever they live.
Methods: `getCurrentUserId()`, `isLoggedIn()`, `requireAuth()`, `getSessionToken()`.

---

## Step 3 — Create Validators

### app/Validators/UserValidator.php
Extract ALL validation logic currently spread across `login.php` and any register endpoint.

Must implement:
- `validateUsername(string $username): array` — returns array of error strings
- `validateEmail(string $email): array`
- `validatePassword(string $password): array`
- `validateRegistration(array $data): array` — runs all three above

DNS check must have a try/catch fallback (never block registration if DNS times out).

```php
public function validateEmail(string $email): array {
    $errors = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
        return $errors;
    }
    $domain = substr(strrchr($email, '@'), 1);
    try {
        if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            $errors[] = 'Email domain does not exist.';
        }
    } catch (\Throwable) {
        // DNS check failed — do not block registration
    }
    return $errors;
}
```

---

## Step 4 — Create Repositories (data layer only)

For each repository, extract ONLY the SQL queries from existing files.
No validation, no HTTP, no notifications inside repositories.

### app/Repositories/UserRepository.php
Methods to extract from existing code:
- `findByUsername(string $username): ?array`
- `findByEmail(string $email): ?array`
- `emailExists(string $email): bool`
- `usernameExists(string $username): bool`
- `create(string $username, string $email, string $fullName, string $hashedPassword, string $instituicao): int`
- `updatePassword(int $userId, string $hashedPassword): void`
- `setVerifyToken(int $userId, string $token, string $expires): void`
- `activateByToken(string $token): bool`

### app/Repositories/CommentRepository.php
Methods:
- `create(int $userId, int $videoId, string $text, ?int $parentId): int`
- `findByVideo(int $videoId, int $limit, int $offset): array`
- `delete(int $commentId, int $userId): bool`

### app/Repositories/VideoRepository.php
Methods:
- `create(int $userId, string $title, string $description, string $filename): int`
- `findById(int $videoId): ?array`
- `findByUser(int $userId): array`
- `updateThumbnail(int $videoId, string $path): void`
- `delete(int $videoId, int $userId): bool`

### app/Repositories/ChatRepository.php
Methods:
- `createConversation(int $userA, int $userB): int`
- `findConversation(int $userA, int $userB): ?array`
- `saveMessage(int $conversationId, int $senderId, string $message): int`
- `getMessages(int $conversationId, int $limit): array`

---

## Step 5 — Create Services (business logic)

### app/Services/AuthService.php
```php
public function register(array $data): array {
    // 1. Validate via UserValidator
    // 2. Check uniqueness via UserRepository
    // 3. Hash password
    // 4. Create user via UserRepository
    // 5. Generate verification token
    // 6. Send verification email via EmailVerificationService
    // 7. Return result — never throw raw exceptions to HTTP layer
}

public function login(string $usernameOrEmail, string $password): array {
    // 1. Find user via UserRepository
    // 2. Verify password_verify with timing-safe dummy hash on failure
    // 3. Regenerate session
    // 4. Return user data (never return password hash)
}
```

### app/Services/CommentService.php
```php
public function createComment(int $userId, int $videoId, string $text, ?int $parentId = null): array {
    // 1. Validate input
    // 2. CommentRepository->create()
    // 3. NotificationService->notifyCommentAuthor()
    // 4. Return created comment data
}
```

### app/Services/NotificationService.php
Extract ALL `INSERT INTO notifications` queries currently scattered across files.
Single responsibility: create notifications. Nothing else.

```php
public function notify(int $toUserId, int $fromUserId, string $type, ?int $referenceId = null): void {
    // Single INSERT — reused everywhere
}
```

### app/Services/EmailVerificationService.php
Implement the email verification flow discussed:
- `sendVerificationEmail(int $userId, string $email): void`
- `verifyToken(string $token): bool`

---

## Step 6 — Refactor API entry points

Each file in `api/` must become thin — 10 to 20 lines maximum.

Pattern for every API file:
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';

use MyTube\Core\{Auth, Response};
use MyTube\Services\CommentService;
use MyTube\Repositories\CommentRepository;

// 1. Auth check
$userId = Auth::requireAuth(); // exits with 401 if not logged in

// 2. Input — sanitised, nothing else
$videoId = (int)($_POST['video_id'] ?? 0);
$text    = trim($_POST['text'] ?? '');

// 3. Service call
$service = new CommentService(new CommentRepository($pdo));
$result  = $service->createComment($userId, $videoId, $text);

// 4. Response
Response::success($result, 'Comment created', 201);
```

---

## Step 7 — Security checks during refactor

While refactoring each file, fix these if found:
- `SELECT *` in any query → replace with explicit column list
- `$e->getMessage()` in any JSON response → replace with generic message, log internally
- `$_SESSION` contents in any log → remove
- Raw `userId` from client in Socket.IO → flag with comment `// TODO: replace with server-side JWT validation`
- Any `error_log` with email/password/token data → remove

---

## Step 8 — Autoloader

Create `autoload.php` in project root:

```php
<?php
spl_autoload_register(function (string $class): void {
    $prefix    = 'MyTube\\';
    $base_dir  = __DIR__ . '/app/';
    $len       = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) return;

    $relative = substr($class, $len);
    $file     = $base_dir . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) require $file;
});
```

Add `require_once __DIR__ . '/autoload.php';` to `includes/config.php`.

---

## Step 9 — Validation

After each domain is refactored, run:

```bash
# Syntax check all new files
find app/ -name "*.php" -exec php -l {} \;

# Check no $e->getMessage() leaks into responses
grep -rn "getMessage" api/ --include="*.php"

# Check no $_SESSION in logs
grep -rn "_SESSION" app/ --include="*.php"

# Check no SELECT * in repositories
grep -rn "SELECT \*" app/Repositories/ --include="*.php"
```

---

## Refactoring order (do not skip steps)

1. `app/Core/Database.php`
2. `app/Core/Response.php`
3. `app/Core/Auth.php`
4. `app/Validators/UserValidator.php`
5. `app/Repositories/UserRepository.php`
6. `app/Services/AuthService.php`
7. Refactor `login.php` to use AuthService
8. `app/Repositories/CommentRepository.php`
9. `app/Services/NotificationService.php`
10. `app/Services/CommentService.php`
11. Refactor `api/add_comment.php`
12. Repeat pattern for Video and Chat domains

---

## Definition of Done

- [ ] All `api/*.php` files are under 25 lines
- [ ] Zero `$pdo->prepare(` calls outside `app/Repositories/`
- [ ] Zero `INSERT INTO notifications` outside `NotificationService`
- [ ] Zero `$_SESSION` data in any log
- [ ] Zero `$e->getMessage()` in any JSON response
- [ ] All new PHP files pass `php -l`
- [ ] Existing features still work (manual smoke test: login, register, comment, upload)