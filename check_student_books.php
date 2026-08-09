<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
    }
}

header('Content-Type: application/json');

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

if (isset($_GET['index'])) {
    $index = trim(strval($_GET['index'] ?? ''));
    $rep_id = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;

    $access_context = function_exists('book_system_get_effective_rep_access_context')
        ? book_system_get_effective_rep_access_context($conn)
        : null;
    $current_admin_id = intval($access_context['effective_admin_id'] ?? ($_SESSION['admin_id'] ?? 0));
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

    $normalized_index = function_exists('book_system_normalize_index_number')
        ? book_system_normalize_index_number($index)
        : strtoupper(preg_replace('/[^A-Z0-9]+/', '', $index));

    // Only accept exact normalized 10-character index numbers to prevent enumeration.
    if (!preg_match('/^[A-Z0-9]{10}$/', $normalized_index)) {
        echo json_encode([]);
        exit;
    }

    $student_index_expr = function_exists('book_system_normalized_index_sql')
        ? book_system_normalized_index_sql('s.index_number')
        : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(s.index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";
    
    // Find all Book IDs already requested by this student in the current semester
    $sql = "
        SELECT DISTINCT ri.book_id 
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.request_id
        JOIN students s ON r.student_id = s.student_id
        WHERE $student_index_expr = ? AND r.semester_id = ?
          AND COALESCE(ri.is_cancelled, 0) = 0
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
        $stmt->bind_param("si", $normalized_index, $semester_id);
    } else {
        $stmt->bind_param("sii", $normalized_index, $semester_id, $rep_id);
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

