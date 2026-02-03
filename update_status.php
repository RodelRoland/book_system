<?php
session_start();
// Security: Only logged-in admins can update status
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

include 'db.php';

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

if (isset($_GET['id'])) {
    // Get the ID from the URL and make sure it's a number
    $request_id = intval($_GET['id']);

    if ($request_id <= 0) {
        header("Location: view_request.php?msg=invalid_request");
        exit;
    }

    // Update the payment status in the database
    if ($is_super_admin) {
        $stmt = $conn->prepare("UPDATE requests SET payment_status = 'paid' WHERE request_id = ?");
        $stmt->bind_param('i', $request_id);
    } else {
        $stmt = $conn->prepare("UPDATE requests SET payment_status = 'paid' WHERE request_id = ? AND admin_id = ?");
        $stmt->bind_param('ii', $request_id, $current_admin_id);
    }

    if ($stmt && $stmt->execute()) {
        if (!$is_super_admin && $stmt->affected_rows !== 1) {
            header("Location: view_request.php?msg=unauthorized");
            exit;
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