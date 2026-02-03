<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$index_number = trim($_GET['index'] ?? '');

if ($index_number === '') {
    echo json_encode(['success' => false, 'error' => 'No index number provided']);
    exit;
}

// Support partial matching (last 3+ digits)
$like_pattern = '%' . $index_number;
$stmt = $conn->prepare("SELECT student_name, index_number FROM class_students WHERE admin_id = ? AND index_number LIKE ? LIMIT 1");
$stmt->bind_param("is", $current_admin_id, $like_pattern);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();
    echo json_encode(['success' => true, 'student_name' => $row['student_name'], 'full_index' => $row['index_number']]);
} else {
    echo json_encode(['success' => false, 'error' => 'Student not found']);
}
