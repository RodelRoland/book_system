<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(null);
    exit;
}

if (isset($_GET['index'])) {
    $index = substr(trim(strval($_GET['index'] ?? '')), 0, 50);
    $result = null;
    $stmt = $conn->prepare("SELECT full_name, phone FROM students WHERE index_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $index);
        $stmt->execute();
        $result = $stmt->get_result();
    }

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
