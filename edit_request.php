<?php
include 'db.php';

if (!isset($_GET['id'])) { die("Request ID missing."); }
$request_id = $conn->real_escape_string($_GET['id']);

// 1. Get current request and student details
$req_query = $conn->query("SELECT r.*, s.full_name FROM requests r JOIN students s ON r.student_id = s.student_id WHERE r.request_id = '$request_id'");
$request = $req_query->fetch_assoc();

// 2. Get currently selected book IDs for this request
$current_books = [];
$items_query = $conn->query("SELECT book_id FROM request_items WHERE request_id = '$request_id'");
while($item = $items_query->fetch_assoc()){
    $current_books[] = $item['book_id'];
}

// 3. Handle the Update Logic
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $selected_books = isset($_POST['books']) ? $_POST['books'] : [];
    $amount_paid = $conn->real_escape_string($_POST['amount_paid']);
    
    // Delete old items for this request
    $conn->query("DELETE FROM request_items WHERE request_id = '$request_id'");
    
    $new_total = 0;
    foreach ($selected_books as $book_id) {
        $book_id = $conn->real_escape_string($book_id);
        // Get book price to calculate new total
        $b_res = $conn->query("SELECT price FROM books WHERE book_id = '$book_id'");
        $b_data = $b_res->fetch_assoc();
        $new_total += $b_data['price'];
        
        // Insert back (marking as not collected by default, or you can preserve status)
        $conn->query("INSERT INTO request_items (request_id, book_id, is_collected) VALUES ('$request_id', '$book_id', 0)");
    }
    
    $status = ($amount_paid >= $new_total) ? 'paid' : 'unpaid';
    
    // Update the main request table
    $conn->query("UPDATE requests SET total_amount = '$new_total', amount_paid = '$amount_paid', payment_status = '$status' WHERE request_id = '$request_id'");
    
    header("Location: view_request.php?msg=updated");
    exit();
}

// 4. Get all available books for the checkboxes
$all_books = $conn->query("SELECT * FROM books");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Request Items</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f4f4; padding: 20px; }
        .box { background: white; padding: 25px; max-width: 500px; margin: auto; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .book-item { padding: 8px; border-bottom: 1px solid #eee; display: flex; align-items: center; }
        .book-item input { margin-right: 15px; transform: scale(1.2); }
        label { font-weight: bold; display: block; margin-top: 15px; }
        input[type="number"] { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 5px; }
        .save-btn { width: 100%; padding: 12px; background: #28a745; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="box">
        <h3>Edit Request: <?php echo $request['full_name']; ?></h3>
        <form method="POST">
            <label>Select/Unselect Books:</label>
            <div style="background: #fafafa; border: 1px solid #ddd; padding: 10px; border-radius: 8px; margin-top: 5px; max-height: 250px; overflow-y: auto;">
                <?php while($book = $all_books->fetch_assoc()): ?>
                    <div class="book-item">
                        <input type="checkbox" name="books[]" value="<?php echo $book['book_id']; ?>" 
                            <?php echo in_array($book['book_id'], $current_books) ? 'checked' : ''; ?>>
                        <span><?php echo $book['book_title']; ?> (GH₵ <?php echo $book['price']; ?>)</span>
                    </div>
                <?php endwhile; ?>
            </div>

            <label>Amount Paid (GH₵):</label>
            <input type="number" step="0.01" name="amount_paid" value="<?php echo $request['amount_paid']; ?>">

            <button type="submit" class="save-btn">Update Request & Total</button>
            <a href="view_request.php" style="display:block; text-align:center; margin-top:15px; color:#666; text-decoration:none; font-size:14px;">Cancel</a>
        </form>
    </div>
</body>
</html>