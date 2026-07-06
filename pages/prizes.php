<?php
/**
 * 苍井寿司 AI 积分管理系统 — 积分奖品管理
 *
 * 功能：奖品列表、添加、编辑、删除、Excel导入导出、上架/下架
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
        $name        = trim($_POST['name'] ?? '');
        $pointsCost  = floatval($_POST['points_cost'] ?? 0);
        $stock       = trim($_POST['stock'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status      = $_POST['status'] ?? 'active';

        if ($name === '') {
            $error = '请输入奖品名称';
        } elseif ($pointsCost <= 0) {
            $error = '积分额度必须大于 0';
        } else {
            $stockVal = ($stock === '') ? null : intval($stock);
            $stmt = $db->prepare('INSERT INTO prizes (name, points_cost, stock, description, status) VALUES (:n, :p, :s, :d, :st)');
            $stmt->execute([':n' => $name, ':p' => $pointsCost, ':s' => $stockVal, ':d' => $description, ':st' => $status]);
            header('Location: /pages/prizes.php?added=1');
            exit;
        }
    }

    // --- EDIT ---
    elseif ($action === 'edit') {
        $id          = intval($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $pointsCost  = floatval($_POST['points_cost'] ?? 0);
        $stock       = trim($_POST['stock'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status      = $_POST['status'] ?? 'active';

        if ($id <= 0) {
            $error = '无效的奖品ID';
        } elseif ($name === '') {
            $error = '请输入奖品名称';
        } elseif ($pointsCost <= 0) {
            $error = '积分额度必须大于 0';
        } else {
            $stockVal = ($stock === '') ? null : intval($stock);
            $stmt = $db->prepare('UPDATE prizes SET name=:n, points_cost=:p, stock=:s, description=:d, status=:st WHERE id=:id');
            $stmt->execute([':n' => $name, ':p' => $pointsCost, ':s' => $stockVal, ':d' => $description, ':st' => $status, ':id' => $id]);
            header('Location: /pages/prizes.php?updated=1');
            exit;
        }
    }

    // --- DELETE ---
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM prizes WHERE id = :id')->execute([':id' => $id]);
            header('Location: /pages/prizes.php?deleted=1');
            exit;
        }
    }
}

// ========================
// Success Messages
// ========================
if (isset($_GET['added']))   $success = '奖品添加成功';
if (isset($_GET['updated'])) $success = '奖品已更新';
if (isset($_GET['deleted'])) $success = '奖品已删除';
if (isset($_GET['imported'])) {
    $n = intval($_GET['imported']);
    $success = "导入完成：新增 {$n} 个奖品";
}

// ========================
// Data Query
// ========================
$prizes = $db->query('SELECT * FROM prizes ORDER BY status DESC, id DESC')->fetchAll();

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>积分奖品管理</h2>
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

<div class="card mb-3">
    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">
                新增奖品
            </button>
            <button type="button" class="btn btn-success btn-sm ml-1" data-toggle="modal" data-target="#importModal">
                Excel导入
            </button>
        </div>
        <div>
            <a href="/includes/excel_handler.php?action=template_prizes" class="btn btn-outline-secondary btn-sm">下载模板</a>
            <a href="/includes/excel_handler.php?action=export_prizes" class="btn btn-outline-info btn-sm ml-1">导出Excel</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>奖品列表</strong> <small class="text-muted">（<?php echo count($prizes); ?> 个）</small></div>
    <div class="card-body p-0">
        <?php if (empty($prizes)): ?>
        <div class="p-3 text-muted">暂无奖品，请点击"新增奖品"添加</div>
        <?php else: ?>
        <div class="table-responsive" style="max-height:550px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th>奖品名称</th>
                    <th>所需积分</th>
                    <th>库存</th>
                    <th>描述</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($prizes as $p):
                    $isActive = $p['status'] === 'active';
                ?>
                <tr class="<?php echo $isActive ? '' : 'text-muted'; ?>">
                    <td><strong><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><strong class="text-primary"><?php echo number_format($p['points_cost'], 1); ?></strong></td>
                    <td><?php echo $p['stock'] === null ? '<span class="text-muted">不限</span>' : intval($p['stock']); ?></td>
                    <td>
                        <div style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                             title="<?php echo htmlspecialchars($p['description'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($p['description'] ?: '--', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $isActive ? 'success' : 'secondary'; ?>">
                            <?php echo $isActive ? '上架' : '下架'; ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit"
                                data-toggle="modal" data-target="#editModal"
                                data-id="<?php echo $p['id']; ?>"
                                data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-cost="<?php echo $p['points_cost']; ?>"
                                data-stock="<?php echo $p['stock'] ?? ''; ?>"
                                data-description="<?php echo htmlspecialchars($p['description'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-status="<?php echo $p['status']; ?>">
                            编辑
                        </button>
                        <form method="POST" action="/pages/prizes.php" style="display:inline;"
                              onsubmit="return confirm('确定要删除「<?php echo htmlspecialchars(addslashes($p['name']), ENT_QUOTES, 'UTF-8'); ?>」吗？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
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
     Add Modal
     ======================== -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">新增奖品</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="/pages/prizes.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">

                    <div class="form-group">
                        <label for="addName">奖品名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="addName" class="form-control" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="addCost">所需积分 <span class="text-danger">*</span></label>
                        <input type="number" name="points_cost" id="addCost" class="form-control"
                               min="0.5" step="0.5" value="10" required>
                    </div>

                    <div class="form-group">
                        <label for="addStock">库存数量</label>
                        <input type="number" name="stock" id="addStock" class="form-control"
                               min="0" placeholder="留空表示不限">
                        <small class="text-muted">留空则库存不限</small>
                    </div>

                    <div class="form-group">
                        <label for="addDesc">描述</label>
                        <textarea name="description" id="addDesc" class="form-control" rows="2" maxlength="300"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="addStatus">状态</label>
                        <select name="status" id="addStatus" class="form-control">
                            <option value="active">上架</option>
                            <option value="inactive">下架</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================
     Edit Modal
     ======================== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑奖品</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="/pages/prizes.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editId">

                    <div class="form-group">
                        <label for="editName">奖品名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="editCost">所需积分 <span class="text-danger">*</span></label>
                        <input type="number" name="points_cost" id="editCost" class="form-control"
                               min="0.5" step="0.5" required>
                    </div>

                    <div class="form-group">
                        <label for="editStock">库存数量</label>
                        <input type="number" name="stock" id="editStock" class="form-control"
                               min="0" placeholder="留空表示不限">
                        <small class="text-muted">留空则库存不限</small>
                    </div>

                    <div class="form-group">
                        <label for="editDesc">描述</label>
                        <textarea name="description" id="editDesc" class="form-control" rows="2" maxlength="300"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="editStatus">状态</label>
                        <select name="status" id="editStatus" class="form-control">
                            <option value="active">上架</option>
                            <option value="inactive">下架</option>
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
     Import Modal
     ======================== -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Excel导入奖品</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="/includes/excel_handler.php?action=import_prizes" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group">
                        <label>选择Excel文件</label>
                        <div class="custom-file">
                            <input type="file" name="file" class="custom-file-input" id="importFile" accept=".xlsx" required>
                            <label class="custom-file-label" for="importFile" data-browse="浏览">选择.xlsx文件...</label>
                        </div>
                    </div>
                    <div class="alert alert-info py-2 small">
                        请先<a href="/includes/excel_handler.php?action=template_prizes" target="_blank">下载模板</a>，按模板格式填写后导入。<br>
                        表格列：奖品名称、所需积分、库存数量、描述、状态
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-success">上传导入</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit modal: populate data
$('#editModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#editId').val(btn.data('id'));
    $('#editName').val(btn.data('name'));
    $('#editCost').val(btn.data('cost'));
    $('#editStock').val(btn.data('stock'));
    $('#editDesc').val(btn.data('description'));
    $('#editStatus').val(btn.data('status'));
});

// Update custom file input label
$('#importFile').on('change', function() {
    var fileName = $(this).val().split('\\').pop();
    $(this).next('.custom-file-label').text(fileName || '选择.xlsx文件...');
});
</script>

<?php
$content = ob_get_clean();
renderLayout('积分奖品管理', 'prizes', $content);
