<?php
$conn = new mysqli(
    "localhost",
    "root", "",
    "book_distribution_system",
    3306);

if ($conn->connect_error) {
    die("Database connection failed");
}

