<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';

// Only reps can generate access codes (super admin doesn't need one)
if ($current_admin_role === 'super_admin') {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';
$current_code = null;
$code_expires = null;

// Fetch current access code
$stmt = $conn->prepare("SELECT access_code, access_code_expires FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $current_admin_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
    if ($row['access_code'] && $row['access_code_expires']) {
        $expires_time = strtotime($row['access_code_expires']);
        if ($expires_time > time()) {
            $current_code = $row['access_code'];
            $code_expires = $row['access_code_expires'];
        }
    }
}

// Handle generate new code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_code'])) {
    $duration_hours = intval($_POST['duration'] ?? 24);
    if ($duration_hours < 1) $duration_hours = 1;
    if ($duration_hours > 168) $duration_hours = 168; // Max 1 week
    
    // Generate a random 8-character code
    $new_code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $expires_at = date('Y-m-d H:i:s', time() + ($duration_hours * 3600));
    
    $stmt = $conn->prepare("UPDATE admins SET access_code = ?, access_code_expires = ? WHERE admin_id = ?");
    $stmt->bind_param("ssi", $new_code, $expires_at, $current_admin_id);
    
    if ($stmt->execute()) {
        $current_code = $new_code;
        $code_expires = $expires_at;
        $success_msg = "New access code generated successfully!";
    } else {
        $error_msg = "Error generating access code.";
    }
}

// Handle revoke code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_code'])) {
    $stmt = $conn->prepare("UPDATE admins SET access_code = NULL, access_code_expires = NULL WHERE admin_id = ?");
    $stmt->bind_param("i", $current_admin_id);
    
    if ($stmt->execute()) {
        $current_code = null;
        $code_expires = null;
        $success_msg = "Access code revoked. Super admin can no longer access your data.";
    } else {
        $error_msg = "Error revoking access code.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Access Code</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
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
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #e7f3ff; color: #0c5460; border: 1px solid #b8daff; }
        
        .code-display {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            text-align: center;
            margin-bottom: 20px;
        }
        .code-display .label { font-size: 14px; opacity: 0.9; margin-bottom: 10px; }
        .code-display .code {
            font-size: 42px;
            font-weight: 700;
            letter-spacing: 8px;
            font-family: 'Courier New', monospace;
        }
        .code-display .expires {
            margin-top: 15px;
            font-size: 13px;
            opacity: 0.9;
        }
        
        .no-code {
            background: #f8f9fa;
            padding: 40px;
            border-radius: 16px;
            text-align: center;
            color: #666;
        }
        .no-code .icon { font-size: 48px; margin-bottom: 15px; }
        .no-code p { font-size: 14px; margin-bottom: 20px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; color: #555; margin-bottom: 8px; font-size: 14px; }
        .form-group select, .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group select:focus, .form-group input:focus { outline: none; border-color: #667eea; }
        
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-2px); }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-block { width: 100%; }
        
        .actions { display: flex; gap: 15px; margin-top: 20px; }
        .actions form { flex: 1; }
        .actions .btn { width: 100%; }
        
        .info-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        .info-box h3 { font-size: 14px; color: #333; margin-bottom: 10px; }
        .info-box ul { padding-left: 20px; font-size: 13px; color: #666; }
        .info-box li { margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>🔐 Access Code</h1>
            <p class="subtitle">Control super admin access to your data</p>
        </div>
        <a href="admin.php" class="back-btn">← Back</a>
    </div>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h2>Your Access Code</h2>
        
        <?php if ($current_code): ?>
            <div class="code-display">
                <div class="label">Share this code with Super Admin</div>
                <div class="code"><?php echo htmlspecialchars($current_code); ?></div>
                <div class="expires">Expires: <?php echo date('M j, Y g:i A', strtotime($code_expires)); ?></div>
            </div>
            
            <div class="actions">
                <form method="POST">
                    <input type="hidden" name="generate_code" value="1">
                    <input type="hidden" name="duration" value="24">
                    <button type="submit" class="btn btn-primary">🔄 Generate New Code</button>
                </form>
                <form method="POST" onsubmit="return confirm('Revoke access code? Super admin will no longer be able to view your data.');">
                    <input type="hidden" name="revoke_code" value="1">
                    <button type="submit" class="btn btn-danger">🚫 Revoke Access</button>
                </form>
            </div>
        <?php else: ?>
            <div class="no-code">
                <div class="icon">🔒</div>
                <p>No active access code. Super admin cannot view your detailed data.</p>
            </div>
            
            <form method="POST" style="margin-top: 20px;">
                <div class="form-group">
                    <label>Code Validity Duration</label>
                    <select name="duration">
                        <option value="1">1 hour</option>
                        <option value="6">6 hours</option>
                        <option value="24" selected>24 hours (1 day)</option>
                        <option value="72">72 hours (3 days)</option>
                        <option value="168">168 hours (1 week)</option>
                    </select>
                </div>
                <button type="submit" name="generate_code" class="btn btn-primary btn-block">🔑 Generate Access Code</button>
            </form>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>How it works:</h3>
            <ul>
                <li>Generate a temporary access code to share with the Super Admin</li>
                <li>Super Admin enters this code to view your detailed records</li>
                <li>Without a valid code, Super Admin can only see summary statistics</li>
                <li>You can revoke access at any time by clicking "Revoke Access"</li>
                <li>Codes automatically expire after the selected duration</li>
            </ul>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
