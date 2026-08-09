<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$role = strval($_SESSION['admin_role'] ?? '');
if (!in_array($role, ['super_admin', 'temporary_admin'], true)) {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();
$admin_id = intval($_SESSION['admin_id'] ?? 0);
$dashboard_url = 'admin.php';
if ($role === 'temporary_admin') {
    $tempPermissions = $_SESSION['temp_admin_permissions'] ?? [];
    if (function_exists('book_system_temp_admin_has_rep_workspace_access') && is_array($tempPermissions) && book_system_temp_admin_has_rep_workspace_access($tempPermissions)) {
        $dashboard_url = 'my_profile.php';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $current_password = strval($_POST['current_password'] ?? '');
        $new_password = strval($_POST['new_password'] ?? '');
        $confirm_password = strval($_POST['confirm_password'] ?? '');

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $error_msg = 'All password fields are required.';
        } elseif (strlen($new_password) < 6) {
            $error_msg = 'New password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $error_msg = 'New password and confirmation do not match.';
        } else {
            $stmt = $conn->prepare("SELECT password_hash FROM admins WHERE admin_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $admin_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$row || !password_verify($current_password, strval($row['password_hash'] ?? ''))) {
                    $error_msg = 'Your current password is incorrect.';
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ? LIMIT 1");
                    if ($update) {
                        $update->bind_param('si', $new_hash, $admin_id);
                        if ($update->execute()) {
                            $success_msg = 'Password changed successfully.';
                            if (function_exists('book_system_audit_log')) {
                                book_system_audit_log($conn, 'change_admin_password', 'admin', $admin_id, [
                                    'role' => $role,
                                ]);
                            }
                        } else {
                            $error_msg = 'Could not update the password.';
                        }
                        $update->close();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%); min-height: 100vh; padding: 30px 20px; }
        .container { max-width: 760px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 26px 30px; border-radius: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 16px; box-shadow: 0 10px 30px rgba(102,126,234,0.3); }
        .header h1 { font-size: 24px; font-weight: 700; }
        .header .subtitle { opacity: .92; margin-top: 6px; font-size: 14px; }
        .back-btn { background: rgba(255,255,255,0.18); color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; border: 1px solid rgba(255,255,255,0.3); }
        .card { background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 700; color: #475569; font-size: 14px; }
        .form-input { width: 100%; padding: 13px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
        .form-input:focus { outline: none; border-color: #667eea; }
        .password-field { position: relative; }
        .password-field .form-input { padding-right: 100px; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #667eea;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 8px;
        }
        .password-toggle:hover { background: rgba(102, 126, 234, 0.08); }
        .btn { border: none; border-radius: 10px; padding: 12px 18px; font-weight: 700; cursor: pointer; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Change Password</h1>
            <p class="subtitle">Update your admin password securely.</p>
        </div>
        <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="back-btn">&larr; Back</a>
    </div>

    <div class="card">
        <?php if ($success_msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
        <?php if ($error_msg): ?><div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label>Current Password</label>
                <div class="password-field">
                    <input type="password" name="current_password" class="form-input" required data-password-input>
                    <button type="button" class="password-toggle" data-password-toggle>Show</button>
                </div>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <div class="password-field">
                    <input type="password" name="new_password" class="form-input" required minlength="6" data-password-input>
                    <button type="button" class="password-toggle" data-password-toggle>Show</button>
                </div>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <div class="password-field">
                    <input type="password" name="confirm_password" class="form-input" required minlength="6" data-password-input>
                    <button type="button" class="password-toggle" data-password-toggle>Show</button>
                </div>
            </div>
            <button type="submit" class="btn">Change Password</button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-password-toggle]').forEach(function(toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            var wrapper = toggleBtn.closest('.password-field');
            var passwordInput = wrapper ? wrapper.querySelector('[data-password-input]') : null;
            if (!passwordInput) {
                return;
            }
            var showing = passwordInput.type === 'text';
            passwordInput.type = showing ? 'password' : 'text';
            toggleBtn.textContent = showing ? 'Show' : 'Hide';
        });
    });
});
</script>
</body>
</html>
