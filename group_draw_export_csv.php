<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
require_once 'app_helpers.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? '');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}

$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}

$session_id = intval($_GET['session_id'] ?? 0);
if ($session_id <= 0 || $semester_id <= 0) {
    header('Location: group_draw_results.php');
    exit;
}

$required_tables = [
    'group_draw_sessions',
    'group_draw_groups',
    'group_draw_participants',
];
foreach ($required_tables as $table_name) {
    $table_check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'");
    if (!($table_check && $table_check->num_rows === 1)) {
        header('Location: group_draw_results.php');
        exit;
    }
}

$session_stmt = $conn->prepare("SELECT
        session_id,
        session_title
    FROM group_draw_sessions
    WHERE session_id = ? AND admin_id = ? AND semester_id = ?
    LIMIT 1");
$session_row = null;
if ($session_stmt) {
    $session_stmt->bind_param('iii', $session_id, $current_admin_id, $semester_id);
    $session_stmt->execute();
    $session_res = $session_stmt->get_result();
    $session_row = ($session_res && $session_res->num_rows === 1) ? $session_res->fetch_assoc() : null;
    $session_stmt->close();
}

if (!$session_row) {
    header('Location: group_draw_results.php');
    exit;
}

$safe_title = preg_replace('/[^A-Za-z0-9_-]+/', '_', strval($session_row['session_title'] ?? 'group_draw_results'));
$safe_title = trim($safe_title, '_');
if ($safe_title === '') {
    $safe_title = 'group_draw_results';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safe_title . '.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');
fputcsv($output, ['Session Title', 'Group Name', 'Full Name', 'Index Number', 'Draw Status', 'Drawn Time']);

$rows_stmt = $conn->prepare("SELECT
        s.session_title,
        COALESCE(g.group_name, 'Not Yet Assigned') AS group_name,
        p.participant_name,
        p.index_number,
        p.draw_status,
        p.assigned_at
    FROM group_draw_participants p
    INNER JOIN group_draw_sessions s ON s.session_id = p.session_id
    LEFT JOIN group_draw_groups g ON g.group_id = p.assigned_group_id
    WHERE p.session_id = ? AND p.admin_id = ? AND p.semester_id = ?
    ORDER BY
        CASE WHEN p.assigned_group_id IS NULL THEN 1 ELSE 0 END ASC,
        g.sort_order ASC,
        g.group_id ASC,
        p.participant_name ASC");
if ($rows_stmt) {
    $rows_stmt->bind_param('iii', $session_id, $current_admin_id, $semester_id);
    $rows_stmt->execute();
    $rows_res = $rows_stmt->get_result();
    while ($rows_res && ($row = $rows_res->fetch_assoc())) {
        fputcsv($output, [
            strval($row['session_title'] ?? ''),
            strval($row['group_name'] ?? ''),
            strval($row['participant_name'] ?? ''),
            strval($row['index_number'] ?? ''),
            strval($row['draw_status'] ?? ''),
            strval($row['assigned_at'] ?? ''),
        ]);
    }
    $rows_stmt->close();
}

fclose($output);
exit;
