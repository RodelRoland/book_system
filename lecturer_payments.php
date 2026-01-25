<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';

// Handle new payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $book_id = intval($_POST['book_id']);
    $copies_paid = intval($_POST['copies_paid']);
    $amount_paid = floatval($_POST['amount_paid']);
    $payment_date = $conn->real_escape_string($_POST['payment_date']);
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    
    if ($book_id > 0 && $copies_paid > 0 && $amount_paid > 0) {
        $stmt = $conn->prepare("INSERT INTO lecturer_payments (book_id, copies_paid, amount_paid, payment_date, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iidss", $book_id, $copies_paid, $amount_paid, $payment_date, $notes);
        
        if ($stmt->execute()) {
            $success_msg = "Payment recorded successfully!";
        } else {
            $error_msg = "Error recording payment: " . $conn->error;
        }
    } else {
        $error_msg = "Please fill in all required fields.";
    }
}

// Fetch all books with sales statistics
$books_sql = "
    SELECT 
        b.book_id,
        b.book_title,
        b.price,
        COUNT(DISTINCT CASE WHEN ri.is_collected = 1 THEN ri.item_id END) as sold_copies,
        COUNT(DISTINCT CASE WHEN ri.is_collected = 1 AND r.payment_status = 'paid' THEN ri.item_id END) as sold_paid_copies,
        COALESCE(SUM(CASE WHEN ri.is_collected = 1 AND r.payment_status = 'paid' THEN b.price ELSE 0 END), 0) as sold_paid_amount,
        COALESCE(lp.total_paid_copies, 0) as lecturer_paid_copies,
        COALESCE(lp.total_paid_amount, 0) as lecturer_paid_amount
    FROM books b
    LEFT JOIN request_items ri ON b.book_id = ri.book_id
    LEFT JOIN requests r ON ri.request_id = r.request_id
    LEFT JOIN (
        SELECT book_id, SUM(copies_paid) as total_paid_copies, SUM(amount_paid) as total_paid_amount
        FROM lecturer_payments
        GROUP BY book_id
    ) lp ON b.book_id = lp.book_id
    GROUP BY b.book_id
    ORDER BY b.book_title ASC
";
$books_result = $conn->query($books_sql);

// Fetch recent lecturer payments
$payments_sql = "
    SELECT lp.*, b.book_title 
    FROM lecturer_payments lp
    JOIN books b ON lp.book_id = b.book_id
    ORDER BY lp.payment_date DESC, lp.created_at DESC
    LIMIT 20
";
$payments_result = $conn->query($payments_sql);

// Fetch books for dropdown
$dropdown_books = $conn->query("SELECT book_id, book_title FROM books ORDER BY book_title ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Payments</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 1200px; margin: 0 auto; }
        
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        @media (max-width: 700px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px; height: 100%;
        }
        .stat-card.green::before { background: #28a745; }
        .stat-card.blue::before { background: #17a2b8; }
        .stat-card.red::before { background: #dc3545; }
        .stat-card .label { font-size: 12px; text-transform: uppercase; color: #888; font-weight: 600; margin-bottom: 8px; }
        .stat-card .value { font-size: 26px; font-weight: 700; }
        .stat-card.green .value { color: #28a745; }
        .stat-card.blue .value { color: #17a2b8; }
        .stat-card.red .value { color: #dc3545; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .card h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; color: #555; margin-bottom: 8px; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group textarea { resize: vertical; min-height: 70px; }
        
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
        
        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            font-weight: 700;
            border-bottom: 2px solid #e9ecef;
        }
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        tr:hover { background: #fafbfc; }
        
        .stat-positive { color: #28a745; font-weight: 700; }
        .stat-danger { color: #dc3545; font-weight: 700; }
        
        .progress-bar { height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        
        .empty-state { text-align: center; padding: 30px; color: #888; }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <div>
            <h1>💰 Lecturer Payments</h1>
            <p class="subtitle">Track payments made to lecturers for each book</p>
        </div>
        <a href="admin.php" class="back-btn">← Back to Dashboard</a>
    </div>
    
    <?php
    // Calculate totals
    $total_collected = 0;
    $total_paid_to_lecturers = 0;
    $total_copies_sold = 0;
    
    if ($books_result && $books_result->num_rows > 0) {
        $books_result->data_seek(0);
        while ($row = $books_result->fetch_assoc()) {
            $total_collected += $row['sold_paid_amount'];
            $total_paid_to_lecturers += $row['lecturer_paid_amount'];
            $total_copies_sold += $row['sold_copies'];
        }
        $books_result->data_seek(0);
    }
    $outstanding = $total_collected - $total_paid_to_lecturers;
    ?>
    
    <div class="stats-grid">
        <div class="stat-card green">
            <div class="label">Total Collected</div>
            <div class="value">GH₵ <?php echo number_format($total_collected, 2); ?></div>
        </div>
        <div class="stat-card blue">
            <div class="label">Paid to Lecturers</div>
            <div class="value">GH₵ <?php echo number_format($total_paid_to_lecturers, 2); ?></div>
        </div>
        <div class="stat-card red">
            <div class="label">Outstanding</div>
            <div class="value">GH₵ <?php echo number_format($outstanding, 2); ?></div>
        </div>
    </div>
    
    <div class="grid-2">
        <!-- Record Payment Form -->
        <div class="card">
            <h3>💰 Record Payment to Lecturer</h3>
            
            <?php if ($success_msg): ?>
                <div class="alert alert-success"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-error"><?php echo $error_msg; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Select Book *</label>
                    <select name="book_id" required>
                        <option value="">-- Choose a book --</option>
                        <?php while ($book = $dropdown_books->fetch_assoc()): ?>
                            <option value="<?php echo $book['book_id']; ?>">
                                <?php echo htmlspecialchars($book['book_title']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Number of Copies Paid For *</label>
                    <input type="number" name="copies_paid" min="1" required placeholder="e.g. 15">
                </div>
                
                <div class="form-group">
                    <label>Amount Paid (GH₵) *</label>
                    <input type="number" step="0.01" name="amount_paid" min="0.01" required placeholder="e.g. 150.00">
                </div>
                
                <div class="form-group">
                    <label>Payment Date *</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" placeholder="e.g. Paid via MoMo to Dr. Mensah"></textarea>
                </div>
                
                <button type="submit" name="record_payment" class="btn btn-primary">Record Payment</button>
            </form>
        </div>
        
        <!-- Recent Payments -->
        <div class="card">
            <h3>📋 Recent Payments</h3>
            <?php if ($payments_result && $payments_result->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Book</th>
                            <th>Copies</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($payment = $payments_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($payment['book_title']); ?></td>
                                <td><?php echo $payment['copies_paid']; ?></td>
                                <td>GH₵ <?php echo number_format($payment['amount_paid'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #666; text-align: center;">No payments recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Book Sales Summary -->
    <div class="card">
        <h3>📊 Book Sales & Payment Summary</h3>
        <table>
            <thead>
                <tr>
                    <th>Book Title</th>
                    <th>Price</th>
                    <th>Copies Sold (Collected)</th>
                    <th>Copies Sold & Paid</th>
                    <th>Total Collected</th>
                    <th>Copies Paid to Lecturer</th>
                    <th>Amount Paid to Lecturer</th>
                    <th>Outstanding</th>
                    <th>Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($books_result && $books_result->num_rows > 0): ?>
                    <?php $books_result->data_seek(0); ?>
                    <?php while ($book = $books_result->fetch_assoc()): ?>
                        <?php
                        $book_outstanding = $book['sold_paid_amount'] - $book['lecturer_paid_amount'];
                        $progress = $book['sold_paid_amount'] > 0 
                            ? min(100, ($book['lecturer_paid_amount'] / $book['sold_paid_amount']) * 100) 
                            : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($book['book_title']); ?></strong></td>
                            <td>GH₵ <?php echo number_format($book['price'], 2); ?></td>
                            <td><?php echo $book['sold_copies']; ?></td>
                            <td><?php echo $book['sold_paid_copies']; ?></td>
                            <td class="stat-positive">GH₵ <?php echo number_format($book['sold_paid_amount'], 2); ?></td>
                            <td><?php echo $book['lecturer_paid_copies']; ?></td>
                            <td style="color: #007bff;">GH₵ <?php echo number_format($book['lecturer_paid_amount'], 2); ?></td>
                            <td class="<?php echo $book_outstanding > 0 ? 'stat-danger' : 'stat-positive'; ?>">
                                GH₵ <?php echo number_format($book_outstanding, 2); ?>
                            </td>
                            <td style="min-width: 100px;">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                                </div>
                                <small><?php echo number_format($progress, 0); ?>% paid</small>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align: center;">No books found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
