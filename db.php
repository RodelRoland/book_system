<?php
$conn = new mysqli(
    "localhost",
    "root", "",
    "book_distribution_system",
    3308);

if ($conn->connect_error) {
    die("Database connection failed");
}

