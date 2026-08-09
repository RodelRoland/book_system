<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_run_setup_tasks')) {
        book_system_run_setup_tasks($conn);
    }
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;
$export_scope_admin_id = $current_admin_id;
$current_admin_username = strval($access_context['effective_username'] ?? '');
$admin_filter = "AND admin_id = $current_admin_id";
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$reports_url = 'activity_log.php';

function book_system_ensure_lecturer_export_tables(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS lecturer_export_batches (
        batch_id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL DEFAULT 0,
        book_id INT NOT NULL,
        semester_id INT NOT NULL,
        export_mode ENUM('new','all') NOT NULL DEFAULT 'new',
        start_date DATE NULL,
        end_date DATE NULL,
        exported_by_username VARCHAR(50) NULL,
        exported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lecturer_export_batches_scope (admin_id, book_id, semester_id, exported_at),
        INDEX idx_lecturer_export_batches_book (book_id)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS lecturer_export_batch_items (
        batch_item_id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        student_id INT NOT NULL,
        exported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lecturer_export_batch_student (batch_id, student_id),
        INDEX idx_lecturer_export_batch_items_student (student_id),
        CONSTRAINT fk_lecturer_export_batch_items_batch FOREIGN KEY (batch_id) REFERENCES lecturer_export_batches(batch_id) ON DELETE CASCADE,
        CONSTRAINT fk_lecturer_export_batch_items_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
    )");

    $ensured = true;
}

book_system_ensure_lecturer_export_tables($conn);

function lecturer_payments_initials(string $fullName): string {
    $fullName = trim($fullName);
    if ($fullName === '') {
        return 'ST';
    }
    $parts = preg_split('/\s+/', $fullName) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) >= 2) {
            break;
        }
    }
    return $letters !== '' ? $letters : strtoupper(substr($fullName, 0, 2));
}

function lecturer_payments_prepare(mysqli $conn, string $sql, string $context): ?mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('lecturer_payments prepare failed [' . $context . ']: ' . $conn->error . ' SQL: ' . $sql);
        return null;
    }
    return $stmt;
}

$selected_book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;
$selected_book_title = '';

$selected_received_students = 0;
$selected_yet_students = 0;
$selected_total_students = 0;

$csrf_token = csrf_get_token();

$page_msg = trim(strval($_GET['msg'] ?? ''));
if ($page_msg === 'no_new_exports') {
    $success_msg = 'All currently listed students have already been exported. Use Export All if you want to send the full list again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_received'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = "Invalid request. Please refresh and try again.";
    } else {
    $book_id = intval($_POST['book_id']);
    $copies_received = intval($_POST['copies_received']);
    $receive_date = trim(strval($_POST['receive_date'] ?? ''));
    $lecturer_name = trim(strval($_POST['lecturer_name'] ?? ''));
    $notes = trim(strval($_POST['notes'] ?? ''));
    
    if ($book_id > 0 && $copies_received != 0) {
        $unit_price = null;
        $hpstmt = $conn->prepare("SELECT new_price FROM book_price_history WHERE book_id = ? AND effective_date IS NOT NULL AND effective_date <= ? ORDER BY effective_date DESC, history_id DESC LIMIT 1");
        if ($hpstmt) {
            $hpstmt->bind_param('is', $book_id, $receive_date);
            $hpstmt->execute();
            $hpres = $hpstmt->get_result();
            if ($hpres && $hpres->num_rows === 1) {
                $unit_price = floatval($hpres->fetch_assoc()['new_price']);
            }
        }

        if ($unit_price === null) {
            $hnstmt = $conn->prepare("SELECT old_price FROM book_price_history WHERE book_id = ? AND effective_date IS NOT NULL AND effective_date > ? ORDER BY effective_date ASC, history_id ASC LIMIT 1");
            if ($hnstmt) {
                $hnstmt->bind_param('is', $book_id, $receive_date);
                $hnstmt->execute();
                $hnres = $hnstmt->get_result();
                if ($hnres && $hnres->num_rows === 1) {
                    $unit_price = floatval($hnres->fetch_assoc()['old_price']);
                }
            }
        }

        if ($unit_price === null) {
            $pstmt = $conn->prepare("SELECT price FROM books WHERE book_id = ? AND semester_id = ? LIMIT 1");
            if ($pstmt) {
                $pstmt->bind_param('ii', $book_id, $semester_id);
                $pstmt->execute();
                $pres = $pstmt->get_result();
                if ($pres && $pres->num_rows === 1) {
                    $unit_price = floatval($pres->fetch_assoc()['price']);
                }
            }
        }

        if ($unit_price === null) {
            $error_msg = "Unable to determine book price for this receive record. Please refresh and try again.";
        } else {

        $stmt = $conn->prepare("INSERT INTO books_received (book_id, copies_received, unit_price, receive_date, lecturer_name, notes, semester_id, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iidsssii", $book_id, $copies_received, $unit_price, $receive_date, $lecturer_name, $notes, $semester_id, $current_admin_id);
        
        if ($stmt->execute()) {
            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'record_books_received', 'books_received', intval($conn->insert_id), [
                    'book_id' => $book_id,
                    'copies_received' => $copies_received,
                    'unit_price' => $unit_price,
                    'receive_date' => $receive_date,
                    'lecturer_name' => $lecturer_name,
                ]);
            }
            $success_msg = "Books received recorded successfully!";
        } else {
            $error_msg = "Error recording: " . $conn->error;
        }
        }
    } else {
        $error_msg = "Please fill in all required fields.";
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_received'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = "Invalid request. Please refresh and try again.";
        header('Location: lecturer_payments.php?book_id=' . intval($_POST['book_id'] ?? 0));
        exit;
    }
    $receive_id = intval($_POST['receive_id']);
    $book_id = intval($_POST['book_id']);
    $copies_received = intval($_POST['copies_received']);
    $receive_date = trim(strval($_POST['receive_date'] ?? ''));
    $lecturer_name = trim(strval($_POST['lecturer_name'] ?? ''));
    $notes = trim(strval($_POST['notes'] ?? ''));
    $unit_price = floatval($_POST['unit_price'] ?? 0);

    if ($receive_id > 0 && $book_id > 0 && $copies_received != 0) {
        if ($is_super_admin) {
            $stmt = $conn->prepare("UPDATE books_received SET copies_received = ?, unit_price = ?, receive_date = ?, lecturer_name = ?, notes = ? WHERE receive_id = ? AND book_id = ?");
            $stmt->bind_param("idsssii", $copies_received, $unit_price, $receive_date, $lecturer_name, $notes, $receive_id, $book_id);
        } else {
            $stmt = $conn->prepare("UPDATE books_received SET copies_received = ?, unit_price = ?, receive_date = ?, lecturer_name = ?, notes = ? WHERE receive_id = ? AND book_id = ? AND admin_id = ?");
            $stmt->bind_param("idsssiii", $copies_received, $unit_price, $receive_date, $lecturer_name, $notes, $receive_id, $book_id, $current_admin_id);
        }
        if ($stmt->execute()) {
            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'update_books_received', 'books_received', $receive_id, [
                    'book_id' => $book_id,
                    'copies_received' => $copies_received,
                    'unit_price' => $unit_price,
                    'receive_date' => $receive_date,
                    'lecturer_name' => $lecturer_name,
                ]);
            }
            $success_msg = "Received record updated successfully!";
        } else {
            $error_msg = "Error updating record: " . $conn->error;
        }
    } else {
        $error_msg = "Please fill in all required fields.";
    }

    header('Location: lecturer_payments.php?book_id=' . $book_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_received'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = "Invalid request. Please refresh and try again.";
        header('Location: lecturer_payments.php?book_id=' . intval($_POST['book_id'] ?? 0));
        exit;
    }
    $receive_id = intval($_POST['receive_id']);
    $book_id = intval($_POST['book_id']);

    if ($receive_id > 0 && $book_id > 0) {
        if ($is_super_admin) {
            $stmt = $conn->prepare("DELETE FROM books_received WHERE receive_id = ? AND book_id = ?");
            $stmt->bind_param("ii", $receive_id, $book_id);
        } else {
            $stmt = $conn->prepare("DELETE FROM books_received WHERE receive_id = ? AND book_id = ? AND admin_id = ?");
            $stmt->bind_param("iii", $receive_id, $book_id, $current_admin_id);
        }
        if ($stmt->execute()) {
            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'delete_books_received', 'books_received', $receive_id, [
                    'book_id' => $book_id,
                ]);
            }
            $success_msg = "Received record deleted successfully!";
        } else {
            $error_msg = "Error deleting record: " . $conn->error;
        }
    } else {
        $error_msg = "Invalid delete request.";
    }

    header('Location: lecturer_payments.php?book_id=' . $book_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = "Invalid request. Please refresh and try again.";
    } else {
        $book_id = intval($_POST['book_id']);
        $copies_paid = intval($_POST['copies_paid']);
        $amount_paid = floatval($_POST['amount_paid']);
        $payment_date = trim(strval($_POST['payment_date'] ?? ''));
        $notes = trim(strval($_POST['notes'] ?? ''));

        $same_sign = ($copies_paid > 0 && $amount_paid > 0) || ($copies_paid < 0 && $amount_paid < 0);
        if ($book_id > 0 && $copies_paid != 0 && $amount_paid != 0 && $same_sign) {
            $stmt = $conn->prepare("INSERT INTO lecturer_payments (book_id, copies_paid, amount_paid, payment_date, notes, semester_id, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iidssii", $book_id, $copies_paid, $amount_paid, $payment_date, $notes, $semester_id, $current_admin_id);

            if ($stmt->execute()) {
                if (function_exists('book_system_audit_log')) {
                    book_system_audit_log($conn, 'record_lecturer_payment', 'lecturer_payment', intval($conn->insert_id), [
                        'book_id' => $book_id,
                        'copies_paid' => $copies_paid,
                        'amount_paid' => $amount_paid,
                        'payment_date' => $payment_date,
                    ]);
                }
                $success_msg = "Payment recorded successfully!";
            } else {
                $error_msg = "Error recording payment: " . $conn->error;
            }
        } else {
            $error_msg = "Please fill in all required fields.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = "Invalid request. Please refresh and try again.";
        header('Location: lecturer_payments.php?book_id=' . intval($_POST['book_id'] ?? 0));
        exit;
    }
    $payment_id = intval($_POST['payment_id']);
    $book_id = intval($_POST['book_id']);
    $copies_paid = intval($_POST['copies_paid']);
    $amount_paid = floatval($_POST['amount_paid']);
    $payment_date = trim(strval($_POST['payment_date'] ?? ''));
    $notes = trim(strval($_POST['notes'] ?? ''));

    $same_sign = ($copies_paid > 0 && $amount_paid > 0) || ($copies_paid < 0 && $amount_paid < 0);
    if ($payment_id > 0 && $book_id > 0 && $copies_paid != 0 && $amount_paid != 0 && $same_sign) {
        if ($is_super_admin) {
            $stmt = $conn->prepare("UPDATE lecturer_payments SET copies_paid = ?, amount_paid = ?, payment_date = ?, notes = ? WHERE payment_id = ? AND book_id = ?");
            $stmt->bind_param("idssii", $copies_paid, $amount_paid, $payment_date, $notes, $payment_id, $book_id);
        } else {
            $stmt = $conn->prepare("UPDATE lecturer_payments SET copies_paid = ?, amount_paid = ?, payment_date = ?, notes = ? WHERE payment_id = ? AND book_id = ? AND admin_id = ?");
            $stmt->bind_param("idssiii", $copies_paid, $amount_paid, $payment_date, $notes, $payment_id, $book_id, $current_admin_id);
        }
        if ($stmt->execute()) {
            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'update_lecturer_payment', 'lecturer_payment', $payment_id, [
                    'book_id' => $book_id,
                    'copies_paid' => $copies_paid,
                    'amount_paid' => $amount_paid,
                    'payment_date' => $payment_date,
                ]);
            }
            $success_msg = "Payment record updated successfully!";
        } else {
            $error_msg = "Error updating payment: " . $conn->error;
        }
    } else {
        $error_msg = "Please fill in all required fields.";
    }

    header('Location: lecturer_payments.php?book_id=' . $book_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = "Invalid request. Please refresh and try again.";
        header('Location: lecturer_payments.php?book_id=' . intval($_POST['book_id'] ?? 0));
        exit;
    }
    $payment_id = intval($_POST['payment_id']);
    $book_id = intval($_POST['book_id']);

    if ($payment_id > 0 && $book_id > 0) {
        if ($is_super_admin) {
            $stmt = $conn->prepare("DELETE FROM lecturer_payments WHERE payment_id = ? AND book_id = ?");
            $stmt->bind_param("ii", $payment_id, $book_id);
        } else {
            $stmt = $conn->prepare("DELETE FROM lecturer_payments WHERE payment_id = ? AND book_id = ? AND admin_id = ?");
            $stmt->bind_param("iii", $payment_id, $book_id, $current_admin_id);
        }
        if ($stmt->execute()) {
            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'delete_lecturer_payment', 'lecturer_payment', $payment_id, [
                    'book_id' => $book_id,
                ]);
            }
            $success_msg = "Payment record deleted successfully!";
        } else {
            $error_msg = "Error deleting payment: " . $conn->error;
        }
    } else {
        $error_msg = "Invalid delete request.";
    }

    header('Location: lecturer_payments.php?book_id=' . $book_id);
    exit;
}

$requests_join = $is_super_admin
    ? "LEFT JOIN requests r ON ri.request_id = r.request_id AND r.semester_id = ?"
    : "LEFT JOIN requests r ON ri.request_id = r.request_id AND r.semester_id = ? AND r.admin_id = ?";

$books_sql = "
    SELECT 
        b.book_id,
        b.book_title,
        b.price,
        COALESCE(br.total_received, 0) as received_copies,
        COALESCE(br.total_received_value, 0) as received_value,
        COUNT(DISTINCT CASE WHEN ri.is_collected = 1 AND r.request_id IS NOT NULL THEN ri.item_id END) as sold_copies,
        COUNT(DISTINCT CASE WHEN ri.is_collected = 1 AND r.request_id IS NOT NULL AND GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0) <= (COALESCE(r.amount_paid, 0) + 0.009) THEN ri.item_id END) as sold_paid_copies,
        COALESCE(lp.total_paid_copies, 0) as lecturer_paid_copies,
        COALESCE(lp.total_paid_amount, 0) as lecturer_paid_amount
    FROM books b
    LEFT JOIN request_items ri ON b.book_id = ri.book_id
    {$requests_join}
    LEFT JOIN (
        SELECT book_id, SUM(copies_received) as total_received, SUM(copies_received * COALESCE(unit_price, 0)) as total_received_value
        FROM books_received
        WHERE semester_id = ? " . ($is_super_admin ? "" : "AND admin_id = ?") . "
        GROUP BY book_id
    ) br ON b.book_id = br.book_id
    LEFT JOIN (
        SELECT book_id, SUM(copies_paid) as total_paid_copies, SUM(amount_paid) as total_paid_amount
        FROM lecturer_payments
        WHERE semester_id = ? " . ($is_super_admin ? "" : "AND admin_id = ?") . "
        GROUP BY book_id
    ) lp ON b.book_id = lp.book_id
    WHERE b.semester_id = ?
    " . ($is_super_admin ? "" : "AND (b.admin_id = ? OR b.admin_id IS NULL)") . "
    GROUP BY b.book_id
    ORDER BY b.book_title ASC
";
$books_result = null;
$books_stmt = lecturer_payments_prepare($conn, $books_sql, 'books_summary');
if ($books_stmt) {
    if ($is_super_admin) {
        $books_stmt->bind_param('iiii', $semester_id, $semester_id, $semester_id, $semester_id);
    } else {
        $books_stmt->bind_param('iiiiiiii', $semester_id, $current_admin_id, $semester_id, $current_admin_id, $semester_id, $current_admin_id, $semester_id, $current_admin_id);
    }
    $books_stmt->execute();
    $books_result = $books_stmt->get_result();
}

if ($selected_book_id > 0) {
    if ($is_super_admin) {
        $sql = "SELECT book_title FROM books WHERE book_id = ? AND semester_id = ? LIMIT 1";
        $stmt = lecturer_payments_prepare($conn, $sql, 'selected_book_title_super_admin');
        if ($stmt) {
            $stmt->bind_param("ii", $selected_book_id, $semester_id);
        }
    } else {
        $sql = "SELECT book_title FROM books WHERE book_id = ? AND semester_id = ? AND (admin_id = ? OR admin_id IS NULL) LIMIT 1";
        $stmt = lecturer_payments_prepare($conn, $sql, 'selected_book_title_rep');
        if ($stmt) {
            $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
        }
    }
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $selected_book_title = $res->fetch_assoc()['book_title'];
        } else {
            $selected_book_id = 0;
        }
        $stmt->close();
    } else {
        $selected_book_id = 0;
    }
}

if ($selected_book_id > 0) {
    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ?");
        $stmt->bind_param("ii", $selected_book_id, $semester_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ? AND r.admin_id = ?");
        $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $selected_total_students = intval($res->fetch_assoc()['c']);
    }

    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 1 AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ?");
        $stmt->bind_param("ii", $selected_book_id, $semester_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 1 AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ? AND r.admin_id = ?");
        $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $selected_received_students = intval($res->fetch_assoc()['c']);
    }

    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 0 AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ?");
        $stmt->bind_param("ii", $selected_book_id, $semester_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 0 AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ? AND r.admin_id = ?");
        $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $selected_yet_students = intval($res->fetch_assoc()['c']);
    }
}

$selected_received_result = null;
$selected_payments_result = null;
$selected_received_rows_data = [];
$selected_payments_rows_data = [];
$students_received_rows = [];
$students_yet_to_receive_rows = [];
$students_received_new_count = 0;
$students_received_exported_count = 0;
$show_received_list = isset($_GET['show_received']) && $_GET['show_received'] === '1';
$show_yet_list = isset($_GET['show_yet']) && $_GET['show_yet'] === '1';
$export_received = isset($_GET['export_received']) && $_GET['export_received'] === '1';
$export_mode = (isset($_GET['export_mode']) && $_GET['export_mode'] === 'all') ? 'all' : 'new';

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$has_date_range = ($start_date !== '' && $end_date !== '');

if ($selected_book_id > 0) {
    $admin_cond = $is_super_admin ? "" : " AND admin_id = $current_admin_id";
    $stmt = $conn->prepare("SELECT * FROM books_received WHERE book_id = ? AND semester_id = ? $admin_cond ORDER BY receive_date DESC, created_at DESC LIMIT 50");
    $stmt->bind_param("ii", $selected_book_id, $semester_id);
    $stmt->execute();
    $selected_received_result = $stmt->get_result();
    if ($selected_received_result) {
        while ($selected_received_row = $selected_received_result->fetch_assoc()) {
            $selected_received_rows_data[] = $selected_received_row;
        }
    }

    $stmt = $conn->prepare("SELECT * FROM lecturer_payments WHERE book_id = ? AND semester_id = ? $admin_cond ORDER BY payment_date DESC, created_at DESC LIMIT 50");
    $stmt->bind_param("ii", $selected_book_id, $semester_id);
    $stmt->execute();
    $selected_payments_result = $stmt->get_result();
    if ($selected_payments_result) {
        while ($selected_payment_row = $selected_payments_result->fetch_assoc()) {
            $selected_payments_rows_data[] = $selected_payment_row;
        }
    }

    if ($selected_book_id > 0) {
        $date_sql = '';
        if ($has_date_range) {
            $date_sql = " AND DATE(COALESCE(ri.received_at, r.created_at)) BETWEEN ? AND ?";
        }

        if ($is_super_admin) {
            $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, COALESCE(ri.received_at, r.created_at) as display_date,
                COALESCE(a.class_name, '') AS class_name,
                COALESCE(a.academic_level, '') AS academic_level
            FROM request_items ri
            JOIN requests r ON ri.request_id = r.request_id
            JOIN students s ON r.student_id = s.student_id
            LEFT JOIN admins a ON r.admin_id = a.admin_id
            WHERE ri.book_id = ? AND ri.is_collected = 1 AND r.semester_id = ?
            $date_sql
            ORDER BY CAST(s.index_number AS DECIMAL(20,10)) ASC, s.index_number ASC
        ");
            if ($has_date_range) {
                $stmt->bind_param("iiss", $selected_book_id, $semester_id, $start_date, $end_date);
            } else {
                $stmt->bind_param("ii", $selected_book_id, $semester_id);
            }
        } else {
            $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, COALESCE(ri.received_at, r.created_at) as display_date,
                COALESCE(a.class_name, '') AS class_name,
                COALESCE(a.academic_level, '') AS academic_level
            FROM request_items ri
            JOIN requests r ON ri.request_id = r.request_id
            JOIN students s ON r.student_id = s.student_id
            LEFT JOIN admins a ON r.admin_id = a.admin_id
            WHERE ri.book_id = ? AND ri.is_collected = 1 AND r.semester_id = ? AND r.admin_id = ?
            $date_sql
            ORDER BY CAST(s.index_number AS DECIMAL(20,10)) ASC, s.index_number ASC
        ");
            if ($has_date_range) {
                $stmt->bind_param("iiiss", $selected_book_id, $semester_id, $current_admin_id, $start_date, $end_date);
            } else {
                $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
            }
        }
        $stmt->execute();
        $students_received_result = $stmt->get_result();
        if ($students_received_result) {
            while ($row = $students_received_result->fetch_assoc()) {
                $row['exported_before'] = false;
                $row['first_exported_at'] = null;
                $row['last_exported_at'] = null;
                $row['export_count'] = 0;
                $students_received_rows[] = $row;
            }
        }

        if (!empty($students_received_rows)) {
            $exported_students = [];
            $stmt = $conn->prepare("
                SELECT
                    ebi.student_id,
                    MIN(leb.exported_at) AS first_exported_at,
                    MAX(leb.exported_at) AS last_exported_at,
                    COUNT(*) AS export_count
                FROM lecturer_export_batch_items ebi
                JOIN lecturer_export_batches leb ON leb.batch_id = ebi.batch_id
                WHERE leb.admin_id = ? AND leb.book_id = ? AND leb.semester_id = ?
                GROUP BY ebi.student_id
            ");
            if ($stmt) {
                $stmt->bind_param("iii", $export_scope_admin_id, $selected_book_id, $semester_id);
                $stmt->execute();
                $export_log_result = $stmt->get_result();
                if ($export_log_result) {
                    while ($export_row = $export_log_result->fetch_assoc()) {
                        $exported_students[intval($export_row['student_id'] ?? 0)] = $export_row;
                    }
                }
                $stmt->close();
            }

            foreach ($students_received_rows as &$received_row) {
                $student_export = $exported_students[intval($received_row['student_id'] ?? 0)] ?? null;
                if ($student_export) {
                    $received_row['exported_before'] = true;
                    $received_row['first_exported_at'] = $student_export['first_exported_at'] ?? null;
                    $received_row['last_exported_at'] = $student_export['last_exported_at'] ?? null;
                    $received_row['export_count'] = intval($student_export['export_count'] ?? 0);
                    $students_received_exported_count++;
                } else {
                    $students_received_new_count++;
                }
            }
            unset($received_row);
        }
    }
    
    if ($selected_book_id > 0) {
        if ($is_super_admin) {
            $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, r.created_at as display_date,
                COALESCE(a.class_name, '') AS class_name,
                COALESCE(a.academic_level, '') AS academic_level
            FROM request_items ri 
            JOIN requests r ON ri.request_id = r.request_id 
            JOIN students s ON r.student_id = s.student_id
            LEFT JOIN admins a ON r.admin_id = a.admin_id
            WHERE ri.book_id = ? AND ri.is_collected = 0 AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ?
            ORDER BY s.full_name ASC
        ");
            $stmt->bind_param("ii", $selected_book_id, $semester_id);
        } else {
            $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, r.created_at as display_date,
                COALESCE(a.class_name, '') AS class_name,
                COALESCE(a.academic_level, '') AS academic_level
            FROM request_items ri 
            JOIN requests r ON ri.request_id = r.request_id 
            JOIN students s ON r.student_id = s.student_id
            LEFT JOIN admins a ON r.admin_id = a.admin_id
            WHERE ri.book_id = ? AND ri.is_collected = 0 AND COALESCE(ri.is_cancelled, 0) = 0 AND r.semester_id = ? AND r.admin_id = ?
            ORDER BY s.full_name ASC
        ");
            $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
        }
        $stmt->execute();
        $students_yet_to_receive_result = $stmt->get_result();
        if ($students_yet_to_receive_result) {
            while ($row = $students_yet_to_receive_result->fetch_assoc()) {
                $students_yet_to_receive_rows[] = $row;
            }
        }
    }
}

if ($export_received && $selected_book_id > 0) {
    $export_rows = [];
    foreach ($students_received_rows as $row) {
        if ($export_mode === 'all' || empty($row['exported_before'])) {
            $export_rows[] = $row;
        }
    }

    if (empty($export_rows)) {
        header('Location: lecturer_payments.php?book_id=' . intval($selected_book_id) . '&show_received=1&msg=no_new_exports');
        exit;
    }

    $batch_id = 0;
    $conn->begin_transaction();
    try {
        $batch_stmt = $conn->prepare("
            INSERT INTO lecturer_export_batches (
                admin_id, book_id, semester_id, export_mode, start_date, end_date, exported_by_username
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$batch_stmt) {
            throw new RuntimeException('Could not prepare export batch insert.');
        }

        $batch_stmt->bind_param(
            "iiissss",
            $export_scope_admin_id,
            $selected_book_id,
            $semester_id,
            $export_mode,
            $start_date,
            $end_date,
            $current_admin_username
        );
        $batch_stmt->execute();
        $batch_id = intval($conn->insert_id);
        $batch_stmt->close();

        $item_stmt = $conn->prepare("INSERT INTO lecturer_export_batch_items (batch_id, student_id) VALUES (?, ?)");
        if (!$item_stmt) {
            throw new RuntimeException('Could not prepare export batch item insert.');
        }

        foreach ($export_rows as $row) {
            $student_id = intval($row['student_id'] ?? 0);
            $item_stmt->bind_param("ii", $batch_id, $student_id);
            $item_stmt->execute();
        }
        $item_stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        die('Unable to complete export right now. Please refresh and try again.');
    }

    if (function_exists('book_system_audit_log')) {
        book_system_audit_log($conn, 'export_received_students', 'lecturer_export_batch', $batch_id, [
            'book_id' => $selected_book_id,
            'book_title' => $selected_book_title,
            'semester_id' => $semester_id,
            'export_mode' => $export_mode,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'students_exported' => count($export_rows),
        ]);
    }

    header('Content-Type: text/csv');
    $safe_title = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $selected_book_title ?: ('book_' . $selected_book_id));
    $export_prefix = ($export_mode === 'all') ? 'students_received_all_' : 'students_received_new_';
    header('Content-Disposition: attachment; filename=' . $export_prefix . $safe_title . '_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Name', 'Index Number', 'Phone', 'Date Received']);
    foreach ($export_rows as $row) {
        fputcsv($output, [
            $row['full_name'],
            $row['index_number'],
            $row['phone'],
            $row['display_date'],
        ]);
    }
    fclose($output);
    exit;
}

$received_result = null;
$recent_received_rows_data = [];
if ($is_super_admin) {
    if ($selected_book_id > 0) {
        $received_stmt = $conn->prepare("SELECT br.*, b.book_title FROM books_received br JOIN books b ON br.book_id = b.book_id WHERE br.semester_id = ? AND br.book_id = ? ORDER BY br.receive_date DESC, br.created_at DESC LIMIT 10");
        if ($received_stmt) {
            $received_stmt->bind_param('ii', $semester_id, $selected_book_id);
            $received_stmt->execute();
            $received_result = $received_stmt->get_result();
            if ($received_result) {
                while ($recent_received_row = $received_result->fetch_assoc()) {
                    $recent_received_rows_data[] = $recent_received_row;
                }
            }
        }
    } else {
        $received_stmt = $conn->prepare("SELECT br.*, b.book_title FROM books_received br JOIN books b ON br.book_id = b.book_id WHERE br.semester_id = ? ORDER BY br.receive_date DESC, br.created_at DESC LIMIT 10");
        if ($received_stmt) {
            $received_stmt->bind_param('i', $semester_id);
            $received_stmt->execute();
            $received_result = $received_stmt->get_result();
            if ($received_result) {
                while ($recent_received_row = $received_result->fetch_assoc()) {
                    $recent_received_rows_data[] = $recent_received_row;
                }
            }
        }
    }
} else {
    if ($selected_book_id > 0) {
        $received_stmt = $conn->prepare("SELECT br.*, b.book_title FROM books_received br JOIN books b ON br.book_id = b.book_id WHERE br.semester_id = ? AND br.admin_id = ? AND br.book_id = ? ORDER BY br.receive_date DESC, br.created_at DESC LIMIT 10");
        if ($received_stmt) {
            $received_stmt->bind_param('iii', $semester_id, $current_admin_id, $selected_book_id);
            $received_stmt->execute();
            $received_result = $received_stmt->get_result();
            if ($received_result) {
                while ($recent_received_row = $received_result->fetch_assoc()) {
                    $recent_received_rows_data[] = $recent_received_row;
                }
            }
        }
    } else {
        $received_stmt = $conn->prepare("SELECT br.*, b.book_title FROM books_received br JOIN books b ON br.book_id = b.book_id WHERE br.semester_id = ? AND br.admin_id = ? ORDER BY br.receive_date DESC, br.created_at DESC LIMIT 10");
        if ($received_stmt) {
            $received_stmt->bind_param('ii', $semester_id, $current_admin_id);
            $received_stmt->execute();
            $received_result = $received_stmt->get_result();
            if ($received_result) {
                while ($recent_received_row = $received_result->fetch_assoc()) {
                    $recent_received_rows_data[] = $recent_received_row;
                }
            }
        }
    }
}

$payments_result = null;
$recent_payments_rows_data = [];
if ($is_super_admin) {
    if ($selected_book_id > 0) {
        $payments_stmt = $conn->prepare("SELECT lp.*, b.book_title FROM lecturer_payments lp JOIN books b ON lp.book_id = b.book_id WHERE lp.semester_id = ? AND lp.book_id = ? ORDER BY lp.payment_date DESC, lp.created_at DESC LIMIT 10");
        if ($payments_stmt) {
            $payments_stmt->bind_param('ii', $semester_id, $selected_book_id);
            $payments_stmt->execute();
            $payments_result = $payments_stmt->get_result();
            if ($payments_result) {
                while ($recent_payment_row = $payments_result->fetch_assoc()) {
                    $recent_payments_rows_data[] = $recent_payment_row;
                }
            }
        }
    } else {
        $payments_stmt = $conn->prepare("SELECT lp.*, b.book_title FROM lecturer_payments lp JOIN books b ON lp.book_id = b.book_id WHERE lp.semester_id = ? ORDER BY lp.payment_date DESC, lp.created_at DESC LIMIT 10");
        if ($payments_stmt) {
            $payments_stmt->bind_param('i', $semester_id);
            $payments_stmt->execute();
            $payments_result = $payments_stmt->get_result();
            if ($payments_result) {
                while ($recent_payment_row = $payments_result->fetch_assoc()) {
                    $recent_payments_rows_data[] = $recent_payment_row;
                }
            }
        }
    }
} else {
    if ($selected_book_id > 0) {
        $payments_stmt = $conn->prepare("SELECT lp.*, b.book_title FROM lecturer_payments lp JOIN books b ON lp.book_id = b.book_id WHERE lp.semester_id = ? AND lp.admin_id = ? AND lp.book_id = ? ORDER BY lp.payment_date DESC, lp.created_at DESC LIMIT 10");
        if ($payments_stmt) {
            $payments_stmt->bind_param('iii', $semester_id, $current_admin_id, $selected_book_id);
            $payments_stmt->execute();
            $payments_result = $payments_stmt->get_result();
            if ($payments_result) {
                while ($recent_payment_row = $payments_result->fetch_assoc()) {
                    $recent_payments_rows_data[] = $recent_payment_row;
                }
            }
        }
    } else {
        $payments_stmt = $conn->prepare("SELECT lp.*, b.book_title FROM lecturer_payments lp JOIN books b ON lp.book_id = b.book_id WHERE lp.semester_id = ? AND lp.admin_id = ? ORDER BY lp.payment_date DESC, lp.created_at DESC LIMIT 10");
        if ($payments_stmt) {
            $payments_stmt->bind_param('ii', $semester_id, $current_admin_id);
            $payments_stmt->execute();
            $payments_result = $payments_stmt->get_result();
            if ($payments_result) {
                while ($recent_payment_row = $payments_result->fetch_assoc()) {
                    $recent_payments_rows_data[] = $recent_payment_row;
                }
            }
        }
    }
}

$book_options = [];
if ($is_super_admin) {
    $book_options_stmt = $conn->prepare("SELECT book_id, book_title FROM books WHERE semester_id = ? ORDER BY book_title ASC");
    if ($book_options_stmt) {
        $book_options_stmt->bind_param('i', $semester_id);
        $book_options_stmt->execute();
        $book_options_result = $book_options_stmt->get_result();
        if ($book_options_result) {
            while ($row = $book_options_result->fetch_assoc()) {
                $book_options[] = $row;
            }
        }
    }
} else {
    $book_options_stmt = $conn->prepare("SELECT book_id, book_title FROM books WHERE semester_id = ? AND (admin_id = ? OR admin_id IS NULL) ORDER BY book_title ASC");
    if ($book_options_stmt) {
        $book_options_stmt->bind_param('ii', $semester_id, $current_admin_id);
        $book_options_stmt->execute();
        $book_options_result = $book_options_stmt->get_result();
        if ($book_options_result) {
            while ($row = $book_options_result->fetch_assoc()) {
                $book_options[] = $row;
            }
        }
    }
}

$total_received = 0;
$total_sold = 0;
$total_paid_to_lecturers = 0;
$total_due_to_lecturers = 0;
$books_summary_rows = [];
$selected_book_metrics = null;

if ($books_result && $books_result->num_rows > 0) {
    $books_result->data_seek(0);
    while ($row = $books_result->fetch_assoc()) {
        $total_received += intval($row['received_copies']);
        $total_sold += intval($row['sold_copies']);
        $total_paid_to_lecturers += floatval($row['lecturer_paid_amount']);
        $total_due_to_lecturers += floatval($row['received_value']);
        $books_summary_rows[] = $row;
        if ($selected_book_id > 0 && intval($row['book_id']) === $selected_book_id) {
            $selected_book_metrics = $row;
        }
    }
}
$remaining_stock = $total_received - $total_sold;
$unpaid_to_lecturer = $total_due_to_lecturers - $total_paid_to_lecturers;
$is_overpaid_total = $unpaid_to_lecturer < 0;
$selected_received_copies = intval($selected_book_metrics['received_copies'] ?? 0);
$selected_sold_copies = intval($selected_book_metrics['sold_copies'] ?? 0);
$selected_paid_to_lecturer = floatval($selected_book_metrics['lecturer_paid_amount'] ?? 0);
$selected_due_to_lecturer = floatval($selected_book_metrics['received_value'] ?? 0);
$selected_remaining_copies = max(0, $selected_received_copies - $selected_sold_copies);
$selected_balance_due = $selected_due_to_lecturer - $selected_paid_to_lecturer;
$selected_books_progress = $selected_received_copies > 0 ? min(100, ($selected_sold_copies / max(1, $selected_received_copies)) * 100) : 0;
$selected_payment_progress = $selected_due_to_lecturer > 0 ? min(100, ($selected_paid_to_lecturer / $selected_due_to_lecturer) * 100) : 0;
$selected_book_display = $selected_book_title !== '' ? $selected_book_title : 'Select a book';
$summary_received_copies = $selected_book_id > 0 ? $selected_received_copies : $total_received;
$summary_sold_copies = $selected_book_id > 0 ? $selected_sold_copies : $total_sold;
$summary_remaining_copies = $selected_book_id > 0 ? $selected_remaining_copies : max(0, $remaining_stock);
$summary_paid_to_lecturer = $selected_book_id > 0 ? $selected_paid_to_lecturer : $total_paid_to_lecturers;
$summary_balance_due = $selected_book_id > 0 ? $selected_balance_due : $unpaid_to_lecturer;
$summary_is_overpaid = $summary_balance_due < 0;
$summary_books_rows = ($selected_book_id > 0 && $selected_book_metrics !== null) ? [$selected_book_metrics] : $books_summary_rows;
$inventory_heading = $selected_book_id > 0 ? 'Selected Book Summary' : 'Book Inventory &amp; Payment Summary';
$received_preview_rows = array_slice($students_received_rows, 0, 3);
$yet_preview_rows = array_slice($students_yet_to_receive_rows, 0, 3);
$show_received_preview = $selected_book_id > 0;
$show_yet_preview = $selected_book_id > 0;
$lecturer_payments_refresh_state = [
    'semester_id' => $semester_id,
    'admin_id' => $current_admin_id,
    'selected_book_id' => $selected_book_id,
    'summary' => [
        'total_received' => intval($summary_received_copies),
        'total_sold' => intval($summary_sold_copies),
        'remaining' => intval($summary_remaining_copies),
        'paid_to_lecturer' => round(floatval($summary_paid_to_lecturer), 2),
        'balance_due' => round(floatval($summary_balance_due), 2),
    ],
    'inventory_rows' => array_map(static function (array $row): array {
        return [
            'book_id' => intval($row['book_id'] ?? 0),
            'received_copies' => intval($row['received_copies'] ?? 0),
            'sold_copies' => intval($row['sold_copies'] ?? 0),
            'lecturer_paid_copies' => intval($row['lecturer_paid_copies'] ?? 0),
            'lecturer_paid_amount' => round(floatval($row['lecturer_paid_amount'] ?? 0), 2),
            'received_value' => round(floatval($row['received_value'] ?? 0), 2),
        ];
    }, $summary_books_rows),
    'selected_received_rows' => array_map(static function (array $row): array {
        return [
            'receive_id' => intval($row['receive_id'] ?? 0),
            'copies_received' => intval($row['copies_received'] ?? 0),
            'unit_price' => round(floatval($row['unit_price'] ?? 0), 2),
            'receive_date' => strval($row['receive_date'] ?? ''),
            'lecturer_name' => strval($row['lecturer_name'] ?? ''),
            'notes' => strval($row['notes'] ?? ''),
        ];
    }, $selected_received_rows_data),
    'selected_payment_rows' => array_map(static function (array $row): array {
        return [
            'payment_id' => intval($row['payment_id'] ?? 0),
            'copies_paid' => intval($row['copies_paid'] ?? 0),
            'amount_paid' => round(floatval($row['amount_paid'] ?? 0), 2),
            'payment_date' => strval($row['payment_date'] ?? ''),
            'notes' => strval($row['notes'] ?? ''),
        ];
    }, $selected_payments_rows_data),
    'recent_received_rows' => array_map(static function (array $row): array {
        return [
            'receive_id' => intval($row['receive_id'] ?? 0),
            'book_id' => intval($row['book_id'] ?? 0),
            'copies_received' => intval($row['copies_received'] ?? 0),
            'unit_price' => round(floatval($row['unit_price'] ?? 0), 2),
            'receive_date' => strval($row['receive_date'] ?? ''),
            'created_at' => strval($row['created_at'] ?? ''),
        ];
    }, $recent_received_rows_data),
    'recent_payment_rows' => array_map(static function (array $row): array {
        return [
            'payment_id' => intval($row['payment_id'] ?? 0),
            'book_id' => intval($row['book_id'] ?? 0),
            'copies_paid' => intval($row['copies_paid'] ?? 0),
            'amount_paid' => round(floatval($row['amount_paid'] ?? 0), 2),
            'payment_date' => strval($row['payment_date'] ?? ''),
            'created_at' => strval($row['created_at'] ?? ''),
        ];
    }, $recent_payments_rows_data),
];
$lecturer_payments_refresh_token = function_exists('book_system_build_refresh_token')
    ? book_system_build_refresh_token($lecturer_payments_refresh_state)
    : sha1(json_encode($lecturer_payments_refresh_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if (function_exists('book_system_maybe_output_refresh_status')) {
    book_system_maybe_output_refresh_status($lecturer_payments_refresh_token);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Payments</title>
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: rgba(255, 255, 255, 0.94);
            --surface-strong: #ffffff;
            --border: rgba(148, 163, 184, 0.22);
            --border-soft: rgba(226, 232, 240, 0.9);
            --text: #132238;
            --muted: #64748b;
            --purple: #6f52d9;
            --purple-deep: #5536c5;
            --purple-soft: rgba(111, 82, 217, 0.12);
            --green: #1f9d63;
            --green-soft: rgba(31, 157, 99, 0.12);
            --red: #cf4d5f;
            --red-soft: rgba(207, 77, 95, 0.12);
            --amber: #d97706;
            --amber-soft: rgba(245, 158, 11, 0.16);
            --cyan: #0f97b8;
            --shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 8px 20px rgba(15, 23, 42, 0.05);
            --radius-lg: 24px;
            --radius-md: 18px;
            --radius-sm: 14px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(111, 82, 217, 0.12), transparent 34%),
                linear-gradient(180deg, #f7f8fd 0%, #eef3f8 100%);
            color: var(--text);
            min-height: 100vh;
            padding: 14px 14px 92px;
            overflow-x: hidden;
        }

        a { color: inherit; }

        .page-container {
            width: min(1180px, 100%);
            margin: 0 auto;
            display: grid;
            gap: 14px;
        }

        .hero-card,
        .surface-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(10px);
        }

        .hero-card {
            padding: 18px;
            background:
                linear-gradient(145deg, rgba(255,255,255,0.96), rgba(245,247,255,0.95)),
                linear-gradient(135deg, rgba(111,82,217,0.08), rgba(15,151,184,0.08));
        }

        .top-shell {
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(226, 232, 240, 0.88);
            border-radius: 34px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.07);
            padding: 18px;
        }

        .hero-top {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .page-headbar {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 12px;
            align-items: start;
        }

        .icon-button {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(111, 82, 217, 0.18);
            background: rgba(111, 82, 217, 0.06);
            color: var(--purple-deep);
            text-decoration: none;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
        }

        .icon-button svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
        }

        .report-button {
            min-height: 54px;
            padding: 0 18px;
            border-radius: 18px;
            display: inline-flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(111, 82, 217, 0.18);
            background: rgba(111, 82, 217, 0.06);
            color: var(--purple-deep);
            font-weight: 800;
            text-decoration: none;
        }

        .report-button svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
        }

        .hero-title {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .title-icon {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, var(--purple), #8a6df0);
            box-shadow: 0 14px 30px rgba(111, 82, 217, 0.24);
        }

        .hero-copy h1 {
            font-size: 1.4rem;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .hero-copy p {
            color: var(--muted);
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .hero-copy h1 {
            margin-bottom: 2px;
        }

        .toolbar-grid {
            display: grid;
            gap: 10px;
        }

        .selector-card {
            background: var(--surface-strong);
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-md);
            padding: 14px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
        }

        .selector-shell {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 58px;
            padding: 0 18px;
            border-radius: 20px;
            border: 2px solid rgba(111, 82, 217, 0.58);
            background: #fff;
        }

        .selector-shell::after {
            content: '';
            position: absolute;
            right: 22px;
            top: 50%;
            width: 10px;
            height: 10px;
            border-right: 2px solid var(--purple-deep);
            border-bottom: 2px solid var(--purple-deep);
            transform: translateY(-70%) rotate(45deg);
            pointer-events: none;
        }

        .selector-icon {
            width: 24px;
            height: 24px;
            flex-shrink: 0;
            color: var(--purple-deep);
        }

        .selector-icon svg {
            width: 24px;
            height: 24px;
            stroke: currentColor;
        }

        .selector-shell .header-select {
            border: 0;
            box-shadow: none;
            padding: 0 34px 0 0;
            background: transparent;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .selector-shell .header-select:focus {
            box-shadow: none;
        }

        .selector-label,
        .eyebrow {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 8px;
        }

        .selector-card form {
            display: grid;
            gap: 12px;
        }

        .header-select,
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            border: 1px solid #d8e0ee;
            border-radius: 16px;
            background: #fff;
            color: var(--text);
            padding: 14px 16px;
            font-size: 0.95rem;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.03);
        }

        .header-select:focus,
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: rgba(111, 82, 217, 0.55);
            box-shadow: 0 0 0 4px rgba(111, 82, 217, 0.12);
        }

        .top-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .back-btn,
        .chip-link,
        .text-btn {
            text-decoration: none;
        }

        .selected-snapshot {
            display: grid;
            gap: 10px;
            padding: 14px;
            border-radius: var(--radius-md);
            border: 1px solid rgba(111, 82, 217, 0.12);
            background: linear-gradient(135deg, rgba(111, 82, 217, 0.09), rgba(255,255,255,0.94));
        }

        .selected-title {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .chip-row,
        .action-row,
        .mini-action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .info-chip,
        .status-chip,
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 7px 11px;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .info-chip.received { background: rgba(31, 157, 99, 0.12); color: #157347; }
        .info-chip.pending { background: rgba(245, 158, 11, 0.16); color: #b45309; }
        .info-chip.exported { background: rgba(100, 116, 139, 0.12); color: #475569; }
        .info-chip.new { background: rgba(31, 157, 99, 0.12); color: #166534; }

        .section-stack {
            display: grid;
            gap: 14px;
        }

        .summary-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .metric-card {
            padding: 16px;
            border-radius: var(--radius-md);
            background: var(--surface-strong);
            border: 1px solid var(--border-soft);
            box-shadow: var(--shadow-soft);
            min-width: 0;
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255,255,255,0.82), transparent 55%);
            pointer-events: none;
        }

        .metric-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .metric-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(111, 82, 217, 0.10);
            color: var(--purple-deep);
            flex-shrink: 0;
        }

        .metric-icon svg {
            width: 21px;
            height: 21px;
            stroke: currentColor;
        }

        .metric-card .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 8px;
        }

        .metric-card .value {
            font-size: 1.25rem;
            font-weight: 800;
            line-height: 1.15;
            overflow-wrap: anywhere;
            position: relative;
            z-index: 1;
        }

        .metric-card .meta {
            margin-top: 4px;
            font-size: 0.82rem;
            color: var(--muted);
            position: relative;
            z-index: 1;
        }

        .metric-card.cyan .value { color: var(--cyan); }
        .metric-card.green .value { color: var(--green); }
        .metric-card.purple .value { color: var(--purple-deep); }
        .metric-card.red .value { color: var(--red); }

        .metric-card.cyan .metric-icon { background: rgba(111, 82, 217, 0.12); color: var(--purple); }
        .metric-card.green .metric-icon { background: rgba(31, 157, 99, 0.12); color: var(--green); }
        .metric-card.orange .metric-icon { background: rgba(245, 158, 11, 0.16); color: var(--amber); }
        .metric-card.blue .metric-icon { background: rgba(15, 151, 184, 0.12); color: #1862a9; }

        .due-card {
            padding: 18px;
            border-radius: 20px;
            border: 1px solid rgba(207, 77, 95, 0.14);
            background: linear-gradient(135deg, rgba(207, 77, 95, 0.10), rgba(255,255,255,0.98));
            box-shadow: var(--shadow);
        }

        .due-card.settled {
            border-color: rgba(31, 157, 99, 0.16);
            background: linear-gradient(135deg, rgba(31, 157, 99, 0.10), rgba(255,255,255,0.98));
        }

        .due-card .label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 10px;
        }

        .due-card .value {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--red);
            line-height: 1.05;
        }

        .due-card.settled .value { color: var(--green); }

        .due-card p {
            margin-top: 8px;
            color: var(--muted);
            font-size: 0.84rem;
        }

        .due-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .due-copy {
            display: grid;
            gap: 4px;
        }

        .due-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .due-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.62);
            color: var(--red);
            flex-shrink: 0;
        }

        .due-icon svg {
            width: 24px;
            height: 24px;
            stroke: currentColor;
        }

        .due-card.settled .due-icon {
            color: var(--green);
        }

        .due-link {
            min-height: 46px;
            padding: 0 18px;
            border-radius: 16px;
            border: 1px solid rgba(207, 77, 95, 0.16);
            background: rgba(255,255,255,0.78);
            color: var(--red);
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .due-card.settled .due-link {
            color: var(--green);
            border-color: rgba(31, 157, 99, 0.18);
        }

        .section-card {
            padding: 18px;
            border-radius: var(--radius-lg);
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-soft);
        }

        .section-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .section-heading .mini-icon {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            background: rgba(111, 82, 217, 0.12);
            color: var(--purple-deep);
        }

        .section-heading.success .mini-icon {
            background: rgba(31, 157, 99, 0.12);
            color: var(--green);
        }

        .section-heading.danger .mini-icon {
            background: rgba(207, 77, 95, 0.12);
            color: var(--red);
        }

        .section-heading.info .mini-icon {
            background: rgba(15, 151, 184, 0.12);
            color: var(--cyan);
        }

        .section-heading h2,
        .section-heading h3,
        .section-heading span:last-child {
            font-size: 1rem;
            line-height: 1.35;
            font-weight: 800;
        }

        .section-subtitle {
            color: var(--muted);
            font-size: 0.84rem;
            line-height: 1.45;
        }

        .quick-actions {
            display: grid;
            gap: 12px;
        }

        .cta-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            min-height: 54px;
            padding: 14px 18px;
            border-radius: 18px;
            border: 0;
            font-size: 0.96rem;
            font-weight: 800;
            color: #fff;
            cursor: pointer;
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
        }

        .cta-btn svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
        }

        .cta-btn.purple {
            background: linear-gradient(135deg, var(--purple), var(--purple-deep));
        }

        .cta-btn.green {
            background: linear-gradient(135deg, #31b76c, #1d8c54);
        }

        .progress-panel {
            display: grid;
            gap: 14px;
        }

        .progress-item {
            display: grid;
            gap: 8px;
        }

        .progress-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: baseline;
        }

        .progress-meta strong {
            font-size: 0.95rem;
        }

        .progress-meta span {
            color: var(--muted);
            font-size: 0.84rem;
        }

        .progress-bar {
            height: 10px;
            border-radius: 999px;
            background: #e6ecf5;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(135deg, var(--purple), #8c6fff);
        }

        .progress-fill.green {
            background: linear-gradient(135deg, #31b76c, #1d8c54);
        }

        .progress-fill.red {
            background: linear-gradient(135deg, #df6d7b, #cf4d5f);
        }

        .support-copy {
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.45;
        }

        .student-list {
            display: grid;
            gap: 10px;
        }

        .student-card {
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            background: #fff;
            padding: 14px;
            display: grid;
            gap: 10px;
            box-shadow: var(--shadow-soft);
        }

        .student-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .student-ident {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            min-width: 0;
        }

        .student-avatar {
            width: 48px;
            height: 48px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1rem;
            color: var(--purple-deep);
            background: linear-gradient(135deg, rgba(245, 180, 190, 0.24), rgba(242, 235, 255, 0.95));
            flex-shrink: 0;
        }

        .student-card.received-card .student-avatar {
            color: #1a5fb8;
            background: linear-gradient(135deg, rgba(220, 245, 232, 0.95), rgba(236, 244, 255, 0.95));
        }

        .student-card.pending-card .student-avatar {
            color: #d04d5d;
            background: linear-gradient(135deg, rgba(255, 228, 230, 0.96), rgba(255, 244, 229, 0.95));
        }

        .student-name {
            font-size: 0.97rem;
            font-weight: 800;
            line-height: 1.35;
        }

        .student-meta {
            display: grid;
            gap: 4px;
            color: var(--muted);
            font-size: 0.84rem;
            line-height: 1.4;
        }

        .student-status {
            flex-shrink: 0;
        }

        .status-chip.given {
            background: rgba(31, 157, 99, 0.12);
            color: var(--green);
        }

        .status-chip.pending {
            background: rgba(245, 158, 11, 0.16);
            color: var(--amber);
        }

        .status-chip.outline-green {
            background: transparent;
            border: 1px solid rgba(31, 157, 99, 0.18);
        }

        .student-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .student-actions .text-btn,
        .secondary-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 14px;
            font-size: 0.84rem;
            font-weight: 700;
            border: 1px solid var(--border-soft);
            background: #fff;
            color: var(--text);
        }

        .student-actions .text-btn.primary-link {
            border-color: rgba(111, 82, 217, 0.16);
            background: rgba(111, 82, 217, 0.08);
            color: var(--purple-deep);
        }

        .empty-state {
            text-align: center;
            padding: 18px;
            color: var(--muted);
            border-radius: 18px;
            background: rgba(248, 250, 252, 0.96);
            border: 1px dashed #d8e0ee;
        }

        .grid-2 {
            display: grid;
            gap: 14px;
        }

        .form-card form {
            display: grid;
            gap: 14px;
        }

        .form-group {
            display: grid;
            gap: 8px;
        }

        .form-group label {
            color: var(--muted);
            font-size: 0.84rem;
            font-weight: 700;
        }

        .form-group textarea {
            min-height: 84px;
            resize: vertical;
        }

        .alert {
            border-radius: 18px;
            padding: 14px 16px;
            font-size: 0.92rem;
            line-height: 1.45;
            border: 1px solid transparent;
        }

        .alert-success {
            background: rgba(31, 157, 99, 0.12);
            border-color: rgba(31, 157, 99, 0.16);
            color: #166534;
        }

        .alert-error {
            background: rgba(207, 77, 95, 0.12);
            border-color: rgba(207, 77, 95, 0.16);
            color: #9f1239;
        }

        .timeline-list {
            display: grid;
            gap: 10px;
        }

        .timeline-item {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 12px;
            align-items: start;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-soft);
        }

        .timeline-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .timeline-dot {
            width: 12px;
            height: 12px;
            margin-top: 6px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--purple), #8c6fff);
            box-shadow: 0 0 0 6px rgba(111, 82, 217, 0.12);
        }

        .timeline-dot.green {
            background: linear-gradient(135deg, #31b76c, #1d8c54);
            box-shadow: 0 0 0 6px rgba(31, 157, 99, 0.12);
        }

        .timeline-copy strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.93rem;
        }

        .timeline-copy p {
            color: var(--muted);
            font-size: 0.83rem;
            line-height: 1.45;
        }

        .timeline-meta {
            min-width: 110px;
            text-align: right;
            color: var(--muted);
            font-size: 0.84rem;
            line-height: 1.5;
        }

        .timeline-card {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 14px 0;
            border-bottom: 1px solid var(--border-soft);
        }

        .timeline-card:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .timeline-card .timeline-copy {
            flex: 1;
            min-width: 0;
        }

        .two-list-grid {
            display: grid;
            gap: 14px;
        }

        .list-panel {
            border-radius: 22px;
            border: 1px solid var(--border-soft);
            background: rgba(255,255,255,0.94);
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .list-panel-header {
            padding: 16px 16px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid var(--border-soft);
        }

        .list-panel-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            font-size: 1rem;
        }

        .list-panel-title svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
        }

        .list-panel.pending-list .list-panel-title,
        .list-panel.pending-list .list-panel-link {
            color: #f05c38;
        }

        .list-panel.received-list .list-panel-title,
        .list-panel.received-list .list-panel-link {
            color: var(--green);
        }

        .list-panel-body {
            display: grid;
        }

        .list-panel-footer {
            padding: 14px 16px;
            border-top: 1px solid var(--border-soft);
            display: flex;
            justify-content: center;
        }

        .list-panel-link {
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .hidden-form-panels {
            display: grid;
            gap: 12px;
        }

        .drawer-panel {
            display: none;
        }

        .drawer-panel.open {
            display: block;
        }

        .drawer-close {
            margin-left: auto;
            min-height: 38px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid var(--border-soft);
            background: #fff;
            color: var(--muted);
            font-weight: 700;
            cursor: pointer;
        }

        .reports-inline-link {
            margin-left: auto;
            color: var(--purple-deep);
            text-decoration: none;
            font-weight: 800;
        }
        .scroll-highlight {
            animation: section-glow 1.4s ease;
        }
        @keyframes section-glow {
            0% {
                box-shadow: 0 0 0 0 rgba(110, 91, 255, 0.18);
                transform: translateY(6px);
            }
            45% {
                box-shadow: 0 0 0 10px rgba(110, 91, 255, 0.06);
                transform: translateY(0);
            }
            100% {
                box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
                transform: translateY(0);
            }
        }

        .inventory-list,
        .records-stack {
            display: grid;
            gap: 12px;
        }

        .inventory-card,
        .record-card {
            border-radius: 18px;
            border: 1px solid var(--border-soft);
            background: #fff;
            padding: 14px;
            box-shadow: var(--shadow-soft);
        }

        .inventory-card h4,
        .record-card h4 {
            font-size: 0.98rem;
            line-height: 1.35;
            margin-bottom: 10px;
        }

        .inventory-meta,
        .record-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .inventory-meta .mini-stat,
        .record-meta .mini-stat {
            padding: 10px 12px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #edf2f7;
        }

        .mini-stat .label {
            color: var(--muted);
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .mini-stat .value {
            font-size: 0.95rem;
            font-weight: 800;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .record-card form {
            display: grid;
            gap: 12px;
        }

        .inline-form-grid {
            display: grid;
            gap: 10px;
        }

        .action-pair {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border: 0;
            border-radius: 14px;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            color: #fff;
            text-decoration: none;
            padding: 0 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--purple), var(--purple-deep));
        }

        .btn-green {
            background: linear-gradient(135deg, #31b76c, #1d8c54);
        }

        .btn-danger {
            background: linear-gradient(135deg, #df6d7b, #cf4d5f);
        }

        .btn-muted {
            background: linear-gradient(135deg, #7b879c, #566478);
        }

        .helper-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .stat-positive { color: var(--green); font-weight: 800; }
        .stat-danger { color: var(--red); font-weight: 800; }

        @media (min-width: 700px) {
            body {
                padding: 22px 22px 110px;
            }

            .summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .grid-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .quick-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .toolbar-grid {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: end;
            }

            .two-list-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 980px) {
            body {
                padding-bottom: 32px;
            }

            .page-container {
                gap: 18px;
            }

            .hero-card,
            .section-card {
                padding: 22px;
            }

            .toolbar-grid {
                grid-template-columns: minmax(320px, 1fr) auto;
            }

            .section-stack.columns {
                grid-template-columns: 1.1fr 0.9fr;
                align-items: start;
            }

            .grid-2.forms-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .activity-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .inventory-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 699px) {
            .page-headbar {
                grid-template-columns: auto 1fr;
            }

            .report-button {
                grid-column: 1 / -1;
                width: 100%;
            }

            .due-row {
                flex-direction: column;
                align-items: stretch;
            }

            .due-link {
                justify-content: center;
            }

            .timeline-card {
                flex-direction: column;
            }

            .timeline-meta {
                text-align: left;
                margin-left: 26px;
            }
        }
    </style>
</head>
<body>
<div class="page-container">
    <section class="top-shell">
        <div class="hero-top">
            <div class="page-headbar">
                <a href="<?= htmlspecialchars($dashboard_url) ?>" class="icon-button" aria-label="Back to dashboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 18l-6-6 6-6"></path>
                    </svg>
                </a>
                <div class="hero-copy">
                    <h1>Lecturer Payments</h1>
                    <p>Track books received and payments made to lecturers</p>
                </div>
                <a href="<?= htmlspecialchars($reports_url) ?>" class="report-button">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 3h8l4 4v14H4V3h4z"></path>
                        <path d="M16 3v5h5"></path>
                        <path d="M8 13h8"></path>
                        <path d="M8 17h5"></path>
                        <path d="M8 9h2"></path>
                    </svg>
                    <span>Reports</span>
                </a>
            </div>

            <div class="toolbar-grid">
                <div class="selector-card">
                    <form method="GET">
                        <div class="selector-shell">
                            <span class="selector-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                                    <path d="M12 6.5h4"></path>
                                </svg>
                            </span>
                            <select name="book_id" onchange="this.form.submit()" class="header-select" aria-label="Select book">
                                <option value="">Select Book</option>
                                <?php foreach ($book_options as $b): ?>
                                    <option value="<?php echo intval($b['book_id']); ?>" <?php echo ($selected_book_id === intval($b['book_id'])) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['book_title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($selected_book_id > 0): ?>
                <div class="selected-snapshot">
                    <div class="eyebrow">Current Book</div>
                    <div class="selected-title"><?php echo htmlspecialchars($selected_book_display); ?></div>
                    <div class="chip-row">
                        <span class="info-chip received">Students Received: <?php echo number_format($selected_received_students); ?></span>
                        <span class="info-chip pending">Yet to Receive: <?php echo number_format($selected_yet_students); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <section class="section-stack">
        <div class="summary-grid">
            <div class="metric-card cyan">
                <div class="metric-head">
                    <span class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v12"></path>
                            <path d="M8 11l4 4 4-4"></path>
                            <rect x="4" y="17" width="16" height="4" rx="1.5"></rect>
                        </svg>
                    </span>
                    <div class="label">Books Received</div>
                </div>
                <div class="value"><?php echo number_format($summary_received_copies); ?> <span style="font-size:0.86rem; font-weight:700; color:var(--text);">copies</span></div>
            </div>
            <div class="metric-card green">
                <div class="metric-head">
                    <span class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 5h5v5"></path>
                            <path d="M10 14L19 5"></path>
                            <path d="M19 14v5h-5"></path>
                            <path d="M5 19l9-9"></path>
                        </svg>
                    </span>
                    <div class="label">Books Given Out</div>
                </div>
                <div class="value"><?php echo number_format($summary_sold_copies); ?> <span style="font-size:0.86rem; font-weight:700; color:var(--text);">copies</span></div>
            </div>
            <div class="metric-card orange">
                <div class="metric-head">
                    <span class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                        </svg>
                    </span>
                    <div class="label">Books Remaining</div>
                </div>
                <div class="value" style="color:#7b341e;"><?php echo number_format($summary_remaining_copies); ?> <span style="font-size:0.86rem; font-weight:700; color:var(--text);">copies</span></div>
            </div>
            <div class="metric-card blue">
                <div class="metric-head">
                    <span class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="6" width="18" height="12" rx="2"></rect>
                            <path d="M7 12h6"></path>
                            <path d="M16 10h.01"></path>
                            <path d="M16 14h.01"></path>
                        </svg>
                    </span>
                    <div class="label">Paid to Lecturer</div>
                </div>
                <div class="value">GH&#8373; <?php echo number_format($summary_paid_to_lecturer, 2); ?></div>
                <div class="meta">amount paid</div>
            </div>
        </div>

        <div class="due-card <?php echo !$summary_is_overpaid && $summary_balance_due <= 0 ? 'settled' : ''; ?>">
            <div class="due-row">
                <div class="due-left">
                    <span class="due-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 11V7a4 4 0 0 0-8 0v4"></path>
                            <rect x="4" y="11" width="16" height="9" rx="2"></rect>
                            <path d="M12 15h.01"></path>
                        </svg>
                    </span>
                    <div class="due-copy">
                        <div class="label">Balance Due to Lecturer</div>
                        <div class="value">
                            <?php if ($summary_is_overpaid): ?>
                                Overpaid GH&#8373; <?php echo number_format(abs($summary_balance_due), 2); ?>
                            <?php else: ?>
                                GH&#8373; <?php echo number_format(max(0, $summary_balance_due), 2); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <a href="#inventory-summary" class="due-link">
                    <span>View Details</span>
                    <span>&rsaquo;</span>
                </a>
            </div>
        </div>
    </section>

    <?php if ($selected_book_id > 0): ?>
        <section class="section-card">
            <div class="section-heading">
                <span class="mini-icon">&#9881;</span>
                <span>Quick Actions</span>
            </div>
            <div class="quick-actions">
                <button type="button" class="cta-btn purple" data-drawer-target="record-received-drawer">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3v12"></path>
                        <path d="M8 11l4 4 4-4"></path>
                        <rect x="4" y="17" width="16" height="4" rx="1.5"></rect>
                    </svg>
                    <span>Record Books Received</span>
                </button>
                <button type="button" class="cta-btn green" data-drawer-target="record-payment-drawer">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="6" width="18" height="12" rx="2"></rect>
                        <path d="M7 12h6"></path>
                        <path d="M16 10h.01"></path>
                        <path d="M16 14h.01"></path>
                    </svg>
                    <span>Record Lecturer Payment</span>
                </button>
            </div>
        </section>

        <section class="section-card">
            <div class="section-heading">
                <span class="mini-icon">&#128200;</span>
                <span>Book Progress</span>
            </div>
            <div class="progress-panel">
                    <div class="progress-item">
                        <div class="progress-meta">
                            <strong>Books Given Out</strong>
                            <span><?php echo number_format($selected_sold_copies); ?> / <?php echo number_format($selected_received_copies); ?> copies</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill green" style="width: <?php echo number_format($selected_books_progress, 2, '.', ''); ?>%;"></div>
                        </div>
                        <div class="support-copy"><?php echo number_format($selected_books_progress, 0); ?>% of books given out</div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-meta">
                            <strong>Paid to Lecturer</strong>
                            <span>GH&#8373; <?php echo number_format($selected_paid_to_lecturer, 2); ?> / GH&#8373; <?php echo number_format($selected_due_to_lecturer, 2); ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?php echo $selected_balance_due <= 0 ? 'green' : ($selected_paid_to_lecturer > 0 ? '' : 'red'); ?>" style="width: <?php echo number_format($selected_payment_progress, 2, '.', ''); ?>%;"></div>
                        </div>
                        <div class="support-copy"><?php echo number_format($selected_payment_progress, 0); ?>% of total payment</div>
                    </div>
                </div>
            <div class="helper-row" style="margin-top:14px; margin-bottom:0;">
                <span class="support-copy">Total Cost: <strong>GH&#8373; <?php echo number_format($selected_due_to_lecturer, 2); ?></strong><?php if ($selected_received_copies > 0): ?> (<?php echo number_format($selected_received_copies); ?> copies × GH&#8373; <?php echo number_format($selected_due_to_lecturer / max(1, $selected_received_copies), 2); ?>)<?php endif; ?></span>
                <a href="#inventory-summary" class="reports-inline-link">View Breakdown</a>
            </div>
        </section>

        <section class="two-list-grid">
            <div class="list-panel pending-list" id="yet-to-receive-panel">
                <div class="list-panel-header">
                    <div class="list-panel-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="8.5" cy="7" r="4"></circle>
                            <path d="M20 8v6"></path>
                            <path d="M23 11h-6"></path>
                        </svg>
                        <span>Yet to Receive (<?php echo count($students_yet_to_receive_rows); ?>)</span>
                    </div>
                    <a href="?book_id=<?php echo $selected_book_id; ?><?php echo $show_yet_list ? '' : '&show_yet=1'; ?>#yet-to-receive-panel" class="list-panel-link"><?php echo $show_yet_list ? '&minus;' : '&rsaquo;'; ?></a>
                </div>
                <div class="list-panel-body">
                    <?php $visible_yet_rows = $show_yet_list ? $students_yet_to_receive_rows : $yet_preview_rows; ?>
                    <?php if (!empty($visible_yet_rows)): ?>
                        <?php foreach ($visible_yet_rows as $student): ?>
                            <?php
                            $studentClass = trim(strval($student['class_name'] ?? ''));
                            $studentLevel = trim(strval($student['academic_level'] ?? ''));
                            $classLine = $studentLevel !== '' ? 'Level ' . $studentLevel : '';
                            if ($studentClass !== '') {
                                $classLine = $studentClass . ($classLine !== '' ? ' • ' . $classLine : '');
                            }
                            ?>
                            <article class="student-card pending-card">
                                <div class="student-top">
                                    <div class="student-ident">
                                        <span class="student-avatar"><?php echo htmlspecialchars(lecturer_payments_initials(strval($student['full_name'] ?? ''))); ?></span>
                                        <div>
                                            <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <div class="student-meta">
                                                <span><?php echo htmlspecialchars($student['index_number']); ?><?php echo $classLine !== '' ? ' • ' . htmlspecialchars($classLine) : ''; ?></span>
                                                <span>Requested: <?php echo date('M d, Y', strtotime($student['display_date'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="view_request.php?search=<?php echo urlencode(strval($student['index_number'] ?? '')); ?>" class="text-btn primary-link">Give Book</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="margin:14px;">All selected students have already received this book.</div>
                    <?php endif; ?>
                </div>
                <div class="list-panel-footer">
                    <?php if ($show_yet_list): ?>
                        <a href="?book_id=<?php echo $selected_book_id; ?>#yet-to-receive-panel" class="list-panel-link">Show less</a>
                    <?php else: ?>
                        <a href="?book_id=<?php echo $selected_book_id; ?>&show_yet=1#yet-to-receive-panel" class="list-panel-link">View all (<?php echo count($students_yet_to_receive_rows); ?>)</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="list-panel received-list" id="students-received-panel">
                <div class="list-panel-header">
                    <div class="list-panel-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="8.5" cy="7" r="4"></circle>
                            <path d="M20 6l-4 4"></path>
                            <path d="M16 6l4 4"></path>
                        </svg>
                        <span>Students Received (<?php echo count($students_received_rows); ?>)</span>
                    </div>
                    <a href="?book_id=<?php echo $selected_book_id; ?><?php echo $show_received_list ? '' : '&show_received=1'; ?>#students-received-panel" class="list-panel-link"><?php echo $show_received_list ? '&minus;' : '&rsaquo;'; ?></a>
                </div>
                <div class="list-panel-body">
                    <?php $visible_received_rows = $show_received_list ? $students_received_rows : $received_preview_rows; ?>
                    <?php if (!empty($visible_received_rows)): ?>
                        <?php foreach ($visible_received_rows as $student): ?>
                            <?php
                            $studentClass = trim(strval($student['class_name'] ?? ''));
                            $studentLevel = trim(strval($student['academic_level'] ?? ''));
                            $classLine = $studentLevel !== '' ? 'Level ' . $studentLevel : '';
                            if ($studentClass !== '') {
                                $classLine = $studentClass . ($classLine !== '' ? ' • ' . $classLine : '');
                            }
                            ?>
                            <article class="student-card received-card">
                                <div class="student-top">
                                    <div class="student-ident">
                                        <span class="student-avatar"><?php echo htmlspecialchars(lecturer_payments_initials(strval($student['full_name'] ?? ''))); ?></span>
                                        <div>
                                            <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <div class="student-meta">
                                                <span><?php echo htmlspecialchars($student['index_number']); ?><?php echo $classLine !== '' ? ' • ' . htmlspecialchars($classLine) : ''; ?></span>
                                                <span>Received: <?php echo date('M d, Y', strtotime($student['display_date'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="status-chip given outline-green">Given</span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="margin:14px;">No students have been marked as received for this book yet.</div>
                    <?php endif; ?>
                </div>
                <div class="list-panel-footer">
                    <div class="mini-action-row" style="justify-content:center;">
                        <?php if ($show_received_list): ?>
                            <a href="?book_id=<?php echo $selected_book_id; ?>#students-received-panel" class="list-panel-link">Show less</a>
                        <?php else: ?>
                            <a href="?book_id=<?php echo $selected_book_id; ?>&show_received=1#students-received-panel" class="list-panel-link">View all (<?php echo count($students_received_rows); ?>)</a>
                        <?php endif; ?>
                        <a href="?book_id=<?php echo $selected_book_id; ?>&export_received=1&export_mode=new" class="text-btn primary-link" <?php echo $students_received_new_count === 0 ? 'style="opacity:0.7;"' : ''; ?>>Export New</a>
                        <a href="?book_id=<?php echo $selected_book_id; ?>&export_received=1&export_mode=all" class="text-btn primary-link">Export All</a>
                    </div>
                </div>
            </div>
        </section>
    <?php else: ?>
        <section class="section-card">
            <div class="section-heading info">
                <span class="mini-icon">&#128214;</span>
                <span>Select a Book to See Progress</span>
            </div>
            <div class="empty-state">
                Choose a book from the selector above to view student lists, progress bars, and edit records for a specific lecturer title.
            </div>
        </section>
    <?php endif; ?>

    <section class="hidden-form-panels">
        <div class="section-card form-card drawer-panel" id="record-received-drawer">
            <div class="section-heading">
                <span class="mini-icon">&#128230;</span>
                <span>Record Books Received</span>
                <button type="button" class="drawer-close" data-drawer-close="record-received-drawer">Close</button>
            </div>
            <p class="section-subtitle">Add new lecturer stock without leaving the page.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label>Select Book *</label>
                    <select name="book_id" required>
                        <option value="">-- Choose a book --</option>
                        <?php foreach ($book_options as $book): ?>
                            <option value="<?php echo $book['book_id']; ?>" <?php echo $selected_book_id === intval($book['book_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($book['book_title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Number of Copies Received *</label>
                    <input type="number" name="copies_received" step="1" required placeholder="e.g. 50 (use -50 to correct)">
                </div>
                <div class="form-group">
                    <label>Date Received *</label>
                    <input type="date" name="receive_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Lecturer Name (Optional)</label>
                    <input type="text" name="lecturer_name" placeholder="e.g. Dr. Mensah">
                </div>
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" placeholder="e.g. First batch for semester"></textarea>
                </div>
                <button type="submit" name="record_received" class="cta-btn purple">&#128230; Record Books Received</button>
            </form>
        </div>

        <div class="section-card form-card drawer-panel" id="record-payment-drawer">
            <div class="section-heading success">
                <span class="mini-icon">&#128176;</span>
                <span>Record Lecturer Payment</span>
                <button type="button" class="drawer-close" data-drawer-close="record-payment-drawer">Close</button>
            </div>
            <p class="section-subtitle">Keep lecturer settlements updated as copies are paid for.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label>Select Book *</label>
                    <select name="book_id" required>
                        <option value="">-- Choose a book --</option>
                        <?php foreach ($book_options as $book): ?>
                            <option value="<?php echo $book['book_id']; ?>" <?php echo $selected_book_id === intval($book['book_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($book['book_title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Number of Copies Paid For *</label>
                    <input type="number" name="copies_paid" step="1" required placeholder="e.g. 15 (use -15 to correct)">
                </div>
                <div class="form-group">
                    <label>Amount Paid (GH&#8373;) *</label>
                    <input type="number" step="0.01" name="amount_paid" required placeholder="e.g. 150.00 (use -150.00 to correct)">
                </div>
                <div class="form-group">
                    <label>Payment Date *</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" placeholder="e.g. Paid via MoMo to Dr. Mensah"></textarea>
                </div>
                <button type="submit" name="record_payment" class="cta-btn green">&#128176; Record Lecturer Payment</button>
            </form>
        </div>
    </section>

    <?php if ($selected_book_id > 0): ?>
        <section class="section-card">
            <div class="section-heading">
                <span class="mini-icon">&#9998;</span>
                <span>Edit Records</span>
            </div>
            <p class="section-subtitle"><?php echo htmlspecialchars($selected_book_title); ?></p>

            <div class="section-stack columns" style="margin-top:14px;">
                <div class="records-stack">
                    <div class="eyebrow">Books Received</div>
                    <?php if (!empty($selected_received_rows_data)): ?>
                        <?php foreach ($selected_received_rows_data as $row): ?>
                            <article class="record-card">
                                <h4><?php echo htmlspecialchars($row['lecturer_name'] ?: 'Lecturer receive record'); ?></h4>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="receive_id" value="<?php echo intval($row['receive_id']); ?>">
                                    <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                                    <div class="inline-form-grid">
                                        <div class="form-group">
                                            <label>Date</label>
                                            <input type="date" name="receive_date" value="<?php echo htmlspecialchars($row['receive_date']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Copies</label>
                                            <input type="number" name="copies_received" step="1" value="<?php echo intval($row['copies_received']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Unit Price (GH&#8373;)</label>
                                            <input type="number" name="unit_price" step="0.01" value="<?php echo htmlspecialchars($row['unit_price'] ?? ''); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Lecturer</label>
                                            <input type="text" name="lecturer_name" value="<?php echo htmlspecialchars($row['lecturer_name'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Notes</label>
                                            <input type="text" name="notes" value="<?php echo htmlspecialchars($row['notes'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="action-pair">
                                        <button type="submit" name="update_received" class="btn btn-primary">Update</button>
                                        <button type="submit" name="delete_received" class="btn btn-danger" onclick="return confirm('Delete this received entry?');">Delete</button>
                                    </div>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No received records for this book.</div>
                    <?php endif; ?>
                </div>

                <div class="records-stack">
                    <div class="eyebrow">Payments to Lecturer</div>
                    <?php if (!empty($selected_payments_rows_data)): ?>
                        <?php foreach ($selected_payments_rows_data as $row): ?>
                            <article class="record-card">
                                <h4>GH&#8373; <?php echo number_format(floatval($row['amount_paid']), 2); ?></h4>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="payment_id" value="<?php echo intval($row['payment_id']); ?>">
                                    <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                                    <div class="inline-form-grid">
                                        <div class="form-group">
                                            <label>Date</label>
                                            <input type="date" name="payment_date" value="<?php echo htmlspecialchars($row['payment_date']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Copies</label>
                                            <input type="number" name="copies_paid" step="1" value="<?php echo intval($row['copies_paid']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Amount (GH&#8373;)</label>
                                            <input type="number" name="amount_paid" step="0.01" value="<?php echo htmlspecialchars($row['amount_paid']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Notes</label>
                                            <input type="text" name="notes" value="<?php echo htmlspecialchars($row['notes'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="action-pair">
                                        <button type="submit" name="update_payment" class="btn btn-primary">Update</button>
                                        <button type="submit" name="delete_payment" class="btn btn-danger" onclick="return confirm('Delete this payment entry?');">Delete</button>
                                    </div>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No payment records for this book.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="section-card">
        <div class="section-heading">
            <span class="mini-icon">&#128337;</span>
            <span>Recent Activity</span>
            <a href="<?= htmlspecialchars($reports_url) ?>" class="reports-inline-link">View all</a>
        </div>
        <div class="grid-2 activity-grid">
        <div class="section-card">
            <div class="section-heading">
                <span class="mini-icon">&#128230;</span>
                <span>Recent Books Received</span>
            </div>
            <?php if (!empty($recent_received_rows_data)): ?>
                <div class="timeline-list">
                    <?php foreach ($recent_received_rows_data as $received): ?>
                        <article class="timeline-card">
                            <span class="timeline-dot"></span>
                            <div class="timeline-copy">
                                <strong>Books Received</strong>
                                <p><?php echo intval($received['copies_received']); ?> copies of <?php echo htmlspecialchars($received['book_title']); ?> added</p>
                                <p><?php echo htmlspecialchars(strval($received['lecturer_name'] ?: 'Lecturer not specified')); ?> &bull; Unit Price: GH&#8373; <?php echo number_format(floatval($received['unit_price'] ?? 0), 2); ?></p>
                            </div>
                            <div class="timeline-meta"><?php echo date('M d, Y', strtotime($received['receive_date'])); ?><br><?php echo !empty($received['created_at']) ? date('g:i A', strtotime($received['created_at'])) : ''; ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No books received recorded yet.</div>
            <?php endif; ?>
        </div>

        <div class="section-card">
            <div class="section-heading success">
                <span class="mini-icon">&#128176;</span>
                <span>Recent Lecturer Payments</span>
            </div>
            <?php if (!empty($recent_payments_rows_data)): ?>
                <div class="timeline-list">
                    <?php foreach ($recent_payments_rows_data as $payment): ?>
                        <article class="timeline-card">
                            <span class="timeline-dot green"></span>
                            <div class="timeline-copy">
                                <strong>Lecturer Payment</strong>
                                <p>Payment of GH&#8373; <?php echo number_format($payment['amount_paid'], 2); ?> recorded</p>
                                <p><?php echo intval($payment['copies_paid']); ?> copies paid &bull; <?php echo htmlspecialchars($payment['book_title']); ?></p>
                            </div>
                            <div class="timeline-meta"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?><br><?php echo !empty($payment['created_at']) ? date('g:i A', strtotime($payment['created_at'])) : ''; ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No payments recorded yet.</div>
            <?php endif; ?>
        </div>
        </div>
    </section>

    <section class="section-card" id="inventory-summary">
        <div class="section-heading">
            <span class="mini-icon">&#128202;</span>
            <span><?php echo $inventory_heading; ?></span>
        </div>
        <?php if (!empty($summary_books_rows)): ?>
            <div class="inventory-list">
                <?php foreach ($summary_books_rows as $book): ?>
                    <?php
                    $received = intval($book['received_copies']);
                    $sold = intval($book['sold_copies']);
                    $remaining_raw = $received - $sold;
                    $remaining = max(0, $remaining_raw);
                    $due_amount = floatval($book['received_value']);
                    $paid_amount = floatval($book['lecturer_paid_amount']);
                    $unpaid_amount = $due_amount - $paid_amount;
                    $is_overpaid = $unpaid_amount < 0;
                    $progress = $due_amount > 0 ? min(100, ($paid_amount / $due_amount) * 100) : 0;
                    ?>
                    <article class="inventory-card">
                        <h4><a href="lecturer_payments.php?book_id=<?php echo intval($book['book_id']); ?>" class="text-btn" style="padding:0; min-height:0; border:0; background:none; color:inherit; justify-content:flex-start;"><?php echo htmlspecialchars($book['book_title']); ?></a></h4>
                        <div class="inventory-meta">
                            <div class="mini-stat">
                                <div class="label">Price</div>
                                <div class="value">GH&#8373; <?php echo number_format($book['price'], 2); ?></div>
                            </div>
                            <div class="mini-stat">
                                <div class="label">Received</div>
                                <div class="value"><?php echo $received; ?> copies</div>
                            </div>
                            <div class="mini-stat">
                                <div class="label">Given Out</div>
                                <div class="value"><?php echo $sold; ?> copies</div>
                            </div>
                            <div class="mini-stat">
                                <div class="label">Remaining</div>
                                <div class="value"><?php echo $remaining; ?> copies</div>
                            </div>
                            <div class="mini-stat">
                                <div class="label">Paid to Lecturer</div>
                                <div class="value">GH&#8373; <?php echo number_format($paid_amount, 2); ?></div>
                            </div>
                            <div class="mini-stat">
                                <div class="label">Balance Due</div>
                                <div class="value <?php echo $unpaid_amount > 0 ? 'stat-danger' : 'stat-positive'; ?>">
                                    <?php if ($is_overpaid): ?>
                                        Overpaid GH&#8373; <?php echo number_format(abs($unpaid_amount), 2); ?>
                                    <?php else: ?>
                                        GH&#8373; <?php echo number_format($unpaid_amount, 2); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="progress-item">
                            <div class="progress-meta">
                                <strong>Payment Progress</strong>
                                <span><?php echo number_format($progress, 0); ?>% paid</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $unpaid_amount <= 0 ? 'green' : ''; ?>" style="width: <?php echo number_format($progress, 2, '.', ''); ?>%;"></div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No books found.</div>
        <?php endif; ?>
    </section>
</div>

<?php include 'footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const drawerPanels = Array.from(document.querySelectorAll('.drawer-panel'));
    let highlightTimer = null;
    const initialDrawerId = <?php
        $initialDrawerId = '';
        if ($error_msg !== '') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
                $initialDrawerId = 'record-payment-drawer';
            } else {
                $initialDrawerId = 'record-received-drawer';
            }
        }
        echo json_encode($initialDrawerId);
    ?>;

    function highlightSection(target) {
        if (!target) {
            return;
        }
        target.classList.remove('scroll-highlight');
        window.requestAnimationFrame(function () {
            target.classList.add('scroll-highlight');
        });
        if (highlightTimer) {
            window.clearTimeout(highlightTimer);
        }
        highlightTimer = window.setTimeout(function () {
            target.classList.remove('scroll-highlight');
        }, 1600);
    }

    function scrollToSection(target) {
        if (!target) {
            return;
        }
        const absoluteTop = window.scrollY + target.getBoundingClientRect().top - 20;
        window.scrollTo({
            top: Math.max(absoluteTop, 0),
            behavior: 'smooth'
        });
        highlightSection(target);
    }

    function openDrawer(id) {
        drawerPanels.forEach(function (panel) {
            panel.classList.toggle('open', panel.id === id);
        });
        const activePanel = document.getElementById(id);
        if (activePanel) {
            scrollToSection(activePanel);
        }
    }

    function closeDrawer(id) {
        const panel = document.getElementById(id);
        if (panel) {
            panel.classList.remove('open');
        }
    }

    document.querySelectorAll('[data-drawer-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            openDrawer(button.getAttribute('data-drawer-target'));
        });
    });

    document.querySelectorAll('[data-drawer-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeDrawer(button.getAttribute('data-drawer-close'));
        });
    });

    document.querySelectorAll('a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            const href = link.getAttribute('href') || '';
            if (href === '#' || href.length < 2) {
                return;
            }
            const target = document.querySelector(href);
            if (!target) {
                return;
            }
            event.preventDefault();
            scrollToSection(target);
            if (target.id) {
                try {
                    window.history.replaceState(null, '', '#' + target.id);
                } catch (error) {}
            }
        });
    });

    if (initialDrawerId) {
        openDrawer(initialDrawerId);
    }

    if (window.location.hash) {
        const initialTarget = document.querySelector(window.location.hash);
        if (initialTarget) {
            window.setTimeout(function () {
                scrollToSection(initialTarget);
            }, 120);
        }
    }
});
</script>

<?php
if (function_exists('book_system_render_refresh_polling_script')) {
    book_system_render_refresh_polling_script($lecturer_payments_refresh_token, [
        'interval_ms' => 15000,
        'min_gap_ms' => 10000,
        'pause_selectors' => [
            '.drawer-panel.open input:focus',
            '.drawer-panel.open select:focus',
            '.drawer-panel.open textarea:focus',
            '.record-card input:focus',
            '.record-card textarea:focus',
        ],
    ]);
}
?>

</body>
</html>


