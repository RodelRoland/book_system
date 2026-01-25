<?php
include 'db.php'; 

// 1. Capture search input
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// 2. SQL Query
$sql = "SELECT 
            r.request_id, s.full_name, s.index_number, s.phone, 
            r.total_amount, r.amount_paid, r.payment_status, r.created_at,
            GROUP_CONCAT(CONCAT(ri.item_id, ':', b.book_title, ':', ri.is_collected) SEPARATOR '|') AS books_data
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        LEFT JOIN request_items ri ON r.request_id = ri.request_id
        LEFT JOIN books b ON ri.book_id = b.book_id
        WHERE s.full_name LIKE '%$search%' 
           OR s.index_number LIKE '%$search%' 
           OR s.phone LIKE '%$search%'
           OR b.book_title LIKE '%$search%'
        GROUP BY r.request_id
        ORDER BY r.created_at DESC";

$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - View Requests</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f0f2f5; padding: 40px; }
        .container { max-width: 1250px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h2 { color: #333; margin-bottom: 20px; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        
        .search-section { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
        .search-input { flex-grow: 1; height: 45px; padding: 0 15px; border: 2px solid #ddd; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
        
        .btn-base { height: 45px; display: inline-flex; align-items: center; justify-content: center; padding: 0 25px; border-radius: 8px; font-weight: bold; font-size: 14px; text-decoration: none; border: none; cursor: pointer; white-space: nowrap; box-sizing: border-box; transition: 0.2s; }
        .btn-base:hover { opacity: 0.85; }
        
        .search-btn { background: #007bff; color: white; }
        .clear-btn { background: #6c757d; color: white; }
        .export-btn { background: #28a745; color: white; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: left; }
        th { background-color: #007bff; color: white; text-transform: uppercase; font-size: 12px; }
        
        .book-tag { display: inline-block; padding: 4px 10px; margin: 2px; border-radius: 20px; text-decoration: none; font-size: 11px; font-weight: 600; }
        .tag-pending { background: #fff; color: #d9534f; border: 1px solid #d9534f; }
        .tag-collected { background: #28a745; color: #fff; border: 1px solid #28a745; }
        
        .status-toggle { text-decoration: none; padding: 5px 10px; border-radius: 5px; font-size: 11px; font-weight: bold; }
        .status-paid { background: #e8f5e9; color: #2e7d32; border: 1px solid #2e7d32; }
        .status-unpaid { background: #ffebee; color: #c62828; border: 1px solid #c62828; }
        
        /* Fixed History Link Styling */
        .history-link { 
            font-size: 11px; 
            color: #6f42c1; 
            text-decoration: none; 
            font-weight: bold; 
            border: 1px solid #6f42c1; 
            padding: 2px 8px; 
            border-radius: 4px; 
            display: inline-block; 
            margin-top: 8px;
            background: #f9f6ff;
        }
        .history-link:hover { background: #6f42c1; color: white; }

        .credit-amt { color: #28a745; font-weight: bold; }
        .zero-amt { color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin.php" style="text-decoration:none; color:#007bff; font-weight:bold;">← Back to Dashboard</a>
        <h2>Course Material Requests</h2>

        <form method="GET" class="search-section">
            <input type="text" name="search" class="search-input" 
                   placeholder="Search Student..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-base search-btn">Search</button>
            
            <?php if (!empty($search)): ?>
                <a href="view_request.php" class="btn-base clear-btn">Clear</a>
                <a href="export_excel.php?search=<?php echo urlencode($search); ?>" class="btn-base export-btn">📥 Export CSV</a>
            <?php endif; ?>
        </form>

       <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student Info</th>
                    <th>Books (Issue)</th>
                    <th>Cost (GH₵)</th>
                    <th>Paid (GH₵)</th>
                    <th>Payment Status</th>
                    <th>Credit Balance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): ?>
                <?php 
                    $credit_balance = $row['amount_paid'] - $row['total_amount']; 
                ?>
                <tr>
                    <td><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                    <td>
                        <div style="line-height: 1.6;">
                            <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                            <small><?php echo htmlspecialchars($row['index_number']); ?></small><br>
                            <a href="student_history.php?index=<?php echo urlencode($row['index_number']); ?>" class="history-link">📜 History</a>
                        </div>
                    </td>
                    <td>
                        <?php 
                        if ($row['books_data']) {
                            $books = explode('|', $row['books_data']);
                            foreach ($books as $book) {
                                $parts = explode(':', $book);
                                if(count($parts) == 3) {
                                    list($item_id, $title, $is_collected) = $parts;
                                    $tagClass = ($is_collected == 1) ? 'tag-collected' : 'tag-pending';
                                    echo "<a href='toggle_book_collection.php?item_id=$item_id' class='book-tag $tagClass'>".($is_collected == 1 ? '✓ ' : '+ ')."$title</a>";
                                }
                            }
                        }
                        ?>
                    </td>
                    <td><?php echo number_format($row['total_amount'], 2); ?></td>
                    <td><?php echo number_format($row['amount_paid'], 2); ?></td>
                    <td>
                        <a href="toggle_payment.php?request_id=<?php echo $row['request_id']; ?>" 
                           class="status-toggle <?php echo ($row['payment_status'] == 'paid') ? 'status-paid' : 'status-unpaid'; ?>">
                            <?php echo strtoupper($row['payment_status']); ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($credit_balance > 0): ?>
                            <span class="credit-amt">GH₵ <?php echo number_format($credit_balance, 2); ?></span>
                        <?php else: ?>
                            <span class="zero-amt">0.00</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <a href="edit_request.php?id=<?php echo $row['request_id']; ?>" style="color: #007bff; text-decoration: none; font-size: 18px;" title="Edit Amount">✏️</a>
                        <a href="delete_request.php?id=<?php echo $row['request_id']; ?>" 
                           style="color: #d63031; text-decoration: none; font-size: 18px; margin-left: 10px;" 
                           onclick="return confirm('Are you sure you want to delete this entire request?');" title="Delete Entry">🗑️</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8" style="text-align:center;">No requests found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>