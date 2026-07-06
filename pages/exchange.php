<?php
/**
 * 苍井寿司 AI 积分管理系统 — 积分兑换
 *
 * FDE 录入兑换申请 → 校验余额 → 扣减积分
 * EXC-01 ~ EXC-04
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

// AJAX endpoint for balance query (must be before any output)
if (isset($_GET['fetch_balance'])) {
    $eid = intval($_GET['fetch_balance']);
    $bal = $db->prepare('SELECT COALESCE(SUM(points), 0) FROM points_log WHERE employee_id = :eid');
    $bal->execute([':eid' => $eid]);
    $balance = floatval($bal->fetchColumn());
    header('Content-Type: application/json');
    echo json_encode(['balance' => number_format($balance, 1)]);
    exit;
}

// ========================
// POST Handler
// ========================
$error   = null;
$success = null;

$histType   = $_GET['htype'] ?? 'all';
$histSearch = trim($_GET['hsearch'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'exchange') {
        $employeeId   = intval($_POST['employee_id'] ?? 0);
        $exchangeType = $_POST['exchange_type'] ?? '';
        $pointsCost   = floatval($_POST['points_cost'] ?? 0);
        $description  = trim($_POST['description'] ?? '');

        if ($employeeId <= 0) {
            $error = '请选择一名员工';
        } elseif (!in_array($exchangeType, ['holiday', 'goods'])) {
            $error = '请选择兑换类型';
        } elseif ($pointsCost <= 0) {
            $error = '兑换分值必须大于 0';
        } elseif ($description === '') {
            $error = '请填写兑换说明';
        } else {
            // Calculate employee's current balance
            $bal = $db->prepare('SELECT COALESCE(SUM(points), 0) FROM points_log WHERE employee_id = :eid');
            $bal->execute([':eid' => $employeeId]);
            $balance = floatval($bal->fetchColumn());

            if ($pointsCost > $balance) {
                $error = "积分余额不足！当前余额：{$balance} 分，需要：{$pointsCost} 分";
            } else {
                $db->beginTransaction();
                try {
                    // Insert exchange record
                    $stmt = $db->prepare(
                        'INSERT INTO exchange_records (employee_id, exchange_type, points_cost, description)
                         VALUES (:eid, :type, :cost, :desc)'
                    );
                    $stmt->execute([
                        ':eid'  => $employeeId,
                        ':type' => $exchangeType,
                        ':cost' => $pointsCost,
                        ':desc' => $description,
                    ]);

                    // Deduct points (negative points_log entry)
                    $typeLabel = ($exchangeType === 'holiday') ? '假期兑换' : '实物兑换';
                    $db->prepare(
                        "INSERT INTO points_log (employee_id, type, points, description, operator)
                         VALUES (:eid, 'exchange', :pts, :desc, 'FDE工程师')"
                    )->execute([
                        ':eid'  => $employeeId,
                        ':pts'  => -$pointsCost,
                        ':desc' => $typeLabel . '：' . $description,
                    ]);

                    $db->commit();
                    $newBalance = $balance - $pointsCost;
                    $success = "兑换成功！扣除 {$pointsCost} 分，剩余余额：{$newBalance} 分";
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '操作失败：' . $e->getMessage();
                }
            }
        }
    }
}

// ========================
// Data Queries
// ========================

// Active employees (for form)
$employees = $db->query(
    "SELECT e.id, e.name, d.name AS dept_name
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     WHERE e.status = '在职'
     ORDER BY d.id, e.name"
)->fetchAll();

// Exchange history
$hWhere = [];
$hParams = [];

if ($histType !== 'all') {
    $hWhere[] = 'er.exchange_type = :htype';
    $hParams[':htype'] = $histType;
}
if ($histSearch !== '') {
    $hWhere[] = 'e.name LIKE :hsearch';
    $hParams[':hsearch'] = '%' . $histSearch . '%';
}

$hWhereClause = $hWhere ? 'WHERE ' . implode(' AND ', $hWhere) : '';

$history = $db->prepare(
    "SELECT er.id, er.exchange_type, er.points_cost, er.description, er.operator, er.created_at,
            e.name AS emp_name, d.name AS dept_name
     FROM exchange_records er
     JOIN employees e ON er.employee_id = e.id
     JOIN departments d ON e.department_id = d.id
     {$hWhereClause}
     ORDER BY er.created_at DESC
     LIMIT 100"
);
$history->execute($hParams);
$exchanges = $history->fetchAll();

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>积分兑换</h2>
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

<div class="row">
    <!-- ========== Left: Exchange Form ========== -->
    <div class="col-md-5">
        <div class="card mb-4">
            <div class="card-header"><strong>录入积分兑换</strong></div>
            <div class="card-body">
                <form method="POST" action="/pages/exchange.php" id="exchangeForm">
                    <input type="hidden" name="action" value="exchange">

                    <div class="form-group" style="position:relative;">
                        <label for="exEmpSearch">选择员工 <span class="text-danger">*</span></label>
                        <input type="text" id="exEmpSearch" class="form-control"
                               placeholder="输入员工姓名搜索..." autocomplete="off" required>
                        <input type="hidden" name="employee_id" id="exEmpId">
                        <div id="exEmpDropdown" class="search-dropdown" style="display:none;"></div>
                    </div>

                    <div class="form-group">
                        <label>当前余额</label>
                        <div id="exBalance" class="form-control-plaintext font-weight-bold text-success">-- 分</div>
                    </div>

                    <div class="form-group">
                        <label for="exType">兑换类型 <span class="text-danger">*</span></label>
                        <select name="exchange_type" id="exType" class="form-control" required>
                            <option value="">-- 请选择 --</option>
                            <option value="holiday">假期</option>
                            <option value="goods">实物</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="exCost">所需积分 <span class="text-danger">*</span></label>
                        <input type="number" name="points_cost" id="exCost" class="form-control"
                               min="0.5" max="999" step="0.5" placeholder="输入所需积分" required>
                    </div>

                    <div class="form-group">
                        <label for="exDesc">兑换说明 <span class="text-danger">*</span></label>
                        <textarea name="description" id="exDesc" class="form-control" rows="2"
                                  placeholder="例：兑换 1 天假期、兑换蓝牙耳机"
                                  maxlength="200" required></textarea>
                    </div>

                    <button type="submit" class="btn btn-warning">确认兑换</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== Right: History List ========== -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <strong>兑换记录</strong>
                <form method="GET" action="/pages/exchange.php" class="form-inline float-right">
                    <select name="htype" class="form-control form-control-sm mr-1" style="width:90px;">
                        <option value="all" <?php echo $histType === 'all' ? 'selected' : ''; ?>>全部</option>
                        <option value="holiday" <?php echo $histType === 'holiday' ? 'selected' : ''; ?>>假期</option>
                        <option value="goods" <?php echo $histType === 'goods' ? 'selected' : ''; ?>>实物</option>
                    </select>
                    <input type="text" name="hsearch" class="form-control form-control-sm mr-1" style="width:100px;"
                           placeholder="员工姓名" value="<?php echo htmlspecialchars($histSearch, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">筛选</button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($exchanges)): ?>
                <div class="p-3 text-muted">暂无兑换记录</div>
                <?php else: ?>
                <div style="max-height:520px;overflow-y:auto;">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>员工</th>
                            <th>类型</th>
                            <th>积分</th>
                            <th>说明</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exchanges as $ex):
                            $isHoliday = $ex['exchange_type'] === 'holiday';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($ex['emp_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($ex['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $isHoliday ? 'info' : 'secondary'; ?>">
                                    <?php echo $isHoliday ? '假期' : '实物'; ?>
                                </span>
                            </td>
                            <td><strong class="text-danger">-<?php echo $ex['points_cost']; ?></strong></td>
                            <td>
                                <div style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                     title="<?php echo htmlspecialchars($ex['description'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($ex['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td>
                            <td><small><?php echo $ex['created_at']; ?></small></td>
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
// Employee search
var allEmps = <?php echo json_encode(array_map(function($e) {
    return ['id' => $e['id'], 'name' => $e['name'], 'dept' => $e['dept_name']];
}, $employees), JSON_UNESCAPED_UNICODE); ?>;

$('#exEmpSearch').on('input focus', function() {
    var q = $.trim($(this).val().toLowerCase());
    var $dd = $('#exEmpDropdown');
    $('#exEmpId').val('');
    $('#exBalance').text('-- 分');
    if (q.length === 0) { $dd.hide().empty(); return; }
    var matches = allEmps.filter(function(e) {
        return e.name.toLowerCase().indexOf(q) !== -1 || e.dept.toLowerCase().indexOf(q) !== -1;
    });
    if (matches.length === 0) { $dd.hide().empty(); return; }
    var html = '';
    matches.slice(0, 10).forEach(function(e) {
        html += '<div class="search-dropdown-item" data-id="' + e.id + '" data-name="' + e.name + ' - ' + e.dept + '">'
              + '<strong>' + e.name + '</strong><small class="text-muted ml-2">' + e.dept + '</small></div>';
    });
    $dd.html(html).show();
}).on('blur', function() {
    setTimeout(function() { $('#exEmpDropdown').hide(); }, 200);
});

$('#exEmpDropdown').on('mousedown', '.search-dropdown-item', function() {
    var $item = $(this);
    var empId = $item.data('id');
    $('#exEmpId').val(empId);
    $('#exEmpSearch').val($item.data('name'));
    $('#exEmpDropdown').hide();
    // Fetch balance
    $.get('/pages/exchange.php?fetch_balance=' + empId, function(data) {
        $('#exBalance').text(data.balance + ' 分');
        if (data.balance < 0) {
            $('#exBalance').removeClass('text-success').addClass('text-danger');
        } else {
            $('#exBalance').removeClass('text-danger').addClass('text-success');
        }
    }, 'json');
});
</script>

<style>
.search-dropdown {
    position: absolute; z-index: 1050; max-height: 240px; overflow-y: auto;
    background: #fff; border: 1px solid var(--color-border); border-top: none;
    border-radius: 0 0 var(--radius-md) var(--radius-md); width: calc(100% - 30px);
    box-shadow: var(--shadow-md);
}
.search-dropdown-item {
    padding: 8px 14px; cursor: pointer; font-size: 13px;
    transition: background 0.1s;
}
.search-dropdown-item:hover { background: rgba(201, 169, 110, 0.08); color: var(--color-navy); }
</style>

<?php
$content = ob_get_clean();
renderLayout('积分兑换', 'exchange', $content);
