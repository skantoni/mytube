<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * API: Consultar Best MyTuber da Semana
 * ═══════════════════════════════════════════════════════════════
 * 
 * Actions:
 * - current       → Vencedores ativos agora (badge visível)
 * - this_week     → Vencedores desta semana (mesmo que badge não esteja visível)
 * - check_user    → Verifica se um user_id específico é best mytuber agora
 * - history       → Histórico de vencedores
 * - candidates    → Rankings de candidatos de uma semana
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$action = $_GET['action'] ?? 'current';

try {
    $now = new DateTime('now', new DateTimeZone('Africa/Luanda'));
    $now_str = $now->format('Y-m-d H:i:s');
    
    switch ($action) {
        
        // ═══════════════════════════════════════
        // VENCEDORES ATIVOS (badge visível agora)
        // Retorna os best mytubers cujo badge está visível
        // ═══════════════════════════════════════
        case 'current':
            $stmt = $pdo->prepare("
                SELECT 
                    bm.*,
                    u.username,
                    u.full_name,
                    u.profile_picture,
                    u.is_verified,
                    u.school_id AS user_school_id,
                    s.name AS school_name,
                    s.short_name AS school_short
                FROM best_mytuber_weekly bm
                INNER JOIN users u ON bm.user_id = u.id
                LEFT JOIN schools s ON bm.school_id = s.id
                WHERE bm.badge_visible_from <= ?
                  AND bm.badge_visible_until >= ?
                ORDER BY bm.scope = 'global' DESC, bm.total_score DESC
            ");
            $stmt->execute([$now_str, $now_str]);
            $winners = $stmt->fetchAll();
            
            foreach ($winners as &$w) {
                $w['profile_picture_url'] = 'assets/images/avatars/' . ($w['profile_picture'] ?? 'default.webp');
                $w['total_score'] = (float)$w['total_score'];
                $w['is_badge_active'] = true;
            }
            
            echo json_encode([
                'success' => true,
                'badge_active' => !empty($winners),
                'now' => $now_str,
                'winners' => $winners
            ]);
            break;
        
        // ═══════════════════════════════════════
        // VENCEDORES DESTA SEMANA (mesmo sem badge visível)
        // ═══════════════════════════════════════
        case 'this_week':
            $dayOfWeek = (int)$now->format('N');
            $monday = clone $now;
            if ($dayOfWeek > 1) {
                $monday->modify('-' . ($dayOfWeek - 1) . ' days');
            }
            $week_start = $monday->format('Y-m-d');
            
            $stmt = $pdo->prepare("
                SELECT 
                    bm.*,
                    u.username,
                    u.full_name,
                    u.profile_picture,
                    u.is_verified,
                    s.name AS school_name,
                    s.short_name AS school_short
                FROM best_mytuber_weekly bm
                INNER JOIN users u ON bm.user_id = u.id
                LEFT JOIN schools s ON bm.school_id = s.id
                WHERE bm.week_start = ?
                ORDER BY bm.scope = 'global' DESC, bm.total_score DESC
            ");
            $stmt->execute([$week_start]);
            $winners = $stmt->fetchAll();
            
            $badge_active = false;
            foreach ($winners as &$w) {
                $w['profile_picture_url'] = 'assets/images/avatars/' . ($w['profile_picture'] ?? 'default.webp');
                $w['total_score'] = (float)$w['total_score'];
                $w['is_badge_active'] = ($now_str >= $w['badge_visible_from'] && $now_str <= $w['badge_visible_until']);
                if ($w['is_badge_active']) $badge_active = true;
            }
            
            echo json_encode([
                'success' => true,
                'week_start' => $week_start,
                'badge_active' => $badge_active,
                'winners' => $winners
            ]);
            break;
        
        // ═══════════════════════════════════════
        // VERIFICAR UM USUARIO ESPECÍFICO
        // GET ?action=check_user&user_id=X
        // ═══════════════════════════════════════
        case 'check_user':
            $check_uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            if (!$check_uid) {
                echo json_encode(['success' => false, 'error' => 'user_id obrigatório']);
                exit;
            }
            
            // Buscar se é Best MyTuber com badge ATIVO agora
            $stmt = $pdo->prepare("
                SELECT 
                    bm.scope,
                    bm.total_score,
                    bm.week_start,
                    bm.badge_visible_from,
                    bm.badge_visible_until,
                    bm.school_id,
                    s.name AS school_name,
                    s.short_name AS school_short,
                    bm.consistency_score,
                    bm.quality_score,
                    bm.engagement_score,
                    bm.impact_score,
                    bm.behavior_score,
                    bm.videos_count,
                    bm.total_likes,
                    bm.total_views,
                    bm.rising_star_bonus
                FROM best_mytuber_weekly bm
                LEFT JOIN schools s ON bm.school_id = s.id
                WHERE bm.user_id = ?
                  AND bm.badge_visible_from <= ?
                  AND bm.badge_visible_until >= ?
                ORDER BY bm.scope = 'global' DESC
            ");
            $stmt->execute([$check_uid, $now_str, $now_str]);
            $badges = $stmt->fetchAll();
            
            $is_global = false;
            $is_school = false;
            $badge_data = [];
            
            foreach ($badges as $b) {
                if ($b['scope'] === 'global') $is_global = true;
                if ($b['scope'] === 'school') $is_school = true;
                $badge_data[] = $b;
            }
            
            echo json_encode([
                'success' => true,
                'user_id' => $check_uid,
                'is_best_mytuber_global' => $is_global,
                'is_best_mytuber_school' => $is_school,
                'has_any_badge' => $is_global || $is_school,
                'badges' => $badge_data
            ]);
            break;
        
        // ═══════════════════════════════════════
        // HISTÓRICO DE VENCEDORES
        // GET ?action=history&limit=10
        // ═══════════════════════════════════════
        case 'history':
            $limit = min((int)($_GET['limit'] ?? 10), 50);
            $scope = $_GET['scope'] ?? 'global';
            
            $stmt = $pdo->prepare("
                SELECT 
                    bm.*,
                    u.username,
                    u.full_name,
                    u.profile_picture,
                    u.is_verified,
                    s.name AS school_name,
                    s.short_name AS school_short
                FROM best_mytuber_weekly bm
                INNER JOIN users u ON bm.user_id = u.id
                LEFT JOIN schools s ON bm.school_id = s.id
                WHERE bm.scope = ?
                ORDER BY bm.week_start DESC
                LIMIT ?
            ");
            $stmt->execute([$scope, $limit]);
            $history = $stmt->fetchAll();
            
            foreach ($history as &$h) {
                $h['profile_picture_url'] = 'assets/images/avatars/' . ($h['profile_picture'] ?? 'default.webp');
            }
            
            echo json_encode([
                'success' => true,
                'scope' => $scope,
                'history' => $history
            ]);
            break;
        
        // ═══════════════════════════════════════
        // CANDIDATOS DE UMA SEMANA (transparência)
        // GET ?action=candidates&week_start=2026-03-02&scope=global
        // ═══════════════════════════════════════
        case 'candidates':
            $ws = $_GET['week_start'] ?? '';
            $scope = $_GET['scope'] ?? 'global';
            $school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : null;
            
            if (empty($ws)) {
                // Usar semana atual
                $dayOfWeek = (int)$now->format('N');
                $monday = clone $now;
                if ($dayOfWeek > 1) {
                    $monday->modify('-' . ($dayOfWeek - 1) . ' days');
                }
                $ws = $monday->format('Y-m-d');
            }
            
            $sql = "
                SELECT 
                    bc.*,
                    u.username,
                    u.full_name,
                    u.profile_picture,
                    u.is_verified,
                    s.name AS school_name
                FROM best_mytuber_candidates bc
                INNER JOIN users u ON bc.user_id = u.id
                LEFT JOIN schools s ON u.school_id = s.id
                WHERE bc.week_start = ? AND bc.scope = ?
            ";
            $params = [$ws, $scope];
            
            if ($scope === 'school' && $school_id) {
                $sql .= " AND bc.school_id = ?";
                $params[] = $school_id;
            }
            
            $sql .= " ORDER BY bc.position ASC LIMIT 20";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $candidates = $stmt->fetchAll();
            
            foreach ($candidates as &$cnd) {
                $cnd['profile_picture_url'] = 'assets/images/avatars/' . ($cnd['profile_picture'] ?? 'default.webp');
            }
            
            echo json_encode([
                'success' => true,
                'week_start' => $ws,
                'scope' => $scope,
                'candidates' => $candidates
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
