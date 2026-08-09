<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

if (strval($_SESSION['admin_role'] ?? '') === 'super_admin') {
    header('Location: admin.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;

if (!$access_context || !empty($access_context['is_workspace_mode']) || !empty($access_context['is_own_rep_mode'])) {
    header('Location: rep_dashboard.php');
    exit;
}

$rep_id = intval($access_context['effective_admin_id'] ?? 0);
$rep_name = strval($access_context['effective_full_name'] ?? 'Rep');
$class_name = strval($access_context['effective_class_name'] ?? '');
$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();

$stmt = $conn->prepare("SELECT COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access FROM admins WHERE admin_id = ? LIMIT 1");
$stmt->bind_param('i', $rep_id);
$stmt->execute();
$result = $stmt->get_result();
$row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : ['allow_super_admin_access' => 0];
$allow_super_admin_access = intval($row['allow_super_admin_access'] ?? 0);
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access_setting'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $allow_super_admin_access = isset($_POST['allow_super_admin_access']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE admins SET allow_super_admin_access = ?, access_code = NULL, access_code_expires = NULL WHERE admin_id = ?");
        $stmt->bind_param('ii', $allow_super_admin_access, $rep_id);
        if ($stmt->execute()) {
            $success_msg = $allow_super_admin_access
                ? 'Super admin workspace sharing is now enabled for your rep account.'
                : 'Super admin workspace sharing has been turned off for your rep account.';
        } else {
            $error_msg = 'Unable to save your access preference right now.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workspace Access</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 760px; margin: 0 auto; }
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
        .header .subtitle { opacity: 0.92; font-size: 14px; margin-top: 5px; }
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
        .card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 18px;
        }
        .status-chip.shared { background: #dcfce7; color: #166534; }
        .status-chip.private { background: #fee2e2; color: #991b1b; }
        .title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        .description {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 24px;
        }
        .toggle-panel {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            background: #f8fafc;
            margin-bottom: 22px;
        }
        .toggle-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .toggle-label {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
        }
        .toggle-note {
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
            margin-top: 6px;
        }
        .checkbox-wrap {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: #111827;
        }
        .checkbox-wrap input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 22px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Workspace Access</h1>
            <p class="subtitle"><?php echo htmlspecialchars($rep_name); ?><?php echo $class_name !== '' ? ' • ' . htmlspecialchars($class_name) : ''; ?></p>
        </div>
        <a href="rep_dashboard.php" class="back-btn">&larr; Back to Dashboard</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="status-chip <?php echo $allow_super_admin_access ? 'shared' : 'private'; ?>">
            <?php echo $allow_super_admin_access ? 'Shared with Super Admin' : 'Private to Your Rep Workspace'; ?>
        </div>
        <div class="title">Control super admin access to your rep workspace</div>
        <p class="description">
            This setting decides whether the super admin can open your rep dashboard and operational pages from the rep management area.
            Platform management still stays with the super admin, but your rep data remains private unless you share it here.
        </p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="save_access_setting" value="1">

            <div class="toggle-panel">
                <div class="toggle-row">
                    <div>
                        <div class="toggle-label">Allow super admin to open my rep workspace</div>
                        <div class="toggle-note">Turn this on only when you want the super admin to step into your workspace and view your rep-side records.</div>
                    </div>
                    <label class="checkbox-wrap">
                        <input type="checkbox" name="allow_super_admin_access" value="1" <?php echo $allow_super_admin_access ? 'checked' : ''; ?>>
                        Enable sharing
                    </label>
                </div>
            </div>

            <button type="submit" class="btn">Save Access Preference</button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
