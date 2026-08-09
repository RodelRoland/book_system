<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_ensure_column($conn, 'admins', 'recovery_email', 'VARCHAR(120) NULL AFTER account_number');
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$session_role = strval($_SESSION['admin_role'] ?? '');
$temp_permissions = $_SESSION['temp_admin_permissions'] ?? [];
if ($session_role !== 'temporary_admin' || !is_array($temp_permissions) || !function_exists('book_system_temp_admin_has_rep_workspace_access') || !book_system_temp_admin_has_rep_workspace_access($temp_permissions)) {
    header('Location: rep_dashboard.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
if (!$access_context || empty($access_context['is_assistant_mode'])) {
    header('Location: login.php');
    exit;
}

$assistant_admin_id = intval($_SESSION['admin_id'] ?? 0);
$rep_admin_name = strval($access_context['effective_full_name'] ?? 'Rep');
$rep_class_name = strval($access_context['effective_class_name'] ?? 'Class');
$setup_mode = isset($_GET['setup']) || trim(strval($access_context['actor_full_name'] ?? '')) === '';
$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();
$rep_bottom_nav_active = 'profile';
$rep_bottom_nav_profile_href = 'assistant_profile.php';

$stmt = $conn->prepare("SELECT admin_id, username, full_name, recovery_email, profile_photo_path
    FROM admins
    WHERE admin_id = ? AND role = 'temporary_admin'
    LIMIT 1");
$stmt->bind_param('i', $assistant_admin_id);
$stmt->execute();
$assistant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assistant) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $full_name = trim(strval($_POST['full_name'] ?? ''));
        $recovery_email = function_exists('book_system_normalize_recovery_email')
            ? book_system_normalize_recovery_email($_POST['recovery_email'] ?? '')
            : trim(strval($_POST['recovery_email'] ?? ''));
        $new_profile_photo_path = trim(strval($assistant['profile_photo_path'] ?? ''));

        if ($full_name === '') {
            $error_msg = 'Enter your name before continuing.';
        } elseif (trim(strval($_POST['recovery_email'] ?? '')) !== '' && $recovery_email === '') {
            $error_msg = 'Enter a valid recovery email address.';
        } elseif (isset($_FILES['profile_photo']) && intval($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload_result = function_exists('book_system_store_profile_photo_upload')
                ? book_system_store_profile_photo_upload($_FILES['profile_photo'], 'assistant_profile')
                : ['success' => false, 'message' => 'Profile upload helper is unavailable.'];
            if (empty($upload_result['success'])) {
                $error_msg = strval($upload_result['message'] ?? 'Could not upload the profile picture.');
            } else {
                $new_profile_photo_path = strval($upload_result['path'] ?? '');
            }
        }

        if ($error_msg === '') {
            $update = $conn->prepare("UPDATE admins
                SET full_name = ?, recovery_email = NULLIF(?, ''), profile_photo_path = ?
                WHERE admin_id = ? AND role = 'temporary_admin'
                LIMIT 1");
            if ($update) {
                $update->bind_param('sssi', $full_name, $recovery_email, $new_profile_photo_path, $assistant_admin_id);
                if ($update->execute()) {
                    $old_profile_photo_path = trim(strval($assistant['profile_photo_path'] ?? ''));
                    if ($new_profile_photo_path !== '' && $new_profile_photo_path !== $old_profile_photo_path && function_exists('book_system_delete_profile_photo')) {
                        book_system_delete_profile_photo($old_profile_photo_path);
                    }
                    $_SESSION['admin_full_name'] = $full_name;
                    $assistant['full_name'] = $full_name;
                    $assistant['recovery_email'] = $recovery_email;
                    $assistant['profile_photo_path'] = $new_profile_photo_path;
                    if (function_exists('book_system_audit_log')) {
                        book_system_audit_log($conn, 'update_assistant_profile', 'admin', $assistant_admin_id, [
                            'source' => 'assistant_profile',
                        ], intval($access_context['effective_admin_id'] ?? 0));
                    }
                    if ($setup_mode) {
                        header('Location: rep_dashboard.php?msg=assistant_profile_ready');
                        exit;
                    }
                    $success_msg = 'Profile updated successfully.';
                } else {
                    $error_msg = 'Could not update your profile right now.';
                }
                $update->close();
            }
        }
    }
}

$assistant_name = trim(strval($assistant['full_name'] ?? ''));
$assistant_photo = trim(strval($assistant['profile_photo_path'] ?? ''));
$assistant_initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $assistant_name !== '' ? $assistant_name : strval($assistant['username'] ?? 'AR')), 0, 2));
if ($assistant_initials === '') {
    $assistant_initials = 'AR';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistant Profile</title>
    <style>
        :root {
            --bg: #f6f8fc;
            --surface: #ffffff;
            --line: #e5edf8;
            --text: #172033;
            --muted: #667085;
            --primary: #2563eb;
            --shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            --radius-lg: 22px;
            --radius-md: 16px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
            color: var(--text);
            padding: 14px 12px 120px;
        }
        .page { width: min(100%, 430px); margin: 0 auto; display: grid; gap: 16px; }
        .hero, .card { background: var(--surface); border: 1px solid rgba(255,255,255,0.92); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
        .hero { padding: 18px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--primary); margin-bottom: 12px; text-decoration: none; }
        .hero h1 { margin: 0 0 6px; font-size: 22px; line-height: 1.2; }
        .hero p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
        .hero-chip { display: inline-flex; align-items: center; gap: 8px; margin-top: 12px; padding: 8px 12px; border-radius: 999px; background: #eff6ff; color: var(--primary); font-size: 12px; font-weight: 800; }
        .card { padding: 18px; }
        .alert { padding: 12px 14px; border-radius: 14px; font-size: 13px; line-height: 1.55; }
        .alert.success { background: #ecfdf3; color: #166534; }
        .alert.error { background: #fef2f2; color: #991b1b; }
        .profile-photo-wrap { display: grid; grid-template-columns: 96px 1fr; gap: 14px; align-items: center; margin-bottom: 18px; }
        .profile-photo-avatar {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            background: #dbeafe;
            color: var(--primary);
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            background-size: cover;
            background-position: center;
            border: 3px solid rgba(255,255,255,0.95);
            box-shadow: 0 14px 24px rgba(37, 99, 235, 0.16);
        }
        .profile-photo-avatar.has-image { color: transparent; }
        .profile-photo-copy strong { display: block; margin-bottom: 6px; font-size: 15px; }
        .profile-photo-copy span { display: block; color: var(--muted); font-size: 13px; line-height: 1.5; }
        .file-input { margin-top: 10px; width: 100%; font: inherit; }
        .form-grid { display: grid; gap: 12px; }
        .form-group label { display: block; margin-bottom: 7px; font-size: 12px; font-weight: 800; color: #475467; text-transform: uppercase; letter-spacing: 0.04em; }
        .form-input { width: 100%; border: 1px solid var(--line); border-radius: 14px; padding: 13px 14px; font: inherit; background: #fff; }
        .form-input:focus { outline: none; border-color: #93c5fd; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); }
        .note { color: var(--muted); font-size: 12px; line-height: 1.55; }
        .btn { border: none; border-radius: 14px; padding: 13px 16px; font: inherit; font-weight: 800; cursor: pointer; }
        .btn-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; }
        .btn-secondary { display: inline-flex; align-items: center; justify-content: center; background: #eef2ff; color: #3730a3; text-decoration: none; }
        .actions { display: grid; gap: 10px; margin-top: 16px; }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <?php if (!$setup_mode): ?>
            <a href="rep_dashboard.php" class="back-link">&larr; Back to Workspace</a>
        <?php endif; ?>
        <h1><?php echo $setup_mode ? 'Complete your assistant profile' : 'Assistant Profile'; ?></h1>
        <p><?php echo $setup_mode ? 'Add your name and optional picture before entering the class workspace.' : 'Update the identity that appears while you work inside the rep workspace.'; ?></p>
        <div class="hero-chip">Working under <?php echo htmlspecialchars($rep_admin_name); ?> • <?php echo htmlspecialchars($rep_class_name); ?></div>
    </section>

    <?php if ($success_msg !== ''): ?><div class="alert success"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg !== ''): ?><div class="alert error"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <section class="card">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="profile-photo-wrap">
                <div class="profile-photo-avatar<?php echo $assistant_photo !== '' ? ' has-image' : ''; ?>" id="profilePhotoPreview" <?php if ($assistant_photo !== ''): ?>style="background-image:url('<?php echo htmlspecialchars($assistant_photo, ENT_QUOTES); ?>');"<?php endif; ?>><?php echo htmlspecialchars($assistant_initials); ?></div>
                <div class="profile-photo-copy">
                    <strong>Profile picture</strong>
                    <span>Your name and picture will identify you clearly whenever you work in the rep workspace.</span>
                    <input type="file" name="profile_photo" id="profilePhotoInput" class="file-input" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($assistant_name); ?>" required>
                </div>
                <div class="form-group">
                    <label>Recovery Email</label>
                    <input type="email" name="recovery_email" class="form-input" value="<?php echo htmlspecialchars(strval($assistant['recovery_email'] ?? '')); ?>" placeholder="Optional">
                    <div class="note">Use this if you want to recover this assistant account later without relying on the rep.</div>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary"><?php echo $setup_mode ? 'Save and Continue' : 'Save Profile'; ?></button>
                <?php if (!$setup_mode): ?>
                    <a href="change_admin_password.php" class="btn btn-secondary">Change Password</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
</div>
<?php require __DIR__ . '/rep_bottom_nav.php'; ?>
<script>
const profilePhotoInput = document.getElementById('profilePhotoInput');
const profilePhotoPreview = document.getElementById('profilePhotoPreview');
if (profilePhotoInput && profilePhotoPreview) {
    profilePhotoInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (event) {
            profilePhotoPreview.style.backgroundImage = "url('" + event.target.result + "')";
            profilePhotoPreview.classList.add('has-image');
            profilePhotoPreview.textContent = '';
        };
        reader.readAsDataURL(file);
    });
}
</script>
</body>
</html>
