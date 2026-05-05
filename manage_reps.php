<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'academic_level', 'VARCHAR(10) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'program_name', 'VARCHAR(100) NULL AFTER academic_level');
        book_system_setup_ensure_column($conn, 'admins', 'show_on_public_portal', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER program_name');
        book_system_setup_ensure_column($conn, 'admins', 'allow_super_admin_access', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_public_portal');
        book_system_setup_ensure_column($conn, 'admins', 'trial_started_at', 'DATETIME NULL AFTER approved_at');
        book_system_setup_ensure_column($conn, 'admins', 'trial_expires_at', 'DATETIME NULL AFTER trial_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_active', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER trial_expires_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_started_at', 'DATETIME NULL AFTER subscription_active');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_expires_at', 'DATETIME NULL AFTER subscription_started_at');
    }
}

// Only super_admin can access this page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_role'] !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';

$csrf_token = csrf_get_token();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_rep') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $class_name = trim($_POST['class_name'] ?? '');
        $academic_level = preg_replace('/[^0-9]/', '', strval($_POST['academic_level'] ?? ''));
        $program_name = trim($_POST['program_name'] ?? '');
        $show_on_public_portal = isset($_POST['show_on_public_portal']) ? 1 : 0;
        $allow_super_admin_access = isset($_POST['allow_super_admin_access']) ? 1 : 0;
        $momo_number = trim($_POST['momo_number'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        
        if (empty($username) || empty($password) || empty($full_name)) {
            $error_msg = "Username, password, and full name are required.";
        } elseif (strlen($password) < 6) {
            $error_msg = "Password must be at least 6 characters.";
        } else {
            // Check if username exists
            $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ?");
            $check->bind_param("s", $username);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error_msg = "Username '$username' already exists.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO admins (username, password_hash, full_name, class_name, academic_level, program_name, show_on_public_portal, allow_super_admin_access, momo_number, account_number, account_name, bank_name, role, approved_at, trial_started_at, trial_expires_at, subscription_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'rep', NOW(), NOW(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 0)");
                $stmt->bind_param("ssssssiissss", $username, $password_hash, $full_name, $class_name, $academic_level, $program_name, $show_on_public_portal, $allow_super_admin_access, $momo_number, $account_number, $account_name, $bank_name);
                if ($stmt->execute()) {
                    $success_msg = "Rep account '$username' created successfully!";
                } else {
                    $error_msg = "Failed to create account: " . $conn->error;
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        if ($admin_id > 0) {
            $stmt = $conn->prepare("UPDATE admins SET is_active = NOT is_active WHERE admin_id = ? AND role = 'rep'");
            if ($stmt) {
                $stmt->bind_param('i', $admin_id);
                $stmt->execute();
            }
            if (intval($_SESSION['super_admin_rep_context_id'] ?? 0) === $admin_id) {
                unset($_SESSION['super_admin_rep_context_id']);
            }
            $success_msg = "Account status updated.";
        }
    } elseif ($action === 'enter_workspace') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        $stmt = $conn->prepare("SELECT admin_id, is_active, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access FROM admins WHERE admin_id = ? AND role = 'rep' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $rep_row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($rep_row && intval($rep_row['is_active'] ?? 0) === 1 && intval($rep_row['allow_super_admin_access'] ?? 0) === 1) {
                $_SESSION['super_admin_rep_context_id'] = $admin_id;
                $_SESSION['super_admin_data_scope'] = 'workspace';
                header('Location: rep_dashboard.php');
                exit;
            }
        }
        $error_msg = 'This rep has not granted workspace access.';
    } elseif ($action === 'clear_workspace') {
        unset($_SESSION['super_admin_rep_context_id']);
        unset($_SESSION['super_admin_data_scope']);
        $success_msg = 'Rep workspace access cleared.';
    } elseif ($action === 'reset_password') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';
        if ($admin_id > 0 && strlen($new_password) >= 6) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ? AND role = 'rep'");
            $stmt->bind_param("si", $password_hash, $admin_id);
            $stmt->execute();
            $success_msg = "Password reset successfully.";
        } else {
            $error_msg = "Password must be at least 6 characters.";
        }
    } elseif ($action === 'update_rep') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $class_name = trim($_POST['class_name'] ?? '');
        $academic_level = preg_replace('/[^0-9]/', '', strval($_POST['academic_level'] ?? ''));
        $program_name = trim($_POST['program_name'] ?? '');
        $show_on_public_portal = isset($_POST['show_on_public_portal']) ? 1 : 0;
        $allow_super_admin_access = isset($_POST['allow_super_admin_access']) ? 1 : 0;
        $momo_number = trim($_POST['momo_number'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        if ($admin_id > 0 && !empty($full_name)) {
            $stmt = $conn->prepare("UPDATE admins SET full_name = ?, class_name = ?, academic_level = ?, program_name = ?, show_on_public_portal = ?, allow_super_admin_access = ?, momo_number = ?, account_number = ?, account_name = ?, bank_name = ? WHERE admin_id = ? AND role = 'rep'");
            $stmt->bind_param("ssssiissssi", $full_name, $class_name, $academic_level, $program_name, $show_on_public_portal, $allow_super_admin_access, $momo_number, $account_number, $account_name, $bank_name, $admin_id);
            $stmt->execute();
            if (!$allow_super_admin_access && intval($_SESSION['super_admin_rep_context_id'] ?? 0) === $admin_id) {
                unset($_SESSION['super_admin_rep_context_id']);
            }
            $success_msg = "Rep details updated.";
        }
    } elseif ($action === 'toggle_subscription') {
        $admin_id = intval($_POST['admin_id'] ?? 0);
        $subscription_mode = strval($_POST['subscription_mode'] ?? '');
        if ($admin_id > 0) {
            if ($subscription_mode === 'activate') {
                $stmt = $conn->prepare("UPDATE admins
                    SET subscription_active = 1,
                        subscription_started_at = NOW(),
                        subscription_expires_at = NULL
                    WHERE admin_id = ? AND role = 'rep'");
                if ($stmt) {
                    $stmt->bind_param('i', $admin_id);
                    $stmt->execute();
                    $stmt->close();
                }
                $success_msg = 'Rep subscription activated.';
            } elseif ($subscription_mode === 'deactivate') {
                $stmt = $conn->prepare("UPDATE admins
                    SET subscription_active = 0,
                        subscription_expires_at = NOW()
                    WHERE admin_id = ? AND role = 'rep'");
                if ($stmt) {
                    $stmt->bind_param('i', $admin_id);
                    $stmt->execute();
                    $stmt->close();
                }
                $success_msg = 'Rep subscription ended.';
            }
        }
    }
    }
}

// Fetch all reps
$reps = $conn->query("SELECT * FROM admins WHERE role = 'rep' ORDER BY created_at DESC");
$rep_summary = [
    'total_reps' => 0,
    'active_reps' => 0,
    'portal_visible' => 0,
    'with_class' => 0,
];
$rep_summary_result = $conn->query("SELECT
    COUNT(*) AS total_reps,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_reps,
    SUM(CASE WHEN COALESCE(show_on_public_portal, 1) = 1 THEN 1 ELSE 0 END) AS portal_visible,
    SUM(CASE WHEN COALESCE(TRIM(class_name), '') <> '' THEN 1 ELSE 0 END) AS with_class
    FROM admins
    WHERE role = 'rep'");
if ($rep_summary_result) {
    $rep_summary = array_merge($rep_summary, $rep_summary_result->fetch_assoc() ?: []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reps</title>
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
            margin-bottom: 25px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #eef2f7;
        }
        .summary-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
        }
        .summary-value {
            color: #111827;
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 6px;
        }
        .summary-note {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-input:focus { outline: none; border-color: #667eea; }
        
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
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 8px 14px; font-size: 12px; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        
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
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        
        .action-btns { display: flex; gap: 8px; flex-wrap: wrap; }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            padding: 30px;
            border-radius: 16px;
            max-width: 450px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        .modal-close {
            float: right;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #888;
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 15px; }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>&#128101; Manage Class Reps</h1>
            <p class="subtitle">Create and manage rep accounts</p>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back to Dashboard</a>
    </div>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Total Reps</div>
            <div class="summary-value"><?php echo intval($rep_summary['total_reps'] ?? 0); ?></div>
            <div class="summary-note">All class rep accounts currently created in the system.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Active Reps</div>
            <div class="summary-value"><?php echo intval($rep_summary['active_reps'] ?? 0); ?></div>
            <div class="summary-note">Rep accounts that can currently log in and work.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Visible on Portal</div>
            <div class="summary-value"><?php echo intval($rep_summary['portal_visible'] ?? 0); ?></div>
            <div class="summary-note">Reps exposed on the shared public request portal.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Classes Configured</div>
            <div class="summary-value"><?php echo intval($rep_summary['with_class'] ?? 0); ?></div>
            <div class="summary-note">Rep accounts that already have a class name assigned.</div>
        </div>
    </div>

    <?php if (intval($_SESSION['super_admin_rep_context_id'] ?? 0) > 0): ?>
        <div class="card" style="padding:18px 20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <div style="font-weight:700; color:#1f2937;">You are currently inside a rep workspace context.</div>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="clear_workspace">
                    <button type="submit" class="btn btn-sm btn-warning">Leave Rep Workspace</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Create New Rep Form -->
    <div class="card">
        <h3 class="card-title">&#10133; Create New Rep Account</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="create_rep">
            <div class="form-row">
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" class="form-input" placeholder="e.g. john_rep" required>
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" class="form-input" placeholder="Min 6 characters" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" class="form-input" placeholder="e.g. John Doe" required>
                </div>
                <div class="form-group">
                    <label>Class Name</label>
                    <input type="text" name="class_name" class="form-input" placeholder="e.g. Level 200 CS">
                </div>
                <div class="form-group">
                    <label>Academic Level</label>
                    <input type="text" name="academic_level" class="form-input" placeholder="e.g. 200" maxlength="3">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" class="form-input" placeholder="e.g. Computer Science">
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <label style="display:flex; align-items:center; gap:10px; margin-bottom: 0;">
                        <input type="checkbox" name="show_on_public_portal" value="1" checked style="width:auto;">
                        Show this rep on the common request portal
                    </label>
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <label style="display:flex; align-items:center; gap:10px; margin-bottom: 0;">
                        <input type="checkbox" name="allow_super_admin_access" value="1" style="width:auto;">
                        Allow super admin access to this rep workspace
                    </label>
                </div>
                <div class="form-group">
                    <label>MoMo Number</label>
                    <input type="text" name="momo_number" class="form-input" placeholder="e.g. 0244123456">
                </div>
                <div class="form-group">
                    <label>Bank Name</label>
                    <input type="text" name="bank_name" class="form-input" placeholder="e.g. GCB Bank">
                </div>
                <div class="form-group">
                    <label>Account Name</label>
                    <input type="text" name="account_name" class="form-input" placeholder="e.g. John Doe">
                </div>
                <div class="form-group">
                    <label>Account Number</label>
                    <input type="text" name="account_number" class="form-input" placeholder="e.g. 1234567890">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Create Rep Account</button>
        </form>
    </div>
    
    <!-- Existing Reps Table -->
    <div class="card">
        <h3 class="card-title">&#128203; All Rep Accounts</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Class</th>
                        <th>Level</th>
                        <th>Program</th>
                        <th>Portal</th>
                        <th>Privacy</th>
                        <th>Status</th>
                        <th>Access Plan</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reps && $reps->num_rows > 0): ?>
                        <?php while ($rep = $reps->fetch_assoc()): ?>
                        <tr>
                            <?php $rep_access = function_exists('book_system_build_rep_access_status_from_row') ? book_system_build_rep_access_status_from_row($rep) : []; ?>
                            <td><strong><?php echo htmlspecialchars($rep['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($rep['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($rep['class_name'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($rep['academic_level'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($rep['program_name'] ?: '—'); ?></td>
                            <td><?php echo !empty($rep['show_on_public_portal']) ? 'Visible' : 'Hidden'; ?></td>
                            <td><?php echo !empty($rep['allow_super_admin_access']) ? 'Shared' : 'Private'; ?></td>
                            <td>
                                <span class="status-badge <?php echo $rep['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $rep['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($rep_access['is_subscription_active'])): ?>
                                    <span class="status-badge status-active">Subscribed</span>
                                <?php elseif (!empty($rep_access['is_expired'])): ?>
                                    <span class="status-badge status-inactive">Trial Expired</span>
                                <?php else: ?>
                                    <span class="status-badge" style="background:#fff3cd; color:#856404;">
                                        Trial <?php echo intval($rep_access['days_left'] ?? 0); ?> day<?php echo intval($rep_access['days_left'] ?? 0) === 1 ? '' : 's'; ?> left
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($rep['created_at'])); ?></td>
                            <td>
                                <div class="action-btns">
                                    <button type="button" class="btn btn-sm btn-warning" 
                                            onclick="openEditModal(<?php echo $rep['admin_id']; ?>, '<?php echo htmlspecialchars($rep['full_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rep['class_name'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rep['academic_level'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rep['program_name'] ?? '', ENT_QUOTES); ?>', <?php echo !empty($rep['show_on_public_portal']) ? 'true' : 'false'; ?>, <?php echo !empty($rep['allow_super_admin_access']) ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars($rep['momo_number'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rep['bank_name'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rep['account_name'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rep['account_number'] ?? '', ENT_QUOTES); ?>')">
                                        &#9998; Edit
                                    </button>
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick="openPasswordModal(<?php echo $rep['admin_id']; ?>, '<?php echo htmlspecialchars($rep['username'], ENT_QUOTES); ?>')">
                                        &#128273; Reset
                                    </button>
                                    <?php if (!empty($rep['is_active']) && !empty($rep['allow_super_admin_access'])): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="enter_workspace">
                                        <input type="hidden" name="admin_id" value="<?php echo $rep['admin_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            Open Workspace
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo $rep['is_active'] ? 'Deactivate' : 'Activate'; ?> this account?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="admin_id" value="<?php echo $rep['admin_id']; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $rep['is_active'] ? 'btn-danger' : 'btn-success'; ?>">
                                            <?php echo $rep['is_active'] ? '&#128683; Deactivate' : '&#9989; Activate'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo !empty($rep_access['is_subscription_active']) ? 'End' : 'Activate'; ?> this subscription?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="toggle_subscription">
                                        <input type="hidden" name="admin_id" value="<?php echo $rep['admin_id']; ?>">
                                        <input type="hidden" name="subscription_mode" value="<?php echo !empty($rep_access['is_subscription_active']) ? 'deactivate' : 'activate'; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo !empty($rep_access['is_subscription_active']) ? 'btn-danger' : 'btn-success'; ?>">
                                            <?php echo !empty($rep_access['is_subscription_active']) ? '&#9208; End Subscription' : '&#128176; Activate Subscription'; ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <div class="icon">&#128101;</div>
                                    <p>No rep accounts yet. Create one above!</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay">
    <div class="modal">
        <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
        <h3 class="modal-title">&#9998; Edit Rep Details</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="update_rep">
            <input type="hidden" name="admin_id" id="edit_admin_id">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" id="edit_full_name" class="form-input" required>
            </div>
            <div class="form-group">
                <label>Class Name</label>
                <input type="text" name="class_name" id="edit_class_name" class="form-input">
            </div>
            <div class="form-group">
                <label>Academic Level</label>
                <input type="text" name="academic_level" id="edit_academic_level" class="form-input" maxlength="3">
            </div>
            <div class="form-group">
                <label>Program Name</label>
                <input type="text" name="program_name" id="edit_program_name" class="form-input">
            </div>
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="show_on_public_portal" id="edit_show_on_public_portal" value="1" style="width:auto;">
                    Show this rep on the common request portal
                </label>
            </div>
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="allow_super_admin_access" id="edit_allow_super_admin_access" value="1" style="width:auto;">
                    Allow super admin access to this rep workspace
                </label>
            </div>
            <hr style="margin: 15px 0; border: none; border-top: 1px solid #eee;">
            <p style="font-weight: 600; color: #667eea; margin-bottom: 15px;">&#128179; Payment Details</p>
            <div class="form-group">
                <label>MoMo Number</label>
                <input type="text" name="momo_number" id="edit_momo_number" class="form-input" placeholder="e.g. 0244123456">
            </div>
            <div class="form-group">
                <label>Bank Name</label>
                <input type="text" name="bank_name" id="edit_bank_name" class="form-input" placeholder="e.g. GCB Bank">
            </div>
            <div class="form-group">
                <label>Account Name</label>
                <input type="text" name="account_name" id="edit_account_name" class="form-input" placeholder="e.g. John Doe">
            </div>
            <div class="form-group">
                <label>Account Number</label>
                <input type="text" name="account_number" id="edit_account_number" class="form-input" placeholder="e.g. 1234567890">
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>

<!-- Password Reset Modal -->
<div id="passwordModal" class="modal-overlay">
    <div class="modal">
        <button type="button" class="modal-close" onclick="closeModal('passwordModal')">&times;</button>
        <h3 class="modal-title">&#128273; Reset Password</h3>
        <p style="margin-bottom:15px; color:#666;" id="password_username_label"></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="admin_id" id="password_admin_id">
            <div class="form-group">
                <label>New Password *</label>
                <input type="password" name="new_password" class="form-input" required minlength="6" placeholder="Min 6 characters">
            </div>
            <button type="submit" class="btn btn-primary">Reset Password</button>
        </form>
    </div>
</div>

<script>
function openEditModal(adminId, fullName, className, academicLevel, programName, showOnPortal, allowSuperAdminAccess, momoNumber, bankName, accountName, accountNumber) {
    document.getElementById('edit_admin_id').value = adminId;
    document.getElementById('edit_full_name').value = fullName;
    document.getElementById('edit_class_name').value = className;
    document.getElementById('edit_academic_level').value = academicLevel || '';
    document.getElementById('edit_program_name').value = programName || '';
    document.getElementById('edit_show_on_public_portal').checked = !!showOnPortal;
    document.getElementById('edit_allow_super_admin_access').checked = !!allowSuperAdminAccess;
    document.getElementById('edit_momo_number').value = momoNumber || '';
    document.getElementById('edit_bank_name').value = bankName || '';
    document.getElementById('edit_account_name').value = accountName || '';
    document.getElementById('edit_account_number').value = accountNumber || '';
    document.getElementById('editModal').classList.add('active');
}

function openPasswordModal(adminId, username) {
    document.getElementById('password_admin_id').value = adminId;
    document.getElementById('password_username_label').textContent = 'Resetting password for: ' + username;
    document.getElementById('passwordModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Close modal on outside click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});

</script>

<?php include 'footer.php'; ?>

</body>
</html>

