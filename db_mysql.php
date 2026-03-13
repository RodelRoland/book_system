<?php
// MySQL 8.0 connection (port 3307)
mysqli_report(MYSQLI_REPORT_OFF);

$db_user = "root";
$db_pass = "";  // Set your MySQL root password
$db_name = "book_distribution_system";

// Try MySQL 8.0 first (port 3307), then fallback to MariaDB (port 3306)
$connections = [
    ['host' => '127.0.0.1', 'port' => 3307, 'type' => 'MySQL 8.0'],
    ['host' => '127.0.0.1', 'port' => 3306, 'type' => 'MariaDB'],
];

$conn = null;
$last_error = '';
$using_db = '';

foreach ($connections as $cfg) {
    try {
        $conn = new mysqli($cfg['host'], $db_user, $db_pass, $db_name, $cfg['port']);
        if (!$conn->connect_error) {
            $using_db = $cfg['type'];
            break;
        }
        $last_error = $cfg['type'] . ': ' . $conn->connect_error;
        $conn = null;
    } catch (Exception $e) {
        $last_error = $cfg['type'] . ': ' . $e->getMessage();
    }
}

if (!$conn) {
    die("Database connection failed. Tried MySQL 8.0 and MariaDB. Error: $last_error");
}

echo "Connected to: $using_db\n";

// All your existing table creation code will work here...
// The db.php code will automatically recreate all tables

?>
