<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
        book_system_setup_ensure_column($conn, 'request_items', 'cash_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        book_system_setup_ensure_column($conn, 'request_items', 'credit_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    }
}

header('Content-Type: application/json');

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$index = trim(strval($_GET['index'] ?? ''));
$rep_id = intval($_GET['rep_id'] ?? 0);

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = (($current_admin_role ?? '') === 'super_admin');

if ($rep_id <= 0 && isset($_SESSION['admin_logged_in']) && $current_admin_id > 0) {
    $rep_id = $current_admin_id;
}

if (!isset($_SESSION['admin_logged_in']) && $rep_id <= 0) {
    echo json_encode([]);
    exit;
}

if (!preg_match('/^\d{10}$/', $index)) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT
        b.book_title,
        r.payment_status,
        ri.is_collected,
        COALESCE(ri.is_cancelled, 0) AS is_cancelled,
        COALESCE(ri.cash_refunded_amount, 0) AS cash_refunded_amount,
        COALESCE(ri.credit_refunded_amount, 0) AS credit_refunded_amount,
        r.created_at
    FROM requests r
    JOIN students s ON s.student_id = r.student_id
    JOIN request_items ri ON ri.request_id = r.request_id
    JOIN books b ON b.book_id = ri.book_id
    WHERE s.index_number = ? AND r.semester_id = ?";

if (!$is_super_admin) {
    $sql .= " AND r.admin_id = ?";
}

$sql .= " ORDER BY r.created_at DESC, b.book_title ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([]);
    exit;
}

if ($is_super_admin) {
    $stmt->bind_param('si', $index, $semester_id);
} else {
    $stmt->bind_param('sii', $index, $semester_id, $rep_id);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'book_title' => strval($row['book_title'] ?? ''),
            'payment_status' => strval($row['payment_status'] ?? 'unpaid'),
            'is_collected' => intval($row['is_collected'] ?? 0),
            'is_cancelled' => intval($row['is_cancelled'] ?? 0),
            'cash_refunded_amount' => floatval($row['cash_refunded_amount'] ?? 0),
            'credit_refunded_amount' => floatval($row['credit_refunded_amount'] ?? 0),
            'created_at' => strval($row['created_at'] ?? ''),
        ];
    }
}

echo json_encode($rows);

