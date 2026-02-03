<?php
session_start();
include 'db.php';

/* Protect export (admin only) */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

// Capture the search term from the URL
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$collection_filter = isset($_GET['collection_filter']) ? $_GET['collection_filter'] : 'all';
if (!in_array($collection_filter, ['all', 'not_taken'], true)) {
    $collection_filter = 'all';
}

$collection_where = '';
if ($collection_filter === 'not_taken') {
    $collection_where = " AND (ri.is_collected = 0 OR ri.is_collected IS NULL) ";
}

/* Tell browser this is a CSV file */
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=filtered_book_requests.csv');

/* Open output stream */
$output = fopen('php://output', 'w');

/* CSV column headers */
fputcsv($output, ['Index Number', 'Student Name', 'Phone', 'Book Title', 'Payment Status', 'Collection Status', 'Request Date']);

/* Fetch data - Modified to include filtering */
$sql = "SELECT 
            s.index_number, s.full_name, s.phone, b.book_title, 
            r.payment_status, ri.is_collected, r.created_at
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        JOIN request_items ri ON r.request_id = ri.request_id
        JOIN books b ON ri.book_id = b.book_id
        WHERE r.semester_id = $semester_id
          " . ($is_super_admin ? "" : " AND r.admin_id = $current_admin_id ") . "
          $collection_where
          AND (
               s.full_name LIKE '%$search%' 
           OR s.index_number LIKE '%$search%' 
           OR b.book_title LIKE '%$search%'
          )
        ORDER BY r.created_at DESC";

$result = $conn->query($sql);

/* Write rows */
while ($row = $result->fetch_assoc()) {
    $status = ($row['is_collected'] == 1) ? 'COLLECTED' : 'PENDING';
    fputcsv($output, [
        $row['index_number'],
        $row['full_name'],
        $row['phone'],
        $row['book_title'],
        strtoupper($row['payment_status']),
        $status,
        $row['created_at']
    ]);
}

fclose($output);
exit;