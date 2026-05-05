<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
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
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
    }
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Rep workspace access is required']);
        exit;
    }
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'CSRF invalid']);
            exit;
        }
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
    $item_id = intval($_POST['item_id'] ?? 0);

    if ($item_id <= 0) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid item']);
            exit;
        }
        header("Location: view_request.php?msg=invalid_item");
        exit;
    }

    // Get current status first to determine if we're collecting or uncollecting
    if ($is_super_admin) {
        $current_stmt = $conn->prepare("SELECT is_collected, COALESCE(is_cancelled, 0) AS is_cancelled FROM request_items WHERE item_id = ?");
    } else {
        $current_stmt = $conn->prepare("SELECT ri.is_collected, COALESCE(ri.is_cancelled, 0) AS is_cancelled FROM request_items ri JOIN requests r ON r.request_id = ri.request_id WHERE ri.item_id = ? AND r.admin_id = ?");
    }
    
    $current_status = 0;
    $is_cancelled = 0;
    if ($current_stmt) {
        if ($is_super_admin) {
            $current_stmt->bind_param('i', $item_id);
        } else {
            $current_stmt->bind_param('ii', $item_id, $current_admin_id);
        }
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        if ($current_result && $current_result->num_rows === 1) {
            $current_row = $current_result->fetch_assoc();
            $current_status = intval($current_row['is_collected'] ?? 0);
            $is_cancelled = intval($current_row['is_cancelled'] ?? 0);
        }
    }

    if ($is_cancelled === 1) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Cancelled items cannot be collected']);
            exit;
        }
        header("Location: view_request.php?msg=cancelled_item");
        exit;
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
        if (isset($_POST['ajax'])) {
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

    if (function_exists('book_system_audit_log')) {
        book_system_audit_log($conn, 'toggle_collection', 'request_item', $item_id, [
            'is_collected' => $new_status,
        ]);
    }

    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'is_collected' => $new_status]);
        exit;
    }

    header("Location: view_request.php");
    exit;
}
?>

