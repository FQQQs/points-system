<?php
/**
 * 苍井寿司 AI 积分管理系统 — 首页仪表盘
 *
 * 全面数据概览：人员统计、积分概况、月度变化、排行榜、最近动态
 */

require_once 'includes/db.php';
require_once 'includes/layout.php';

$db = getDB();

// ========================
// Personnel Stats
// ========================
$totalEmployees = $db->query('SELECT COUNT(*) FROM employees')->fetchColumn();
$totalActive    = $db->query("SELECT COUNT(*) FROM employees WHERE status = '在职'")->fetchColumn();
$totalDepts     = $db->query('SELECT COUNT(*) FROM departments')->fetchColumn();

// AI Level distribution
$levels = $db->query(
    "SELECT ai_level, COUNT(*) AS cnt FROM employees WHERE status = '在职' GROUP BY ai_level ORDER BY ai_level"
)->fetchAll();
$levelMap = [];
foreach ($levels as $l) $levelMap[$l['ai_level']] = $l['cnt'];

// ========================
// Points Stats
// ========================
$totalPoints = $db->query('SELECT COALESCE(SUM(points), 0) FROM points_log')->fetchColumn();
$ptRaw = $db->query(
    "SELECT COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS earned,
            COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END), 0) AS spent
     FROM points_log"
)->fetch();

// By type breakdown
$byType = $db->query(
    "SELECT type, COALESCE(SUM(points), 0) AS total, COUNT(*) AS cnt
     FROM points_log
     GROUP BY type ORDER BY total DESC"
)->fetchAll();
$typeMap = [];
foreach ($byType as $t) $typeMap[$t['type']] = $t;

// ========================
// Monthly Trend
// ========================
$thisMonth = date('Y-m');
$lastMonth = date('Y-m', strtotime('-1 month'));

$monthPoints = $db->prepare(
    "SELECT COALESCE(SUM(points), 0) FROM points_log WHERE points > 0 AND strftime('%Y-%m', created_at) = :m"
);
$monthPoints->execute([':m' => $thisMonth]);
$ptsThisMonth = floatval($monthPoints->fetchColumn());

$monthSpent = $db->prepare(
    "SELECT COALESCE(SUM(-points), 0) FROM points_log WHERE points < 0 AND strftime('%Y-%m', created_at) = :m"
);
$monthSpent->execute([':m' => $thisMonth]);
$spentThisMonth = floatval($monthSpent->fetchColumn());

$monthPoints->execute([':m' => $lastMonth]);
$ptsLastMonth = floatval($monthPoints->fetchColumn());

$monthSpent->execute([':m' => $lastMonth]);
$spentLastMonth = floatval($monthSpent->fetchColumn());

// ========================
// Top Rankings
// ========================
$topEarners = $db->query(
    "SELECT e.name, d.name AS dept_name, COALESCE(SUM(pl.points), 0) AS total
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     LEFT JOIN points_log pl ON pl.employee_id = e.id
     WHERE e.status = '在职'
     GROUP BY e.id
     ORDER BY total DESC
     LIMIT 5"
)->fetchAll();

// ========================
// Achievement & Exchange Stats
// ========================
$achPending   = $db->query("SELECT COUNT(*) FROM achievement_applications WHERE status = 'pending'")->fetchColumn();
$achApproved  = $db->query("SELECT COUNT(*) FROM achievement_applications WHERE status = 'approved'")->fetchColumn();
$exchangeCount = $db->query('SELECT COUNT(*) FROM exchange_records')->fetchColumn();
$prizeCount    = $db->query("SELECT COUNT(*) FROM prizes WHERE status = 'active'")->fetchColumn();

// ========================
// Recent Activities
// ========================
$recentLogs = $db->query(
    "SELECT pl.type, pl.points, pl.description, pl.created_at, e.name AS emp_name
     FROM points_log pl
     JOIN employees e ON pl.employee_id = e.id
     ORDER BY pl.id DESC
     LIMIT 8"
)->fetchAll();

$recentAchievements = $db->query(
    "SELECT aa.description, aa.total_score, aa.reviewed_at, e.name AS emp_name
     FROM achievement_applications aa
     JOIN employees e ON aa.employee_id = e.id
     WHERE aa.status = 'approved'
     ORDER BY aa.reviewed_at DESC
     LIMIT 4"
)->fetchAll();

// ========================
// Level colors & names
// ========================
$lvlColors  = ['L0' => '#95a5a6', 'L1' => '#3498db', 'L2' => '#27ae60', 'L3' => '#f39c12', 'L4' => '#e74c3c'];
$lvlOrder   = ['L0', 'L1', 'L2', 'L3', 'L4'];

ob_start();
?>

<style>
/* ========== Dashboard v3.0 Styles ========== */

/* -- KPI Stat Cards -- */
.dash-stat-card {
    border: none;
    border-radius: var(--radius-lg);
    color: #fff;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
}
.dash-stat-card::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    pointer-events: none;
}
.dash-stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(26,35,50,0.2); }
.dash-stat-card .card-body { padding: 20px 20px 16px; position: relative; z-index: 1; }
.dash-stat-card .stat-icon {
    font-size: 2.2rem;
    opacity: 0.35;
    position: absolute;
    right: 18px;
    top: 14px;
    transition: transform 0.3s ease;
}
.dash-stat-card:hover .stat-icon { transform: scale(1.1) rotate(-5deg); }
.dash-stat-card .stat-value { font-size: 2rem; font-weight: 800; letter-spacing: -0.5px; line-height: 1.1; color: #1e1e1e; }
.dash-stat-card .stat-label { font-size: 0.8rem; opacity: 0.85; margin-top: 4px; font-weight: 600; letter-spacing: 0.3px; color: #2d2d2d; }
.dash-stat-card .stat-sub { font-size: 0.72rem; opacity: 0.75; margin-top: 6px; line-height: 1.4; color: #4a4a4a; }
.dash-stat-card .stat-icon { opacity: 0.8; }

/* Refined gradients */
.bg-gradient-navy   { background: linear-gradient(135deg, #1a2332, #2c3e50); }
.bg-gradient-gold   { background: linear-gradient(135deg, #b8944d, #c9a96e); }
.bg-gradient-teal   { background: linear-gradient(135deg, #2d6a5a, #3d8a78); }
.bg-gradient-rose   { background: linear-gradient(135deg, #8b5a5a, #a06a6a); }

/* -- Section Title -- */
.dash-section-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--color-navy);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.2px;
}

/* -- Points Bar Item -- */
.points-bar-item { margin-bottom: 14px; }
.points-bar-item:last-child { margin-bottom: 0; }
.points-bar-item .bar-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
    font-size: 13px;
}
.points-bar-item .bar-label .type-icon { margin-right: 4px; }
.points-bar-item .bar-label .type-name { font-weight: 600; color: var(--color-text); }
.points-bar-item .bar-label .type-value {
    font-size: 13px;
    font-weight: 700;
    font-family: var(--font-mono);
}

/* -- Level Distribution -- */
.level-item { margin-bottom: 10px; }
.level-item:last-child { margin-bottom: 0; }
.level-item .level-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    font-size: 13px;
}
.level-item .level-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    margin-right: 8px;
}
.level-item .level-badge .dot {
    width: 10px; height: 10px;
    border-radius: 3px;
    display: inline-block;
}
.level-item .level-count {
    font-family: var(--font-mono);
    font-size: 13px;
    color: var(--color-text-secondary);
    font-weight: 600;
}
.level-progress { height: 6px; border-radius: 3px; background: #f0ece5; }
.level-progress .level-bar { border-radius: 3px; transition: width 0.8s ease; height: 100%; }

/* -- Dashboard Summary Cards (compact) -- */
.dash-summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px 12px;
    background: #fafaf8;
    border-radius: var(--radius-sm);
    min-width: 90px;
}
.dash-summary-item .summary-num {
    font-size: 18px;
    font-weight: 800;
    font-family: var(--font-mono);
}
.dash-summary-item .summary-label {
    font-size: 10px;
    color: var(--color-text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* -- Timeline (refined) -- */
.timeline-list { position: relative; }
.timeline-item {
    border-left: 2px solid var(--color-border);
    margin-left: 6px;
    padding: 0 0 14px 16px;
    position: relative;
    transition: border-color 0.2s;
}
.timeline-item:hover { border-left-color: var(--color-gold); }
.timeline-item:last-child { padding-bottom: 0; border-left-color: transparent; }
.timeline-item::before {
    content: '';
    position: absolute;
    left: -5px;
    top: 5px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--color-gold);
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px var(--color-gold-light);
    transition: transform 0.2s;
}
.timeline-item:hover::before { transform: scale(1.3); }
.timeline-item .tl-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; }

/* -- Quick Action Cards -- */
.quick-action-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
.qa-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--color-white);
    border: 1px solid var(--color-border-light);
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}
.qa-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 3px;
    height: 100%;
    transition: width 0.2s ease;
}
.qa-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    text-decoration: none;
}
.qa-card:hover::before { width: 5px; }
.qa-card .qa-icon {
    width: 42px; height: 42px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
    color: #fff;
    font-weight: 700;
}
.qa-card .qa-info { min-width: 0; }
.qa-card .qa-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--color-text);
    letter-spacing: -0.1px;
}
.qa-card .qa-desc {
    font-size: 11px;
    color: var(--color-text-muted);
    margin-top: 2px;
}

/* Active nav indicator on row 3 cards */
.ach-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    background: #fafaf8;
}
.ach-nav-item strong { font-size: 14px; }
.ach-nav-item small { font-size: 10px; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }

@media (max-width: 992px) {
    .quick-action-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 576px) {
    .quick-action-grid { grid-template-columns: 1fr; }
    .dash-stat-card .stat-value { font-size: 1.5rem; }
}
</style>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h2>首页仪表盘</h2>
        <small class="text-muted">数据快照 · <?php echo date('Y-m-d H:i'); ?></small>
    </div>
</div>

<!-- ========================
     Row 1: KPI Cards
     ======================== -->
<div class="row animate-stagger">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card dash-stat-card bg-gradient-navy">
            <div class="card-body">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?php echo $totalActive; ?></div>
                <div class="stat-label">在职员工</div>
                <div class="stat-sub">共 <?php echo $totalEmployees; ?> 人 · <?php echo $totalDepts; ?> 个部门</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card dash-stat-card bg-gradient-gold">
            <div class="card-body">
                <div class="stat-icon">⭐</div>
                <div class="stat-value"><?php echo number_format($ptRaw['earned'], 1); ?></div>
                <div class="stat-label">累计发放积分</div>
                <div class="stat-sub">已兑换 <?php echo number_format($ptRaw['spent'], 1); ?> 分</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card dash-stat-card bg-gradient-teal">
            <div class="card-body">
                <div class="stat-icon">📅</div>
                <div class="stat-value"><?php echo number_format($ptsThisMonth - $spentThisMonth, 1); ?></div>
                <div class="stat-label">本月净增</div>
                <div class="stat-sub">
                    <?php if ($ptsLastMonth > 0):
                        $growth = $ptsLastMonth > 0 ? round((($ptsThisMonth - $spentThisMonth) - ($ptsLastMonth - $spentLastMonth)) / ($ptsLastMonth - $spentLastMonth ?: 1) * 100) : 0;
                    ?>
                    <?php echo $growth >= 0 ? '↑' : '↓'; ?> <?php echo abs($growth); ?>% vs 上月
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card dash-stat-card bg-gradient-rose">
            <div class="card-body">
                <div class="stat-icon">🏆</div>
                <div class="stat-value"><?php echo ($levelMap['L3'] ?? 0) + ($levelMap['L4'] ?? 0); ?></div>
                <div class="stat-label">L3+ 高级认证</div>
                <div class="stat-sub">L3 <?php echo $levelMap['L3'] ?? 0; ?> 人 · L4 <?php echo $levelMap['L4'] ?? 0; ?> 人</div>
            </div>
        </div>
    </div>
</div>

<!-- ========================
     Row 2: Points Breakdown + AI Levels
     ======================== -->
<div class="row">
    <!-- Points by Type -->
    <div class="col-lg-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><strong>积分类型分布</strong></div>
            <div class="card-body">
                <?php
                $typeInfo = [
                    'survey'      => ['name' => '调查积分', 'color' => 'var(--color-info)',    'bg' => '#dce8f2'],
                    'training'    => ['name' => '培训积分', 'color' => 'var(--color-success)', 'bg' => '#daf0da'],
                    'exam'        => ['name' => '考核积分', 'color' => 'var(--color-warning)', 'bg' => '#fae8d8'],
                    'achievement' => ['name' => '成就积分', 'color' => 'var(--color-danger)',  'bg' => '#fae0de'],
                    'exchange'    => ['name' => '积分兑换', 'color' => 'var(--color-text-muted)','bg' => '#e8ecf0'],
                ];
                $maxPts = max(array_map(function($k) use ($typeMap) {
                    return abs($typeMap[$k]['total'] ?? 0);
                }, array_keys($typeInfo)) ?: [1]);
                $maxPts = $maxPts ?: 1;
                foreach ($typeInfo as $tk => $ti):
                    $pts  = floatval($typeMap[$tk]['total'] ?? 0);
                    $cnt  = intval($typeMap[$tk]['cnt'] ?? 0);
                    $pct  = $maxPts > 0 ? min(abs($pts) / $maxPts * 100, 100) : 0;
                    $sign = $pts >= 0 ? '+' : '';
                ?>
                <div class="points-bar-item">
                    <div class="bar-label">
                        <span class="type-name"><?php echo $ti['name']; ?></span>
                        <span class="type-value <?php echo $pts >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $sign . number_format($pts, 1); ?>
                            <span class="text-muted font-weight-normal" style="font-size:11px;">(<?php echo $cnt; ?>条)</span>
                        </span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $ti['color']; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- AI Level Distribution -->
    <div class="col-lg-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><strong>AI 等级分布</strong> <small class="text-muted">（在职员工）</small></div>
            <div class="card-body">
                <?php
                $lvlNewColors = ['L0'=>'#8899aa','L1'=>'#5b8fa8','L2'=>'#4a8c4a','L3'=>'#d4956a','L4'=>'#c4655a'];
                foreach ($lvlOrder as $lv):
                    $cnt = $levelMap[$lv] ?? 0;
                    $pct = $totalActive > 0 ? round($cnt / $totalActive * 100) : 0;
                    $lbl = ['L0'=>'入门','L1'=>'初级','L2'=>'中级','L3'=>'高级','L4'=>'专家'];
                ?>
                <div class="level-item">
                    <div class="level-row">
                        <span class="level-badge">
                            <span class="dot" style="background:<?php echo $lvlNewColors[$lv]; ?>;"></span>
                            <?php echo $lv; ?> <?php echo $lbl[$lv]; ?>
                        </span>
                        <span class="level-count"><?php echo $cnt; ?> 人 (<?php echo $pct; ?>%)</span>
                    </div>
                    <div class="level-progress">
                        <div class="level-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $lvlNewColors[$lv]; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <hr>
                <div class="d-flex justify-content-between flex-wrap" style="gap:8px;">
                    <div class="ach-nav-item">
                        <strong class="text-warning"><?php echo $achPending; ?></strong>
                        <small>待审核</small>
                    </div>
                    <div class="ach-nav-item">
                        <strong class="text-success"><?php echo $achApproved; ?></strong>
                        <small>已通过</small>
                    </div>
                    <div class="ach-nav-item">
                        <strong class="text-info"><?php echo $exchangeCount; ?></strong>
                        <small>兑换记录</small>
                    </div>
                    <div class="ach-nav-item">
                        <strong><?php echo $prizeCount; ?></strong>
                        <small>上架奖品</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================
     Row 3: Top Rankings + Recent Activity
     ======================== -->
<div class="row">
    <!-- Top Earners -->
    <div class="col-lg-5 mb-3">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>🏅 积分排行 Top 5</strong>
                <a href="/pages/leaderboard.php" class="btn btn-sm btn-outline-primary">完整排行 →</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topEarners)): ?>
                <div class="empty-state"><p>暂无数据</p></div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php foreach ($topEarners as $i => $te):
                            $medal = ($i === 0) ? '🥇' : (($i === 1) ? '🥈' : (($i === 2) ? '🥉' : ''));
                        ?>
                        <tr>
                            <td style="width:44px;" class="text-center font-weight-bold" style="font-size:16px;">
                                <?php echo $medal ?: ($i + 1); ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($te['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($te['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td class="text-right pr-3">
                                <strong class="text-navy font-mono"><?php echo number_format($te['total'], 1); ?></strong>
                                <small class="text-muted">分</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="col-lg-7 mb-3">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>📌 最近动态</strong>
                <a href="/pages/reports.php" class="btn btn-sm btn-outline-primary">查看全部 →</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentLogs)): ?>
                <div class="empty-state"><p>暂无动态</p></div>
                <?php else: ?>
                <?php
                $badgeC = ['survey'=>'info','training'=>'success','exam'=>'warning','achievement'=>'danger','exchange'=>'secondary'];
                $badgeN = ['survey'=>'调查','training'=>'培训','exam'=>'考核','achievement'=>'成就','exchange'=>'兑换'];
                ?>
                <div class="timeline-list">
                <?php foreach ($recentLogs as $log):
                    $bc = $badgeC[$log['type']] ?? 'secondary';
                    $bn = $badgeN[$log['type']] ?? $log['type'];
                    $neg = $log['points'] < 0;
                ?>
                    <div class="timeline-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <span>
                                <span class="badge badge-<?php echo $bc; ?> tl-badge mr-1"><?php echo $bn; ?></span>
                                <strong><?php echo htmlspecialchars($log['emp_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </span>
                            <span class="<?php echo $neg ? 'text-danger' : 'text-success'; ?> font-weight-bold font-mono" style="font-size:13px;">
                                <?php echo $neg ? number_format($log['points'], 1) : '+' . number_format($log['points'], 1); ?>
                            </span>
                        </div>
                        <small class="text-muted"><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <br><small class="text-muted" style="font-size:11px;"><?php echo $log['created_at']; ?></small>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ========================
     Row 4: Quick Actions
     ======================== -->
<div class="mb-3 mt-2">
    <div class="dash-section-title">⚡ 快捷操作</div>
    <div class="quick-action-grid">
        <?php
        $links = [
            ['href' => '/index.php',                    'icon' => '👥', 'bg' => '#3d6d8a', 'title' => '员工管理',   'desc' => '查看与管理员工信息'],
            ['href' => '/pages/points.php',             'icon' => '⚡', 'bg' => '#4a8c4a', 'title' => '积分发放',   'desc' => '调查·培训·考核积分'],
            ['href' => '/pages/achievement.php',        'icon' => '🌟', 'bg' => '#c4655a', 'title' => '成就积分',   'desc' => '申请提交与审核评定'],
            ['href' => '/pages/exchange.php',           'icon' => '🔄', 'bg' => '#d4956a', 'title' => '积分兑换',   'desc' => '假期·实物兑换管理'],
            ['href' => '/pages/prizes.php',             'icon' => '🎁', 'bg' => '#8b5a7a', 'title' => '积分奖品',   'desc' => '奖品目录上下架'],
            ['href' => '/pages/leaderboard.php',        'icon' => '🏆', 'bg' => '#2d6a5a', 'title' => '排行榜',     'desc' => '多维积分排名'],
            ['href' => '/pages/reports.php',            'icon' => '📋', 'bg' => '#1a2332', 'title' => '报表中心',   'desc' => '数据查询与汇总'],
            ['href' => '/pages/points_records.php',     'icon' => '📝', 'bg' => '#6b7280', 'title' => '积分记录',   'desc' => '全部操作日志'],
        ];
        foreach ($links as $l):
        ?>
        <a href="<?php echo $l['href']; ?>" class="qa-card" style="--accent:<?php echo $l['bg']; ?>;">
            <style>.qa-card[style*="--accent:<?php echo $l['bg']; ?>"]::before { background: <?php echo $l['bg']; ?>; }</style>
            <div class="qa-icon" style="background:<?php echo $l['bg']; ?>;"><?php echo $l['icon']; ?></div>
            <div class="qa-info">
                <div class="qa-title"><?php echo $l['title']; ?></div>
                <div class="qa-desc"><?php echo $l['desc']; ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout('首页仪表盘', 'dashboard', $content);
