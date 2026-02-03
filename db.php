<?php

mysqli_report(MYSQLI_REPORT_OFF);

$db_hosts = ["127.0.0.1", "localhost"];
$db_ports = [3306, 3307];
$db_user = "root";
$db_pass = "";
$db_name = "book_distribution_system";

$conn = null;
$last_error = '';
foreach ($db_hosts as $h) {
    foreach ($db_ports as $p) {
        $try = @new mysqli($h, $db_user, $db_pass, $db_name, $p);
        if (!$try->connect_error) {
            $conn = $try;
            break 2;
        }
        $last_error = $try->connect_error;
    }
}

if (!$conn) {
    die("Database connection failed. Please start MySQL in XAMPP and confirm the port (3306/3307). Error: " . $last_error);
}

$conn->query("CREATE TABLE IF NOT EXISTS semesters (
    semester_id INT AUTO_INCREMENT PRIMARY KEY,
    semester_name VARCHAR(30) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_semester_name (semester_name)
)");

// Helper function to add missing columns (must be defined before use)
function ensure_column_exists(mysqli $conn, string $table, string $column, string $definition): void {
    $dbRes = $conn->query("SELECT DATABASE() AS db");
    if (!$dbRes || $dbRes->num_rows !== 1) {
        return;
    }
    $db = $dbRes->fetch_assoc()['db'] ?? '';
    if ($db === '') {
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

ensure_column_exists($conn, 'requests', 'semester_id', 'INT NULL');
ensure_column_exists($conn, 'books_received', 'semester_id', 'INT NULL');
ensure_column_exists($conn, 'lecturer_payments', 'semester_id', 'INT NULL');

// Add admin_id to scope data per rep
ensure_column_exists($conn, 'requests', 'admin_id', 'INT NULL');
ensure_column_exists($conn, 'books_received', 'admin_id', 'INT NULL');
ensure_column_exists($conn, 'lecturer_payments', 'admin_id', 'INT NULL');
ensure_column_exists($conn, 'students', 'admin_id', 'INT NULL');
ensure_column_exists($conn, 'books', 'admin_id', 'INT NULL');

// Admins table for multi-rep support
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

// Ensure admins table has all required columns (for upgrades from older schema)
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

// Create default super admin (Roland) if not exists - password: Rodel1234
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

// Class students table for rep's class roster (auto-populate student names)
$conn->query("CREATE TABLE IF NOT EXISTS class_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    index_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_index (admin_id, index_number),
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE
)");

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

    $safe_name = $conn->real_escape_string($name);
    $ok = $conn->query("INSERT INTO semesters (semester_name, is_active) VALUES ('{$safe_name}', 1)");
    if ($ok) {
        return intval($conn->insert_id);
    }

    die("Failed to create default semester. Database error: " . $conn->error);
}

$ACTIVE_SEMESTER_ID = get_active_semester_id($conn);

// Backfill existing data into the current active semester once (safe to re-run)
$sid = intval($ACTIVE_SEMESTER_ID);
$conn->query("UPDATE requests SET semester_id = $sid WHERE semester_id IS NULL");
$conn->query("UPDATE books_received SET semester_id = $sid WHERE semester_id IS NULL");
$conn->query("UPDATE lecturer_payments SET semester_id = $sid WHERE semester_id IS NULL");

// Backfill existing data with super admin's admin_id (admin_id = 1) for records without an admin_id
$conn->query("UPDATE requests SET admin_id = 1 WHERE admin_id IS NULL OR admin_id = 0");
$conn->query("UPDATE books_received SET admin_id = 1 WHERE admin_id IS NULL OR admin_id = 0");
$conn->query("UPDATE lecturer_payments SET admin_id = 1 WHERE admin_id IS NULL OR admin_id = 0");

