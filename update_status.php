<?php
session_start();
// Security: Only logged-in admins can update status
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

include 'db.php';

if (isset($_GET['id'])) {
    // Get the ID from the URL and make sure it's a number
    $request_id = intval($_GET['id']);

    // Update the payment status in the database
    $sql = "UPDATE requests SET payment_status = 'paid' WHERE request_id = $request_id";
    
    if ($conn->query($sql)) {
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