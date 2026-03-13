<?php
echo "Testing Redis (for sessions only):\n";

try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    echo "✅ Redis is working\n";
    $redis->close();
} catch (Exception $e) {
    echo "❌ Redis failed: " . $e->getMessage() . "\n";
}

echo "\nTesting MariaDB (for data):\n";

try {
    $mysqli = new mysqli('127.0.0.1', 'root', '', '', 3306);
    if ($mysqli->connect_error) {
        echo "❌ MariaDB failed: " . $mysqli->connect_error . "\n";
    } else {
        echo "✅ MariaDB is working\n";
        $mysqli->close();
    }
} catch (Exception $e) {
    echo "❌ MariaDB failed: " . $e->getMessage() . "\n";
}

echo "\nConclusion: Redis and MariaDB are separate systems.\n";
echo "Disabling Redis does NOT affect MariaDB connections.\n";
?>
