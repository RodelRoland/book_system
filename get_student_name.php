<?php
session_start();
require_once 'db.php';

// Turn off error reporting to prevent HTML error messages from breaking the response
error_reporting(0); 

if (!isset($_SESSION['admin_logged_in'])) {
    echo "Unauthorized";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['index_number'])) {
    $index = substr(trim(strval($_POST['index_number'] ?? '')), 0, 50);
    $result = null;
    $stmt = $conn->prepare("SELECT full_name FROM students WHERE index_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $index);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo $row['full_name']; 
    } else {
        echo "Student Not Found";
    }
}
exit; // Ensure nothing else is sent after the name
?>