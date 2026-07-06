<?php
/**
 * 苍井寿司 AI 积分管理系统 — 报表中心
 *
 * Tab 1: 积分明细查询 (RPT-01) — 默认全部显示，支持搜索/编辑/删除
 * Tab 2: 月度汇总报表 (RPT-02) — 点击姓名跳转明细
 * Tab 3: 月度 AI 之星候选 (RPT-04)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

$activeTab = $_GET['tab'] ?? 'detail';

// ========================
// POST Handler (edit/delete)
// ========================
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit') {
        $logId       = intval($_POST['log_id'] ?? 0);
        $points      = floatval($_POST['points'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $logType     = $_POST['log_type'] ?? '';

        $validTypes = ['survey', 'training', 'exam', 'achievement', 'exchange'];
        if (!in_array($logType, $validTypes)) {
            $error = '无效的积分类型';
        } elseif ($points == 0 || ($points > 0 ? $points < 0.5 : $points > -0.5)) {
            $error = '分值不能为 0';
        } elseif ($description === '') {
            $error = '描述不能为空';
        } else {
            $stmt = $db->prepare('UPDATE points_log SET points = :pts, description = :desc, type = :type WHERE id = :id');
            $stmt->execute([':pts' => $points, ':desc' => $description, ':type' => $logType, ':id' => $logId]);
            if ($stmt->rowCount() > 0) $success = '记录已更新'; else $error = '记录不存在';
        }
    }

    elseif ($action === 'delete') {
        $logId = intval($_POST['log_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM points_log WHERE id = :id');
        $stmt->execute([':id' => $logId]);
        if ($stmt->rowCount() > 0) $success = '记录已删除'; else $error = '记录不存在';
    }
}

// ========================
// Data for form controls
// ========================

$employees = $db->query(
    "SELECT e.id, e.name, d.name AS dept_name
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     WHERE e.status = '在职'
     ORDER BY d.id, e.name"
)->fetchAll();

// ========================
// Tab 1: Detail Query
// ========================
$detailEmployee = intval($_GET['emp'] ?? 0);
$detailType     = $_GET['dtype'] ?? 'all';
$detailFrom     = $_GET['dfrom'] ?? date('Y-m-01');
$detailTo       = $_GET['dto']   ?? date('Y-m-d');
$detailSearch   = trim($_GET['dsearch'] ?? '');

$detailRecords = [];
$detailStats   = ['earned' => 0, 'spent' => 0, 'total' => 0];
$detailEmpName = '';
$dWhere = [];
$dParams = [];

if ($detailEmployee > 0) {
    $dWhere[] = 'pl.employee_id = :eid';
    $dParams[':eid'] = $detailEmployee;

    $en = $db->prepare('SELECT name FROM employees WHERE id = :id');
    $en->execute([':id' => $detailEmployee]);
    $detailEmpName = $en->fetchColumn() ?: '';
}

// Always show records — employee filter is optional now
if ($detailType !== 'all') {
    $dWhere[] = 'pl.type = :dtype';
    $dParams[':dtype'] = $detailType;
}
if ($detailFrom !== '') {
    $dWhere[] = 'pl.created_at >= :dfrom';
    $dParams[':dfrom'] = $detailFrom . ' 00:00:00';
}
if ($detailTo !== '') {
    $dWhere[] = 'pl.created_at <= :dto';
    $dParams[':dto'] = $detailTo . ' 23:59:59';
}
if ($detailSearch !== '') {
    $dWhere[] = '(e.name LIKE :search OR pl.description LIKE :search2)';
    $dParams[':search']  = '%' . $detailSearch . '%';
    $dParams[':search2'] = '%' . $detailSearch . '%';
}

$whereClause = $dWhere ? 'WHERE ' . implode(' AND ', $dWhere) : '';

$dSql = "SELECT pl.*, e.name AS emp_name, d.name AS dept_name
         FROM points_log pl
         JOIN employees e ON pl.employee_id = e.id
         JOIN departments d ON e.department_id = d.id
         {$whereClause}
         ORDER BY pl.created_at DESC
         LIMIT 300";

$dStmt = $db->prepare($dSql);
$dStmt->execute($dParams);
$detailRecords = $dStmt->fetchAll();

// Stats
$sWhere = $dWhere; // same conditions
$sClause = $sWhere ? 'WHERE ' . implode(' AND ', $sWhere) : '';
$sSql = "SELECT
            COALESCE(SUM(CASE WHEN pl.points > 0 THEN pl.points ELSE 0 END), 0) AS earned,
            COALESCE(SUM(CASE WHEN pl.points < 0 THEN -pl.points ELSE 0 END), 0) AS spent
         FROM points_log pl
         JOIN employees e ON pl.employee_id = e.id
         {$sClause}";
$sStmt = $db->prepare($sSql);
$sStmt->execute($dParams);
$detailStats = $sStmt->fetch();
$detailStats['total'] = $detailStats['earned'] - $detailStats['spent'];

// ========================
// Tab 2: Monthly Summary
// ========================
$summaryMonth = $_GET['smonth'] ?? date('Y-m');
$sFrom = $summaryMonth . '-01';
$sTo   = date('Y-m-t', strtotime($sFrom));

$monthlySummary = $db->prepare(
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
     ORDER BY net DESC"
);
$monthlySummary->execute([':dfrom' => $sFrom . ' 00:00:00', ':dto' => $sTo . ' 23:59:59']);
$summaryRows = $monthlySummary->fetchAll();

$summaryTotalEarned = array_sum(array_column($summaryRows, 'earned'));
$summaryTotalSpent  = array_sum(array_column($summaryRows, 'spent'));

// Available months
$availMonths = $db->query(
    "SELECT DISTINCT strftime('%Y-%m', created_at) AS m
     FROM points_log
     UNION SELECT strftime('%Y-%m', 'now')
     ORDER BY m DESC LIMIT 12"
)->fetchAll();

// ========================
// Tab 3: AI Star Candidates
// ========================
$starMonth = $_GET['smonth2'] ?? date('Y-m');
$starFrom  = $starMonth . '-01';
$starTo    = date('Y-m-t', strtotime($starFrom));

$starCandidates = $db->prepare(
    "SELECT e.id, e.name, d.name AS dept_name, e.ai_level,
            COALESCE(SUM(pl.points), 0) AS month_points,
            (SELECT COUNT(*) FROM achievement_applications aa
             WHERE aa.employee_id = e.id AND aa.status = 'approved'
               AND aa.reviewed_at >= :achfrom AND aa.reviewed_at <= :achto) AS ach_count
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     LEFT JOIN points_log pl ON pl.employee_id = e.id
         AND pl.points > 0
         AND pl.created_at >= :dfrom AND pl.created_at <= :dto
     WHERE e.status = '在职'
     GROUP BY e.id
     HAVING month_points >= 10 AND ach_count >= 2
     ORDER BY month_points DESC"
);
$starCandidates->execute([
    ':dfrom'   => $starFrom . ' 00:00:00',
    ':dto'     => $starTo . ' 23:59:59',
    ':achfrom' => $starFrom . ' 00:00:00',
    ':achto'   => $starTo . ' 23:59:59',
]);
$stars = $starCandidates->fetchAll();

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>报表中心</h2>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4" id="reportTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'detail' ? 'active' : ''; ?>"
           href="?tab=detail">积分明细查询</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'summary' ? 'active' : ''; ?>"
           href="?tab=summary">月度汇总报表</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'stars' ? 'active' : ''; ?>"
           href="?tab=stars">月度 AI 之星候选</a>
    </li>
</ul>

<!-- ========================
     Tab 1: Detail Query
     ======================== -->
<?php if ($activeTab === 'detail'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/pages/reports.php" class="form-inline flex-wrap">
            <input type="hidden" name="tab" value="detail">
            <div class="form-group mr-2 mb-1">
                <select name="emp" class="form-control form-control-sm" style="width:180px;">
                    <option value="0">全部员工</option>
                    <?php
                    $curDept = '';
                    foreach ($employees as $emp):
                        if ($emp['dept_name'] !== $curDept):
                            if ($curDept !== '') echo '</optgroup>';
                            $curDept = $emp['dept_name'];
                            echo '<optgroup label="' . htmlspecialchars($curDept) . '">';
                        endif;
                    ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $detailEmployee === $emp['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($emp['name']); ?>
                    </option>
                    <?php endforeach;
                    if ($curDept !== '') echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <input type="text" name="dsearch" class="form-control form-control-sm" style="width:140px;"
                       placeholder="搜索姓名/描述" value="<?php echo htmlspecialchars($detailSearch, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group mr-2 mb-1">
                <select name="dtype" class="form-control form-control-sm" style="width:100px;">
                    <option value="all" <?php echo $detailType === 'all' ? 'selected' : ''; ?>>全部类型</option>
                    <option value="survey" <?php echo $detailType === 'survey' ? 'selected' : ''; ?>>调查</option>
                    <option value="training" <?php echo $detailType === 'training' ? 'selected' : ''; ?>>培训</option>
                    <option value="exam" <?php echo $detailType === 'exam' ? 'selected' : ''; ?>>考核</option>
                    <option value="achievement" <?php echo $detailType === 'achievement' ? 'selected' : ''; ?>>成就</option>
                    <option value="exchange" <?php echo $detailType === 'exchange' ? 'selected' : ''; ?>>兑换</option>
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <input type="date" name="dfrom" class="form-control form-control-sm" style="width:135px;"
                       value="<?php echo $detailFrom; ?>">
            </div>
            <div class="form-group mr-2 mb-1">
                <input type="date" name="dto" class="form-control form-control-sm" style="width:135px;"
                       value="<?php echo $detailTo; ?>">
            </div>
            <button type="submit" class="btn btn-sm btn-primary mb-1 mr-1">查询</button>
            <a href="?tab=detail" class="btn btn-sm btn-outline-secondary mb-1">重置</a>
        </form>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-success text-white text-center py-2">
            <div class="small">获得积分</div>
            <div class="h5 mb-0">+<?php echo number_format($detailStats['earned'], 1); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-danger text-white text-center py-2">
            <div class="small">兑换消耗</div>
            <div class="h5 mb-0">-<?php echo number_format($detailStats['spent'], 1); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-primary text-white text-center py-2">
            <div class="small">净增</div>
            <div class="h5 mb-0"><?php echo number_format($detailStats['total'], 1); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-info text-white text-center py-2">
            <div class="small">记录数</div>
            <div class="h5 mb-0"><?php echo count($detailRecords); ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <strong><?php echo $detailEmpName ? htmlspecialchars($detailEmpName) . ' ' : ''; ?>积分明细</strong>
        <small class="text-muted ml-2">（最近300条）</small>
    </div>
    <div class="card-body p-0">
        <?php if (empty($detailRecords)): ?>
        <div class="p-3 text-muted">该条件下无积分记录</div>
        <?php else: ?>
        <div style="max-height:500px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th style="min-width:60px;">员工</th>
                    <th style="min-width:50px;">类型</th>
                    <th style="min-width:50px;">分值</th>
                    <th>描述</th>
                    <th style="min-width:130px;">时间</th>
                    <th style="min-width:110px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $badgeColors = ['survey'=>'info','training'=>'success','exam'=>'warning','achievement'=>'danger','exchange'=>'secondary'];
                $badgeNames  = ['survey'=>'调查','training'=>'培训','exam'=>'考核','achievement'=>'成就','exchange'=>'兑换'];
                foreach ($detailRecords as $r):
                    $badge = $badgeColors[$r['type']] ?? 'secondary';
                    $tName = $badgeNames[$r['type']] ?? $r['type'];
                    $isNeg = $r['points'] < 0;
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($r['emp_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <br><small class="text-muted"><?php echo htmlspecialchars($r['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                    </td>
                    <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $tName; ?></span></td>
                    <td><strong class="<?php echo $isNeg ? 'text-danger' : 'text-success'; ?>">
                        <?php echo $isNeg ? $r['points'] : '+' . $r['points']; ?>
                    </strong></td>
                    <td>
                        <div style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                             title="<?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </td>
                    <td><small><?php echo $r['created_at']; ?></small></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-log"
                                data-toggle="modal" data-target="#editLogModal"
                                data-id="<?php echo $r['id']; ?>"
                                data-type="<?php echo $r['type']; ?>"
                                data-points="<?php echo $r['points']; ?>"
                                data-description="<?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>">
                            编辑
                        </button>
                        <form method="POST" action="/pages/reports.php?<?php echo http_build_query(['tab'=>'detail','emp'=>$detailEmployee,'dtype'=>$detailType,'dsearch'=>$detailSearch,'dfrom'=>$detailFrom,'dto'=>$detailTo]); ?>" style="display:inline;"
                              class="form-delete-log" data-employee="<?php echo htmlspecialchars($r['emp_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="log_id" value="<?php echo $r['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================
     Tab 2: Monthly Summary
     ======================== -->
<?php elseif ($activeTab === 'summary'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/pages/reports.php" class="form-inline">
            <input type="hidden" name="tab" value="summary">
            <label class="mr-2 mb-0">选择月份：</label>
            <select name="smonth" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                <?php foreach ($availMonths as $m): ?>
                <option value="<?php echo $m['m']; ?>" <?php echo $summaryMonth === $m['m'] ? 'selected' : ''; ?>>
                    <?php echo $m['m']; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-success text-white text-center py-2">
            <div class="small">总获得</div>
            <div class="h5 mb-0">+<?php echo number_format($summaryTotalEarned, 1); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-danger text-white text-center py-2">
            <div class="small">总兑换</div>
            <div class="h5 mb-0">-<?php echo number_format($summaryTotalSpent, 1); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-primary text-white text-center py-2">
            <div class="small">净增</div>
            <div class="h5 mb-0"><?php echo number_format($summaryTotalEarned - $summaryTotalSpent, 1); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card bg-info text-white text-center py-2">
            <div class="small">涉及人数</div>
            <div class="h5 mb-0"><?php echo count(array_filter($summaryRows, fn($r) => $r['earned'] > 0 || $r['spent'] > 0)); ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong><?php echo $summaryMonth; ?> 月度积分汇总</strong></div>
    <div class="card-body p-0">
        <?php if (empty($summaryRows)): ?>
        <div class="p-3 text-muted">暂无数据</div>
        <?php else: ?>
        <div style="max-height:550px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th>员工</th>
                    <th>部门</th>
                    <th>获得</th>
                    <th>兑换</th>
                    <th>净增</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $r):
                    if ($r['earned'] == 0 && $r['spent'] == 0) continue;
                ?>
                <tr>
                    <td>
                        <a href="?tab=detail&emp=<?php echo $r['id']; ?>"
                           title="查看 <?php echo htmlspecialchars($r['name']); ?> 的积分明细"
                           style="color:inherit;text-decoration:underline;text-decoration-color:#dee2e6;">
                            <strong><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                    </td>
                    <td><small><?php echo htmlspecialchars($r['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                    <td class="text-success">+<?php echo number_format($r['earned'], 1); ?></td>
                    <td class="text-danger"><?php echo $r['spent'] > 0 ? '-' . number_format($r['spent'], 1) : '--'; ?></td>
                    <td><strong class="text-primary"><?php echo number_format($r['net'], 1); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================
     Tab 3: AI Star Candidates
     ======================== -->
<?php elseif ($activeTab === 'stars'): ?>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/pages/reports.php" class="form-inline">
            <input type="hidden" name="tab" value="stars">
            <label class="mr-2 mb-0">选择月份：</label>
            <select name="smonth2" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                <?php foreach ($availMonths as $m): ?>
                <option value="<?php echo $m['m']; ?>" <?php echo $starMonth === $m['m'] ? 'selected' : ''; ?>>
                    <?php echo $m['m']; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="alert alert-info py-2">
    <small>筛选条件：当月新增积分 ≥ 10 分 且 成就案例 ≥ 2 个</small>
</div>

<div class="card">
    <div class="card-header"><strong><?php echo $starMonth; ?> AI 之星候选</strong>
        <span class="badge badge-warning ml-2"><?php echo count($stars); ?> 人</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($stars)): ?>
        <div class="p-3 text-muted">该月暂无符合条件的 AI 之星候选</div>
        <?php else: ?>
        <div style="max-height:550px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th style="width:40px;">#</th>
                    <th>员工</th>
                    <th>部门</th>
                    <th>AI等级</th>
                    <th>月积分</th>
                    <th>成就数</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stars as $i => $s):
                    $rank = $i + 1;
                    $medal = ($rank === 1) ? '🥇' : (($rank === 2) ? '🥈' : (($rank === 3) ? '🥉' : $rank));
                    $lvlBadge = ['L0'=>'secondary','L1'=>'info','L2'=>'success','L3'=>'warning','L4'=>'danger'];
                    $lvlB = $lvlBadge[$s['ai_level']] ?? 'secondary';
                ?>
                <tr>
                    <td class="text-center font-weight-bold"><?php echo $medal; ?></td>
                    <td><strong><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><small><?php echo htmlspecialchars($s['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                    <td><span class="badge badge-<?php echo $lvlB; ?>"><?php echo $s['ai_level']; ?></span></td>
                    <td><strong class="text-primary"><?php echo number_format($s['month_points'], 1); ?></strong></td>
                    <td><span class="badge badge-success"><?php echo $s['ach_count']; ?> 个</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ========================
     Edit Modal
     ======================== -->
<div class="modal fade" id="editLogModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑积分记录</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="/pages/reports.php?<?php echo http_build_query(['tab'=>'detail','emp'=>$detailEmployee,'dtype'=>$detailType,'dsearch'=>$detailSearch,'dfrom'=>$detailFrom,'dto'=>$detailTo]); ?>">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="log_id" id="editLogId">

                    <div class="form-group">
                        <label for="editLogType">积分类型</label>
                        <select name="log_type" id="editLogType" class="form-control">
                            <option value="survey">调查积分</option>
                            <option value="training">培训积分</option>
                            <option value="exam">考核积分</option>
                            <option value="achievement">成就积分</option>
                            <option value="exchange">积分兑换</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="editLogPoints">分值（负数为扣减）</label>
                        <input type="number" name="points" id="editLogPoints" class="form-control"
                               step="0.5" required>
                    </div>

                    <div class="form-group">
                        <label for="editLogDesc">描述</label>
                        <textarea name="description" id="editLogDesc" class="form-control" rows="2"
                                  maxlength="300" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存修改</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit modal
$('#editLogModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#editLogId').val(btn.data('id'));
    $('#editLogType').val(btn.data('type'));
    $('#editLogPoints').val(btn.data('points'));
    $('#editLogDesc').val(btn.data('description'));
});

// Delete confirmation
$('.form-delete-log').on('submit', function() {
    var emp = $(this).data('employee');
    return confirm('确定要删除「' + emp + '」的这条积分记录吗？');
});
</script>

<?php
$content = ob_get_clean();
renderLayout('报表中心', 'reports', $content);
