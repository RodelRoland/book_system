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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

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
fputcsv($output, ['Index Number', 'Student Name', 'Phone', 'Book Title', 'Payment Status', 'Collection Status', 'Date']);

/* Fetch data - Prepared for safety */
$sql = "SELECT 
            s.index_number, s.full_name, s.phone, b.book_title, 
            r.payment_status, ri.is_collected, 
            COALESCE(ri.received_at, r.created_at) as display_date,
            r.created_at as request_date,
            ri.received_at as received_date
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        JOIN request_items ri ON r.request_id = ri.request_id
        JOIN books b ON ri.book_id = b.book_id
        WHERE r.semester_id = ?
          " . ($is_super_admin ? "" : " AND r.admin_id = ? ") . "
          $collection_where";

if ($search !== '') {
    $sql .= " AND (
               s.full_name LIKE ?
           OR s.index_number LIKE ?
           OR b.book_title LIKE ?
          )";
}

$sql .= " ORDER BY display_date DESC";

$stmt = $conn->prepare($sql);
$result = null;
if ($stmt) {
    $search_pattern = '%' . $search . '%';
    if ($is_super_admin) {
        if ($search !== '') {
            $stmt->bind_param('isss', $semester_id, $search_pattern, $search_pattern, $search_pattern);
        } else {
            $stmt->bind_param('i', $semester_id);
        }
    } else {
        if ($search !== '') {
            $stmt->bind_param('iisss', $semester_id, $current_admin_id, $search_pattern, $search_pattern, $search_pattern);
        } else {
            $stmt->bind_param('ii', $semester_id, $current_admin_id);
        }
    }
    $stmt->execute();
    $result = $stmt->get_result();
}

/* Write rows */
while ($result && ($row = $result->fetch_assoc())) {
    $status = ($row['is_collected'] == 1) ? 'COLLECTED' : 'PENDING';
    fputcsv($output, [
        $row['index_number'],
        $row['full_name'],
        $row['phone'],
        $row['book_title'],
        strtoupper($row['payment_status']),
        $status,
        $row['display_date']
    ]);
}

fclose($output);
exit;