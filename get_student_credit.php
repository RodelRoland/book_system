<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_GET['index'])) {
    $index = $conn->real_escape_string($_GET['index']);
    $sql = "SELECT full_name, phone, credit_balance FROM students WHERE index_number = '$index' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'found' => true,
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'credit_balance' => floatval($row['credit_balance'])
        ]);
    } else {
        echo json_encode(['found' => false, 'credit_balance' => 0]);
    }
} else {
    echo json_encode(['found' => false, 'credit_balance' => 0]);
}
