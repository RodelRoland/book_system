<?php
include 'db.php';

// Turn off error reporting to prevent HTML error messages from breaking the response
error_reporting(0); 

if (isset($_POST['index_number'])) {
    $index = $conn->real_escape_string($_POST['index_number']);
    $sql = "SELECT full_name FROM students WHERE index_number = '$index' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo $row['full_name']; 
    } else {
        echo "Student Not Found";
    }
}
exit; // Ensure nothing else is sent after the name
?>