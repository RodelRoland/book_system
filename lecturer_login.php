<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
error_reporting(0);
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_level', 'VARCHAR(10) NULL AFTER full_name');
    }
}

if (isset($_SESSION['lecturer_logged_in']) && intval($_SESSION['lecturer_logged_in']) === 1) {
    header('Location: lecturer_dashboard.php');
    exit;
}

$error = '';
$success = '';
$csrf_token = csrf_get_token();

if (isset($_GET['signed_up']) && $_GET['signed_up'] === '1') {
    $success = 'Account created successfully. Your account is pending activation.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (book_system_is_login_rate_limited($conn, 'lecturer', $username)) {
            $error = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
            $stmt = $conn->prepare("SELECT lecturer_id, username, password_hash, full_name, teaching_level, is_active FROM lecturers WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $res = $stmt->get_result();
                $lecturer = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;

                if (!$lecturer) {
                    $error = 'Invalid username or password.';
                } elseif (intval($lecturer['is_active'] ?? 0) !== 1) {
                    $error = 'Your account has been deactivated. Contact the administrator.';
                } elseif (!password_verify($password, strval($lecturer['password_hash'] ?? ''))) {
                    $error = 'Invalid username or password.';
                } else {
                    book_system_secure_session_regenerate(true);
                    book_system_record_login_attempt($conn, 'lecturer', $username, true);
                    $_SESSION['lecturer_logged_in'] = 1;
                    $_SESSION['lecturer_id'] = intval($lecturer['lecturer_id']);
                    $_SESSION['lecturer_username'] = $lecturer['username'];
                    $_SESSION['lecturer_full_name'] = $lecturer['full_name'];
                    $_SESSION['lecturer_teaching_level'] = function_exists('book_system_normalize_teaching_level')
                        ? book_system_normalize_teaching_level($lecturer['teaching_level'] ?? '')
                        : preg_replace('/[^0-9]/', '', strval($lecturer['teaching_level'] ?? ''));

                    header('Location: lecturer_dashboard.php');
                    exit;
                }
            } else {
                $error = 'Database error. Please try again.';
            }

            if ($error !== '') {
                book_system_record_login_attempt($conn, 'lecturer', $username, false);
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
    <title>Lecturer Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body, html { height: 100%; }

        .login-wrapper {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-card {
            background: white;
            padding: 46px 40px;
            width: 100%;
            max-width: 420px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header .icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 30px;
            color: white;
        }
        .login-header h2 {
            font-size: 24px;
            color: #111827;
            font-weight: 800;
        }
        .login-header p {
            color: #6b7280;
            font-size: 13px;
            margin-top: 8px;
        }

        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 700;
            color: #374151;
            margin-bottom: 10px;
            font-size: 13px;
        }
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: #f9fafb;
        }
        .form-group input:focus {
            outline: none;
            border-color: #111827;
            background: white;
        }
        .password-field {
            position: relative;
        }
        .password-field input {
            padding-right: 100px;
        }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #111827;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 8px;
        }
        .password-toggle:hover {
            background: rgba(17, 24, 39, 0.08);
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            color: white;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            margin-top: 8px;
        }
        .login-btn:hover { opacity: 0.95; }

        .error-msg {
            background: #ffebee;
            color: #c62828;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
            border-left: 4px solid #f44336;
        }

        .footer-text {
            text-align: center;
            margin-top: 18px;
            color: #9ca3af;
            font-size: 13px;
        }
        .footer-text a {
            color: #111827;
            text-decoration: none;
            font-weight: 700;
        }
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="icon">🎓</div>
            <h2>Lecturer Login</h2>
            <p>Access your course materials dashboard</p>
        </div>

        <?php if (!empty($success)): ?>
            <div class="error-msg" style="background:#d4edda; color:#155724; border-left-color:#28a745;">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="password-field">
                    <input type="password" name="password" required autocomplete="current-password" data-password-input>
                    <button type="button" class="password-toggle" data-password-toggle>Show</button>
                </div>
            </div>

            <button type="submit" class="login-btn">Sign In</button>
        </form>

        <div class="footer-text">
            <div style="margin-bottom: 8px;">
                <a href="lecturer_signup.php">Create Lecturer Account</a>
            </div>
            <div class="footer-links">
                <a href="common_request_portal.php">Portal</a>
                <a href="login.php">Admin Login</a>
                <?php if (!empty($error)): ?>
                    <span style="color:#d1d5db;">|</span>
                    <a href="forgot_password.php?account_type=lecturer">Forgot Password</a>
                <?php endif; ?>
            </div>
        </div>
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
