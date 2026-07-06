<?php
/**
 * 苍井寿司 AI 积分管理系统 — 员工详情页
 * 
 * 路由：employee.php?id={id}
 * 功能：显示员工基本信息（姓名、部门、手机号、邮箱、AI等级、状态、录入时间、最后更新）
 *        + 4个积分占位面板（调查/培训/考核/成就）
 */

require_once 'includes/db.php';
require_once 'includes/layout.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Map AI level to Bootstrap badge CSS class (same scheme as index.php list page).
 */
if (!function_exists('aiLevelBadgeClass')) {
    function aiLevelBadgeClass(string $level): string
    {
        $map = [
            'L0' => 'badge-ai-l0',
            'L1' => 'badge-ai-l1',
            'L2' => 'badge-ai-l2',
            'L3' => 'badge-ai-l3',
            'L4' => 'badge-ai-l4',
        ];
        return $map[$level] ?? 'badge-ai-l0';
    }
}

/**
 * Render an error page when the employee ID is invalid or not found.
 */
if (!function_exists('renderError')) {
    function renderError(string $title, string $message): void
{
    ob_start();
    ?>
    <a href="index.php" class="detail-back-link">&larr; 返回员工列表</a>
    <div class="alert alert-warning">
        <h5><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h5>
        <p class="mb-0"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php
    $content = ob_get_clean();
    renderLayout('员工详情', 'employees', $content);
}
} // end if (!function_exists('renderError'))

// ---------------------------------------------------------------------------
// Route & Validation
// ---------------------------------------------------------------------------

$db = getDB();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    renderError('无效的员工ID', '请从员工列表页面选择一名员工查看详情。');
    return;
}

// Query employee with department JOIN (prepared statement — mitigates T-03-01)
$stmt = $db->prepare(
    'SELECT e.*, d.name AS department_name
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     WHERE e.id = :id'
);
$stmt->execute([':id' => $id]);
$employee = $stmt->fetch();

if (!$employee) {
    renderError('员工不存在', '该员工记录可能已被删除或ID无效。');
    return;
}

// ---------------------------------------------------------------------------
// Data extraction (all values escaped on output — mitigates T-03-02)
// ---------------------------------------------------------------------------

$name           = $employee['name'];
$departmentName = $employee['department_name'];
$phone          = $employee['phone'] ?? '';
$email          = $employee['email'] ?? '';
$aiLevel        = $employee['ai_level'];
$status         = $employee['status'] ?? '在职';
$createdAt      = $employee['created_at'];
$updatedAt      = $employee['updated_at'];

// ---------------------------------------------------------------------------
// Query Real Points Data (Phase 2)
// ---------------------------------------------------------------------------

// Points breakdown by type
$pointSummary = $db->prepare(
    "SELECT type, SUM(points) AS total
     FROM points_log
     WHERE employee_id = :id
     GROUP BY type"
);
$pointSummary->execute([':id' => $id]);
$pointsByType = [];
while ($row = $pointSummary->fetch()) {
    $pointsByType[$row['type']] = floatval($row['total']);
}

$surveyPts     = $pointsByType['survey'] ?? 0;
$trainingPts   = $pointsByType['training'] ?? 0;
$examPts       = $pointsByType['exam'] ?? 0;
$achievementPts = $pointsByType['achievement'] ?? 0;
$totalPts       = $surveyPts + $trainingPts + $examPts + $achievementPts;

// Exam records for this employee
$examRecs = $db->prepare(
    'SELECT exam_level, passed_date FROM exam_records WHERE employee_id = :id ORDER BY exam_level'
);
$examRecs->execute([':id' => $id]);
$passedExams = $examRecs->fetchAll();

// Recent points log (last 20)
$recentLogs = $db->prepare(
    "SELECT type, points, description, created_at
     FROM points_log
     WHERE employee_id = :id
     ORDER BY created_at DESC
     LIMIT 20"
);
$recentLogs->execute([':id' => $id]);
$recentLogs = $recentLogs->fetchAll();

// ---------------------------------------------------------------------------
// Render Detail Page
// ---------------------------------------------------------------------------

ob_start();
?>
<a href="index.php" class="detail-back-link">&larr; 返回员工列表</a>

<h4><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h4>

<!-- Basic Info Card -->
<div class="card mb-4">
    <div class="card-header"><strong>基本信息</strong></div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tbody>
                <tr>
                    <td class="text-muted" style="width: 120px;">姓名</td>
                    <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td class="text-muted">部门</td>
                    <td><?php echo htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td class="text-muted">手机号</td>
                    <td><?php echo $phone ? htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">未填写</span>'; ?></td>
                </tr>
                <tr>
                    <td class="text-muted">邮箱</td>
                    <td><?php echo $email ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">未填写</span>'; ?></td>
                </tr>
                <tr>
                    <td class="text-muted">AI等级</td>
                    <td>
                        <span class="badge <?php echo aiLevelBadgeClass($aiLevel); ?> detail-badge">
                            <?php echo htmlspecialchars($aiLevel, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">状态</td>
                    <td>
                        <span class="badge <?php echo $status === '在职' ? 'badge-success' : 'badge-secondary'; ?> detail-badge">
                            <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">录入时间</td>
                    <td><?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td class="text-muted">最后更新</td>
                    <td><?php echo htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Points Overview — Real data from Phase 2 -->
<h5 class="mt-4 mb-3">积分概览 <span class="badge badge-primary">总计 <?php echo $totalPts; ?> 分</span></h5>
<div class="row">
    <!-- 调查积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel">
            <div class="card-header bg-primary text-white">
                📋 调查积分
            </div>
            <div class="card-body text-center">
                <h2 class="text-primary mb-0"><?php echo $surveyPts; ?></h2>
                <small class="text-muted">累计配合调研次数：<?php echo $surveyPts > 0 ? ($surveyPts / 0.5) : 0; ?> 次</small>
            </div>
        </div>
    </div>
    <!-- 培训积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel">
            <div class="card-header bg-success text-white">
                📚 培训积分
            </div>
            <div class="card-body text-center">
                <h2 class="text-success mb-0"><?php echo $trainingPts; ?></h2>
                <small class="text-muted">参加培训并获得积分</small>
            </div>
        </div>
    </div>
    <!-- 考核积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel">
            <div class="card-header bg-warning text-dark">
                📝 考核积分
            </div>
            <div class="card-body text-center">
                <h2 class="text-warning mb-0"><?php echo $examPts; ?></h2>
                <small class="text-muted">
                    <?php if (!empty($passedExams)): ?>
                    已通过：<?php echo implode(', ', array_column($passedExams, 'exam_level')); ?>
                    <?php else: ?>
                    未参加考试
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    <!-- 成就积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel">
            <div class="card-header bg-danger text-white">
                🏆 成就积分
            </div>
            <div class="card-body text-center">
                <h2 class="text-danger mb-0"><?php echo $achievementPts; ?></h2>
                <small class="text-muted">数据将在后续版本上线</small>
            </div>
        </div>
    </div>
</div>

<!-- Recent Points Log -->
<?php if (!empty($recentLogs)): ?>
<h5 class="mt-4 mb-3">积分明细（最近20条）</h5>
<div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th>类型</th>
                <th>分值</th>
                <th>说明</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $typeLabels = ['survey' => '调查', 'training' => '培训', 'exam' => '考核', 'achievement' => '成就'];
            $typeBadges = ['survey' => 'info', 'training' => 'success', 'exam' => 'warning', 'achievement' => 'danger'];
            foreach ($recentLogs as $log):
                $label = $typeLabels[$log['type']] ?? $log['type'];
                $badge = $typeBadges[$log['type']] ?? 'secondary';
            ?>
            <tr>
                <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $label; ?></span></td>
                <td><strong>+<?php echo $log['points']; ?></strong></td>
                <td><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><small><?php echo $log['created_at']; ?></small></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
$content = ob_get_clean();
renderLayout($name . ' - 员工详情', 'employees', $content);
