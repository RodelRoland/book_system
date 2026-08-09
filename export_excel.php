<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
include 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
    }
}

/* Protect export (admin only) */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

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

// Capture the search term from the URL
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$request_filter = trim(strval($_GET['filter'] ?? 'all'));
if (!in_array($request_filter, ['all', 'paid', 'partial', 'unpaid', 'pending', 'given_out', 'today'], true)) {
    $request_filter = 'all';
}
$request_day = trim(strval($_GET['request_day'] ?? ''));
$request_day_obj = $request_day !== '' ? DateTime::createFromFormat('Y-m-d', $request_day) : false;
if (!$request_day_obj || $request_day_obj->format('Y-m-d') !== $request_day) {
    $request_day = '';
}
$selected_book_id = intval($_GET['book_id'] ?? 0);
$book_status = trim(strval($_GET['book_status'] ?? 'all'));
if (!in_array($book_status, ['all', 'taken', 'not_taken'], true) || $selected_book_id <= 0) {
    $book_status = 'all';
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$collection_filter = isset($_GET['collection_filter']) ? $_GET['collection_filter'] : 'all';
if (!in_array($collection_filter, ['all', 'not_taken'], true)) {
    $collection_filter = 'all';
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
            r.payment_status, ri.is_collected, COALESCE(ri.is_cancelled, 0) AS is_cancelled,
            COALESCE(ri.received_at, r.created_at) as display_date,
            r.created_at as request_date,
            ri.received_at as received_date
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        JOIN request_items ri ON r.request_id = ri.request_id
        JOIN books b ON ri.book_id = b.book_id
        WHERE r.semester_id = ?";
$bind_types = 'i';
$bind_params = [$semester_id];

if (!$is_super_admin) {
    $sql .= " AND r.admin_id = ?";
    $bind_types .= 'i';
    $bind_params[] = $current_admin_id;
}

if ($collection_filter === 'not_taken') {
    $sql .= " AND COALESCE(ri.is_cancelled, 0) = 0
              AND COALESCE(ri.is_collected, 0) = 0";
}

if ($request_filter === 'paid') {
    $sql .= " AND COALESCE(r.amount_paid, 0) + 0.009 >= GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0)";
} elseif ($request_filter === 'partial') {
    $sql .= " AND COALESCE(r.amount_paid, 0) > 0.009
              AND COALESCE(r.amount_paid, 0) + 0.009 < GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0)";
} elseif ($request_filter === 'unpaid') {
    $sql .= " AND GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0) > 0.009
              AND COALESCE(r.amount_paid, 0) <= 0.009";
} elseif ($request_filter === 'pending') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM request_items ri_pending
        WHERE ri_pending.request_id = r.request_id
          AND COALESCE(ri_pending.is_cancelled, 0) = 0
          AND COALESCE(ri_pending.is_collected, 0) = 0
    )";
} elseif ($request_filter === 'given_out') {
    $sql .= " AND NOT EXISTS (
        SELECT 1 FROM request_items ri_pending
        WHERE ri_pending.request_id = r.request_id
          AND COALESCE(ri_pending.is_cancelled, 0) = 0
          AND COALESCE(ri_pending.is_collected, 0) = 0
    )";
}

if ($request_day !== '') {
    $sql .= " AND DATE(r.created_at) = ?";
    $bind_types .= 's';
    $bind_params[] = $request_day;
}

if ($search !== '') {
    $sql .= " AND (
               s.full_name LIKE ?
           OR s.index_number LIKE ?
           OR b.book_title LIKE ?
          )";
    $search_pattern = '%' . $search . '%';
    $bind_types .= 'sss';
    array_push($bind_params, $search_pattern, $search_pattern, $search_pattern);
}

if ($selected_book_id > 0) {
    $sql .= " AND ri.book_id = ?
              AND COALESCE(ri.is_cancelled, 0) = 0";
    $bind_types .= 'i';
    $bind_params[] = $selected_book_id;

    if ($book_status === 'taken') {
        $sql .= " AND COALESCE(ri.is_collected, 0) = 1";
    } elseif ($book_status === 'not_taken') {
        $sql .= " AND COALESCE(ri.is_collected, 0) = 0
                  AND COALESCE(r.amount_paid, 0) + COALESCE(r.credit_used, 0) + 0.009 >= (
                      SELECT COALESCE(SUM(COALESCE(ri_covered.unit_price, b_covered.price, 0)), 0)
                      FROM request_items ri_covered
                      JOIN books b_covered ON b_covered.book_id = ri_covered.book_id
                      WHERE ri_covered.request_id = r.request_id
                        AND COALESCE(ri_covered.is_cancelled, 0) = 0
                        AND ri_covered.item_id <= ri.item_id
                  )";
    }
}

$sql .= " ORDER BY display_date DESC";

$stmt = $conn->prepare($sql);
$result = null;
if ($stmt) {
    $bind_args = [$bind_types];
    foreach ($bind_params as $param_index => $param_value) {
        $bind_args[] = &$bind_params[$param_index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_args);
    $stmt->execute();
    $result = $stmt->get_result();
}

/* Write rows */
while ($result && ($row = $result->fetch_assoc())) {
    $status = intval($row['is_cancelled'] ?? 0) === 1 ? 'REFUNDED' : (($row['is_collected'] == 1) ? 'COLLECTED' : 'PENDING');
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

