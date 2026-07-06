<?php
/**
 * 苍井寿司 AI 积分管理系统 — 部门管理页
 * 
 * 功能：部门列表、添加、编辑、删除、Excel批量导入、Excel导出
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

// ========================
// POST Handler
// ========================
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- ADD ---
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $error = '请输入部门名称';
        } elseif (mb_strlen($name) > 100) {
            $error = '部门名称不能超过100个字符';
        } else {
            // Check duplicate
            $check = $db->prepare('SELECT COUNT(*) FROM departments WHERE name = :name');
            $check->execute([':name' => $name]);
            if ($check->fetchColumn() > 0) {
                $error = '部门名称已存在';
            } else {
                $stmt = $db->prepare('INSERT INTO departments (name) VALUES (:name)');
                $stmt->execute([':name' => $name]);
                header('Location: /pages/department.php?added=1');
                exit;
            }
        }
    }

    // --- EDIT ---
    elseif ($action === 'edit') {
        $id   = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id <= 0) {
            $error = '无效的部门ID';
        } elseif ($name === '') {
            $error = '请输入部门名称';
        } else {
            $check = $db->prepare('SELECT COUNT(*) FROM departments WHERE name = :name AND id != :id');
            $check->execute([':name' => $name, ':id' => $id]);
            if ($check->fetchColumn() > 0) {
                $error = '部门名称已存在';
            } else {
                $stmt = $db->prepare('UPDATE departments SET name = :name WHERE id = :id');
                $stmt->execute([':name' => $name, ':id' => $id]);
                header('Location: /pages/department.php?updated=1');
                exit;
            }
        }
    }

    // --- DELETE ---
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);

        if ($id > 0) {
            // Check if department has employees
            $empCheck = $db->prepare('SELECT COUNT(*) FROM employees WHERE department_id = :id');
            $empCheck->execute([':id' => $id]);
            $empCount = $empCheck->fetchColumn();

            if ($empCount > 0) {
                $error = '该部门下有 ' . $empCount . ' 名员工，无法删除。请先将员工转移至其他部门。';
            } else {
                $stmt = $db->prepare('DELETE FROM departments WHERE id = :id');
                $stmt->execute([':id' => $id]);
                header('Location: /pages/department.php?deleted=1');
                exit;
            }
        }
    }
}

// ========================
// Success Messages
// ========================
if (isset($_GET['added']))   $success = '部门添加成功';
if (isset($_GET['updated'])) $success = '部门已更新';
if (isset($_GET['deleted'])) $success = '部门已删除';
if (isset($_GET['imported'])) {
    $n = intval($_GET['imported']);
    $u = intval($_GET['updated'] ?? 0);
    $success = "导入完成：新增 {$n} 个部门" . ($u > 0 ? "，已有 {$u} 个部门名称已存在被跳过" : "");
}
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'upload') $error = '文件上传失败，请重试';
    if ($_GET['error'] === 'empty')  $error = 'Excel 文件为空或格式不正确';
    if ($_GET['error'] === 'import') $error = '导入失败：' . ($_GET['msg'] ?? '未知错误');
}

// ========================
// Data Query
// ========================
$deptStmt = $db->query(
    'SELECT d.id, d.name,
            COUNT(e.id) AS employee_count
     FROM departments d
     LEFT JOIN employees e ON e.department_id = d.id
     GROUP BY d.id
     ORDER BY d.id'
);
$departments = $deptStmt->fetchAll();

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>部门管理</h2>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php endif; ?>

<!-- Toolbar -->
<div class="filter-bar">
    <div class="form-inline">
        <span class="text-muted">共 <?php echo count($departments); ?> 个部门</span>
    </div>
    <div class="btn-group">
        <a href="/includes/excel_handler.php?action=template_department" class="btn btn-outline-secondary btn-sm">下载模板</a>
        <button type="button" class="btn btn-outline-info btn-sm" data-toggle="modal" data-target="#importModal">导入Excel</button>
        <a href="/includes/excel_handler.php?action=export_departments" class="btn btn-outline-success btn-sm">导出Excel</a>
        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">添加部门</button>
    </div>
</div>

<!-- Department Table -->
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>ID</th>
                <th>部门名称</th>
                <th>员工人数</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($departments)): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state"><p>暂无部门数据</p></div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($departments as $dept): ?>
                <tr>
                    <td><?php echo $dept['id']; ?></td>
                    <td><?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <span class="badge badge-info"><?php echo $dept['employee_count']; ?> 人</span>
                    </td>
                    <td>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary btn-edit"
                                data-toggle="modal"
                                data-target="#editModal"
                                data-id="<?php echo $dept['id']; ?>"
                                data-name="<?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?>">
                            编辑
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger btn-delete"
                                data-id="<?php echo $dept['id']; ?>"
                                data-name="<?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-count="<?php echo $dept['employee_count']; ?>">
                            删除
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ========================
     Add Modal
     ======================== -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="/pages/department.php" id="addForm">
                <div class="modal-header">
                    <h5 class="modal-title">添加部门</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="addName">部门名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="addName" class="form-control" required maxlength="100"
                               placeholder="请输入部门名称" autofocus>
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
     Edit Modal
     ======================== -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="/pages/department.php" id="editForm">
                <div class="modal-header">
                    <h5 class="modal-title">编辑部门</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editId">
                    <div class="form-group">
                        <label for="editName">部门名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required maxlength="100">
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
     Import Modal
     ======================== -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="/includes/excel_handler.php" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">批量导入部门</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="import_departments">
                    <p class="text-muted mb-2"><small>请下载模板，按格式填写部门名称后上传。已存在的部门名称将自动跳过。</small></p>
                    <a href="/includes/excel_handler.php?action=template_department" class="btn btn-sm btn-outline-secondary mb-3">下载部门导入模板</a>
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
// Clear add modal
$('#addModal').on('shown.bs.modal', function () {
    $('#addName').val('').focus();
});

// Pre-fill edit modal
$('#editModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#editId').val(btn.data('id'));
    $('#editName').val(btn.data('name'));
});

// Delete confirmation
$('.btn-delete').on('click', function() {
    var id    = $(this).data('id');
    var name  = $(this).data('name');
    var count = $(this).data('count');

    var msg = '确定要删除部门「' + name + '」吗？';
    if (count > 0) {
        msg += '\n\n该部门下有 ' + count + ' 名员工，无法删除。请先将员工转移至其他部门。';
        alert(msg);
        return;
    }
    msg += '此操作不可撤销。';

    if (confirm(msg)) {
        var form = $('<form method="POST" style="display:none">');
        form.append('<input type="hidden" name="action" value="delete">');
        form.append('<input type="hidden" name="id" value="' + id + '">');
        $('body').append(form);
        form.submit();
    }
});
</script>

<?php
$content = ob_get_clean();
renderLayout('部门管理', 'departments', $content);
