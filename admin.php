<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
        book_system_setup_ensure_column($conn, 'semesters', 'semester_start_date', 'DATE NULL AFTER semester_name');
    }
}

if (isset($_SESSION['admin_logged_in']) && ($_SESSION['admin_role'] ?? '') === 'super_admin') {
    $_SESSION['super_admin_data_scope'] = 'own';
}

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
    $semester_start_date = trim(strval($_POST['semester_start_date'] ?? ''));
    $date_object = DateTime::createFromFormat('Y-m-d', $semester_start_date);
    if ($name !== '' && $date_object && $date_object->format('Y-m-d') === $semester_start_date) {
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("INSERT INTO semesters (semester_name, semester_start_date, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE semester_start_date = VALUES(semester_start_date), is_active = 1");
        $stmt->bind_param("ss", $name, $semester_start_date);
        $stmt->execute();
        $new_semester_id = intval($conn->insert_id);
        if ($new_semester_id <= 0) {
            $lookup = $conn->prepare("SELECT semester_id FROM semesters WHERE semester_name = ? LIMIT 1");
            if ($lookup) {
                $lookup->bind_param('s', $name);
                $lookup->execute();
                $lookup_result = $lookup->get_result();
                if ($lookup_result && $lookup_result->num_rows === 1) {
                    $new_semester_id = intval($lookup_result->fetch_assoc()['semester_id'] ?? 0);
                }
                $lookup->close();
            }
        }
        $carry_forward_summary = function_exists('book_system_run_balance_carry_forward')
            ? book_system_run_balance_carry_forward($conn, $new_semester_id)
            : ['carried_count' => 0, 'carried_total' => 0.0];
        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'create_semester', 'semester', $new_semester_id, [
                'semester_name' => $name,
                'semester_start_date' => $semester_start_date,
                'carried_balance_count' => intval($carry_forward_summary['carried_count'] ?? 0),
                'carried_balance_total' => floatval($carry_forward_summary['carried_total'] ?? 0),
            ]);
            if (floatval($carry_forward_summary['carried_total'] ?? 0) > 0) {
                book_system_audit_log($conn, 'carry_forward_balance', 'semester', $new_semester_id, [
                    'target_semester_id' => $new_semester_id,
                    'carried_balance_count' => intval($carry_forward_summary['carried_count'] ?? 0),
                    'carried_balance_total' => floatval($carry_forward_summary['carried_total'] ?? 0),
                    'source' => 'admin_dashboard',
                ]);
            }
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
            color: #333;
        }
        .dashboard-container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .dashboard-header h1 { font-size: 28px; font-weight: 600; }
        .dashboard-header .subtitle { opacity: 0.9; margin-top: 5px; font-size: 14px; }
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .header-form,
        .header-create-form {
            margin: 0;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .header-select,
        .header-input {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.35);
            background: rgba(255,255,255,0.18);
            color: white;
            font-weight: 700;
        }
        .header-select option { color: #333; }
        .header-input::placeholder { color: rgba(255,255,255,0.9); }
        .header-input { width: 180px; }
        .header-input.date { width: 165px; }
        .header-btn,
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
            cursor: pointer;
        }
        .header-btn:hover,
        .logout-btn:hover { background: rgba(255,255,255,0.3); }
        .logout-form { margin: 0; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
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

        .menu-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 26px;
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
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-right: 15px;
            flex-shrink: 0;
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

        .activity-list {
            display: grid;
            gap: 12px;
        }
        .activity-item {
            padding: 16px;
            border-radius: 12px;
            background: #f8f9fa;
            border: 1px solid #eceff1;
        }
        .activity-item .title {
            font-weight: 700;
            color: #333;
            margin-bottom: 4px;
            font-size: 14px;
        }
        .activity-item .meta {
            color: #777;
            font-size: 12px;
            line-height: 1.5;
        }
        .activity-item .details {
            color: #555;
            font-size: 13px;
            margin-top: 7px;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }
        .empty-note {
            color: #777;
            font-size: 14px;
            background: #f8f9fa;
            border: 1px solid #eceff1;
            border-radius: 10px;
            padding: 16px;
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 700px) {
            .menu-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 540px) {
            body { padding: 18px 14px; }
            .stats-grid { grid-template-columns: 1fr; }
            .dashboard-header { padding: 24px 20px; }
            .header-input,
            .header-input.date,
            .header-select { width: 100%; }
            .header-form,
            .header-create-form,
            .header-actions { width: 100%; }
        }
    </style>
</head>
<body>

<?php
$total_collected = floatval($dashboard_metrics['cash_collected'] ?? $dashboard_metrics['paid_revenue'] ?? 0);
$paid_to_lecturers = floatval($dashboard_metrics['lecturer_paid'] ?? 0);
$net_balance = floatval($dashboard_metrics['available_balance'] ?? ($total_collected - $paid_to_lecturers));
$total_pending = intval($dashboard_metrics['unpaid_requests'] ?? 0);

$semesters_result = $conn->query("SELECT semester_id, semester_name, semester_start_date, is_active FROM semesters ORDER BY semester_id DESC");
$active_semester_name = isset($ACTIVE_SEMESTER_NAME) ? strval($ACTIVE_SEMESTER_NAME) : '';
$active_semester_label = isset($ACTIVE_SEMESTER_LABEL) ? strval($ACTIVE_SEMESTER_LABEL) : $active_semester_name;

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
                <p class="subtitle">
                    <?php echo $is_super_admin ? '&#128100; Super Admin' : '&#128203; ' . htmlspecialchars($current_admin_class ?: 'Class Rep'); ?>
                    <?php echo $active_semester_label ? ' &bull; ' . htmlspecialchars($active_semester_label) : ''; ?>
                </p>
            </div>
            <div class="header-actions">
                <form method="POST" class="header-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="set_active_semester" value="1">
                    <select name="semester_id" onchange="this.form.submit()" class="header-select">
                        <?php if ($semesters_result): ?>
                            <?php while ($s = $semesters_result->fetch_assoc()): ?>
                                <option value="<?php echo intval($s['semester_id']); ?>" <?php echo intval($s['is_active']) === 1 ? 'selected' : ''; ?>>
                                    <?php
                                        $semesterOptionLabel = function_exists('book_system_build_semester_label')
                                            ? book_system_build_semester_label(strval($s['semester_name'] ?? ''), strval($s['semester_start_date'] ?? ''))
                                            : strval($s['semester_name'] ?? '');
                                        echo htmlspecialchars($semesterOptionLabel);
                                    ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </form>
                <form method="POST" class="header-create-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="text" name="semester_name" placeholder="New semester name" required class="header-input">
                    <input type="date" name="semester_start_date" required class="header-input date">
                    <button type="submit" name="create_semester" value="1" class="header-btn">Create</button>
                </form>
                <form method="POST" class="logout-form">
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
                        <h3>Workspace Access</h3>
                        <p>Control super admin workspace sharing</p>
                    </div>
                </a>
                <?php endif; ?>
                <?php if ($is_super_admin): ?>
                <a href="super_admin_home.php" class="menu-item" style="background: #eef2ff;">
                    <div class="icon" style="background: #c7d2fe;">&#127969;</div>
                    <div class="text">
                        <h3>Records Home</h3>
                        <p>Open or start a semester record</p>
                    </div>
                </a>
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
                <a href="manage_ads.php" class="menu-item" style="background: #f5f3ff;">
                    <div class="icon" style="background: #ddd6fe;">&#128227;</div>
                    <div class="text">
                        <h3>Manage Portal Ads</h3>
                        <p>Create adverts for the common request portal</p>
                    </div>
                </a>
                <a href="view_rep_data.php" class="menu-item" style="background: #e3f2fd;">
                    <div class="icon" style="background: #bbdefb;">&#128065;</div>
                    <div class="text">
                        <h3>Rep Workspaces</h3>
                        <p>Open shared rep workspaces</p>
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



