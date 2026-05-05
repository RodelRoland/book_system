<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'trial_started_at', 'DATETIME NULL AFTER approved_at');
        book_system_setup_ensure_column($conn, 'admins', 'trial_expires_at', 'DATETIME NULL AFTER trial_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_active', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER trial_expires_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_started_at', 'DATETIME NULL AFTER subscription_active');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_expires_at', 'DATETIME NULL AFTER subscription_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
    }
}

// Redirect to login if not logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? '');

if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}

// Handle Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_workspace']) && !empty($access_context['is_workspace_mode'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: rep_dashboard.php?msg=csrf_invalid');
        exit;
    }
    unset($_SESSION['super_admin_rep_context_id']);
    unset($_SESSION['super_admin_data_scope']);
    header("Location: manage_reps.php?msg=workspace_closed");
    exit;
}

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
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_class = strval($access_context['effective_class_name'] ?? '');
$current_admin_name = strval($access_context['effective_full_name'] ?? $access_context['effective_username'] ?? 'Rep');
$viewing_workspace = !empty($access_context['is_workspace_mode']);
$profile_photo_path = '';
$profile_initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $current_admin_name), 0, 2));
if ($profile_initials === '') {
    $profile_initials = 'RP';
}
$profile_stmt = $conn->prepare("SELECT profile_photo_path FROM admins WHERE admin_id = ?");
if ($profile_stmt) {
    $profile_stmt->bind_param('i', $current_admin_id);
    $profile_stmt->execute();
    $profile_stmt->bind_result($profile_photo_path_result);
    if ($profile_stmt->fetch()) {
        $profile_photo_path = trim(strval($profile_photo_path_result ?? ''));
    }
    $profile_stmt->close();
}
$rep_access_status = ($session_role === 'rep' && function_exists('book_system_get_rep_access_status'))
    ? book_system_get_rep_access_status($conn, $current_admin_id)
    : [];

// Fetch rep's stats
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$total_collected = 0.0;
$paid_to_lecturers = 0.0;
$total_pending = 0;
$net_balance = 0.0;

$active_semester_name = isset($ACTIVE_SEMESTER_NAME) ? strval($ACTIVE_SEMESTER_NAME) : '';
$active_semester_label = isset($ACTIVE_SEMESTER_LABEL) ? strval($ACTIVE_SEMESTER_LABEL) : $active_semester_name;

// Get rep's unique order link
$rep_username = strval($access_context['effective_username'] ?? '');
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
} else {
    $net_balance = $total_collected - $paid_to_lecturers;
}

$today_date = date('Y-m-d');
$unseen_request_count = 0;
$today_request_count = 0;
$notification_stmt = $conn->prepare("SELECT
        SUM(CASE WHEN rep_viewed_at IS NULL THEN 1 ELSE 0 END) AS unseen_requests,
        SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS today_requests
    FROM requests
    WHERE semester_id = ? AND admin_id = ?");
if ($notification_stmt) {
    $notification_stmt->bind_param('sii', $today_date, $semester_id, $current_admin_id);
    $notification_stmt->execute();
    $notification_res = $notification_stmt->get_result();
    if ($notification_res && $notification_res->num_rows === 1) {
        $notification_row = $notification_res->fetch_assoc();
        $unseen_request_count = intval($notification_row['unseen_requests'] ?? 0);
        $today_request_count = intval($notification_row['today_requests'] ?? 0);
    }
    $notification_stmt->close();
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
        .header-top {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .header-avatar {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 3px solid rgba(255,255,255,0.32);
            background: rgba(255,255,255,0.16);
            box-shadow: 0 12px 28px rgba(0,0,0,0.18);
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 0.04em;
            color: #ffffff;
            background-size: cover;
            background-position: center;
        }
        .header-avatar.has-photo { color: transparent; }
        .header-copy { min-width: 0; }
        .dashboard-header h1 { font-size: 24px; font-weight: 600; }
        .dashboard-header .subtitle { opacity: 0.9; margin-top: 5px; font-size: 14px; }
        .header-actions {
            display: flex;
            justify-content: flex-end;
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
        
        .notification-link {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(255,255,255,0.18);
            color: white;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.28);
            font-size: 22px;
        }
        .notification-badge {
            position: absolute;
            top: -4px;
            right: -2px;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            border-radius: 999px;
            background: #dc2626;
            color: white;
            font-size: 11px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.18);
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
        .share-spotlight {
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(46, 125, 50, 0.16);
            margin-bottom: 20px;
            border: 1px solid rgba(67, 160, 71, 0.16);
            position: relative;
            overflow: hidden;
        }
        .share-spotlight::before {
            content: '';
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(67, 160, 71, 0.08);
            top: -80px;
            right: -40px;
        }
        .share-spotlight h2 {
            font-size: 18px;
            color: #14532d;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        .share-spotlight p {
            color: #3f3f46;
            font-size: 13px;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }
        .share-spotlight-code {
            display: block;
            padding: 12px 14px;
            border-radius: 12px;
            background: #0f172a;
            color: #f8fafc;
            font-family: Consolas, monospace;
            font-size: 12px;
            word-break: break-all;
            position: relative;
            z-index: 1;
        }
        .share-spotlight-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
            position: relative;
            z-index: 1;
        }
        .share-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
        }
        .share-action-btn.primary {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: white;
        }
        .share-action-btn.secondary {
            background: #e0f2fe;
            color: #075985;
        }
        .share-action-btn:hover { opacity: 0.94; }
        .trial-banner {
            background: linear-gradient(135deg, #fff8e1 0%, #fff3cd 100%);
            color: #7c4a03;
            border: 1px solid rgba(217, 119, 6, 0.22);
            border-radius: 18px;
            padding: 16px 18px;
            box-shadow: 0 8px 20px rgba(217, 119, 6, 0.10);
            margin-bottom: 18px;
        }
        .trial-banner strong {
            display: block;
            font-size: 15px;
            margin-bottom: 4px;
        }
        .trial-banner span {
            font-size: 13px;
            line-height: 1.6;
        }
        
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
        
        @media (max-width: 640px) {
            .header-top { align-items: flex-start; }
            .header-avatar {
                width: 70px;
                height: 70px;
                font-size: 22px;
            }
            .share-action-btn { width: 100%; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <div class="dashboard-header">
        <div class="header-top">
            <div
                class="header-avatar<?php echo $profile_photo_path !== '' ? ' has-photo' : ''; ?>"
                <?php if ($profile_photo_path !== ''): ?>
                    style="background-image:url('<?php echo htmlspecialchars($profile_photo_path, ENT_QUOTES); ?>');"
                <?php endif; ?>
            ><?php echo htmlspecialchars($profile_initials); ?></div>
            <div class="header-copy">
                <h1>Welcome, <?php echo htmlspecialchars($current_admin_name); ?></h1>
                <p class="subtitle">Class: <?php echo htmlspecialchars($current_admin_class ?: 'Class Representative'); ?><?php echo $active_semester_label ? ' &bull; ' . htmlspecialchars($active_semester_label) : ''; ?></p>
                <?php if ($viewing_workspace): ?>
                    <p class="subtitle" style="margin-top:8px; font-weight:600;">Super admin workspace view for this rep is active.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="header-actions">
            <a href="view_request.php?notification_view=1&request_day=<?php echo urlencode($today_date); ?>" class="notification-link" title="Today's requests">
                &#128276;
                <?php if ($today_request_count > 0): ?>
                    <span class="notification-badge"><?php echo $today_request_count > 99 ? '99+' : $today_request_count; ?></span>
                <?php endif; ?>
            </a>
            <?php if ($viewing_workspace): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="leave_workspace" value="1" class="logout-btn">Leave Workspace</button>
            </form>
            <?php endif; ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="logout" value="1" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>

    <?php if (!empty($rep_access_status['is_trial_expiring_soon'])): ?>
    <div class="trial-banner">
        <strong>Free trial ending soon</strong>
        <span>
            <?php echo htmlspecialchars(strval($rep_access_status['reminder_message'] ?? 'Your free trial will expire soon.')); ?>
            Please make arrangements to subscribe before access stops at 12:00 AM on
            <?php echo htmlspecialchars(date('M d, Y', strtotime(strval($rep_access_status['trial_expires_at'] ?? 'now')))); ?>.
        </span>
    </div>
    <?php endif; ?>

    <div class="share-spotlight">
        <h2>Share Your Class Request Link</h2>
        <p>Keep this link handy anytime your class needs to place requests. You can also download your full rep data as a manual backup.</p>
        <code class="share-spotlight-code" id="orderLink"><?php echo htmlspecialchars($public_order_link); ?></code>
        <div class="share-spotlight-actions">
            <button type="button" class="share-action-btn primary" onclick="copyLink()">&#128203; Copy Class Link</button>
            <a href="rep_export_data.php" class="share-action-btn secondary">&#128229; Download My Data</a>
        </div>
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
            <?php if (!$viewing_workspace): ?>
            <a href="generate_access_code.php" class="menu-item">
                <div class="icon">&#128274;</div>
                <div class="text">
                    <h3>Workspace Access</h3>
                    <p>Control super admin workspace sharing</p>
                </div>
            </a>
            <?php endif; ?>
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


