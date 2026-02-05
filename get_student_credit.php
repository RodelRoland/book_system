<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_GET['index'])) {
    $index = trim($_GET['index']);
    $rep_id = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;
    
    // Support partial matching (last 3+ digits) using prepared statements
    $like_pattern = '%' . $index;
    
    // First check students table (existing students with order history)
    $stmt = $conn->prepare("SELECT index_number, full_name, phone, credit_balance FROM students WHERE index_number LIKE ? LIMIT 1");
    $stmt->bind_param("s", $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'found' => true,
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'credit_balance' => floatval($row['credit_balance']),
            'full_index' => $row['index_number']
        ]);
    } else {
        // Fallback: check class_students table (rep's uploaded roster)
        if ($rep_id > 0) {
            $stmt2 = $conn->prepare("SELECT index_number, student_name FROM class_students WHERE admin_id = ? AND index_number LIKE ? LIMIT 1");
            $stmt2->bind_param("is", $rep_id, $like_pattern);
        } else {
            $stmt2 = $conn->prepare("SELECT index_number, student_name FROM class_students WHERE index_number LIKE ? LIMIT 1");
            $stmt2->bind_param("s", $like_pattern);
        }
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        
        if ($result2 && $result2->num_rows > 0) {
            $row2 = $result2->fetch_assoc();
            echo json_encode([
                'found' => true,
                'full_name' => $row2['student_name'],
                'phone' => '',
                'credit_balance' => 0,
                'full_index' => $row2['index_number']
            ]);
        } else {
            echo json_encode(['found' => false, 'credit_balance' => 0]);
        }
    }
} else {
    echo json_encode(['found' => false, 'credit_balance' => 0]);
}
