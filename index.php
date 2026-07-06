<?php
/**
 * 苍井寿司 AI 积分管理系统 — 员工列表页
 * 
 * 功能：部门筛选、分页列表、添加员工（模态框+POST处理）、编辑员工（模态框）、
 *       删除员工（确认弹窗）、Excel批量导入导出
 */

require_once 'includes/db.php';
require_once 'includes/layout.php';

$db = getDB();

// ========================
// POST Handler — Add / Edit / Delete Employee
// ========================
$error       = null;
$success     = null;
$warning     = null;
$editErrorId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- ADD ---
    if ($action === 'add') {
        $name         = trim($_POST['name'] ?? '');
        $departmentId = $_POST['department_id'] ?? '';
        $phone        = trim($_POST['phone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $aiLevel      = trim($_POST['ai_level'] ?? 'L0');

        // Validate: name
        if ($name === '') {
            $error = '请输入员工姓名';
        } elseif (mb_strlen($name) > 100) {
            $error = '姓名不能超过100个字符';
        }

        // Validate: department_id
        if ($error === null) {
            if ($departmentId === '' || !ctype_digit((string)$departmentId)) {
                $error = '请选择部门';
            } else {
                $departmentId = (int)$departmentId;
                $deptCheck = $db->prepare('SELECT COUNT(*) FROM departments WHERE id = :id');
                $deptCheck->execute([':id' => $departmentId]);
                if ($deptCheck->fetchColumn() == 0) {
                    $error = '所选部门不存在';
                }
            }
        }

        // Validate: ai_level
        if ($error === null) {
            $validLevels = ['L0', 'L1', 'L2', 'L3', 'L4'];
            if (!in_array($aiLevel, $validLevels, true)) {
                $aiLevel = 'L0';
            }
        }

        // Insert if no errors
        if ($error === null) {
            $stmt = $db->prepare(
                'INSERT INTO employees (name, department_id, phone, email, ai_level)
                 VALUES (:name, :department_id, :phone, :email, :ai_level)'
            );
            $stmt->execute([
                ':name'          => $name,
                ':department_id' => $departmentId,
                ':phone'         => $phone,
                ':email'         => $email,
                ':ai_level'      => $aiLevel,
            ]);

            header('Location: index.php?added=1');
            exit;
        }
    }

    // --- EDIT ---
    elseif ($action === 'edit') {
        $employeeId   = $_POST['employee_id'] ?? '';
        $name         = trim($_POST['name'] ?? '');
        $departmentId = $_POST['department_id'] ?? '';
        $phone        = trim($_POST['phone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $aiLevel      = trim($_POST['ai_level'] ?? 'L0');

        // Validate: employee_id
        if ($employeeId === '' || !ctype_digit((string)$employeeId)) {
            $error = '无效的员工ID';
            $editErrorId = 0;
        } else {
            $employeeId = (int)$employeeId;
            $empCheck = $db->prepare('SELECT COUNT(*) FROM employees WHERE id = :id');
            $empCheck->execute([':id' => $employeeId]);
            if ($empCheck->fetchColumn() == 0) {
                $error = '员工不存在';
                $editErrorId = $employeeId;
            }
        }

        // Validate: name
        if ($error === null) {
            if ($name === '') {
                $error = '请输入员工姓名';
                $editErrorId = $employeeId;
            } elseif (mb_strlen($name) > 100) {
                $error = '姓名不能超过100个字符';
                $editErrorId = $employeeId;
            }
        }

        // Validate: department_id
        if ($error === null) {
            if ($departmentId === '' || !ctype_digit((string)$departmentId)) {
                $error = '请选择部门';
                $editErrorId = $employeeId;
            } else {
                $departmentId = (int)$departmentId;
                $deptCheck = $db->prepare('SELECT COUNT(*) FROM departments WHERE id = :id');
                $deptCheck->execute([':id' => $departmentId]);
                if ($deptCheck->fetchColumn() == 0) {
                    $error = '所选部门不存在';
                    $editErrorId = $employeeId;
                }
            }
        }

        // Validate: ai_level
        if ($error === null) {
            $validLevels = ['L0', 'L1', 'L2', 'L3', 'L4'];
            if (!in_array($aiLevel, $validLevels, true)) {
                $aiLevel = 'L0';
            }
        }

        // Update if no errors
        if ($error === null) {
            $stmt = $db->prepare(
                'UPDATE employees
                 SET name = :name, department_id = :department_id, phone = :phone,
                     email = :email, ai_level = :ai_level,
                     updated_at = datetime(\'now\',\'localtime\')
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name'          => $name,
                ':department_id' => $departmentId,
                ':phone'         => $phone,
                ':email'         => $email,
                ':ai_level'      => $aiLevel,
                ':id'            => $employeeId,
            ]);

            header('Location: index.php?updated=1');
            exit;
        }
    }

    // --- DELETE ---
    elseif ($action === 'delete') {
        $employeeId = $_POST['employee_id'] ?? '';

        if ($employeeId === '' || !ctype_digit((string)$employeeId)) {
            header('Location: index.php?error=notfound');
            exit;
        }

        $employeeId = (int)$employeeId;
        $empCheck = $db->prepare('SELECT id FROM employees WHERE id = :id');
        $empCheck->execute([':id' => $employeeId]);
        if ($empCheck->fetchColumn() == 0) {
            header('Location: index.php?error=notfound');
            exit;
        }

        $stmt = $db->prepare('DELETE FROM employees WHERE id = :id');
        $stmt->execute([':id' => $employeeId]);

        header('Location: index.php?deleted=1');
        exit;
    }
}

// ========================
// Success / Error Messages (PRG)
// ========================
if (isset($_GET['added']) && $_GET['added'] == '1') {
    $success = '员工添加成功';
}
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $success = '员工信息已更新';
}
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $success = '员工已删除';
}
if (isset($_GET['imported'])) {
    $n = intval($_GET['imported']);
    $success = "导入完成：成功导入 {$n} 名员工";
}
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'notfound') {
        $warning = '员工不存在，可能已被删除';
    } elseif ($_GET['error'] === 'upload') {
        $warning = '文件上传失败，请重试';
    } elseif ($_GET['error'] === 'empty') {
        $warning = 'Excel 文件为空或格式不正确';
    } elseif ($_GET['error'] === 'import') {
        $warning = '导入失败：' . ($_GET['msg'] ?? '未知错误');
    }
}

// ========================
// Data Queries
// ========================

// All departments for filter dropdown & modals
$deptStmt = $db->query('SELECT id, name FROM departments ORDER BY name');
$departments = $deptStmt->fetchAll();

// Filter
$filterDept  = isset($_GET['department']) ? intval($_GET['department']) : 0;
$currentPage = max(1, intval($_GET['page'] ?? 1));
$perPage     = 20;
$offset      = ($currentPage - 1) * $perPage;

// Count total employees (for pagination)
$countSql = 'SELECT COUNT(*) FROM employees e';
$countParams = [];
if ($filterDept > 0) {
    $countSql .= ' WHERE e.department_id = :dept_id';
    $countParams[':dept_id'] = $filterDept;
}
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

// Cap current page
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $perPage;
}

// Fetch employees
$empSql = 'SELECT e.id, e.name, e.department_id, d.name AS department_name,
                  e.phone, e.email, e.ai_level, e.status
           FROM employees e
           JOIN departments d ON e.department_id = d.id';
$empParams = [];
if ($filterDept > 0) {
    $empSql .= ' WHERE e.department_id = :dept_id';
    $empParams[':dept_id'] = $filterDept;
}
$empSql .= ' ORDER BY e.id DESC LIMIT :limit OFFSET :offset';

$empStmt = $db->prepare($empSql);
foreach ($empParams as $key => $val) {
    $empStmt->bindValue($key, $val, PDO::PARAM_INT);
}
$empStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$empStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$empStmt->execute();
$employees = $empStmt->fetchAll();

// ========================
// Helpers
// ========================

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

function buildPageUrl(int $page, int $dept): string
{
    $params = ['page' => $page];
    if ($dept > 0) {
        $params['department'] = $dept;
    }
    return 'index.php?' . http_build_query($params);
}

function buildDepartmentUrl(int $dept): string
{
    if ($dept > 0) {
        return 'index.php?department=' . $dept;
    }
    return 'index.php';
}

/**
 * Render department <option> elements for a <select> dropdown.
 * Shared by Add and Edit modals to avoid duplicate code.
 */
function renderDepartmentOptions(array $departments, ?int $selectedId = null): string
{
    $html = '<option value="">请选择部门</option>';
    foreach ($departments as $dept) {
        $sel = ($selectedId !== null && $selectedId === (int)$dept['id']) ? ' selected' : '';
        $html .= sprintf(
            '<option value="%d"%s>%s</option>',
            $dept['id'],
            $sel,
            htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8')
        );
    }
    return $html;
}

// ========================
// Build Content
// ========================

ob_start();
?>

<div class="page-header">
    <h2>员工管理</h2>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php endif; ?>

<?php if ($warning): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="form-inline">
        <label for="departmentFilter" class="mr-2">部门筛选：</label>
        <select id="departmentFilter" class="form-control form-control-sm" onchange="filterDepartment(this.value)">
            <option value="">全部部门</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>"
                    <?php echo ($filterDept === (int)$dept['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="btn-group">
        <a href="includes/excel_handler.php?action=template_employee" class="btn btn-outline-secondary btn-sm">下载模板</a>
        <button type="button" class="btn btn-outline-info btn-sm" data-toggle="modal" data-target="#importEmployeeModal">导入Excel</button>
        <a href="includes/excel_handler.php?action=export_employees&department=<?php echo $filterDept; ?>" class="btn btn-outline-success btn-sm">导出Excel</a>
        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addEmployeeModal">添加员工</button>
    </div>
</div>

<!-- Employee Table -->
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>#</th>
                <th>姓名</th>
                <th>部门</th>
                <th>手机号</th>
                <th>AI等级</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($employees)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <p>暂无员工数据</p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                <tr>
                    <td><?php echo $emp['id']; ?></td>
                    <td>
                        <a href="employee.php?id=<?php echo $emp['id']; ?>" class="text-primary">
                            <?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($emp['department_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($emp['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <span class="badge <?php echo aiLevelBadgeClass($emp['ai_level']); ?>">
                            <?php echo htmlspecialchars($emp['ai_level'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $emp['status'] === '在职' ? 'badge-success' : 'badge-secondary'; ?>">
                            <?php echo htmlspecialchars($emp['status'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                    <td>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary btn-edit"
                                data-toggle="modal"
                                data-target="#editEmployeeModal"
                                data-id="<?php echo $emp['id']; ?>"
                                data-name="<?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-dept="<?php echo $emp['department_id']; ?>"
                                data-phone="<?php echo htmlspecialchars($emp['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-email="<?php echo htmlspecialchars($emp['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-level="<?php echo htmlspecialchars($emp['ai_level'], ENT_QUOTES, 'UTF-8'); ?>">
                            编辑
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger btn-delete"
                                data-id="<?php echo $emp['id']; ?>"
                                data-name="<?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>">
                            删除
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalRows > 0): ?>
<div class="pagination-wrapper">
    <div class="pagination-info">
        共 <?php echo $totalRows; ?> 条记录，第 <?php echo $currentPage; ?>/<?php echo $totalPages; ?> 页
    </div>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php if ($currentPage > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo buildPageUrl($currentPage - 1, $filterDept); ?>">上一页</a>
                </li>
            <?php else: ?>
                <li class="page-item disabled">
                    <span class="page-link">上一页</span>
                </li>
            <?php endif; ?>

            <?php
            // Show page numbers with ellipsis
            $startPage = max(1, $currentPage - 2);
            $endPage   = min($totalPages, $currentPage + 2);

            if ($startPage > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo buildPageUrl(1, $filterDept); ?>">1</a>
                </li>
                <?php if ($startPage > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item <?php echo ($p === $currentPage) ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo buildPageUrl($p, $filterDept); ?>"><?php echo $p; ?></a>
                </li>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo buildPageUrl($totalPages, $filterDept); ?>"><?php echo $totalPages; ?></a>
                </li>
            <?php endif; ?>

            <?php if ($currentPage < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="<?php echo buildPageUrl($currentPage + 1, $filterDept); ?>">下一页</a>
                </li>
            <?php else: ?>
                <li class="page-item disabled">
                    <span class="page-link">下一页</span>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

<!-- ========================
     Add Employee Modal
     ======================== -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1" role="dialog" aria-labelledby="addEmployeeModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="index.php" id="addEmployeeForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="addEmployeeModalLabel">添加员工</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">

                    <?php if ($error && $editErrorId === null): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="empName">姓名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="empName" class="form-control"
                               required maxlength="100" placeholder="请输入员工姓名"
                               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="empDept">部门 <span class="text-danger">*</span></label>
                        <select name="department_id" id="empDept" class="form-control" required>
                            <?php
                            $addSelectedDept = isset($_POST['department_id']) ? (int)$_POST['department_id'] : null;
                            echo renderDepartmentOptions($departments, $addSelectedDept);
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="empPhone">手机号</label>
                        <input type="text" name="phone" id="empPhone" class="form-control"
                               maxlength="20" placeholder="请输入手机号"
                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="empEmail">邮箱</label>
                        <input type="email" name="email" id="empEmail" class="form-control"
                               maxlength="100" placeholder="请输入邮箱"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="empAiLevel">AI等级</label>
                        <select name="ai_level" id="empAiLevel" class="form-control">
                            <?php
                            $levels = ['L0' => 'L0 — 未认证', 'L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'L4' => 'L4'];
                            $selectedLevel = isset($_POST['ai_level']) ? $_POST['ai_level'] : 'L0';
                            foreach ($levels as $val => $label):
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo ($selectedLevel === $val) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================
     Edit Employee Modal
     ======================== -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1" role="dialog" aria-labelledby="editEmployeeModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="index.php" id="editEmployeeForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="editEmployeeModalLabel">编辑员工</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="employee_id" id="edit_employee_id">

                    <?php if ($error && $editErrorId !== null): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="edit_name">姓名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control"
                               required maxlength="100" placeholder="请输入员工姓名">
                    </div>

                    <div class="form-group">
                        <label for="edit_department_id">部门 <span class="text-danger">*</span></label>
                        <select name="department_id" id="edit_department_id" class="form-control" required>
                            <?php echo renderDepartmentOptions($departments); ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit_phone">手机号</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control"
                               maxlength="20" placeholder="请输入手机号">
                    </div>

                    <div class="form-group">
                        <label for="edit_email">邮箱</label>
                        <input type="email" name="email" id="edit_email" class="form-control"
                               maxlength="100" placeholder="请输入邮箱">
                    </div>

                    <div class="form-group">
                        <label for="edit_ai_level">AI等级</label>
                        <select name="ai_level" id="edit_ai_level" class="form-control">
                            <?php foreach (['L0' => 'L0 — 未认证', 'L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'L4' => 'L4'] as $val => $label): ?>
                                <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
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

<!-- ========================
     Import Employee Modal
     ======================== -->
<div class="modal fade" id="importEmployeeModal" tabindex="-1" role="dialog" aria-labelledby="importEmployeeModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="includes/excel_handler.php" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="importEmployeeModalLabel">批量导入员工</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="import_employees">
                    <p class="text-muted mb-2"><small>请先下载模板，按模板格式填写后上传。部门列填写部门名称即可自动匹配。</small></p>
                    <a href="includes/excel_handler.php?action=template_employee" class="btn btn-sm btn-outline-secondary mb-3">下载员工导入模板</a>
                    <div class="form-group">
                        <label for="importFile">选择Excel文件 (.xlsx)</label>
                        <input type="file" name="file" id="importFile" class="form-control-file" accept=".xlsx,.xls" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">导入</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Department filter auto-submit
function filterDepartment(val) {
    if (val === '') {
        window.location.href = 'index.php';
    } else {
        window.location.href = 'index.php?department=' + val;
    }
}

// Clear add modal form on shown
$('#addEmployeeModal').on('shown.bs.modal', function () {
    $('#addEmployeeForm')[0].reset();
    $('#addEmployeeForm .alert').remove();
});

// Pre-fill edit modal with employee data from clicked row
$('#editEmployeeModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget);
    $('#edit_employee_id').val(button.data('id'));
    $('#edit_name').val(button.data('name'));
    $('#edit_department_id').val(button.data('dept'));
    $('#edit_phone').val(button.data('phone'));
    $('#edit_email').val(button.data('email'));
    $('#edit_ai_level').val(button.data('level'));
});

// Delete confirmation with irreversibility warning
$(function() {
    $('.btn-delete').on('click', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        if (confirm('确定要删除员工「' + name + '」吗？此操作不可撤销。')) {
            var form = $('<form method="POST" style="display:none">');
            form.append('<input type="hidden" name="action" value="delete">');
            form.append('<input type="hidden" name="employee_id" value="' + id + '">');
            $('body').append(form);
            form.submit();
        }
    });
});

// Auto-show add modal on add validation error
<?php if ($error && $editErrorId === null): ?>
$(function () {
    $('#addEmployeeModal').modal('show');
});
<?php endif; ?>

// Auto-show edit modal on edit validation error
<?php if ($editErrorId !== null): ?>
$(function () {
    $('#editEmployeeModal').modal('show');
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();

// Render layout
renderLayout('员工管理', 'employees', $content);
