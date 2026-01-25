<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php';

if (isset($_GET['request_id'])) {
    $request_id = intval($_GET['request_id']);

    // 1. Get current status
    $res = $conn->query("SELECT payment_status FROM requests WHERE request_id = $request_id");
    
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        // 2. Flip the status
        $new_status = ($row['payment_status'] == 'paid') ? 'unpaid' : 'paid';
        
        // 3. Update the database
        $conn->query("UPDATE requests SET payment_status = '$new_status' WHERE request_id = $request_id");
    }
}

// 4. Redirect back to the view page
header("Location: view_request.php");
exit;
?>