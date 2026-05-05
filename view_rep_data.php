<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

if (strval($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$csrf_token = csrf_get_token();
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enter_workspace'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $rep_id = intval($_POST['rep_id'] ?? 0);
        if ($rep_id > 0) {
            $stmt = $conn->prepare("SELECT admin_id, is_active, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access, full_name
                FROM admins
                WHERE admin_id = ? AND role = 'rep'
                LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $rep_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $rep = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
                $stmt->close();

                if ($rep && intval($rep['is_active'] ?? 0) === 1 && intval($rep['allow_super_admin_access'] ?? 0) === 1) {
                    $_SESSION['super_admin_rep_context_id'] = $rep_id;
                    $_SESSION['super_admin_data_scope'] = 'workspace';
                    header('Location: rep_dashboard.php');
                    exit;
                }
            }
        }
        $error_msg = 'This rep has not shared workspace access.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_workspace'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        unset($_SESSION['super_admin_rep_context_id']);
        unset($_SESSION['super_admin_data_scope']);
        $success_msg = 'Workspace access has been closed.';
    }
}

$current_workspace_id = intval($_SESSION['super_admin_rep_context_id'] ?? 0);
$current_workspace = null;
if ($current_workspace_id > 0) {
    $stmt = $conn->prepare("SELECT admin_id, full_name, class_name FROM admins WHERE admin_id = ? AND role = 'rep' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $current_workspace_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current_workspace = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

$summary = [
    'total_reps' => 0,
    'shared_reps' => 0,
    'private_reps' => 0,
    'active_reps' => 0,
];

$summary_result = $conn->query("SELECT
    COUNT(*) AS total_reps,
    SUM(CASE WHEN COALESCE(allow_super_admin_access, 0) = 1 THEN 1 ELSE 0 END) AS shared_reps,
    SUM(CASE WHEN COALESCE(allow_super_admin_access, 0) = 0 THEN 1 ELSE 0 END) AS private_reps,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_reps
    FROM admins
    WHERE role = 'rep'");
if ($summary_result) {
    $summary = array_merge($summary, $summary_result->fetch_assoc() ?: []);
}

$reps_sql = "
    SELECT
        a.admin_id,
        a.username,
        a.full_name,
        a.class_name,
        a.is_active,
        COALESCE(a.allow_super_admin_access, 0) AS allow_super_admin_access,
        COALESCE(rs.request_count, 0) AS request_count,
        COALESCE(rs.cash_collected, 0) AS total_collected,
        COALESCE(lps.paid_to_lecturers, 0) AS paid_to_lecturers
    FROM admins a
    LEFT JOIN (
        SELECT admin_id,
               COUNT(*) AS request_count,
               SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END) AS cash_collected
        FROM requests
        WHERE semester_id = $semester_id
        GROUP BY admin_id
    ) rs ON rs.admin_id = a.admin_id
    LEFT JOIN (
        SELECT admin_id, SUM(amount_paid) AS paid_to_lecturers
        FROM lecturer_payments
        WHERE semester_id = $semester_id
        GROUP BY admin_id
    ) lps ON lps.admin_id = a.admin_id
    WHERE a.role = 'rep'
    ORDER BY a.full_name ASC";
$reps_result = $conn->query($reps_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rep Workspace Access</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .header h1 { font-size: 24px; }
        .header .subtitle { opacity: 0.9; font-size: 14px; margin-top: 5px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .workspace-banner {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .workspace-banner strong { font-size: 18px; }
        .workspace-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .workspace-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            color: white;
            cursor: pointer;
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
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 18px;
            color: #111827;
            margin-bottom: 18px;
        }
        .helper {
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 20px;
        }
        .reps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }
        .rep-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }
        .rep-card.inactive { opacity: 0.7; }
        .rep-name {
            font-size: 17px;
            font-weight: 700;
            color: #111827;
        }
        .rep-class {
            color: #64748b;
            font-size: 13px;
            margin-top: 5px;
        }
        .status-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .chip.shared { background: #dcfce7; color: #166534; }
        .chip.private { background: #fee2e2; color: #991b1b; }
        .chip.active { background: #dbeafe; color: #1d4ed8; }
        .chip.inactive { background: #e5e7eb; color: #374151; }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 16px;
        }
        .stat-box {
            background: white;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            border: 1px solid #e5e7eb;
        }
        .stat-box .value {
            font-size: 18px;
            font-weight: 800;
            color: #111827;
        }
        .stat-box .label {
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            margin-top: 4px;
        }
        .rep-actions {
            margin-top: 16px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-muted {
            background: #e5e7eb;
            color: #374151;
        }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Rep Workspace Access</h1>
            <p class="subtitle">Open only the rep workspaces that have been shared with super admin.</p>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back to Dashboard</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if ($current_workspace): ?>
        <div class="workspace-banner">
            <div>
                <div>Current workspace</div>
                <strong><?php echo htmlspecialchars($current_workspace['full_name'] ?? ''); ?></strong>
                <?php if (!empty($current_workspace['class_name'])): ?>
                    <div><?php echo htmlspecialchars($current_workspace['class_name']); ?></div>
                <?php endif; ?>
            </div>
            <div class="workspace-actions">
                <a href="rep_dashboard.php" class="workspace-btn">Open Dashboard</a>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="leave_workspace" value="1" class="workspace-btn">Leave Workspace</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Total Reps</div>
            <div class="summary-value"><?php echo intval($summary['total_reps'] ?? 0); ?></div>
            <div class="summary-note">All rep accounts currently enrolled on the system.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Shared Workspaces</div>
            <div class="summary-value"><?php echo intval($summary['shared_reps'] ?? 0); ?></div>
            <div class="summary-note">Reps who have allowed super-admin workspace access.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Private Workspaces</div>
            <div class="summary-value"><?php echo intval($summary['private_reps'] ?? 0); ?></div>
            <div class="summary-note">Reps whose workspace data stays private to them.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Active Reps</div>
            <div class="summary-value"><?php echo intval($summary['active_reps'] ?? 0); ?></div>
            <div class="summary-note">Rep accounts currently able to log in and work.</div>
        </div>
    </div>

    <div class="card">
        <h2>Rep Access Overview</h2>
        <p class="helper">The super admin can always manage approvals, activation, and platform settings. Opening a rep workspace depends only on whether that rep has enabled sharing.</p>

        <?php if ($reps_result && $reps_result->num_rows > 0): ?>
        <div class="reps-grid">
            <?php while ($rep = $reps_result->fetch_assoc()): ?>
            <?php
                $is_shared = !empty($rep['allow_super_admin_access']);
                $is_active = !empty($rep['is_active']);
                $available_balance = floatval($rep['total_collected'] ?? 0) - floatval($rep['paid_to_lecturers'] ?? 0);
            ?>
            <div class="rep-card <?php echo $is_active ? '' : 'inactive'; ?>">
                <div class="rep-name"><?php echo htmlspecialchars($rep['full_name']); ?></div>
                <div class="rep-class"><?php echo htmlspecialchars($rep['class_name'] ?: 'No class assigned'); ?></div>

                <div class="status-row">
                    <span class="chip <?php echo $is_shared ? 'shared' : 'private'; ?>">
                        <?php echo $is_shared ? 'Shared' : 'Private'; ?>
                    </span>
                    <span class="chip <?php echo $is_active ? 'active' : 'inactive'; ?>">
                        <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>

                <div class="stats">
                    <div class="stat-box">
                        <div class="value"><?php echo intval($rep['request_count'] ?? 0); ?></div>
                        <div class="label">Requests</div>
                    </div>
                    <div class="stat-box">
                        <div class="value">GH&#8373;<?php echo number_format(floatval($rep['total_collected'] ?? 0), 0); ?></div>
                        <div class="label">Cash</div>
                    </div>
                    <div class="stat-box">
                        <div class="value">GH&#8373;<?php echo number_format($available_balance, 0); ?></div>
                        <div class="label">Balance</div>
                    </div>
                </div>

                <div class="rep-actions">
                    <?php if ($is_shared && $is_active): ?>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="rep_id" value="<?php echo intval($rep['admin_id']); ?>">
                        <button type="submit" name="enter_workspace" value="1" class="btn btn-primary">Open Workspace</button>
                    </form>
                    <?php else: ?>
                    <button type="button" class="btn btn-muted" disabled><?php echo $is_active ? 'Private Workspace' : 'Inactive Rep'; ?></button>
                    <?php endif; ?>
                    <a href="manage_reps.php" class="btn btn-muted" style="text-decoration:none;">Manage Rep</a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <p class="helper" style="margin-bottom:0;">No rep accounts are available yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
