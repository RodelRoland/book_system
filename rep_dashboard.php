<?php
session_start();
require_once 'db.php';

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Redirect super admin to admin.php
if (($_SESSION['admin_role'] ?? '') === 'super_admin') {
    header('Location: admin.php');
    exit;
}

// Handle Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: rep_dashboard.php?msg=csrf_invalid');
        exit;
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

// Get current rep info
$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_class = $_SESSION['admin_class_name'] ?? '';
$current_admin_name = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Rep';

// Fetch rep's stats
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$total_collected = 0;
$paid_to_lecturers = 0;
$total_pending = 0;

$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total FROM requests WHERE payment_status = 'paid' AND semester_id = ? AND admin_id = ?");
if ($stmt) {
    $stmt->bind_param('ii', $semester_id, $current_admin_id);
    $stmt->execute();
    $rev_res = $stmt->get_result();
    if ($rev_res && $rev_res->num_rows === 1) {
        $total_collected = floatval($rev_res->fetch_assoc()['total'] ?? 0);
    }
}

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) AS total FROM lecturer_payments WHERE semester_id = ? AND admin_id = ?");
if ($stmt) {
    $stmt->bind_param('ii', $semester_id, $current_admin_id);
    $stmt->execute();
    $lec_res = $stmt->get_result();
    if ($lec_res && $lec_res->num_rows === 1) {
        $paid_to_lecturers = floatval($lec_res->fetch_assoc()['total'] ?? 0);
    }
}

$net_balance = $total_collected - $paid_to_lecturers;

$stmt = $conn->prepare("SELECT COUNT(*) AS count FROM requests WHERE payment_status = 'unpaid' AND semester_id = ? AND admin_id = ?");
if ($stmt) {
    $stmt->bind_param('ii', $semester_id, $current_admin_id);
    $stmt->execute();
    $pen_res = $stmt->get_result();
    if ($pen_res && $pen_res->num_rows === 1) {
        $total_pending = intval($pen_res->fetch_assoc()['count'] ?? 0);
    }
}

// Get active semester name
$active_semester_name = '';
$stmt = $conn->prepare("SELECT semester_name FROM semesters WHERE is_active = 1 ORDER BY semester_id DESC LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $sem_res = $stmt->get_result();
    if ($sem_res && $sem_res->num_rows > 0) {
        $active_semester_name = strval($sem_res->fetch_assoc()['semester_name'] ?? '');
    }
}

// Get rep's unique order link
$rep_username = $_SESSION['admin_username'] ?? '';
$order_link = "index.php?rep=" . urlencode($rep_username);
$request_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$request_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$request_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/book_system/rep_dashboard.php')), '/');
$public_order_link = $request_scheme . '://' . $request_host . ($request_dir !== '' ? $request_dir : '') . '/' . $order_link;

$csrf_token = csrf_get_token();
$dashboard_metrics = function_exists('book_system_get_dashboard_metrics')
    ? book_system_get_dashboard_metrics($conn, $semester_id, $current_admin_id)
    : [];
$dashboard_alerts = function_exists('book_system_get_dashboard_alerts')
    ? book_system_get_dashboard_alerts($conn, $semester_id, $current_admin_id)
    : [];
$semester_chart_rows = function_exists('book_system_get_semester_chart_data')
    ? book_system_get_semester_chart_data($conn, 6, $current_admin_id)
    : [];
$recent_activity = function_exists('book_system_fetch_recent_activity')
    ? book_system_fetch_recent_activity($conn, 8, $current_admin_id, false)
    : [];

if (!empty($dashboard_metrics)) {
    $total_collected = floatval($dashboard_metrics['cash_collected'] ?? $dashboard_metrics['paid_revenue'] ?? $total_collected);
    $paid_to_lecturers = floatval($dashboard_metrics['lecturer_paid'] ?? $paid_to_lecturers);
    $net_balance = floatval($dashboard_metrics['available_balance'] ?? ($total_collected - $paid_to_lecturers));
    $total_pending = intval($dashboard_metrics['unpaid_requests'] ?? $total_pending);
}

$chart_max_value = 1;
foreach ($semester_chart_rows as $chart_row) {
    $chart_max_value = max(
        $chart_max_value,
        floatval($chart_row['revenue'] ?? 0),
        floatval($chart_row['unpaid_balance'] ?? 0),
        intval($chart_row['collected_items'] ?? 0),
        intval($chart_row['pending_items'] ?? 0)
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rep Dashboard - <?php echo htmlspecialchars($current_admin_class); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #43a047 0%, #2e7d32 100%);
            color: white;
            padding: 25px;
            border-radius: 16px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(46, 125, 50, 0.3);
        }
        .dashboard-header h1 { font-size: 24px; font-weight: 600; }
        .dashboard-header .subtitle { opacity: 0.9; margin-top: 5px; font-size: 14px; }
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .logout-btn:hover { background: rgba(255,255,255,0.3); }
        
        .order-link-box {
            background: rgba(255,255,255,0.15);
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .order-link-box code {
            background: rgba(0,0,0,0.2);
            padding: 5px 10px;
            border-radius: 4px;
            font-family: monospace;
            word-break: break-all;
        }
        .copy-btn {
            background: white;
            color: #2e7d32;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
        }
        .copy-btn:hover { background: #f5f5f5; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        @media (max-width: 500px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 4px solid #43a047;
        }
        .stat-card.blue { border-left-color: #1976d2; }
        .stat-card.orange { border-left-color: #f57c00; }
        .stat-card.red { border-left-color: #d32f2f; }
        
        .stat-card .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .stat-card .value {
            font-size: 22px;
            font-weight: 700;
            color: #333;
        }
        
        .menu-section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .overview-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .panel {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .panel-wide { grid-column: 1 / -1; }
        .panel h2 {
            font-size: 16px;
            color: #1f2937;
            margin-bottom: 14px;
        }
        .chart-group { margin-bottom: 16px; }
        .chart-group:last-child { margin-bottom: 0; }
        .chart-label {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 12px;
            color: #4b5563;
            margin-bottom: 6px;
            font-weight: 600;
        }
        .chart-track {
            height: 10px;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .chart-fill {
            height: 100%;
            border-radius: 999px;
        }
        .chart-fill.revenue { background: linear-gradient(135deg, #34d399 0%, #059669 100%); }
        .chart-fill.unpaid { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .chart-fill.collected { background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%); }
        .chart-fill.pending { background: linear-gradient(135deg, #f87171 0%, #dc2626 100%); }
        .alert-list {
            display: grid;
            gap: 10px;
        }
        .alert-banner {
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
        }
        .alert-warning { background: #fff7ed; color: #9a3412; border-left: 4px solid #f59e0b; }
        .alert-info { background: #eff6ff; color: #1d4ed8; border-left: 4px solid #3b82f6; }
        .alert-danger { background: #fef2f2; color: #b91c1c; border-left: 4px solid #ef4444; }
        .empty-note {
            background: #f8fafc;
            color: #64748b;
            padding: 14px;
            border-radius: 12px;
            font-size: 13px;
        }
        .activity-list {
            display: grid;
            gap: 10px;
        }
        .activity-item {
            padding: 12px 14px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
        }
        .activity-item .title {
            font-weight: 700;
            color: #1f2937;
            font-size: 14px;
        }
        .activity-item .meta {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }
        .activity-item .details {
            font-size: 12px;
            color: #475569;
            margin-top: 6px;
        }
        @media (max-width: 760px) {
            .overview-grid { grid-template-columns: 1fr; }
        }
        .menu-section h2 {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e8f5e9;
        }
        
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        @media (max-width: 500px) { .menu-grid { grid-template-columns: 1fr; } }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        .menu-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #43a047;
            background: #e8f5e9;
        }
        .menu-item .icon {
            width: 56px;
            height: 56px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-right: 12px;
            background: #e8f5e9;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.75), 0 8px 18px rgba(15, 23, 42, 0.08);
        }
        .menu-item .text h3 { font-size: 14px; font-weight: 600; margin-bottom: 2px; }
        .menu-item .text p { font-size: 11px; color: #888; }
        
        .welcome-note {
            background: #fff3e0;
            border: 1px solid #ffcc80;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #e65100;
        }
        .welcome-note strong { color: #bf360c; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h1>Welcome, <?php echo htmlspecialchars($current_admin_name); ?></h1>
        <p class="subtitle">Class: <?php echo htmlspecialchars($current_admin_class ?: 'Class Representative'); ?><?php echo $active_semester_name ? ' &bull; ' . htmlspecialchars($active_semester_name) : ''; ?></p>
        
        <div class="header-actions">
            <div class="order-link-box">
                <span>&#128279; Your Order Link:</span>
                <code id="orderLink"><?php echo htmlspecialchars($public_order_link); ?></code>
                <button class="copy-btn" onclick="copyLink()">Copy</button>
            </div>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="logout" value="1" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>
    
    <div class="welcome-note">
        <strong>Tip:</strong> Share your order link with students in your class. When they order through your link, their payments will appear in your dashboard.
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Cash Collected</div>
            <div class="value">GH&#8373; <?php echo number_format($total_collected, 2); ?></div>
        </div>
        <div class="stat-card blue">
            <div class="label">Paid to Lecturers</div>
            <div class="value">GH&#8373; <?php echo number_format($paid_to_lecturers, 2); ?></div>
        </div>
        <div class="stat-card orange">
            <div class="label">Available Balance</div>
            <div class="value">GH&#8373; <?php echo number_format($net_balance, 2); ?></div>
        </div>
        <div class="stat-card red">
            <div class="label">Unpaid Requests</div>
            <div class="value"><?php echo $total_pending; ?></div>
        </div>
    </div>
    
    <div class="menu-section">
        <h2>Quick Actions</h2>
        <div class="menu-grid">
            <a href="view_request.php" class="menu-item">
                <div class="icon">&#128228;</div>
                <div class="text">
                    <h3>View Requests</h3>
                    <p>Student orders & payments</p>
                </div>
            </a>
            <a href="manage_books.php" class="menu-item">
                <div class="icon">&#128218;</div>
                <div class="text">
                    <h3>Manage Books</h3>
                    <p>Add, edit prices & availability</p>
                </div>
            </a>
            <a href="lecturer_payments.php" class="menu-item">
                <div class="icon">&#128176;</div>
                <div class="text">
                    <h3>Lecturer Payments</h3>
                    <p>Track payments to lecturers</p>
                </div>
            </a>
            <a href="admin_manual_order.php" class="menu-item">
                <div class="icon">&#10133;</div>
                <div class="text">
                    <h3>Manual Order</h3>
                    <p>Record cash payments</p>
                </div>
            </a>
            <a href="upload_class.php" class="menu-item">
                <div class="icon">&#128203;</div>
                <div class="text">
                    <h3>Upload Class</h3>
                    <p>Import your class roster</p>
                </div>
            </a>
            <a href="my_profile.php" class="menu-item">
                <div class="icon">&#128100;</div>
                <div class="text">
                    <h3>My Profile</h3>
                    <p>Update payment details</p>
                </div>
            </a>
            <a href="activity_log.php" class="menu-item">
                <div class="icon">&#128221;</div>
                <div class="text">
                    <h3>Activity Log</h3>
                    <p>Review your recent request and payment changes</p>
                </div>
            </a>
            <a href="generate_access_code.php" class="menu-item">
                <div class="icon">&#128274;</div>
                <div class="text">
                    <h3>Access Code</h3>
                    <p>Generate code for super admin</p>
                </div>
            </a>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const link = document.getElementById('orderLink').textContent.trim();
    navigator.clipboard.writeText(link).then(() => {
        alert('Order link copied to clipboard!');
    }).catch(() => {
        prompt('Copy this link:', link);
    });
}
</script>

<?php include 'footer.php'; ?>

</body>
</html>

