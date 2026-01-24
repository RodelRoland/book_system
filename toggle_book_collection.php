<?php
include 'db.php';

if(isset($_GET['item_id'])) {
    $item_id = intval($_GET['item_id']);
    // This query flips the 0 to 1 or 1 to 0
    $conn->query("UPDATE request_items SET is_collected = 1 - is_collected WHERE item_id = $item_id");
    
    header("Location: view_request.php");
    exit;
}
?>