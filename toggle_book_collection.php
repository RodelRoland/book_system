<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    header('Location: admin.php');
    exit;
}
include 'db.php';

if(isset($_GET['item_id'])) {
    $item_id = intval($_GET['item_id']);
    
    // Simply toggle is_collected (no stock restriction)
    $result = $conn->query("UPDATE request_items SET is_collected = 1 - is_collected WHERE item_id = $item_id");
    
    if (!$result) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Toggle failed']);
            exit;
        }
        header("Location: view_request.php?msg=toggle_failed");
        exit;
    }

    // Get new status
    $status_result = $conn->query("SELECT is_collected FROM request_items WHERE item_id = $item_id");
    $new_status = 0;
    if ($status_result && $status_result->num_rows === 1) {
        $new_status = intval($status_result->fetch_assoc()['is_collected']);
    }

    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'is_collected' => $new_status]);
        exit;
    }

    header("Location: view_request.php");
    exit;
}
?>