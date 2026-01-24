<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }

$index = $conn->real_escape_string($_GET['index']);

// Query to get detailed history including individual book collection status
$sql = "SELECT r.created_at, r.total_amount, r.payment_status, 
               GROUP_CONCAT(CONCAT(b.book_title, ':', ri.is_collected) SEPARATOR '|') as books_data
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        JOIN request_items ri ON r.request_id = ri.request_id
        JOIN books b ON ri.book_id = b.book_id
        WHERE s.index_number = '$index'
        GROUP BY r.request_id
        ORDER BY r.created_at DESC";

$result = $conn->query($sql);
$grand_total = 0;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Detailed History</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; padding: 30px; }
        .history-card { max-width: 900px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        
        /* Book Tag Styling */
        .book-pill { 
            display: inline-block; 
            padding: 2px 10px; 
            margin: 2px; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: 500;
        }
        .collected { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .pending { background: #fff5f5; color: #c62828; border: 1px solid #ffcdd2; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 15px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #6f42c1; color: white; }
        .total-row { background-color: #f8f9fa; font-weight: bold; font-size: 1.1em; }
    </style>
</head>
<body>
    <div class="history-card">
        <a href="view_request.php" style="text-decoration:none; color:#6f42c1; font-weight:bold;">← Back to All Requests</a>
        <h2 style="margin-top:15px; color: #2c3e50;">Full History for Index: <?php echo htmlspecialchars($index); ?></h2>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Books & Collection Status</th>
                    <th>Amount</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <?php $grand_total += $row['total_amount']; ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                        <td>
                            <?php 
                            $books = explode('|', $row['books_data']);
                            foreach ($books as $book) {
                                list($title, $is_collected) = explode(':', $book);
                                $class = ($is_collected == 1) ? 'collected' : 'pending';
                                $icon = ($is_collected == 1) ? '✓' : '⌛';
                                echo "<span class='book-pill $class'>$icon " . htmlspecialchars($title) . "</span> ";
                            }
                            ?>
                        </td>
                        <td>GH₵ <?php echo number_format($row['total_amount'], 2); ?></td>
                        <td>
                            <strong style="color: <?php echo ($row['payment_status'] == 'paid') ? '#28a745' : '#dc3545'; ?>">
                                <?php echo strtoupper($row['payment_status']); ?>
                            </strong>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    
                    <tr class="total-row">
                        <td colspan="2" style="text-align: right;">Cumulative Total:</td>
                        <td colspan="2" style="color: #6f42c1;">GH₵ <?php echo number_format($grand_total, 2); ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center;">No history found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>