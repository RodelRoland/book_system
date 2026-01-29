<?php

// =================================================================
//   ONE-TIME DATABASE SETUP SCRIPT — RUN THIS ONLY ONCE!
// =================================================================

global $conn;
require_once 'db.php';

echo "<pre>";

// ── Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS book_distribution_system");
$conn->select_db("book_distribution_system");

// ── 1. admins
$conn->query("
    CREATE TABLE IF NOT EXISTS admins (
        admin_id      INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50) NOT NULL UNIQUE,
        password      VARCHAR(255) NOT NULL
    )
");

// ── 2. students
$conn->query("
    CREATE TABLE IF NOT EXISTS students (
        student_id    INT AUTO_INCREMENT PRIMARY KEY,
        index_number  VARCHAR(50) NOT NULL UNIQUE,
        full_name     VARCHAR(100) NOT NULL,
        phone         VARCHAR(20) NULL,
        credit_balance  DECIMAL(10,2) DEFAULT 0.00
    )
");

// ── 3. books
$conn->query("
    CREATE TABLE IF NOT EXISTS books (
        book_id       INT AUTO_INCREMENT PRIMARY KEY,
        book_title    VARCHAR(150) NOT NULL,
        price         DECIMAL(10,2) NOT NULL,
        stock_quantity INT NOT NULL DEFAULT 0,
        availability  ENUM('available','out_of_stock') DEFAULT 'available'
    )
");

// Migrate existing installs: add stock_quantity if missing
$colCheck = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books' AND COLUMN_NAME = 'stock_quantity' LIMIT 1");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE books ADD COLUMN stock_quantity INT NOT NULL DEFAULT 0");
}

// ── 4. requests
$conn->query("
    CREATE TABLE IF NOT EXISTS requests (
        request_id      INT AUTO_INCREMENT PRIMARY KEY,
        student_id      INT NOT NULL,
        total_amount    DECIMAL(10,2) NOT NULL,
        amount_paid     DECIMAL(10,2) DEFAULT 0.00,
        payment_status  ENUM('paid','unpaid') DEFAULT 'unpaid',
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
    )
");

// ── 5. request_items (junction table)
$conn->query("
    CREATE TABLE IF NOT EXISTS request_items (
        item_id          INT AUTO_INCREMENT PRIMARY KEY,
        request_id       INT NOT NULL,
        book_id          INT NOT NULL,
        is_collected     TINYINT(1) DEFAULT 0,
        FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
        FOREIGN KEY (book_id)    REFERENCES books(book_id) ON DELETE CASCADE
    )
");

// ── 6. lecturer_payments (track payments made to lecturers per book)
$conn->query("
    CREATE TABLE IF NOT EXISTS lecturer_payments (
        payment_id      INT AUTO_INCREMENT PRIMARY KEY,
        book_id         INT NOT NULL,
        copies_paid     INT NOT NULL,
        amount_paid     DECIMAL(10,2) NOT NULL,
        payment_date    DATE NOT NULL,
        notes           VARCHAR(255) NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
    )
");

// ── 7. books_received (track books received/taken from lecturers)
$conn->query("
    CREATE TABLE IF NOT EXISTS books_received (
        receive_id      INT AUTO_INCREMENT PRIMARY KEY,
        book_id         INT NOT NULL,
        copies_received INT NOT NULL,
        receive_date    DATE NOT NULL,
        lecturer_name   VARCHAR(100) NULL,
        notes           VARCHAR(255) NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
    )
");

// ── 8. Add indexes for better performance
function ensure_index($conn, $table, $indexName, $createSql) {
    $check = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($check && $check->num_rows === 0) {
        $conn->query($createSql);
    }
}

ensure_index($conn, 'requests', 'idx_requests_student', "CREATE INDEX idx_requests_student ON requests(student_id)");
ensure_index($conn, 'requests', 'idx_requests_status', "CREATE INDEX idx_requests_status ON requests(payment_status)");
ensure_index($conn, 'requests', 'idx_requests_date', "CREATE INDEX idx_requests_date ON requests(created_at)");
ensure_index($conn, 'request_items', 'idx_request_items_request', "CREATE INDEX idx_request_items_request ON request_items(request_id)");
ensure_index($conn, 'request_items', 'idx_request_items_book', "CREATE INDEX idx_request_items_book ON request_items(book_id)");
ensure_index($conn, 'lecturer_payments', 'idx_lecturer_payments_book', "CREATE INDEX idx_lecturer_payments_book ON lecturer_payments(book_id)");
ensure_index($conn, 'lecturer_payments', 'idx_lecturer_payments_date', "CREATE INDEX idx_lecturer_payments_date ON lecturer_payments(payment_date)");

if ($conn->error) {
    echo "Error: " . $conn->error . "\n";
} else {
    echo "All tables and indexes created successfully.\n";
}

echo "</pre>";

echo "<p><strong>Setup complete.</strong> You can now safely delete or move this file (setup.php).</p>";