<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php';

if (!isset($_GET['id'])) { die("Request ID missing."); }

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

$request_id = intval($_GET['id']);
if ($request_id <= 0) {
    header('Location: view_request.php?msg=invalid_request');
    exit;
}

// 1. Get current request and student details
if ($is_super_admin) {
    $req_stmt = $conn->prepare("SELECT r.*, s.full_name FROM requests r JOIN students s ON r.student_id = s.student_id WHERE r.request_id = ? LIMIT 1");
    $req_stmt->bind_param('i', $request_id);
} else {
    $req_stmt = $conn->prepare("SELECT r.*, s.full_name FROM requests r JOIN students s ON r.student_id = s.student_id WHERE r.request_id = ? AND r.admin_id = ? LIMIT 1");
    $req_stmt->bind_param('ii', $request_id, $current_admin_id);
}

$req_stmt->execute();
$req_query = $req_stmt->get_result();
$request = ($req_query && $req_query->num_rows === 1) ? $req_query->fetch_assoc() : null;
if (!$request) {
    header('Location: view_request.php?msg=unauthorized');
    exit;
}

// 2. Get currently selected book IDs for this request
$current_books = [];
$items_stmt = $conn->prepare("SELECT book_id FROM request_items WHERE request_id = ?");
$items_stmt->bind_param('i', $request_id);
$items_stmt->execute();
$items_query = $items_stmt->get_result();
if ($items_query) {
    while($item = $items_query->fetch_assoc()){
        $current_books[] = $item['book_id'];
    }
}

// 3. Handle the Update Logic
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $selected_books = isset($_POST['books']) ? $_POST['books'] : [];
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    
    // Delete old items for this request
    $del_stmt = $conn->prepare("DELETE FROM request_items WHERE request_id = ?");
    $del_stmt->bind_param('i', $request_id);
    $del_stmt->execute();
    
    $new_total = 0;
    foreach ($selected_books as $book_id) {
        $book_id = intval($book_id);
        // Get book price to calculate new total
        if ($book_id <= 0) {
            continue;
        }
        $b_stmt = $conn->prepare("SELECT price FROM books WHERE book_id = ? LIMIT 1");
        $b_stmt->bind_param('i', $book_id);
        $b_stmt->execute();
        $b_res = $b_stmt->get_result();
        $b_data = ($b_res && $b_res->num_rows === 1) ? $b_res->fetch_assoc() : null;
        if (!$b_data) {
            continue;
        }
        $new_total += floatval($b_data['price']);
        
        // Insert back (marking as not collected by default, or you can preserve status)
        $ins_stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id, is_collected) VALUES (?, ?, 0)");
        $ins_stmt->bind_param('ii', $request_id, $book_id);
        $ins_stmt->execute();
    }
    
    $status = ($amount_paid >= $new_total) ? 'paid' : 'unpaid';
    
    // Update the main request table
    if ($is_super_admin) {
        $upd_stmt = $conn->prepare("UPDATE requests SET total_amount = ?, amount_paid = ?, payment_status = ? WHERE request_id = ?");
        $upd_stmt->bind_param('ddsi', $new_total, $amount_paid, $status, $request_id);
    } else {
        $upd_stmt = $conn->prepare("UPDATE requests SET total_amount = ?, amount_paid = ?, payment_status = ? WHERE request_id = ? AND admin_id = ?");
        $upd_stmt->bind_param('ddsii', $new_total, $amount_paid, $status, $request_id, $current_admin_id);
    }
    $upd_stmt->execute();
    
    header("Location: view_request.php?msg=updated");
    exit();
}

// 4. Get all available books for the checkboxes
$all_books = $conn->query("SELECT * FROM books");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Request</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 500px; margin: 0 auto; }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 16px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .page-header h1 { font-size: 20px; font-weight: 600; }
        .page-header .subtitle { opacity: 0.9; margin-top: 3px; font-size: 13px; }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .books-list {
            max-height: 250px;
            overflow-y: auto;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .book-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        .book-item:hover { background: #f8f9fa; }
        .book-item:last-child { border-bottom: none; }
        .book-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 12px;
            cursor: pointer;
        }
        .book-item .title { flex: 1; font-weight: 500; color: #333; font-size: 14px; }
        .book-item .price { color: #667eea; font-weight: 600; font-size: 13px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .form-input:focus { outline: none; border-color: #667eea; }
        
        .btn-save {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .btn-cancel {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #888;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
        }
        .btn-cancel:hover { color: #333; }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <h1>✏️ Edit Request</h1>
        <p class="subtitle"><?php echo htmlspecialchars($request['full_name']); ?></p>
    </div>
    
    <div class="card">
        <form method="POST">
            <div class="section-title">Select Books</div>
            <div class="books-list">
                <?php while($book = $all_books->fetch_assoc()): ?>
                    <label class="book-item">
                        <input type="checkbox" name="books[]" value="<?php echo $book['book_id']; ?>" 
                            <?php echo in_array($book['book_id'], $current_books) ? 'checked' : ''; ?>>
                        <span class="title"><?php echo htmlspecialchars($book['book_title']); ?></span>
                        <span class="price">GH₵ <?php echo number_format($book['price'], 2); ?></span>
                    </label>
                <?php endwhile; ?>
            </div>

            <div class="form-group">
                <label>Amount Paid (GH₵)</label>
                <input type="number" step="0.01" name="amount_paid" class="form-input" value="<?php echo $request['amount_paid']; ?>">
            </div>

            <button type="submit" class="btn-save">Update Request</button>
            <a href="view_request.php" class="btn-cancel">Cancel</a>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>