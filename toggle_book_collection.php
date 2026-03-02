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
    if (!csrf_validate($_GET['csrf_token'] ?? null)) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'CSRF invalid']);
            exit;
        }
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
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

    // Get current status first to determine if we're collecting or uncollecting
    if ($is_super_admin) {
        $current_stmt = $conn->prepare("SELECT is_collected FROM request_items WHERE item_id = ?");
    } else {
        $current_stmt = $conn->prepare("SELECT ri.is_collected FROM request_items ri JOIN requests r ON r.request_id = ri.request_id WHERE ri.item_id = ? AND r.admin_id = ?");
    }
    
    $current_status = 0;
    if ($current_stmt) {
        if ($is_super_admin) {
            $current_stmt->bind_param('i', $item_id);
        } else {
            $current_stmt->bind_param('ii', $item_id, $current_admin_id);
        }
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        if ($current_result && $current_result->num_rows === 1) {
            $current_status = intval($current_result->fetch_assoc()['is_collected']);
        }
    }

    // Toggle is_collected and set/clear received_at accordingly
    $new_collected_status = 1 - $current_status;
    if ($new_collected_status === 1) {
        // Marking as collected - set received_at to NOW()
        if ($is_super_admin) {
            $stmt = $conn->prepare("UPDATE request_items SET is_collected = 1, received_at = NOW() WHERE item_id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE request_items ri JOIN requests r ON r.request_id = ri.request_id SET ri.is_collected = 1, ri.received_at = NOW() WHERE ri.item_id = ? AND r.admin_id = ?");
        }
    } else {
        // Unmarking as collected - clear received_at
        if ($is_super_admin) {
            $stmt = $conn->prepare("UPDATE request_items SET is_collected = 0, received_at = NULL WHERE item_id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE request_items ri JOIN requests r ON r.request_id = ri.request_id SET ri.is_collected = 0, ri.received_at = NULL WHERE ri.item_id = ? AND r.admin_id = ?");
        }
    }
    
    if ($stmt) {
        if ($is_super_admin) {
            $stmt->bind_param('i', $item_id);
        } else {
            $stmt->bind_param('ii', $item_id, $current_admin_id);
        }
        $stmt->execute();
        $result = ($stmt->affected_rows >= 0);
    } else {
        $result = false;
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