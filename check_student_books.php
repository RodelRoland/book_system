<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

if (isset($_GET['index'])) {
    $index = trim(strval($_GET['index'] ?? ''));
    $rep_id = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;

    $current_admin_id = intval($_SESSION['admin_id'] ?? 0);
    $current_admin_role = $_SESSION['admin_role'] ?? 'rep';
    $is_super_admin = (($current_admin_role ?? '') === 'super_admin');

    // If authenticated, use session admin_id as scope.
    if ($rep_id <= 0 && isset($_SESSION['admin_logged_in']) && $current_admin_id > 0) {
        $rep_id = $current_admin_id;
    }

    // Public calls must include a rep_id scope.
    if (!isset($_SESSION['admin_logged_in']) && $rep_id <= 0) {
        echo json_encode([]);
        exit;
    }

    // Only accept exact index numbers to prevent enumeration.
    if (!preg_match('/^\d{10}$/', $index)) {
        echo json_encode([]);
        exit;
    }
    
    // Find all Book IDs already requested by this student in the current semester
    $sql = "
        SELECT ri.book_id 
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.request_id
        JOIN students s ON r.student_id = s.student_id
        WHERE s.index_number = ? AND r.semester_id = ?
    ";

    if (!$is_super_admin) {
        $sql .= " AND r.admin_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode([]);
        exit;
    }

    if ($is_super_admin) {
        $stmt->bind_param("si", $index, $semester_id);
    } else {
        $stmt->bind_param("sii", $index, $semester_id, $rep_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $owned_books = [];
    while ($row = $result->fetch_assoc()) {
        $owned_books[] = intval($row['book_id']);
    }
    
    echo json_encode($owned_books);
} else {
    echo json_encode([]);
}
?>