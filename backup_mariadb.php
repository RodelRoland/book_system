<?php
// Backup MariaDB data before migration
require_once 'db.php';

if (!$conn) {
    die("Database connection failed. Cannot backup.");
}

echo "Starting backup...\n";

// Get all tables
$tables = [];
$result = $conn->query("SHOW TABLES");
if ($result) {
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
}

$backup_file = 'C:\xampp\htdocs\book_system\backup_' . date('Y-m-d_H-i-s') . '.sql';
$handle = fopen($backup_file, 'w');

if (!$handle) {
    die("Cannot create backup file: $backup_file");
}

fwrite($handle, "-- Backup created on " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "-- From MariaDB database: book_distribution_system\n\n");

foreach ($tables as $table) {
    echo "Backing up table: $table\n";
    
    // Get table structure
    $result = $conn->query("SHOW CREATE TABLE `$table`");
    if ($result) {
        $row = $result->fetch_row();
        fwrite($handle, "-- Table structure for `$table`\n");
        fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($handle, $row[1] . ";\n\n");
    }
    
    // Get table data
    $result = $conn->query("SELECT * FROM `$table`");
    if ($result && $result->num_rows > 0) {
        fwrite($handle, "-- Data for `$table`\n");
        while ($row = $result->fetch_assoc()) {
            $values = array_map(function($value) use ($conn) {
                if ($value === null) return 'NULL';
                return "'" . $conn->real_escape_string($value) . "'";
            }, $row);
            fwrite($handle, "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n");
        }
        fwrite($handle, "\n");
    }
}

fclose($handle);
echo "Backup completed: $backup_file\n";
echo "Tables backed up: " . implode(', ', $tables) . "\n";
?>
