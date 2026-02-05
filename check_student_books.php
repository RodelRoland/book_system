<?php
include 'db.php';

header('Content-Type: application/json');

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

if (isset($_GET['index'])) {
    $index = trim($_GET['index']);
    
    // Find all Book IDs already requested by this student in the current semester
    $stmt = $conn->prepare("
        SELECT ri.book_id 
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.request_id
        JOIN students s ON r.student_id = s.student_id
        WHERE s.index_number = ? AND r.semester_id = ?
    ");
    $stmt->bind_param("si", $index, $semester_id);
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