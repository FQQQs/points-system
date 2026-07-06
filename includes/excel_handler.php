<?php
/**
 * Excel 导入导出处理器
 * 
 * 路由参数 action:
 *   template_employee  — 下载员工导入模板
 *   template_department — 下载部门导入模板
 *   export_employees   — 导出员工列表（?department=X 可选筛选）
 *   export_departments — 导出部门列表
 *   import_employees   — 批量导入员工（POST multipart）
 *   import_departments — 批量导入部门（POST multipart）
 */

require_once __DIR__ . '/db.php';

// Load PhpSpreadsheet autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet');
}
require_once $autoloadPath;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

$db   = getDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ========================
// TEMPLATES
// ========================

if ($action === 'template_employee') {
    downloadEmployeeTemplate();
    exit;
}

if ($action === 'template_department') {
    downloadDepartmentTemplate();
    exit;
}

// ========================
// EXPORTS
// ========================

if ($action === 'export_employees') {
    exportEmployees($db, intval($_GET['department'] ?? 0));
    exit;
}

if ($action === 'export_departments') {
    exportDepartments($db);
    exit;
}

if ($action === 'template_prizes') {
    downloadPrizesTemplate();
    exit;
}

if ($action === 'export_prizes') {
    exportPrizes($db);
    exit;
}

// ========================
// IMPORTS
// ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import_employees') {
    importEmployees($db);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import_departments') {
    importDepartments($db);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import_prizes') {
    importPrizes($db);
    exit;
}

die('Invalid action');

// ========================
// Template Functions
// ========================

function downloadEmployeeTemplate(): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('员工导入模板');

    // Header row
    $headers = ['姓名', '部门', '手机号', '邮箱', 'AI等级', '状态'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '1', $h);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $sheet->getStyle($col . '1')->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FFD9E1F2');
        $col++;
    }

    // Sample data row
    $sheet->setCellValue('A2', '张三');
    $sheet->setCellValue('B2', '总经办');
    $sheet->setCellValue('C2', '13800000000');
    $sheet->setCellValue('D2', 'zhangsan@example.com');
    $sheet->setCellValue('E2', 'L0');
    $sheet->setCellValue('F2', '在职');

    // Column widths
    $sheet->getColumnDimension('A')->setWidth(12);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(18);
    $sheet->getColumnDimension('D')->setWidth(25);
    $sheet->getColumnDimension('E')->setWidth(10);
    $sheet->getColumnDimension('F')->setWidth(10);

    // AI Level dropdown validation
    $validation = $sheet->getCell('E2')->getDataValidation();
    $validation->setType(DataValidation::TYPE_LIST);
    $validation->setFormula1('"L0,L1,L2,L3,L4"');
    $validation->setAllowBlank(true);
    $validation->setShowDropDown(true);
    $sheet->setDataValidation('E2:E1000', $validation);

    // Status dropdown validation
    $validation2 = $sheet->getCell('F2')->getDataValidation();
    $validation2->setType(DataValidation::TYPE_LIST);
    $validation2->setFormula1('"在职,离职"');
    $validation2->setAllowBlank(true);
    $validation2->setShowDropDown(true);
    $sheet->setDataValidation('F2:F1000', $validation2);

    outputSpreadsheet($spreadsheet, '员工导入模板.xlsx');
}

function downloadDepartmentTemplate(): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('部门导入模板');

    $headers = ['部门名称'];
    $sheet->setCellValue('A1', $headers[0]);
    $sheet->getStyle('A1')->getFont()->setBold(true);
    $sheet->getStyle('A1')->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FFD9E1F2');

    $sheet->setCellValue('A2', '总经办');
    $sheet->getColumnDimension('A')->setWidth(20);

    outputSpreadsheet($spreadsheet, '部门导入模板.xlsx');
}

// ========================
// Export Functions
// ========================

function exportEmployees(PDO $db, int $filterDept): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('员工列表');

    // Headers
    $headers = ['ID', '姓名', '部门', '手机号', '邮箱', 'AI等级', '状态'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '1', $h);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $sheet->getStyle($col . '1')->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FFD9E1F2');
        $col++;
    }

    // Data
    $sql = 'SELECT e.id, e.name, d.name AS dept_name, e.phone, e.email, e.ai_level, e.status
            FROM employees e JOIN departments d ON e.department_id = d.id';
    $params = [];
    if ($filterDept > 0) {
        $sql .= ' WHERE e.department_id = :dept_id';
        $params[':dept_id'] = $filterDept;
    }
    $sql .= ' ORDER BY e.id';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = 2;
    while ($emp = $stmt->fetch()) {
        $sheet->setCellValue('A' . $row, $emp['id']);
        $sheet->setCellValue('B' . $row, $emp['name']);
        $sheet->setCellValue('C' . $row, $emp['dept_name']);
        $sheet->setCellValue('D' . $row, $emp['phone']);
        $sheet->setCellValue('E' . $row, $emp['email']);
        $sheet->setCellValue('F' . $row, $emp['ai_level']);
        $sheet->setCellValue('G' . $row, $emp['status']);
        $row++;
    }

    // Auto-size columns
    foreach (range('A', 'G') as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
    }

    outputSpreadsheet($spreadsheet, '员工列表_' . date('Y-m-d') . '.xlsx');
}

function exportDepartments(PDO $db): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('部门列表');

    $sheet->setCellValue('A1', 'ID');
    $sheet->setCellValue('B1', '部门名称');
    $sheet->getStyle('A1:B1')->getFont()->setBold(true);
    $sheet->getStyle('A1:B1')->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FFD9E1F2');

    $stmt = $db->query('SELECT id, name FROM departments ORDER BY id');
    $row = 2;
    while ($dept = $stmt->fetch()) {
        $sheet->setCellValue('A' . $row, $dept['id']);
        $sheet->setCellValue('B' . $row, $dept['name']);
        $row++;
    }

    $sheet->getColumnDimension('A')->setAutoSize(true);
    $sheet->getColumnDimension('B')->setAutoSize(true);

    outputSpreadsheet($spreadsheet, '部门列表_' . date('Y-m-d') . '.xlsx');
}

// ========================
// Import Functions
// ========================

function importEmployees(PDO $db): void
{
    $redirect = '../index.php';

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: ' . $redirect . '?error=upload');
        exit;
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (count($rows) < 2) {
            header('Location: ' . $redirect . '?error=empty');
            exit;
        }

        // Skip header row
        $headers = array_map('trim', $rows[0]);

        // Preload department name→id map
        $deptMap = [];
        $deptStmt = $db->query('SELECT id, name FROM departments');
        while ($d = $deptStmt->fetch()) {
            $deptMap[trim($d['name'])] = $d['id'];
        }

        $validLevels = ['L0', 'L1', 'L2', 'L3', 'L4'];
        $imported = 0;
        $errors = [];

        $db->beginTransaction();

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $name    = trim($row[0] ?? '');
            $deptName = trim($row[1] ?? '');
            $phone   = trim($row[2] ?? '');
            $email   = trim($row[3] ?? '');
            $aiLevel = trim($row[4] ?? 'L0');
            $status  = trim($row[5] ?? '在职');

            if ($name === '') continue; // skip empty rows

            // Resolve department
            $deptId = $deptMap[$deptName] ?? null;
            if ($deptId === null) {
                // Auto-create department if not found
                $stmt = $db->prepare('INSERT INTO departments (name) VALUES (:name)');
                $stmt->execute([':name' => $deptName]);
                $deptId = $db->lastInsertId();
                $deptMap[$deptName] = $deptId;
            }

            if (!in_array($aiLevel, $validLevels, true)) {
                $aiLevel = 'L0';
            }
            if (!in_array($status, ['在职', '离职'], true)) {
                $status = '在职';
            }

            $stmt = $db->prepare(
                'INSERT INTO employees (name, department_id, phone, email, ai_level, status)
                 VALUES (:name, :dept_id, :phone, :email, :ai_level, :status)'
            );
            $stmt->execute([
                ':name'    => $name,
                ':dept_id' => $deptId,
                ':phone'   => $phone,
                ':email'   => $email,
                ':ai_level' => $aiLevel,
                ':status'  => $status,
            ]);
            $imported++;
        }

        $db->commit();

        header('Location: ' . $redirect . '?imported=' . $imported);
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $msg = urlencode(mb_substr($e->getMessage(), 0, 200));
        header('Location: ' . $redirect . '?error=import&msg=' . $msg);
        exit;
    }
}

function importDepartments(PDO $db): void
{
    $redirect = '../pages/department.php';

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: ' . $redirect . '?error=upload');
        exit;
    }

    try {
        $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        if (count($rows) < 2) {
            header('Location: ' . $redirect . '?error=empty');
            exit;
        }

        $imported = 0;
        $updated = 0;

        $db->beginTransaction();

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $name = trim($row[0] ?? '');

            if ($name === '') continue;

            // Check if department exists (first column is name)
            $check = $db->prepare('SELECT id FROM departments WHERE name = :name');
            $check->execute([':name' => $name]);

            if ($existing = $check->fetch()) {
                // Update mode: if there's an ID in column 0 (0-indexed), use it
                // Otherwise just skip (name already exists)
                $updated++;
            } else {
                $stmt = $db->prepare('INSERT INTO departments (name) VALUES (:name)');
                $stmt->execute([':name' => $name]);
                $imported++;
            }
        }

        $db->commit();

        header('Location: ' . $redirect . '?imported=' . $imported . '&updated=' . $updated);
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $msg = urlencode(mb_substr($e->getMessage(), 0, 200));
        header('Location: ' . $redirect . '?error=import&msg=' . $msg);
        exit;
    }
}

// ========================
// Utility
// ========================

function outputSpreadsheet(Spreadsheet $spreadsheet, string $filename): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
}

// ========================
// Prizes Functions
// ========================

function downloadPrizesTemplate(): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('奖品导入模板');

    $headers = ['奖品名称', '所需积分', '库存数量', '描述', '状态'];
    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col . '1', $h);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
    }

    $sheet->setCellValue('A2', '蓝牙耳机');
    $sheet->setCellValue('B2', 50);
    $sheet->setCellValue('C2', 10);
    $sheet->setCellValue('D2', '高品质蓝牙耳机');
    $sheet->setCellValue('E2', '上架');

    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(12);
    $sheet->getColumnDimension('C')->setWidth(10);
    $sheet->getColumnDimension('D')->setWidth(30);
    $sheet->getColumnDimension('E')->setWidth(10);

    outputSpreadsheet($spreadsheet, '奖品导入模板.xlsx');
}

function exportPrizes(PDO $db): void
{
    $prizes = $db->query('SELECT name, points_cost, stock, description, status FROM prizes ORDER BY id')->fetchAll();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('奖品列表');

    $headers = ['奖品名称', '所需积分', '库存数量', '描述', '状态'];
    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col . '1', $h);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
    }

    $row = 2;
    foreach ($prizes as $p) {
        $sheet->setCellValue('A' . $row, $p['name']);
        $sheet->setCellValue('B' . $row, $p['points_cost']);
        $sheet->setCellValue('C' . $row, $p['stock'] ?? '不限');
        $sheet->setCellValue('D' . $row, $p['description']);
        $sheet->setCellValue('E' . $row, $p['status'] === 'active' ? '上架' : '下架');
        $row++;
    }

    foreach (range('A', 'E') as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
    }

    outputSpreadsheet($spreadsheet, '奖品列表_' . date('Y-m-d') . '.xlsx');
}

function importPrizes(PDO $db): void
{
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        die('上传失败');
    }

    $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();
    array_shift($rows); // remove header

    $added   = 0;
    $updated = 0;

    $stmt = $db->prepare(
        'INSERT INTO prizes (name, points_cost, stock, description, status) VALUES (:n, :p, :s, :d, :st)'
    );

    foreach ($rows as $r) {
        $name    = trim($r[0] ?? '');
        $cost    = floatval($r[1] ?? 0);
        $stock   = trim($r[2] ?? '');
        $desc    = trim($r[3] ?? '');
        $status  = trim($r[4] ?? '上架');

        if ($name === '' || $cost <= 0) continue;

        $stockVal = ($stock === '' || strtolower($stock) === '不限') ? null : intval($stock);
        $statusVal = ($status === '下架') ? 'inactive' : 'active';

        $stmt->execute([
            ':n'  => $name,
            ':p'  => $cost,
            ':s'  => $stockVal,
            ':d'  => $desc,
            ':st' => $statusVal,
        ]);
        $added++;
    }

    header('Location: /pages/prizes.php?imported=' . $added . ($updated > 0 ? '&updated=' . $updated : ''));
    exit;
}
