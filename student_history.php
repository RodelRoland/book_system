<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

$semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;

$student_id = intval($_GET['student_id'] ?? 0);
$index = trim($_GET['index'] ?? '');

if ($student_id > 0 && $index === '') {
    $stmt = $conn->prepare("SELECT index_number FROM students WHERE student_id = ? LIMIT 1");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $index = $res->fetch_assoc()['index_number'] ?? '';
    }
}

if (!$is_super_admin && $index !== '') {
    $chk = $conn->prepare("SELECT 1 FROM requests r JOIN students s ON r.student_id = s.student_id WHERE s.index_number = ? AND r.admin_id = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param('si', $index, $current_admin_id);
        $chk->execute();
        $chk_res = $chk->get_result();
        if (!$chk_res || $chk_res->num_rows < 1) {
            $index = '';
            $student_id = 0;
        }
    }
}

$where = "WHERE 1=1";
$types = '';
$params = [];

if ($index !== '') {
    $where .= " AND s.index_number = ?";
    $types .= 's';
    $params[] = $index;
} elseif ($student_id > 0) {
    $where .= " AND r.student_id = ?";
    $types .= 'i';
    $params[] = $student_id;
} else {
    $where .= " AND 1=0";
}

if ($semester_id > 0) {
    $where .= " AND r.semester_id = ?";
    $types .= 'i';
    $params[] = $semester_id;
}

$sql = "SELECT r.request_id, r.created_at, r.total_amount, r.amount_paid, r.credit_used, r.payment_status,
               COALESCE(br.refunded_amount, 0) AS refunded_amount,
               GROUP_CONCAT(CONCAT(b.book_title, ':', COALESCE(ri.is_collected, 0)) SEPARATOR '|') as books_data
        FROM requests r
        LEFT JOIN students s ON r.student_id = s.student_id
        LEFT JOIN request_items ri ON r.request_id = ri.request_id
        LEFT JOIN books b ON ri.book_id = b.book_id
        LEFT JOIN (
            SELECT request_id, SUM(amount) AS refunded_amount
            FROM balance_returns
            GROUP BY request_id
        ) br ON br.request_id = r.request_id
        $where
        GROUP BY r.request_id
        ORDER BY r.created_at DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$grand_total_cost = 0;
$grand_total_paid = 0;
$grand_total_outstanding = 0;
$grand_total_returned = 0;
$grand_total_available_credit = 0;
$history_rows = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $total_amount = floatval($row['total_amount']);
        $amount_paid = floatval($row['amount_paid']);
        $credit_used = floatval($row['credit_used'] ?? 0);
        $refunded_amount = floatval($row['refunded_amount'] ?? 0);
        $due_after_credit = max(0, $total_amount - $credit_used);
        $cash_overpaid = max(0, $amount_paid - $due_after_credit);
        $outstanding = max(0, $due_after_credit - $amount_paid);
        $available_credit = max(0, $cash_overpaid - $refunded_amount);

        $row['calc_due_after_credit'] = $due_after_credit;
        $row['calc_outstanding'] = $outstanding;
        $row['calc_available_credit'] = $available_credit;
        $history_rows[] = $row;

        $grand_total_cost += $total_amount;
        $grand_total_paid += ($amount_paid + $credit_used);
        $grand_total_outstanding += $outstanding;
        $grand_total_returned += $refunded_amount;
        $grand_total_available_credit += $available_credit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student History</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">
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
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .summary-card .label {
            font-size: 12px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 8px;
            font-weight: 700;
        }
        .summary-card .value {
            font-size: 22px;
            font-weight: 800;
            color: #1f2937;
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
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .print-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 540px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
        @media print {
            body { background: white; padding: 0; }
            .page-header, .summary-card, .card { box-shadow: none; }
            .back-btn, .print-btn { display: none; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-clock-history"></i> Student History</h1>
            <p class="subtitle">Index: <?php echo htmlspecialchars($index); ?></p>
        </div>
        <div class="header-actions">
            <button type="button" class="print-btn" onclick="window.print()">Print Summary</button>
            <a href="view_request.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back to Requests</a>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="label">Requests</div>
            <div class="value"><?php echo count($history_rows); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Cost</div>
            <div class="value">GH&#8373; <?php echo number_format($grand_total_cost, 2); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Outstanding</div>
            <div class="value">GH&#8373; <?php echo number_format($grand_total_outstanding, 2); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Returned / Credit Left</div>
            <div class="value">GH&#8373; <?php echo number_format($grand_total_returned + $grand_total_available_credit, 2); ?></div>
        </div>
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
                    <?php if (!empty($history_rows)): ?>
                        <?php foreach ($history_rows as $row): ?>
                        <?php 
                            $total_amount = floatval($row['total_amount']);
                            $amount_paid = floatval($row['amount_paid']);
                            $credit_used = floatval($row['credit_used'] ?? 0);
                            $refunded_amount = floatval($row['refunded_amount'] ?? 0);
                            $outstanding = floatval($row['calc_outstanding'] ?? 0);
                            $available_credit = floatval($row['calc_available_credit'] ?? 0);
                        ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td>
                                <?php 
                                if (!empty($row['books_data'])) {
                                    $books = explode('|', $row['books_data']);
                                    foreach ($books as $book) {
                                        if ($book === '') continue;
                                        $parts = explode(':', $book);
                                        if (count($parts) < 2) continue;
                                        $title = $parts[0];
                                        $is_collected = $parts[1];
                                        $class = ($is_collected == 1) ? 'collected' : 'pending';
                                        $icon = ($is_collected == 1) ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle"></i>';
                                        echo "<span class='book-pill $class'>$icon " . htmlspecialchars($title) . "</span> ";
                                    }
                                } else {
                                    echo "<span style='color:#888;'>&mdash;</span>";
                                }
                                ?>
                            </td>
                            <td>GH&#8373; <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>GH&#8373; <?php echo number_format($amount_paid + $credit_used, 2); ?></td>

                            <td>
                                <span class="status-badge <?php echo ($row['payment_status'] == 'paid') ? 'status-paid' : 'status-unpaid'; ?>">
                                    <?php echo strtoupper($row['payment_status']); ?>
                                </span>
                            </td>
                            <td class="credit-text">
                                <?php if ($outstanding > 0): ?>
                                    Owes GH&#8373; <?php echo number_format($outstanding, 2); ?>
                                <?php elseif ($refunded_amount > 0): ?>
                                    Returned GH&#8373; <?php echo number_format($refunded_amount, 2); ?>
                                <?php elseif ($available_credit > 0): ?>
                                    Credit GH&#8373; <?php echo number_format($available_credit, 2); ?>
                                <?php else: ?>
                                    0.00
                                <?php endif; ?>
                            </td>

                        </tr>
                        <?php endforeach; ?>
                        
                        <tr class="total-row">
                            <td colspan="2" style="text-align: right;">Cumulative Totals:</td>
                            <td>GH&#8373; <?php echo number_format($grand_total_cost, 2); ?></td>
                            <td>GH&#8373; <?php echo number_format($grand_total_paid, 2); ?></td>

                            <td></td>
                            <td class="credit-text">
                                GH&#8373; <?php 
                                    $total_credit = $grand_total_returned + $grand_total_available_credit;
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


