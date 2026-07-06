<?php
/**
 * 苍井寿司 AI 积分管理系统 — 排行榜
 *
 * 总积分排名 / 月度新增排名 / 部门人均排名
 * LDB-01 ~ LDB-04
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

$activeList = $_GET['list'] ?? 'total'; // total | monthly | dept
$dateFrom   = $_GET['from'] ?? date('Y-m-01');
$dateTo     = $_GET['to']   ?? date('Y-m-d');

// Month selector defaults
if (!isset($_GET['month'])) {
    $activeMonth = date('Y-m');
} else {
    $activeMonth = $_GET['month'];
}

// ========================
// Tab 1: Total Points Ranking
// ========================
$totalRank = $db->prepare(
    "SELECT e.id, e.name, d.name AS dept_name,
            COALESCE(SUM(pl.points), 0) AS total_pts,
            COALESCE(SUM(CASE WHEN pl.type = 'survey' THEN pl.points ELSE 0 END), 0) AS survey_pts,
            COALESCE(SUM(CASE WHEN pl.type = 'training' THEN pl.points ELSE 0 END), 0) AS training_pts,
            COALESCE(SUM(CASE WHEN pl.type = 'exam' THEN pl.points ELSE 0 END), 0) AS exam_pts,
            COALESCE(SUM(CASE WHEN pl.type = 'achievement' THEN pl.points ELSE 0 END), 0) AS achieve_pts
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     LEFT JOIN points_log pl ON pl.employee_id = e.id
     WHERE e.status = '在职'
     GROUP BY e.id
     ORDER BY total_pts DESC
     LIMIT 50"
);
$totalRank->execute();
$totalRankings = $totalRank->fetchAll();

// ========================
// Tab 2: Monthly New Points Ranking
// ========================
$monthStart = $activeMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$monthlyRank = $db->prepare(
    "SELECT e.id, e.name, d.name AS dept_name,
            COALESCE(SUM(CASE WHEN pl.points > 0 THEN pl.points ELSE 0 END), 0) AS earned,
            COALESCE(SUM(CASE WHEN pl.points < 0 THEN -pl.points ELSE 0 END), 0) AS spent,
            COALESCE(SUM(pl.points), 0) AS net
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     LEFT JOIN points_log pl ON pl.employee_id = e.id
         AND pl.created_at >= :dfrom AND pl.created_at <= :dto
     WHERE e.status = '在职'
     GROUP BY e.id
     HAVING net > 0
     ORDER BY net DESC
     LIMIT 50"
);
$monthlyRank->execute([':dfrom' => $monthStart . ' 00:00:00', ':dto' => $monthEnd . ' 23:59:59']);
$monthlyRankings = $monthlyRank->fetchAll();

// Available months for dropdown
$months = $db->query(
    "SELECT DISTINCT strftime('%Y-%m', created_at) AS m
     FROM points_log
     UNION SELECT strftime('%Y-%m', 'now')
     ORDER BY m DESC
     LIMIT 12"
)->fetchAll();

// ========================
// Tab 3: Department Average Ranking
// ========================
$deptRank = $db->prepare(
    "SELECT d.id, d.name,
            COUNT(DISTINCT e.id) AS emp_count,
            COALESCE(SUM(pl.points), 0) AS total_pts,
            ROUND(COALESCE(SUM(pl.points), 0) * 1.0 / MAX(COUNT(DISTINCT e.id), 1), 1) AS avg_pts
     FROM departments d
     LEFT JOIN employees e ON e.department_id = d.id AND e.status = '在职'
     LEFT JOIN points_log pl ON pl.employee_id = e.id
         AND pl.created_at >= :dfrom2 AND pl.created_at <= :dto2
     GROUP BY d.id
     HAVING emp_count > 0
     ORDER BY avg_pts DESC"
);
$deptRank->execute([
    ':dfrom2' => $dateFrom . ' 00:00:00',
    ':dto2'   => $dateTo . ' 23:59:59',
]);
$deptRankings = $deptRank->fetchAll();

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>排行榜</h2>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="rankTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeList === 'total' ? 'active' : ''; ?>"
           href="?list=total">总积分排名</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeList === 'monthly' ? 'active' : ''; ?>"
           href="?list=monthly">月度新增排名</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeList === 'dept' ? 'active' : ''; ?>"
           href="?list=dept">部门人均排名</a>
    </li>
</ul>

<?php if ($activeList === 'total'): ?>
<!-- ========== Total Points Ranking ========== -->
<div class="card">
    <div class="card-header"><strong>全员总积分排名</strong> <small class="text-muted">（Top 50）</small></div>
    <div class="card-body p-0">
        <?php if (empty($totalRankings)): ?>
        <div class="p-3 text-muted">暂无数据</div>
        <?php else: ?>
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th style="width:40px;">#</th>
                    <th>员工</th>
                    <th>部门</th>
                    <th style="width:55px;" title="调查">调查</th>
                    <th style="width:55px;" title="培训">培训</th>
                    <th style="width:55px;" title="考核">考核</th>
                    <th style="width:55px;" title="成就">成就</th>
                    <th style="width:70px;">总积分</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank = 0; $prevPts = null; ?>
                <?php foreach ($totalRankings as $i => $r):
                    if ($prevPts !== $r['total_pts']) $rank = $i + 1;
                    $prevPts = $r['total_pts'];
                    $medal = ($rank === 1) ? '🥇' : (($rank === 2) ? '🥈' : (($rank === 3) ? '🥉' : $rank));
                ?>
                <tr<?php echo $rank <= 3 ? ' style="background:#fffde7;"' : ''; ?>>
                    <td class="text-center font-weight-bold"><?php echo $medal; ?></td>
                    <td><strong><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><small><?php echo htmlspecialchars($r['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                    <td><small class="text-info"><?php echo $r['survey_pts'] > 0 ? number_format($r['survey_pts'], 1) : '--'; ?></small></td>
                    <td><small class="text-success"><?php echo $r['training_pts'] > 0 ? number_format($r['training_pts'], 1) : '--'; ?></small></td>
                    <td><small class="text-warning"><?php echo $r['exam_pts'] > 0 ? number_format($r['exam_pts'], 1) : '--'; ?></small></td>
                    <td><small class="text-danger"><?php echo $r['achieve_pts'] > 0 ? number_format($r['achieve_pts'], 1) : '--'; ?></small></td>
                    <td><strong class="text-primary"><?php echo number_format($r['total_pts'], 1); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($activeList === 'monthly'): ?>
<!-- ========== Monthly New Points Ranking ========== -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/pages/leaderboard.php" class="form-inline">
            <input type="hidden" name="list" value="monthly">
            <label class="mr-2 mb-0">选择月份：</label>
            <select name="month" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                <?php foreach ($months as $m): ?>
                <option value="<?php echo $m['m']; ?>" <?php echo $activeMonth === $m['m'] ? 'selected' : ''; ?>>
                    <?php echo $m['m']; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">
        <strong><?php echo $activeMonth; ?> 月度新增排名</strong>
        <small class="text-muted">（仅显示净增为正的员工，Top 50）</small>
    </div>
    <div class="card-body p-0">
        <?php if (empty($monthlyRankings)): ?>
        <div class="p-3 text-muted">该月暂无新增积分记录</div>
        <?php else: ?>
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th style="width:50px;">#</th>
                    <th>员工</th>
                    <th>部门</th>
                    <th style="width:80px;">获得</th>
                    <th style="width:80px;">兑换</th>
                    <th style="width:80px;">净增</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyRankings as $i => $r):
                    $rank = $i + 1;
                    $medal = ($rank === 1) ? '🥇' : (($rank === 2) ? '🥈' : (($rank === 3) ? '🥉' : $rank));
                ?>
                <tr<?php echo $rank <= 3 ? ' style="background:#fffde7;"' : ''; ?>>
                    <td class="text-center font-weight-bold"><?php echo $medal; ?></td>
                    <td><strong><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><small><?php echo htmlspecialchars($r['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                    <td class="text-success">+<?php echo number_format($r['earned'], 1); ?></td>
                    <td class="text-danger">-<?php echo number_format($r['spent'], 1); ?></td>
                    <td><strong class="text-primary"><?php echo number_format($r['net'], 1); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($activeList === 'dept'): ?>
<!-- ========== Department Average Ranking ========== -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/pages/leaderboard.php" class="form-inline flex-wrap">
            <input type="hidden" name="list" value="dept">
            <label class="mr-2 mb-0">时间范围：</label>
            <input type="date" name="from" class="form-control form-control-sm mr-2" style="width:140px;"
                   value="<?php echo $dateFrom; ?>">
            <span class="mr-2">至</span>
            <input type="date" name="to" class="form-control form-control-sm mr-2" style="width:140px;"
                   value="<?php echo $dateTo; ?>">
            <button type="submit" class="btn btn-sm btn-primary">查询</button>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">
        <strong>部门人均积分排名</strong>
        <small class="text-muted">时间段：<?php echo $dateFrom; ?> ~ <?php echo $dateTo; ?></small>
    </div>
    <div class="card-body p-0">
        <?php if (empty($deptRankings)): ?>
        <div class="p-3 text-muted">暂无数据</div>
        <?php else: ?>
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th style="width:50px;">#</th>
                    <th>部门</th>
                    <th style="width:60px;">人数</th>
                    <th style="width:80px;">总积分</th>
                    <th style="width:80px;">人均</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deptRankings as $i => $r):
                    $rank = $i + 1;
                    $medal = ($rank === 1) ? '🥇' : (($rank === 2) ? '🥈' : (($rank === 3) ? '🥉' : $rank));
                ?>
                <tr<?php echo $rank <= 3 ? ' style="background:#fffde7;"' : ''; ?>>
                    <td class="text-center font-weight-bold"><?php echo $medal; ?></td>
                    <td><strong><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td class="text-muted"><?php echo $r['emp_count']; ?>人</td>
                    <td><?php echo number_format($r['total_pts'], 1); ?></td>
                    <td><strong class="text-primary"><?php echo number_format($r['avg_pts'], 1); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
renderLayout('排行榜', 'rank', $content);
