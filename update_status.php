<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
// Security: Only logged-in admins can update status
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
    // Get the ID from the URL and make sure it's a number
    $request_id = intval($_POST['id'] ?? 0);

    if ($request_id <= 0) {
        header("Location: view_request.php?msg=invalid_request");
        exit;
    }

    // Update the payment status in the database
    if ($is_super_admin) {
        $stmt = $conn->prepare("UPDATE requests SET payment_status = 'paid', amount_paid = GREATEST(0, total_amount - credit_used) WHERE request_id = ?");
        $stmt->bind_param('i', $request_id);
    } else {
        $stmt = $conn->prepare("UPDATE requests SET payment_status = 'paid', amount_paid = GREATEST(0, total_amount - credit_used) WHERE request_id = ? AND admin_id = ?");
        $stmt->bind_param('ii', $request_id, $current_admin_id);
    }

    if ($stmt && $stmt->execute()) {
        if (!$is_super_admin && $stmt->affected_rows !== 1) {
            header("Location: view_request.php?msg=unauthorized");
            exit;
        }
        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'mark_paid', 'request', $request_id, [
                'payment_status' => 'paid',
            ]);
        }
        // Go back to the view requests page with a success message
        header("Location: view_request.php?msg=paid_success");
    } else {
        echo "Error updating record: " . $conn->error;
    }

} else {
    echo "No ID provided.";
}
exit;
?>

