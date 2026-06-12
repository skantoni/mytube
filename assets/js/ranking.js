/**
 * MyTube Rankings System - JavaScript
 * Sistema de rankings interativo e viciante
 */

(function() {
    'use strict';

    // ═══════════════════════════════════════
    // STATE
    // ═══════════════════════════════════════
    let currentTab = 'dominant';
    let currentPeriod = 'week';
    let allSchools = [];
    let dataCache = {};

    // ═══════════════════════════════════════
    // INIT — CONSOLIDATED LOAD (1 request instead of 7)
    // ═══════════════════════════════════════
    document.addEventListener('DOMContentLoaded', function() {
        // Bloquear botão "Mês" se não for dia 26
        const monthBtn = document.querySelector('.period-btn[data-period="month"]');
        if (monthBtn && window.serverDay !== 26) {
            monthBtn.disabled = true;
            monthBtn.style.opacity = '0.4';
            monthBtn.style.cursor = 'not-allowed';
            monthBtn.title = 'Resultado do mês disponível apenas no dia 26';
        }

        // Single consolidated request instead of 7 separate ones
        const schoolParam = window.userSchoolId ? `&school_id=${window.userSchoolId}` : '';
        fetch(`api/get_rankings.php?action=initial_load${schoolParam}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;

                // My Rank
                if (data.my_rank && data.my_rank.success) {
                    renderMyRank(data.my_rank);
                }

                // Best MyTuber
                if (data.best_mytuber) {
                    renderBestMyTuber(data.best_mytuber);
                }

                // Dominant School
                dataCache.dominant = true;
                renderDominantSchoolData(data.dominant);

                // Trending Videos
                dataCache.trending = true;
                renderTrendingData(data.trending);

                // Top Creators (period=all)
                dataCache['creators_all'] = true;
                if (data.creators && data.creators.length > 0) {
                    renderPodium(data.creators.slice(0, 3));
                    renderCreatorsTable(data.creators, 'creatorsTable');
                } else {
                    const podium = document.getElementById('podiumSection');
                    if (podium) {
                        podium.classList.remove('loading-placeholder');
                        podium.innerHTML = '<div class="empty-state"><i class="fas fa-users"></i><p>Sem criadores ainda</p></div>';
                    }
                    const ct = document.getElementById('creatorsTable');
                    if (ct) ct.innerHTML = '';
                }

                // Top Schools
                dataCache.schools = true;
                renderSchoolsData(data.schools);

                // My School
                if (data.my_school) {
                    dataCache.myschool = true;
                    if (data.my_school.creators && data.my_school.creators.length > 0) {
                        renderCreatorsTable(data.my_school.creators, 'mySchoolCreators');
                    } else {
                        const el = document.getElementById('mySchoolCreators');
                        if (el) {
                            el.classList.remove('loading-placeholder');
                            el.innerHTML = '<div class="empty-state"><i class="fas fa-users"></i><p>Nenhum criador da sua escola ainda.</p></div>';
                        }
                    }
                }
            })
            .catch(err => {
                console.error('Erro ao carregar rankings:', err);
                // Fallback: carregar individualmente
                loadMyRank();
                loadBestMyTuber();
                loadDominantSchool();
                loadTrendingVideos();
                setTimeout(() => {
                    loadTopCreators();
                    loadTopSchools();
                    if (window.userSchoolId) loadMySchoolCreators();
                }, 500);
            });
    });

    // ═══════════════════════════════════════
    // CONSOLIDATED RENDER HELPERS
    // ═══════════════════════════════════════
    function renderMyRank(data) {
        const card = document.getElementById('myRankCard');
        if (!card || !data.user) return;
        const u = data.user;
        const pic = u.profile_picture_url || 'assets/images/avatars/' + (u.profile_picture || 'default.webp');
        card.innerHTML = `
            <div class="my-rank-content">
                <img src="${pic}" class="my-rank-avatar" alt="${esc(u.full_name)}">
                <div class="my-rank-info">
                    <div class="my-rank-name">${esc(u.full_name || u.username)}</div>
                    <div class="my-rank-school">
                        ${u.school_name ? `<i class="fas fa-graduation-cap"></i> ${esc(u.school_name)}` : '<i class="fas fa-school"></i> Sem escola'}
                    </div>
                    <div class="my-rank-stats">
                        <div class="my-rank-stat">
                            <div class="my-rank-stat-value">${formatNum(data.points)}</div>
                            <div class="my-rank-stat-label">Pontos</div>
                        </div>
                        <div class="my-rank-stat">
                            <div class="my-rank-stat-value">${u.total_videos || 0}</div>
                            <div class="my-rank-stat-label">Vídeos</div>
                        </div>
                        <div class="my-rank-stat">
                            <div class="my-rank-stat-value">${formatNum(u.total_likes || 0)}</div>
                            <div class="my-rank-stat-label">Likes</div>
                        </div>
                    </div>
                </div>
                <div class="my-rank-position">
                    <div class="my-rank-number">#${data.global_rank}</div>
                    <div class="my-rank-label">Global</div>
                    ${data.school_rank ? `
                        <div class="my-rank-number" style="font-size:20px; margin-top:4px;">#${data.school_rank}</div>
                        <div class="my-rank-label">Escola</div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    function renderBestMyTuber(data) {
        const section = document.getElementById('bestMytuberSection');
        if (!section) return;
        if (!data.success || !data.badge_active || !data.winners || data.winners.length === 0) {
            section.style.display = 'none';
            return;
        }
        section.style.display = 'block';
        renderBestMyTuberContent(data.winners);
    }

    function renderDominantSchoolData(result) {
        const el = document.getElementById('dominantSchoolBanner');
        if (!el) return;
        const dominant = result ? result.dominant : null;
        const top_videos = result ? result.top_videos : [];

        if (!dominant) {
            el.classList.remove('loading-placeholder');
            el.innerHTML = '<div class="dominant-empty"><i class="fas fa-crown"></i><p>Nenhuma escola dominou esta semana ainda.<br>Seja o primeiro a representar sua escola!</p></div>';
            return;
        }

        const s = dominant;
        el.classList.remove('loading-placeholder');
        el.innerHTML = `
            <div class="dominant-crown">👑</div>
            <div class="dominant-school-tag">⭐ Escola Dominante da Semana ⭐</div>
            <div class="dominant-school-name">${esc(s.name)}</div>
            <div class="dominant-stats">
                <div class="dominant-stat"><div class="dominant-stat-value">${formatNum(s.points)}</div><div class="dominant-stat-label">Pontos</div></div>
                <div class="dominant-stat"><div class="dominant-stat-value">${s.total_videos}</div><div class="dominant-stat-label">Vídeos</div></div>
                <div class="dominant-stat"><div class="dominant-stat-value">${s.total_students}</div><div class="dominant-stat-label">Alunos</div></div>
                <div class="dominant-stat"><div class="dominant-stat-value">${formatNum(s.total_views)}</div><div class="dominant-stat-label">Views</div></div>
            </div>
        `;
        if (top_videos && top_videos.length > 0) {
            renderDominantVideos(top_videos);
        }
    }

    function renderTrendingData(videos) {
        const cont = document.getElementById('trendingVideos');
        if (!cont) return;
        if (!videos || videos.length === 0) {
            cont.classList.remove('loading-placeholder');
            cont.innerHTML = '<div class="empty-state"><i class="fas fa-video"></i><p>Sem vídeos em alta</p></div>';
            return;
        }
        if (!dataCache.dominant || cont.querySelector('.spinner-small')) {
            cont.classList.remove('loading-placeholder');
            cont.innerHTML = videos.map((v, i) => `
                <div class="trending-video-card" onclick="window.location.href='index.php?video_id=${v.id}'">
                    <div class="trending-video-thumb">
                        ${v.thumbnail_path ? `<img src="uploads/thumbnails/${encodeURIComponent(v.thumbnail_path)}" alt="Thumbnail" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;">` : `<video muted preload="metadata" src="${resolveVideoUrl(v.video_path)}" onloadeddata="this.currentTime = 0.5;" style="width:100%;height:100%;object-fit:cover;"></video>`}
                        <div class="trending-video-rank">#${v.position}</div>
                        <div class="trending-video-stats">
                            <span><i class="fas fa-eye"></i> ${formatNum(v.views_count)}</span>
                            <span><i class="fas fa-heart"></i> ${formatNum(v.likes_count)}</span>
                        </div>
                    </div>
                    <div class="trending-video-info">
                        <div class="trending-video-title">${esc(v.title)}</div>
                        <div class="trending-video-creator">
                            <img src="${v.profile_picture_url}" alt="">
                            @${esc(v.username)}
                        </div>
                        ${v.school_name ? `<div class="trending-school-badge"><i class="fas fa-graduation-cap"></i> ${esc(v.school_name)}</div>` : ''}
                    </div>
                </div>
            `).join('');
        }
    }

    function renderSchoolsData(schools) {
        const el = document.getElementById('schoolsRanking');
        if (!el) return;
        el.classList.remove('loading-placeholder');
        if (!schools || schools.length === 0) {
            el.innerHTML = '<div class="empty-state"><i class="fas fa-school"></i><p>Nenhuma escola no ranking ainda.<br>Seja o primeiro a representar sua escola!</p></div>';
            return;
        }
        el.innerHTML = schools.map(s => {
            const topClass = s.position <= 3 ? `top-${s.position}` : '';
            return `
                <div class="school-rank-card ${topClass}" onclick="viewSchoolRanking(${s.id}, '${esc(s.name)}')">
                    <div class="school-rank-header">
                        <div class="school-rank-position">${s.position <= 3 ? getMedal(s.position) : s.position}</div>
                        <div class="school-rank-logo">🏫</div>
                        <div class="school-rank-info">
                            <div class="school-rank-name">${esc(s.name)}</div>
                            <div class="school-rank-city"><i class="fas fa-map-marker-alt"></i> ${esc(s.city)}</div>
                        </div>
                        <div>
                            <div class="school-rank-points">${formatNum(s.points)}</div>
                            <div class="school-rank-points-label">pontos</div>
                        </div>
                    </div>
                    <div class="school-rank-stats">
                        <div class="school-stat"><i class="fas fa-users"></i><span class="school-stat-value">${s.total_students}</span> alunos</div>
                        <div class="school-stat"><i class="fas fa-video"></i><span class="school-stat-value">${s.total_videos}</span> vídeos</div>
                        <div class="school-stat"><i class="fas fa-heart"></i><span class="school-stat-value">${formatNum(s.total_likes)}</span> likes</div>
                        <div class="school-stat"><i class="fas fa-eye"></i><span class="school-stat-value">${formatNum(s.total_views)}</span> views</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // ═══════════════════════════════════════
    // TAB SWITCHING
    // ═══════════════════════════════════════
    window.switchTab = function(tab) {
        currentTab = tab;
        
        // Update tab buttons
        document.querySelectorAll('.ranking-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.ranking-tab[data-tab="${tab}"]`).classList.add('active');
        
        // Update tab content
        document.querySelectorAll('.ranking-tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(`tab-${tab}`).classList.add('active');
        
        // Load data if needed
        switch(tab) {
            case 'dominant':
                if (!dataCache.dominant) loadDominantSchool();
                if (!dataCache.trending) loadTrendingVideos();
                break;
            case 'creators':
                if (!dataCache[`creators_${currentPeriod}`]) loadTopCreators();
                break;
            case 'schools':
                if (!dataCache.schools) loadTopSchools();
                break;
            case 'myschool':
                if (!dataCache.myschool) loadMySchoolCreators();
                break;
        }
    };

    // ═══════════════════════════════════════
    // PERIOD FILTER
    // ═══════════════════════════════════════
    window.filterPeriod = function(period) {
        if (period === 'month' && window.serverDay !== 26) return;
        currentPeriod = period;
        document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        document.querySelector(`.period-btn[data-period="${period}"]`).classList.add('active');
        loadTopCreators();
    };

    // ═══════════════════════════════════════
    // LOAD MY RANK
    // ═══════════════════════════════════════
    function loadMyRank() {
        fetch('api/get_rankings.php?action=my_rank')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const card = document.getElementById('myRankCard');
                const u = data.user;
                const pic = u.profile_picture_url || 'assets/images/avatars/' + (u.profile_picture || 'default.webp');
                
                card.innerHTML = `
                    <div class="my-rank-content">
                        <img src="${pic}" class="my-rank-avatar" alt="${esc(u.full_name)}">
                        <div class="my-rank-info">
                            <div class="my-rank-name">${esc(u.full_name || u.username)}</div>
                            <div class="my-rank-school">
                                ${u.school_name ? `<i class="fas fa-graduation-cap"></i> ${esc(u.school_name)}` : '<i class="fas fa-school"></i> Sem escola'}
                            </div>
                            <div class="my-rank-stats">
                                <div class="my-rank-stat">
                                    <div class="my-rank-stat-value">${formatNum(data.points)}</div>
                                    <div class="my-rank-stat-label">Pontos</div>
                                </div>
                                <div class="my-rank-stat">
                                    <div class="my-rank-stat-value">${u.total_videos || 0}</div>
                                    <div class="my-rank-stat-label">Vídeos</div>
                                </div>
                                <div class="my-rank-stat">
                                    <div class="my-rank-stat-value">${formatNum(u.total_likes || 0)}</div>
                                    <div class="my-rank-stat-label">Likes</div>
                                </div>
                            </div>
                        </div>
                        <div class="my-rank-position">
                            <div class="my-rank-number">#${data.global_rank}</div>
                            <div class="my-rank-label">Global</div>
                            ${data.school_rank ? `
                                <div class="my-rank-number" style="font-size:20px; margin-top:4px;">#${data.school_rank}</div>
                                <div class="my-rank-label">Escola</div>
                            ` : ''}
                        </div>
                    </div>
                `;
            })
            .catch(err => {
                console.error('Erro ao carregar ranking:', err);
                document.getElementById('myRankCard').innerHTML = '';
            });
    }

    // ═══════════════════════════════════════
    // LOAD DOMINANT SCHOOL
    // ═══════════════════════════════════════
    function loadDominantSchool() {
        fetch('api/get_rankings.php?action=dominant_school')
            .then(r => r.json())
            .then(data => {
                dataCache.dominant = true;
                const el = document.getElementById('dominantSchoolBanner');
                
                if (!data.success || !data.dominant) {
                    el.classList.remove('loading-placeholder');
                    el.innerHTML = `
                        <div class="dominant-empty">
                            <i class="fas fa-crown"></i>
                            <p>Nenhuma escola dominou esta semana ainda.<br>Seja o primeiro a representar sua escola!</p>
                        </div>
                    `;
                    return;
                }
                
                const s = data.dominant;
                el.classList.remove('loading-placeholder');
                el.innerHTML = `
                    <div class="dominant-crown">👑</div>
                    <div class="dominant-school-tag">⭐ Escola Dominante da Semana ⭐</div>
                    <div class="dominant-school-name">${esc(s.name)}</div>
                    <div class="dominant-stats">
                        <div class="dominant-stat">
                            <div class="dominant-stat-value">${formatNum(s.points)}</div>
                            <div class="dominant-stat-label">Pontos</div>
                        </div>
                        <div class="dominant-stat">
                            <div class="dominant-stat-value">${s.total_videos}</div>
                            <div class="dominant-stat-label">Vídeos</div>
                        </div>
                        <div class="dominant-stat">
                            <div class="dominant-stat-value">${s.total_students}</div>
                            <div class="dominant-stat-label">Alunos</div>
                        </div>
                        <div class="dominant-stat">
                            <div class="dominant-stat-value">${formatNum(s.total_views)}</div>
                            <div class="dominant-stat-label">Views</div>
                        </div>
                    </div>
                `;
                
                // Render top videos of dominant school
                if (data.top_videos && data.top_videos.length > 0) {
                    renderDominantVideos(data.top_videos);
                }
            });
    }

    function renderDominantVideos(videos) {
        const cont = document.getElementById('trendingVideos');
        if (!cont) return;
        cont.classList.remove('loading-placeholder');

        // Show only within dominant section - these are the school's top videos
        cont.innerHTML = videos.map((v, i) => `
            <div class="trending-video-card" onclick="window.location.href='index.php?video_id=${v.id}'">
                <div class="trending-video-thumb">
                    ${v.thumbnail_path ? `<img src="uploads/thumbnails/${encodeURIComponent(v.thumbnail_path)}" alt="Thumbnail" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;">` : `<video muted preload="metadata" src="${resolveVideoUrl(v.video_path)}" onloadeddata="this.currentTime = 0.5;" style="width:100%;height:100%;object-fit:cover;"></video>`}
                    <div class="trending-video-rank">#${i + 1}</div>
                    <div class="trending-video-stats">
                        <span><i class="fas fa-eye"></i> ${formatNum(v.views_count)}</span>
                        <span><i class="fas fa-heart"></i> ${formatNum(v.likes_count)}</span>
                    </div>
                </div>
                <div class="trending-video-info">
                    <div class="trending-video-title">${esc(v.title)}</div>
                    <div class="trending-video-creator">
                        <img src="${v.profile_picture_url}" alt="">
                        @${esc(v.username)}
                    </div>
                </div>
            </div>
        `).join('');
    }

    // ═══════════════════════════════════════
    // LOAD TRENDING VIDEOS
    // ═══════════════════════════════════════
    function loadTrendingVideos() {
        fetch('api/get_rankings.php?action=trending_videos&limit=6')
            .then(r => r.json())
            .then(data => {
                dataCache.trending = true;
                const cont = document.getElementById('trendingVideos');
                if (!cont) return;
                
                if (!data.success || !data.videos || data.videos.length === 0) {
                    cont.classList.remove('loading-placeholder');
                    cont.innerHTML = '<div class="empty-state"><i class="fas fa-video"></i><p>Sem vídeos em alta</p></div>';
                    return;
                }
                
                // Only render if dominant videos haven't replaced it
                if (!dataCache.dominant || cont.querySelector('.spinner-small')) {
                    cont.classList.remove('loading-placeholder');
                    cont.innerHTML = data.videos.map((v, i) => `
                        <div class="trending-video-card" onclick="window.location.href='index.php?video_id=${v.id}'">
                            <div class="trending-video-thumb">
                                ${v.thumbnail_path ? `<img src="uploads/thumbnails/${encodeURIComponent(v.thumbnail_path)}" alt="Thumbnail" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;">` : `<video muted preload="metadata" src="${resolveVideoUrl(v.video_path)}" onloadeddata="this.currentTime = 0.5;" style="width:100%;height:100%;object-fit:cover;"></video>`}
                                <div class="trending-video-rank">#${v.position}</div>
                                <div class="trending-video-stats">
                                    <span><i class="fas fa-eye"></i> ${formatNum(v.views_count)}</span>
                                    <span><i class="fas fa-heart"></i> ${formatNum(v.likes_count)}</span>
                                </div>
                            </div>
                            <div class="trending-video-info">
                                <div class="trending-video-title">${esc(v.title)}</div>
                                <div class="trending-video-creator">
                                    <img src="${v.profile_picture_url}" alt="">
                                    @${esc(v.username)}
                                </div>
                                ${v.school_name ? `<div class="trending-school-badge"><i class="fas fa-graduation-cap"></i> ${esc(v.school_name)}</div>` : ''}
                            </div>
                        </div>
                    `).join('');
                }
            });
    }

    // ═══════════════════════════════════════
    // LOAD TOP CREATORS
    // ═══════════════════════════════════════
    function loadTopCreators() {
        const cacheKey = `creators_${currentPeriod}`;
        
        fetch(`api/get_rankings.php?action=top_creators&period=${currentPeriod}&limit=30`)
            .then(r => r.json())
            .then(data => {
                dataCache[cacheKey] = true;
                
                if (!data.success || !data.creators || data.creators.length === 0) {
                    document.getElementById('podiumSection').innerHTML = '<div class="empty-state"><i class="fas fa-users"></i><p>Sem criadores ainda</p></div>';
                    document.getElementById('podiumSection').classList.remove('loading-placeholder');
                    document.getElementById('creatorsTable').innerHTML = '';
                    return;
                }
                
                renderPodium(data.creators.slice(0, 3));
                renderCreatorsTable(data.creators, 'creatorsTable');
            });
    }

    function renderPodium(top3) {
        const el = document.getElementById('podiumSection');
        el.classList.remove('loading-placeholder');
        
        if (top3.length === 0) {
            el.innerHTML = '';
            return;
        }

        const classes = ['first', 'second', 'third'];
        const crowns = ['👑', '', ''];
        const medals = ['🥇', '🥈', '🥉'];
        
        let html = '';
        for (let i = 0; i < Math.min(3, top3.length); i++) {
            const c = top3[i];
            const pic = c.profile_picture_url || 'assets/images/avatars/default.webp';
            html += `
                <div class="podium-item ${classes[i]}" onclick="goToProfile('${escAttr(c.username)}')">
                    <div class="podium-avatar-wrapper">
                        ${crowns[i] ? `<div class="podium-crown">${crowns[i]}</div>` : ''}
                        <img src="${pic}" class="podium-avatar" alt="${esc(c.full_name)}">
                        <div class="podium-rank-badge">${i + 1}</div>
                    </div>
                    <div class="podium-name">${esc(c.full_name)}</div>
                    <div class="podium-school">${esc(c.school_name || '')}</div>
                    <div class="podium-points">${formatNum(c.points)} pts</div>
                </div>
            `;
        }
        
        el.innerHTML = html;
    }

    function renderCreatorsTable(creators, containerId) {
        const el = document.getElementById(containerId);
        el.classList && el.classList.remove('loading-placeholder');
        
        if (!creators || creators.length === 0) {
            el.innerHTML = '<div class="empty-state"><i class="fas fa-users"></i><p>Nenhum criador encontrado</p></div>';
            return;
        }

        el.innerHTML = creators.map(c => {
            const pos = c.position;
            const posClass = pos === 1 ? 'gold' : pos === 2 ? 'silver' : pos === 3 ? 'bronze' : '';
            const isMe = c.id == window.currentUserId;
            const pic = c.profile_picture_url || 'assets/images/avatars/' + (c.profile_picture || 'default.webp');
            
            return `
                <div class="creator-row ${isMe ? 'highlighted' : ''}" onclick="goToProfile('${escAttr(c.username)}')">
                    <div class="creator-position ${posClass}">
                        ${pos <= 3 ? getMedal(pos) : pos}
                    </div>
                    <img src="${pic}" class="creator-avatar" alt="${esc(c.full_name)}">
                    <div class="creator-info">
                        <div class="creator-name">
                            ${esc(c.full_name)}
                            ${c.is_verified ? '<i class="fas fa-check-circle verified-badge"></i>' : ''}
                        </div>
                        <div class="creator-school-tag">
                            ${c.school_name ? `<i class="fas fa-graduation-cap"></i> ${esc(c.school_name)}` : ''}
                        </div>
                        <div class="creator-stats-inline">
                            <span class="creator-stat-mini"><i class="fas fa-video"></i> ${c.total_videos}</span>
                            <span class="creator-stat-mini"><i class="fas fa-heart"></i> ${formatNum(c.total_likes)}</span>
                            <span class="creator-stat-mini"><i class="fas fa-eye"></i> ${formatNum(c.total_views)}</span>
                        </div>
                    </div>
                    <div>
                        <div class="creator-points">${formatNum(c.points)}</div>
                        <div class="creator-points-label">pontos</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // ═══════════════════════════════════════
    // LOAD TOP SCHOOLS
    // ═══════════════════════════════════════
    function loadTopSchools() {
        fetch('api/get_rankings.php?action=top_schools&limit=20')
            .then(r => r.json())
            .then(data => {
                dataCache.schools = true;
                const el = document.getElementById('schoolsRanking');
                el.classList.remove('loading-placeholder');
                
                if (!data.success || !data.schools || data.schools.length === 0) {
                    el.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-school"></i>
                            <p>Nenhuma escola no ranking ainda.<br>Seja o primeiro a representar sua escola!</p>
                        </div>
                    `;
                    return;
                }
                
                el.innerHTML = data.schools.map(s => {
                    const topClass = s.position <= 3 ? `top-${s.position}` : '';
                    return `
                        <div class="school-rank-card ${topClass}" onclick="viewSchoolRanking(${s.id}, '${escAttr(s.name)}')">
                            <div class="school-rank-header">
                                <div class="school-rank-position">${s.position <= 3 ? getMedal(s.position) : s.position}</div>
                                <div class="school-rank-logo">🏫</div>
                                <div class="school-rank-info">
                                    <div class="school-rank-name">${esc(s.name)}</div>
                                    <div class="school-rank-city"><i class="fas fa-map-marker-alt"></i> ${esc(s.city)}</div>
                                </div>
                                <div>
                                    <div class="school-rank-points">${formatNum(s.points)}</div>
                                    <div class="school-rank-points-label">pontos</div>
                                </div>
                            </div>
                            <div class="school-rank-stats">
                                <div class="school-stat">
                                    <i class="fas fa-users"></i>
                                    <span class="school-stat-value">${s.total_students}</span> alunos
                                </div>
                                <div class="school-stat">
                                    <i class="fas fa-video"></i>
                                    <span class="school-stat-value">${s.total_videos}</span> vídeos
                                </div>
                                <div class="school-stat">
                                    <i class="fas fa-heart"></i>
                                    <span class="school-stat-value">${formatNum(s.total_likes)}</span> likes
                                </div>
                                <div class="school-stat">
                                    <i class="fas fa-eye"></i>
                                    <span class="school-stat-value">${formatNum(s.total_views)}</span> views
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            });
    }

    // ═══════════════════════════════════════
    // LOAD MY SCHOOL CREATORS
    // ═══════════════════════════════════════
    function loadMySchoolCreators() {
        if (!window.userSchoolId) return;
        
        fetch(`api/get_rankings.php?action=school_creators&school_id=${window.userSchoolId}`)
            .then(r => r.json())
            .then(data => {
                dataCache.myschool = true;
                
                if (!data.success || !data.creators || data.creators.length === 0) {
                    const el = document.getElementById('mySchoolCreators');
                    el.classList.remove('loading-placeholder');
                    el.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>Nenhum criador da sua escola ainda.<br>Seja o primeiro! Publique um vídeo.</p>
                        </div>
                    `;
                    return;
                }
                
                renderCreatorsTable(data.creators, 'mySchoolCreators');
            });
    }

    // ═══════════════════════════════════════
    // SCHOOL SELECTOR MODAL
    // ═══════════════════════════════════════
    window.openSchoolSelector = function() {
        const modal = document.getElementById('schoolModal');
        modal.classList.add('active');
        
        if (allSchools.length === 0) {
            fetch('api/get_rankings.php?action=list_schools')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        allSchools = data.schools;
                        renderSchoolList(allSchools);
                    }
                });
        } else {
            renderSchoolList(allSchools);
        }
    };

    window.closeSchoolSelector = function() {
        document.getElementById('schoolModal').classList.remove('active');
    };

    window.filterSchools = function(query) {
        query = query.toLowerCase().trim();
        if (!query) {
            renderSchoolList(allSchools);
            return;
        }
        const filtered = allSchools.filter(s => 
            s.name.toLowerCase().includes(query) || 
            (s.short_name && s.short_name.toLowerCase().includes(query))
        );
        renderSchoolList(filtered);
    };

    function renderSchoolList(schools) {
        const el = document.getElementById('schoolList');
        if (schools.length === 0) {
            el.innerHTML = '<div class="empty-state"><p>Nenhuma escola encontrada</p></div>';
            return;
        }
        
        el.innerHTML = schools.map(s => `
            <div class="school-list-item ${s.id == window.userSchoolId ? 'selected' : ''}" 
                 onclick="selectSchool(${s.id}, '${escAttr(s.name)}')">
                <div class="school-list-icon">🏫</div>
                <div class="school-list-name">${esc(s.name)}</div>
                <div class="school-list-short">${esc(s.short_name || '')}</div>
            </div>
        `).join('');
    }

    window.selectSchool = function(schoolId, schoolName) {
        fetch('api/get_rankings.php?action=update_school', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ school_id: schoolId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.userSchoolId = schoolId;
                closeSchoolSelector();
                // Show success feedback
                showToast(`🎉 Agora você representa ${schoolName}!`);
                // Reload page to update everything
                setTimeout(() => window.location.reload(), 1200);
            }
        });
    };

    // ═══════════════════════════════════════
    // VIEW SCHOOL RANKING (click on school card)
    // ═══════════════════════════════════════
    window.viewSchoolRanking = function(schoolId, schoolName) {
        // Switch to myschool tab temporarily or show in modal
        fetch(`api/get_rankings.php?action=school_creators&school_id=${schoolId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                
                // If myschool tab exists, use it; otherwise create inline
                let tabEl = document.getElementById('tab-myschool');
                if (!tabEl) {
                    // Create the tab content dynamically
                    tabEl = document.createElement('div');
                    tabEl.className = 'ranking-tab-content';
                    tabEl.id = 'tab-myschool';
                    document.querySelector('.ranking-main').appendChild(tabEl);
                    
                    // Create tab button
                    const tabBtn = document.createElement('button');
                    tabBtn.className = 'ranking-tab';
                    tabBtn.dataset.tab = 'myschool';
                    tabBtn.onclick = () => switchTab('myschool');
                    tabBtn.innerHTML = '<i class="fas fa-users"></i><span>' + esc(data.school ? data.school.short_name || 'Escola' : 'Escola') + '</span>';
                    document.querySelector('.ranking-tabs').appendChild(tabBtn);
                }
                
                tabEl.innerHTML = `
                    <div class="myschool-section">
                        <div class="myschool-header">
                            <h2><i class="fas fa-graduation-cap"></i> ${esc(schoolName)}</h2>
                            <p>Top criadores desta escola</p>
                        </div>
                        <div id="tempSchoolCreators" class="creators-table"></div>
                    </div>
                `;
                
                renderCreatorsTable(data.creators, 'tempSchoolCreators');
                switchTab('myschool');
            });
    };

    // ═══════════════════════════════════════
    // NAVIGATION HELPERS
    // ═══════════════════════════════════════
    window.goToProfile = function(username) {
        window.location.href = 'perfil.php?username=' + encodeURIComponent(username);
    };

    // ═══════════════════════════════════════
    // UTILITY FUNCTIONS
    // ═══════════════════════════════════════
    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, '\\n').replace(/\r/g, '\\r');
    }

    function formatNum(num) {
        num = parseInt(num) || 0;
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1) + 'k';
        return num.toString();
    }

    function getMedal(pos) {
        const medals = { 1: '🥇', 2: '🥈', 3: '🥉' };
        return medals[pos] || pos;
    }

    function showToast(message) {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%);
            background: rgba(0, 212, 255, 0.95); color: #000; padding: 12px 24px;
            border-radius: 30px; font-size: 14px; font-weight: 600; z-index: 99999;
            box-shadow: 0 4px 20px rgba(0, 212, 255, 0.3); 
            animation: fadeInUp 0.3s ease;
            white-space: nowrap;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 2500);
    }

    // ═══════════════════════════════════════
    // RANKING SEARCH SYSTEM
    // ═══════════════════════════════════════
    let searchTimeout = null;
    let expandedUserId = null;

    window.openSearchModal = function() {
        const modal = document.getElementById('rankingSearchModal');
        if (!modal) return;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            const input = document.getElementById('rankingSearchInput');
            if (input) input.focus();
        }, 100);
    };

    window.closeRankingSearch = function() {
        const modal = document.getElementById('rankingSearchModal');
        if (!modal) return;
        modal.classList.remove('active');
        document.body.style.overflow = '';
        clearRankingSearch();
        expandedUserId = null;
    };

    window.clearRankingSearch = function() {
        const input = document.getElementById('rankingSearchInput');
        const results = document.getElementById('rankingSearchResults');
        const clear = document.getElementById('rankingSearchClear');
        if (input) { input.value = ''; input.focus(); }
        if (clear) clear.style.display = 'none';
        if (results) results.innerHTML = '<div class="search-placeholder"><i class="fas fa-search"></i><p>Pesquise por criadores</p></div>';
        expandedUserId = null;
    };

    function rankingSearch(query) {
        const results = document.getElementById('rankingSearchResults');
        if (query.length < 2) {
            results.innerHTML = '<div class="search-placeholder"><i class="fas fa-search"></i><p>Digite pelo menos 2 caracteres</p></div>';
            return;
        }
        results.innerHTML = '<div class="search-loading"><div class="spinner-small"></div></div>';
        expandedUserId = null;

        fetch('api/search.php?context=ranking&q=' + encodeURIComponent(query))
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                if (data.success) {
                    renderRankingSearchResults(data.users);
                } else {
                    results.innerHTML = '<div class="search-no-results"><i class="fas fa-exclamation-circle"></i><p>Erro ao pesquisar</p></div>';
                }
            })
            .catch(() => {
                results.innerHTML = '<div class="search-no-results"><i class="fas fa-exclamation-circle"></i><p>Erro de conexão</p></div>';
            });
    }

    function renderRankingSearchResults(users) {
        const results = document.getElementById('rankingSearchResults');
        if (!users || users.length === 0) {
            results.innerHTML = '<div class="search-no-results"><i class="fas fa-search"></i><p>Nenhum criador encontrado</p></div>';
            return;
        }

        results.innerHTML = '<div class="ranking-search-list">' +
            users.map(function(u) {
                const pic = u.profile_picture_url || 'assets/images/avatars/' + (u.profile_picture || 'default.webp');
                const verified = u.is_verified ? ' <i class="fas fa-check-circle" style="color:#20d5ec;font-size:11px"></i>' : '';
                const school = u.school_name || 'Sem escola';
                const pts = u.ranking_points || 0;
                return '<div class="rs-item" data-uid="' + u.id + '" onclick="toggleRankingCard(' + u.id + ')">' +
                    '<div class="rs-item-row">' +
                        '<img src="' + pic + '" alt="" class="rs-avatar" loading="lazy">' +
                        '<div class="rs-info">' +
                            '<div class="rs-name">' + escapeHtml(u.full_name || u.username) + verified + '</div>' +
                            '<div class="rs-meta">' + escapeHtml(u.full_name || '') + '</div>' +
                        '</div>' +
                        '<div class="rs-points-badge">' + formatNum(pts) + ' pts</div>' +
                    '</div>' +
                    '<div class="rs-expanded" id="rs-expand-' + u.id + '">' +
                        '<div class="rs-stats-grid">' +
                            '<div class="rs-stat"><i class="fas fa-school"></i><span>' + escapeHtml(school) + '</span></div>' +
                            '<div class="rs-stat"><i class="fas fa-bolt"></i><span>' + formatNum(pts) + ' pontos</span></div>' +
                            '<div class="rs-stat"><i class="fas fa-video"></i><span>' + formatNum(u.videos_count) + ' vídeos</span></div>' +
                            '<div class="rs-stat"><i class="fas fa-users"></i><span>' + formatNum(u.followers_count) + ' seguidores</span></div>' +
                        '</div>' +
                        '<button class="rs-profile-link" onclick="event.stopPropagation(); window.location.href=\'perfil.php?id=' + u.id + '\'"><i class="fas fa-user"></i> Ver perfil completo</button>' +
                    '</div>' +
                '</div>';
            }).join('') +
        '</div>';
    }

    window.toggleRankingCard = function(userId) {
        var el = document.getElementById('rs-expand-' + userId);
        if (!el) return;
        var item = el.closest('.rs-item');
        if (expandedUserId === userId) {
            el.classList.remove('open');
            if (item) item.classList.remove('rs-item-active');
            expandedUserId = null;
        } else {
            // Collapse previous
            if (expandedUserId) {
                var prev = document.getElementById('rs-expand-' + expandedUserId);
                if (prev) {
                    prev.classList.remove('open');
                    var prevItem = prev.closest('.rs-item');
                    if (prevItem) prevItem.classList.remove('rs-item-active');
                }
            }
            el.classList.add('open');
            if (item) item.classList.add('rs-item-active');
            expandedUserId = userId;
        }
    };

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Search input listeners
    (function() {
        var input = document.getElementById('rankingSearchInput');
        var clear = document.getElementById('rankingSearchClear');
        var modal = document.getElementById('rankingSearchModal');

        if (input) {
            input.addEventListener('input', function() {
                var q = input.value.trim();
                if (clear) clear.style.display = q.length > 0 ? 'flex' : 'none';
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() { rankingSearch(q); }, 300);
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
                closeRankingSearch();
            }
        });
    })();

    // ═══════════════════════════════════════
    // BEST MYTUBER DA SEMANA
    // ═══════════════════════════════════════
    function loadBestMyTuber() {
        fetch('api/get_best_mytuber.php?action=current')
            .then(r => r.json())
            .then(data => {
                renderBestMyTuberContent(data.winners || []);
            })
            .catch(err => {
                console.error('Erro Best MyTuber:', err);
                const section = document.getElementById('bestMytuberSection');
                if (section) section.style.display = 'none';
            });
    }

    function renderBestMyTuberContent(winners) {
                const section = document.getElementById('bestMytuberSection');
                if (!section) return;
                
                if (!winners || winners.length === 0) {
                    section.style.display = 'none';
                    return;
                }
                
                section.style.display = 'block';
                
                const globalWinner = winners.find(w => w.scope === 'global');
                const schoolWinners = winners.filter(w => w.scope === 'school');
                
                let html = '';
                
                // Seção header
                html += '<div class="section-header-label best-mytuber-header-label">';
                html += '<i class="fas fa-crown"></i> Best MyTuber da Semana';
                html += '</div>';
                
                // Global Winner
                if (globalWinner) {
                    const pic = globalWinner.profile_picture_url || 'assets/images/avatars/default.webp';
                    html += `
                        <div class="best-mytuber-card best-mytuber-global-card" onclick="goToProfile('${esc(globalWinner.username)}')">
                            <div class="best-mytuber-card-glow"></div>
                            <div class="best-mytuber-crown-icon"><svg class="mytube-rank-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:1.2em; height:1.2em; vertical-align:middle; display:inline-block; filter:drop-shadow(0 2px 4px rgba(0,123,255,0.4));"><defs><linearGradient id="mytubeFlame" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4facfe"/><stop offset="100%" stop-color="#00f2fe"/></linearGradient><linearGradient id="mytubeBar" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#007BFF"/><stop offset="100%" stop-color="#003D82"/></linearGradient></defs><path d="M12 2C12 2 15 5.5 15 8C15 9.65 13.65 11 12 11C10.35 11 9 9.65 9 8C9 5.5 12 2 12 2Z" fill="url(#mytubeFlame)"/><rect x="7" y="13" width="10" height="4" rx="1" fill="url(#mytubeBar)"/><rect x="3" y="19" width="18" height="4" rx="1" fill="url(#mytubeBar)"/></svg></div>
                            <div class="best-mytuber-tag">Best MyTuber Global</div>
                            <div class="best-mytuber-avatar-wrapper">
                                <img src="${esc(pic)}" class="best-mytuber-avatar" alt="${esc(globalWinner.full_name)}">
                                <div class="best-mytuber-badge-icon best-mytuber-badge-gold">
                                    <i class="fas fa-crown"></i>
                                </div>
                            </div>
                            <div class="best-mytuber-name">${esc(globalWinner.full_name)}</div>
                            <div class="best-mytuber-username">@${esc(globalWinner.username)}</div>
                            ${globalWinner.rising_star_bonus == 1 ? '<div class="best-mytuber-rising"><i class="fas fa-bolt"></i> Rising Star</div>' : ''}
                            <div class="best-mytuber-stats-row">
                                <div class="best-mytuber-stat">
                                    <div class="best-mytuber-stat-val">${formatNum(globalWinner.total_score)}</div>
                                    <div class="best-mytuber-stat-lbl">Score</div>
                                </div>
                                <div class="best-mytuber-stat">
                                    <div class="best-mytuber-stat-val">${globalWinner.videos_count}</div>
                                    <div class="best-mytuber-stat-lbl">Vídeos</div>
                                </div>
                                <div class="best-mytuber-stat">
                                    <div class="best-mytuber-stat-val">${formatNum(globalWinner.total_likes)}</div>
                                    <div class="best-mytuber-stat-lbl">Likes</div>
                                </div>
                                <div class="best-mytuber-stat">
                                    <div class="best-mytuber-stat-val">${formatNum(globalWinner.total_views)}</div>
                                    <div class="best-mytuber-stat-lbl">Views</div>
                                </div>
                            </div>
                            <div class="best-mytuber-score-breakdown">
                                <div class="score-bar" title="Consistência">
                                    <div class="score-bar-fill" style="width:${Math.min(100, (globalWinner.consistency_score/25)*100)}%; background: linear-gradient(90deg, #22c55e, #16a34a);">
                                        <span>✔ ${Number(globalWinner.consistency_score).toFixed(0)}</span>
                                    </div>
                                </div>
                                <div class="score-bar" title="Qualidade">
                                    <div class="score-bar-fill" style="width:${Math.min(100, (globalWinner.quality_score/30)*100)}%; background: linear-gradient(90deg, #f59e0b, #f97316);">
                                        <span>⭐ ${Number(globalWinner.quality_score).toFixed(0)}</span>
                                    </div>
                                </div>
                                <div class="score-bar" title="Engajamento">
                                    <div class="score-bar-fill" style="width:${Math.min(100, (globalWinner.engagement_score/25)*100)}%; background: linear-gradient(90deg, #ef4444, #dc2626);">
                                        <span>🔥 ${Number(globalWinner.engagement_score).toFixed(0)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                // School Winners — Top 3 escolas apenas
                const topSchoolWinners = schoolWinners
                    .sort((a, b) => b.total_score - a.total_score)
                    .slice(0, 3);
                
                if (topSchoolWinners.length > 0) {
                    html += '<div class="best-mytuber-schools-title">';
                    html += '<i class="fas fa-medal"></i> Best MyTuber por Escola';
                    html += '</div>';
                    html += '<div class="best-mytuber-school-grid">';
                    
                    topSchoolWinners.forEach(w => {
                        const pic = w.profile_picture_url || 'assets/images/avatars/default.webp';
                        const schoolName = w.school_short || w.school_name || 'Escola';
                        html += `
                            <div class="best-mytuber-school-card" onclick="goToProfile('${esc(w.username)}')">
                                <div class="best-mytuber-school-card-header">
                                    <img src="${esc(pic)}" class="best-mytuber-school-avatar" alt="${esc(w.full_name)}">
                                    <div class="best-mytuber-badge-icon best-mytuber-badge-blue">
                                        <i class="fas fa-medal"></i>
                                    </div>
                                </div>
                                <div class="best-mytuber-school-name">${esc(w.full_name)}</div>
                                <div class="best-mytuber-school-tag">
                                    <i class="fas fa-graduation-cap"></i> ${esc(schoolName)}
                                </div>
                                <div class="best-mytuber-school-score">${formatNum(w.total_score)} pts</div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                }
                
                section.innerHTML = html;
    }

})();
