<?php
include 'db.php';

if (isset($_GET['index'])) {
    $index = $conn->real_escape_string($_GET['index']);
    
    // Find all Book IDs already linked to this Index Number
    $sql = "SELECT ri.book_id 
            FROM request_items ri
            JOIN requests r ON ri.request_id = r.request_id
            JOIN students s ON r.student_id = s.student_id
            WHERE s.index_number = '$index'";
            
    $result = $conn->query($sql);
    $owned_books = [];
    
    while ($row = $result->fetch_assoc()) {
        $owned_books[] = $row['book_id'];
    }
    
    // Send the list back to the browser as JSON
    echo json_encode($owned_books);
}
?>