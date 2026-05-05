<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
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

$rep_id = intval($access_context['effective_admin_id'] ?? 0);
if ($rep_id <= 0) {
    die('Invalid rep session.');
}

$rep_profile = null;
$stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, role, is_active, momo_number, bank_name, account_name, account_number, approved_at, created_at
    FROM admins
    WHERE admin_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $rep_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rep_profile = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();
}

if (!$rep_profile) {
    die('Rep profile not found.');
}

$active_semester = function_exists('book_system_get_active_semester_details')
    ? book_system_get_active_semester_details($conn)
    : ['semester_id' => 0, 'semester_name' => '', 'semester_start_date' => ''];

function rep_export_fetch_all(mysqli $conn, string $sql, string $types = '', ...$params): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

$class_students = rep_export_fetch_all(
    $conn,
    "SELECT id, index_number, student_name, created_at
     FROM class_students
     WHERE admin_id = ?
     ORDER BY student_name ASC, index_number ASC",
    'i',
    $rep_id
);

$students = rep_export_fetch_all(
    $conn,
    "SELECT student_id, index_number, full_name, phone, credit_balance, admin_id
     FROM students
     WHERE admin_id = ?
     ORDER BY full_name ASC, index_number ASC",
    'i',
    $rep_id
);

$requests = rep_export_fetch_all(
    $conn,
    "SELECT
        r.request_id,
        r.student_id,
        s.full_name AS student_name,
        s.index_number,
        s.phone,
        r.total_amount,
        r.amount_paid,
        r.credit_used,
        r.payment_status,
        r.rep_viewed_at,
        r.created_at,
        sem.semester_name,
        sem.semester_start_date
     FROM requests r
     JOIN students s ON s.student_id = r.student_id
     LEFT JOIN semesters sem ON sem.semester_id = r.semester_id
     WHERE r.admin_id = ?
     ORDER BY r.created_at DESC",
    'i',
    $rep_id
);

$request_items = rep_export_fetch_all(
    $conn,
    "SELECT
        ri.item_id,
        ri.request_id,
        ri.book_id,
        b.book_title,
        b.course_code,
        ri.unit_price,
        ri.is_collected,
        ri.received_at
     FROM request_items ri
     JOIN requests r ON r.request_id = ri.request_id
     LEFT JOIN books b ON b.book_id = ri.book_id
     WHERE r.admin_id = ?
     ORDER BY ri.request_id DESC, ri.item_id ASC",
    'i',
    $rep_id
);

$lecturer_payments = rep_export_fetch_all(
    $conn,
    "SELECT
        lp.payment_id,
        lp.book_id,
        b.book_title,
        b.course_code,
        lp.copies_paid,
        lp.amount_paid,
        lp.payment_date,
        lp.notes,
        lp.created_at,
        sem.semester_name,
        sem.semester_start_date
     FROM lecturer_payments lp
     LEFT JOIN books b ON b.book_id = lp.book_id
     LEFT JOIN semesters sem ON sem.semester_id = lp.semester_id
     WHERE lp.admin_id = ?
     ORDER BY lp.payment_date DESC, lp.payment_id DESC",
    'i',
    $rep_id
);

$books_received = rep_export_fetch_all(
    $conn,
    "SELECT
        br.receive_id,
        br.book_id,
        b.book_title,
        b.course_code,
        br.copies_received,
        br.receive_date,
        br.lecturer_name,
        br.notes,
        br.created_at,
        sem.semester_name,
        sem.semester_start_date
     FROM books_received br
     LEFT JOIN books b ON b.book_id = br.book_id
     LEFT JOIN semesters sem ON sem.semester_id = br.semester_id
     WHERE br.admin_id = ?
     ORDER BY br.receive_date DESC, br.receive_id DESC",
    'i',
    $rep_id
);

$balance_returns = rep_export_fetch_all(
    $conn,
    "SELECT
        ret.return_id,
        ret.student_id,
        s.full_name AS student_name,
        s.index_number,
        ret.request_id,
        ret.amount,
        ret.return_date,
        ret.notes
     FROM balance_returns ret
     JOIN students s ON s.student_id = ret.student_id
     WHERE s.admin_id = ?
     ORDER BY ret.return_date DESC, ret.return_id DESC",
    'i',
    $rep_id
);

$audit_logs = rep_export_fetch_all(
    $conn,
    "SELECT
        log_id,
        actor_role,
        actor_username,
        action_type,
        entity_type,
        entity_id,
        target_admin_id,
        details_json,
        created_at
     FROM audit_logs
     WHERE actor_admin_id = ? OR target_admin_id = ?
     ORDER BY created_at DESC, log_id DESC
     LIMIT 1000",
    'ii',
    $rep_id,
    $rep_id
);

$payload = [
    'export_meta' => [
        'generated_at' => date('c'),
        'system' => 'Book System',
        'export_type' => 'rep_full_data',
        'active_semester' => $active_semester,
    ],
    'rep_profile' => $rep_profile,
    'class_students' => $class_students,
    'students' => $students,
    'requests' => $requests,
    'request_items' => $request_items,
    'lecturer_payments' => $lecturer_payments,
    'books_received' => $books_received,
    'balance_returns' => $balance_returns,
    'audit_logs' => $audit_logs,
];

$safe_name = preg_replace('/[^A-Za-z0-9_-]+/', '_', strval($rep_profile['username'] ?? ('rep_' . $rep_id)));
$filename = $safe_name . '_book_system_export_' . date('Ymd_His') . '.json';

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename=' . $filename);
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

