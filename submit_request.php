<?php
session_start();
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

// If no rep specified, get default super admin
if ($rep_id <= 0) {
    $default_rep = $conn->query("SELECT admin_id FROM admins WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
    if ($default_rep && $default_rep->num_rows > 0) {
        $rep_id = intval($default_rep->fetch_assoc()['admin_id']);
    }
}

// Validate index number length
if (strlen($index_number) !== 10) {
    die("Index number must be exactly 10 characters.");
}

// Check if student exists using prepared statement
$stmt = $conn->prepare("SELECT student_id, credit_balance, admin_id FROM students WHERE index_number = ?");
$stmt->bind_param("s", $index_number);
$stmt->execute();
$check = $stmt->get_result();

$credit_balance = 0;
$credit_used = 0;

if ($check->num_rows > 0) {
    $student = $check->fetch_assoc();
    $student_id = $student['student_id'];
    $credit_balance = floatval($student['credit_balance']);

    $student_admin_id = intval($student['admin_id'] ?? 0);
    if ($student_admin_id > 0 && $student_admin_id !== $rep_id) {
        die("Student record is assigned to a different rep. Please contact the administrator.");
    }

    if ($student_admin_id <= 0) {
        $claim = $conn->prepare("UPDATE students SET admin_id = ? WHERE student_id = ? AND (admin_id IS NULL OR admin_id = 0)");
        $claim->bind_param("ii", $rep_id, $student_id);
        $claim->execute();
    }
} else {
    // Insert new student using prepared statement
    $stmt = $conn->prepare("INSERT INTO students (full_name, index_number, phone, credit_balance, admin_id) VALUES (?, ?, ?, 0, ?)");
    $stmt->bind_param("sssi", $full_name, $index_number, $phone, $rep_id);
    $stmt->execute();
    $student_id = $conn->insert_id;
}

// Check for duplicate books in the same semester
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$duplicate_titles = [];
foreach ($books as $book_id) {
    $book_id = intval($book_id);
    if ($book_id <= 0) continue;
    $dup_stmt = $conn->prepare("SELECT b.book_title FROM request_items ri JOIN requests r ON ri.request_id = r.request_id JOIN books b ON ri.book_id = b.book_id WHERE r.student_id = ? AND r.semester_id = ? AND ri.book_id = ? LIMIT 1");
    if ($dup_stmt) {
        $dup_stmt->bind_param('iii', $student_id, $semester_id, $book_id);
        $dup_stmt->execute();
        $dup_res = $dup_stmt->get_result();
        if ($dup_res && $dup_res->num_rows > 0) {
            $duplicate_titles[] = $dup_res->fetch_assoc()['book_title'];
        }
    }
}
if (!empty($duplicate_titles)) {
    die("Duplicate Request: You have already requested: " . htmlspecialchars(implode(', ', $duplicate_titles)));
}

$total_amount = 0.0;
$book_prices = [];
$price_date = date('Y-m-d');
foreach ($books as $book_id) {
    $book_id = intval($book_id);
    if ($book_id <= 0) {
        continue;
    }

    $unit_price = null;
    $hpstmt = $conn->prepare("SELECT new_price FROM book_price_history WHERE book_id = ? AND effective_date IS NOT NULL AND effective_date <= ? ORDER BY effective_date DESC, history_id DESC LIMIT 1");
    if ($hpstmt) {
        $hpstmt->bind_param('is', $book_id, $price_date);
        $hpstmt->execute();
        $hpres = $hpstmt->get_result();
        if ($hpres && $hpres->num_rows === 1) {
            $unit_price = floatval($hpres->fetch_assoc()['new_price']);
        }
    }

    if ($unit_price === null) {
        $hnstmt = $conn->prepare("SELECT old_price FROM book_price_history WHERE book_id = ? AND effective_date IS NOT NULL AND effective_date > ? ORDER BY effective_date ASC, history_id ASC LIMIT 1");
        if ($hnstmt) {
            $hnstmt->bind_param('is', $book_id, $price_date);
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
        continue;
    }

    $book_prices[$book_id] = $unit_price;
    $total_amount += $unit_price;
}

// Calculate how much credit to apply
if ($credit_balance > 0) {
    if ($credit_balance >= $total_amount) {
        // Credit covers entire amount
        $credit_used = $total_amount;
        $amount_to_pay = 0;
        $new_balance = $credit_balance - $total_amount;
        $payment_status = 'paid';
    } else {
        // Partial credit
        $credit_used = $credit_balance;
        $amount_to_pay = $total_amount - $credit_balance;
        $new_balance = 0;
        $payment_status = 'unpaid';
    }
    
    // Update student's credit balance
    $stmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ? AND admin_id = ?");
    $stmt->bind_param("dii", $new_balance, $student_id, $rep_id);
    $stmt->execute();
} else {
    $amount_to_pay = $total_amount;
    $payment_status = 'unpaid';
}

// Create request: amount_paid tracks actual cash/MoMo paid, credit_used tracks applied balance
$amount_paid = 0.00;
$stmt = $conn->prepare("INSERT INTO requests (student_id, total_amount, amount_paid, credit_used, payment_status, semester_id, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("idddsii", $student_id, $total_amount, $amount_paid, $credit_used, $payment_status, $semester_id, $rep_id);
$stmt->execute();

$request_id = $conn->insert_id;

// Save requested books using prepared statement
$stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id, unit_price) VALUES (?, ?, ?)");
foreach ($books as $book_id) {
    $book_id = intval($book_id);
    if (!isset($book_prices[$book_id])) {
        continue;
    }
    $unit_price = floatval($book_prices[$book_id]);
    $stmt->bind_param("iid", $request_id, $book_id, $unit_price);
    $stmt->execute();
}

// Redirect to payment instructions
header("Location: payment_instructions.php?request_id=$request_id");
exit;
