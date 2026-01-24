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

// ── 1. students
$conn->query("
    CREATE TABLE IF NOT EXISTS students (
        student_id    INT AUTO_INCREMENT PRIMARY KEY,
        index_number  VARCHAR(10) NOT NULL UNIQUE,
        full_name     VARCHAR(255) NOT NULL,
        phone         VARCHAR(20) NOT NULL
    )
");

// ── 2. books
$conn->query("
    CREATE TABLE IF NOT EXISTS books (
        book_id       INT AUTO_INCREMENT PRIMARY KEY,
        book_title    VARCHAR(255) NOT NULL,
        price         DECIMAL(10,2) NOT NULL,
        availability  ENUM('available','out_of_stock') DEFAULT 'available'
    )
");

// ── 3. requests (Fixed with $conn->query wrapper and created_at column)
$conn->query("
    CREATE TABLE IF NOT EXISTS requests (
        request_id      INT AUTO_INCREMENT PRIMARY KEY,
        student_id      INT NOT NULL,
        total_amount    DECIMAL(10,2) NOT NULL,
        payment_status  ENUM('unpaid','paid') DEFAULT 'unpaid',
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
    )
");

// ── 4. request_items (junction table)
$conn->query("
    CREATE TABLE IF NOT EXISTS request_items (
        request_item_id  INT AUTO_INCREMENT PRIMARY KEY,
        request_id       INT NOT NULL,
        book_id          INT NOT NULL,
        status           ENUM('pending','given_out') DEFAULT 'pending',
        FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
        FOREIGN KEY (book_id)    REFERENCES books(book_id) ON DELETE CASCADE
    )
");

if ($conn->error) {
    echo "Error: " . $conn->error . "\n";
} else {
    echo "All tables created successfully (or already existed).\n";
}

echo "</pre>";

echo "<p><strong>Setup complete.</strong> You can now safely delete or move this file (setup.php).</p>";