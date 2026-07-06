<?php
/**
 * 苍井寿司 AI 积分管理系统 — 成就积分管理
 *
 * FDE 可录入员工成就积分申请 → 三维度评定 → 审核通过/驳回
 * ACH-01 ~ ACH-05
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$db = getDB();

// ========================
// POST Handler
// ========================
$error   = null;
$success = null;
$statusFilter = $_GET['status'] ?? 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- SUBMIT: Create new achievement application ---
    if ($action === 'submit') {
        $employeeId   = intval($_POST['employee_id'] ?? 0);
        $description  = trim($_POST['description'] ?? '');
        $quantitative = trim($_POST['quantitative'] ?? '');

        if ($employeeId <= 0) {
            $error = '请选择一名员工';
        } elseif ($description === '') {
            $error = '请填写成果描述';
        } else {
            // Handle file upload (supports multiple files)
            $attachments    = [];
            $uploadFailed   = false;

            if (isset($_FILES['attachment']) && is_array($_FILES['attachment']['name'])) {
                $uploadDir = __DIR__ . '/../data/uploads/';
                $maxSize   = 100 * 1024 * 1024; // 100MB per file

                foreach ($_FILES['attachment']['name'] as $i => $origName) {
                    if ($_FILES['attachment']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if (empty($origName)) continue;

                    $fileSize = $_FILES['attachment']['size'][$i];
                    if ($fileSize > $maxSize) {
                        $error        = '附件 "' . htmlspecialchars($origName) . '" 大小超过 100MB';
                        $uploadFailed = true;
                        break;
                    }

                    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $safeName = date('YmdHis') . '_' . $i . '_' . substr(md5(uniqid()), 0, 8) . ($ext ? '.' . $ext : '');
                    $destPath = $uploadDir . $safeName;

                    if (!move_uploaded_file($_FILES['attachment']['tmp_name'][$i], $destPath)) {
                        $error        = '附件 "' . htmlspecialchars($origName) . '" 上传失败，请重试';
                        $uploadFailed = true;
                        break;
                    }

                    $attachments[] = ['name' => $origName, 'file' => $safeName];
                }
            }

            $attachmentJson = !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : '';

            if (!$uploadFailed) {
                $stmt = $db->prepare(
                    'INSERT INTO achievement_applications (employee_id, description, quantitative, attachment)
                     VALUES (:eid, :desc, :quant, :att)'
                );
                $stmt->execute([
                    ':eid'   => $employeeId,
                    ':desc'  => $description,
                    ':quant' => $quantitative,
                    ':att'   => $attachmentJson,
                ]);
                $success = '成就积分申请已提交，待审核评定';
            }
        }
    }

    // --- REVIEW: Score dimensions + approve/reject ---
    elseif ($action === 'review') {
        $appId         = intval($_POST['app_id'] ?? 0);
        $reviewAction  = $_POST['review_action'] ?? ''; // 'approve' or 'reject'
        $reviewComment = trim($_POST['review_comment'] ?? '');
        $dimSaving     = intval($_POST['dim_saving'] ?? 0);
        $dimImpact     = intval($_POST['dim_impact'] ?? 0);
        $dimReplicate  = intval($_POST['dim_replicate'] ?? 0);
        $totalScore    = floatval($_POST['total_score'] ?? 0);

        // Validate dimensions
        if ($dimSaving < 0 || $dimSaving > 3)  $dimSaving = 0;
        if ($dimImpact < 0 || $dimImpact > 4)  $dimImpact = 0;
        if ($dimReplicate < 0 || $dimReplicate > 3) $dimReplicate = 0;

        // Auto-calculate if not manually adjusted
        $autoTotal = $dimSaving + $dimImpact + $dimReplicate;
        if ($totalScore <= 0) {
            $totalScore = $autoTotal;
        }
        if ($totalScore < 2) $totalScore = 2;
        if ($totalScore > 10) $totalScore = 10;

        // Verify application exists and is pending
        $check = $db->prepare('SELECT * FROM achievement_applications WHERE id = :id AND status = \'pending\'');
        $check->execute([':id' => $appId]);
        $app = $check->fetch();

        if (!$app) {
            $error = '申请不存在或已审核';
        } elseif (!in_array($reviewAction, ['approve', 'reject'])) {
            $error = '请选择审核操作（通过或驳回）';
        } elseif ($reviewAction === 'approve' && $totalScore < 2) {
            $error = '通过时综合分值不能低于 2 分';
        } else {
            $db->beginTransaction();
            try {
                // Update application
                $upd = $db->prepare(
                    'UPDATE achievement_applications
                     SET dim_saving = :ds, dim_impact = :di, dim_replicate = :dr,
                         total_score = :ts, status = :st, review_comment = :rc,
                         reviewed_at = datetime(\'now\',\'localtime\')
                     WHERE id = :id'
                );
                $newStatus = ($reviewAction === 'approve') ? 'approved' : 'rejected';
                $upd->execute([
                    ':ds' => $dimSaving,
                    ':di' => $dimImpact,
                    ':dr' => $dimReplicate,
                    ':ts' => $totalScore,
                    ':st' => $newStatus,
                    ':rc' => $reviewComment,
                    ':id' => $appId,
                ]);

                // If approved, issue points
                if ($reviewAction === 'approve') {
                    $ptsStmt = $db->prepare(
                        "INSERT INTO points_log (employee_id, type, points, description, operator)
                         VALUES (:eid, 'achievement', :pts, :desc, 'FDE工程师')"
                    );
                    $ptsStmt->execute([
                        ':eid'  => $app['employee_id'],
                        ':pts'  => $totalScore,
                        ':desc' => '成就积分：' . $app['description'],
                    ]);
                }

                $db->commit();
                $label = ($reviewAction === 'approve') ? '已通过，发放 ' . $totalScore . ' 分' : '已驳回';
                $success = '审核完成：' . $label;
            } catch (Exception $e) {
                $db->rollBack();
                $error = '操作失败：' . $e->getMessage();
            }
        }
    }

    // --- EDIT: Modify an approved application ---
    elseif ($action === 'edit') {
        $appId         = intval($_POST['app_id'] ?? 0);
        $description   = trim($_POST['description'] ?? '');
        $quantitative  = trim($_POST['quantitative'] ?? '');
        $reviewComment = trim($_POST['review_comment'] ?? '');
        $dimSaving     = intval($_POST['dim_saving'] ?? 0);
        $dimImpact     = intval($_POST['dim_impact'] ?? 0);
        $dimReplicate  = intval($_POST['dim_replicate'] ?? 0);
        $totalScore    = floatval($_POST['total_score'] ?? 0);

        if ($dimSaving < 0 || $dimSaving > 3)  $dimSaving = 0;
        if ($dimImpact < 0 || $dimImpact > 4)  $dimImpact = 0;
        if ($dimReplicate < 0 || $dimReplicate > 3) $dimReplicate = 0;

        $autoTotal = $dimSaving + $dimImpact + $dimReplicate;
        if ($totalScore <= 0) $totalScore = $autoTotal;
        if ($totalScore < 2)  $totalScore = 2;
        if ($totalScore > 10) $totalScore = 10;

        if ($description === '') {
            $error = '成果描述不能为空';
        } else {
            // Get old data for comparison
            $old = $db->prepare('SELECT * FROM achievement_applications WHERE id = :id AND status = \'approved\'');
            $old->execute([':id' => $appId]);
            $oldApp = $old->fetch();

            if (!$oldApp) {
                $error = '申请不存在或非已通过状态';
            } else {
                // Build changes array
                $changes = [];
                $fields = [
                    'description'     => ['old' => $oldApp['description'],     'new' => $description],
                    'quantitative'    => ['old' => $oldApp['quantitative'],    'new' => $quantitative],
                    'review_comment'  => ['old' => $oldApp['review_comment'],  'new' => $reviewComment],
                    'dim_saving'      => ['old' => (int)$oldApp['dim_saving'], 'new' => $dimSaving],
                    'dim_impact'      => ['old' => (int)$oldApp['dim_impact'], 'new' => $dimImpact],
                    'dim_replicate'   => ['old' => (int)$oldApp['dim_replicate'], 'new' => $dimReplicate],
                    'total_score'     => ['old' => (float)$oldApp['total_score'], 'new' => $totalScore],
                ];

                foreach ($fields as $f => $vals) {
                    if ($vals['old'] !== $vals['new']) {
                        $changes[$f] = $vals;
                    }
                }

                if (empty($changes)) {
                    $error = '未检测到任何修改';
                } else {
                    $db->beginTransaction();
                    try {
                        // Update application
                        $upd = $db->prepare(
                            'UPDATE achievement_applications
                             SET description = :desc, quantitative = :quant,
                                 dim_saving = :ds, dim_impact = :di, dim_replicate = :dr,
                                 total_score = :ts, review_comment = :rc,
                                 updated_at = datetime(\'now\',\'localtime\')
                             WHERE id = :id'
                        );
                        $upd->execute([
                            ':desc'  => $description,
                            ':quant' => $quantitative,
                            ':ds'    => $dimSaving,
                            ':di'    => $dimImpact,
                            ':dr'    => $dimReplicate,
                            ':ts'    => $totalScore,
                            ':rc'    => $reviewComment,
                            ':id'    => $appId,
                        ]);

                        // Record edit log
                        $logStmt = $db->prepare(
                            'INSERT INTO achievement_edit_logs (application_id, changes_json)
                             VALUES (:aid, :json)'
                        );
                        $logStmt->execute([
                            ':aid'  => $appId,
                            ':json' => json_encode($changes, JSON_UNESCAPED_UNICODE),
                        ]);

                        // If score or description changed, sync points_log
                        if (isset($changes['total_score']) || isset($changes['description'])) {
                            $ptsLog = $db->prepare(
                                "SELECT id FROM points_log
                                 WHERE employee_id = :eid AND type = 'achievement'
                                 ORDER BY id DESC LIMIT 1"
                            );
                            $ptsLog->execute([':eid' => $oldApp['employee_id']]);
                            $ptsId = $ptsLog->fetchColumn();

                            if ($ptsId) {
                                $ptsUpd = $db->prepare(
                                    'UPDATE points_log SET points = :pts, description = :desc WHERE id = :id'
                                );
                                $ptsUpd->execute([
                                    ':pts'  => $totalScore,
                                    ':desc' => '成就积分：' . $description,
                                    ':id'   => $ptsId,
                                ]);
                            }
                        }

                        $db->commit();
                        $changeCount = count($changes);
                        $success = "编辑保存成功！共修改 {$changeCount} 个字段";
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = '操作失败：' . $e->getMessage();
                    }
                }
            }
        }
    }

    // --- DELETE: Remove an approved application ---
    elseif ($action === 'delete') {
        $appId = intval($_POST['app_id'] ?? 0);

        $chk = $db->prepare('SELECT * FROM achievement_applications WHERE id = :id AND status = \'approved\'');
        $chk->execute([':id' => $appId]);
        $app = $chk->fetch();

        if (!$app) {
            $error = '申请不存在或非已通过状态';
        } else {
            $db->beginTransaction();
            try {
                // Delete related points_log
                $db->prepare(
                    "DELETE FROM points_log
                     WHERE id = (
                         SELECT id FROM points_log
                         WHERE employee_id = :eid AND type = 'achievement'
                         ORDER BY id DESC LIMIT 1
                     )"
                )->execute([':eid' => $app['employee_id']]);

                // Delete uploaded attachment files
                $attachData = !empty($app['attachment']) ? json_decode($app['attachment'], true) : null;
                if (is_array($attachData)) {
                    $uploadDir = __DIR__ . '/../data/uploads/';
                    foreach ($attachData as $att) {
                        $filePath = $uploadDir . $att['file'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                }

                // Delete application (CASCADE removes edit_logs)
                $db->prepare('DELETE FROM achievement_applications WHERE id = :id')
                   ->execute([':id' => $appId]);

                $db->commit();
                $success = '已删除该成就积分申请及相关积分记录';
            } catch (Exception $e) {
                $db->rollBack();
                $error = '删除失败：' . $e->getMessage();
            }
        }
    }
}

// ========================
// Data Queries
// ========================

// All active employees (for new application form)
$employees = $db->query(
    "SELECT e.id, e.name, d.name AS dept_name
     FROM employees e
     JOIN departments d ON e.department_id = d.id
     WHERE e.status = '在职'
     ORDER BY d.id, e.name"
)->fetchAll();

// All applications with employee info
$sql = "SELECT aa.id, aa.employee_id, e.name AS emp_name, d.name AS dept_name,
               aa.description, aa.quantitative, aa.attachment,
               aa.dim_saving, aa.dim_impact, aa.dim_replicate, aa.total_score,
               aa.status, aa.review_comment, aa.operator,
               aa.created_at, aa.reviewed_at
        FROM achievement_applications aa
        JOIN employees e ON aa.employee_id = e.id
        JOIN departments d ON e.department_id = d.id";

if ($statusFilter === 'pending') {
    $sql .= " WHERE aa.status = 'pending'";
} elseif ($statusFilter === 'approved') {
    $sql .= " WHERE aa.status = 'approved'";
} elseif ($statusFilter === 'rejected') {
    $sql .= " WHERE aa.status = 'rejected'";
}

$sql .= " ORDER BY aa.created_at DESC LIMIT 100";
$applications = $db->query($sql)->fetchAll();

// Edit logs for all applications
$editLogsByApp = [];
$editLogs = $db->query(
    "SELECT application_id, changes_json, created_at
     FROM achievement_edit_logs
     ORDER BY created_at ASC"
)->fetchAll();
foreach ($editLogs as $log) {
    $editLogsByApp[$log['application_id']][] = $log;
}

// ========================
// Build Content
// ========================
ob_start();
?>

<div class="page-header">
    <h2>成就积分管理</h2>
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
    <!-- ========== Left: Submit New Application ========== -->
    <div class="col-md-5">
        <div class="card mb-4">
            <div class="card-header"><strong>提交成就积分申请</strong></div>
            <div class="card-body">
                <form method="POST" action="/pages/achievement.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit">

                    <div class="form-group" style="position:relative;">
                        <label for="empSearch">选择员工 <span class="text-danger">*</span></label>
                        <input type="text" id="empSearch" class="form-control"
                               placeholder="输入员工姓名搜索..." autocomplete="off" required>
                        <input type="hidden" name="employee_id" id="empId">
                        <div id="empDropdown" class="search-dropdown" style="display:none;"></div>
                    </div>

                    <div class="form-group">
                        <label for="achDesc">成果描述 <span class="text-danger">*</span></label>
                        <textarea name="description" id="achDesc" class="form-control" rows="3"
                                  placeholder="描述员工的具体成果内容，如：开发了XX自动化脚本、编写了XX培训文档等"
                                  required maxlength="500"></textarea>
                        <small class="text-muted">最多 500 字</small>
                    </div>

                    <div class="form-group">
                        <label for="achQuant">量化数据</label>
                        <textarea name="quantitative" id="achQuant" class="form-control" rows="2"
                                  placeholder="例：每月节省 20 工时、覆盖 3 个部门、已被 5 个门店复用"
                                  maxlength="300"></textarea>
                        <small class="text-muted">可选，最多 300 字</small>
                    </div>

                    <div class="form-group">
                        <label for="achFile">佐证附件</label>
                        <div class="custom-file">
                            <input type="file" name="attachment[]" id="achFile" class="custom-file-input" multiple>
                            <label class="custom-file-label" for="achFile" data-browse="浏览">选择文件（可多选）...</label>
                        </div>
                        <small class="text-muted">可选，支持所有文件类型，单个最大 100MB</small>
                    </div>

                    <button type="submit" class="btn btn-primary">提交申请</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== Right: Application List ========== -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>申请审核列表</strong>
                <div class="btn-group btn-group-sm">
                    <a href="?status=all"      class="btn btn-outline-secondary <?php echo $statusFilter === 'all'      ? 'active' : ''; ?>">全部</a>
                    <a href="?status=pending"   class="btn btn-outline-warning <?php echo $statusFilter === 'pending'   ? 'active' : ''; ?>">待审核</a>
                    <a href="?status=approved"  class="btn btn-outline-success <?php echo $statusFilter === 'approved'  ? 'active' : ''; ?>">已通过</a>
                    <a href="?status=rejected"  class="btn btn-outline-danger  <?php echo $statusFilter === 'rejected'  ? 'active' : ''; ?>">已驳回</a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($applications)): ?>
                <div class="p-3 text-muted">暂无成就积分申请</div>
                <?php else: ?>
                <div style="max-height:520px;overflow-y:auto;">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>员工</th>
                            <th>成果描述</th>
                            <th>分值</th>
                            <th>状态</th>
                            <th>时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app):
                            $statusBadge = [
                                'pending'  => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                            ];
                            $statusLabel = [
                                'pending'  => '待审核',
                                'approved' => '已通过',
                                'rejected' => '已驳回',
                            ];
                            $badge = $statusBadge[$app['status']] ?? 'secondary';
                            $label = $statusLabel[$app['status']] ?? $app['status'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($app['emp_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($app['dept_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td>
                                <div style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                     title="<?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($app['status'] === 'approved'): ?>
                                <span class="badge badge-primary">+<?php echo $app['total_score']; ?></span>
                                <?php else: ?>
                                <span class="text-muted">--</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $label; ?></span></td>
                            <td>
                                <small><?php echo $app['created_at']; ?></small>
                                <?php
                                $attachData = !empty($app['attachment']) ? json_decode($app['attachment'], true) : null;
                                if (is_array($attachData)):
                                    foreach ($attachData as $att):
                                ?>
                                <br><a href="/data/uploads/<?php echo htmlspecialchars(urlencode($att['file']), ENT_QUOTES, 'UTF-8'); ?>"
                                      target="_blank" class="small"
                                      title="<?php echo htmlspecialchars($att['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    📎 <?php echo htmlspecialchars($att['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php endforeach; endif; ?>
                            </td>
                            <td>
                                <?php if ($app['status'] === 'pending'): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-review"
                                        data-toggle="modal" data-target="#reviewModal"
                                        data-id="<?php echo $app['id']; ?>"
                                        data-employee="<?php echo htmlspecialchars($app['emp_name'] . ' - ' . $app['dept_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-description="<?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-quantitative="<?php echo htmlspecialchars($app['quantitative'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-attachment="<?php echo htmlspecialchars($app['attachment'], ENT_QUOTES, 'UTF-8'); ?>">
                                    审核
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-detail"
                                        data-toggle="modal" data-target="#detailModal"
                                        data-id="<?php echo $app['id']; ?>"
                                        data-employee="<?php echo htmlspecialchars($app['emp_name'] . ' - ' . $app['dept_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-description="<?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-quantitative="<?php echo htmlspecialchars($app['quantitative'] ?: '无', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-attachment="<?php echo htmlspecialchars($app['attachment'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-dimsaving="<?php echo $app['dim_saving']; ?>"
                                        data-dimimpact="<?php echo $app['dim_impact']; ?>"
                                        data-dimreplicate="<?php echo $app['dim_replicate']; ?>"
                                        data-total="<?php echo $app['total_score']; ?>"
                                        data-comment="<?php echo htmlspecialchars($app['review_comment'] ?: '无', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-statuslabel="<?php echo $label; ?>"
                                        data-statusbadge="<?php echo $badge; ?>"
                                        data-reviewedat="<?php echo $app['reviewed_at'] ?? ''; ?>">
                                    查看
                                </button>
                                <?php if ($app['status'] === 'approved'): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning btn-edit"
                                        data-toggle="modal" data-target="#editModal"
                                        data-id="<?php echo $app['id']; ?>"
                                        data-description="<?php echo htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-quantitative="<?php echo htmlspecialchars($app['quantitative'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-dimsaving="<?php echo $app['dim_saving']; ?>"
                                        data-dimimpact="<?php echo $app['dim_impact']; ?>"
                                        data-dimreplicate="<?php echo $app['dim_replicate']; ?>"
                                        data-total="<?php echo $app['total_score']; ?>"
                                        data-comment="<?php echo htmlspecialchars($app['review_comment'], ENT_QUOTES, 'UTF-8'); ?>">
                                    编辑
                                </button>
                                <form method="POST" action="/pages/achievement.php?status=<?php echo $statusFilter; ?>" style="display:inline;"
                                      class="form-delete" data-employee="<?php echo htmlspecialchars($app['emp_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="app_id" value="<?php echo $app['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                                </form>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
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

<!-- ========================
     Review Modal
     ======================== -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">审核成就积分申请</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="/pages/achievement.php" id="reviewForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="review">
                    <input type="hidden" name="app_id" id="reviewAppId">

                    <!-- Application info (read-only) -->
                    <div class="form-row mb-3">
                        <div class="col">
                            <label class="text-muted small">员工</label>
                            <div class="font-weight-bold" id="reviewEmployee"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="text-muted small">成果描述</label>
                        <div id="reviewDesc" class="p-2 bg-light rounded"></div>
                    </div>
                    <div class="form-group">
                        <label class="text-muted small">量化数据</label>
                        <div id="reviewQuant" class="p-2 bg-light rounded text-muted"></div>
                    </div>
                    <div class="form-group" id="reviewAttachGroup" style="display:none;">
                        <label class="text-muted small">佐证附件</label>
                        <div id="reviewAttachments"></div>
                    </div>

                    <hr>
                    <h6>三维度评定</h6>

                    <div class="form-row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="dimSaving">
                                    节省工时
                                    <span class="text-muted small">（0-3分）</span>
                                </label>
                                <select name="dim_saving" id="dimSaving" class="form-control dim-select">
                                    <?php for ($i = 0; $i <= 3; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> 分</option>
                                    <?php endfor; ?>
                                </select>
                                <small class="text-muted">评估所节省的工时/效率提升</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="dimImpact">
                                    影响范围
                                    <span class="text-muted small">（0-4分）</span>
                                </label>
                                <select name="dim_impact" id="dimImpact" class="form-control dim-select">
                                    <?php for ($i = 0; $i <= 4; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> 分</option>
                                    <?php endfor; ?>
                                </select>
                                <small class="text-muted">评估影响的部门/人员范围</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="dimReplicate">
                                    可复制性
                                    <span class="text-muted small">（0-3分）</span>
                                </label>
                                <select name="dim_replicate" id="dimReplicate" class="form-control dim-select">
                                    <?php for ($i = 0; $i <= 3; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> 分</option>
                                    <?php endfor; ?>
                                </select>
                                <small class="text-muted">评估成果可复用到其他场景的程度</small>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>自动汇总：<strong id="autoTotal">0</strong> 分</span>
                            <div class="form-inline">
                                <label for="totalScore" class="mr-2 mb-0">调整分值：</label>
                                <input type="number" name="total_score" id="totalScore"
                                       class="form-control form-control-sm" style="width:80px;"
                                       min="2" max="10" step="1" value="0"
                                       placeholder="自动">
                                <small class="text-muted ml-2">留空则使用自动汇总</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reviewComment">审核意见</label>
                        <textarea name="review_comment" id="reviewComment" class="form-control" rows="2"
                                  placeholder="通过/驳回的具体理由" maxlength="300"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" name="review_action" value="reject" class="btn btn-danger">驳回</button>
                    <button type="submit" name="review_action" value="approve" class="btn btn-success">通过并发放积分</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================
     Detail Modal (view-only for approved/rejected)
     ======================== -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">审核详情</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="text-muted small">员工</label>
                    <div class="font-weight-bold" id="detailEmployee"></div>
                </div>
                <div class="form-group">
                    <label class="text-muted small">成果描述</label>
                    <div id="detailDesc" class="p-2 bg-light rounded"></div>
                </div>
                <div class="form-group">
                    <label class="text-muted small">量化数据</label>
                    <div id="detailQuant" class="p-2 bg-light rounded text-muted"></div>
                </div>
                <div class="form-group" id="detailAttachGroup" style="display:none;">
                    <label class="text-muted small">佐证附件</label>
                    <div id="detailAttachments"></div>
                </div>
                <hr>
                <div class="form-row">
                    <div class="col-4 text-center">
                        <small class="text-muted">节省工时</small>
                        <div class="font-weight-bold" id="detailDimSaving"></div>
                    </div>
                    <div class="col-4 text-center">
                        <small class="text-muted">影响范围</small>
                        <div class="font-weight-bold" id="detailDimImpact"></div>
                    </div>
                    <div class="col-4 text-center">
                        <small class="text-muted">可复制性</small>
                        <div class="font-weight-bold" id="detailDimReplicate"></div>
                    </div>
                </div>
                <div class="text-center my-3">
                    <span class="text-muted">综合分值：</span>
                    <span class="badge badge-primary" style="font-size:1.1rem;" id="detailTotal"></span>
                    <span class="badge ml-2" id="detailStatus"></span>
                </div>
                <div class="form-group">
                    <label class="text-muted small">审核意见</label>
                    <div id="detailComment" class="p-2 bg-light rounded"></div>
                </div>
                <div class="form-group">
                    <label class="text-muted small">审核时间</label>
                    <div id="detailReviewedAt" class="text-muted small"></div>
                </div>
                <div id="detailEditHistory" style="display:none;">
                    <hr>
                    <label class="text-muted small">编辑记录</label>
                    <div id="detailEditHistoryList" class="small"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================
     Edit Modal (for approved applications)
     ======================== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑已通过申请</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="/pages/achievement.php" id="editForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="app_id" id="editAppId">

                    <div class="form-group">
                        <label for="editDesc">成果描述 <span class="text-danger">*</span></label>
                        <textarea name="description" id="editDesc" class="form-control" rows="3"
                                  required maxlength="500"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="editQuant">量化数据</label>
                        <textarea name="quantitative" id="editQuant" class="form-control" rows="2"
                                  maxlength="300"></textarea>
                    </div>

                    <hr>
                    <h6>三维度评定</h6>

                    <div class="form-row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="editDimSaving">节省工时 <span class="text-muted small">（0-3分）</span></label>
                                <select name="dim_saving" id="editDimSaving" class="form-control dim-edit-select">
                                    <?php for ($i = 0; $i <= 3; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> 分</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="editDimImpact">影响范围 <span class="text-muted small">（0-4分）</span></label>
                                <select name="dim_impact" id="editDimImpact" class="form-control dim-edit-select">
                                    <?php for ($i = 0; $i <= 4; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> 分</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="editDimReplicate">可复制性 <span class="text-muted small">（0-3分）</span></label>
                                <select name="dim_replicate" id="editDimReplicate" class="form-control dim-edit-select">
                                    <?php for ($i = 0; $i <= 3; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> 分</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>自动汇总：<strong id="editAutoTotal">0</strong> 分</span>
                            <div class="form-inline">
                                <label for="editTotalScore" class="mr-2 mb-0">调整分值：</label>
                                <input type="number" name="total_score" id="editTotalScore"
                                       class="form-control form-control-sm" style="width:80px;"
                                       min="2" max="10" step="1" value="0"
                                       placeholder="自动">
                                <small class="text-muted ml-2">留空则使用自动汇总</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="editComment">审核意见</label>
                        <textarea name="review_comment" id="editComment" class="form-control" rows="2"
                                  maxlength="300"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-warning">保存修改</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Review modal: populate data
$('#reviewModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#reviewAppId').val(btn.data('id'));
    $('#reviewEmployee').text(btn.data('employee'));
    $('#reviewDesc').text(btn.data('description'));
    $('#reviewQuant').text(btn.data('quantitative') || '（无）');
    // Reset form
    $('#dimSaving').val(0);
    $('#dimImpact').val(0);
    $('#dimReplicate').val(0);
    $('#totalScore').val('');
    $('#reviewComment').val('');
    // Attachment
    var att = btn.data('attachment');
    var $attGroup = $('#reviewAttachGroup');
    var $attContainer = $('#reviewAttachments');
    $attContainer.empty();
    if (att) {
        try {
            var files = JSON.parse(att);
            if (Array.isArray(files) && files.length > 0) {
                files.forEach(function(f) {
                    $attContainer.append(
                        '<a href="/data/uploads/' + encodeURIComponent(f.file) + '" target="_blank" class="d-block small mb-1">📎 ' + $('<span>').text(f.name).html() + '</a>'
                    );
                });
                $attGroup.show();
            } else {
                $attGroup.hide();
            }
        } catch(e) {
            $attGroup.hide();
        }
    } else {
        $attGroup.hide();
    }
    updateAutoTotal();
});

// Auto-calculate total score when dimensions change
$('.dim-select').on('change', function() {
    updateAutoTotal();
});

function updateAutoTotal() {
    var saving = parseInt($('#dimSaving').val()) || 0;
    var impact = parseInt($('#dimImpact').val()) || 0;
    var replicate = parseInt($('#dimReplicate').val()) || 0;
    var total = saving + impact + replicate;
    $('#autoTotal').text(total);
}

// Detail modal: populate data
$('#detailModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#detailEmployee').text(btn.data('employee'));
    $('#detailDesc').text(btn.data('description'));
    $('#detailQuant').text(btn.data('quantitative'));
    // Attachment
    var att = btn.data('attachment');
    var $attGroup = $('#detailAttachGroup');
    var $attContainer = $('#detailAttachments');
    $attContainer.empty();
    if (att) {
        try {
            var files = JSON.parse(att);
            if (Array.isArray(files) && files.length > 0) {
                files.forEach(function(f) {
                    $attContainer.append(
                        '<a href="/data/uploads/' + encodeURIComponent(f.file) + '" target="_blank" class="d-block small mb-1">📎 ' + $('<span>').text(f.name).html() + '</a>'
                    );
                });
                $attGroup.show();
            } else {
                $attGroup.hide();
            }
        } catch(e) {
            $attGroup.hide();
        }
    } else {
        $attGroup.hide();
    }
    $('#detailDimSaving').text(btn.data('dimsaving') + ' 分');
    $('#detailDimImpact').text(btn.data('dimimpact') + ' 分');
    $('#detailDimReplicate').text(btn.data('dimreplicate') + ' 分');
    $('#detailTotal').text('+' + btn.data('total') + ' 分');
    $('#detailComment').text(btn.data('comment'));
    $('#detailReviewedAt').text(btn.data('reviewedat') || '');
    $('#detailStatus')
        .text(btn.data('statuslabel'))
        .removeClass('badge-warning badge-success badge-danger')
        .addClass('badge-' + btn.data('statusbadge'));
});

// Delete confirmation
$('.form-delete').on('submit', function() {
    var emp = $(this).data('employee');
    return confirm('确定要删除「' + emp + '」的成就积分申请吗？\n\n此操作将同时删除关联的积分记录和附件文件，不可撤销！');
});

// Update custom file input label when files selected
$('#achFile').on('change', function() {
    var files = this.files;
    if (files.length === 0) {
        $(this).next('.custom-file-label').text('选择文件（可多选）...');
    } else if (files.length === 1) {
        $(this).next('.custom-file-label').text(files[0].name);
    } else {
        $(this).next('.custom-file-label').text('已选 ' + files.length + ' 个文件');
    }
});

// Employee search dropdown
var allEmployees = <?php echo json_encode(array_map(function($e) {
    return ['id' => $e['id'], 'name' => $e['name'], 'dept' => $e['dept_name']];
}, $employees), JSON_UNESCAPED_UNICODE); ?>;

$('#empSearch').on('input focus', function() {
    var q = $.trim($(this).val().toLowerCase());
    var $dd = $('#empDropdown');
    if (q.length === 0) {
        $dd.hide().empty();
        $('#empId').val('');
        return;
    }
    var matches = allEmployees.filter(function(e) {
        return e.name.toLowerCase().indexOf(q) !== -1 || e.dept.toLowerCase().indexOf(q) !== -1;
    });
    if (matches.length === 0) {
        $dd.hide().empty();
        return;
    }
    var html = '';
    matches.slice(0, 10).forEach(function(e) {
        html += '<div class="search-dropdown-item" data-id="' + e.id + '" data-name="' + e.name + ' - ' + e.dept + '">'
              + '<strong>' + e.name + '</strong>'
              + '<small class="text-muted ml-2">' + e.dept + '</small>'
              + '</div>';
    });
    $dd.html(html).show();
}).on('blur', function() {
    setTimeout(function() { $('#empDropdown').hide(); }, 200);
});

$('#empDropdown').on('mousedown', '.search-dropdown-item', function() {
    var $item = $(this);
    $('#empId').val($item.data('id'));
    $('#empSearch').val($item.data('name'));
    $('#empDropdown').hide();
});

// ========================
// Edit Modal
// ========================
$('#editModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#editAppId').val(btn.data('id'));
    $('#editDesc').val(btn.data('description'));
    $('#editQuant').val(btn.data('quantitative'));
    $('#editDimSaving').val(btn.data('dimsaving'));
    $('#editDimImpact').val(btn.data('dimimpact'));
    $('#editDimReplicate').val(btn.data('dimreplicate'));
    $('#editTotalScore').val(btn.data('total'));
    $('#editComment').val(btn.data('comment'));
    updateEditAutoTotal();
});

$('.dim-edit-select').on('change', function() {
    updateEditAutoTotal();
});

function updateEditAutoTotal() {
    var saving = parseInt($('#editDimSaving').val()) || 0;
    var impact = parseInt($('#editDimImpact').val()) || 0;
    var replicate = parseInt($('#editDimReplicate').val()) || 0;
    var total = saving + impact + replicate;
    $('#editAutoTotal').text(total);
}

// ========================
// Edit History in Detail Modal
// ========================
var editLogsByApp = <?php echo json_encode($editLogsByApp, JSON_UNESCAPED_UNICODE); ?>;

var editFieldLabels = {
    'description':    '成果描述',
    'quantitative':   '量化数据',
    'review_comment': '审核意见',
    'dim_saving':     '节省工时',
    'dim_impact':     '影响范围',
    'dim_replicate':  '可复制性',
    'total_score':    '综合分值'
};

// Display edit history in detail modal
$(document).on('show.bs.modal', '#detailModal', function (event) {
    var btn = $(event.relatedTarget);
    var appId = parseInt(btn.data('id'));
    var logs = editLogsByApp[appId];
    var $history = $('#detailEditHistory');
    var $list = $('#detailEditHistoryList');

    if (logs && logs.length > 0) {
        var html = '';
        logs.forEach(function(log) {
            var changes = JSON.parse(log.changes_json);
            var fieldsHtml = Object.keys(changes).map(function(f) {
                var label = editFieldLabels[f] || f;
                return '<span class="text-muted">' + label + '</span>: '
                     + '<span class="text-danger">' + changes[f].old + '</span> → '
                     + '<span class="text-success">' + changes[f].new + '</span>';
            }).join('<br>');
            html += '<div class="mb-2 p-2 bg-light rounded">'
                  + '<div class="text-muted" style="font-size:0.75rem;">' + log.created_at + '</div>'
                  + fieldsHtml
                  + '</div>';
        });
        $list.html(html);
        $history.show();
    } else {
        $history.hide();
    }
});
</script>

<style>
.search-dropdown {
    position: absolute;
    z-index: 1050;
    max-height: 240px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid var(--color-border);
    border-top: none;
    border-radius: 0 0 var(--radius-md) var(--radius-md);
    width: 100%;
    box-shadow: var(--shadow-md);
}
.search-dropdown-item {
    padding: 8px 14px;
    cursor: pointer;
    font-size: 13px;
    transition: background 0.1s;
}
.search-dropdown-item:hover {
    background: rgba(201, 169, 110, 0.08);
    color: var(--color-navy);
}
#empSearch:focus + #empDropdown,
#empDropdown:hover {
    display: block;
}
</style>

<?php
$content = ob_get_clean();
renderLayout('成就积分管理', 'achievement', $content);
