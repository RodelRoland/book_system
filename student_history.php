<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$index = $conn->real_escape_string($_GET['index']);

// Updated SQL to include amount_paid for balance calculation
$sql = "SELECT r.created_at, r.total_amount, r.amount_paid, r.payment_status, 
               GROUP_CONCAT(CONCAT(b.book_title, ':', ri.is_collected) SEPARATOR '|') as books_data
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        JOIN request_items ri ON r.request_id = ri.request_id
        JOIN books b ON ri.book_id = b.book_id
        WHERE s.index_number = '$index' AND r.semester_id = $semester_id
        GROUP BY r.request_id
        ORDER BY r.created_at DESC";

$result = $conn->query($sql);
$grand_total_cost = 0;
$grand_total_paid = 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student History</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 1100px; margin: 0 auto; }
        
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
        .page-header h1 { font-size: 22px; font-weight: 600; }
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
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th {
            background: #f8f9fa;
            padding: 14px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            font-weight: 700;
            border-bottom: 2px solid #e9ecef;
        }
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            font-size: 14px;
        }
        tr:hover { background: #fafbfc; }
        
        .book-pill {
            display: inline-block;
            padding: 4px 10px;
            margin: 2px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }
        .collected { background: #d4edda; color: #155724; }
        .pending { background: #fff3cd; color: #856404; }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-paid { background: #d4edda; color: #155724; }
        .status-unpaid { background: #f8d7da; color: #721c24; }
        
        .credit-text { color: #28a745; font-weight: 700; }
        
        .total-row {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .total-row td { font-weight: 700; border: none; }
        .total-row .credit-text { color: #90EE90; }
        
        .empty-state { text-align: center; padding: 50px; color: #888; }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>📜 Student History</h1>
            <p class="subtitle">Index: <?php echo htmlspecialchars($index); ?></p>
        </div>
        <a href="view_request.php" class="back-btn">← Back to Requests</a>
    </div>
    
    <div class="card">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Books & Collection Status</th>
                        <th>Cost</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <?php 
                            $grand_total_cost += $row['total_amount']; 
                            $grand_total_paid += $row['amount_paid'];
                            $balance = $row['amount_paid'] - $row['total_amount'];
                            $display_balance = ($balance > 0) ? $balance : 0;
                        ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <?php 
                                $books = explode('|', $row['books_data']);
                                foreach ($books as $book) {
                                    list($title, $is_collected) = explode(':', $book);
                                    $class = ($is_collected == 1) ? 'collected' : 'pending';
                                    $icon = ($is_collected == 1) ? '✓' : '○';
                                    echo "<span class='book-pill $class'>$icon " . htmlspecialchars($title) . "</span> ";
                                }
                                ?>
                            </td>
                            <td>GH₵ <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>GH₵ <?php echo number_format($row['amount_paid'], 2); ?></td>
                            <td>
                                <span class="status-badge <?php echo ($row['payment_status'] == 'paid') ? 'status-paid' : 'status-unpaid'; ?>">
                                    <?php echo strtoupper($row['payment_status']); ?>
                                </span>
                            </td>
                            <td class="credit-text"><?php echo number_format($display_balance, 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                        
                        <tr class="total-row">
                            <td colspan="2" style="text-align: right;">Cumulative Totals:</td>
                            <td>GH₵ <?php echo number_format($grand_total_cost, 2); ?></td>
                            <td>GH₵ <?php echo number_format($grand_total_paid, 2); ?></td>
                            <td></td>
                            <td class="credit-text">
                                GH₵ <?php 
                                    $total_credit = $grand_total_paid - $grand_total_cost;
                                    echo number_format(($total_credit > 0 ? $total_credit : 0), 2); 
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">No history found for this student.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>