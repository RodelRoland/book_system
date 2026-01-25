<?php
include 'db.php';

if (isset($_GET['id'])) {
    $id = $conn->real_escape_string($_GET['id']);

    // 1. Delete the book items linked to this request first
    $conn->query("DELETE FROM request_items WHERE request_id = '$id'");

    // 2. Delete the main request
    $conn->query("DELETE FROM requests WHERE request_id = '$id'");

    header("Location: view_request.php?msg=Deleted");
}
?>