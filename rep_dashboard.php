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
if (isset($_GET['logout'])) {
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

$rev_q = "SELECT SUM(total_amount) AS total FROM requests WHERE payment_status = 'paid' AND semester_id = $semester_id AND admin_id = $current_admin_id";
$rev_res = $conn->query($rev_q);
$rev_data = $rev_res->fetch_assoc();
$total_collected = $rev_data['total'] ?? 0;

$lec_q = "SELECT SUM(amount_paid) AS total FROM lecturer_payments WHERE semester_id = $semester_id AND admin_id = $current_admin_id";
$lec_res = $conn->query($lec_q);
$lec_data = $lec_res->fetch_assoc();
$paid_to_lecturers = $lec_data['total'] ?? 0;

$net_balance = $total_collected - $paid_to_lecturers;

$pen_q = "SELECT COUNT(*) AS count FROM requests WHERE payment_status = 'unpaid' AND semester_id = $semester_id AND admin_id = $current_admin_id";
$pen_res = $conn->query($pen_q);
$pen_data = $pen_res->fetch_assoc();
$total_pending = $pen_data['count'] ?? 0;

// Get active semester name
$sem_res = $conn->query("SELECT semester_name FROM semesters WHERE is_active = 1 LIMIT 1");
$active_semester_name = ($sem_res && $sem_res->num_rows > 0) ? $sem_res->fetch_assoc()['semester_name'] : '';

// Get rep's unique order link
$rep_username = $_SESSION['admin_username'] ?? '';
$order_link = "index.php?rep=" . urlencode($rep_username);
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
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-right: 12px;
            background: #e8f5e9;
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
        <h1>👋 Welcome, <?php echo htmlspecialchars($current_admin_name); ?></h1>
        <p class="subtitle">📋 <?php echo htmlspecialchars($current_admin_class ?: 'Class Representative'); ?><?php echo $active_semester_name ? ' • ' . htmlspecialchars($active_semester_name) : ''; ?></p>
        
        <div class="header-actions">
            <div class="order-link-box">
                <span>📎 Your Order Link:</span>
                <code id="orderLink"><?php echo htmlspecialchars($order_link); ?></code>
                <button class="copy-btn" onclick="copyLink()">Copy</button>
            </div>
            <a href="?logout=1" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="welcome-note">
        <strong>Tip:</strong> Share your order link with students in your class. When they order through your link, their payments will appear in your dashboard.
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">💰 Total Collected</div>
            <div class="value">GH₵ <?php echo number_format($total_collected, 2); ?></div>
        </div>
        <div class="stat-card blue">
            <div class="label">📤 Paid to Lecturers</div>
            <div class="value">GH₵ <?php echo number_format($paid_to_lecturers, 2); ?></div>
        </div>
        <div class="stat-card orange">
            <div class="label">💵 Your Balance</div>
            <div class="value">GH₵ <?php echo number_format($net_balance, 2); ?></div>
        </div>
        <div class="stat-card red">
            <div class="label">⏳ Unpaid Requests</div>
            <div class="value"><?php echo $total_pending; ?></div>
        </div>
    </div>
    
    <div class="menu-section">
        <h2>Quick Actions</h2>
        <div class="menu-grid">
            <a href="view_request.php" class="menu-item">
                <div class="icon">📩</div>
                <div class="text">
                    <h3>View Requests</h3>
                    <p>Student orders & payments</p>
                </div>
            </a>
            <a href="manage_books.php" class="menu-item">
                <div class="icon">📚</div>
                <div class="text">
                    <h3>Manage Books</h3>
                    <p>Add, edit prices & availability</p>
                </div>
            </a>
            <a href="lecturer_payments.php" class="menu-item">
                <div class="icon">💰</div>
                <div class="text">
                    <h3>Lecturer Payments</h3>
                    <p>Track payments to lecturers</p>
                </div>
            </a>
            <a href="admin_manual_order.php" class="menu-item">
                <div class="icon">➕</div>
                <div class="text">
                    <h3>Manual Order</h3>
                    <p>Record cash payments</p>
                </div>
            </a>
            <a href="upload_class.php" class="menu-item">
                <div class="icon">📋</div>
                <div class="text">
                    <h3>Upload Class</h3>
                    <p>Import your class roster</p>
                </div>
            </a>
            <a href="my_profile.php" class="menu-item">
                <div class="icon">👤</div>
                <div class="text">
                    <h3>My Profile</h3>
                    <p>Update payment details</p>
                </div>
            </a>
            <a href="generate_access_code.php" class="menu-item">
                <div class="icon">🔐</div>
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
    const link = window.location.origin + window.location.pathname.replace('rep_dashboard.php', '') + document.getElementById('orderLink').textContent;
    navigator.clipboard.writeText(link).then(() => {
        alert('Order link copied to clipboard!');
    }).catch(() => {
        // Fallback
        const text = document.getElementById('orderLink').textContent;
        prompt('Copy this link:', window.location.origin + window.location.pathname.replace('rep_dashboard.php', '') + text);
    });
}
</script>

<?php include 'footer.php'; ?>

</body>
</html>
