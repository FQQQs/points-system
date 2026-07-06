<?php
/**
 * 苍井寿司 AI 积分管理系统 — 积分发放记录
 *
 * 查看/搜索/编辑/删除所有积分发放记录
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

// ========================
// POST Handler
// ========================
$error   = null;
$success = null;

// Search filters
$searchName = trim($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? 'all';
$dateFrom   = $_GET['date_from'] ?? '';
$dateTo     = $_GET['date_to'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- EDIT ---
    if ($action === 'edit') {
        $logId       = intval($_POST['log_id'] ?? 0);
        $points      = floatval($_POST['points'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $logType     = $_POST['log_type'] ?? '';

        $validTypes = ['survey', 'training', 'exam', 'achievement'];
        if (!in_array($logType, $validTypes)) {
            $error = '无效的积分类型';
        } elseif ($points <= 0) {
            $error = '分值必须大于 0';
        } elseif ($description === '') {
            $error = '描述不能为空';
        } else {
            $stmt = $db->prepare(
                'UPDATE points_log SET points = :pts, description = :desc, type = :type WHERE id = :id'
            );
            $stmt->execute([
                ':pts'  => $points,
                ':desc' => $description,
                ':type' => $logType,
                ':id'   => $logId,
            ]);
            if ($stmt->rowCount() > 0) {
                $success = '记录已更新';
            } else {
                $error = '记录不存在';
            }
        }
    }

    // --- DELETE ---
    elseif ($action === 'delete') {
        $logId = intval($_POST['log_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM points_log WHERE id = :id');
        $stmt->execute([':id' => $logId]);
        if ($stmt->rowCount() > 0) {
            $success = '记录已删除';
        } else {
            $error = '记录不存在';
        }
    }
}

// ========================
// Data Query
// ========================
$where   = [];
$params  = [];

if ($searchName !== '') {
    $where[] = "e.name LIKE :name";
    $params[':name'] = '%' . $searchName . '%';
}
if ($typeFilter !== 'all') {
    $where[] = "pl.type = :type";
    $params[':type'] = $typeFilter;
}
if ($dateFrom !== '') {
    $where[] = "pl.created_at >= :dfrom";
    $params[':dfrom'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = "pl.created_at <= :dto";
    $params[':dto'] = $dateTo . ' 23:59:59';
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT pl.id, pl.type, pl.points, pl.description, pl.operator, pl.created_at,
               e.name AS emp_name, d.name AS dept_name
        FROM points_log pl
        JOIN employees e ON pl.employee_id = e.id
        JOIN departments d ON e.department_id = d.id
        {$whereClause}
        ORDER BY pl.created_at DESC
        LIMIT 200";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Type summary stats
$statsSql = "SELECT type, COUNT(*) AS cnt, SUM(points) AS total
             FROM points_log pl
             JOIN employees e ON pl.employee_id = e.id
             {$whereClause}
             GROUP BY type";
$statsStmt = $db->prepare($statsSql);
$statsStmt->execute($params);
$stats = $statsStmt->fetchAll();
$statsMap = [];
foreach ($stats as $s) {
    $statsMap[$s['type']] = $s;
}

$totalPoints = array_sum(array_column($stats, 'total'));
$totalCount  = array_sum(array_column($stats, 'cnt'));

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>积分发放记录</h2>
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

<!-- Stats Cards -->
<div class="row mb-3">
    <div class="col-md-2 col-6 mb-2">
        <div class="card bg-primary text-white text-center py-2">
            <div class="small">总记录</div>
            <div class="h5 mb-0"><?php echo $totalCount; ?></div>
        </div>
    </div>
    <div class="col-md-2 col-6 mb-2">
        <div class="card bg-success text-white text-center py-2">
            <div class="small">总积分</div>
            <div class="h5 mb-0"><?php echo number_format($totalPoints, 1); ?></div>
        </div>
    </div>
    <?php
    $typeLabels = [
        'survey'      => ['label' => '调查', 'color' => 'info'],
        'training'    => ['label' => '培训', 'color' => 'success'],
        'exam'        => ['label' => '考核', 'color' => 'warning'],
        'achievement' => ['label' => '成就', 'color' => 'danger'],
    ];
    foreach ($typeLabels as $t => $info):
        $cnt = $statsMap[$t]['cnt'] ?? 0;
        $pts = $statsMap[$t]['total'] ?? 0;
    ?>
    <div class="col-md-2 col-6 mb-2">
        <div class="card border-<?php echo $info['color']; ?> text-center py-2">
            <div class="small text-muted"><?php echo $info['label']; ?></div>
            <div class="h6 mb-0"><?php echo $cnt; ?> 条</div>
            <div class="small text-<?php echo $info['color']; ?>">+<?php echo number_format($pts, 1); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Search Bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/pages/points_records.php" class="form-inline flex-wrap">
            <div class="form-group mr-2 mb-1">
                <input type="text" name="search" class="form-control form-control-sm" style="width:160px;"
                       placeholder="员工姓名" value="<?php echo htmlspecialchars($searchName, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group mr-2 mb-1">
                <select name="type" class="form-control form-control-sm">
                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>全部类型</option>
                    <option value="survey" <?php echo $typeFilter === 'survey' ? 'selected' : ''; ?>>调查积分</option>
                    <option value="training" <?php echo $typeFilter === 'training' ? 'selected' : ''; ?>>培训积分</option>
                    <option value="exam" <?php echo $typeFilter === 'exam' ? 'selected' : ''; ?>>考核积分</option>
                    <option value="achievement" <?php echo $typeFilter === 'achievement' ? 'selected' : ''; ?>>成就积分</option>
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <input type="date" name="date_from" class="form-control form-control-sm" style="width:140px;"
                       value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>" placeholder="开始日期">
            </div>
            <div class="form-group mr-2 mb-1">
                <input type="date" name="date_to" class="form-control form-control-sm" style="width:140px;"
                       value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="结束日期">
            </div>
            <button type="submit" class="btn btn-sm btn-primary mb-1 mr-1">搜索</button>
            <a href="/pages/points_records.php" class="btn btn-sm btn-outline-secondary mb-1">重置</a>
        </form>
    </div>
</div>

<!-- Records Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>发放记录 <small class="text-muted">（最近200条）</small></strong>
        <small class="text-muted"><?php echo count($records); ?> 条结果</small>
    </div>
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
        <div class="p-3 text-muted">暂无匹配的积分记录</div>
        <?php else: ?>
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
        <table class="table table-sm table-striped mb-0">
            <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                <tr>
                    <th style="min-width:80px;">员工</th>
                    <th style="min-width:60px;">类型</th>
                    <th style="min-width:50px;">分值</th>
                    <th>描述</th>
                    <th style="min-width:80px;">操作人</th>
                    <th style="min-width:130px;">时间</th>
                    <th style="min-width:100px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $badgeColors = [
                    'survey'      => 'info',
                    'training'    => 'success',
                    'exam'        => 'warning',
                    'achievement' => 'danger',
                ];
                $badgeNames = [
                    'survey'      => '调查',
                    'training'    => '培训',
                    'exam'        => '考核',
                    'achievement' => '成就',
                ];
                foreach ($records as $rec):
                    $badge  = $badgeColors[$rec['type']] ?? 'secondary';
                    $tName  = $badgeNames[$rec['type']] ?? $rec['type'];
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($rec['emp_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <br><small class="text-muted"><?php echo htmlspecialchars($rec['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                    </td>
                    <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $tName; ?></span></td>
                    <td><strong><?php echo $rec['points']; ?></strong></td>
                    <td>
                        <div style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                             title="<?php echo htmlspecialchars($rec['description'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($rec['description'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </td>
                    <td><small><?php echo htmlspecialchars($rec['operator'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                    <td><small><?php echo $rec['created_at']; ?></small></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-log"
                                data-toggle="modal" data-target="#editLogModal"
                                data-id="<?php echo $rec['id']; ?>"
                                data-type="<?php echo $rec['type']; ?>"
                                data-points="<?php echo $rec['points']; ?>"
                                data-description="<?php echo htmlspecialchars($rec['description'], ENT_QUOTES, 'UTF-8'); ?>">
                            编辑
                        </button>
                        <form method="POST" action="/pages/points_records.php?<?php echo http_build_query(['search'=>$searchName,'type'=>$typeFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo]); ?>" style="display:inline;"
                              class="form-delete-log" data-employee="<?php echo htmlspecialchars($rec['emp_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="log_id" value="<?php echo $rec['id']; ?>">
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
     Edit Modal
     ======================== -->
<div class="modal fade" id="editLogModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑积分记录</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="/pages/points_records.php?<?php echo http_build_query(['search'=>$searchName,'type'=>$typeFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo]); ?>">
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
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="editLogPoints">分值</label>
                        <input type="number" name="points" id="editLogPoints" class="form-control"
                               min="0.5" max="100" step="0.5" required>
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
// Edit modal: populate data
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
    return confirm('确定要删除「' + emp + '」的这条积分记录吗？\n\n此操作不可撤销！');
});
</script>

<?php
$content = ob_get_clean();
renderLayout('积分发放记录', 'records', $content);
