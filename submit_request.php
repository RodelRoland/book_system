<?php
include 'db.php';

$index_number = $_POST['index_number'] ?? '';
$full_name = $_POST['full_name'] ?? '';
$phone = $_POST['phone'] ?? '';
$books = $_POST['books'] ?? [];
$total_amount = floatval($_POST['total_amount'] ?? 0);
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

// Create request with credit applied as amount_paid
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$stmt = $conn->prepare("INSERT INTO requests (student_id, total_amount, amount_paid, payment_status, semester_id, admin_id) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iddsii", $student_id, $total_amount, $credit_used, $payment_status, $semester_id, $rep_id);
$stmt->execute();

$request_id = $conn->insert_id;

// Save requested books using prepared statement
$stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id) VALUES (?, ?)");
foreach ($books as $book_id) {
    $book_id = intval($book_id);
    $stmt->bind_param("ii", $request_id, $book_id);
    $stmt->execute();
}

// Redirect to payment instructions
header("Location: payment_instructions.php?request_id=$request_id");
exit;
