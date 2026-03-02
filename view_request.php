<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php'; 

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

// Get current admin info for filtering
$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 100;
$offset = ($page - 1) * $per_page;

// Handle marking a balance as returned
if (isset($_GET['return_balance'], $_GET['request_id'], $_GET['student_id'])) {
    if (!csrf_validate($_GET['csrf_token'] ?? null)) {
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
    $request_id = intval($_GET['request_id']);
    $student_id = intval($_GET['student_id']);

    if ($request_id <= 0 || $student_id <= 0) {
        header("Location: view_request.php?msg=return_failed");
        exit;
    }

    $req = null;
    $req_stmt = $conn->prepare("SELECT total_amount, amount_paid, credit_used, admin_id FROM requests WHERE request_id = ? AND student_id = ? LIMIT 1");
    if ($req_stmt) {
        $req_stmt->bind_param('ii', $request_id, $student_id);
        $req_stmt->execute();
        $req = $req_stmt->get_result();
    }

    if ($req && $req->num_rows === 1) {
        $req_row = $req->fetch_assoc();

        $request_admin_id = intval($req_row['admin_id'] ?? 0);
        if (!$is_super_admin && $request_admin_id !== $current_admin_id) {
            header("Location: view_request.php?msg=unauthorized");
            exit;
        }

        $total_amount = floatval($req_row['total_amount']);
        $amount_paid = floatval($req_row['amount_paid']);
        $credit_used = floatval($req_row['credit_used'] ?? 0);
        $due_after_credit = max(0, $total_amount - $credit_used);

        $overpaid = max(0, $amount_paid - $due_after_credit);

        $total_return = $overpaid;

        if ($total_return > 0) {
            $conn->begin_transaction();
            try {
                if ($overpaid > 0) {
                    $upd_stmt = $conn->prepare("UPDATE requests SET amount_paid = ? WHERE request_id = ?" . ($is_super_admin ? "" : " AND admin_id = ?"));
                    if (!$upd_stmt) {
                        throw new RuntimeException('Failed to prepare update.');
                    }
                    $new_amount_paid = (float) number_format($due_after_credit, 2, '.', '');
                    if ($is_super_admin) {
                        $upd_stmt->bind_param('di', $new_amount_paid, $request_id);
                    } else {
                        $upd_stmt->bind_param('dii', $new_amount_paid, $request_id, $current_admin_id);
                    }
                    if (!$upd_stmt->execute()) {
                        throw new RuntimeException('Failed to update request.');
                    }
                }
                $amount_sql = (float) number_format($total_return, 2, '.', '');
                $ins_stmt = $conn->prepare("INSERT INTO balance_returns (student_id, request_id, amount, notes) VALUES (?, ?, ?, 'Returned to student')");
                if (!$ins_stmt) {
                    throw new RuntimeException('Failed to prepare insert.');
                }
                $ins_stmt->bind_param('iid', $student_id, $request_id, $amount_sql);
                if (!$ins_stmt->execute()) {
                    throw new RuntimeException('Failed to insert balance return.');
                }
                $conn->commit();
                header("Location: view_request.php?msg=returned&amount=$amount_sql");
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                header("Location: view_request.php?msg=return_failed");
                exit;
            }
        }
    }

    header("Location: view_request.php?msg=return_failed");
    exit;
}


// 1. Capture search input
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$collection_filter = isset($_GET['collection_filter']) ? $_GET['collection_filter'] : 'all';
if (!in_array($collection_filter, ['all', 'not_taken'], true)) {
    $collection_filter = 'all';
}

// 2. Two-phase query for speed:
//    (A) get matching request_ids using requests+students + EXISTS
//    (B) fetch details only for those ids (build books_data/pending_items)
$search_pattern = '%' . $search . '%';

$ids_sql = "SELECT r.request_id
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        WHERE r.semester_id = ?
          " . ($is_super_admin ? '' : "AND r.admin_id = ?") . "";

if ($collection_filter === 'not_taken') {
    $ids_sql .= " AND EXISTS (
        SELECT 1 FROM request_items ri2
        WHERE ri2.request_id = r.request_id
          AND (ri2.is_collected = 0 OR ri2.is_collected IS NULL)
    )";
}

if ($search !== '') {
    $ids_sql .= " AND (
        s.full_name LIKE ?
        OR s.index_number LIKE ?
        OR s.phone LIKE ?
        OR EXISTS (
            SELECT 1
            FROM request_items ri3
            JOIN books b3 ON ri3.book_id = b3.book_id
            WHERE ri3.request_id = r.request_id
              AND b3.book_title LIKE ?
        )
    )";
}

$ids_sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($ids_sql);
$request_ids = [];
if ($is_super_admin) {
    if ($search !== '') {
        $stmt->bind_param("issssii", $semester_id, $search_pattern, $search_pattern, $search_pattern, $search_pattern, $per_page, $offset);
    } else {
        $stmt->bind_param("iii", $semester_id, $per_page, $offset);
    }
} else {
    if ($search !== '') {
        $stmt->bind_param("iissssii", $semester_id, $current_admin_id, $search_pattern, $search_pattern, $search_pattern, $search_pattern, $per_page, $offset);
    } else {
        $stmt->bind_param("iiii", $semester_id, $current_admin_id, $per_page, $offset);
    }
}
$stmt->execute();
$ids_res = $stmt->get_result();
if ($ids_res) {
    while ($r = $ids_res->fetch_assoc()) {
        $request_ids[] = intval($r['request_id']);
    }
}

if (count($request_ids) > 0) {
    $ids_in = implode(',', array_map('intval', $request_ids));

    $details_sql = "SELECT 
                r.request_id, r.student_id,
                s.full_name, s.index_number, s.phone,
                r.total_amount, r.amount_paid, r.credit_used, r.payment_status, r.created_at,
                COALESCE(br.refunded_amount, 0) AS refunded_amount,
                br.last_return_date,
                SUM(CASE WHEN ri.is_collected = 0 OR ri.is_collected IS NULL THEN 1 ELSE 0 END) AS pending_items,
                GROUP_CONCAT(CONCAT(ri.item_id, ':', b.book_title, ':', ri.is_collected) SEPARATOR '|' ) AS books_data
            FROM requests r
            JOIN students s ON r.student_id = s.student_id
            LEFT JOIN request_items ri ON r.request_id = ri.request_id
            LEFT JOIN books b ON ri.book_id = b.book_id

            LEFT JOIN (
                SELECT request_id, SUM(amount) AS refunded_amount, MAX(return_date) AS last_return_date
                FROM balance_returns
                WHERE request_id IS NOT NULL
                GROUP BY request_id
            ) br ON r.request_id = br.request_id
            WHERE r.request_id IN ($ids_in)
              AND r.semester_id = ?
              " . ($is_super_admin ? '' : "AND r.admin_id = ?") . "
            GROUP BY r.request_id
            ORDER BY FIELD(r.request_id, $ids_in)";

    $stmt = $conn->prepare($details_sql);
    if ($is_super_admin) {
        $stmt->bind_param("i", $semester_id);
    } else {
        $stmt->bind_param("ii", $semester_id, $current_admin_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = null;
}

$show_next = (count($request_ids) === $per_page);
$prev_page = max(1, $page - 1);
$next_page = $page + 1;

$csrf_token = csrf_get_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Requests</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 1300px; margin: 0 auto; }
        
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
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .search-section {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .search-input {
            flex: 1;
            min-width: 250px;
            padding: 12px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .search-input:focus { outline: none; border-color: #667eea; }
        
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
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
            padding: 16px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            font-size: 14px;
        }
        tr:hover { background: #fafbfc; }
        
        .student-info .name { font-weight: 600; color: #333; }
        .student-info .index { color: #888; font-size: 12px; margin-top: 2px; }
        
        .book-tag {
            display: inline-block;
            padding: 5px 12px;
            margin: 3px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .tag-pending { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .tag-pending:hover { background: #ffc107; color: #333; }
        .tag-collected { background: #d4edda; color: #155724; border: 1px solid #28a745; }
        .tag-collected:hover { background: #28a745; color: white; }
        
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            text-decoration: none;
        }
        .status-paid { background: #d4edda; color: #155724; }
        .status-unpaid { background: #f8d7da; color: #721c24; }
        
        .history-btn {
            font-size: 11px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            background: #f0f0ff;
            display: inline-block;
            margin-top: 6px;
            transition: all 0.2s;
        }
        .history-btn:hover { background: #667eea; color: white; }
        
        .credit-amt { color: #28a745; font-weight: 700; }
        .debit-amt { color: #dc3545; font-weight: 700; }
        .zero-amt { color: #ccc; }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.2s;
            margin-right: 5px;
        }
        .action-edit { background: #e3f2fd; }
        .action-edit:hover { background: #2196f3; }
        .action-delete { background: #ffebee; }
        .action-delete:hover { background: #f44336; }
        .action-return { background: #fff3cd; }
        .action-return:hover { background: #ffc107; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 15px; }
        
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 18px;
            gap: 12px;
        }
        .page-indicator { color: #666; font-weight: 600; font-size: 13px; }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-inbox"></i> Student Requests</h1>
            <p class="subtitle">Manage orders, payments & book collection</p>
        </div>
        <a href="admin.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
    </div>
    
    <div class="card">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'out_of_stock'): ?>
            <div class="alert alert-warning">This book is out of stock. Set the stock quantity in Manage Books before marking it as collected.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'toggle_failed'): ?>
            <div class="alert alert-error">Could not update collection status. Please try again. If it continues, check that the book has stock and exists in the database.</div>
        <?php endif; ?>
        <form method="GET" class="search-section">
            <input type="text" name="search" class="search-input" placeholder="Search by name, index, phone, or book" 
                   value="<?php echo htmlspecialchars($search); ?>">
            <select name="collection_filter" class="search-input" style="max-width: 200px;">
                <option value="all" <?php echo $collection_filter === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="not_taken" <?php echo $collection_filter === 'not_taken' ? 'selected' : ''; ?>>Not Taken</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
            <?php if (!empty($search)): ?>
                <a href="view_request.php?collection_filter=<?php echo urlencode($collection_filter); ?>" class="btn btn-secondary">Clear</a>
                <a href="export_excel.php?search=<?php echo urlencode($search); ?>&collection_filter=<?php echo urlencode($collection_filter); ?>" class="btn btn-success"><i class="bi bi-download"></i> Export</a>
            <?php elseif ($collection_filter !== 'all'): ?>
                <a href="view_request.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Books (Click to toggle)</th>
                        <th>Cost</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>Balance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <?php
                        $total_amount = floatval($row['total_amount']);
                        $amount_paid = floatval($row['amount_paid']);
                        $credit_used = floatval($row['credit_used'] ?? 0);
                        $due_after_credit = max(0, $total_amount - $credit_used);
                        $cash_overpaid = max(0, $amount_paid - $due_after_credit);
                        $debit_amount = max(0, $due_after_credit - $amount_paid);
                        $overpaid_amount = $cash_overpaid;
                        $refunded_amount = max(0, floatval($row['refunded_amount'] ?? 0));
                    ?>
                    <tr>
                        <td><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                        <td>
                            <div class="student-info">
                                <div class="name"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                <div class="index"><?php echo htmlspecialchars($row['index_number']); ?></div>
                                <a href="student_history.php?student_id=<?php echo intval($row['student_id']); ?>&index=<?php echo urlencode($row['index_number']); ?>" class="history-btn"><i class="bi bi-clock-history"></i> History</a>
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
                                        $icon = ($is_collected == 1) ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle"></i>';
                                        $item_id_int = intval($item_id);
                                        $safe_title_attr = htmlspecialchars($title, ENT_QUOTES);
                                        echo "<a href='toggle_book_collection.php?item_id={$item_id_int}&csrf_token=" . urlencode($csrf_token) . "' class='book-tag $tagClass' data-title='{$safe_title_attr}'>$icon $title</a>";
                                    }
                                }
                            }
                            ?>
                        </td>
                        <td><strong>GH₵ <?php echo number_format($row['total_amount'], 2); ?></strong></td>
                        <td>GH₵ <?php echo number_format($row['amount_paid'], 2); ?></td>
                        <td>
                            <a href="toggle_payment.php?request_id=<?php echo $row['request_id']; ?>&csrf_token=<?php echo urlencode($csrf_token); ?>" 
                               class="status-badge <?php echo ($row['payment_status'] == 'paid') ? 'status-paid' : 'status-unpaid'; ?>">
                                <?php echo strtoupper($row['payment_status']); ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($refunded_amount > 0): ?>
                                <div class="credit-amt">Returned: GH₵ <?php echo number_format($refunded_amount, 2); ?></div>
                                <?php if (!empty($row['last_return_date'])): ?>
                                    <div class="index" style="margin-top:4px;"><?php echo date('M d, Y', strtotime($row['last_return_date'])); ?></div>
                                <?php endif; ?>
                            <?php elseif ($debit_amount > 0): ?>
                                <div class="debit-amt">Owes: GH₵ <?php echo number_format($debit_amount, 2); ?></div>
                            <?php elseif ($cash_overpaid > 0): ?>
                                <div class="credit-amt">Balance: GH₵ <?php echo number_format($cash_overpaid, 2); ?></div>
                            <?php else: ?>
                                <span class="zero-amt">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="edit_request.php?id=<?php echo $row['request_id']; ?>" class="action-btn action-edit" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <?php if ($refunded_amount <= 0 && $overpaid_amount > 0): ?>
                                <a href="view_request.php?return_balance=1&request_id=<?php echo $row['request_id']; ?>&student_id=<?php echo $row['student_id']; ?>&csrf_token=<?php echo urlencode($csrf_token); ?>" 
                                   class="action-btn action-return" 
                                   onclick="return confirm('Mark this balance as returned to the student?');" 
                                   title="Mark Returned"><i class="bi bi-cash-coin"></i></a>
                            <?php endif; ?>
                            <a href="delete_request.php?id=<?php echo $row['request_id']; ?>&csrf_token=<?php echo urlencode($csrf_token); ?>" class="action-btn action-delete" 
                               onclick="return confirm('Delete this request?');" title="Delete"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <div class="icon"><i class="bi bi-inbox"></i></div>
                                <p>No requests found</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <div>
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary" href="view_request.php?<?php echo http_build_query(array_merge($_GET, ['page' => $prev_page])); ?>"><i class="bi bi-arrow-left"></i> Prev</a>
                <?php endif; ?>
            </div>
            <div class="page-indicator">Page <?php echo intval($page); ?></div>
            <div>
                <?php if ($show_next): ?>
                    <a class="btn btn-secondary" href="view_request.php?<?php echo http_build_query(array_merge($_GET, ['page' => $next_page])); ?>">Next <i class="bi bi-arrow-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
// AJAX toggle for book collection status - prevents page scroll
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.book-tag').forEach(function(tag) {
        tag.addEventListener('click', function(e) {
            e.preventDefault();
            var link = this;
            var url = link.getAttribute('href') + '&ajax=1';
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        var title = link.getAttribute('data-title') || link.textContent.trim();
                        // Update tag appearance
                        if (data.is_collected === 1) {
                            link.classList.remove('tag-pending');
                            link.classList.add('tag-collected');
                            link.innerHTML = '<i class="bi bi-check-lg"></i> ' + title;
                        } else {
                            link.classList.remove('tag-collected');
                            link.classList.add('tag-pending');
                            link.innerHTML = '<i class="bi bi-circle"></i> ' + title;
                        }
                    } else {
                        alert('Failed to toggle: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    alert('Error toggling book status');
                });
        });
    });
});
</script>

</body>
</html>