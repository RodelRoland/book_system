<?php
include 'db.php';

$index_number = $_POST['index_number'] ?? '';
$full_name = $_POST['full_name'] ?? '';
$phone = $_POST['phone'] ?? '';
$books = $_POST['books'] ?? [];
$total_amount = floatval($_POST['total_amount'] ?? 0);

// Validate index number length
if (strlen($index_number) !== 10) {
    die("Index number must be exactly 10 characters.");
}

// Check if student exists using prepared statement
$stmt = $conn->prepare("SELECT student_id, credit_balance FROM students WHERE index_number = ?");
$stmt->bind_param("s", $index_number);
$stmt->execute();
$check = $stmt->get_result();

$credit_balance = 0;
$credit_used = 0;

if ($check->num_rows > 0) {
    $student = $check->fetch_assoc();
    $student_id = $student['student_id'];
    $credit_balance = floatval($student['credit_balance']);
} else {
    // Insert new student using prepared statement
    $stmt = $conn->prepare("INSERT INTO students (full_name, index_number, phone, credit_balance) VALUES (?, ?, ?, 0)");
    $stmt->bind_param("sss", $full_name, $index_number, $phone);
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
    $stmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ?");
    $stmt->bind_param("di", $new_balance, $student_id);
    $stmt->execute();
} else {
    $amount_to_pay = $total_amount;
    $payment_status = 'unpaid';
}

// Create request with credit applied as amount_paid
$stmt = $conn->prepare("INSERT INTO requests (student_id, total_amount, amount_paid, payment_status) VALUES (?, ?, ?, ?)");
$stmt->bind_param("idds", $student_id, $total_amount, $credit_used, $payment_status);
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
