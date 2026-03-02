<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$admin_id = intval($_SESSION['admin_id']);
$success_msg = '';
$error_msg = '';

$csrf_token = csrf_get_token();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $full_name = trim($_POST['full_name'] ?? '');
    $class_name = trim($_POST['class_name'] ?? '');
    $momo_number = trim($_POST['momo_number'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    
    if (empty($full_name)) {
        $error_msg = "Full name is required.";
    } else {
        $stmt = $conn->prepare("UPDATE admins SET full_name = ?, class_name = ?, momo_number = ?, account_number = ?, account_name = ?, bank_name = ? WHERE admin_id = ?");
        $stmt->bind_param("ssssssi", $full_name, $class_name, $momo_number, $account_number, $account_name, $bank_name, $admin_id);
        if ($stmt->execute()) {
            $_SESSION['admin_full_name'] = $full_name;
            $_SESSION['admin_class_name'] = $class_name;
            $success_msg = "Profile updated successfully!";
        } else {
            $error_msg = "Failed to update profile.";
        }
    }
    }
}

// Fetch current admin details
$stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 700px; margin: 0 auto; }
        
        .page-header {
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
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .page-header .subtitle { opacity: 0.9; margin-top: 3px; font-size: 13px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        .form-input:focus { outline: none; border-color: #667eea; }
        .form-input:disabled { background: #f5f5f5; color: #888; }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
        }
        
        .section-title {
            font-weight: 700;
            color: #667eea;
            font-size: 15px;
            margin: 25px 0 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .section-title:first-of-type { margin-top: 0; padding-top: 0; border-top: none; }
        
        .btn {
            padding: 14px 30px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #666;
        }
        .info-box strong { color: #333; }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>👤 My Profile</h1>
            <p class="subtitle">Update your account and payment details</p>
        </div>
        <a href="admin.php" class="back-btn">← Back to Dashboard</a>
    </div>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h3 class="card-title">📝 Profile Information</h3>
        
        <div class="info-box">
            <strong>Username:</strong> <?php echo htmlspecialchars($admin['username']); ?> &nbsp;|&nbsp;
            <strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $admin['role'])); ?>
        </div>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <p class="section-title">👤 Personal Details</p>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" class="form-input" required 
                           value="<?php echo htmlspecialchars($admin['full_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Class Name</label>
                    <input type="text" name="class_name" class="form-input" 
                           value="<?php echo htmlspecialchars($admin['class_name'] ?? ''); ?>" 
                           placeholder="e.g. Level 200 CS">
                </div>
            </div>
            
            <p class="section-title">💳 Payment Details (For Students)</p>
            <p style="font-size: 13px; color: #888; margin-bottom: 15px;">
                Students will see these details when making payments for their books.
            </p>
            
            <div class="form-group">
                <label>MoMo Number</label>
                <input type="text" name="momo_number" class="form-input" 
                       value="<?php echo htmlspecialchars($admin['momo_number'] ?? ''); ?>" 
                       placeholder="e.g. 0244123456">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Bank Name</label>
                    <input type="text" name="bank_name" class="form-input" 
                           value="<?php echo htmlspecialchars($admin['bank_name'] ?? ''); ?>" 
                           placeholder="e.g. GCB Bank">
                </div>
                <div class="form-group">
                    <label>Account Name</label>
                    <input type="text" name="account_name" class="form-input" 
                           value="<?php echo htmlspecialchars($admin['account_name'] ?? ''); ?>" 
                           placeholder="e.g. John Doe">
                </div>
            </div>
            
            <div class="form-group">
                <label>Account Number</label>
                <input type="text" name="account_number" class="form-input" 
                       value="<?php echo htmlspecialchars($admin['account_number'] ?? ''); ?>" 
                       placeholder="e.g. 1234567890">
            </div>
            
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>
