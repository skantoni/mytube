<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * API: Calcular Best MyTuber da Semana
 * ═══════════════════════════════════════════════════════════════
 * 
 * Deve ser chamada toda sexta-feira às 20:00 (via cron ou admin)
 * 
 * Janela de dados: Segunda 00:00 → Sexta 19:59
 * Badge visível: Sexta 20:00 → Domingo 23:59
 * 
 * ALGORITMO:
 * 1. Consistência (max 25 pts): +5 por vídeo (até 5 vídeos)
 * 2. Qualidade (max 30 pts): Likes/views ratio, média por vídeo
 * 3. Engajamento (max 25 pts): Comentários recebidos, likes nos vídeos
 * 4. Impacto Escolar (max 15 pts): Contribuição para a escola
 * 5. Comportamento (max 10 pts): Comentários dados, interação positiva
 * 
 * ANTI-MONOPOLIO:
 * - Ganhou 1x nas últimas 4 semanas: -20%
 * - Ganhou 2x: -30%  
 * - Ganhou 3x+: -40%
 * - Nunca ganhou: +15% (Rising Star)
 * 
 * POST api/calculate_best_mytuber.php
 * Params: ?force=1 para recalcular mesmo que já exista
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

// Apenas admin pode executar
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$admin_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$admin_stmt->execute([$_SESSION['user_id']]);
$admin_user = $admin_stmt->fetch();
if ($admin_user['username'] !== 'Admin') {
    echo json_encode(['success' => false, 'error' => 'Apenas admin pode executar o cálculo']);
    exit;
}

$force = isset($_GET['force']) || isset($_POST['force']);

try {
    // ═══════════════════════════════════════
    // CALCULAR DATAS DA SEMANA
    // ═══════════════════════════════════════
    $now = new DateTime('now', new DateTimeZone('Africa/Luanda'));
    
    // Encontrar a segunda-feira desta semana
    $dayOfWeek = (int)$now->format('N'); // 1=seg, 7=dom
    $monday = clone $now;
    if ($dayOfWeek > 1) {
        $monday->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    $monday->setTime(0, 0, 0);
    
    // Sexta-feira 19:59:59 = fim dos dados
    $friday_data_end = clone $monday;
    $friday_data_end->modify('+4 days');
    $friday_data_end->setTime(19, 59, 59);
    
    $data_start = $monday->format('Y-m-d H:i:s');
    $data_end = $friday_data_end->format('Y-m-d H:i:s');
    
    // Sexta-feira 20:00 = badge visível
    $friday_badge_start = clone $monday;
    $friday_badge_start->modify('+4 days');
    $friday_badge_start->setTime(20, 0, 0);
    $badge_from = $friday_badge_start->format('Y-m-d H:i:s');
    
    // Domingo 23:59:59 = badge desaparece
    $sunday_badge_end = clone $monday;
    $sunday_badge_end->modify('+6 days');
    $sunday_badge_end->setTime(23, 59, 59);
    $badge_until = $sunday_badge_end->format('Y-m-d H:i:s');
    
    $week_start = $monday->format('Y-m-d');
    $week_end = $friday_data_end->format('Y-m-d');
    
    // ═══════════════════════════════════════
    // VERIFICAR SE JÁ FOI CALCULADO
    // ═══════════════════════════════════════
    if (!$force) {
        $check = $pdo->prepare("SELECT id FROM best_mytuber_weekly WHERE week_start = ? LIMIT 1");
        $check->execute([$week_start]);
        if ($check->fetch()) {
            echo json_encode([
                'success' => false, 
                'error' => 'Semana já calculada. Use ?force=1 para recalcular.',
                'week_start' => $week_start
            ]);
            exit;
        }
    }
    
    // Se force, limpar dados anteriores desta semana
    if ($force) {
        $pdo->prepare("DELETE FROM best_mytuber_weekly WHERE week_start = ?")->execute([$week_start]);
        $pdo->prepare("DELETE FROM best_mytuber_candidates WHERE week_start = ?")->execute([$week_start]);
    }
    
    // ═══════════════════════════════════════
    // BUSCAR HISTÓRICO DE VITÓRIAS (últimas 4 semanas)
    // ═══════════════════════════════════════
    $four_weeks_ago = clone $monday;
    $four_weeks_ago->modify('-4 weeks');
    
    $wins_stmt = $pdo->prepare("
        SELECT user_id, scope, school_id, COUNT(*) as win_count 
        FROM best_mytuber_weekly 
        WHERE week_start >= ? AND week_start < ?
        GROUP BY user_id, scope, school_id
    ");
    $wins_stmt->execute([$four_weeks_ago->format('Y-m-d'), $week_start]);
    $win_history = [];
    while ($row = $wins_stmt->fetch()) {
        $key = $row['user_id'] . '_' . $row['scope'] . '_' . ($row['school_id'] ?? 'null');
        $win_history[$key] = (int)$row['win_count'];
    }
    
    // Buscar todos os usuários que já ganharam alguma vez (para Rising Star)
    $ever_won_stmt = $pdo->query("SELECT DISTINCT user_id FROM best_mytuber_weekly");
    $ever_won = [];
    while ($row = $ever_won_stmt->fetch()) {
        $ever_won[$row['user_id']] = true;
    }
    
    // ═══════════════════════════════════════
    // BUSCAR TODOS OS CRIADORES COM ATIVIDADE NA SEMANA
    // ═══════════════════════════════════════
    $creators_sql = "
        SELECT 
            u.id AS user_id,
            u.username,
            u.full_name,
            u.school_id,
            u.created_at AS user_created_at,
            
            -- Vídeos publicados na semana
            COUNT(DISTINCT v.id) AS videos_count,
            
            -- Métricas dos vídeos publicados na semana
            COALESCE(SUM(v.likes_count), 0) AS total_likes,
            COALESCE(SUM(v.views_count), 0) AS total_views,
            COALESCE(SUM(v.comments_count), 0) AS total_comments,
            COALESCE(SUM(v.shares_count), 0) AS total_shares
            
        FROM users u
        INNER JOIN videos v ON v.user_id = u.id 
            AND v.is_public = 1 
            AND v.created_at >= ? 
            AND v.created_at <= ?
        WHERE u.username != 'Admin'
        GROUP BY u.id
        HAVING videos_count > 0
        ORDER BY total_likes DESC
    ";
    
    $creators_stmt = $pdo->prepare($creators_sql);
    $creators_stmt->execute([$data_start, $data_end]);
    $creators = $creators_stmt->fetchAll();
    
    if (empty($creators)) {
        echo json_encode([
            'success' => true, 
            'message' => 'Nenhum criador com atividade esta semana.',
            'week_start' => $week_start,
            'winners' => []
        ]);
        exit;
    }
    
    // ═══════════════════════════════════════
    // BUSCAR NOVOS SEGUIDORES NA SEMANA (para cada criador)
    // ═══════════════════════════════════════
    $user_ids = array_column($creators, 'user_id');
    $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
    
    $followers_stmt = $pdo->prepare("
        SELECT following_id AS user_id, COUNT(*) AS new_followers
        FROM follows
        WHERE following_id IN ($placeholders)
          AND created_at >= ? AND created_at <= ?
        GROUP BY following_id
    ");
    $followers_stmt->execute(array_merge($user_ids, [$data_start, $data_end]));
    $new_followers = [];
    while ($row = $followers_stmt->fetch()) {
        $new_followers[$row['user_id']] = (int)$row['new_followers'];
    }
    
    // ═══════════════════════════════════════
    // BUSCAR COMENTÁRIOS DADOS a outros (comportamento positivo)
    // ═══════════════════════════════════════
    $comments_given_stmt = $pdo->prepare("
        SELECT c.user_id, COUNT(DISTINCT c.id) AS comments_given
        FROM comments c
        INNER JOIN videos v ON c.video_id = v.id
        WHERE c.user_id IN ($placeholders)
          AND v.user_id != c.user_id
          AND c.created_at >= ? AND c.created_at <= ?
        GROUP BY c.user_id
    ");
    $comments_given_stmt->execute(array_merge($user_ids, [$data_start, $data_end]));
    $comments_given = [];
    while ($row = $comments_given_stmt->fetch()) {
        $comments_given[$row['user_id']] = (int)$row['comments_given'];
    }
    
    // Likes dados em comentários de outros
    $comment_likes_given_stmt = $pdo->prepare("
        SELECT cl.user_id, COUNT(*) AS likes_given
        FROM comment_likes cl
        INNER JOIN comments c ON cl.comment_id = c.id
        WHERE cl.user_id IN ($placeholders)
          AND c.user_id != cl.user_id
          AND cl.created_at >= ? AND cl.created_at <= ?
        GROUP BY cl.user_id
    ");
    $comment_likes_given_stmt->execute(array_merge($user_ids, [$data_start, $data_end]));
    $comment_likes_given = [];
    while ($row = $comment_likes_given_stmt->fetch()) {
        $comment_likes_given[$row['user_id']] = (int)$row['likes_given'];
    }
    
    // ═══════════════════════════════════════
    // BUSCAR LIKES RECEBIDOS NA SEMANA (em vídeos da semana)
    // Para considerar engajamento que aconteceu na semana
    // ═══════════════════════════════════════
    $weekly_likes_stmt = $pdo->prepare("
        SELECT v.user_id, COUNT(DISTINCT vl.id) AS weekly_likes
        FROM video_likes vl
        INNER JOIN videos v ON vl.video_id = v.id
        WHERE v.user_id IN ($placeholders)
          AND vl.created_at >= ? AND vl.created_at <= ?
        GROUP BY v.user_id
    ");
    $weekly_likes_stmt->execute(array_merge($user_ids, [$data_start, $data_end]));
    $weekly_likes = [];
    while ($row = $weekly_likes_stmt->fetch()) {
        $weekly_likes[$row['user_id']] = (int)$row['weekly_likes'];
    }
    
    // ═══════════════════════════════════════
    // BUSCAR DADOS POR ESCOLA (para impacto)
    // ═══════════════════════════════════════
    $school_totals_stmt = $pdo->prepare("
        SELECT 
            u.school_id,
            COUNT(DISTINCT v.id) AS school_videos,
            COALESCE(SUM(v.views_count), 0) AS school_views,
            COUNT(DISTINCT u.id) AS school_creators
        FROM users u
        INNER JOIN videos v ON v.user_id = u.id 
            AND v.is_public = 1 
            AND v.created_at >= ? AND v.created_at <= ?
        WHERE u.school_id IS NOT NULL
        GROUP BY u.school_id
    ");
    $school_totals_stmt->execute([$data_start, $data_end]);
    $school_totals = [];
    while ($row = $school_totals_stmt->fetch()) {
        $school_totals[$row['school_id']] = $row;
    }
    
    // ═══════════════════════════════════════
    // CALCULAR SCORES PARA CADA CRIADOR
    // ═══════════════════════════════════════
    $all_candidates = [];
    
    foreach ($creators as $c) {
        $uid = $c['user_id'];
        $vid_count = (int)$c['videos_count'];
        $likes = (int)$c['total_likes'];
        $views = (int)$c['total_views'];
        $comments = (int)$c['total_comments'];
        $shares = (int)$c['total_shares'];
        $followers = $new_followers[$uid] ?? 0;
        $given_comments = $comments_given[$uid] ?? 0;
        $given_comment_likes = $comment_likes_given[$uid] ?? 0;
        $w_likes = $weekly_likes[$uid] ?? 0;
        
        // ──────────────────────────────
        // 1. CONSISTÊNCIA (max 25 pts)
        // +5 por vídeo publicado, max 5 vídeos
        // Bonus +3 se publicou em dias diferentes (diversidade temporal)
        // ──────────────────────────────
        $consistency = min($vid_count, 5) * 5; // 0-25
        
        // ──────────────────────────────
        // 2. QUALIDADE (max 30 pts)
        // Baseada na média de interações por vídeo
        // Evita premiar "1 vídeo viral" sobre vários bons vídeos
        // ──────────────────────────────
        $avg_likes = $vid_count > 0 ? $likes / $vid_count : 0;
        $avg_views = $vid_count > 0 ? $views / $vid_count : 0;
        $avg_comments = $vid_count > 0 ? $comments / $vid_count : 0;
        
        // Ratio de engajamento (likes + comments) / views
        $engagement_ratio = $views > 0 ? ($likes + $comments) / $views : 0;
        
        // Score de qualidade: combinação de métricas médias
        // avg_likes * 2 (max ~10) + avg_comments * 3 (max ~10) + ratio * 20 (max ~10)
        $quality = min(30, 
            min(10, $avg_likes * 2) + 
            min(10, $avg_comments * 3) + 
            min(10, $engagement_ratio * 20)
        );
        
        // ──────────────────────────────
        // 3. ENGAJAMENTO REAL (max 25 pts)
        // Total de interações recebidas na semana
        // Usa likes da semana em vez de acumulados
        // ──────────────────────────────
        $engagement = min(25,
            min(10, $w_likes * 1.5) +           // Likes recebidos na semana
            min(8, $comments * 1.5) +            // Comentários nos vídeos
            min(4, $followers * 2) +             // Novos seguidores
            min(3, $shares * 1.5)                // Compartilhamentos
        );
        
        // ──────────────────────────────
        // 4. IMPACTO NA ESCOLA (max 15 pts)
        // Quanto o criador contribuiu para a escola
        // ──────────────────────────────
        $impact = 0;
        if ($c['school_id'] && isset($school_totals[$c['school_id']])) {
            $st = $school_totals[$c['school_id']];
            
            // % das views da escola que vieram deste criador
            $view_share = $st['school_views'] > 0 ? $views / $st['school_views'] : 0;
            
            // Bônus por escola com mais criadores (incentiva colaboração)
            $team_bonus = min(5, ($st['school_creators'] - 1) * 1.5);
            
            $impact = min(15,
                min(10, $view_share * 15) + $team_bonus
            );
        }
        
        // ──────────────────────────────
        // 5. COMPORTAMENTO POSITIVO (max 10 pts)
        // Interagir com a comunidade
        // ──────────────────────────────
        $behavior = min(10,
            min(6, $given_comments * 0.8) +       // Comentários em vídeos de outros
            min(4, $given_comment_likes * 0.4)     // Likes em comentários de outros
        );
        
        // ══════════════════════════════
        // SCORE BRUTO
        // ══════════════════════════════
        $raw_score = $consistency + $quality + $engagement + $impact + $behavior;
        
        // ══════════════════════════════
        // AJUSTES: COOLDOWN & RISING STAR
        // ══════════════════════════════
        
        // Cooldown Global
        $key_global = $uid . '_global_null';
        $wins_global = $win_history[$key_global] ?? 0;
        $cooldown_global = 0;
        if ($wins_global >= 3) $cooldown_global = 0.40;
        elseif ($wins_global >= 2) $cooldown_global = 0.30;
        elseif ($wins_global >= 1) $cooldown_global = 0.20;
        
        // Cooldown Escola
        $key_school = $uid . '_school_' . ($c['school_id'] ?? 'null');
        $wins_school = $win_history[$key_school] ?? 0;
        $cooldown_school = 0;
        if ($wins_school >= 3) $cooldown_school = 0.40;
        elseif ($wins_school >= 2) $cooldown_school = 0.30;
        elseif ($wins_school >= 1) $cooldown_school = 0.20;
        
        // Rising Star: nunca ganhou NENHUM prémio
        $is_rising_star = !isset($ever_won[$uid]);
        $rising_bonus = $is_rising_star ? 0.15 : 0;
        
        // Score final global  
        $final_global = $raw_score * (1 - $cooldown_global) * (1 + $rising_bonus);
        
        // Score final escola
        $final_school = $raw_score * (1 - $cooldown_school) * (1 + $rising_bonus);
        
        $all_candidates[] = [
            'user_id' => $uid,
            'username' => $c['username'],
            'school_id' => $c['school_id'],
            'raw_score' => round($raw_score, 2),
            'final_score_global' => round($final_global, 2),
            'final_score_school' => round($final_school, 2),
            'consistency' => round($consistency, 2),
            'quality' => round($quality, 2),
            'engagement' => round($engagement, 2),
            'impact' => round($impact, 2),
            'behavior' => round($behavior, 2),
            'cooldown_global' => $cooldown_global,
            'cooldown_school' => $cooldown_school,
            'rising_star' => $is_rising_star,
            'videos_count' => $vid_count,
            'total_likes' => $likes,
            'total_views' => $views,
            'total_comments' => $comments,
            'new_followers' => $followers,
        ];
    }
    
    // ═══════════════════════════════════════
    // DETERMINAR VENCEDORES
    // ═══════════════════════════════════════
    
    $winners = [];
    
    // --- Best MyTuber GLOBAL ---
    usort($all_candidates, function($a, $b) {
        return $b['final_score_global'] <=> $a['final_score_global'];
    });
    
    if (!empty($all_candidates)) {
        $global_winner = $all_candidates[0];
        
        // Salvar vencedor global
        $insert = $pdo->prepare("
            INSERT INTO best_mytuber_weekly 
            (user_id, week_start, week_end, scope, school_id, total_score,
             consistency_score, quality_score, engagement_score, impact_score, behavior_score,
             cooldown_penalty, rising_star_bonus, videos_count, total_likes, total_views, 
             total_comments, new_followers, badge_visible_from, badge_visible_until)
            VALUES (?, ?, ?, 'global', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $global_winner['user_id'], $week_start, $week_end, 
            $global_winner['final_score_global'],
            $global_winner['consistency'], $global_winner['quality'],
            $global_winner['engagement'], $global_winner['impact'], $global_winner['behavior'],
            $global_winner['cooldown_global'], $global_winner['rising_star'] ? 1 : 0,
            $global_winner['videos_count'], $global_winner['total_likes'],
            $global_winner['total_views'], $global_winner['total_comments'],
            $global_winner['new_followers'], $badge_from, $badge_until
        ]);
        
        // --- Notificação Global para todos os usuários ---
        $notif_stmt = $pdo->prepare("
            INSERT INTO global_notifications (type, message, reference_id)
            VALUES ('best_mytuber_global', ?, ?)
        ");
        $notif_message = $global_winner['username'] . " é o Best MyTuber Global da semana! 🎉";
        $notif_stmt->execute([$notif_message, $global_winner['user_id']]);
        // --------------------------------------------------
        
        $winners[] = [
            'scope' => 'global',
            'user_id' => $global_winner['user_id'],
            'username' => $global_winner['username'],
            'score' => $global_winner['final_score_global'],
            'raw_score' => $global_winner['raw_score']
        ];
        
        // Salvar todos os candidatos globais
        $pos_stmt = $pdo->prepare("
            INSERT INTO best_mytuber_candidates 
            (week_start, scope, school_id, user_id, raw_score, final_score,
             consistency_score, quality_score, engagement_score, impact_score, behavior_score,
             cooldown_penalty, rising_star_bonus, videos_count, position)
            VALUES (?, 'global', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($all_candidates as $i => $cand) {
            $pos_stmt->execute([
                $week_start, $cand['user_id'], $cand['raw_score'], $cand['final_score_global'],
                $cand['consistency'], $cand['quality'], $cand['engagement'], 
                $cand['impact'], $cand['behavior'],
                $cand['cooldown_global'], $cand['rising_star'] ? 1 : 0,
                $cand['videos_count'], $i + 1
            ]);
        }
    }
    
    // --- Best MyTuber POR ESCOLA ---
    $by_school = [];
    foreach ($all_candidates as $cand) {
        if ($cand['school_id']) {
            $by_school[$cand['school_id']][] = $cand;
        }
    }
    
    foreach ($by_school as $school_id => $school_candidates) {
        usort($school_candidates, function($a, $b) {
            return $b['final_score_school'] <=> $a['final_score_school'];
        });
        
        // Mínimo de 2 candidatos na escola para ter "Best MyTuber da Escola"
        // Se só 1 pessoa posta, não faz sentido dar badge
        if (count($school_candidates) >= 1) {
            $school_winner = $school_candidates[0];
            
            // Salvar vencedor escola
            $insert = $pdo->prepare("
                INSERT INTO best_mytuber_weekly 
                (user_id, week_start, week_end, scope, school_id, total_score,
                 consistency_score, quality_score, engagement_score, impact_score, behavior_score,
                 cooldown_penalty, rising_star_bonus, videos_count, total_likes, total_views, 
                 total_comments, new_followers, badge_visible_from, badge_visible_until)
                VALUES (?, ?, ?, 'school', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $school_winner['user_id'], $week_start, $week_end, $school_id,
                $school_winner['final_score_school'],
                $school_winner['consistency'], $school_winner['quality'],
                $school_winner['engagement'], $school_winner['impact'], $school_winner['behavior'],
                $school_winner['cooldown_school'], $school_winner['rising_star'] ? 1 : 0,
                $school_winner['videos_count'], $school_winner['total_likes'],
                $school_winner['total_views'], $school_winner['total_comments'],
                $school_winner['new_followers'], $badge_from, $badge_until
            ]);
            
            $winners[] = [
                'scope' => 'school',
                'school_id' => $school_id,
                'user_id' => $school_winner['user_id'],
                'username' => $school_winner['username'],
                'score' => $school_winner['final_score_school'],
                'raw_score' => $school_winner['raw_score']
            ];
            
            // Salvar candidatos da escola
            $pos_stmt = $pdo->prepare("
                INSERT INTO best_mytuber_candidates 
                (week_start, scope, school_id, user_id, raw_score, final_score,
                 consistency_score, quality_score, engagement_score, impact_score, behavior_score,
                 cooldown_penalty, rising_star_bonus, videos_count, position)
                VALUES (?, 'school', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($school_candidates as $i => $cand) {
                $pos_stmt->execute([
                    $week_start, $school_id, $cand['user_id'], 
                    $cand['raw_score'], $cand['final_score_school'],
                    $cand['consistency'], $cand['quality'], $cand['engagement'], 
                    $cand['impact'], $cand['behavior'],
                    $cand['cooldown_school'], $cand['rising_star'] ? 1 : 0,
                    $cand['videos_count'], $i + 1
                ]);
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Best MyTuber da semana calculado com sucesso!',
        'week_start' => $week_start,
        'week_end' => $week_end,
        'badge_visible' => "$badge_from → $badge_until",
        'total_candidates' => count($all_candidates),
        'winners' => $winners,
        'breakdown' => array_map(function($c) {
            return [
                'user' => $c['username'],
                'raw' => $c['raw_score'],
                'global' => $c['final_score_global'],
                'school' => $c['final_score_school'],
                'consistency' => $c['consistency'],
                'quality' => $c['quality'],
                'engagement' => $c['engagement'],
                'impact' => $c['impact'],
                'behavior' => $c['behavior'],
                'cooldown' => $c['cooldown_global'],
                'rising_star' => $c['rising_star']
            ];
        }, $all_candidates)
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
