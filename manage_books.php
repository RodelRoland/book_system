<?php
session_start();
require_once 'db.php';

/* Protect admin page */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

/* Add new book */
if (isset($_POST['add_book'])) {
    $title = $conn->real_escape_string($_POST['book_title']);
    $price = floatval($_POST['price']);
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    $availability = ($stock_quantity > 0) ? 'available' : 'out_of_stock';

    $conn->query("
        INSERT INTO books (book_title, price, stock_quantity, availability)
        VALUES ('$title', $price, $stock_quantity, '$availability')
    ");
}

/* Update book (price or availability) */
if (isset($_POST['update_book'])) {
    $book_id = intval($_POST['book_id']);
    $price = floatval($_POST['price']);
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    $availability = $_POST['availability'] === 'out_of_stock'
        ? 'out_of_stock'
        : 'available';

    if ($stock_quantity <= 0) {
        $availability = 'out_of_stock';
    }

    $conn->query("
        UPDATE books
        SET price = $price,
            stock_quantity = $stock_quantity,
            availability = '$availability'
        WHERE book_id = $book_id
    ");
}

/* Fetch all books */
$books = $conn->query("SELECT * FROM books ORDER BY book_title ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Books</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        /* Header */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .page-header .subtitle { opacity: 0.9; margin-top: 3px; font-size: 13px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        /* Grid Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 25px;
        }
        @media (max-width: 800px) { .content-grid { grid-template-columns: 1fr; } }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card h2 .icon {
            width: 36px;
            height: 36px;
            background: #e3f2fd;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        /* Form Styling */
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        .btn-primary:hover { opacity: 0.9; }
        
        /* Table Styling */
        .books-table {
            width: 100%;
            border-collapse: collapse;
        }
        .books-table th {
            background: #f8f9fa;
            padding: 14px 15px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
        }
        .books-table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .books-table tr:hover { background: #fafbfc; }
        
        .book-title {
            font-weight: 600;
            color: #333;
        }
        
        .price-input {
            width: 100px;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            text-align: right;
        }
        .price-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .status-select {
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        .status-select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-update {
            padding: 8px 16px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-update:hover { background: #218838; }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-available { background: #d4edda; color: #155724; }
        .status-out { background: #f8d7da; color: #721c24; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #888;
        }
        
        /* Success Message */
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>📚 Manage Books</h1>
            <p class="subtitle">Add new books and update prices</p>
        </div>
        <a href="admin.php" class="back-btn">← Back to Dashboard</a>
    </div>
    
    <div class="content-grid">
        <!-- Add New Book Form -->
        <div class="card">
            <h2><span class="icon">➕</span> Add New Book</h2>
            <form method="post">
                <div class="form-group">
                    <label>Book Title</label>
                    <input type="text" name="book_title" placeholder="Enter book title" required>
                </div>
                <div class="form-group">
                    <label>Price (GH₵)</label>
                    <input type="number" step="0.01" name="price" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Stock Quantity</label>
                    <input type="number" name="stock_quantity" min="0" placeholder="0" required>
                </div>
                <button type="submit" name="add_book" class="btn-primary">Add Book</button>
            </form>
        </div>
        
        <!-- Books List -->
        <div class="card">
            <h2><span class="icon">📖</span> All Books</h2>
            
            <?php if ($books && $books->num_rows > 0): ?>
                <table class="books-table">
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Price (GH₵)</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $books->fetch_assoc()): ?>
                        <tr>
                            <form method="post">
                                <td class="book-title"><?php echo htmlspecialchars($row['book_title']); ?></td>
                                <td>
                                    <input type="number" step="0.01" name="price" 
                                           class="price-input" value="<?php echo $row['price']; ?>">
                                </td>
                                <td>
                                    <input type="number" name="stock_quantity" min="0" 
                                           class="price-input" style="width: 90px;" value="<?php echo intval($row['stock_quantity'] ?? 0); ?>">
                                </td>
                                <td>
                                    <select name="availability" class="status-select">
                                        <option value="available" <?php if ($row['availability'] === 'available') echo 'selected'; ?>>
                                            Available
                                        </option>
                                        <option value="out_of_stock" <?php if ($row['availability'] === 'out_of_stock') echo 'selected'; ?>>
                                            Out of Stock
                                        </option>
                                    </select>
                                </td>
                                <td>
                                    <input type="hidden" name="book_id" value="<?php echo $row['book_id']; ?>">
                                    <button type="submit" name="update_book" class="btn-update">Update</button>
                                </td>
                            </form>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <p>No books added yet. Add your first book using the form.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
