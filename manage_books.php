<?php
session_start();
require_once 'db.php';

/* Protect admin page */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header("Location: admin.php");
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$auto_assigned_count = 0;
$auto_assigned_total = 0;

$csrf_token = csrf_get_token();

/**
 * Auto-assign a book to students who have sufficient credit balance.
 * Creates a paid request and deducts from their balance.
 */
function auto_assign_book_to_students_with_balance($conn, $book_id, $book_price, $semester_id) {
    $assigned_count = 0;
    $total_deducted = 0;
    
    if ($book_price <= 0) {
        return ['count' => 0, 'total' => 0];
    }
    
    // Find students with credit_balance >= book_price
    $stmt = $conn->prepare("SELECT student_id, full_name, credit_balance, admin_id FROM students WHERE credit_balance >= ?");
    $stmt->bind_param("d", $book_price);
    $stmt->execute();
    $students = $stmt->get_result();
    
    while ($student = $students->fetch_assoc()) {
        $student_id = intval($student['student_id']);
        $current_balance = floatval($student['credit_balance']);
        $admin_id = intval($student['admin_id']);
        
        // Check if student already has this book in current semester
        $check_stmt = $conn->prepare("
            SELECT ri.item_id FROM request_items ri 
            JOIN requests r ON ri.request_id = r.request_id 
            WHERE r.student_id = ? AND r.semester_id = ? AND ri.book_id = ? 
            LIMIT 1
        ");
        $check_stmt->bind_param("iii", $student_id, $semester_id, $book_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result();
        
        if ($existing->num_rows > 0) {
            // Student already has this book, skip
            continue;
        }
        
        // Create auto-assigned request (marked as paid)
        $conn->begin_transaction();
        try {
            // Insert request
            $amount_paid = 0.00;
            $credit_used = $book_price;
            $req_stmt = $conn->prepare("
                INSERT INTO requests (student_id, total_amount, amount_paid, credit_used, payment_status, semester_id, admin_id, created_at) 
                VALUES (?, ?, ?, ?, 'paid', ?, ?, NOW())
            ");
            $req_stmt->bind_param("idddii", $student_id, $book_price, $amount_paid, $credit_used, $semester_id, $admin_id);
            $req_stmt->execute();
            $request_id = $conn->insert_id;
            
            // Insert request item
            $item_stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id, unit_price, is_collected) VALUES (?, ?, ?, 0)");
            $item_stmt->bind_param("iid", $request_id, $book_id, $book_price);
            $item_stmt->execute();
            
            // Deduct from student's balance
            $new_balance = $current_balance - $book_price;
            $bal_stmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ?");
            $bal_stmt->bind_param("di", $new_balance, $student_id);
            $bal_stmt->execute();
            
            $conn->commit();
            $assigned_count++;
            $total_deducted += $book_price;
            
        } catch (Exception $e) {
            $conn->rollback();
        }
    }
    
    return ['count' => $assigned_count, 'total' => $total_deducted];
}

/* Add new book */
if (isset($_POST['add_book'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: manage_books.php?msg=csrf_invalid');
        exit;
    }
    $title = trim($_POST['book_title']);
    $price = floatval($_POST['price']);
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    // Default to available - quantity is optional/for tracking only
    $availability = 'available';

    $stmt = $conn->prepare("INSERT INTO books (book_title, price, stock_quantity, availability) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sdis", $title, $price, $stock_quantity, $availability);
    $stmt->execute();
    $new_book_id = $conn->insert_id;
    
    if (function_exists('clear_books_cache')) clear_books_cache();
    
    // Auto-assign to students with sufficient balance if book is available
    if ($availability === 'available' && $price > 0) {
        $result = auto_assign_book_to_students_with_balance($conn, $new_book_id, $price, $semester_id);
        $auto_assigned_count = $result['count'];
        $auto_assigned_total = $result['total'];
    }
}

/* Update book (price or availability) */
if (isset($_POST['update_book'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: manage_books.php?msg=csrf_invalid');
        exit;
    }
    $book_id = intval($_POST['book_id']);
    $price = floatval($_POST['price']);
    $effective_date = trim(strval($_POST['effective_date'] ?? ''));
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    $availability = $_POST['availability'] === 'out_of_stock'
        ? 'out_of_stock'
        : 'available';

    $today = date('Y-m-d');
    if ($effective_date === '') {
        $effective_date = $today;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $effective_date);
    if (!$dt || $dt->format('Y-m-d') !== $effective_date) {
        $effective_date = $today;
    }

    // Stock quantity is optional - don't force out_of_stock based on quantity
    // Only use the availability dropdown selection

    // Check if book was previously unavailable and is now being made available
    $prev_stmt = $conn->prepare("SELECT availability, price FROM books WHERE book_id = ?");
    $prev_stmt->bind_param("i", $book_id);
    $prev_stmt->execute();
    $prev_result = $prev_stmt->get_result();
    $prev_book = $prev_result->fetch_assoc();
    $was_unavailable = ($prev_book && $prev_book['availability'] === 'out_of_stock');
    $price_changed = ($prev_book && floatval($prev_book['price']) != $price);
    $old_price = $prev_book ? floatval($prev_book['price']) : null;

    $schedule_price_change = ($price_changed && $effective_date > $today);

    if ($price_changed && $old_price !== null) {
        $sup = $conn->prepare("UPDATE book_price_history SET applied_at = NOW(), notes = CONCAT(IFNULL(notes, ''), IF(IFNULL(notes,'')='', '', ' | '), 'superseded') WHERE book_id = ? AND applied_at IS NULL AND effective_date IS NOT NULL AND effective_date > ?");
        if ($sup) {
            $sup->bind_param('is', $book_id, $today);
            $sup->execute();
        }
    }
    
    if ($schedule_price_change) {
        $upd_stmt = $conn->prepare("UPDATE books SET stock_quantity = ?, availability = ? WHERE book_id = ?");
        $upd_stmt->bind_param("isi", $stock_quantity, $availability, $book_id);
        $upd_stmt->execute();
    } else {
        $upd_stmt = $conn->prepare("UPDATE books SET price = ?, stock_quantity = ?, availability = ? WHERE book_id = ?");
        $upd_stmt->bind_param("disi", $price, $stock_quantity, $availability, $book_id);
        $upd_stmt->execute();
    }

    if ($price_changed && $old_price !== null) {
        $hstmt = $conn->prepare("INSERT INTO book_price_history (book_id, old_price, new_price, changed_by_admin_id, effective_date, applied_at) VALUES (?, ?, ?, ?, ?, ?)");
        if ($hstmt) {
            $applied_at = $schedule_price_change ? null : date('Y-m-d H:i:s');
            $hstmt->bind_param('iddiss', $book_id, $old_price, $price, $current_admin_id, $effective_date, $applied_at);
            $hstmt->execute();
        }
    }

    if ($price_changed && $old_price !== null && !$schedule_price_change && $semester_id > 0) {
        $upd_items = $conn->prepare("UPDATE request_items ri\n            JOIN requests r ON r.request_id = ri.request_id\n            SET ri.unit_price = ?\n            WHERE ri.book_id = ?\n              AND r.payment_status = 'unpaid'\n              AND r.semester_id = ?\n              AND DATE(r.created_at) >= ?");
        if ($upd_items) {
            $upd_items->bind_param('diis', $price, $book_id, $semester_id, $effective_date);
            $upd_items->execute();

            $conn->query("UPDATE requests r\n                JOIN (SELECT request_id, SUM(COALESCE(unit_price, 0)) AS total_amount_calc FROM request_items GROUP BY request_id) x\n                    ON x.request_id = r.request_id\n                SET r.total_amount = x.total_amount_calc,\n                    r.payment_status = CASE WHEN (COALESCE(r.amount_paid,0) + COALESCE(r.credit_used,0)) >= COALESCE(x.total_amount_calc,0) THEN 'paid' ELSE 'unpaid' END\n                WHERE r.semester_id = " . intval($semester_id) . " AND r.payment_status = 'unpaid'");
        }
    }
    
    if (function_exists('clear_books_cache')) clear_books_cache();
    
    // Auto-assign if book is now available (was unavailable OR price changed)
    if ($availability === 'available' && $price > 0 && ($was_unavailable || ($price_changed && !$schedule_price_change))) {
        $result = auto_assign_book_to_students_with_balance($conn, $book_id, $price, $semester_id);
        $auto_assigned_count = $result['count'];
        $auto_assigned_total = $result['total'];
    }
}

/* Fetch all books */
$books = $conn->query("SELECT 
    b.*,
    (
        SELECT bph.new_price
        FROM book_price_history bph
        WHERE bph.book_id = b.book_id
          AND bph.applied_at IS NULL
          AND bph.effective_date IS NOT NULL
          AND bph.effective_date > CURDATE()
        ORDER BY bph.effective_date DESC, bph.history_id DESC
        LIMIT 1
    ) AS scheduled_new_price,
    (
        SELECT bph.effective_date
        FROM book_price_history bph
        WHERE bph.book_id = b.book_id
          AND bph.applied_at IS NULL
          AND bph.effective_date IS NOT NULL
          AND bph.effective_date > CURDATE()
        ORDER BY bph.effective_date DESC, bph.history_id DESC
        LIMIT 1
    ) AS scheduled_effective_date
    FROM books b
    ORDER BY b.book_title ASC");
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
            <?php if ($auto_assigned_count > 0): ?>
            <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <strong>✅ Auto-Assignment Complete!</strong><br>
                <span style="font-size: 14px;">Automatically assigned book to <strong><?php echo $auto_assigned_count; ?></strong> student(s) with existing balance.</span><br>
                <span style="font-size: 13px; opacity: 0.8;">Total deducted: GH₵ <?php echo number_format($auto_assigned_total, 2); ?></span>
            </div>
            <?php endif; ?>
            <h2><span class="icon">➕</span> Add New Book</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label>Book Title</label>
                    <input type="text" name="book_title" placeholder="Enter book title" required>
                </div>
                <div class="form-group">
                    <label>Price (GH₵)</label>
                    <input type="number" step="0.01" name="price" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Stock Quantity <span style="font-weight:normal;color:#888;">(optional)</span></label>
                    <input type="number" name="stock_quantity" min="0" placeholder="0">
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
                            <th>Effective Date</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $books->fetch_assoc()): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <td class="book-title"><?php echo htmlspecialchars($row['book_title']); ?></td>
                                <td>
                                    <input type="number" step="0.01" name="price" 
                                           class="price-input" value="<?php echo $row['price']; ?>">
                                    <?php if (!empty($row['scheduled_new_price']) && !empty($row['scheduled_effective_date'])): ?>
                                        <div style="margin-top:6px; font-size: 12px; color: #6c757d; font-weight: 600;">
                                            Scheduled: GH₵ <?php echo number_format(floatval($row['scheduled_new_price']), 2); ?> on <?php echo htmlspecialchars($row['scheduled_effective_date']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="date" name="effective_date" class="price-input" style="width: 155px; text-align: left;" value="<?php echo htmlspecialchars($row['scheduled_effective_date'] ?: date('Y-m-d')); ?>">
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

</div>

<?php include 'footer.php'; ?>

</body>
</html>
