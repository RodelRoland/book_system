<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

// Fetch available books
$books_res = $conn->query("SELECT * FROM books WHERE availability = 'available'");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $index_number = $conn->real_escape_string($_POST['index_number']);
    
    // logic: if phone is empty, it remains an empty string
    $phone = isset($_POST['phone']) ? $conn->real_escape_string($_POST['phone']) : '';
    
    $selected_books = isset($_POST['books']) ? $_POST['books'] : [];
    $cash_received = floatval($_POST['cash_received']);

    if (!empty($selected_books)) {
        $duplicate_titles = [];
        foreach ($selected_books as $book_id) {
            $book_id = intval($book_id);
            $check_sql = "SELECT b.book_title 
                          FROM request_items ri
                          JOIN requests r ON ri.request_id = r.request_id
                          JOIN students s ON r.student_id = s.student_id
                          JOIN books b ON ri.book_id = b.book_id
                          WHERE s.index_number = '$index_number' AND ri.book_id = '$book_id'";
            
            $check_res = $conn->query($check_sql);
            if ($check_res && $check_res->num_rows > 0) {
                $row = $check_res->fetch_assoc();
                $duplicate_titles[] = $row['book_title'];
            }
        }

        if (!empty($duplicate_titles)) {
            $error = "Duplicate Request: Student has already received: " . implode(", ", $duplicate_titles);
        } else {
            // INSERT with phone as optional
            $conn->query("INSERT INTO students (index_number, full_name, phone) 
                          VALUES ('$index_number', '$full_name', '$phone') 
                          ON DUPLICATE KEY UPDATE full_name='$full_name', phone='$phone'");
            
            $student_id_res = $conn->query("SELECT student_id FROM students WHERE index_number = '$index_number'");
            $student_id = $student_id_res->fetch_assoc()['student_id'];

            $total_amount = 0;
            $ids = implode(',', array_map('intval', $selected_books));
            $price_res = $conn->query("SELECT SUM(price) as total FROM books WHERE book_id IN ($ids)");
            $total_amount = $price_res->fetch_assoc()['total'];

            $sql_request = "INSERT INTO requests (student_id, total_amount, amount_paid, payment_status, created_at) 
                            VALUES ('$student_id', '$total_amount', '$cash_received', 'paid', NOW())";
            
            if ($conn->query($sql_request)) {
                $request_id = $conn->insert_id;
                foreach ($selected_books as $book_id) {
                    $book_id = intval($book_id);
                    $conn->query("INSERT INTO request_items (request_id, book_id) VALUES ('$request_id', '$book_id')");
                }
                header("Location: view_request.php?msg=manual_success");
                exit;
            } else {
                $error = "Database Error: " . $conn->error;
            }
        }
    } else {
        $error = "Please select at least one book.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manual Order Entry</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f0f2f5; padding: 20px; }
        .container { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .back-link { display: inline-block; margin-bottom: 15px; text-decoration: none; color: #007bff; font-weight: 600; font-size: 14px; }
        .input-field { width: 100%; padding: 12px; margin-bottom: 12px; border: 1px solid #00bcd4; border-radius: 8px; box-sizing: border-box; }
        h4 { margin: 15px 0 8px 0; font-size: 16px; color: #333; }
        .book-container { max-height: 150px; overflow-y: auto; border: 1px solid #eee; padding: 5px 10px; border-radius: 5px; background: #fafafa; margin-bottom: 20px; }
        .book-item { padding: 3px 0; font-size: 14px; border-bottom: 1px solid #f0f0f0; }
        .cash-input { width: 120px; padding: 10px; border: 2px solid #28a745; border-radius: 6px; font-size: 16px; font-weight: bold; margin-top: 5px; }
        .primary-btn { width: 100%; padding: 14px; background: #28a745; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 15px; }
        .error-box { background: #ffe3e3; color: #d63031; padding: 12px; border: 1px solid #ff0000; border-radius: 8px; margin-bottom: 15px; font-size: 14px; line-height: 1.4; }
    </style>
</head>
<body>
<div class="container">
    <a href="admin.php" class="back-link">← Back to Dashboard</a>
    <h2 style="margin-top: 0; color: #006064;">Manual Book Issue</h2>

    <?php if (isset($error)): ?>
        <div class="error-box"><strong>Error:</strong><br><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="post">
        <input type="text" name="full_name" class="input-field" placeholder="Student Full Name" value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>" required>
        
        <input type="text" name="index_number" class="input-field" placeholder="Index Number" value="<?php echo isset($_POST['index_number']) ? htmlspecialchars($_POST['index_number']) : ''; ?>" required>
        
        <input type="text" name="phone" class="input-field" placeholder="Phone Number (Optional)" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
        
        <h4>Select Books:</h4>
        <div class="book-container">
            <?php if ($books_res && $books_res->num_rows > 0): ?>
                <?php while($b = $books_res->fetch_assoc()): ?>
                    <div class="book-item">
                        <label style="cursor: pointer;">
                            <input type="checkbox" name="books[]" value="<?php echo $b['book_id']; ?>"> 
                            <?php echo htmlspecialchars($b['book_title']); ?> 
                            <span style="color:#666;">(GH₵<?php echo number_format($b['price'], 2); ?>)</span>
                        </label>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="font-size: 12px; color: red;">No books available in database.</p>
            <?php endif; ?>
        </div>

        <div style="margin-bottom: 10px;">
            <label style="font-size: 14px; font-weight: bold; display: block;">Cash Received (GH₵):</label>
            <input type="number" step="0.01" name="cash_received" class="cash-input" placeholder="0.00" value="<?php echo isset($_POST['cash_received']) ? htmlspecialchars($_POST['cash_received']) : ''; ?>" required>
        </div>
        
        <button type="submit" class="primary-btn">Issue Books & Record Payment</button>
    </form>
</div>
</body>
</html>