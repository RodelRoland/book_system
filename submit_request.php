<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    die('Invalid request. Please refresh and try again.');
}

$index_number = $_POST['index_number'] ?? '';
$full_name = $_POST['full_name'] ?? '';
$phone = $_POST['phone'] ?? '';
$books = $_POST['books'] ?? [];
$rep_id = intval($_POST['rep_id'] ?? 0);
$selected_book_ids = array_values(array_unique(array_filter(array_map('intval', is_array($books) ? $books : []), static function ($book_id) {
    return $book_id > 0;
})));

// If no rep specified, get default super admin
if (strlen($index_number) !== 10) {
    die("Index number must be exactly 10 characters.");
}

if (empty($selected_book_ids)) {
    die('Please select at least one valid course material.');
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$credit_balance = 0.0;
$credit_used = 0.0;
$request_id = 0;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->begin_transaction();

    if ($rep_id <= 0) {
        $default_rep = $conn->query("SELECT admin_id FROM admins WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
        if ($default_rep && $default_rep->num_rows > 0) {
            $rep_id = intval($default_rep->fetch_assoc()['admin_id'] ?? 0);
        }
    }

    if ($rep_id <= 0) {
        throw new RuntimeException('No active rep could be assigned to this request.');
    }

    $student_stmt = $conn->prepare("SELECT student_id, credit_balance, admin_id FROM students WHERE index_number = ? LIMIT 1 FOR UPDATE");
    $student_stmt->bind_param("s", $index_number);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();

    if ($student_result && $student_result->num_rows > 0) {
        $student = $student_result->fetch_assoc();
        $student_id = intval($student['student_id'] ?? 0);
        $credit_balance = floatval($student['credit_balance'] ?? 0);
        $student_admin_id = intval($student['admin_id'] ?? 0);

        if ($student_admin_id <= 0) {
            $claim = $conn->prepare("UPDATE students SET admin_id = ? WHERE student_id = ? AND (admin_id IS NULL OR admin_id = 0)");
            $claim->bind_param("ii", $rep_id, $student_id);
            $claim->execute();
            $claim->close();
        }
    } else {
        $insert_student_stmt = $conn->prepare("INSERT INTO students (full_name, index_number, phone, credit_balance, admin_id) VALUES (?, ?, ?, 0, ?)");
        $insert_student_stmt->bind_param("sssi", $full_name, $index_number, $phone, $rep_id);
        $insert_student_stmt->execute();
        $student_id = intval($conn->insert_id);
        $insert_student_stmt->close();
    }
    $student_stmt->close();

    $book_placeholders = implode(',', array_fill(0, count($selected_book_ids), '?'));

    $duplicate_sql = "SELECT DISTINCT ri.book_id, b.book_title
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.request_id
        JOIN books b ON ri.book_id = b.book_id
        WHERE r.student_id = ?
          AND r.semester_id = ?
          AND r.admin_id = ?
          AND COALESCE(ri.is_cancelled, 0) = 0
          AND ri.book_id IN ($book_placeholders)";
    $duplicate_stmt = $conn->prepare($duplicate_sql);
    $duplicate_types = 'iii' . str_repeat('i', count($selected_book_ids));
    $duplicate_params = array_merge([$student_id, $semester_id, $rep_id], $selected_book_ids);
    $duplicate_stmt->bind_param($duplicate_types, ...$duplicate_params);
    $duplicate_stmt->execute();
    $duplicate_result = $duplicate_stmt->get_result();
    $duplicate_titles = [];
    if ($duplicate_result) {
        while ($duplicate_row = $duplicate_result->fetch_assoc()) {
            $duplicate_titles[] = strval($duplicate_row['book_title'] ?? '');
        }
    }
    $duplicate_stmt->close();

    if (!empty($duplicate_titles)) {
        throw new RuntimeException("Duplicate Request: You have already requested: " . htmlspecialchars(implode(', ', $duplicate_titles)));
    }

    $price_date = date('Y-m-d');
    $books_sql = "SELECT
            b.book_id,
            COALESCE(
                (
                    SELECT bph.new_price
                    FROM book_price_history bph
                    WHERE bph.book_id = b.book_id
                      AND bph.effective_date IS NOT NULL
                      AND bph.effective_date <= ?
                    ORDER BY bph.effective_date DESC, bph.history_id DESC
                    LIMIT 1
                ),
                (
                    SELECT bph.old_price
                    FROM book_price_history bph
                    WHERE bph.book_id = b.book_id
                      AND bph.effective_date IS NOT NULL
                      AND bph.effective_date > ?
                    ORDER BY bph.effective_date ASC, bph.history_id ASC
                    LIMIT 1
                ),
                b.price
            ) AS unit_price
        FROM books b
        WHERE b.book_id IN ($book_placeholders)
          AND (b.admin_id = ? OR b.admin_id IS NULL)";
    $books_stmt = $conn->prepare($books_sql);
    $books_types = 'ss' . str_repeat('i', count($selected_book_ids)) . 'i';
    $books_params = array_merge([$price_date, $price_date], $selected_book_ids, [$rep_id]);
    $books_stmt->bind_param($books_types, ...$books_params);
    $books_stmt->execute();
    $books_result = $books_stmt->get_result();

    $book_prices = [];
    $total_amount = 0.0;
    if ($books_result) {
        while ($book_row = $books_result->fetch_assoc()) {
            $book_id = intval($book_row['book_id'] ?? 0);
            $unit_price = round(floatval($book_row['unit_price'] ?? 0), 2);
            if ($book_id > 0) {
                $book_prices[$book_id] = $unit_price;
                $total_amount += $unit_price;
            }
        }
    }
    $books_stmt->close();

    if (empty($book_prices)) {
        throw new RuntimeException('No valid books were selected for this rep.');
    }

    if ($credit_balance > 0) {
        if ($credit_balance >= $total_amount) {
            $credit_used = $total_amount;
            $new_balance = $credit_balance - $total_amount;
            $payment_status = 'paid';
        } else {
            $credit_used = $credit_balance;
            $new_balance = 0;
            $payment_status = 'unpaid';
        }

        $balance_stmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ?");
        $balance_stmt->bind_param("di", $new_balance, $student_id);
        $balance_stmt->execute();
        $balance_stmt->close();
    } else {
        $payment_status = 'unpaid';
    }

    $amount_paid = 0.00;
    $request_stmt = $conn->prepare("INSERT INTO requests (student_id, total_amount, amount_paid, credit_used, payment_status, semester_id, admin_id, rep_viewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)");
    $request_stmt->bind_param("idddsii", $student_id, $total_amount, $amount_paid, $credit_used, $payment_status, $semester_id, $rep_id);
    $request_stmt->execute();
    $request_id = intval($conn->insert_id);
    $request_stmt->close();

    $insert_values = [];
    $insert_types = '';
    $insert_params = [];
    foreach ($selected_book_ids as $book_id) {
        if (!isset($book_prices[$book_id])) {
            continue;
        }
        $insert_values[] = '(?, ?, ?)';
        $insert_types .= 'iid';
        $insert_params[] = $request_id;
        $insert_params[] = $book_id;
        $insert_params[] = floatval($book_prices[$book_id]);
    }

    if (empty($insert_values)) {
        throw new RuntimeException('No valid books were available to save.');
    }

    $items_sql = "INSERT INTO request_items (request_id, book_id, unit_price) VALUES " . implode(', ', $insert_values);
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param($insert_types, ...$insert_params);
    $items_stmt->execute();
    $items_stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    if (book_system_security_debug_enabled()) {
        die($e->getMessage());
    }
    die('Unable to submit the request right now. Please try again.');
} finally {
    mysqli_report(MYSQLI_REPORT_OFF);
}

header("Location: payment_instructions.php?request_id=$request_id");
exit;

