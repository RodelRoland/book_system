<?php
// Test different connection methods
echo "Testing database connections...\n\n";

// Test 1: 127.0.0.1
echo "1. Testing 127.0.0.1:3306...\n";
try {
    $conn1 = new mysqli('127.0.0.1', 'root', '', 'book_distribution_system', 3306);
    if ($conn1->connect_error) {
        echo "   FAILED: " . $conn1->connect_error . "\n";
    } else {
        echo "   SUCCESS!\n";
        $conn1->close();
    }
} catch (Exception $e) {
    echo "   EXCEPTION: " . $e->getMessage() . "\n";
}

// Test 2: localhost
echo "\n2. Testing localhost:3306...\n";
try {
    $conn2 = new mysqli('localhost', 'root', '', 'book_distribution_system', 3306);
    if ($conn2->connect_error) {
        echo "   FAILED: " . $conn2->connect_error . "\n";
    } else {
        echo "   SUCCESS!\n";
        $conn2->close();
    }
} catch (Exception $e) {
    echo "   EXCEPTION: " . $e->getMessage() . "\n";
}

// Test 3: 127.0.0.1 without database
echo "\n3. Testing 127.0.0.1:3306 (no database)...\n";
try {
    $conn3 = new mysqli('127.0.0.1', 'root', '', '', 3306);
    if ($conn3->connect_error) {
        echo "   FAILED: " . $conn3->connect_error . "\n";
    } else {
        echo "   SUCCESS!\n";
        // Check if database exists
        $result = $conn3->query("SHOW DATABASES LIKE 'book_distribution_system'");
        if ($result && $result->num_rows > 0) {
            echo "   Database 'book_distribution_system' exists\n";
        } else {
            echo "   Database 'book_distribution_system' NOT FOUND\n";
        }
        $conn3->close();
    }
} catch (Exception $e) {
    echo "   EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
?>
