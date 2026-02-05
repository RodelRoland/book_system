<?php
echo "<h2>Redis Status Check</h2>";
echo "<pre>";

// Check PHP Redis extension
echo "1. PHP Redis Extension: ";
if (class_exists('Redis')) {
    echo "INSTALLED ✓\n";
} else {
    echo "NOT INSTALLED ✗\n";
    echo "</pre>";
    exit;
}

// Try to connect to Redis server
echo "2. Redis Server Connection: ";
try {
    $redis = new Redis();
    $connected = $redis->connect('127.0.0.1', 6379, 2);
    if ($connected) {
        echo "CONNECTED ✓\n";
        
        // Test ping
        echo "3. Redis PING: " . $redis->ping() . "\n";
        
        // Get server info
        $info = $redis->info();
        echo "4. Redis Version: " . $info['redis_version'] . "\n";
        echo "5. Uptime: " . $info['uptime_in_seconds'] . " seconds\n";
        echo "6. Connected Clients: " . $info['connected_clients'] . "\n";
        echo "7. Used Memory: " . $info['used_memory_human'] . "\n";
        
        // Test set/get
        echo "\n--- Cache Test ---\n";
        $redis->set('test_key', 'Hello from Redis!');
        echo "8. SET test_key: OK\n";
        echo "9. GET test_key: " . $redis->get('test_key') . "\n";
        $redis->del('test_key');
        echo "10. DEL test_key: OK\n";
        
        echo "\n*** REDIS IS WORKING PERFECTLY! ***\n";
    } else {
        echo "FAILED ✗\n";
    }
} catch (Exception $e) {
    echo "FAILED ✗\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nMake sure Redis server is running!\n";
    echo "If using Memurai on Windows, start it from Services or run: memurai.exe\n";
}

echo "</pre>";
