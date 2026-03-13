<?php
echo "Fixing MariaDB permissions...\n";

// Try to connect as root without database
try {
    $conn = new mysqli('127.0.0.1', 'root', '', '', 3306);
    if ($conn->connect_error) {
        echo "Cannot connect to MariaDB: " . $conn->connect_error . "\n";
        echo "You need to:\n";
        echo "1. Stop MariaDB in XAMPP\n";
        echo "2. Start it with: mysqld.exe --skip-grant-tables\n";
        echo "3. Run this script again\n";
        exit;
    }
    
    echo "Connected to MariaDB\n";
    
    // Fix permissions
    $conn->query("USE mysql");
    $conn->query("UPDATE user SET Host='%' WHERE User='root'");
    $conn->query("GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION");
    $conn->query("GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION");
    $conn->query("FLUSH PRIVILEGES");
    
    echo "✅ Permissions fixed!\n";
    echo "Now restart MariaDB normally in XAMPP\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
