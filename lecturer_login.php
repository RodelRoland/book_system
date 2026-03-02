<?php
session_start();
error_reporting(0);
require_once 'db.php';

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

        $stmt = $conn->prepare("SELECT lecturer_id, username, password_hash, full_name, is_active FROM lecturers WHERE username = ? LIMIT 1");
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
                @session_regenerate_id(true);
                $_SESSION['lecturer_logged_in'] = 1;
                $_SESSION['lecturer_id'] = intval($lecturer['lecturer_id']);
                $_SESSION['lecturer_username'] = $lecturer['username'];
                $_SESSION['lecturer_full_name'] = $lecturer['full_name'];

                header('Location: lecturer_dashboard.php');
                exit;
            }
        } else {
            $error = 'Database error. Please try again.';
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
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
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
                <input type="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="login-btn">Sign In</button>
        </form>

        <div class="footer-text">
            <div style="margin-bottom: 8px;">
                <a href="lecturer_signup.php">Create Lecturer Account</a>
            </div>
            <a href="login.php">Admin Login</a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>
