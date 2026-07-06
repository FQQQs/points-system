<?php
/**
 * 苍井寿司 AI 积分管理系统 — 积分管理中心
 *
 * Tab 1: 调查积分 — 部门筛选 + 多选员工 + 描述 → 批量发放 0.5 分/人
 * Tab 2: 培训管理 — 新建培训 + 多选参与员工 → 直接发放积分
 * Tab 3: 考核积分 — 部门筛选 + 多选员工 + 等级 → 批量发放（自动跳过已通过同等级）
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

// ========================
// POST Handler
// ========================
$error   = null;
$success = null;
$activeTab = $_GET['tab'] ?? 'survey';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- SURVEY: Batch issue survey points ---
    if ($action === 'survey') {
        $employeeIds = $_POST['employee_ids'] ?? [];
        $description = trim($_POST['description'] ?? '');

        if (empty($employeeIds)) {
            $error = '请至少选择一名员工';
        } elseif ($description === '') {
            $error = '请填写调研说明';
        } else {
            $db->beginTransaction();
            try {
                $count = 0;
                $stmt = $db->prepare(
                    "INSERT INTO points_log (employee_id, type, points, description, operator)
                     VALUES (:eid, 'survey', 0.5, :desc, 'FDE工程师')"
                );
                foreach ($employeeIds as $eid) {
                    $stmt->execute([':eid' => intval($eid), ':desc' => $description]);
                    $count++;
                }
                $db->commit();
                $success = "调查积分发放完成！共 {$count} 人，每人 0.5 分";
            } catch (Exception $e) {
                $db->rollBack();
                $error = '发放失败：' . $e->getMessage();
            }
        }
        $activeTab = 'survey';
    }

    // --- TRAINING: Create training + record attendance + issue points ---
    elseif ($action === 'training') {
        $name        = trim($_POST['name'] ?? '');
        $date        = trim($_POST['train_date'] ?? '');
        $duration    = floatval($_POST['duration'] ?? 1);
        $employeeIds = $_POST['employee_ids'] ?? [];

        if ($name === '') {
            $error = '请输入培训名称';
        } elseif ($date === '') {
            $error = '请选择培训日期';
        } elseif ($duration < 0.5 || $duration > 4.0) {
            $error = '培训时长需在 0.5 ~ 4 小时之间';
        } elseif (empty($employeeIds)) {
            $error = '请至少选择一名参与员工';
        } else {
            $db->beginTransaction();
            try {
                // Insert training
                $tStmt = $db->prepare(
                    'INSERT INTO training_activities (name, train_date, duration)
                     VALUES (:name, :date, :duration)'
                );
                $tStmt->execute([':name' => $name, ':date' => $date, ':duration' => $duration]);
                $trainingId = $db->lastInsertId();

                // Record attendance + issue points
                $attStmt = $db->prepare(
                    'INSERT OR IGNORE INTO training_attendance (training_id, employee_id) VALUES (:tid, :eid)'
                );
                $ptsStmt = $db->prepare(
                    "INSERT INTO points_log (employee_id, type, points, description, operator)
                     VALUES (:eid, 'training', :pts, :desc, 'FDE工程师')"
                );

                $count = 0;
                foreach ($employeeIds as $eid) {
                    $eid = intval($eid);
                    $attStmt->execute([':tid' => $trainingId, ':eid' => $eid]);
                    $ptsStmt->execute([
                        ':eid'  => $eid,
                        ':pts'  => $duration,
                        ':desc' => '参加培训：' . $name,
                    ]);
                    $count++;
                }

                $db->commit();
                $success = "培训活动已创建！共 {$count} 人参与，每人获得 {$duration} 培训积分";
            } catch (Exception $e) {
                $db->rollBack();
                $error = '操作失败：' . $e->getMessage();
            }
        }
        $activeTab = 'training';
    }

    // --- EXAM: Batch record exam passes ---
    elseif ($action === 'exam') {
        $employeeIds = $_POST['employee_ids'] ?? [];
        $examLevel   = trim($_POST['exam_level'] ?? '');

        $validLevels = ['L1', 'L2', 'L3', 'L4'];
        $levelPoints = ['L1' => 4, 'L2' => 8, 'L3' => 16, 'L4' => 32];
        $levelOrder  = ['L0' => 0, 'L1' => 1, 'L2' => 2, 'L3' => 3, 'L4' => 4];

        if (empty($employeeIds)) {
            $error = '请至少选择一名员工';
        } elseif (!in_array($examLevel, $validLevels)) {
            $error = '无效的考试等级';
        } else {
            $db->beginTransaction();
            try {
                $pts   = $levelPoints[$examLevel];
                $added = 0;
                $skipped = 0;

                foreach ($employeeIds as $eid) {
                    $eid = intval($eid);

                    // Check duplicate
                    $check = $db->prepare(
                        'SELECT COUNT(*) FROM exam_records WHERE employee_id = :eid AND exam_level = :lvl'
                    );
                    $check->execute([':eid' => $eid, ':lvl' => $examLevel]);
                    if ($check->fetchColumn() > 0) {
                        $skipped++;
                        continue;
                    }

                    // Insert exam record
                    $db->prepare('INSERT INTO exam_records (employee_id, exam_level) VALUES (:eid, :lvl)')
                       ->execute([':eid' => $eid, ':lvl' => $examLevel]);

                    // Issue points
                    $db->prepare(
                        "INSERT INTO points_log (employee_id, type, points, description, operator)
                         VALUES (:eid, 'exam', :pts, :desc, 'FDE工程师')"
                    )->execute([
                        ':eid'  => $eid,
                        ':pts'  => $pts,
                        ':desc' => "通过 {$examLevel} 考试",
                    ]);

                    // Update AI level if higher
                    $emp = $db->prepare('SELECT ai_level FROM employees WHERE id = :id');
                    $emp->execute([':id' => $eid]);
                    $current = $emp->fetchColumn();
                    if ($levelOrder[$examLevel] > $levelOrder[$current]) {
                        $db->prepare("UPDATE employees SET ai_level = :lvl, updated_at = datetime('now','localtime') WHERE id = :id")
                           ->execute([':lvl' => $examLevel, ':id' => $eid]);
                    }

                    $added++;
                }

                $db->commit();

                $msg = "考核登记完成：成功发放 {$added} 人（每人 {$pts} 分）";
                if ($skipped > 0) {
                    $msg .= "，{$skipped} 人已通过 {$examLevel} 自动跳过";
                }
                $success = $msg;
            } catch (Exception $e) {
                $db->rollBack();
                $error = '操作失败：' . $e->getMessage();
            }
        }
        $activeTab = 'exam';
    }
}

// ========================
// Data Queries
// ========================

// All departments (for filter dropdown)
$allDepts = $db->query('SELECT id, name FROM departments ORDER BY id')->fetchAll();

// All active employees grouped by department
$empByDept = [];
$empList = $db->query(
    "SELECT e.id, e.name, d.name AS dept_name, d.id AS dept_id
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     WHERE e.status = '在职'
     ORDER BY d.id, e.id"
)->fetchAll();
foreach ($empList as $e) {
    $empByDept[$e['dept_id']][] = $e;
}
$allEmployees = $empList; // flat list for convenience

// Recent survey logs
$surveyLogs = $db->query(
    "SELECT pl.id, e.name AS emp_name, d.name AS dept_name, pl.points, pl.description, pl.created_at
     FROM points_log pl
     JOIN employees e ON pl.employee_id = e.id
     JOIN departments d ON e.department_id = d.id
     WHERE pl.type = 'survey'
     ORDER BY pl.created_at DESC
     LIMIT 50"
)->fetchAll();

// Training activities list
$trainings = $db->query(
    'SELECT ta.id, ta.name, ta.train_date, ta.duration,
            (SELECT COUNT(*) FROM training_attendance WHERE training_id = ta.id) AS attendee_count
     FROM training_activities ta
     ORDER BY ta.train_date DESC, ta.id DESC
     LIMIT 20'
)->fetchAll();

// Training attendance detail (for each training's attendees)
$trainingAttendees = [];
foreach ($trainings as $t) {
    $attStmt = $db->prepare(
        'SELECT e.name, d.name AS dept_name
         FROM training_attendance ta
         JOIN employees e ON ta.employee_id = e.id
         JOIN departments d ON e.department_id = d.id
         WHERE ta.training_id = :tid
         ORDER BY e.name'
    );
    $attStmt->execute([':tid' => $t['id']]);
    $trainingAttendees[$t['id']] = $attStmt->fetchAll();
}

// Exam records list
$examRecords = $db->query(
    "SELECT er.id, e.name AS emp_name, d.name AS dept_name, er.exam_level, er.created_at
     FROM exam_records er
     JOIN employees e ON er.employee_id = e.id
     JOIN departments d ON e.department_id = d.id
     ORDER BY er.created_at DESC
     LIMIT 50"
)->fetchAll();

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>积分管理</h2>
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

<!-- ========================
     Tabs Navigation
     ======================== -->
<ul class="nav nav-tabs mb-4" id="pointsTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'survey' ? 'active' : ''; ?>"
           href="/pages/points.php?tab=survey">调查积分</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'training' ? 'active' : ''; ?>"
           href="/pages/points.php?tab=training">培训管理</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'exam' ? 'active' : ''; ?>"
           href="/pages/points.php?tab=exam">考核积分</a>
    </li>
</ul>

<script>
// Initialize dispatch table BEFORE any initHierSelector call
window._hierSelectors = {};
function selectAllHier(scope) {
    var fn = window._hierSelectors[scope];
    if (fn) fn(true);
}
function deselectAllHier(scope) {
    var fn = window._hierSelectors[scope];
    if (fn) fn(false);
}

/**
 * Initialize a hierarchical department+employee checkbox selector.
 * MUST be defined before the tab-specific <script> blocks that call it.
 */
function initHierSelector(listSelector, scope, counterSelector) {
    var $list = $(listSelector);

    function updateDeptCheck($deptBlock) {
        var $deptCheck = $deptBlock.find('.dept-check');
        var $emps      = $deptBlock.find('.emp-check');
        var total      = $emps.length;
        var checked    = $emps.filter(':checked').length;

        if (checked === 0) {
            $deptCheck.prop('checked', false).prop('indeterminate', false);
        } else if (checked === total) {
            $deptCheck.prop('checked', true).prop('indeterminate', false);
        } else {
            $deptCheck.prop('checked', false).prop('indeterminate', true);
        }
    }

    function updateCount() {
        var n = $list.find('.emp-check:checked').length;
        $(counterSelector).text('已选 ' + n + ' 人');
    }

    // Department checkbox -> toggle all its employees + expand/collapse sublist
    $list.on('change', '.dept-check', function() {
        var $block = $(this).closest('.dept-block');
        var checked = this.checked;
        $block.find('.emp-check').prop('checked', checked);
        if (checked) {
            $block.find('.dept-toggle').text('▼');
            $block.find('.emp-sublist').slideDown(150);
        } else {
            $block.find('.dept-toggle').text('▶');
            $block.find('.emp-sublist').slideUp(150);
        }
        updateCount();
    });

    // Employee checkbox -> update parent department
    $list.on('change', '.emp-check', function() {
        var $block = $(this).closest('.dept-block');
        updateDeptCheck($block);
        updateCount();
    });

    // Toggle button click -> expand/collapse sublist ONLY, no selection change
    $list.on('click', '.dept-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $block = $(this).closest('.dept-block');
        var $sublist = $block.find('.emp-sublist');
        var $toggle = $(this);
        if ($sublist.is(':visible')) {
            $toggle.text('▶');
            $sublist.slideUp(150);
        } else {
            $toggle.text('▼');
            $sublist.slideDown(150);
        }
    });

    // Register selectAll/deselectAll for this scope
    window._hierSelectors[scope] = function(checked) {
        $list.find('.dept-block').each(function() {
            var $block = $(this);
            $block.find('.dept-check').prop('checked', checked).prop('indeterminate', false);
            $block.find('.emp-check').prop('checked', checked);
            if (checked) {
                $block.find('.dept-toggle').text('▼');
                $block.find('.emp-sublist').slideDown(150);
            } else {
                $block.find('.dept-toggle').text('▶');
                $block.find('.emp-sublist').slideUp(150);
            }
        });
        updateCount();
    };

    // Initialize
    $list.find('.dept-block').each(function() { updateDeptCheck($(this)); });
    updateCount();
}
</script>

<!-- ========================
     Tab 1: Survey Points
     ======================== -->
<?php if ($activeTab === 'survey'): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header"><strong>发放调查积分</strong></div>
            <div class="card-body">
                <form method="POST" action="/pages/points.php?tab=survey">
                    <input type="hidden" name="action" value="survey">

                    <!-- Hierarchical Department + Employee Selector -->
                    <div class="form-group">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="mb-0">选择员工 <span class="text-danger">*</span>
                                <small class="text-muted" id="surveySelectedCount">已选 0 人</small>
                            </label>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllHier('survey')">全选</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary ml-1" onclick="deselectAllHier('survey')">取消</button>
                            </div>
                        </div>
                        <div id="surveyEmpList" class="hierarchical-list" style="max-height:280px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px;padding:0;">
                            <?php foreach ($empByDept as $deptId => $emps): ?>
                            <div class="dept-block" data-dept="<?php echo $deptId; ?>" data-scope="survey">
                                <div class="dept-check-label">
                                    <input type="checkbox" class="dept-check survey-dept"
                                           data-dept="<?php echo $deptId; ?>" data-scope="survey">
                                    <strong><?php echo htmlspecialchars($emps[0]['dept_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small class="text-muted">（<?php echo count($emps); ?>人）</small>
                                    <span class="dept-toggle">▶</span>
                                </div>
                                <div class="emp-sublist">
                                    <?php foreach ($emps as $emp): ?>
                                    <label class="emp-check-label">
                                        <input type="checkbox" class="emp-check survey-emp" name="employee_ids[]"
                                               value="<?php echo $emp['id']; ?>"
                                               data-dept="<?php echo $deptId; ?>" data-scope="survey">
                                        <?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="surveyDesc">调研说明 <span class="text-danger">*</span></label>
                        <textarea name="description" id="surveyDesc" class="form-control" rows="2"
                                  placeholder="例：配合完成「门店排班系统」需求调研" required></textarea>
                    </div>

                    <div class="alert alert-info py-2 mb-3">
                        <small>发放分值：<strong>0.5 积分/人</strong>（固定）</small>
                    </div>

                    <button type="submit" class="btn btn-primary">批量发放调查积分</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><strong>发放记录（最近50条）</strong></div>
            <div class="card-body p-0">
                <?php if (empty($surveyLogs)): ?>
                <div class="p-3 text-muted">暂无调查积分记录</div>
                <?php else: ?>
                <div style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>员工</th><th>部门</th><th>分值</th><th>说明</th><th>时间</th></tr></thead>
                    <tbody>
                        <?php foreach ($surveyLogs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['emp_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($log['dept_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge badge-primary"><?php echo $log['points']; ?></span></td>
                            <td><small><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td><small><?php echo $log['created_at']; ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Hierarchical selectors: shared logic for all three tabs
initHierSelector('#surveyEmpList', 'survey', '#surveySelectedCount');
</script>

<!-- ========================
     Tab 2: Training Management
     ======================== -->
<?php elseif ($activeTab === 'training'): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header"><strong>新建培训活动 + 发放积分</strong></div>
            <div class="card-body">
                <form method="POST" action="/pages/points.php?tab=training" id="trainingForm">
                    <input type="hidden" name="action" value="training">

                    <div class="form-group">
                        <label for="trainingName">培训名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="trainingName" class="form-control"
                               placeholder="例：飞书多维表格入门培训" required maxlength="200">
                    </div>

                    <div class="form-row">
                        <div class="col">
                            <div class="form-group">
                                <label for="trainingDate">培训日期 <span class="text-danger">*</span></label>
                                <input type="date" name="train_date" id="trainingDate" class="form-control"
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-group">
                                <label for="trainingDuration">培训时长 <span class="text-danger">*</span></label>
                                <select name="duration" id="trainingDuration" class="form-control" required>
                                    <?php for ($d = 0.5; $d <= 4.0; $d += 0.5): ?>
                                    <option value="<?php echo $d; ?>">
                                        <?php echo rtrim(rtrim(number_format($d, 1), '0'), '.'); ?> 小时（<?php echo rtrim(rtrim(number_format($d, 1), '0'), '.'); ?> 积分）
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <!-- Hierarchical Department + Employee Selector -->
                    <div class="form-group">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="mb-0">参与员工 <span class="text-danger">*</span>
                                <small class="text-muted" id="trainingSelectedCount">已选 0 人</small>
                            </label>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllHier('training')">全选</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary ml-1" onclick="deselectAllHier('training')">取消</button>
                            </div>
                        </div>
                        <div id="trainingEmpList" class="hierarchical-list" style="max-height:280px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px;padding:0;">
                            <?php foreach ($empByDept as $deptId => $emps): ?>
                            <div class="dept-block" data-dept="<?php echo $deptId; ?>" data-scope="training">
                                <div class="dept-check-label">
                                    <input type="checkbox" class="dept-check train-dept"
                                           data-dept="<?php echo $deptId; ?>" data-scope="training">
                                    <strong><?php echo htmlspecialchars($emps[0]['dept_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small class="text-muted">（<?php echo count($emps); ?>人）</small>
                                    <span class="dept-toggle">▶</span>
                                </div>
                                <div class="emp-sublist">
                                    <?php foreach ($emps as $emp): ?>
                                    <label class="emp-check-label">
                                        <input type="checkbox" class="emp-check train-emp" name="employee_ids[]"
                                               value="<?php echo $emp['id']; ?>"
                                               data-dept="<?php echo $deptId; ?>" data-scope="training">
                                        <?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">创建培训并发放积分</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><strong>培训活动列表（最近20场）</strong></div>
            <div class="card-body p-0">
                <?php if (empty($trainings)): ?>
                <div class="p-3 text-muted">暂无培训活动</div>
                <?php else: ?>
                <div style="max-height:500px;overflow-y:auto;">
                <?php foreach ($trainings as $t): ?>
                <div class="border-bottom p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="text-muted small">
                                <?php echo $t['train_date']; ?> &nbsp;|&nbsp;
                                <?php echo rtrim(rtrim(number_format($t['duration'], 1), '0'), '.'); ?> 小时 &nbsp;|&nbsp;
                                <span class="badge badge-info"><?php echo $t['attendee_count']; ?> 人参与</span>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($trainingAttendees[$t['id']])): ?>
                    <div class="mt-1 small text-muted">
                        参与人员：
                        <?php
                        $names = array_map(function($a) {
                            return htmlspecialchars($a['name'], ENT_QUOTES, 'UTF-8') . '(' . htmlspecialchars($a['dept_name'], ENT_QUOTES, 'UTF-8') . ')';
                        }, $trainingAttendees[$t['id']]);
                        echo implode('、', $names);
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
initHierSelector('#trainingEmpList', 'training', '#trainingSelectedCount');
</script>

<!-- ========================
     Tab 3: Exam Management
     ======================== -->
<?php elseif ($activeTab === 'exam'): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header"><strong>登记考核通过</strong></div>
            <div class="card-body">
                <form method="POST" action="/pages/points.php?tab=exam">
                    <input type="hidden" name="action" value="exam">

                    <div class="form-group">
                        <label for="examLevel">考试等级 <span class="text-danger">*</span></label>
                        <select name="exam_level" id="examLevel" class="form-control" required>
                            <option value="L1">L1 — 4 积分</option>
                            <option value="L2">L2 — 8 积分</option>
                            <option value="L3">L3 — 16 积分</option>
                            <option value="L4">L4 — 32 积分</option>
                        </select>
                    </div>

                    <hr>
                    <!-- Hierarchical Department + Employee Selector -->
                    <div class="form-group">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="mb-0">选择员工 <span class="text-danger">*</span>
                                <small class="text-muted" id="examSelectedCount">已选 0 人</small>
                            </label>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllHier('exam')">全选</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary ml-1" onclick="deselectAllHier('exam')">取消</button>
                            </div>
                        </div>
                        <div id="examEmpList" class="hierarchical-list" style="max-height:280px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px;padding:0;">
                            <?php foreach ($empByDept as $deptId => $emps): ?>
                            <div class="dept-block" data-dept="<?php echo $deptId; ?>" data-scope="exam">
                                <div class="dept-check-label">
                                    <input type="checkbox" class="dept-check exam-dept"
                                           data-dept="<?php echo $deptId; ?>" data-scope="exam">
                                    <strong><?php echo htmlspecialchars($emps[0]['dept_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small class="text-muted">（<?php echo count($emps); ?>人）</small>
                                    <span class="dept-toggle">▶</span>
                                </div>
                                <div class="emp-sublist">
                                    <?php foreach ($emps as $emp): ?>
                                    <label class="emp-check-label">
                                        <input type="checkbox" class="emp-check exam-emp" name="employee_ids[]"
                                               value="<?php echo $emp['id']; ?>"
                                               data-dept="<?php echo $deptId; ?>" data-scope="exam">
                                        <?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 mb-3">
                        <small>
                            分值规则：L1=4分 / L2=8分 / L3=16分 / L4=32分<br>
                            同一员工同一等级仅发放一次，重复将自动跳过
                        </small>
                    </div>

                    <button type="submit" class="btn btn-primary">批量登记通过并发放积分</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><strong>考核通过记录（最近50条）</strong></div>
            <div class="card-body p-0">
                <?php if (empty($examRecords)): ?>
                <div class="p-3 text-muted">暂无考核记录</div>
                <?php else: ?>
                <div style="max-height:480px;overflow-y:auto;">
                <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>员工</th><th>部门</th><th>等级</th><th>积分</th><th>通过时间</th></tr></thead>
                    <tbody>
                        <?php
                        $levelPts = ['L1' => 4, 'L2' => 8, 'L3' => 16, 'L4' => 32];
                        $badgeMap = ['L1'=>'info','L2'=>'success','L3'=>'warning','L4'=>'danger'];
                        foreach ($examRecords as $rec):
                            $pts = $levelPts[$rec['exam_level']] ?? 0;
                            $badge = $badgeMap[$rec['exam_level']] ?? 'secondary';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($rec['emp_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($rec['dept_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $rec['exam_level']; ?></span></td>
                            <td><strong>+<?php echo $pts; ?></strong></td>
                            <td><small><?php echo $rec['created_at']; ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
initHierSelector('#examEmpList', 'exam', '#examSelectedCount');
</script>

<?php endif; ?>

<!-- ========================
     Shared Hierarchical Selector CSS + JS
     ======================== -->
<style>
.hierarchical-list { background: #fff; }
.dept-block { border-bottom: 1px solid var(--color-border-light); }
.dept-block:last-child { border-bottom: none; }
.dept-check-label {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 14px; margin: 0;
    background: #fafaf8; font-size: 13px;
    user-select: none; cursor: pointer;
    transition: background 0.15s;
}
.dept-check-label:hover { background: #f0ece5; }
.dept-check-label input[type="checkbox"] { margin: 0; cursor: pointer; }
.dept-toggle {
    margin-left: auto;
    cursor: pointer;
    font-size: 10px;
    color: var(--color-text-muted);
    padding: 2px 8px;
    line-height: 1;
    transition: color 0.15s;
    font-weight: 700;
}
.dept-toggle:hover { color: var(--color-gold); }
.emp-sublist {
    padding: 2px 0 2px 28px;
    display: none;
}
.emp-check-label {
    display: flex; align-items: center; gap: 6px;
    padding: 4px 14px; margin: 0; cursor: pointer;
    font-size: 13px; user-select: none;
    transition: background 0.1s;
    border-radius: 3px;
}
.emp-check-label:hover { background: rgba(201, 169, 110, 0.08); }
.emp-check-label input[type="checkbox"] { margin: 0; }
</style>

<?php
$content = ob_get_clean();
renderLayout('积分管理', 'points', $content);
