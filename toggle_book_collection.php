<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php';

if(isset($_GET['item_id'])) {
    $item_id = intval($_GET['item_id']);
    
    $itemRes = $conn->query("SELECT book_id, is_collected FROM request_items WHERE item_id = $item_id LIMIT 1");
    if (!$itemRes || $itemRes->num_rows !== 1) {
        header("Location: view_request.php");
        exit;
    }

    $itemRow = $itemRes->fetch_assoc();
    $book_id = intval($itemRow['book_id']);
    $current = intval($itemRow['is_collected']);
    $next = ($current === 1) ? 0 : 1;

    $conn->begin_transaction();
    try {
        if ($next === 1) {
            $stockRes = $conn->query("SELECT stock_quantity FROM books WHERE book_id = $book_id LIMIT 1 FOR UPDATE");
            if (!$stockRes || $stockRes->num_rows !== 1) {
                throw new Exception('Book not found');
            }
            $stockRow = $stockRes->fetch_assoc();
            $stock = intval($stockRow['stock_quantity']);
            if ($stock <= 0) {
                $conn->rollback();
                header("Location: view_request.php?msg=out_of_stock");
                exit;
            }

            $conn->query("UPDATE request_items SET is_collected = 1 WHERE item_id = $item_id");
            $conn->query("UPDATE books SET stock_quantity = stock_quantity - 1 WHERE book_id = $book_id");
        } else {
            $conn->query("UPDATE request_items SET is_collected = 0 WHERE item_id = $item_id");
            $conn->query("UPDATE books SET stock_quantity = stock_quantity + 1 WHERE book_id = $book_id");
        }

        $conn->query("UPDATE books SET availability = IF(stock_quantity <= 0, 'out_of_stock', 'available') WHERE book_id = $book_id");
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
    }

    header("Location: view_request.php");
    exit;
}
?>