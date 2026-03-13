<?php

mysqli_report(MYSQLI_REPORT_OFF);

// =========================
// DATABASE CONFIGURATION
// =========================
$db_user = "book_user";
$db_pass = "1234";
$db_name = "book_distribution_system";

$conn = null;
$last_error = '';

// Try common XAMPP MySQL/MariaDB host/port combinations
$hosts_ports = [
    ['host' => '127.0.0.1', 'port' => 3306],
    ['host' => '127.0.0.1', 'port' => 3307],
    ['host' => 'localhost', 'port' => 3306],
];

foreach ($hosts_ports as $hp) {
    try {
        $test_conn = @new mysqli(
            $hp['host'],
            $db_user,
            $db_pass,
            $db_name,
            $hp['port']
        );

        if (!$test_conn->connect_error) {
            $conn = $test_conn;
            break;
        }

        $last_error = $test_conn->connect_error;
    } catch (Throwable $e) {
        $last_error = $e->getMessage();
    }
}

function csrf_get_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!headers_sent()) {
            @session_start();
        }
    }

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $_SESSION['csrf_token'] = bin2hex((string) microtime(true) . (string) mt_rand());
        }
    }

    return $_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

if (!$conn) {
    die("Database connection failed. Please confirm that MySQL is running in XAMPP, the database exists, and the user credentials are correct. Error: " . $last_error);
}