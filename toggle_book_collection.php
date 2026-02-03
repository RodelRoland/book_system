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

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

if(isset($_GET['item_id'])) {
    $item_id = intval($_GET['item_id']);

    if ($item_id <= 0) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid item']);
            exit;
        }
        header("Location: view_request.php?msg=invalid_item");
        exit;
    }

    // Simply toggle is_collected (no stock restriction)
    if ($is_super_admin) {
        $stmt = $conn->prepare("UPDATE request_items SET is_collected = 1 - is_collected WHERE item_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $item_id);
            $stmt->execute();
            $result = ($stmt->affected_rows >= 0);
        } else {
            $result = false;
        }
    } else {
        // Rep: only toggle if item belongs to one of their requests
        $stmt = $conn->prepare("UPDATE request_items ri JOIN requests r ON r.request_id = ri.request_id SET ri.is_collected = 1 - ri.is_collected WHERE ri.item_id = ? AND r.admin_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $item_id, $current_admin_id);
            $stmt->execute();
            $result = ($stmt->affected_rows === 1);
        } else {
            $result = false;
        }
    }

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
    if ($is_super_admin) {
        $status_stmt = $conn->prepare("SELECT is_collected FROM request_items WHERE item_id = ?");
        if ($status_stmt) {
            $status_stmt->bind_param('i', $item_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
        } else {
            $status_result = false;
        }
    } else {
        $status_stmt = $conn->prepare("SELECT ri.is_collected FROM request_items ri JOIN requests r ON r.request_id = ri.request_id WHERE ri.item_id = ? AND r.admin_id = ?");
        if ($status_stmt) {
            $status_stmt->bind_param('ii', $item_id, $current_admin_id);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
        } else {
            $status_result = false;
        }
    }

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