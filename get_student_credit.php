<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_GET['index'])) {
    $index = $conn->real_escape_string($_GET['index']);
    $rep_id = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;
    
    // Support partial matching (last 3+ digits)
    // First check students table (existing students with order history)
    $sql = "SELECT index_number, full_name, phone, credit_balance FROM students WHERE index_number LIKE '%$index' LIMIT 1";
    $result = $conn->query($sql);

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
            $sql2 = "SELECT index_number, student_name FROM class_students WHERE admin_id = $rep_id AND index_number LIKE '%$index' LIMIT 1";
        } else {
            $sql2 = "SELECT index_number, student_name FROM class_students WHERE index_number LIKE '%$index' LIMIT 1";
        }
        $result2 = $conn->query($sql2);
        
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
