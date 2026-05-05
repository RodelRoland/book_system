<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php';

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
    $request_id = intval($_POST['request_id'] ?? 0);

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
        
        // 3. Update the database - also update amount_paid when marking as paid
        if ($new_status === 'paid') {
            // When marking as paid, set amount_paid to remaining due (total_amount - credit_used)
            if ($is_super_admin) {
                $upd = $conn->prepare("UPDATE requests SET payment_status = ?, amount_paid = GREATEST(0, total_amount - credit_used) WHERE request_id = ?");
                $upd->bind_param('si', $new_status, $request_id);
            } else {
                $upd = $conn->prepare("UPDATE requests SET payment_status = ?, amount_paid = GREATEST(0, total_amount - credit_used) WHERE request_id = ? AND admin_id = ?");
                $upd->bind_param('sii', $new_status, $request_id, $current_admin_id);
            }
        } else {
            // When marking as unpaid, reset amount_paid to 0 for consistency
            if ($is_super_admin) {
                $upd = $conn->prepare("UPDATE requests SET payment_status = ?, amount_paid = 0 WHERE request_id = ?");
                $upd->bind_param('si', $new_status, $request_id);
            } else {
                $upd = $conn->prepare("UPDATE requests SET payment_status = ?, amount_paid = 0 WHERE request_id = ? AND admin_id = ?");
                $upd->bind_param('sii', $new_status, $request_id, $current_admin_id);
            }
        }
        $upd->execute();
        if ($upd->affected_rows >= 0 && function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'toggle_payment', 'request', $request_id, [
                'payment_status' => $new_status,
            ]);
        }
    }
}

// 4. Redirect back to the view page
header("Location: view_request.php");
exit;
?>

