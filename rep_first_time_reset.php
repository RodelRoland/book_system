<?php
session_start();
require_once 'db.php';

$success_msg = '';
$error_msg = '';

$csrf_token = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $username = trim($_POST['username'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    if ($username === '' || $code === '' || $new_password === '') {
        $error_msg = 'All fields are required.';
    } elseif (!preg_match('/^\d{4}$/', $code)) {
        $error_msg = 'Code must be a 4-digit number.';
    } elseif (strlen($new_password) < 6) {
        $error_msg = 'Password must be at least 6 characters.';
    } else {
        $stmt = $conn->prepare("SELECT admin_id, first_time_code_expires FROM admins WHERE username = ? AND role = 'rep' AND is_active = 1 AND requires_password_reset = 1 AND first_time_code = ? LIMIT 1");
        $stmt->bind_param('ss', $username, $code);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $expires = $row['first_time_code_expires'] ? strtotime($row['first_time_code_expires']) : 0;
            if ($expires > time()) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $admin_id = intval($row['admin_id']);
                $upd = $conn->prepare("UPDATE admins SET password_hash = ?, first_time_code = NULL, first_time_code_expires = NULL, requires_password_reset = 0 WHERE admin_id = ?");
                $upd->bind_param('si', $hash, $admin_id);
                if ($upd->execute()) {
                    header('Location: login.php');
                    exit;
                } else {
                    $error_msg = 'Failed to update password. Please try again.';
                }
            } else {
                $error_msg = 'This code has expired. Contact the super admin for a new code.';
            }
        } else {
            $error_msg = 'Invalid details. Make sure your username and code are correct.';
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
    <title>First-Time Code Reset</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 520px; margin: 0 auto; }
        .header {
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
        .header h1 { font-size: 20px; font-weight: 800; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        .card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 18px;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
            border-left: 4px solid;
        }
        .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left-color: #f44336; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 800; color: #555; margin-bottom: 8px; font-size: 14px; }
        input {
            width: 100%;
            padding: 13px 14px;
            border: 2px solid #e8e8e8;
            border-radius: 10px;
            font-size: 15px;
            background: #f8f9fa;
        }
        input:focus { outline: none; border-color: #667eea; background: white; }
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-weight: 900;
            cursor: pointer;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 15px;
        }
        .btn:hover { opacity: 0.95; }
        .note { color: #666; font-size: 13px; line-height: 1.6; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>First-Time Code Reset</h1>
            <div style="opacity:0.9; font-size: 13px; margin-top: 4px;">Set your password using the 4-digit code from super admin</div>
        </div>
        <a href="login.php" class="back-btn">← Back</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?> <a href="login.php" style="color:#155724; font-weight:800; text-decoration:none;">Login</a></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>4-Digit Code *</label>
                <input type="text" name="code" required maxlength="4" inputmode="numeric">
            </div>
            <div class="form-group">
                <label>New Password *</label>
                <input type="password" name="new_password" required minlength="6">
            </div>
            <button type="submit" class="btn">Set Password</button>
            <div class="note">
                If your code is expired or invalid, contact the super admin to generate a new one.
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
