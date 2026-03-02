<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

// Get current admin info for filtering
$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');
$admin_filter = $is_super_admin ? '' : "AND admin_id = $current_admin_id";

$selected_book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;
$selected_book_title = '';

$selected_received_students = 0;
$selected_yet_students = 0;
$selected_total_students = 0;

$csrf_token = csrf_get_token();

// Handle recording books received from lecturer
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
            $pstmt = $conn->prepare("SELECT price FROM books WHERE book_id = ? LIMIT 1");
            if ($pstmt) {
                $pstmt->bind_param('i', $book_id);
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

// Handle recording payment to lecturer
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

// Fetch all books with received, sold, and payment statistics
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
        COUNT(DISTINCT CASE WHEN ri.is_collected = 1 AND r.request_id IS NOT NULL AND r.payment_status = 'paid' THEN ri.item_id END) as sold_paid_copies,
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
    GROUP BY b.book_id
    ORDER BY b.book_title ASC
";
$books_result = null;
$books_stmt = $conn->prepare($books_sql);
if ($books_stmt) {
    if ($is_super_admin) {
        $books_stmt->bind_param('iii', $semester_id, $semester_id, $semester_id);
    } else {
        $books_stmt->bind_param('iiiiii', $semester_id, $current_admin_id, $semester_id, $current_admin_id, $semester_id, $current_admin_id);
    }
    $books_stmt->execute();
    $books_result = $books_stmt->get_result();
}

if ($selected_book_id > 0) {
    $stmt = $conn->prepare("SELECT book_title FROM books WHERE book_id = ? LIMIT 1");
    $stmt->bind_param("i", $selected_book_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $selected_book_title = $res->fetch_assoc()['book_title'];
    } else {
        $selected_book_id = 0;
    }
}

if ($selected_book_id > 0) {
    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND r.semester_id = ?");
        $stmt->bind_param("ii", $selected_book_id, $semester_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND r.semester_id = ? AND r.admin_id = ?");
        $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $selected_total_students = intval($res->fetch_assoc()['c']);
    }

    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 1 AND r.semester_id = ?");
        $stmt->bind_param("ii", $selected_book_id, $semester_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 1 AND r.semester_id = ? AND r.admin_id = ?");
        $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $selected_received_students = intval($res->fetch_assoc()['c']);
    }

    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 0 AND r.semester_id = ?");
        $stmt->bind_param("ii", $selected_book_id, $semester_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT r.student_id) AS c FROM request_items ri JOIN requests r ON ri.request_id = r.request_id WHERE ri.book_id = ? AND ri.is_collected = 0 AND r.semester_id = ? AND r.admin_id = ?");
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
$students_received = null;
$students_yet_to_receive = null;
$show_received_list = isset($_GET['show_received']) && $_GET['show_received'] === '1';
$show_yet_list = isset($_GET['show_yet']) && $_GET['show_yet'] === '1';
$export_received = isset($_GET['export_received']) && $_GET['export_received'] === '1';

$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$has_date_range = ($start_date !== '' && $end_date !== '');

if ($selected_book_id > 0) {
    $admin_cond = $is_super_admin ? "" : " AND admin_id = $current_admin_id";
    $stmt = $conn->prepare("SELECT * FROM books_received WHERE book_id = ? AND semester_id = ? $admin_cond ORDER BY receive_date DESC, created_at DESC LIMIT 50");
    $stmt->bind_param("ii", $selected_book_id, $semester_id);
    $stmt->execute();
    $selected_received_result = $stmt->get_result();

    $stmt = $conn->prepare("SELECT * FROM lecturer_payments WHERE book_id = ? AND semester_id = ? $admin_cond ORDER BY payment_date DESC, created_at DESC LIMIT 50");
    $stmt->bind_param("ii", $selected_book_id, $semester_id);
    $stmt->execute();
    $selected_payments_result = $stmt->get_result();

    if ($show_received_list || $export_received) {
        $date_sql = '';
        if ($has_date_range) {
            $date_sql = " AND DATE(COALESCE(ri.received_at, r.created_at)) BETWEEN ? AND ?";
        }

        if ($is_super_admin) {
            $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, COALESCE(ri.received_at, r.created_at) as display_date
            FROM request_items ri
            JOIN requests r ON ri.request_id = r.request_id
            JOIN students s ON r.student_id = s.student_id
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
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, COALESCE(ri.received_at, r.created_at) as display_date
            FROM request_items ri
            JOIN requests r ON ri.request_id = r.request_id
            JOIN students s ON r.student_id = s.student_id
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
        $students_received = $stmt->get_result();
    }
    
    // Fetch students who haven't received this book yet
    if ($show_yet_list) {
        if ($is_super_admin) {
            $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, r.created_at as display_date
            FROM request_items ri 
            JOIN requests r ON ri.request_id = r.request_id 
            JOIN students s ON r.student_id = s.student_id
            WHERE ri.book_id = ? AND ri.is_collected = 0 AND r.semester_id = ?
            ORDER BY s.full_name ASC
        ");
            $stmt->bind_param("ii", $selected_book_id, $semester_id);
        } else {
            $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.index_number, s.phone, r.created_at as display_date
            FROM request_items ri 
            JOIN requests r ON ri.request_id = r.request_id 
            JOIN students s ON r.student_id = s.student_id
            WHERE ri.book_id = ? AND ri.is_collected = 0 AND r.semester_id = ? AND r.admin_id = ?
            ORDER BY s.full_name ASC
        ");
            $stmt->bind_param("iii", $selected_book_id, $semester_id, $current_admin_id);
        }
        $stmt->execute();
        $students_yet_to_receive = $stmt->get_result();
    }
}

if ($export_received && $selected_book_id > 0) {
    if (!$has_date_range) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Select Date Range</title>
            <style>
                body{font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;background:#f5f7fa;padding:30px;}
                .card{max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:22px;box-shadow:0 4px 15px rgba(0,0,0,.08);}
                label{display:block;margin:12px 0 6px;font-weight:600;color:#444;}
                input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:14px;}
                .btn{margin-top:16px;width:100%;padding:12px 14px;border:none;border-radius:10px;background:#667eea;color:#fff;font-weight:700;cursor:pointer;}
                .link{display:block;margin-top:12px;text-align:center;color:#667eea;text-decoration:none;font-weight:600;}
            </style>
        </head>
        <body>
            <div class="card">
                <h3 style="margin:0 0 8px;color:#333;">Export date range</h3>
                <div style="color:#666;font-size:13px;">Select start and end dates before exporting.</div>
                <form method="GET">
                    <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                    <input type="hidden" name="export_received" value="1">
                    <label>Start Date</label>
                    <input type="date" name="start_date" required>
                    <label>End Date</label>
                    <input type="date" name="end_date" required>
                    <button type="submit" class="btn">Export</button>
                </form>
                <a class="link" href="lecturer_payments.php?book_id=<?php echo intval($selected_book_id); ?>&show_received=1">Back</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    header('Content-Type: text/csv');
    $safe_title = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $selected_book_title ?: ('book_' . $selected_book_id));
    header('Content-Disposition: attachment; filename=students_received_' . $safe_title . '_' . $start_date . '_to_' . $end_date . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Name', 'Index Number', 'Phone', 'Date Received']);
    if ($students_received) {
        while ($row = $students_received->fetch_assoc()) {
            fputcsv($output, [
                $row['full_name'],
                $row['index_number'],
                $row['phone'],
                $row['display_date'],
            ]);
        }
    }
    fclose($output);
    exit;
}

// Fetch recent books received
$received_result = null;
if ($is_super_admin) {
    $received_stmt = $conn->prepare("SELECT br.*, b.book_title FROM books_received br JOIN books b ON br.book_id = b.book_id WHERE br.semester_id = ? ORDER BY br.receive_date DESC, br.created_at DESC LIMIT 10");
    if ($received_stmt) {
        $received_stmt->bind_param('i', $semester_id);
        $received_stmt->execute();
        $received_result = $received_stmt->get_result();
    }
} else {
    $received_stmt = $conn->prepare("SELECT br.*, b.book_title FROM books_received br JOIN books b ON br.book_id = b.book_id WHERE br.semester_id = ? AND br.admin_id = ? ORDER BY br.receive_date DESC, br.created_at DESC LIMIT 10");
    if ($received_stmt) {
        $received_stmt->bind_param('ii', $semester_id, $current_admin_id);
        $received_stmt->execute();
        $received_result = $received_stmt->get_result();
    }
}

// Fetch recent lecturer payments
$payments_result = null;
if ($is_super_admin) {
    $payments_stmt = $conn->prepare("SELECT lp.*, b.book_title FROM lecturer_payments lp JOIN books b ON lp.book_id = b.book_id WHERE lp.semester_id = ? ORDER BY lp.payment_date DESC, lp.created_at DESC LIMIT 10");
    if ($payments_stmt) {
        $payments_stmt->bind_param('i', $semester_id);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
    }
} else {
    $payments_stmt = $conn->prepare("SELECT lp.*, b.book_title FROM lecturer_payments lp JOIN books b ON lp.book_id = b.book_id WHERE lp.semester_id = ? AND lp.admin_id = ? ORDER BY lp.payment_date DESC, lp.created_at DESC LIMIT 10");
    if ($payments_stmt) {
        $payments_stmt->bind_param('ii', $semester_id, $current_admin_id);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
    }
}

// Fetch books for dropdown
$dropdown_books = $conn->query("SELECT book_id, book_title FROM books ORDER BY book_title ASC");
$dropdown_books2 = $conn->query("SELECT book_id, book_title FROM books ORDER BY book_title ASC");
$filter_books = $conn->query("SELECT book_id, book_title FROM books ORDER BY book_title ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Payments</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
            overflow-x: hidden;
        }
        
        .page-container { max-width: 1200px; margin: 0 auto; width: 100%; }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .page-header .subtitle { opacity: 0.9; margin-top: 3px; font-size: 13px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        @media (max-width: 700px) { .stats-grid { grid-template-columns: 1fr !important; } }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: 1fr !important; } }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
            min-width: 0;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px; height: 100%;
        }
        .stat-card.green::before { background: #28a745; }
        .stat-card.blue::before { background: #17a2b8; }
        .stat-card.red::before { background: #dc3545; }
        .stat-card .label { font-size: 12px; text-transform: uppercase; color: #888; font-weight: 600; margin-bottom: 8px; }
        .stat-card .value { font-size: 26px; font-weight: 700; overflow-wrap: anywhere; }
        .stat-card.green .value { color: #28a745; }
        .stat-card.blue .value { color: #17a2b8; }
        .stat-card.red .value { color: #dc3545; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .card h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; color: #555; margin-bottom: 8px; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group textarea { resize: vertical; min-height: 70px; }
        
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        .btn-primary:hover { opacity: 0.9; }
        
        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .table-container { overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            font-weight: 700;
            border-bottom: 2px solid #e9ecef;
        }
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        tr:hover { background: #fafbfc; }
        
        .stat-positive { color: #28a745; font-weight: 700; }
        .stat-danger { color: #dc3545; font-weight: 700; }
        
        .progress-bar { height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        
        .empty-state { text-align: center; padding: 30px; color: #888; }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <div>
            <h1>💰 Lecturer Payments</h1>
            <p class="subtitle">Track payments made to lecturers for each book</p>
        </div>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
            <form method="GET" style="margin: 0;">
                <select name="book_id" onchange="this.form.submit()" style="padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.35); background: rgba(255,255,255,0.18); color: white; font-weight: 600;">
                    <option value="" style="color:#333;">Filter by book...</option>
                    <?php while ($b = $filter_books->fetch_assoc()): ?>
                        <option value="<?php echo intval($b['book_id']); ?>" <?php echo ($selected_book_id === intval($b['book_id'])) ? 'selected' : ''; ?> style="color:#333;">
                            <?php echo htmlspecialchars($b['book_title']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </form>
            <?php if ($selected_book_id > 0): ?>
                <div style="display:flex; flex-direction: column; gap: 4px; align-items: flex-end;">
                    <div style="font-weight: 700; font-size: 12px; opacity: 0.95; max-width: 320px; text-align: right;">
                        <?php echo htmlspecialchars($selected_book_title); ?>
                    </div>
                    <div style="display:flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
                        <span style="background: rgba(40,167,69,0.18); border: 1px solid rgba(40,167,69,0.35); padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 12px;">
                            Received: <?php echo number_format($selected_received_students); ?>
                        </span>
                        <span style="background: rgba(220,53,69,0.18); border: 1px solid rgba(220,53,69,0.35); padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 12px;">
                            Yet: <?php echo number_format($selected_yet_students); ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            <a href="admin.php" class="back-btn">← Back to Dashboard</a>
        </div>
    </div>
    
    <?php
    // Calculate totals
    $total_received = 0;
    $total_sold = 0;
    $total_paid_to_lecturers = 0;
    $total_due_to_lecturers = 0;
    
    if ($books_result && $books_result->num_rows > 0) {
        $books_result->data_seek(0);
        while ($row = $books_result->fetch_assoc()) {
            $total_received += $row['received_copies'];
            $total_sold += $row['sold_copies'];
            $total_paid_to_lecturers += $row['lecturer_paid_amount'];
            $total_due_to_lecturers += floatval($row['received_value']);
        }
        $books_result->data_seek(0);
    }
    $remaining_stock = $total_received - $total_sold;
    $unpaid_to_lecturer = $total_due_to_lecturers - $total_paid_to_lecturers;
    $is_overpaid_total = $unpaid_to_lecturer < 0;
    ?>
    
    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="stat-card blue">
            <div class="label">Books Received</div>
            <div class="value"><?php echo number_format($total_received); ?> copies</div>
        </div>
        <div class="stat-card green">
            <div class="label">Books Sold (Given Out)</div>
            <div class="value"><?php echo number_format($total_sold); ?> copies</div>
        </div>
        <div class="stat-card" style="--card-color: #6f42c1;">
            <div class="label">Paid to Lecturers</div>
            <div class="value" style="color: #6f42c1;">GH₵ <?php echo number_format($total_paid_to_lecturers, 2); ?></div>
        </div>
        <div class="stat-card red">
            <div class="label">Unpaid to Lecturers</div>
            <div class="value">
                <?php if ($is_overpaid_total): ?>
                    Overpaid GH₵ <?php echo number_format(abs($unpaid_to_lecturer), 2); ?>
                <?php else: ?>
                    GH₵ <?php echo number_format($unpaid_to_lecturer, 2); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($selected_book_id > 0): ?>
        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="stat-card blue">
                <div class="label">Selected Book</div>
                <div class="value" style="font-size: 16px; font-weight: 700; color: #17a2b8;">
                    <?php echo htmlspecialchars($selected_book_title); ?>
                </div>
            </div>
            <a href="?book_id=<?php echo $selected_book_id; ?>&show_received=1" style="text-decoration: none;">
                <div class="stat-card green" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; <?php echo $show_received_list ? 'box-shadow: 0 0 0 3px #28a745;' : ''; ?>">
                    <div class="label">Students Received <span style="font-size:10px;">(Click to view)</span></div>
                    <div class="value"><?php echo number_format($selected_received_students); ?></div>
                </div>
            </a>
            <a href="?book_id=<?php echo $selected_book_id; ?>&show_yet=1" style="text-decoration: none;">
                <div class="stat-card red" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; <?php echo $show_yet_list ? 'box-shadow: 0 0 0 3px #dc3545;' : ''; ?>">
                    <div class="label">Students Yet to Receive <span style="font-size:10px;">(Click to view)</span></div>
                    <div class="value"><?php echo number_format($selected_yet_students); ?></div>
                </div>
            </a>
        </div>

        <?php if ($show_received_list && $students_received && $students_received->num_rows > 0): ?>
        <div class="card" style="margin-bottom: 25px; border-left: 4px solid #28a745;">
            <h3 style="color: #28a745;">📋 Students Received "<?php echo htmlspecialchars($selected_book_title); ?>" (<?php echo $students_received->num_rows; ?>)</h3>
            <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                <a href="?book_id=<?php echo $selected_book_id; ?>" class="btn-primary" style="width: auto; padding: 8px 16px; font-size: 13px; text-decoration: none; display: inline-block; background: #6c757d;">Hide List</a>
                <a href="?book_id=<?php echo $selected_book_id; ?>&export_received=1" class="btn-primary" style="width: auto; padding: 8px 16px; font-size: 13px; text-decoration: none; display: inline-block;">Export (Excel)</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Index Number</th>
                            <th>Phone</th>
                            <th>Date Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; while ($student = $students_received->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($student['index_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['phone'] ?: '-'); ?></td>
                            <td><?php echo date('M d, Y', strtotime($student['display_date'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($show_received_list): ?>
        <div class="card" style="margin-bottom: 25px; border-left: 4px solid #28a745;">
            <h3 style="color: #28a745;">ℹ️ No students marked as received for "<?php echo htmlspecialchars($selected_book_title); ?>"</h3>
            <p style="color: #666;">No collected records found for this book.</p>
            <a href="?book_id=<?php echo $selected_book_id; ?>" style="color: #667eea; font-weight: 600;">← Back</a>
        </div>
        <?php endif; ?>
        
        <?php if ($show_yet_list && $students_yet_to_receive && $students_yet_to_receive->num_rows > 0): ?>
        <div class="card" style="margin-bottom: 25px; border-left: 4px solid #dc3545;">
            <h3 style="color: #dc3545;">📋 Students Yet to Receive "<?php echo htmlspecialchars($selected_book_title); ?>" (<?php echo $students_yet_to_receive->num_rows; ?>)</h3>
            <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                <a href="?book_id=<?php echo $selected_book_id; ?>" class="btn-primary" style="width: auto; padding: 8px 16px; font-size: 13px; text-decoration: none; display: inline-block;">Hide List</a>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Index Number</th>
                            <th>Phone</th>
                            <th>Request Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; while ($student = $students_yet_to_receive->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($student['index_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['phone'] ?: '-'); ?></td>
                            <td><?php echo date('M d, Y', strtotime($student['display_date'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($show_yet_list): ?>
        <div class="card" style="margin-bottom: 25px; border-left: 4px solid #28a745;">
            <h3 style="color: #28a745;">✅ All students have received "<?php echo htmlspecialchars($selected_book_title); ?>"</h3>
            <p style="color: #666;">No pending collections for this book.</p>
            <a href="?book_id=<?php echo $selected_book_id; ?>" style="color: #667eea; font-weight: 600;">← Back</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($selected_book_id > 0): ?>
        <div class="card" style="margin-top: 25px;">
            <h3>✏️ Edit Records — <?php echo htmlspecialchars($selected_book_title); ?></h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th colspan="6">Books Received</th>
                        </tr>
                        <tr>
                            <th>Date</th>
                            <th>Copies</th>
                            <th>Unit Price (GH₵)</th>
                            <th>Lecturer</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($selected_received_result && $selected_received_result->num_rows > 0): ?>
                            <?php while ($row = $selected_received_result->fetch_assoc()): ?>
                                <tr>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <td>
                                            <input type="date" name="receive_date" value="<?php echo htmlspecialchars($row['receive_date']); ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" name="copies_received" step="1" value="<?php echo intval($row['copies_received']); ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" name="unit_price" step="0.01" value="<?php echo htmlspecialchars($row['unit_price'] ?? ''); ?>" required>
                                        </td>
                                        <td>
                                            <input type="text" name="lecturer_name" value="<?php echo htmlspecialchars($row['lecturer_name'] ?? ''); ?>">
                                        </td>
                                        <td>
                                            <input type="text" name="notes" value="<?php echo htmlspecialchars($row['notes'] ?? ''); ?>">
                                        </td>
                                        <td>
                                            <input type="hidden" name="receive_id" value="<?php echo intval($row['receive_id']); ?>">
                                            <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                                            <button type="submit" name="update_received" class="btn btn-primary" style="width: auto; padding: 10px 14px;">Update</button>
                                            <button type="submit" name="delete_received" class="btn btn-primary" style="width: auto; padding: 10px 14px; background: #dc3545;" onclick="return confirm('Delete this received entry?');">Delete</button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center; color:#666;">No received records for this book.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-container" style="margin-top: 18px;">
                <table>
                    <thead>
                        <tr>
                            <th colspan="5">Payments to Lecturer</th>
                        </tr>
                        <tr>
                            <th>Date</th>
                            <th>Copies</th>
                            <th>Amount (GH₵)</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($selected_payments_result && $selected_payments_result->num_rows > 0): ?>
                            <?php while ($row = $selected_payments_result->fetch_assoc()): ?>
                                <tr>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <td>
                                            <input type="date" name="payment_date" value="<?php echo htmlspecialchars($row['payment_date']); ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" name="copies_paid" step="1" value="<?php echo intval($row['copies_paid']); ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" name="amount_paid" step="0.01" value="<?php echo htmlspecialchars($row['amount_paid']); ?>" required>
                                        </td>
                                        <td>
                                            <input type="text" name="notes" value="<?php echo htmlspecialchars($row['notes'] ?? ''); ?>">
                                        </td>
                                        <td>
                                            <input type="hidden" name="payment_id" value="<?php echo intval($row['payment_id']); ?>">
                                            <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                                            <button type="submit" name="update_payment" class="btn btn-primary" style="width: auto; padding: 10px 14px;">Update</button>
                                            <button type="submit" name="delete_payment" class="btn btn-primary" style="width: auto; padding: 10px 14px; background: #dc3545;" onclick="return confirm('Delete this payment entry?');">Delete</button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; color:#666;">No payment records for this book.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="grid-2">
        <!-- Record Books Received Form -->
        <div class="card">
            <h3>📦 Record Books Received from Lecturer</h3>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label>Select Book *</label>
                    <select name="book_id" required>
                        <option value="">-- Choose a book --</option>
                        <?php while ($book = $dropdown_books->fetch_assoc()): ?>
                            <option value="<?php echo $book['book_id']; ?>">
                                <?php echo htmlspecialchars($book['book_title']); ?>
                            </option>
                        <?php endwhile; ?>
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
                
                <button type="submit" name="record_received" class="btn btn-primary" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">📦 Record Books Received</button>
            </form>
        </div>
        
        <!-- Record Payment Form -->
        <div class="card">
            <h3>💰 Record Payment to Lecturer</h3>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label>Select Book *</label>
                    <select name="book_id" required>
                        <option value="">-- Choose a book --</option>
                        <?php while ($book = $dropdown_books2->fetch_assoc()): ?>
                            <option value="<?php echo $book['book_id']; ?>">
                                <?php echo htmlspecialchars($book['book_title']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Number of Copies Paid For *</label>
                    <input type="number" name="copies_paid" step="1" required placeholder="e.g. 15 (use -15 to correct)">
                </div>
                
                <div class="form-group">
                    <label>Amount Paid (GH₵) *</label>
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
                
                <button type="submit" name="record_payment" class="btn btn-primary">💰 Record Payment</button>
            </form>
        </div>
    </div>
    
    <div class="grid-2">
        <!-- Recent Books Received -->
        <div class="card">
            <h3>📦 Recent Books Received</h3>
            <?php if ($received_result && $received_result->num_rows > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Book</th>
                                <th>Copies</th>
                                <th>Lecturer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($received = $received_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($received['receive_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($received['book_title']); ?></td>
                                    <td><?php echo $received['copies_received']; ?></td>
                                    <td><?php echo htmlspecialchars($received['lecturer_name'] ?: '—'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color: #666; text-align: center;">No books received recorded yet.</p>
            <?php endif; ?>
        </div>
        
        <!-- Recent Payments -->
        <div class="card">
            <h3>💰 Recent Payments to Lecturers</h3>
            <?php if ($payments_result && $payments_result->num_rows > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Book</th>
                                <th>Copies</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($payment = $payments_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($payment['book_title']); ?></td>
                                    <td><?php echo $payment['copies_paid']; ?></td>
                                    <td>GH₵ <?php echo number_format($payment['amount_paid'], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color: #666; text-align: center;">No payments recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Book Inventory & Payment Summary -->
    <div class="card">
        <h3>📊 Book Inventory & Payment Summary</h3>
        <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Book Title</th>
                    <th>Price</th>
                    <th>Received</th>
                    <th>Sold (Given Out)</th>
                    <th>Remaining</th>
                    <th>Paid to Lecturer</th>
                    <th>Unpaid to Lecturer</th>
                    <th>Payment Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($books_result && $books_result->num_rows > 0): ?>
                    <?php $books_result->data_seek(0); ?>
                    <?php while ($book = $books_result->fetch_assoc()): ?>
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
                        <tr>
                            <td><strong><a href="lecturer_payments.php?book_id=<?php echo intval($book['book_id']); ?>" style="color: inherit; text-decoration: underline;"><?php echo htmlspecialchars($book['book_title']); ?></a></strong></td>
                            <td>GH₵ <?php echo number_format($book['price'], 2); ?></td>
                            <td style="color: #17a2b8; font-weight: 600;"><?php echo $received; ?></td>
                            <td style="color: #28a745; font-weight: 600;"><?php echo $sold; ?></td>
                            <td style="color: <?php echo $remaining_raw < 0 ? '#dc3545' : '#666'; ?>; font-weight: 600;">
                                <?php echo $remaining; ?>
                            </td>
                            <td style="color: #6f42c1; font-weight: 600;">GH₵ <?php echo number_format($paid_amount, 2); ?></td>
                            <td class="<?php echo $unpaid_amount > 0 ? 'stat-danger' : 'stat-positive'; ?>">
                                <?php if ($is_overpaid): ?>
                                    Overpaid GH₵ <?php echo number_format(abs($unpaid_amount), 2); ?>
                                <?php else: ?>
                                    GH₵ <?php echo number_format($unpaid_amount, 2); ?>
                                <?php endif; ?>
                            </td>
                            <td style="min-width: 100px;">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                                </div>
                                <small><?php echo number_format($progress, 0); ?>% paid</small>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align: center;">No books found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>
