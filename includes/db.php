<?php
/**
 * 苍井寿司 AI 积分管理系统 — PDO数据库连接单例
 * 
 * 提供 getDB() 函数，返回单例 PDO 连接到 data/points.db (SQLite)
 * 首次调用时自动建表并填充 39 个部门的种子数据
 */

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dbPath = __DIR__ . '/../data/points.db';
        $isNew = !file_exists($dbPath);

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Enable foreign keys
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Initialize schema on first run
        if ($isNew) {
            initSchema($pdo);
        } else {
            // Run migrations for existing databases
            migrateToV3($pdo);
            migrateToV4($pdo);
            migrateToV5($pdo);
            migrateToV6($pdo);
            migrateToV7($pdo);
            migrateToV8($pdo);
            migrateToV9($pdo);
            migrateToV10($pdo);
        }
    }

    return $pdo;
}

/**
 * Migrate schema for V3 → V4: simplify training (remove homework, drop status col, extend duration).
 */
function migrateToV4(PDO $pdo): void
{
    // Check if already migrated (homework table gone)
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='training_homework'");
    if (!$result->fetch()) {
        // Check if duration is already REAL type (clean V4 schema)
        $info = $pdo->query("PRAGMA table_info(training_activities)")->fetchAll();
        foreach ($info as $col) {
            if ($col['name'] === 'duration' && strpos($col['type'], 'REAL') !== false) {
                return; // Already migrated
            }
        }
    }

    // Drop homework table
    $pdo->exec('DROP TABLE IF EXISTS training_homework');

    // Rebuild training_attendance without status column
    $pdo->exec('CREATE TABLE IF NOT EXISTS training_attendance_v4 (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        training_id   INTEGER NOT NULL REFERENCES training_activities(id) ON DELETE CASCADE,
        employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        UNIQUE(training_id, employee_id)
    )');

    // Copy data from old attendance table if it exists
    $hasOld = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='training_attendance'");
    $info = $hasOld->fetch();
    if ($info) {
        $cols = [];
        $colInfo = $pdo->query("PRAGMA table_info(training_attendance)")->fetchAll();
        foreach ($colInfo as $c) $cols[] = $c['name'];

        // If old table has status col, it's V3 format
        if (in_array('status', $cols)) {
            $pdo->exec('INSERT OR IGNORE INTO training_attendance_v4 (training_id, employee_id)
                        SELECT training_id, employee_id FROM training_attendance WHERE status = \'attended\'');
            $pdo->exec('DROP TABLE training_attendance');
            $pdo->exec('ALTER TABLE training_attendance_v4 RENAME TO training_attendance');
        }
    }

    // Rebuild training_activities with new duration constraint
    $pdo->exec('CREATE TABLE IF NOT EXISTS training_activities_v4 (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        train_date    TEXT    NOT NULL,
        duration      REAL    NOT NULL CHECK(duration >= 0.5 AND duration <= 4.0),
        operator      TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    $hasActivities = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='training_activities'");
    if ($hasActivities->fetch()) {
        $pdo->exec('INSERT OR IGNORE INTO training_activities_v4 (id, name, train_date, duration, operator, created_at)
                    SELECT id, name, train_date, CAST(duration AS REAL), operator, created_at FROM training_activities');
        $pdo->exec('DROP TABLE training_activities');
        $pdo->exec('ALTER TABLE training_activities_v4 RENAME TO training_activities');
    }
}

/**
 * Migrate schema for V2 → V3: add Phase 2 point tables (points_log, training_*, exam_records).
 */
function migrateToV3(PDO $pdo): void
{
    // Check if Phase 2 tables exist
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='points_log'");
    if ($result->fetch()) {
        return; // Already migrated
    }

    // Unified points transaction log
    $pdo->exec('CREATE TABLE IF NOT EXISTS points_log (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        type          TEXT    NOT NULL CHECK(type IN (\'survey\',\'training\',\'exam\',\'achievement\')),
        points        REAL    NOT NULL,
        description   TEXT    DEFAULT \'\',
        operator      TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Training activities
    $pdo->exec('CREATE TABLE IF NOT EXISTS training_activities (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        train_date    TEXT    NOT NULL,
        duration      REAL    NOT NULL CHECK(duration >= 0.5 AND duration <= 4.0),
        operator      TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Training attendance (simplified: who attended which training)
    $pdo->exec('CREATE TABLE IF NOT EXISTS training_attendance (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        training_id   INTEGER NOT NULL REFERENCES training_activities(id) ON DELETE CASCADE,
        employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        UNIQUE(training_id, employee_id)
    )');

    // Exam records
    $pdo->exec('CREATE TABLE IF NOT EXISTS exam_records (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        exam_level    TEXT    NOT NULL CHECK(exam_level IN (\'L1\',\'L2\',\'L3\',\'L4\')),
        passed_date   TEXT    NOT NULL DEFAULT (date(\'now\',\'localtime\')),
        operator      TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
        UNIQUE(employee_id, exam_level)
    )');

    // Achievement applications (Phase 3)
    $pdo->exec('CREATE TABLE IF NOT EXISTS achievement_applications (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id     INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        description     TEXT    NOT NULL,
        quantitative    TEXT    DEFAULT \'\',
        dim_saving      INTEGER NOT NULL DEFAULT 0 CHECK(dim_saving BETWEEN 0 AND 3),
        dim_impact      INTEGER NOT NULL DEFAULT 0 CHECK(dim_impact BETWEEN 0 AND 4),
        dim_replicate   INTEGER NOT NULL DEFAULT 0 CHECK(dim_replicate BETWEEN 0 AND 3),
        total_score     REAL    NOT NULL DEFAULT 0,
        status          TEXT    NOT NULL DEFAULT \'pending\' CHECK(status IN (\'pending\',\'approved\',\'rejected\')),
        review_comment  TEXT    DEFAULT \'\',
        attachment      TEXT    DEFAULT \'\',
        operator        TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at      TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
        reviewed_at     TEXT    DEFAULT NULL,
        updated_at      TEXT    DEFAULT NULL
    )');
}

/**
 * Migrate schema for V4 → V5: add achievement_applications table (Phase 3).
 */
function migrateToV5(PDO $pdo): void
{
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='achievement_applications'");
    if ($result->fetch()) {
        return; // Already migrated
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS achievement_applications (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id     INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        description     TEXT    NOT NULL,
        quantitative    TEXT    DEFAULT \'\',
        dim_saving      INTEGER NOT NULL DEFAULT 0 CHECK(dim_saving BETWEEN 0 AND 3),
        dim_impact      INTEGER NOT NULL DEFAULT 0 CHECK(dim_impact BETWEEN 0 AND 4),
        dim_replicate   INTEGER NOT NULL DEFAULT 0 CHECK(dim_replicate BETWEEN 0 AND 3),
        total_score     REAL    NOT NULL DEFAULT 0,
        status          TEXT    NOT NULL DEFAULT \'pending\' CHECK(status IN (\'pending\',\'approved\',\'rejected\')),
        review_comment  TEXT    DEFAULT \'\',
        operator        TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at      TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
        reviewed_at     TEXT    DEFAULT NULL
    )');
}

/**
 * Migrate schema for V5 → V6: add attachment column to achievement_applications.
 */
function migrateToV6(PDO $pdo): void
{
    $info = $pdo->query("PRAGMA table_info(achievement_applications)")->fetchAll();
    foreach ($info as $col) {
        if ($col['name'] === 'attachment') {
            return; // Already migrated
        }
    }
    $pdo->exec("ALTER TABLE achievement_applications ADD COLUMN attachment TEXT DEFAULT ''");
}

/**
 * Migrate schema for V6 → V7: add achievement_edit_logs table (Phase 3 edit tracking).
 */
function migrateToV7(PDO $pdo): void
{
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='achievement_edit_logs'");
    if ($result->fetch()) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS achievement_edit_logs (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id   INTEGER NOT NULL REFERENCES achievement_applications(id) ON DELETE CASCADE,
        changes_json     TEXT    NOT NULL,
        operator         TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at       TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');
}

/**
 * Migrate schema for V7 → V8: add updated_at column to achievement_applications.
 */
function migrateToV8(PDO $pdo): void
{
    $info = $pdo->query("PRAGMA table_info(achievement_applications)")->fetchAll();
    foreach ($info as $col) {
        if ($col['name'] === 'updated_at') {
            return;
        }
    }
    $pdo->exec("ALTER TABLE achievement_applications ADD COLUMN updated_at TEXT DEFAULT NULL");
}

/**
 * Migrate schema for V8 → V9: add exchange_records table (Phase 4).
 */
function migrateToV9(PDO $pdo): void
{
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='exchange_records'");
    if ($result->fetch()) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS exchange_records (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id     INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        exchange_type   TEXT    NOT NULL CHECK(exchange_type IN (\'holiday\',\'goods\')),
        points_cost     REAL    NOT NULL CHECK(points_cost > 0),
        description     TEXT    DEFAULT \'\',
        operator        TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at      TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');
}

/**
 * Migrate schema for V9 → V10: add prizes table.
 */
function migrateToV10(PDO $pdo): void
{
    $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='prizes'");
    if ($result->fetch()) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS prizes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        points_cost REAL    NOT NULL CHECK(points_cost > 0),
        stock       INTEGER DEFAULT NULL,
        description TEXT    DEFAULT \'\',
        status      TEXT    NOT NULL DEFAULT \'active\' CHECK(status IN (\'active\',\'inactive\')),
        created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');
}

/**
 * Migrate schema for V1 → V2: remove join_date, add phone/email/status columns.
 */
function migrateSchema(PDO $pdo): void
{
    // Check if this is a V1 database (has join_date column)
    $cols = [];
    $result = $pdo->query("PRAGMA table_info(employees)");
    foreach ($result as $row) {
        $cols[] = $row['name'];
    }

    // V1 → V2 migration: recreate table without join_date, add phone/email/status
    if (in_array('join_date', $cols) && !in_array('phone', $cols)) {
        $pdo->exec('BEGIN TRANSACTION');

        // Create new table
        $pdo->exec('CREATE TABLE employees_new (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT    NOT NULL,
            department_id INTEGER NOT NULL REFERENCES departments(id),
            phone         TEXT    DEFAULT \'\',
            email         TEXT    DEFAULT \'\',
            ai_level      TEXT    NOT NULL DEFAULT \'L0\'
                                  CHECK(ai_level IN (\'L0\',\'L1\',\'L2\',\'L3\',\'L4\')),
            status        TEXT    NOT NULL DEFAULT \'在职\'
                                  CHECK(status IN (\'在职\',\'离职\')),
            created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
            updated_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
        )');

        // Copy data (drop join_date, add defaults for new columns)
        $pdo->exec("INSERT INTO employees_new (id, name, department_id, ai_level, created_at, updated_at)
                    SELECT id, name, department_id, ai_level, created_at, updated_at
                    FROM employees");

        // Swap tables
        $pdo->exec('DROP TABLE employees');
        $pdo->exec('ALTER TABLE employees_new RENAME TO employees');

        $pdo->exec('COMMIT');
    }
}

/**
 * Create tables and seed departments.
 */
function initSchema(PDO $pdo): void
{
    // Departments table
    $pdo->exec('CREATE TABLE IF NOT EXISTS departments (
        id   INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT    NOT NULL UNIQUE
    )');

    // Migration flag table
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (
        version INTEGER NOT NULL
    )');

    // Employees table (V2 — removed join_date, added phone & email)
    $pdo->exec('CREATE TABLE IF NOT EXISTS employees (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        department_id INTEGER NOT NULL REFERENCES departments(id),
        phone         TEXT    DEFAULT \'\',
        email         TEXT    DEFAULT \'\',
        ai_level      TEXT    NOT NULL DEFAULT \'L0\'
                              CHECK(ai_level IN (\'L0\',\'L1\',\'L2\',\'L3\',\'L4\')),
        status        TEXT    NOT NULL DEFAULT \'在职\'
                              CHECK(status IN (\'在职\',\'离职\')),
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
        updated_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Migrate: add missing columns if upgrading from V1 schema
    migrateSchema($pdo);

    // ========================
    // Phase 2 tables — Points System
    // ========================

    // Unified points transaction log
    $pdo->exec('CREATE TABLE IF NOT EXISTS points_log (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        type          TEXT    NOT NULL CHECK(type IN (\'survey\',\'training\',\'exam\',\'achievement\')),
        points        REAL    NOT NULL,
        description   TEXT    DEFAULT \'\',
        operator      TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Training activities
    $pdo->exec('CREATE TABLE IF NOT EXISTS training_activities (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        train_date    TEXT    NOT NULL,
        duration      REAL    NOT NULL CHECK(duration >= 0.5 AND duration <= 4.0),
        operator      TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Training attendance (simplified: who attended which training)
    $pdo->exec('CREATE TABLE IF NOT EXISTS training_attendance (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        training_id   INTEGER NOT NULL REFERENCES training_activities(id) ON DELETE CASCADE,
        employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        UNIQUE(training_id, employee_id)
    )');

    // Exam records
    $pdo->exec('CREATE TABLE IF NOT EXISTS exam_records (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        exam_level    TEXT    NOT NULL CHECK(exam_level IN (\'L1\',\'L2\',\'L3\',\'L4\')),
        passed_date   TEXT    NOT NULL DEFAULT (date(\'now\',\'localtime\')),
        operator      TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
        UNIQUE(employee_id, exam_level)
    )');

    // Achievement applications (Phase 3)
    $pdo->exec('CREATE TABLE IF NOT EXISTS achievement_applications (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id     INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        description     TEXT    NOT NULL,
        quantitative    TEXT    DEFAULT \'\',
        dim_saving      INTEGER NOT NULL DEFAULT 0 CHECK(dim_saving BETWEEN 0 AND 3),
        dim_impact      INTEGER NOT NULL DEFAULT 0 CHECK(dim_impact BETWEEN 0 AND 4),
        dim_replicate   INTEGER NOT NULL DEFAULT 0 CHECK(dim_replicate BETWEEN 0 AND 3),
        total_score     REAL    NOT NULL DEFAULT 0,
        status          TEXT    NOT NULL DEFAULT \'pending\' CHECK(status IN (\'pending\',\'approved\',\'rejected\')),
        review_comment  TEXT    DEFAULT \'\',
        attachment      TEXT    DEFAULT \'\',
        operator        TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at      TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
        reviewed_at     TEXT    DEFAULT NULL,
        updated_at      TEXT    DEFAULT NULL
    )');

    // Achievement edit logs (Phase 3)
    $pdo->exec('CREATE TABLE IF NOT EXISTS achievement_edit_logs (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id   INTEGER NOT NULL REFERENCES achievement_applications(id) ON DELETE CASCADE,
        changes_json     TEXT    NOT NULL,
        operator         TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at       TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Exchange records (Phase 4)
    $pdo->exec('CREATE TABLE IF NOT EXISTS exchange_records (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id     INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
        exchange_type   TEXT    NOT NULL CHECK(exchange_type IN (\'holiday\',\'goods\')),
        points_cost     REAL    NOT NULL CHECK(points_cost > 0),
        description     TEXT    DEFAULT \'\',
        operator        TEXT    NOT NULL DEFAULT \'FDE工程师\',
        created_at      TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Prizes (Phase 4)
    $pdo->exec('CREATE TABLE IF NOT EXISTS prizes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        points_cost REAL    NOT NULL CHECK(points_cost > 0),
        stock       INTEGER DEFAULT NULL,
        description TEXT    DEFAULT \'\',
        status      TEXT    NOT NULL DEFAULT \'active\' CHECK(status IN (\'active\',\'inactive\')),
        created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\',\'localtime\'))
    )');

    // Seed 39 departments
    $departments = [
        '总经办', '人事部', '财务部', '采购部', '营运部', '培训部', 'IT部',
        '市场部', '品控部', '研发部', '仓储物流部', '工程部', '法务部',
        '行政部', '客服部', '外卖运营部', '门店管理部', '加盟管理部',
        '食品安全部', '供应链管理部', '品牌部', '新媒体运营部', '数据分析部',
        '区域管理一部', '区域管理二部', '区域管理三部',
        '深圳一店', '深圳二店', '深圳三店',
        '广州一店', '广州二店', '广州三店',
        '东莞一店', '东莞二店',
        '佛山一店', '惠州一店', '中山一店', '珠海一店', '江门一店',
    ];

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO departments (name) VALUES (:name)');
    foreach ($departments as $dept) {
        $stmt->execute([':name' => $dept]);
    }
}
