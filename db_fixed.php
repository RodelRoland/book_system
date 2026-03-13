<?php

mysqli_report(MYSQLI_REPORT_OFF);

// Database configuration
$db_user = "root";
$db_pass = "";
$db_name = "book_distribution_system";

// Connection attempts - try MySQL 8.0 first, then MariaDB
$connection_attempts = [
    ['host' => '127.0.0.1', 'port' => 3307, 'type' => 'MySQL 8.0'],
    ['host' => '127.0.0.1', 'port' => 3306, 'type' => 'MariaDB'],
    ['host' => 'localhost', 'port' => 3306, 'type' => 'MariaDB Localhost'],
];

$conn = null;
$last_error = '';
$connected_to = '';

foreach ($connection_attempts as $attempt) {
    try {
        $conn = new mysqli($attempt['host'], $db_user, $db_pass, $db_name, $attempt['port']);
        if (!$conn->connect_error) {
            $connected_to = $attempt['type'];
            break;
        }
        $last_error = $attempt['type'] . ': ' . $conn->connect_error;
        $conn = null;
    } catch (Exception $e) {
        $last_error = $attempt['type'] . ': ' . $e->getMessage();
    }
}

if (!$conn) {
    die("Database connection failed. Tried MySQL 8.0 and MariaDB. Error: $last_error");
}

// CSRF token functions
function csrf_get_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!headers_sent()) {
            @session_start();
        }
    }

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $_SESSION['csrf_token'] = bin2hex((string) microtime(true) . (string) mt_rand());
        }
    }

    return $_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

// Helper function to add missing columns
function ensure_column_exists(mysqli $conn, string $table, string $column, string $definition): void {
    $dbRes = $conn->query("SELECT DATABASE() AS db");
    if (!$dbRes || $dbRes->num_rows !== 1) {
        return;
    }
    $db = $dbRes->fetch_assoc()['db'] ?? '';
    if ($db === '') {
        return;
    }

    $tstmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    if (!$tstmt) {
        return;
    }
    $tstmt->bind_param('ss', $db, $table);
    $tstmt->execute();
    $tres = $tstmt->get_result();
    if (!$tres || $tres->num_rows !== 1) {
        return;
    }
    if (intval($tres->fetch_assoc()['c'] ?? 0) <= 0) {
        return;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param("sss", $db, $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $exists = intval($res->fetch_assoc()['c']) > 0;
        if (!$exists) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

// Create tables
$conn->query("CREATE TABLE IF NOT EXISTS semesters (
    semester_id INT AUTO_INCREMENT PRIMARY KEY,
    semester_name VARCHAR(30) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_semester_name (semester_name)
)");

$conn->query("CREATE TABLE IF NOT EXISTS app_meta (
    meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
    meta_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Migrations
$migrations_key = 'migrations_2026_02_06';
$migrations_done = false;
$mres = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($mres) {
    $mres->bind_param('s', $migrations_key);
    $mres->execute();
    $mrow = $mres->get_result();
    if ($mrow && $mrow->num_rows === 1) {
        $migrations_done = ($mrow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$migrations_done) {
    ensure_column_exists($conn, 'requests', 'semester_id', 'INT NULL');
    ensure_column_exists($conn, 'books_received', 'semester_id', 'INT NULL');
    ensure_column_exists($conn, 'lecturer_payments', 'semester_id', 'INT NULL');
    ensure_column_exists($conn, 'requests', 'credit_used', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
    ensure_column_exists($conn, 'requests', 'admin_id', 'INT NULL');
    ensure_column_exists($conn, 'books_received', 'admin_id', 'INT NULL');
    ensure_column_exists($conn, 'lecturer_payments', 'admin_id', 'INT NULL');
    ensure_column_exists($conn, 'students', 'admin_id', 'INT NULL');
    ensure_column_exists($conn, 'books', 'admin_id', 'INT NULL');

    $conn->query("CREATE TABLE IF NOT EXISTS admins (
        admin_id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(30) NOT NULL,
        class_name VARCHAR(30) NULL,
        role ENUM('super_admin', 'rep') NOT NULL DEFAULT 'rep',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    ensure_column_exists($conn, 'admins', 'password_hash', 'VARCHAR(255) NOT NULL DEFAULT ""');
    ensure_column_exists($conn, 'admins', 'full_name', 'VARCHAR(30) NOT NULL DEFAULT ""');
    ensure_column_exists($conn, 'admins', 'class_name', 'VARCHAR(30) NULL');
    ensure_column_exists($conn, 'admins', 'role', "ENUM('super_admin', 'rep') NOT NULL DEFAULT 'rep'");
    ensure_column_exists($conn, 'admins', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensure_column_exists($conn, 'admins', 'access_code', 'VARCHAR(4) NULL');
    ensure_column_exists($conn, 'admins', 'access_code_expires', 'DATETIME NULL');
    ensure_column_exists($conn, 'admins', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    ensure_column_exists($conn, 'admins', 'momo_number', 'VARCHAR(10) NULL');
    ensure_column_exists($conn, 'admins', 'account_number', 'VARCHAR(20) NULL');
    ensure_column_exists($conn, 'admins', 'account_name', 'VARCHAR(30) NULL');
    ensure_column_exists($conn, 'admins', 'bank_name', 'VARCHAR(30) NULL');
    ensure_column_exists($conn, 'admins', 'first_time_code', 'VARCHAR(4) NULL');
    ensure_column_exists($conn, 'admins', 'first_time_code_expires', 'DATETIME NULL');
    ensure_column_exists($conn, 'admins', 'requires_password_reset', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column_exists($conn, 'admins', 'approved_at', 'DATETIME NULL');

    $conn->query("CREATE TABLE IF NOT EXISTS rep_signup_requests (
        signup_id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        full_name VARCHAR(30) NOT NULL,
        class_name VARCHAR(30) NULL,
        momo_number VARCHAR(10) NULL,
        bank_name VARCHAR(30) NULL,
        account_name VARCHAR(30) NULL,
        account_number VARCHAR(20) NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved_at DATETIME NULL,
        approved_by_admin_id INT NULL,
        created_admin_id INT NULL,
        UNIQUE KEY uq_rep_signup_username (username),
        KEY idx_rep_signup_status (status),
        KEY idx_rep_signup_created_at (created_at),
        CONSTRAINT fk_rep_signup_approved_by FOREIGN KEY (approved_by_admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL,
        CONSTRAINT fk_rep_signup_created_admin FOREIGN KEY (created_admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS class_students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        index_number VARCHAR(50) NOT NULL,
        student_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_admin_index (admin_id, index_number),
        FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE
    )");

    $check_admin = $conn->query("SELECT admin_id, password_hash FROM admins WHERE username = 'Roland'");
    if ($check_admin && $check_admin->num_rows === 0) {
        $default_hash = password_hash('Rodel1234', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO admins (username, password_hash, full_name, role) VALUES ('Roland', ?, 'Roland Kitsi', 'super_admin')");
        $stmt->bind_param("s", $default_hash);
        $stmt->execute();
    } elseif ($check_admin && $check_admin->num_rows === 1) {
        $row = $check_admin->fetch_assoc();
        if (empty($row['password_hash'])) {
            $default_hash = password_hash('Rodel1234', PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE admins SET password_hash = ?, full_name = 'Roland Kitsi', role = 'super_admin' WHERE username = 'Roland'");
            $stmt->bind_param("s", $default_hash);
            $stmt->execute();
        }
    }

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $migrations_key);
        $stmt->execute();
    }
}

// Get active semester
function get_active_semester_id(mysqli $conn): int {
    $res = $conn->query("SELECT semester_id FROM semesters WHERE is_active = 1 ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        return intval($res->fetch_assoc()['semester_id']);
    }

    $res = $conn->query("SELECT semester_id FROM semesters ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        $id = intval($res->fetch_assoc()['semester_id']);
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE semester_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
        } else {
            $conn->query("UPDATE semesters SET is_active = 1 WHERE semester_id = " . intval($id));
        }
        return $id;
    }

    $name = 'Default Semester';
    $stmt = $conn->prepare("INSERT INTO semesters (semester_name, is_active) VALUES (?, 1)");
    if ($stmt) {
        $stmt->bind_param("s", $name);
        $stmt->execute();
        return intval($conn->insert_id);
    }

    die("Failed to create default semester. Database error: " . $conn->error);
}

$ACTIVE_SEMESTER_ID = get_active_semester_id($conn);

// Additional migrations (simplified versions)
$balance_returns_key = 'balance_returns_2026_02_06';
$balance_returns_done = false;
$bres = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($bres) {
    $bres->bind_param('s', $balance_returns_key);
    $bres->execute();
    $brow = $bres->get_result();
    if ($brow && $brow->num_rows === 1) {
        $balance_returns_done = ($brow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$balance_returns_done) {
    $conn->query("CREATE TABLE IF NOT EXISTS balance_returns (
        return_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        request_id INT NULL,
        amount DECIMAL(10,2) NOT NULL,
        return_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes VARCHAR(255) NULL,
        INDEX idx_balance_returns_student (student_id),
        INDEX idx_balance_returns_request (request_id)
    )");

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $balance_returns_key);
        $stmt->execute();
    }
}

// Request items received_at column
$request_items_received_at_key = 'request_items_received_at_2026_02_20';
$request_items_received_at_done = false;
$rrat = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($rrat) {
    $rrat->bind_param('s', $request_items_received_at_key);
    $rrat->execute();
    $rrow = $rrat->get_result();
    if ($rrow && $rrow->num_rows === 1) {
        $request_items_received_at_done = ($rrow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$request_items_received_at_done) {
    ensure_column_exists($conn, 'request_items', 'received_at', 'DATETIME NULL');
    
    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $request_items_received_at_key);
        $stmt->execute();
    }
}

// Lecturer portal setup
$lecturer_portal_key = 'lecturer_portal_2026_02_07';
$lecturer_portal_done = false;
$lpres = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($lpres) {
    $lpres->bind_param('s', $lecturer_portal_key);
    $lpres->execute();
    $lprow = $lpres->get_result();
    if ($lprow && $lprow->num_rows === 1) {
        $lecturer_portal_done = ($lprow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$lecturer_portal_done) {
    $conn->query("CREATE TABLE IF NOT EXISTS lecturers (
        lecturer_id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS lecturer_books (
        lecturer_id INT NOT NULL,
        book_id INT NOT NULL,
        assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (lecturer_id, book_id),
        CONSTRAINT fk_lecturer_books_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE CASCADE,
        CONSTRAINT fk_lecturer_books_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS lecturer_distributions (
        distribution_id INT AUTO_INCREMENT PRIMARY KEY,
        lecturer_id INT NOT NULL,
        book_id INT NOT NULL,
        rep_admin_id INT NULL,
        copies_given INT NOT NULL,
        given_date DATE NOT NULL,
        notes VARCHAR(255) NULL,
        semester_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lecturer_dist_lecturer_sem (lecturer_id, semester_id),
        INDEX idx_lecturer_dist_book_sem (book_id, semester_id),
        INDEX idx_lecturer_dist_rep_sem (rep_admin_id, semester_id),
        CONSTRAINT fk_lecturer_dist_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE CASCADE,
        CONSTRAINT fk_lecturer_dist_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
        CONSTRAINT fk_lecturer_dist_rep FOREIGN KEY (rep_admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL
    )");

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $lecturer_portal_key);
        $stmt->execute();
    }
}

// Load cache helper if exists
if (file_exists(__DIR__ . '/cache_helper.php')) {
    require_once __DIR__ . '/cache_helper.php';
}

// Indexes
$indexes_key = 'indexes_2026_02_06';
$indexes_done = false;
$ires = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($ires) {
    $ires->bind_param('s', $indexes_key);
    $ires->execute();
    $irow = $ires->get_result();
    if ($irow && $irow->num_rows === 1) {
        $indexes_done = ($irow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$indexes_done && function_exists('ensure_db_indexes')) {
    ensure_db_indexes($conn);
    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $indexes_key);
        $stmt->execute();
    }
}

echo "Connected to: $connected_to\n";
?>
