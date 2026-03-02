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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $username = substr(trim(strval($_POST['username'] ?? '')), 0, 50);
        $full_name = substr(trim(strval($_POST['full_name'] ?? '')), 0, 100);
        $password = strval($_POST['password'] ?? '');
        $confirm_password = strval($_POST['confirm_password'] ?? '');

        if ($username === '' || $full_name === '' || $password === '') {
            $error = 'All fields are required.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $error = 'Username must be 3-50 characters and contain only letters, numbers, dot, underscore, or dash.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            // Prevent collisions with admin usernames too
            $exists = false;

            $stmt = $conn->prepare("SELECT 1 FROM lecturers WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = ($res && $res->num_rows === 1);
            }

            if (!$exists) {
                $stmt = $conn->prepare("SELECT 1 FROM admins WHERE username = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $exists = ($res && $res->num_rows === 1);
                }
            }

            if ($exists) {
                $error = 'Username already exists. Please choose another.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                // Require activation by default
                $is_active = 0;
                $stmt = $conn->prepare("INSERT INTO lecturers (username, password_hash, full_name, is_active) VALUES (?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('sssi', $username, $hash, $full_name, $is_active);
                    if ($stmt->execute()) {
                        header('Location: lecturer_login.php?signed_up=1');
                        exit;
                    } else {
                        $error = 'Failed to create account. Please try again.';
                    }
                } else {
                    $error = 'Database error. Please try again.';
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
    <title>Lecturer Sign Up</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body, html { height: 100%; }

        .wrapper {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .card {
            background: white;
            padding: 42px 40px;
            width: 100%;
            max-width: 480px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
        }

        .header { text-align: center; margin-bottom: 22px; }
        .header .icon {
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
        .header h2 { font-size: 24px; color: #111827; font-weight: 800; }
        .header p { color: #6b7280; font-size: 13px; margin-top: 8px; }

        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; text-align: center; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 700; color: #374151; margin-bottom: 8px; font-size: 13px; }
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            background: #f9fafb;
        }
        .form-group input:focus { outline: none; border-color: #111827; background: white; }

        .btn {
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
        .btn:hover { opacity: 0.95; }

        .footer { text-align: center; margin-top: 18px; color: #9ca3af; font-size: 13px; }
        .footer a { color: #111827; text-decoration: none; font-weight: 700; }

        .hint {
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            color: #374151;
            padding: 10px 12px;
            border-radius: 10px;
            margin-top: 12px;
            font-size: 12px;
            line-height: 1.4;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="icon">🎓</div>
            <h2>Lecturer Sign Up</h2>
            <p>Create your lecturer account</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" required>
            </div>

            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" required>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required minlength="6">
            </div>

            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" required minlength="6">
            </div>

            <button type="submit" class="btn">Create Account</button>

            <div class="hint">
                After sign-up, your account will be pending activation. Please contact the administrator to activate your account.
            </div>
        </form>

        <div class="footer">
            Already have an account? <a href="lecturer_login.php">Sign In</a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>
