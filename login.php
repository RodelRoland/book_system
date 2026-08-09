<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
error_reporting(0); // Suppress errors on login page to prevent HTML breakage
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'trial_started_at', 'DATETIME NULL AFTER approved_at');
        book_system_setup_ensure_column($conn, 'admins', 'trial_expires_at', 'DATETIME NULL AFTER trial_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_active', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER trial_expires_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_started_at', 'DATETIME NULL AFTER subscription_active');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_expires_at', 'DATETIME NULL AFTER subscription_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'temp_admin_permissions', 'TEXT NULL AFTER profile_photo_path');
        book_system_setup_ensure_column($conn, 'admins', 'temp_admin_expires_at', 'DATETIME NULL AFTER temp_admin_permissions');
        book_system_setup_ensure_column($conn, 'admins', 'delegated_by_admin_id', 'INT NULL AFTER temp_admin_expires_at');
    }
    if (function_exists('book_system_setup_ensure_admin_role_support')) {
        book_system_setup_ensure_admin_role_support($conn);
    }
}

// Redirect if already logged in
if (isset($_SESSION['admin_logged_in'])) {
    if (($_SESSION['admin_role'] ?? '') === 'super_admin') {
        header('Location: super_admin_home.php');
    } elseif (($_SESSION['admin_role'] ?? '') === 'temporary_admin') {
        $temporaryAdminStatus = function_exists('book_system_get_temporary_admin_status')
            ? book_system_get_temporary_admin_status($conn, intval($_SESSION['admin_id'] ?? 0))
            : ['can_access' => false, 'permissions' => []];
        $tempPermissions = is_array($temporaryAdminStatus['permissions'] ?? null)
            ? $temporaryAdminStatus['permissions']
            : ($_SESSION['temp_admin_permissions'] ?? []);
        if (!empty($temporaryAdminStatus['can_access']) && function_exists('book_system_temp_admin_has_rep_workspace_access') && is_array($tempPermissions) && book_system_temp_admin_has_rep_workspace_access($tempPermissions)) {
            $_SESSION['temp_admin_permissions'] = $tempPermissions;
            $_SESSION['temp_admin_expires_at'] = strval($temporaryAdminStatus['expires_at'] ?? '');
            header('Location: rep_dashboard.php');
        } else {
            session_destroy();
            header('Location: login.php?msg=temp_admin_expired');
        }
    } elseif (($_SESSION['admin_role'] ?? '') === 'rep') {
        $pendingStatus = function_exists('book_system_get_pending_rep_gate_status')
            ? book_system_get_pending_rep_gate_status($conn)
            : null;
        if (is_array($pendingStatus) && strval($pendingStatus['state'] ?? '') !== 'approved') {
            header('Location: pending_approval.php');
        } elseif (!book_system_rep_has_books($conn, intval($_SESSION['admin_id'] ?? 0))) {
            header('Location: manage_books.php?msg=book_setup_required');
        } else {
            header('Location: rep_dashboard.php');
        }
    } else {
        header('Location: admin.php');
    }
    exit;
}

$error = '';
$csrf_token = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (book_system_is_login_rate_limited($conn, 'admin', $username)) {
            $error = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
        
            try {
                // Authenticate against admins table
                $trialColumnsAvailable = function_exists('book_system_admin_trial_columns_available')
                    ? book_system_admin_trial_columns_available($conn)
                    : false;
                $trialSelect = $trialColumnsAvailable
                    ? ", trial_started_at, trial_expires_at, subscription_active, subscription_started_at, subscription_expires_at, approved_at, created_at"
                    : ", approved_at, created_at";
                $stmt = $conn->prepare("SELECT admin_id, username, password_hash, full_name, class_name, role, is_active, temp_admin_permissions, temp_admin_expires_at{$trialSelect} FROM admins WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result && $result->num_rows === 1) {
                        $admin = $result->fetch_assoc();

                        if (password_verify($password, $admin['password_hash'])) {
                            book_system_secure_session_regenerate(true);
                            book_system_record_login_attempt($conn, 'admin', $username, true);

                            if ($admin['role'] === 'rep' && intval($admin['is_active'] ?? 0) !== 1) {
                                if (function_exists('book_system_start_restricted_rep_session_from_admin')) {
                                    book_system_start_restricted_rep_session_from_admin($admin, 'inactive');
                                }
                                header('Location: pending_approval.php');
                                exit;
                            }

                            $_SESSION['admin_logged_in'] = true;
                            $_SESSION['admin_id'] = $admin['admin_id'];
                            $_SESSION['admin_username'] = $admin['username'];
                            $_SESSION['admin_full_name'] = $admin['full_name'];
                            $_SESSION['admin_class_name'] = $admin['class_name'];
                            $_SESSION['admin_role'] = $admin['role'];
                            $_SESSION['temp_admin_permissions'] = [];
                            $_SESSION['temp_admin_expires_at'] = '';
                            if ($admin['role'] === 'temporary_admin') {
                                $temporaryAdminStatus = function_exists('book_system_get_temporary_admin_status')
                                    ? book_system_get_temporary_admin_status($conn, intval($admin['admin_id'] ?? 0))
                                    : ['can_access' => false];
                                if (empty($temporaryAdminStatus['can_access'])) {
                                    session_unset();
                                    $error = 'Your temporary admin access has expired or is no longer active.';
                                } else {
                                    $_SESSION['temp_admin_permissions'] = $temporaryAdminStatus['permissions'] ?? [];
                                    $_SESSION['temp_admin_expires_at'] = $temporaryAdminStatus['expires_at'] ?? '';
                                }
                            }
                            if ($error === '') {
                                if ($admin['role'] === 'super_admin') {
                                    header('Location: super_admin_home.php');
                                } elseif ($admin['role'] === 'temporary_admin') {
                                    $tempPermissions = $_SESSION['temp_admin_permissions'] ?? [];
                                    $repWorkspaceAccess = function_exists('book_system_temp_admin_has_rep_workspace_access')
                                        && is_array($tempPermissions)
                                        && book_system_temp_admin_has_rep_workspace_access($tempPermissions);
                                    if ($repWorkspaceAccess) {
                                        $assistantName = trim(strval($admin['full_name'] ?? ''));
                                        header('Location: ' . ($assistantName === '' ? 'my_profile.php?setup=1' : 'rep_dashboard.php'));
                                    } else {
                                        session_destroy();
                                        header('Location: login.php?msg=temp_admin_expired');
                                    }
                                } else {
                                    $repAccessStatus = function_exists('book_system_get_rep_access_status')
                                        ? book_system_get_rep_access_status($conn, intval($admin['admin_id'] ?? 0))
                                        : ['can_access' => true];
                                    if (!empty($repAccessStatus['can_access'])) {
                                        header('Location: ' . (book_system_rep_has_books($conn, intval($admin['admin_id'] ?? 0)) ? 'rep_dashboard.php' : 'manage_books.php?msg=book_setup_required'));
                                    } else {
                                        header('Location: rep_subscription.php');
                                    }
                                }
                                exit;
                            }
                        } else {
                            $error = "Invalid username or password.";
                        }
                    } else {
                        $signupStmt = $conn->prepare("SELECT signup_id, username, full_name, class_name, signup_password_hash, status, created_admin_id
                            FROM rep_signup_requests
                            WHERE username = ?
                            LIMIT 1");
                        if ($signupStmt) {
                            $signupStmt->bind_param('s', $username);
                            $signupStmt->execute();
                            $signupRes = $signupStmt->get_result();
                            $signup = ($signupRes && $signupRes->num_rows === 1) ? $signupRes->fetch_assoc() : null;
                            $signupStmt->close();

                            if (is_array($signup) && trim(strval($signup['signup_password_hash'] ?? '')) !== '' && password_verify($password, strval($signup['signup_password_hash'] ?? ''))) {
                                book_system_secure_session_regenerate(true);
                                book_system_record_login_attempt($conn, 'admin', $username, true);

                                if (strval($signup['status'] ?? '') === 'approved') {
                                    $approvedAdmin = function_exists('book_system_fetch_rep_admin_row_by_id')
                                        ? book_system_fetch_rep_admin_row_by_id($conn, intval($signup['created_admin_id'] ?? 0))
                                        : null;
                                    if (is_array($approvedAdmin) && intval($approvedAdmin['is_active'] ?? 0) === 1) {
                                        if (function_exists('book_system_activate_rep_session_from_admin_row')) {
                                            book_system_activate_rep_session_from_admin_row($approvedAdmin);
                                        }
                                        $repAccessStatus = function_exists('book_system_get_rep_access_status')
                                            ? book_system_get_rep_access_status($conn, intval($approvedAdmin['admin_id'] ?? 0))
                                            : ['can_access' => true];
                                        if (!empty($repAccessStatus['can_access'])) {
                                            header('Location: ' . (book_system_rep_has_books($conn, intval($approvedAdmin['admin_id'] ?? 0)) ? 'rep_dashboard.php' : 'manage_books.php?msg=book_setup_required'));
                                        } else {
                                            header('Location: rep_subscription.php');
                                        }
                                        exit;
                                    }

                                    if (function_exists('book_system_start_pending_rep_session_from_signup')) {
                                        book_system_start_pending_rep_session_from_signup($signup);
                                    }
                                    $_SESSION['rep_access_gate_status'] = 'inactive';
                                    header('Location: pending_approval.php');
                                    exit;
                                }

                                if (function_exists('book_system_start_pending_rep_session_from_signup')) {
                                    book_system_start_pending_rep_session_from_signup($signup);
                                }
                                header('Location: pending_approval.php');
                                exit;
                            }
                        }

                        $error = "Invalid username or password.";
                    }
                } else {
                    $error = "Database error. Please try again.";
                }
            } catch (Exception $e) {
                $error = "System error. Please try again.";
            }

            if ($error !== '') {
                book_system_record_login_attempt($conn, 'admin', $username, false);
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
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ClassBookHub">
    <link rel="icon" type="image/png" sizes="1254x1254" href="assets/images/logo/classbookhub-icon.png">
    <link rel="apple-touch-icon" href="assets/images/logo/classbookhub-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <title>ClassBookHub Login</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body, html { min-height: 100%; }
        body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(72, 108, 241, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(116, 87, 230, 0.16), transparent 24%),
                linear-gradient(180deg, #f7f9ff 0%, #edf3ff 100%);
        }
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px 14px;
        }
        .login-card {
            width: 100%;
            max-width: 460px;
            padding: 34px 22px 28px;
            border-radius: 30px;
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(145, 164, 212, 0.26);
            box-shadow: 0 28px 60px rgba(31, 48, 110, 0.16);
            backdrop-filter: blur(18px);
        }
        .login-header {
            text-align: center;
            margin-bottom: 28px;
            display: grid;
            gap: 10px;
        }
        .login-eyebrow {
            justify-self: center;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 34px;
            padding: 0 14px;
            border-radius: 999px;
            background: rgba(72, 108, 241, 0.08);
            border: 1px solid rgba(72, 108, 241, 0.12);
            color: #4662cf;
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .login-header .icon {
            width: 76px;
            height: 76px;
            margin: 0 auto;
            border-radius: 24px;
            display: grid;
            place-items: center;
            font-size: 34px;
            background: linear-gradient(135deg, #486cf1 0%, #7457e6 100%);
            color: #fff;
            box-shadow: 0 18px 32px rgba(72, 108, 241, 0.24);
        }
        .login-brand {
            font-size: clamp(1.9rem, 7vw, 2.35rem);
            font-weight: 800;
            color: #10213e;
            letter-spacing: -0.04em;
        }
        .login-subtitle {
            color: #5f6f92;
            font-size: 0.98rem;
            line-height: 1.55;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-weight: 700;
            color: #31405f;
            margin-bottom: 9px;
            font-size: 0.92rem;
        }
        .form-group input {
            width: 100%;
            min-height: 56px;
            padding: 0 17px;
            border: 1px solid rgba(145, 164, 212, 0.34);
            border-radius: 18px;
            font: inherit;
            font-size: 0.98rem;
            font-weight: 600;
            color: #10213e;
            background: #f9fbff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .form-group input:focus {
            outline: none;
            border-color: rgba(72, 108, 241, 0.58);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(72, 108, 241, 0.12);
        }
        .password-field { position: relative; }
        .password-field input { padding-right: 92px; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #486cf1;
            font-size: 0.82rem;
            font-weight: 800;
            cursor: pointer;
            padding: 7px 8px;
            border-radius: 10px;
        }
        .password-toggle:hover { background: rgba(72, 108, 241, 0.08); }
        .login-btn {
            width: 100%;
            min-height: 56px;
            border: none;
            border-radius: 18px;
            background: linear-gradient(135deg, #486cf1 0%, #7457e6 100%);
            color: white;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            margin-top: 8px;
            box-shadow: 0 16px 30px rgba(72, 108, 241, 0.22);
        }
        .error-msg {
            background: #fff1f2;
            color: #be123c;
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 20px;
            font-size: 0.92rem;
            text-align: center;
            border: 1px solid #fecdd3;
        }
        .login-note {
            margin-top: 14px;
            text-align: center;
            color: #6b7280;
            font-size: 0.86rem;
            line-height: 1.6;
        }
        .footer-text {
            text-align: center;
            margin-top: 18px;
            color: #7c87a5;
            font-size: 0.84rem;
        }
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .footer-links a {
            color: #486cf1;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.82rem;
        }
        .footer-link-divider { color: #c1c9dd; }
        .footer-links .rep-signup-link { color: #0f9d58; }
        .footer-secondary-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 14px;
            padding: 10px 16px;
            border-radius: 999px;
            background: linear-gradient(135deg, #eff3ff 0%, #eefaf3 100%);
            color: #3f4db2;
            text-decoration: none;
            font-weight: 800;
            box-shadow: 0 10px 24px rgba(75, 91, 190, 0.12);
        }
        @media (max-width: 480px) {
            .login-card { padding: 30px 18px 24px; border-radius: 26px; }
            .footer-links {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                width: 100%;
            }
            .footer-links a {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 42px;
                padding: 6px 4px;
                border-radius: 12px;
                background: #f5f7ff;
                border: 1px solid #e1e7ff;
                white-space: normal;
                text-align: center;
            }
            .footer-link-divider { display: none; }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-eyebrow">Secure Access</div>
            <div class="icon">&#128218;</div>
            <h1 class="login-brand">ClassBookHub</h1>
            <p class="login-subtitle">Admin and Representative Portal</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="Enter your username" required autofocus autocomplete="username">
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <div class="password-field">
                    <input type="password" name="password" placeholder="Enter your password" required autocomplete="current-password" data-password-input>
                    <button type="button" class="password-toggle" data-password-toggle>Show</button>
                </div>
            </div>
            
            <button type="submit" class="login-btn">Sign In</button>
        </form>
        <p class="login-note">Access is restricted to approved users only.</p>
        <div class="footer-text">
            <div class="footer-links">
                <a href="rep_signup.php" class="rep-signup-link">Rep Sign Up</a>
                <span class="footer-link-divider">|</span>
                <a href="lecturer_login.php">Lecturer Login</a>
            </div>
            <?php if (!empty($error)): ?>
                <a href="forgot_password.php?account_type=admin" class="footer-secondary-link">Forgot Password</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
// Ensure inputs are always enabled after page load
document.addEventListener('DOMContentLoaded', function() {
    var inputs = document.querySelectorAll('input');
    inputs.forEach(function(input) {
        input.disabled = false;
        input.readOnly = false;
    });
    document.querySelector('input[name="username"]').focus();

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
