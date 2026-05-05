<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'academic_level', 'VARCHAR(10) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'program_name', 'VARCHAR(100) NULL AFTER academic_level');
        book_system_setup_ensure_column($conn, 'admins', 'show_on_public_portal', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER program_name');
        book_system_setup_ensure_column($conn, 'admins', 'allow_super_admin_access', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_public_portal');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin' && empty($access_context['is_workspace_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$viewing_workspace = !empty($access_context['is_workspace_mode']);
$success_msg = '';
$error_msg = '';

$csrf_token = csrf_get_token();

$stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    header('Location: login.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $full_name = trim($_POST['full_name'] ?? '');
    $class_name = trim($_POST['class_name'] ?? '');
    $academic_level = preg_replace('/[^0-9]/', '', strval($_POST['academic_level'] ?? ''));
    $program_name = trim($_POST['program_name'] ?? '');
    $show_on_public_portal = isset($_POST['show_on_public_portal']) ? 1 : 0;
    $allow_super_admin_access = isset($_POST['allow_super_admin_access']) ? 1 : 0;
    $momo_number = trim($_POST['momo_number'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $new_profile_photo_path = trim(strval($admin['profile_photo_path'] ?? ''));
    
    if (empty($full_name)) {
        $error_msg = "Full name is required.";
    } else {
        if (isset($_FILES['profile_photo']) && intval($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload_result = function_exists('book_system_store_profile_photo_upload')
                ? book_system_store_profile_photo_upload($_FILES['profile_photo'], 'rep_profile')
                : ['success' => false, 'message' => 'Profile upload helper is unavailable.'];

            if (empty($upload_result['success'])) {
                $error_msg = strval($upload_result['message'] ?? 'Could not upload the profile picture.');
            } else {
                $new_profile_photo_path = strval($upload_result['path'] ?? '');
            }
        }
    }

    if ($error_msg === '') {
        $stmt = $conn->prepare("UPDATE admins SET full_name = ?, class_name = ?, academic_level = ?, program_name = ?, show_on_public_portal = ?, allow_super_admin_access = ?, momo_number = ?, account_number = ?, account_name = ?, bank_name = ?, profile_photo_path = ? WHERE admin_id = ?");
        $stmt->bind_param("ssssiisssssi", $full_name, $class_name, $academic_level, $program_name, $show_on_public_portal, $allow_super_admin_access, $momo_number, $account_number, $account_name, $bank_name, $new_profile_photo_path, $admin_id);
        if ($stmt->execute()) {
            $old_profile_photo_path = trim(strval($admin['profile_photo_path'] ?? ''));
            if ($new_profile_photo_path !== '' && $new_profile_photo_path !== $old_profile_photo_path && function_exists('book_system_delete_profile_photo')) {
                book_system_delete_profile_photo($old_profile_photo_path);
            }
            if (!$viewing_workspace) {
                $_SESSION['admin_full_name'] = $full_name;
                $_SESSION['admin_class_name'] = $class_name;
            }
            $success_msg = "Profile updated successfully!";
        } else {
            $error_msg = "Failed to update profile.";
        }
    }
    }
}

// Refresh current admin details after any update
$stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
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
        .profile-photo-wrap {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 18px;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            background: #f8fbff;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .profile-photo-avatar {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%);
            border: 4px solid rgba(102, 126, 234, 0.14);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: #667eea;
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }
        .profile-photo-avatar.has-image { color: transparent; }
        .profile-photo-copy {
            flex: 1;
            min-width: 220px;
        }
        .profile-photo-copy strong {
            display: block;
            color: #333;
            font-size: 15px;
            margin-bottom: 6px;
        }
        .profile-photo-copy span {
            display: block;
            color: #667085;
            font-size: 13px;
            line-height: 1.6;
        }
        .file-input {
            width: 100%;
            padding: 12px 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            background: white;
            margin-top: 14px;
        }
        
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
            <h1>&#128100; My Profile</h1>
            <p class="subtitle">Update your account and payment details</p>
        </div>
        <a href="<?= htmlspecialchars($dashboard_url) ?>" class="back-btn">&larr; Back to Dashboard</a>
    </div>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h3 class="card-title">&#128221; Profile Information</h3>
        
        <div class="info-box">
            <strong>Username:</strong> <?php echo htmlspecialchars($admin['username']); ?> &nbsp;|&nbsp;
            <strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $admin['role'])); ?>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <p class="section-title">&#128100; Personal Details</p>

            <?php
                $profile_photo_path = trim(strval($admin['profile_photo_path'] ?? ''));
                $profile_photo_style = $profile_photo_path !== '' ? "background-image:url('" . htmlspecialchars($profile_photo_path, ENT_QUOTES) . "');" : '';
                $profile_initials = strtoupper(substr(trim(strval($admin['full_name'] ?? 'RP')), 0, 2));
            ?>
            <div class="profile-photo-wrap">
                <div class="profile-photo-avatar <?php echo $profile_photo_path !== '' ? 'has-image' : ''; ?>" id="profilePhotoPreview" style="<?php echo $profile_photo_style; ?>">
                    <?php echo htmlspecialchars($profile_initials); ?>
                </div>
                <div class="profile-photo-copy">
                    <strong>Profile picture</strong>
                    <span>Your class members will see this circular photo when they find you on the common request page.</span>
                    <input type="file" name="profile_photo" id="profilePhotoInput" class="file-input" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>
            
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

            <div class="form-row">
                <div class="form-group">
                    <label>Academic Level</label>
                    <input type="text" name="academic_level" class="form-input"
                           value="<?php echo htmlspecialchars($admin['academic_level'] ?? ''); ?>"
                           placeholder="e.g. 200" maxlength="3">
                </div>
                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" class="form-input"
                           value="<?php echo htmlspecialchars($admin['program_name'] ?? ''); ?>"
                           placeholder="e.g. Computer Science">
                </div>
            </div>

            <?php if ($current_admin_role === 'rep'): ?>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:10px;">
                        <input type="checkbox" name="show_on_public_portal" value="1" style="width:auto;"
                               <?php echo !isset($admin['show_on_public_portal']) || intval($admin['show_on_public_portal']) === 1 ? 'checked' : ''; ?>>
                        Show my class on the common request portal
                    </label>
                </div>
                <div class="form-group">
                    <label style="display:flex; align-items:flex-start; gap:10px;">
                        <input type="checkbox" name="allow_super_admin_access" value="1" style="width:auto; margin-top:3px;"
                               <?php echo !empty($admin['allow_super_admin_access']) ? 'checked' : ''; ?>>
                        <span>
                            Allow the super admin to access my rep workspace and operational data.
                            <br><small style="color:#6b7280;">If this is off, the super admin can still activate, deactivate, and manage your account, but cannot open your rep pages.</small>
                        </span>
                    </label>
                </div>
            <?php endif; ?>
            
            <p class="section-title">&#128179; Payment Details (For Students)</p>
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
            
            <button type="submit" class="btn btn-primary">&#128190; Save Changes</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var backBtn = document.querySelector('.back-btn');
    if (backBtn) {
        backBtn.href = <?= json_encode($dashboard_url) ?>;
        backBtn.innerHTML = '&larr; Back to Dashboard';
    }

    var profileInput = document.getElementById('profilePhotoInput');
    var profilePreview = document.getElementById('profilePhotoPreview');
    if (profileInput && profilePreview) {
        profileInput.addEventListener('change', function () {
            var file = this.files && this.files[0] ? this.files[0] : null;
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (event) {
                var result = event.target && event.target.result ? String(event.target.result) : '';
                if (result !== '') {
                    profilePreview.style.backgroundImage = 'url(' + result.replace(/"/g, '&quot;') + ')';
                    profilePreview.classList.add('has-image');
                    profilePreview.textContent = '';
                }
            };
            reader.readAsDataURL(file);
        });
    }
});
</script>

<?php include 'footer.php'; ?>

</body>
</html>

