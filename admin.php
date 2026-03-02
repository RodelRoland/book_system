<?php
session_start();
require_once 'db.php';

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

// 1. Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

if (isset($_SESSION['admin_logged_in']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_active_semester'])) {
    $new_id = intval($_POST['semester_id']);
    if ($new_id > 0) {
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE semester_id = ?");
        $stmt->bind_param("i", $new_id);
        $stmt->execute();
    }
    header("Location: admin.php");
    exit;
}

if (isset($_SESSION['admin_logged_in']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_semester'])) {
    $name = trim($_POST['semester_name'] ?? '');
    if ($name !== '') {
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("INSERT INTO semesters (semester_name, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1");
        $stmt->bind_param("s", $name);
        $stmt->execute();
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
        // Fetch Total Collected Revenue (scoped by admin_id for reps, all for super_admin)
        $semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
        $admin_filter = $is_super_admin ? '' : "AND admin_id = $current_admin_id";
        
        $rev_q = "SELECT SUM(total_amount) AS total FROM requests WHERE payment_status = 'paid' AND semester_id = $semester_id $admin_filter";
        $rev_res = $conn->query($rev_q);
        $rev_data = $rev_res ? $rev_res->fetch_assoc() : [];
        $total_collected = $rev_data['total'] ?? 0;

        // Fetch Total Paid to Lecturers
        $lec_q = "SELECT SUM(amount_paid) AS total FROM lecturer_payments WHERE semester_id = $semester_id $admin_filter";
        $lec_res = $conn->query($lec_q);
        $lec_data = $lec_res ? $lec_res->fetch_assoc() : [];
        $paid_to_lecturers = $lec_data['total'] ?? 0;
        
        // Net Balance
        $net_balance = $total_collected - $paid_to_lecturers;
        $is_overpaid = floatval($net_balance) < 0;
        $display_balance = $is_overpaid ? abs(floatval($net_balance)) : floatval($net_balance);

        // Fetch Pending Count
        $pen_q = "SELECT COUNT(*) AS count FROM requests WHERE payment_status = 'unpaid' AND semester_id = $semester_id $admin_filter";
        $pen_res = $conn->query($pen_q);
        $pen_data = $pen_res ? $pen_res->fetch_assoc() : [];
        $total_pending = $pen_data['count'] ?? 0;

        $semesters_result = $conn->query("SELECT semester_id, semester_name, is_active FROM semesters ORDER BY semester_id DESC");
        $active_semester_name = '';
        if ($semesters_result) {
            $semesters_result->data_seek(0);
            while ($s = $semesters_result->fetch_assoc()) {
                if (intval($s['is_active']) === 1) {
                    $active_semester_name = $s['semester_name'];
                    break;
                }
            }
            $semesters_result->data_seek(0);
        }
    ?>

    <div class="dashboard-container">
        <div class="dashboard-header">
            <div>
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username']); ?></h1>
                <p class="subtitle"><?php echo $is_super_admin ? '👑 Super Admin' : '📋 ' . htmlspecialchars($current_admin_class ?: 'Class Rep'); ?><?php echo $active_semester_name ? ' • ' . htmlspecialchars($active_semester_name) : ''; ?></p>
            </div>
            <div style="display:flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                <form method="POST" style="margin: 0;">
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
                <form method="POST" style="margin: 0; display:flex; gap: 8px; align-items:center;">
                    <input type="text" name="semester_name" placeholder="New semester name" required style="padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.35); background: rgba(255,255,255,0.18); color: white; font-weight: 700; width: 180px;">
                    <button type="submit" name="create_semester" value="1" class="logout-btn" style="padding: 10px 14px;">Create</button>
                </form>
                <a href="?logout=1" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="label">Total Collected</div>
                <div class="value">GH₵ <?php echo number_format($total_collected, 2); ?></div>
            </div>
            <div class="stat-card blue">
                <div class="label">Paid to Lecturers</div>
                <div class="value">GH₵ <?php echo number_format($paid_to_lecturers, 2); ?></div>
            </div>
            <div class="stat-card yellow">
                <div class="label">Remaining Balance</div>
                <div class="value">GH₵ <?php echo number_format($net_balance, 2); ?></div>
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
                    <div class="icon">📚</div>
                    <div class="text">
                        <h3>Manage Books</h3>
                        <p>Add, edit prices & availability</p>
                    </div>
                </a>
                <a href="view_request.php" class="menu-item requests">
                    <div class="icon">📩</div>
                    <div class="text">
                        <h3>View Requests</h3>
                        <p>Student orders & payments</p>
                    </div>
                </a>
                <a href="lecturer_payments.php" class="menu-item payments">
                    <div class="icon">💰</div>
                    <div class="text">
                        <h3>Lecturer Payments</h3>
                        <p>Track payments to lecturers</p>
                    </div>
                </a>
                <a href="admin_manual_order.php" class="menu-item manual">
                    <div class="icon">➕</div>
                    <div class="text">
                        <h3>Manual Order</h3>
                        <p>Record cash payments</p>
                    </div>
                </a>
                <a href="maintenance.php" class="menu-item maintenance">
                    <div class="icon">⚙️</div>
                    <div class="text">
                        <h3>Maintenance</h3>
                        <p>System reset options</p>
                    </div>
                </a>
                <a href="upload_class.php" class="menu-item" style="background: #e8f5e9;">
                    <div class="icon" style="background: #c8e6c9;">📋</div>
                    <div class="text">
                        <h3>Upload Class</h3>
                        <p>Import your class roster</p>
                    </div>
                </a>
                <a href="my_profile.php" class="menu-item" style="background: #e1f5fe;">
                    <div class="icon" style="background: #b3e5fc;">👤</div>
                    <div class="text">
                        <h3>My Profile</h3>
                        <p>Update payment details</p>
                    </div>
                </a>
                <?php if (!$is_super_admin): ?>
                <a href="generate_access_code.php" class="menu-item" style="background: #fce4ec;">
                    <div class="icon" style="background: #f8bbd9;">🔐</div>
                    <div class="text">
                        <h3>Access Code</h3>
                        <p>Control super admin access</p>
                    </div>
                </a>
                <?php endif; ?>
                <?php if ($is_super_admin): ?>
                <a href="manage_reps.php" class="menu-item" style="background: #fff3e0;">
                    <div class="icon" style="background: #ffe0b2;">👥</div>
                    <div class="text">
                        <h3>Manage Reps</h3>
                        <p>Create & manage rep accounts</p>
                    </div>
                </a>
                <a href="manage_rep_signups.php" class="menu-item" style="background: #e8f5e9;">
                    <div class="icon" style="background: #c8e6c9;">✅</div>
                    <div class="text">
                        <h3>Rep Signup Payments</h3>
                        <p>Confirm payment & approve reps</p>
                    </div>
                </a>
                <a href="view_rep_data.php" class="menu-item" style="background: #e3f2fd;">
                    <div class="icon" style="background: #bbdefb;">👁️</div>
                    <div class="text">
                        <h3>View Rep Data</h3>
                        <p>Access rep records with code</p>
                    </div>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php include 'footer.php'; ?>

</body>
</html>