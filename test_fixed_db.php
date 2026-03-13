<?php
require_once 'db.php';
echo "Testing fixed database connection...\n";
echo "Connection: " . ($conn ? "SUCCESS" : "FAILED") . "\n";

if ($conn) {
    echo "Database info:\n";
    echo "- Host: " . $conn->host_info . "\n";
    echo "- Server: " . $conn->server_info . "\n";
    
    // Test a simple query
    $result = $conn->query("SHOW TABLES");
    if ($result) {
        echo "- Tables count: " . $result->num_rows . "\n";
        echo "- First few tables:\n";
        $count = 0;
        while ($row = $result->fetch_row() && $count < 5) {
            echo "  * " . $row[0] . "\n";
            $count++;
        }
    }
}
?>
