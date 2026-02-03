<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php';

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

if (isset($_GET['request_id'])) {
    $request_id = intval($_GET['request_id']);

    if ($request_id <= 0) {
        header("Location: view_request.php?msg=invalid_request");
        exit;
    }

    // 1. Get current status
    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT payment_status FROM requests WHERE request_id = ?");
        $stmt->bind_param('i', $request_id);
    } else {
        $stmt = $conn->prepare("SELECT payment_status FROM requests WHERE request_id = ? AND admin_id = ?");
        $stmt->bind_param('ii', $request_id, $current_admin_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        // 2. Flip the status
        $new_status = ($row['payment_status'] == 'paid') ? 'unpaid' : 'paid';
        
        // 3. Update the database
        if ($is_super_admin) {
            $upd = $conn->prepare("UPDATE requests SET payment_status = ? WHERE request_id = ?");
            $upd->bind_param('si', $new_status, $request_id);
        } else {
            $upd = $conn->prepare("UPDATE requests SET payment_status = ? WHERE request_id = ? AND admin_id = ?");
            $upd->bind_param('sii', $new_status, $request_id, $current_admin_id);
        }
        $upd->execute();
    }
}

// 4. Redirect back to the view page
header("Location: view_request.php");
exit;
?>