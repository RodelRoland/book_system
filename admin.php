<?php
session_start();
require_once 'db.php';

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

// 1. Handle Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: admin.php?msg=csrf_invalid");
        exit;
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

if (
    isset($_SESSION['admin_logged_in']) &&
    ($_SESSION['admin_role'] ?? '') === 'super_admin' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['set_active_semester'])
) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: admin.php?msg=csrf_invalid");
        exit;
    }
    $new_id = intval($_POST['semester_id']);
    if ($new_id > 0) {
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE semester_id = ?");
        $stmt->bind_param("i", $new_id);
        $stmt->execute();
        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'set_active_semester', 'semester', $new_id, []);
        }
    }
    header("Location: admin.php");
    exit;
}

if (
    isset($_SESSION['admin_logged_in']) &&
    ($_SESSION['admin_role'] ?? '') === 'super_admin' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_semester'])
) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: admin.php?msg=csrf_invalid");
        exit;
    }
    $name = trim($_POST['semester_name'] ?? '');
    if ($name !== '') {
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("INSERT INTO semesters (semester_name, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'create_semester', 'semester', intval($conn->insert_id), [
                'semester_name' => $name,
            ]);
        }
    }
    header("Location: admin.php");
    exit;
}

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Get current admin info
$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$current_admin_class = $_SESSION['admin_class_name'] ?? '';
$is_super_admin = ($current_admin_role === 'super_admin');
$csrf_token = csrf_get_token();
$metrics_admin_id = $is_super_admin ? null : $current_admin_id;
$dashboard_metrics = function_exists('book_system_get_dashboard_metrics')
    ? book_system_get_dashboard_metrics($conn, $semester_id, $metrics_admin_id)
    : [];
$dashboard_alerts = function_exists('book_system_get_dashboard_alerts')
    ? book_system_get_dashboard_alerts($conn, $semester_id, $metrics_admin_id)
    : [];
$semester_chart_rows = function_exists('book_system_get_semester_chart_data')
    ? book_system_get_semester_chart_data($conn, 6, $metrics_admin_id)
    : [];
$recent_activity = function_exists('book_system_fetch_recent_activity')
    ? book_system_fetch_recent_activity($conn, 8, $current_admin_id, $is_super_admin)
    : [];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .dashboard-container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        /* Header */
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .dashboard-header h1 { font-size: 28px; font-weight: 600; }
        .dashboard-header .subtitle { opacity: 0.9; margin-top: 5px; font-size: 14px; }
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 500px) { .stats-grid { grid-template-columns: 1fr; } }
        
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
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        .stat-card.green::before { background: #28a745; }
        .stat-card.blue::before { background: #17a2b8; }
        .stat-card.yellow::before { background: #ffc107; }
        .stat-card.red::before { background: #dc3545; }
        
        .stat-card .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .stat-card .value {
            font-size: 26px;
            font-weight: 700;
        }
        .stat-card.green .value { color: #28a745; }
        .stat-card.blue .value { color: #17a2b8; }
        .stat-card.yellow .value { color: #d4a500; }
        .stat-card.red .value { color: #dc3545; }
        
        /* Menu Section */
        .menu-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .overview-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .panel {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .panel h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 18px;
        }
        .panel-wide {
            grid-column: 1 / -1;
        }
        .chart-group {
            margin-bottom: 18px;
        }
        .chart-group:last-child {
            margin-bottom: 0;
        }
        .chart-label {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 13px;
            color: #555;
            font-weight: 600;
        }
        .chart-track {
            height: 10px;
            background: #edf2f7;
            border-radius: 999px;
            overflow: hidden;
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
            gap: 12px;
        }
        .alert-banner {
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
        }
        .alert-warning { background: #fff7ed; color: #9a3412; border-left: 4px solid #f59e0b; }
        .alert-info { background: #eff6ff; color: #1d4ed8; border-left: 4px solid #3b82f6; }
        .alert-danger { background: #fef2f2; color: #b91c1c; border-left: 4px solid #ef4444; }
        .empty-note {
            color: #6b7280;
            font-size: 14px;
            background: #f8fafc;
            border-radius: 12px;
            padding: 14px 16px;
        }
        .activity-list {
            display: grid;
            gap: 12px;
        }
        .activity-item {
            padding: 14px 16px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .activity-item .title {
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .activity-item .meta {
            color: #64748b;
            font-size: 12px;
        }
        .activity-item .details {
            color: #475569;
            font-size: 13px;
            margin-top: 6px;
        }
        .inline-header-form {
            margin: 0;
            display: inline-flex;
            gap: 8px;
            align-items: center;
        }
        .logout-btn {
            cursor: pointer;
        }
        @media (max-width: 900px) {
            .overview-grid {
                grid-template-columns: 1fr;
            }
        }
        .menu-section h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        @media (max-width: 600px) { .menu-grid { grid-template-columns: 1fr; } }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        .menu-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .menu-item .icon {
            width: 56px;
            height: 56px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-right: 15px;
            flex-shrink: 0;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.75), 0 8px 18px rgba(15, 23, 42, 0.08);
        }
        .menu-item .text h3 { font-size: 16px; font-weight: 600; margin-bottom: 3px; }
        .menu-item .text p { font-size: 12px; color: #888; }
        
        .menu-item.books .icon { background: #e3f2fd; }
        .menu-item.books:hover { border-color: #2196f3; background: #e3f2fd; }
        .menu-item.requests .icon { background: #e8f5e9; }
        .menu-item.requests:hover { border-color: #4caf50; background: #e8f5e9; }
        .menu-item.payments .icon { background: #e0f7fa; }
        .menu-item.payments:hover { border-color: #00bcd4; background: #e0f7fa; }
        .menu-item.manual .icon { background: #f3e5f5; }
        .menu-item.manual:hover { border-color: #9c27b0; background: #f3e5f5; }
        .menu-item.maintenance .icon { background: #fafafa; }
        .menu-item.maintenance:hover { border-color: #9e9e9e; background: #fafafa; }
        
        /* Login Form Styling */
        .login-container {
            max-width: 400px;
            margin: 60px auto;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .login-container h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 24px;
        }
        .login-container .form-group { margin-bottom: 20px; }
        .login-container label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .login-container input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        .login-container input:focus {
            outline: none;
            border-color: #667eea;
        }
        .login-container .login-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: opacity 0.3s;
        }
        .login-container .login-btn:hover { opacity: 0.9; }
        .login-container .error-msg {
            background: #ffebee;
            color: #c62828;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }
    </style>
</head>
<body>

<?php
$total_collected = floatval($dashboard_metrics['cash_collected'] ?? $dashboard_metrics['paid_revenue'] ?? 0);
$paid_to_lecturers = floatval($dashboard_metrics['lecturer_paid'] ?? 0);
$net_balance = floatval($dashboard_metrics['available_balance'] ?? ($total_collected - $paid_to_lecturers));
    $total_pending = intval($dashboard_metrics['unpaid_requests'] ?? 0);

    $semesters_result = $conn->query("SELECT semester_id, semester_name, is_active FROM semesters ORDER BY semester_id DESC");
    $active_semester_name = function_exists('book_system_get_active_semester_name')
        ? book_system_get_active_semester_name($conn)
        : '';

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

    <div class="dashboard-container">
        <div class="dashboard-header">
            <div>
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username']); ?></h1>
                <p class="subtitle"><?php echo $is_super_admin ? 'Super Admin' : 'Class Rep: ' . htmlspecialchars($current_admin_class ?: 'Unassigned'); ?><?php echo $active_semester_name ? ' &bull; ' . htmlspecialchars($active_semester_name) : ''; ?></p>
            </div>
            <div style="display:flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                <?php if ($is_super_admin): ?>
                <form method="POST" class="inline-header-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <select name="semester_id" onchange="this.form.submit()" style="padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.35); background: rgba(255,255,255,0.18); color: white; font-weight: 700;">
                        <?php if ($semesters_result): ?>
                            <?php while ($s = $semesters_result->fetch_assoc()): ?>
                                <option value="<?php echo intval($s['semester_id']); ?>" <?php echo intval($s['is_active']) === 1 ? 'selected' : ''; ?> style="color:#333;">
                                    <?php echo htmlspecialchars($s['semester_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                    <input type="hidden" name="set_active_semester" value="1">
                </form>
                <form method="POST" class="inline-header-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="text" name="semester_name" placeholder="New semester name" required style="padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.35); background: rgba(255,255,255,0.18); color: white; font-weight: 700; width: 180px;">
                    <button type="submit" name="create_semester" value="1" class="logout-btn" style="padding: 10px 14px;">Create</button>
                </form>
                <?php endif; ?>
                <form method="POST" class="inline-header-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="logout" value="1" class="logout-btn">Logout</button>
                </form>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="label">Cash Collected</div>
                <div class="value">GH&#8373; <?php echo number_format($total_collected, 2); ?></div>
            </div>
            <div class="stat-card blue">
                <div class="label">Paid to Lecturers</div>
                <div class="value">GH&#8373; <?php echo number_format($paid_to_lecturers, 2); ?></div>
            </div>
            <div class="stat-card yellow">
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
                <a href="manage_books.php" class="menu-item books">
                    <div class="icon">&#128218;</div>
                    <div class="text">
                        <h3>Manage Books</h3>
                        <p>Add, edit prices & availability</p>
                    </div>
                </a>
                <a href="view_request.php" class="menu-item requests">
                    <div class="icon">&#128228;</div>
                    <div class="text">
                        <h3>View Requests</h3>
                        <p>Student orders & payments</p>
                    </div>
                </a>
                <a href="lecturer_payments.php" class="menu-item payments">
                    <div class="icon">&#128176;</div>
                    <div class="text">
                        <h3>Lecturer Payments</h3>
                        <p>Track payments to lecturers</p>
                    </div>
                </a>
                <a href="admin_manual_order.php" class="menu-item manual">
                    <div class="icon">&#10133;</div>
                    <div class="text">
                        <h3>Manual Order</h3>
                        <p>Record cash payments</p>
                    </div>
                </a>
                <a href="maintenance.php" class="menu-item maintenance">
                    <div class="icon">&#9881;</div>
                    <div class="text">
                        <h3>Maintenance</h3>
                        <p>System reset options</p>
                    </div>
                </a>
                <a href="upload_class.php" class="menu-item" style="background: #e8f5e9;">
                    <div class="icon" style="background: #c8e6c9;">&#128203;</div>
                    <div class="text">
                        <h3>Upload Class</h3>
                        <p>Import your class roster</p>
                    </div>
                </a>
                <a href="my_profile.php" class="menu-item" style="background: #e1f5fe;">
                    <div class="icon" style="background: #b3e5fc;">&#128100;</div>
                    <div class="text">
                        <h3>My Profile</h3>
                        <p>Update payment details</p>
                    </div>
                </a>
                <a href="activity_log.php" class="menu-item" style="background: #f8fafc;">
                    <div class="icon" style="background: #e2e8f0;">&#128221;</div>
                    <div class="text">
                        <h3>Activity Log</h3>
                        <p>See who changed requests, payments, and approvals</p>
                    </div>
                </a>
                <?php if (!$is_super_admin): ?>
                <a href="generate_access_code.php" class="menu-item" style="background: #fce4ec;">
                    <div class="icon" style="background: #f8bbd9;">&#128274;</div>
                    <div class="text">
                        <h3>Access Code</h3>
                        <p>Control super admin access</p>
                    </div>
                </a>
                <?php endif; ?>
                <?php if ($is_super_admin): ?>
                <a href="manage_reps.php" class="menu-item" style="background: #fff3e0;">
                    <div class="icon" style="background: #ffe0b2;">&#128101;</div>
                    <div class="text">
                        <h3>Manage Reps</h3>
                        <p>Create & manage rep accounts</p>
                    </div>
                </a>
                <a href="manage_rep_signups.php" class="menu-item" style="background: #e8f5e9;">
                    <div class="icon" style="background: #c8e6c9;">&#9989;</div>
                    <div class="text">
                        <h3>Rep Signup Payments</h3>
                        <p>Confirm payment & approve reps</p>
                    </div>
                </a>
                <a href="manage_lecturers.php" class="menu-item" style="background: #fff7ed;">
                    <div class="icon" style="background: #fed7aa;">&#127891;</div>
                    <div class="text">
                        <h3>Manage Lecturers</h3>
                        <p>Create lecturer accounts and assign books</p>
                    </div>
                </a>
                <a href="view_rep_data.php" class="menu-item" style="background: #e3f2fd;">
                    <div class="icon" style="background: #bbdefb;">&#128065;</div>
                    <div class="text">
                        <h3>View Rep Data</h3>
                        <p>Access rep records with code</p>
                    </div>
                </a>
                <a href="admin_setup.php" class="menu-item" style="background: #eef2ff;">
                    <div class="icon" style="background: #c7d2fe;">&#128736;</div>
                    <div class="text">
                        <h3>System Setup</h3>
                        <p>Run one-time setup and migration tasks</p>
                    </div>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php include 'footer.php'; ?>

</body>
</html>


