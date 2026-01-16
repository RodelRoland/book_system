<?php
include 'db.php';

$index_number = $_POST['index_number'];
$full_name = $_POST['full_name'];
$phone = $_POST['phone'];
$books = $_POST['books'];
$total_amount = $_POST['total_amount'];

// Validate index number length
if (strlen($index_number) !== 10) {
    die("Index number must be exactly 10 characters.");
}

// Check if student exists
$check = $conn->query("SELECT student_id FROM students WHERE index_number='$index_number'");

if ($check->num_rows > 0) {
    $student = $check->fetch_assoc();
    $student_id = $student['student_id'];
} else {
    $conn->query("
        INSERT INTO students (full_name, index_number, phone)
        VALUES ('$full_name', '$index_number', '$phone')
    ");
    $student_id = $conn->insert_id;
}

// Create request
$conn->query("
    INSERT INTO requests (student_id, total_amount, payment_status)
    VALUES ($student_id, $total_amount, 'unpaid')
");

$request_id = $conn->insert_id;

// Save requested books
foreach ($books as $book_id) {
    $conn->query("
        INSERT INTO request_items (request_id, book_id)
        VALUES ($request_id, $book_id)
    ");
}

// Redirect to payment instructions
header("Location: payment_instructions.php?request_id=$request_id");
exit;
