<?php

mysqli_report(MYSQLI_REPORT_OFF);

$db_hosts = ["127.0.0.1", "localhost"];
$db_ports = [3306];
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

$conn->query("CREATE TABLE IF NOT EXISTS app_meta (
    meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
    meta_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

    $conn->query("CREATE TABLE IF NOT EXISTS class_students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        index_number VARCHAR(50) NOT NULL,
        student_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_admin_index (admin_id, index_number),
        FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE
    )");
}

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

if (!$migrations_done) {
    $sid = intval($ACTIVE_SEMESTER_ID);

    $stmt = $conn->prepare("UPDATE requests SET semester_id = ? WHERE semester_id IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $sid);
        $stmt->execute();
    }
    $stmt = $conn->prepare("UPDATE books_received SET semester_id = ? WHERE semester_id IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $sid);
        $stmt->execute();
    }
    $stmt = $conn->prepare("UPDATE lecturer_payments SET semester_id = ? WHERE semester_id IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $sid);
        $stmt->execute();
    }

    $default_admin_id = 1;
    $stmt = $conn->prepare("UPDATE requests SET admin_id = ? WHERE admin_id IS NULL OR admin_id = 0");
    if ($stmt) {
        $stmt->bind_param('i', $default_admin_id);
        $stmt->execute();
    }
    $stmt = $conn->prepare("UPDATE books_received SET admin_id = ? WHERE admin_id IS NULL OR admin_id = 0");
    if ($stmt) {
        $stmt->bind_param('i', $default_admin_id);
        $stmt->execute();
    }
    $stmt = $conn->prepare("UPDATE lecturer_payments SET admin_id = ? WHERE admin_id IS NULL OR admin_id = 0");
    if ($stmt) {
        $stmt->bind_param('i', $default_admin_id);
        $stmt->execute();
    }

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $migrations_key);
        $stmt->execute();
    }
}

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

$books_received_key = 'books_received_table_2026_02_06';
$books_received_done = false;
$rres = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($rres) {
    $rres->bind_param('s', $books_received_key);
    $rres->execute();
    $rrow = $rres->get_result();
    if ($rrow && $rrow->num_rows === 1) {
        $books_received_done = ($rrow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$books_received_done) {
    $conn->query("CREATE TABLE IF NOT EXISTS books_received (
        receive_id      INT AUTO_INCREMENT PRIMARY KEY,
        book_id         INT NOT NULL,
        copies_received INT NOT NULL,
        receive_date    DATE NOT NULL,
        lecturer_name   VARCHAR(100) NULL,
        notes           VARCHAR(255) NULL,
        semester_id     INT NULL,
        admin_id        INT NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
    )");

    ensure_column_exists($conn, 'books_received', 'semester_id', 'INT NULL');
    ensure_column_exists($conn, 'books_received', 'admin_id', 'INT NULL');

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $books_received_key);
        $stmt->execute();
    }
}

$books_received_unit_price_key = 'books_received_unit_price_2026_02_13';
$books_received_unit_price_done = false;
$upr = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($upr) {
    $upr->bind_param('s', $books_received_unit_price_key);
    $upr->execute();
    $uprow = $upr->get_result();
    if ($uprow && $uprow->num_rows === 1) {
        $books_received_unit_price_done = ($uprow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$books_received_unit_price_done) {
    ensure_column_exists($conn, 'books_received', 'unit_price', 'DECIMAL(10,2) NULL');
    $conn->query("UPDATE books_received br JOIN books b ON br.book_id = b.book_id SET br.unit_price = b.price WHERE br.unit_price IS NULL");

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $books_received_unit_price_key);
        $stmt->execute();
    }
}

$book_price_history_key = 'book_price_history_2026_02_13';
$book_price_history_done = false;
$phs = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($phs) {
    $phs->bind_param('s', $book_price_history_key);
    $phs->execute();
    $phrow = $phs->get_result();
    if ($phrow && $phrow->num_rows === 1) {
        $book_price_history_done = ($phrow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$book_price_history_done) {
    $conn->query("CREATE TABLE IF NOT EXISTS book_price_history (
        history_id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        old_price DECIMAL(10,2) NOT NULL,
        new_price DECIMAL(10,2) NOT NULL,
        changed_by_admin_id INT NULL,
        notes VARCHAR(255) NULL,
        changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_book_price_history_book (book_id, changed_at),
        CONSTRAINT fk_book_price_history_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
    )");

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $book_price_history_key);
        $stmt->execute();
    }
}

$book_price_effective_key = 'book_price_effective_2026_02_13';
$book_price_effective_done = false;
$pe = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($pe) {
    $pe->bind_param('s', $book_price_effective_key);
    $pe->execute();
    $perow = $pe->get_result();
    if ($perow && $perow->num_rows === 1) {
        $book_price_effective_done = ($perow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$book_price_effective_done) {
    ensure_column_exists($conn, 'book_price_history', 'effective_date', 'DATE NULL');
    ensure_column_exists($conn, 'book_price_history', 'applied_at', 'TIMESTAMP NULL');

    $conn->query("UPDATE book_price_history SET effective_date = DATE(changed_at) WHERE effective_date IS NULL");
    $conn->query("UPDATE book_price_history SET applied_at = changed_at WHERE applied_at IS NULL");

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $book_price_effective_key);
        $stmt->execute();
    }
}

$request_items_unit_price_key = 'request_items_unit_price_2026_02_13';
$request_items_unit_price_done = false;
$ripu = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
if ($ripu) {
    $ripu->bind_param('s', $request_items_unit_price_key);
    $ripu->execute();
    $riprow = $ripu->get_result();
    if ($riprow && $riprow->num_rows === 1) {
        $request_items_unit_price_done = ($riprow->fetch_assoc()['meta_value'] ?? '') === '1';
    }
}

if (!$request_items_unit_price_done) {
    $dbRes2 = $conn->query("SELECT DATABASE() AS db");
    $db2 = ($dbRes2 && $dbRes2->num_rows === 1) ? ($dbRes2->fetch_assoc()['db'] ?? '') : '';

    $has_requests = false;
    $has_request_items = false;
    if ($db2 !== '') {
        $t1 = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'requests'");
        if ($t1) {
            $t1->bind_param('s', $db2);
            $t1->execute();
            $r1 = $t1->get_result();
            $has_requests = ($r1 && $r1->num_rows === 1) ? (intval($r1->fetch_assoc()['c'] ?? 0) > 0) : false;
        }
        $t2 = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'request_items'");
        if ($t2) {
            $t2->bind_param('s', $db2);
            $t2->execute();
            $r2 = $t2->get_result();
            $has_request_items = ($r2 && $r2->num_rows === 1) ? (intval($r2->fetch_assoc()['c'] ?? 0) > 0) : false;
        }
    }

    if ($has_requests && $has_request_items) {
        ensure_column_exists($conn, 'request_items', 'unit_price', 'DECIMAL(10,2) NULL');

        $conn->query("UPDATE request_items ri\n            JOIN requests r ON r.request_id = ri.request_id\n            SET ri.unit_price = COALESCE(\n                (SELECT bph.new_price\n                    FROM book_price_history bph\n                    WHERE bph.book_id = ri.book_id\n                      AND bph.effective_date IS NOT NULL\n                      AND bph.effective_date <= DATE(r.created_at)\n                    ORDER BY bph.effective_date DESC, bph.history_id DESC\n                    LIMIT 1\n                ),\n                (SELECT bph.old_price\n                    FROM book_price_history bph\n                    WHERE bph.book_id = ri.book_id\n                      AND bph.effective_date IS NOT NULL\n                      AND bph.effective_date > DATE(r.created_at)\n                    ORDER BY bph.effective_date ASC, bph.history_id ASC\n                    LIMIT 1\n                ),\n                (SELECT b.price FROM books b WHERE b.book_id = ri.book_id LIMIT 1)\n            )\n            WHERE ri.unit_price IS NULL");

        $conn->query("UPDATE requests r\n            JOIN (SELECT request_id, SUM(COALESCE(unit_price, 0)) AS total_amount_calc FROM request_items GROUP BY request_id) x\n                ON x.request_id = r.request_id\n            SET r.total_amount = x.total_amount_calc\n            WHERE ABS(COALESCE(r.total_amount,0) - COALESCE(x.total_amount_calc,0)) > 0.0001");

        $conn->query("UPDATE requests r\n            JOIN (SELECT request_id, SUM(COALESCE(unit_price, 0)) AS total_amount_calc FROM request_items GROUP BY request_id) x\n                ON x.request_id = r.request_id\n            SET r.payment_status = CASE WHEN (COALESCE(r.amount_paid,0) + COALESCE(r.credit_used,0)) >= COALESCE(x.total_amount_calc,0) THEN 'paid' ELSE 'unpaid' END");

        $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
        if ($stmt) {
            $stmt->bind_param('s', $request_items_unit_price_key);
            $stmt->execute();
        }
    }
}

// Add received_at column to request_items for tracking actual book collection dates
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

$apply_scheduled_stmt = $conn->prepare("SELECT history_id, book_id, new_price, effective_date FROM book_price_history WHERE applied_at IS NULL AND effective_date IS NOT NULL AND effective_date <= CURDATE() ORDER BY effective_date ASC, history_id ASC LIMIT 50");
if ($apply_scheduled_stmt) {
    $apply_scheduled_stmt->execute();
    $apply_res = $apply_scheduled_stmt->get_result();
    $apply_scheduled_stmt->close();
    if ($apply_res && $apply_res->num_rows > 0) {
        $upd_book = $conn->prepare("UPDATE books SET price = ? WHERE book_id = ?");
        $mark_applied = $conn->prepare("UPDATE book_price_history SET applied_at = NOW() WHERE history_id = ?");
        $upd_req_items = $conn->prepare("UPDATE request_items ri\n            JOIN requests r ON r.request_id = ri.request_id\n            SET ri.unit_price = ?\n            WHERE ri.book_id = ?\n              AND r.payment_status = 'unpaid'\n              AND r.semester_id = ?\n              AND DATE(r.created_at) >= ?");
        $recalc_unpaid = false;
        $sid = intval($ACTIVE_SEMESTER_ID ?? 0);
        while ($pr = $apply_res->fetch_assoc()) {
            $hid = intval($pr['history_id']);
            $bid = intval($pr['book_id']);
            $nprice = floatval($pr['new_price']);
            $eff = trim(strval($pr['effective_date'] ?? ''));
            if ($hid <= 0 || $bid <= 0) {
                continue;
            }
            if ($upd_book) {
                $upd_book->bind_param('di', $nprice, $bid);
                $upd_book->execute();
            }
            if ($upd_req_items && $sid > 0 && $eff !== '') {
                $upd_req_items->bind_param('diis', $nprice, $bid, $sid, $eff);
                $upd_req_items->execute();
                $recalc_unpaid = true;
            }
            if ($mark_applied) {
                $mark_applied->bind_param('i', $hid);
                $mark_applied->execute();
            }
        }

        if ($recalc_unpaid && $sid > 0) {
            $conn->query("UPDATE requests r\n                JOIN (SELECT request_id, SUM(COALESCE(unit_price, 0)) AS total_amount_calc FROM request_items GROUP BY request_id) x\n                    ON x.request_id = r.request_id\n                SET r.total_amount = x.total_amount_calc,\n                    r.payment_status = CASE WHEN (COALESCE(r.amount_paid,0) + COALESCE(r.credit_used,0)) >= COALESCE(x.total_amount_calc,0) THEN 'paid' ELSE 'unpaid' END\n                WHERE r.semester_id = " . intval($sid) . " AND r.payment_status = 'unpaid'");
        }
    }
}

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

if (file_exists(__DIR__ . '/cache_helper.php')) {
    require_once __DIR__ . '/cache_helper.php';
}

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

$credit_reconcile_key = 'credit_reconcile_2026_02_07';
$credit_reconcile_done = true;

if (!$credit_reconcile_done) {
    $sid = intval($ACTIVE_SEMESTER_ID);
    $batch_size = 200;
    $max_batches = 10;

    for ($batch = 0; $batch < $max_batches; $batch++) {
        $sel = $conn->prepare("SELECT r.request_id, r.student_id
            FROM requests r
            JOIN students s ON r.student_id = s.student_id
            WHERE r.payment_status = 'unpaid'
              AND r.semester_id = ?
              AND s.credit_balance > 0
            ORDER BY r.created_at ASC
            LIMIT ?");
        if (!$sel) {
            break;
        }
        $sel->bind_param('ii', $sid, $batch_size);
        $sel->execute();
        $rows = $sel->get_result();
        if (!$rows || $rows->num_rows === 0) {
            break;
        }

        while ($rr = $rows->fetch_assoc()) {
            $request_id = intval($rr['request_id']);
            $student_id = intval($rr['student_id']);
            if ($request_id <= 0 || $student_id <= 0) {
                continue;
            }

            $conn->begin_transaction();
            try {
                $slock = $conn->prepare("SELECT credit_balance FROM students WHERE student_id = ? FOR UPDATE");
                $slock->bind_param('i', $student_id);
                $slock->execute();
                $sres = $slock->get_result();
                $srow = ($sres && $sres->num_rows === 1) ? $sres->fetch_assoc() : null;
                $credit_balance = $srow ? floatval($srow['credit_balance']) : 0.0;

                $rlock = $conn->prepare("SELECT total_amount, amount_paid, credit_used, payment_status FROM requests WHERE request_id = ? FOR UPDATE");
                $rlock->bind_param('i', $request_id);
                $rlock->execute();
                $rres = $rlock->get_result();
                $rrow = ($rres && $rres->num_rows === 1) ? $rres->fetch_assoc() : null;
                if (!$rrow) {
                    $conn->rollback();
                    continue;
                }

                $total_amount = floatval($rrow['total_amount']);
                $amount_paid = floatval($rrow['amount_paid']);
                $credit_used = floatval($rrow['credit_used'] ?? 0);
                $payment_status = $rrow['payment_status'] ?? 'unpaid';

                $due_after_credit = max(0.0, $total_amount - $credit_used);
                $remaining_due = max(0.0, $due_after_credit - $amount_paid);

                if ($remaining_due <= 0.00001) {
                    if ($payment_status !== 'paid') {
                        $upd = $conn->prepare("UPDATE requests SET payment_status = 'paid' WHERE request_id = ?");
                        $upd->bind_param('i', $request_id);
                        $upd->execute();
                    }
                    $conn->commit();
                    continue;
                }

                if ($credit_balance <= 0.00001) {
                    $conn->commit();
                    continue;
                }

                $apply = min($credit_balance, $remaining_due);
                if ($apply <= 0.00001) {
                    $conn->commit();
                    continue;
                }

                $new_credit_used = $credit_used + $apply;
                $new_student_balance = $credit_balance - $apply;
                $new_due_after_credit = max(0.0, $total_amount - $new_credit_used);
                $new_status = ($amount_paid >= $new_due_after_credit) ? 'paid' : 'unpaid';

                $updr = $conn->prepare("UPDATE requests SET credit_used = ?, payment_status = ? WHERE request_id = ?");
                $updr->bind_param('dsi', $new_credit_used, $new_status, $request_id);
                $updr->execute();

                $upds = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ?");
                $upds->bind_param('di', $new_student_balance, $student_id);
                $upds->execute();

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
            }
        }
    }

    $check = $conn->prepare("SELECT 1
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        WHERE r.payment_status = 'unpaid'
          AND r.semester_id = ?
          AND s.credit_balance > 0
        LIMIT 1");
    if ($check) {
        $check->bind_param('i', $sid);
        $check->execute();
        $c = $check->get_result();
        if (!$c || $c->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
            if ($stmt) {
                $stmt->bind_param('s', $credit_reconcile_key);
                $stmt->execute();
            }
        }
    }
}

