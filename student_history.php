<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (function_exists('book_system_sync_connection_timezone')) {
    book_system_sync_connection_timezone($conn);
}
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
        book_system_setup_ensure_column($conn, 'request_items', 'cancelled_at', 'DATETIME NULL AFTER is_cancelled');
        book_system_setup_ensure_column($conn, 'request_items', 'cancel_reason', 'VARCHAR(255) NULL AFTER cancelled_at');
        book_system_setup_ensure_column($conn, 'request_items', 'cash_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cancel_reason');
        book_system_setup_ensure_column($conn, 'request_items', 'credit_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cash_refunded_amount');
    }
}

if (!isset($_SESSION['admin_logged_in'])) { header('Location: admin.php'); exit; }

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;
$csrf_token = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_item'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: student_history.php?msg=csrf_invalid');
        exit;
    }

    $cancel_item_id = intval($_POST['item_id'] ?? 0);
    $cancel_reason = trim(strval($_POST['cancel_reason'] ?? ''));
    $cancel_result = function_exists('book_system_cancel_request_item')
        ? book_system_cancel_request_item($conn, $cancel_item_id, $current_admin_id, $is_super_admin, $cancel_reason)
        : ['success' => false, 'message' => 'Refund helper is unavailable.'];

    $redirect_params = [];
    $redirect_student_id = intval($_POST['student_id'] ?? 0);
    $redirect_index = trim(strval($_POST['index'] ?? ''));
    $redirect_semester = intval($_POST['semester_id'] ?? 0);
    if ($redirect_student_id > 0) {
        $redirect_params['student_id'] = $redirect_student_id;
    }
    if ($redirect_index !== '') {
        $redirect_params['index'] = $redirect_index;
    }
    if ($redirect_semester > 0) {
        $redirect_params['semester_id'] = $redirect_semester;
    }
    $redirect_params['msg'] = $cancel_result['success'] ? 'cancelled' : 'cancel_failed';
    if (!$cancel_result['success'] && !empty($cancel_result['message'])) {
        $redirect_params['detail'] = substr($cancel_result['message'], 0, 120);
    }

    header('Location: student_history.php?' . http_build_query($redirect_params));
    exit;
}

$active_semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$active_semester_name = isset($ACTIVE_SEMESTER_NAME) ? trim(strval($ACTIVE_SEMESTER_NAME)) : '';
$active_semester_label = isset($ACTIVE_SEMESTER_LABEL) ? trim(strval($ACTIVE_SEMESTER_LABEL)) : $active_semester_name;
$semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : 0;
if ($semester_id <= 0 && $active_semester_id > 0) {
    $semester_id = $active_semester_id;
}
$history_semester_label = '';
if ($semester_id > 0) {
    if ($semester_id === $active_semester_id && $active_semester_label !== '') {
        $history_semester_label = $active_semester_label;
    } else {
        $semester_label_stmt = $conn->prepare("SELECT semester_name, semester_start_date FROM semesters WHERE semester_id = ? LIMIT 1");
        if ($semester_label_stmt) {
            $semester_label_stmt->bind_param('i', $semester_id);
            $semester_label_stmt->execute();
            $semester_label_res = $semester_label_stmt->get_result();
            if ($semester_label_res && $semester_label_res->num_rows === 1) {
                $semester_label_row = $semester_label_res->fetch_assoc();
                $history_semester_label = function_exists('book_system_build_semester_label')
                    ? strval(book_system_build_semester_label(
                        strval($semester_label_row['semester_name'] ?? ''),
                        strval($semester_label_row['semester_start_date'] ?? '')
                    ))
                    : trim(strval($semester_label_row['semester_name'] ?? ''));
            }
            $semester_label_stmt->close();
        }
    }
}

$student_id = intval($_GET['student_id'] ?? 0);
$index = trim($_GET['index'] ?? '');
$return_url = trim(strval($_GET['return_url'] ?? 'view_request.php'));
if ($return_url === '' || preg_match('/^\s*(?:https?:)?\/\//i', $return_url)) {
    $return_url = 'view_request.php';
}
if (strpos($return_url, 'view_request.php') !== 0) {
    $return_url = 'view_request.php';
}

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

$sql = "SELECT r.request_id, r.student_id, r.created_at, r.total_amount, r.amount_paid, r.credit_used, r.payment_status,
               COALESCE(br.refunded_amount, 0) AS refunded_amount,
               COALESCE(SUM(COALESCE(ri.credit_refunded_amount, 0)), 0) AS credit_refunded_amount,
               GROUP_CONCAT(CONCAT_WS('~',
                    ri.item_id,
                    REPLACE(COALESCE(b.book_title, ''), '~', '-'),
                    COALESCE(ri.is_collected, 0),
                    COALESCE(ri.is_cancelled, 0),
                    COALESCE(ri.cash_refunded_amount, 0),
                    COALESCE(ri.credit_refunded_amount, 0)
               ) SEPARATOR '|') as books_data
        FROM requests r
        LEFT JOIN students s ON r.student_id = s.student_id
        LEFT JOIN request_items ri ON r.request_id = ri.request_id
        LEFT JOIN books b ON ri.book_id = b.book_id
        LEFT JOIN (
            SELECT request_id, SUM(amount) AS refunded_amount
            FROM balance_returns
            GROUP BY request_id
        ) br ON br.request_id = r.request_id
        LEFT JOIN (
            SELECT request_id, SUM(amount) AS carried_forward_amount
            FROM semester_balance_carry_forwards
            GROUP BY request_id
        ) cf ON cf.request_id = r.request_id
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
$grand_total_credit_refunded = 0;
$history_rows = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $total_amount = floatval($row['total_amount']);
        $amount_paid = floatval($row['amount_paid']);
        $credit_used = floatval($row['credit_used'] ?? 0);
        $refunded_amount = floatval($row['refunded_amount'] ?? 0);
        $carried_forward_amount = floatval($row['carried_forward_amount'] ?? 0);
        $credit_refunded_amount = floatval($row['credit_refunded_amount'] ?? 0);
        $due_after_credit = max(0, $total_amount - $credit_used);
        $cash_overpaid = max(0, $amount_paid - $due_after_credit);
        $outstanding = max(0, $due_after_credit - $amount_paid);
        $available_credit = max(0, $cash_overpaid - $refunded_amount - $carried_forward_amount);

        $row['calc_due_after_credit'] = $due_after_credit;
        $row['calc_outstanding'] = $outstanding;
        $row['calc_available_credit'] = $available_credit;
        $history_rows[] = $row;

        $grand_total_cost += $total_amount;
        $grand_total_paid += ($amount_paid + $credit_used);
        $grand_total_outstanding += $outstanding;
        $grand_total_returned += ($refunded_amount + $carried_forward_amount);
        $grand_total_available_credit += $available_credit;
        $grand_total_credit_refunded += $credit_refunded_amount;
    }
}

$student_history_refresh_state = [
    'student_id' => $student_id,
    'index' => $index,
    'semester_id' => $semester_id,
    'totals' => [
        'cost' => round($grand_total_cost, 2),
        'paid' => round($grand_total_paid, 2),
        'outstanding' => round($grand_total_outstanding, 2),
        'returned' => round($grand_total_returned, 2),
        'available_credit' => round($grand_total_available_credit, 2),
        'credit_refunded' => round($grand_total_credit_refunded, 2),
    ],
    'rows' => array_map(static function (array $row): array {
        return [
            'request_id' => intval($row['request_id'] ?? 0),
            'amount_paid' => round(floatval($row['amount_paid'] ?? 0), 2),
            'total_amount' => round(floatval($row['total_amount'] ?? 0), 2),
            'credit_used' => round(floatval($row['credit_used'] ?? 0), 2),
            'refunded_amount' => round(floatval($row['refunded_amount'] ?? 0), 2),
            'credit_refunded_amount' => round(floatval($row['credit_refunded_amount'] ?? 0), 2),
            'books_data' => strval($row['books_data'] ?? ''),
            'created_at' => strval($row['created_at'] ?? ''),
        ];
    }, $history_rows),
];
$student_history_refresh_token = function_exists('book_system_build_refresh_token')
    ? book_system_build_refresh_token($student_history_refresh_state)
    : sha1(json_encode($student_history_refresh_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if (function_exists('book_system_maybe_output_refresh_status')) {
    book_system_maybe_output_refresh_status($student_history_refresh_token);
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
            background:
                radial-gradient(circle at top left, rgba(99, 102, 241, 0.08), transparent 28%),
                linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
            min-height: 100vh;
            color: #0f172a;
            padding: 26px 16px 34px;
        }
        
        .page-container { max-width: 1180px; margin: 0 auto; }
        
        .page-header {
            background: rgba(255,255,255,0.96);
            border: 1px solid rgba(148, 163, 184, 0.18);
            padding: 26px 30px;
            border-radius: 24px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
        }
        .page-header h1 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
        }
        .page-header h1 i {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%);
            color: #4f46e5;
            font-size: 18px;
        }
        .page-header .subtitle {
            color: #64748b;
            margin-top: 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .page-header .meta-line {
            margin-top: 8px;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .back-btn {
            background: #ffffff;
            color: #334155;
            padding: 11px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
            border: 1px solid #dbe3ef;
            box-shadow: 0 6px 18px rgba(148, 163, 184, 0.14);
        }
        .back-btn:hover { background: #f8fafc; }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 24px 24px 14px;
            border: 1px solid rgba(148, 163, 184, 0.16);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 22px;
        }
        .summary-card {
            background: white;
            border-radius: 22px;
            padding: 22px 22px 20px;
            border: 1px solid rgba(148, 163, 184, 0.15);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.05);
            position: relative;
            overflow: hidden;
        }
        .summary-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #4f46e5 0%, #7c3aed 100%);
        }
        .summary-card:nth-child(2)::before {
            background: linear-gradient(90deg, #0891b2 0%, #2563eb 100%);
        }
        .summary-card:nth-child(3)::before {
            background: linear-gradient(90deg, #f59e0b 0%, #f97316 100%);
        }
        .summary-card:nth-child(4)::before {
            background: linear-gradient(90deg, #10b981 0%, #14b8a6 100%);
        }
        .summary-card .label {
            font-size: 12px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
        }
        .summary-card .value {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding-bottom: 16px;
            margin-bottom: 14px;
            border-bottom: 1px solid #e5e7eb;
        }
        .section-head h2 {
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-head h2 i {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #4338ca;
        }
        .section-note {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 760px; }
        th {
            background: #f8fafc;
            padding: 15px 14px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            font-weight: 700;
            border-bottom: 1px solid #e2e8f0;
        }
        td {
            padding: 16px 14px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
            font-size: 14px;
        }
        tbody tr:hover { background: #fbfdff; }
        
        .book-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            margin: 2px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .collected { background: #dcfce7; color: #166534; border-color: #86efac; }
        .pending { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .cancelled { background: #e5e7eb; color: #4b5563; border-color: #d1d5db; }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-paid { background: #dcfce7; color: #166534; }
        .status-unpaid { background: #fee2e2; color: #991b1b; }
        
        .credit-text { color: #0f766e; font-weight: 700; line-height: 1.5; }
        
        .total-row {
            background: #f8fafc;
            color: #0f172a;
        }
        .total-row td {
            font-weight: 800;
            border-bottom: none;
            border-top: 2px solid #cbd5e1;
        }
        .total-row .credit-text { color: #0f766e; }
        
        .empty-state { text-align: center; padding: 38px 16px; color: #64748b; }
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .print-btn {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            border: none;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(99, 102, 241, 0.25);
        }
        .alert {
            padding: 13px 16px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .books-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .books-wrap form {
            margin: 0;
        }
        .cancel-form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .book-pill-button {
            border: none;
            cursor: pointer;
            transition: transform 0.15s ease, opacity 0.15s ease, box-shadow 0.15s ease;
        }
        .book-pill-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08);
        }
        .book-pill-button.is-saving {
            opacity: 0.6;
            cursor: wait;
            transform: none;
            box-shadow: none;
        }
        .cancel-btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #b91c1c;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }
        .cancel-btn:hover { background: #fee2e2; }
        .cancel-reason {
            border: 1px solid #d1d5db;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            min-width: 180px;
        }
        .money {
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
        }
        .muted { color: #64748b; }
        @media (max-width: 900px) {
            .page-header { flex-direction: column; align-items: flex-start; }
            .section-head { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 540px) {
            .summary-grid { grid-template-columns: 1fr; }
            body { padding: 18px 12px 24px; }
            .page-header,
            .card,
            .summary-card { border-radius: 18px; }
            .page-header { padding: 22px 18px; }
            .card { padding: 18px 16px 10px; }
            .page-header h1 { font-size: 22px; }
            .summary-grid { grid-template-columns: 1fr; }
        }
        @media print {
            body {
                background: white;
                padding: 0;
                color: #000;
            }
            .page-container {
                max-width: 100%;
                margin: 0;
            }
            .page-header, .summary-card, .card {
                box-shadow: none;
                border: 1px solid #d1d5db;
                background: #fff;
            }
            .page-header {
                padding: 18px 20px;
                margin-bottom: 14px;
            }
            .summary-grid {
                gap: 10px;
                margin-bottom: 14px;
            }
            .summary-card {
                padding: 14px 16px;
            }
            .summary-card .value {
                font-size: 20px;
            }
            .back-btn, .print-btn, .cancel-form, footer {
                display: none !important;
            }
            .card {
                padding: 14px 16px 8px;
            }
            .section-head {
                margin-bottom: 10px;
                padding-bottom: 10px;
            }
            table {
                min-width: 0;
            }
            th, td {
                padding: 10px 8px;
                font-size: 12px;
            }
            .book-pill, .status-badge { box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-clock-history"></i> Student History</h1>
            <p class="subtitle">Index: <?php echo htmlspecialchars($index); ?></p>
            <p class="meta-line">
                Printable request and collection summary
                <?php if ($semester_id > 0 && $history_semester_label !== ''): ?>
                    • <?php echo htmlspecialchars($history_semester_label); ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="header-actions">
            <button type="button" class="print-btn" onclick="window.print()">Print Summary</button>
            <button type="button" class="back-btn" data-return-url="<?php echo htmlspecialchars($return_url, ENT_QUOTES); ?>" onclick="goBackToRequests(this)"><i class="bi bi-arrow-left"></i> Back to Requests</button>
        </div>
    </div>

    <div class="card">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cancelled'): ?>
            <div class="alert alert-success">Book cancelled and refund recorded successfully.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'cancel_failed'): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($_GET['detail'] ?? 'Could not cancel this book item.'); ?></div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'csrf_invalid'): ?>
            <div class="alert alert-error">Invalid request. Please refresh and try again.</div>
        <?php endif; ?>
        <div class="section-head">
            <h2><i class="bi bi-journal-text"></i> Request Timeline</h2>
            <div class="section-note"><?php echo count($history_rows); ?> record<?php echo count($history_rows) === 1 ? '' : 's'; ?> found</div>
        </div>
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
                        <th>Action</th>
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
                            $carried_forward_amount = floatval($row['carried_forward_amount'] ?? 0);
                            $credit_refunded_amount = floatval($row['credit_refunded_amount'] ?? 0);
                            $outstanding = floatval($row['calc_outstanding'] ?? 0);
                            $available_credit = floatval($row['calc_available_credit'] ?? 0);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(function_exists('book_system_format_datetime_local') ? book_system_format_datetime_local(strval($row['created_at'] ?? ''), 'M d, Y') : date('M d, Y', strtotime($row['created_at']))); ?></td>
                            <td>
                                <div class="books-wrap">
                                <?php 
                                if (!empty($row['books_data'])) {
                                    $books = explode('|', $row['books_data']);
                                    foreach ($books as $book) {
                                        if ($book === '') continue;
                                        $parts = explode('~', $book);
                                        if (count($parts) < 6) continue;
                                        $item_id = intval($parts[0] ?? 0);
                                        $title = $parts[1];
                                        $is_collected = intval($parts[2] ?? 0);
                                        $is_cancelled = intval($parts[3] ?? 0);
                                        $cash_refunded = floatval($parts[4] ?? 0);
                                        $credit_refunded = floatval($parts[5] ?? 0);

                                        if ($is_cancelled === 1) {
                                            $refund_bits = [];
                                            if ($cash_refunded > 0) {
                                                $refund_bits[] = 'Refunded GH&#8373; ' . number_format($cash_refunded, 2);
                                            }
                                            if ($credit_refunded > 0) {
                                                $refund_bits[] = 'Credit GH&#8373; ' . number_format($credit_refunded, 2);
                                            }
                                            $refund_text = !empty($refund_bits) ? ' - ' . implode(' | ', $refund_bits) : ' - Refunded';
                                            echo "<span class='book-pill cancelled'><i class='bi bi-x-circle'></i> " . htmlspecialchars($title) . $refund_text . "</span> ";
                                            continue;
                                        }

                                        $class = ($is_collected === 1) ? 'collected' : 'pending';
                                        $icon = ($is_collected === 1) ? '<i class=\"bi bi-check-lg\"></i>' : '<i class=\"bi bi-circle\"></i>';
                                        if ($item_id > 0) {
                                            ?>
                                            <form method="POST" action="toggle_book_collection.php" class="history-toggle-form" data-item-id="<?php echo $item_id; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                                                <input type="hidden" name="item_id" value="<?php echo $item_id; ?>">
                                                <input type="hidden" name="desired_state" value="<?php echo $is_collected === 1 ? '0' : '1'; ?>">
                                                <button
                                                    type="submit"
                                                    class="book-pill book-pill-button <?php echo $class; ?>"
                                                    data-item-id="<?php echo $item_id; ?>"
                                                    data-state="<?php echo $is_collected === 1 ? '1' : '0'; ?>"
                                                    data-title="<?php echo htmlspecialchars($title, ENT_QUOTES); ?>"
                                                >
                                                    <?php echo $icon; ?> <?php echo htmlspecialchars($title); ?>
                                                </button>
                                            </form>
                                            <?php
                                        } else {
                                            echo "<span class='book-pill $class'>$icon " . htmlspecialchars($title) . "</span> ";
                                        }
                                    }
                                } else {
                                    echo "<span style='color:#888;'>&mdash;</span>";
                                }
                                ?>
                                </div>
                            </td>
                            <td class="money">GH&#8373; <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td class="money">GH&#8373; <?php echo number_format($amount_paid + $credit_used, 2); ?></td>

                            <td>
                                <span class="status-badge <?php echo ($row['payment_status'] == 'paid') ? 'status-paid' : 'status-unpaid'; ?>">
                                    <?php echo strtoupper($row['payment_status']); ?>
                                </span>
                            </td>
                            <td class="credit-text">
                                <?php if ($outstanding > 0): ?>
                                    Owes GH&#8373; <?php echo number_format($outstanding, 2); ?>
                                <?php elseif ($refunded_amount > 0 || $carried_forward_amount > 0 || $credit_refunded_amount > 0): ?>
                                    <?php if ($refunded_amount > 0): ?>
                                        Returned GH&#8373; <?php echo number_format($refunded_amount, 2); ?>
                                    <?php endif; ?>
                                    <?php if (($refunded_amount > 0) && ($carried_forward_amount > 0 || $credit_refunded_amount > 0)): ?><br><?php endif; ?>
                                    <?php if ($carried_forward_amount > 0): ?>
                                        Carried Forward GH&#8373; <?php echo number_format($carried_forward_amount, 2); ?>
                                    <?php endif; ?>
                                    <?php if (($refunded_amount > 0 || $carried_forward_amount > 0) && $credit_refunded_amount > 0): ?><br><?php endif; ?>
                                    <?php if ($credit_refunded_amount > 0): ?>
                                        Credit Returned GH&#8373; <?php echo number_format($credit_refunded_amount, 2); ?>
                                    <?php endif; ?>
                                <?php elseif ($available_credit > 0): ?>
                                    Credit GH&#8373; <?php echo number_format($available_credit, 2); ?>
                                <?php else: ?>
                                    <span class="muted">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if (!empty($row['books_data'])) {
                                    $books = explode('|', $row['books_data']);
                                    foreach ($books as $book) {
                                        if ($book === '') continue;
                                        $parts = explode('~', $book);
                                        if (count($parts) < 6) continue;
                                        $item_id = intval($parts[0] ?? 0);
                                        $title = $parts[1];
                                        $is_collected = intval($parts[2] ?? 0);
                                        $is_cancelled = intval($parts[3] ?? 0);
                                        if ($item_id <= 0 || $is_collected === 1 || $is_cancelled === 1) {
                                            continue;
                                        }
                                        ?>
                                        <form method="POST" class="cancel-form" onsubmit="return confirm('Cancel <?php echo htmlspecialchars(addslashes($title), ENT_QUOTES); ?> and record the refund?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="cancel_item" value="1">
                                            <input type="hidden" name="item_id" value="<?php echo $item_id; ?>">
                                            <input type="hidden" name="student_id" value="<?php echo intval($row['student_id']); ?>">
                                            <input type="hidden" name="index" value="<?php echo htmlspecialchars($index); ?>">
                                            <input type="hidden" name="semester_id" value="<?php echo intval($semester_id); ?>">
                                            <input type="text" name="cancel_reason" class="cancel-reason" placeholder="Reason for cancelling <?php echo htmlspecialchars($title); ?>">
                                            <button type="submit" class="cancel-btn">Cancel & Refund</button>
                                        </form>
                                        <?php
                                    }
                                }
                                ?>
                            </td>

                        </tr>
                        <?php endforeach; ?>
                        
                        <tr class="total-row">
                            <td colspan="3" style="text-align: right;">Cumulative Totals:</td>
                            <td>GH&#8373; <?php echo number_format($grand_total_cost, 2); ?></td>
                            <td>GH&#8373; <?php echo number_format($grand_total_paid, 2); ?></td>

                            <td></td>
                            <td class="credit-text">
                                GH&#8373; <?php 
                                    $total_credit = $grand_total_returned + $grand_total_available_credit + $grand_total_credit_refunded;
                                    echo number_format(($total_credit > 0 ? $total_credit : 0), 2);
                                ?>

                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    function parseJsonResponse(response) {
        if (!response.ok) {
            throw new Error('Request failed');
        }
        return response.text().then(function(text) {
            var clean = String(text || '').replace(/^\uFEFF/, '').trim();
            return clean ? JSON.parse(clean) : {};
        });
    }

    function applyHistoryBookState(itemId, isCollected) {
        document.querySelectorAll('.book-pill-button[data-item-id="' + itemId + '"]').forEach(function(button) {
            button.dataset.state = isCollected ? '1' : '0';
            button.classList.remove('collected', 'pending');
            button.classList.add(isCollected ? 'collected' : 'pending');
            button.innerHTML = (isCollected ? '<i class="bi bi-check-lg"></i> ' : '<i class="bi bi-circle"></i> ') + button.dataset.title;
        });
        document.querySelectorAll('.history-toggle-form[data-item-id="' + itemId + '"]').forEach(function(form) {
            var desiredInput = form.querySelector('input[name="desired_state"]');
            if (desiredInput) {
                desiredInput.value = isCollected ? '0' : '1';
            }
        });
    }

    document.querySelectorAll('.history-toggle-form').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();

            var button = form.querySelector('.book-pill-button');
            if (!button) {
                return;
            }

            var itemId = button.getAttribute('data-item-id') || form.getAttribute('data-item-id');
            var previousState = button.dataset.state === '1';
            var nextState = !previousState;

            var desiredValue = nextState ? '1' : '0';
            var data = new FormData(form);
            data.set('desired_state', desiredValue);
            data.append('ajax', '1');

            applyHistoryBookState(itemId, nextState);
            button.classList.add('is-saving');
            button.disabled = true;

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: data,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
            .then(parseJsonResponse)
            .then(function(payload) {
                if (payload && payload.success) {
                    applyHistoryBookState(String(payload.item_id || itemId), parseInt(payload.is_collected || 0, 10) === 1);
                } else {
                    applyHistoryBookState(itemId, previousState);
                    alert((payload && (payload.error || payload.message)) ? (payload.error || payload.message) : 'Could not update book status right now.');
                }
            })
            .catch(function() {
                applyHistoryBookState(itemId, previousState);
                alert('Could not update book status right now.');
            })
            .finally(function() {
                button.classList.remove('is-saving');
                button.disabled = false;
            });
        });
    });
});

function goBackToRequests(button) {
    var returnUrl = (button && button.getAttribute('data-return-url')) || 'view_request.php';
    var referrer = document.referrer || '';
    var cameFromRequests = referrer.indexOf('view_request.php') !== -1 && window.history.length > 1;

    if (cameFromRequests) {
        window.history.back();
        window.setTimeout(function() {
            if (document.visibilityState === 'visible') {
                window.location.href = returnUrl;
            }
        }, 700);
        return;
    }

    window.location.href = returnUrl;
}
</script>

<?php
if (function_exists('book_system_render_refresh_polling_script')) {
    book_system_render_refresh_polling_script($student_history_refresh_token, [
        'interval_ms' => 15000,
        'min_gap_ms' => 10000,
        'pause_selectors' => [
            '.cancel-reason:focus',
            '.history-toggle-form .book-pill-button.is-saving',
        ],
    ]);
}
?>

</body>
</html>



