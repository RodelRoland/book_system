<?php
// Simple backup that doesn't rely on db.php
echo "Creating database backup...\n";

$backup_file = 'C:\xampp\htdocs\book_system\backup_' . date('Y-m-d_H-i-s') . '.sql';
$command = 'C:\xampp\mysql\bin\mysqldump.exe -u root --single-compact --routines --triggers book_distribution_system > "' . $backup_file . '" 2>&1';

exec($command, $output, $return_code);

if ($return_code === 0 && file_exists($backup_file)) {
    echo "✅ Backup successful: $backup_file\n";
    echo "File size: " . number_format(filesize($backup_file)) . " bytes\n";
} else {
    echo "❌ Backup failed\n";
    echo "Command: $command\n";
    echo "Output: " . implode("\n", $output) . "\n";
}
?>
