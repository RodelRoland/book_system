<?php
include 'db.php';

header('Content-Type: application/json');

if (isset($_GET['index'])) {
    $index = $conn->real_escape_string($_GET['index']);
    $sql = "SELECT full_name, phone FROM students WHERE index_number = '$index' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'full_name' => $row['full_name'],
            'phone' => $row['phone']
        ]);
    } else {
        echo json_encode(null);
    }
} else {
    echo json_encode(null);
}
