<?php
session_start();
include 'db.php';

/* Protect export (admin only) */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

/* Tell browser this is a CSV file */
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=book_requests.csv');

/* Open output stream */
$output = fopen('php://output', 'w');

/* CSV column headers */
fputcsv($output, [
    'Index Number',
    'Student Name',
    'Phone',
    'Book Title',
    'Subtotal (GH₵)',
    'Payment Status',
    'Collection Status',
    'Request Date'
]);

/* Fetch data */
$sql = "
SELECT 
    s.index_number,
    s.full_name,
    s.phone,
    b.book_title,
    r.total_amount,
    r.payment_status,
    ri.status AS collection_status,
    r.request_date
FROM requests r
JOIN students s ON r.student_id = s.student_id
JOIN request_items ri ON r.request_id = ri.request_id
JOIN books b ON ri.book_id = b.book_id
ORDER BY r.request_date DESC
";

$result = $conn->query($sql);

/* Write rows */
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['index_number'],
        $row['full_name'],
        $row['phone'],
        $row['book_title'],
        number_format($row['total_amount'], 2),
        strtoupper($row['payment_status']),
        strtoupper($row['collection_status']),
        $row['request_date']
    ]);
}

fclose($output);
exit;
