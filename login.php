<?php
session_start();
error_reporting(0); // Suppress errors on login page to prevent HTML breakage
require_once 'db.php';

// Redirect if already logged in
if (isset($_SESSION['admin_logged_in'])) {
    if (($_SESSION['admin_role'] ?? '') === 'super_admin') {
        header('Location: admin.php');
    } else {
        header('Location: rep_dashboard.php');
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
        
        try {
            // Authenticate against admins table
            $stmt = $conn->prepare("SELECT admin_id, username, password_hash, full_name, class_name, role, is_active, requires_password_reset FROM admins WHERE username = ?");
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows === 1) {
                    $admin = $result->fetch_assoc();
                    
                    // Check if account is active
                    if (!$admin['is_active']) {
                        $error = "Your account has been deactivated. Contact the administrator.";
                    } elseif (password_verify($password, $admin['password_hash'])) {
                        // Check password reset requirement AFTER verifying password
                        if (intval($admin['requires_password_reset'] ?? 0) === 1 && ($admin['role'] ?? '') === 'rep') {
                            $_SESSION['reset_admin_id'] = $admin['admin_id'];
                            header('Location: rep_first_time_reset.php');
                            exit;
                        }
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $admin['admin_id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_full_name'] = $admin['full_name'];
                        $_SESSION['admin_class_name'] = $admin['class_name'];
                        $_SESSION['admin_role'] = $admin['role'];
                        if ($admin['role'] === 'super_admin') {
                            header('Location: admin.php');
                        } else {
                            header('Location: rep_dashboard.php');
                        }
                        exit;
                    } else {
                        $error = "Invalid username or password.";
                    }
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                $error = "Database error. Please try again.";
            }
        } catch (Exception $e) {
            $error = "System error. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body, html { height: 100%; }
        
        .login-wrapper {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            padding: 50px 40px;
            width: 100%;
            max-width: 420px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 35px;
        }
        .login-header .icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
        }
        .login-header h2 {
            font-size: 26px;
            color: #333;
            font-weight: 700;
        }
        .login-header p {
            color: #888;
            font-size: 14px;
            margin-top: 8px;
        }
        
        .form-group { margin-bottom: 22px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 15px 18px;
            border: 2px solid #e8e8e8;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
        }
        
        .login-btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.35);
            transition: all 0.3s;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.45);
        }
        
        .error-msg {
            background: #ffebee;
            color: #c62828;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
            text-align: center;
            border-left: 4px solid #f44336;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 25px;
            color: #aaa;
            font-size: 13px;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="icon">📚</div>
            <h2>Admin Login</h2>
            <p>Book Distribution System</p>
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
                <input type="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
            </div>
            
            <button type="submit" class="login-btn">Sign In</button>
        </form>
        <div class="footer-text" style="margin-top: 18px;">
            <div style="margin-bottom: 6px;">Secure Admin Access</div>
            <div>
                <a href="rep_signup.php" style="color:#667eea; text-decoration:none; font-weight:600;">Rep Sign Up</a>
                <span style="color:#ccc; padding: 0 8px;">|</span>
                <a href="rep_first_time_reset.php" style="color:#667eea; text-decoration:none; font-weight:600;">First-Time Code Reset</a>
                <span style="color:#ccc; padding: 0 8px;">|</span>
                <a href="lecturer_login.php" style="color:#667eea; text-decoration:none; font-weight:600;">Lecturer Login</a>
            </div>
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
});
</script>

</body>
</html>