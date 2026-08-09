<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (isset($_GET['index'])) {
    $index = trim(strval($_GET['index'] ?? ''));
    $rep_id = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;
    $normalized_index = function_exists('book_system_normalize_index_number')
        ? book_system_normalize_index_number($index)
        : strtoupper(trim($index));

    $current_admin_id = intval($_SESSION['admin_id'] ?? 0);
    $current_admin_role = $_SESSION['admin_role'] ?? 'rep';
    $is_super_admin = (($current_admin_role ?? '') === 'super_admin');
    $access_context = null;

    if (isset($_SESSION['admin_logged_in']) && function_exists('book_system_get_effective_rep_access_context')) {
        $access_context = book_system_get_effective_rep_access_context($conn);
    }

    if ($rep_id <= 0 && $access_context && intval($access_context['effective_admin_id'] ?? 0) > 0) {
        $rep_id = intval($access_context['effective_admin_id'] ?? 0);
    } elseif ($rep_id <= 0 && isset($_SESSION['admin_logged_in']) && $current_admin_id > 0 && !$is_super_admin) {
        $rep_id = $current_admin_id;
    }

    if (!isset($_SESSION['admin_logged_in']) && $rep_id <= 0) {
        echo json_encode(['found' => false, 'credit_balance' => 0]);
        exit;
    }

    if ($rep_id <= 0 || $normalized_index === '') {
        echo json_encode(['found' => false, 'credit_balance' => 0]);
        exit;
    }

    $semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
    if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
        $semester_id = book_system_get_active_semester_id($conn);
    }

    $normalized_index_sql = function_exists('book_system_normalized_index_sql')
        ? book_system_normalized_index_sql('index_number')
        : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";

    $roster_name = '';
    $roster_index = '';
    $roster_stmt = $conn->prepare("SELECT student_name, index_number
        FROM class_students
        WHERE admin_id = ?
          AND semester_id = ?
          AND $normalized_index_sql = ?
        LIMIT 1");
    if ($roster_stmt) {
        $roster_stmt->bind_param('iis', $rep_id, $semester_id, $normalized_index);
        $roster_stmt->execute();
        $roster_result = $roster_stmt->get_result();
        if ($roster_result && $roster_result->num_rows > 0) {
            $roster_row = $roster_result->fetch_assoc();
            $roster_name = trim(strval($roster_row['student_name'] ?? ''));
            $roster_index = trim(strval($roster_row['index_number'] ?? ''));
        }
        $roster_stmt->close();
    }

    if ($roster_name === '' || $roster_index === '') {
        echo json_encode(['found' => false, 'credit_balance' => 0]);
        exit;
    }

    $student_stmt = $conn->prepare("SELECT index_number, full_name, phone, credit_balance FROM students WHERE index_number = ? LIMIT 1");
    if ($student_stmt) {
        $student_stmt->bind_param('s', $roster_index);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        if ($student_result && $student_result->num_rows > 0) {
            $row = $student_result->fetch_assoc();
            echo json_encode([
                'found' => true,
                'full_name' => trim(strval($row['full_name'] ?? '')) !== '' ? $row['full_name'] : $roster_name,
                'phone' => strval($row['phone'] ?? ''),
                'credit_balance' => floatval($row['credit_balance'] ?? 0),
                'full_index' => trim(strval($row['index_number'] ?? $roster_index))
            ]);
            exit;
        }
        $student_stmt->close();
    }

    echo json_encode([
        'found' => true,
        'full_name' => $roster_name,
        'phone' => '',
        'credit_balance' => 0,
        'full_index' => $roster_index
    ]);
} else {
    echo json_encode(['found' => false, 'credit_balance' => 0]);
}
