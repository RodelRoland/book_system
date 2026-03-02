<?php
/**
 * Redis-based cache helper for high-performance data caching
 * Falls back to file-based caching if Redis is not available
 */

define('CACHE_DEFAULT_TTL', 300); // 5 minutes default
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('CACHE_PREFIX', 'book_system:');

// File cache fallback directory
define('CACHE_DIR', __DIR__ . '/cache/');

$GLOBALS['redis_connection'] = null;
$GLOBALS['use_redis'] = false;

/**
 * Initialize Redis connection
 */
function init_redis() {
    if ($GLOBALS['redis_connection'] !== null) {
        return $GLOBALS['use_redis'];
    }
    
    if (class_exists('Redis')) {
        try {
            $redis = new Redis();
            $connected = @$redis->connect(REDIS_HOST, REDIS_PORT, 0.2);
            if ($connected) {
                $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
                $GLOBALS['redis_connection'] = $redis;
                $GLOBALS['use_redis'] = true;
                return true;
            }
        } catch (Exception $e) {
            // Redis not available, fall back to file cache
        }
    }
    
    // Fallback: ensure file cache directory exists
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    $GLOBALS['use_redis'] = false;
    return false;
}

// Initialize on load
init_redis();

/**
 * Get cached data or execute callback to fetch fresh data
 */
function cache_get($key, $ttl = CACHE_DEFAULT_TTL, $callback = null) {
    $prefixed_key = CACHE_PREFIX . $key;
    
    if ($GLOBALS['use_redis'] && $GLOBALS['redis_connection']) {
        $data = $GLOBALS['redis_connection']->get($prefixed_key);
        if ($data !== false) {
            return $data;
        }
    } else {
        // File-based fallback
        $cache_file = CACHE_DIR . md5($key) . '.cache';
        if (file_exists($cache_file)) {
            $cache_data = @unserialize(file_get_contents($cache_file));
            if ($cache_data && isset($cache_data['expires']) && $cache_data['expires'] > time()) {
                return $cache_data['data'];
            }
        }
    }
    
    // If callback provided, get fresh data and cache it
    if ($callback && is_callable($callback)) {
        $data = $callback();
        cache_set($key, $data, $ttl);
        return $data;
    }
    
    return null;
}

/**
 * Set cache data
 */
function cache_set($key, $data, $ttl = CACHE_DEFAULT_TTL) {
    $prefixed_key = CACHE_PREFIX . $key;
    
    if ($GLOBALS['use_redis'] && $GLOBALS['redis_connection']) {
        $GLOBALS['redis_connection']->setex($prefixed_key, $ttl, $data);
    } else {
        // File-based fallback
        $cache_file = CACHE_DIR . md5($key) . '.cache';
        $cache_data = [
            'expires' => time() + $ttl,
            'data' => $data
        ];
        @file_put_contents($cache_file, serialize($cache_data), LOCK_EX);
    }
}

/**
 * Clear specific cache
 */
function cache_clear($key) {
    $prefixed_key = CACHE_PREFIX . $key;
    
    if ($GLOBALS['use_redis'] && $GLOBALS['redis_connection']) {
        $GLOBALS['redis_connection']->del($prefixed_key);
    } else {
        $cache_file = CACHE_DIR . md5($key) . '.cache';
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }
    }
}

/**
 * Clear all cache (with pattern matching for Redis)
 */
function cache_clear_all() {
    if ($GLOBALS['use_redis'] && $GLOBALS['redis_connection']) {
        $keys = $GLOBALS['redis_connection']->keys(CACHE_PREFIX . '*');
        if (!empty($keys)) {
            $GLOBALS['redis_connection']->del($keys);
        }
    } else {
        $files = glob(CACHE_DIR . '*.cache');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

/**
 * Check if Redis is being used
 */
function is_using_redis() {
    return $GLOBALS['use_redis'];
}

/**
 * Get books list with caching (used on multiple pages)
 */
function get_cached_books($conn, $available_only = true) {
    $cache_key = 'books_list_' . ($available_only ? 'available' : 'all');
    
    return cache_get($cache_key, 300, function() use ($conn, $available_only) {
        $where = $available_only ? "WHERE availability = 'available'" : "";
        $result = $conn->query("SELECT book_id, book_title, price, availability FROM books $where ORDER BY book_title ASC");
        $books = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $books[] = $row;
            }
        }
        return $books;
    });
}

/**
 * Clear books cache (call when books are modified)
 */
function clear_books_cache() {
    cache_clear('books_list_available');
    cache_clear('books_list_all');
}

/**
 * Ensure database indexes exist for better performance
 */
function ensure_db_indexes($conn) {
    static $indexes_checked = false;
    if ($indexes_checked) return;
    
    $indexes = [
        "CREATE INDEX idx_requests_semester ON requests(semester_id)",
        "CREATE INDEX idx_requests_admin ON requests(admin_id)",
        "CREATE INDEX idx_requests_student ON requests(student_id)",
        "CREATE INDEX idx_requests_created ON requests(created_at)",
        "CREATE INDEX idx_requests_sem_admin_created ON requests(semester_id, admin_id, created_at)",
        "CREATE INDEX idx_requests_student_sem ON requests(student_id, semester_id)",
        "CREATE INDEX idx_students_index ON students(index_number)",
        "CREATE INDEX idx_students_admin ON students(admin_id)",
        "CREATE INDEX idx_students_admin_index ON students(admin_id, index_number)",
        "CREATE INDEX idx_request_items_request ON request_items(request_id)",
        "CREATE INDEX idx_request_items_book ON request_items(book_id)",
        "CREATE INDEX idx_request_items_book_collected ON request_items(book_id, is_collected)",
        "CREATE INDEX idx_request_items_request_collected ON request_items(request_id, is_collected)",
        "CREATE INDEX idx_lecturer_payments_sem_admin_book ON lecturer_payments(semester_id, admin_id, book_id)",
        "CREATE INDEX idx_books_received_sem_admin_book ON books_received(semester_id, admin_id, book_id)",
        "CREATE INDEX idx_class_students_admin ON class_students(admin_id)",
        "CREATE INDEX idx_class_students_index ON class_students(index_number)"
    ];
    
    foreach ($indexes as $sql) {
        @$conn->query($sql);
    }
    
    $indexes_checked = true;
}
