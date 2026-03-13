<?php
echo "Creating proper backup...\n";

$backup_file = 'C:\xampp\htdocs\book_system\backup_' . date('Y-m-d_H-i-s') . '.sql';
$command = 'C:\xampp\mysql\bin\mysqldump.exe -u root -h 127.0.0.1 book_distribution_system > "' . $backup_file . '" 2>&1';

echo "Running: $command\n";
$output = [];
$return_code = 0;
exec($command, $output, $return_code);

echo "Return code: $return_code\n";
echo "Output: " . implode("\n", $output) . "\n";

if ($return_code === 0 && file_exists($backup_file)) {
    $size = filesize($backup_file);
    echo "✅ Backup created: $backup_file\n";
    echo "Size: " . number_format($size) . " bytes\n";
    
    if ($size > 100) {
        echo "✅ Backup appears successful!\n";
    } else {
        echo "❌ Backup too small - likely failed\n";
    }
} else {
    echo "❌ Backup failed\n";
}

// List all backup files
echo "\nAll backup files:\n";
$files = glob('C:\xampp\htdocs\book_system\backup_*.sql');
foreach ($files as $file) {
    $size = filesize($file);
    echo "- " . basename($file) . " (" . number_format($size) . " bytes)\n";
}
?>
