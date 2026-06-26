<?php
/**
 * 苍井寿司 AI 积分管理系统 — 员工详情页
 * 
 * 路由：employee.php?id={id}
 * 功能：显示员工基本信息（姓名、部门、入职日期、AI等级、录入时间、最后更新）
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

/**
 * Render an error page when the employee ID is invalid or not found.
 */
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
$joinDate       = $employee['join_date'];
$aiLevel        = $employee['ai_level'];
$createdAt      = $employee['created_at'];
$updatedAt      = $employee['updated_at'];

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
                    <td class="text-muted">入职日期</td>
                    <td><?php echo htmlspecialchars($joinDate, ENT_QUOTES, 'UTF-8'); ?></td>
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

<!-- Points Overview — 4 Placeholder Panels -->
<h5 class="mt-4 mb-3">积分概览</h5>
<div class="row">
    <!-- 调查积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel" id="panel-survey-points">
            <div class="card-header bg-primary text-white" style="border-left: 3px solid #2980b9;">
                📋 调查积分
            </div>
            <div class="card-body">数据将在后续版本上线</div>
            <div class="card-footer text-muted small">Phase 2+ 功能</div>
        </div>
    </div>
    <!-- 培训积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel" id="panel-training-points">
            <div class="card-header bg-success text-white" style="border-left: 3px solid #219a52;">
                📚 培训积分
            </div>
            <div class="card-body">数据将在后续版本上线</div>
            <div class="card-footer text-muted small">Phase 2+ 功能</div>
        </div>
    </div>
    <!-- 考核积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel" id="panel-exam-points">
            <div class="card-header bg-warning text-dark" style="border-left: 3px solid #d68910;">
                📝 考核积分
            </div>
            <div class="card-body">数据将在后续版本上线</div>
            <div class="card-footer text-muted small">Phase 2+ 功能</div>
        </div>
    </div>
    <!-- 成就积分 -->
    <div class="col-md-6 mb-3">
        <div class="card point-panel" id="panel-achievement-points">
            <div class="card-header bg-danger text-white" style="border-left: 3px solid #c0392b;">
                🏆 成就积分
            </div>
            <div class="card-body">数据将在后续版本上线</div>
            <div class="card-footer text-muted small">Phase 2+ 功能</div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
renderLayout($name . ' - 员工详情', 'employees', $content);
