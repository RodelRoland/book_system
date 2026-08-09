<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'index_number', 'VARCHAR(50) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'academic_level', 'VARCHAR(10) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'program_name', 'VARCHAR(100) NULL AFTER academic_level');
        book_system_setup_ensure_column($conn, 'admins', 'show_on_public_portal', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER program_name');
        book_system_setup_ensure_column($conn, 'admins', 'allow_super_admin_access', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_public_portal');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_ensure_column($conn, 'admins', 'recovery_email', 'VARCHAR(120) NULL AFTER account_number');
        book_system_setup_ensure_column($conn, 'admins', 'payment_method', "VARCHAR(20) NOT NULL DEFAULT 'manual_momo' AFTER account_name");
        book_system_setup_ensure_column($conn, 'admins', 'momo_network', 'VARCHAR(30) NULL AFTER account_name');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER momo_network');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_public_key', 'VARCHAR(255) NULL AFTER paystack_enabled');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_secret_key', 'VARCHAR(255) NULL AFTER paystack_public_key');
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$departments = [];
$department_result = $conn->query("SELECT department_id, department_name, department_code, is_active
    FROM departments
    ORDER BY is_active DESC, department_name ASC");
if ($department_result) {
    while ($department_row = $department_result->fetch_assoc()) {
        $departments[] = $department_row;
    }
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}

$is_assistant_workspace_user = !empty($access_context['is_assistant_mode']);
$assistant_admin_id = intval($_SESSION['admin_id'] ?? 0);
$admin_id = $is_assistant_workspace_user ? $assistant_admin_id : intval($access_context['effective_admin_id'] ?? 0);
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$rep_bottom_nav_active = 'profile';
$viewing_workspace = !empty($access_context['is_workspace_mode']);
$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();
$setup_mode = isset($_GET['setup']) || ($is_assistant_workspace_user && trim(strval($_SESSION['admin_full_name'] ?? '')) === '');
$active_semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($active_semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $active_semester_id = book_system_get_active_semester_id($conn);
}
$active_semester_name = isset($ACTIVE_SEMESTER_NAME) ? strval($ACTIVE_SEMESTER_NAME) : '';
$active_semester_start_date = isset($ACTIVE_SEMESTER_START_DATE) ? strval($ACTIVE_SEMESTER_START_DATE) : '';
$active_semester_label = function_exists('book_system_build_semester_label')
    ? strval(book_system_build_semester_label($active_semester_name, $active_semester_start_date))
    : trim($active_semester_name);
$workspace_reset_summary = (!$is_assistant_workspace_user && function_exists('book_system_get_rep_semester_workspace_summary'))
    ? book_system_get_rep_semester_workspace_summary($conn, $admin_id, $active_semester_id)
    : [];

$stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    header('Location: login.php');
    exit;
}

$current_admin_role = strval($admin['role'] ?? $session_role);
$display_role_label = $is_assistant_workspace_user
    ? 'Assistant Rep'
    : ucfirst(str_replace('_', ' ', $current_admin_role));
$can_manage_paystack_settings = strval($access_context['session_role'] ?? '') === 'super_admin';
$current_payment_method = function_exists('book_system_normalize_payment_method')
    ? book_system_normalize_payment_method($admin['payment_method'] ?? 'manual_momo')
    : 'manual_momo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile_action = strval($_POST['action'] ?? 'save_profile');
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } elseif (!$is_assistant_workspace_user && $profile_action === 'reset_current_semester_workspace') {
        $confirm_keyword = strtoupper(trim(strval($_POST['confirm_keyword'] ?? '')));
        $confirm_reset = !empty($_POST['confirm_reset']);
        if ($active_semester_id <= 0) {
            $error_msg = 'There is no active semester to reset right now.';
        } elseif ($confirm_keyword !== 'RESET') {
            $error_msg = 'Type RESET exactly before resetting your workspace.';
        } elseif (!$confirm_reset) {
            $error_msg = 'Confirm that you understand this reset cannot be undone.';
        } elseif (!function_exists('book_system_reset_rep_semester_workspace')) {
            $error_msg = 'Workspace reset is not available right now.';
        } else {
            $reset_result = book_system_reset_rep_semester_workspace($conn, $admin_id, $active_semester_id);
            if (!empty($reset_result['success'])) {
                header('Location: rep_dashboard.php?msg=workspace_reset_success');
                exit;
            }
            $error_msg = strval($reset_result['message'] ?? 'The workspace could not be reset. Nothing was removed.');
            $workspace_reset_summary = function_exists('book_system_get_rep_semester_workspace_summary')
                ? book_system_get_rep_semester_workspace_summary($conn, $admin_id, $active_semester_id)
                : $workspace_reset_summary;
        }
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $class_name = $is_assistant_workspace_user ? trim(strval($admin['class_name'] ?? '')) : trim($_POST['class_name'] ?? '');
        $index_number = $is_assistant_workspace_user ? trim(strval($admin['index_number'] ?? '')) : trim($_POST['index_number'] ?? '');
        $academic_level = $is_assistant_workspace_user ? preg_replace('/[^0-9]/', '', strval($admin['academic_level'] ?? '')) : preg_replace('/[^0-9]/', '', strval($_POST['academic_level'] ?? ''));
        $program_name = $is_assistant_workspace_user ? trim(strval($admin['program_name'] ?? '')) : trim($_POST['program_name'] ?? '');
        $department_id = $is_assistant_workspace_user ? intval($admin['department_id'] ?? 0) : intval($_POST['department_id'] ?? 0);
        $show_on_public_portal = $is_assistant_workspace_user
            ? intval($admin['show_on_public_portal'] ?? 1)
            : ($current_admin_role === 'rep' ? (isset($_POST['show_on_public_portal']) ? 1 : 0) : intval($admin['show_on_public_portal'] ?? 1));
        $allow_super_admin_access = $is_assistant_workspace_user
            ? intval($admin['allow_super_admin_access'] ?? 0)
            : ($current_admin_role === 'rep' ? (isset($_POST['allow_super_admin_access']) ? 1 : 0) : intval($admin['allow_super_admin_access'] ?? 0));
        $recovery_email = function_exists('book_system_normalize_recovery_email')
            ? book_system_normalize_recovery_email($_POST['recovery_email'] ?? '')
            : trim(strval($_POST['recovery_email'] ?? ''));
        $momo_number = $is_assistant_workspace_user ? trim(strval($admin['momo_number'] ?? '')) : trim($_POST['momo_number'] ?? '');
        $account_name = $is_assistant_workspace_user ? trim(strval($admin['account_name'] ?? '')) : trim($_POST['account_name'] ?? '');
        $momo_network = function_exists('book_system_normalize_momo_network')
            ? book_system_normalize_momo_network($is_assistant_workspace_user ? strval($admin['momo_network'] ?? '') : ($_POST['momo_network'] ?? ''))
            : trim($is_assistant_workspace_user ? strval($admin['momo_network'] ?? '') : ($_POST['momo_network'] ?? ''));
        $payment_method = $can_manage_paystack_settings && function_exists('book_system_normalize_payment_method')
            ? book_system_normalize_payment_method($_POST['payment_method'] ?? $current_payment_method)
            : $current_payment_method;
        $paystack_enabled = ($is_assistant_workspace_user ? intval($admin['paystack_enabled'] ?? 0) : ($can_manage_paystack_settings
            ? (isset($_POST['paystack_enabled']) ? 1 : 0)
            : intval($admin['paystack_enabled'] ?? 0)));
        $paystack_public_key = $is_assistant_workspace_user ? trim(strval($admin['paystack_public_key'] ?? '')) : ($can_manage_paystack_settings ? trim($_POST['paystack_public_key'] ?? '') : trim(strval($admin['paystack_public_key'] ?? '')));
        $paystack_secret_key = $is_assistant_workspace_user ? trim(strval($admin['paystack_secret_key'] ?? '')) : ($can_manage_paystack_settings ? trim($_POST['paystack_secret_key'] ?? '') : trim(strval($admin['paystack_secret_key'] ?? '')));
        if ($can_manage_paystack_settings && $paystack_secret_key === '') {
            $paystack_secret_key = trim(strval($admin['paystack_secret_key'] ?? ''));
        }
        if ($can_manage_paystack_settings) {
            $has_paystack_keys_for_save = ($paystack_public_key !== '' && $paystack_secret_key !== '');
            if ($paystack_enabled === 1 && $has_paystack_keys_for_save) {
                $payment_method = 'paystack';
            } elseif ($paystack_enabled !== 1) {
                $payment_method = 'manual_momo';
            }
        }
        $new_profile_photo_path = trim(strval($admin['profile_photo_path'] ?? ''));

        if ($full_name === '') {
            $error_msg = 'Full name is required.';
        } elseif (trim(strval($_POST['recovery_email'] ?? '')) !== '' && $recovery_email === '') {
            $error_msg = 'Enter a valid recovery email address.';
        } else {
            $department_name = '';
            if (!$is_assistant_workspace_user && $department_id > 0) {
                foreach ($departments as $department_option) {
                    if (intval($department_option['department_id'] ?? 0) === $department_id && intval($department_option['is_active'] ?? 0) === 1) {
                        $department_name = trim(strval($department_option['department_name'] ?? ''));
                        break;
                    }
                }
            }

            if (!$is_assistant_workspace_user && $show_on_public_portal === 1 && $class_name !== '' && ($department_id <= 0 || $department_name === '')) {
                $error_msg = 'Choose an active department before showing your class on the common request portal.';
            } elseif ($department_name !== '') {
                $program_name = $department_name;
            }

            if ($error_msg === '' && isset($_FILES['profile_photo']) && intval($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload_result = function_exists('book_system_store_profile_photo_upload')
                    ? book_system_store_profile_photo_upload($_FILES['profile_photo'], $is_assistant_workspace_user ? 'assistant_profile' : 'rep_profile')
                    : ['success' => false, 'message' => 'Profile upload helper is unavailable.'];

                if (empty($upload_result['success'])) {
                    $error_msg = strval($upload_result['message'] ?? 'Could not upload the profile picture.');
                } else {
                    $new_profile_photo_path = strval($upload_result['path'] ?? '');
                }
            }
        }

        if ($error_msg === '') {
            $stmt = $conn->prepare("UPDATE admins
                SET full_name = ?,
                    class_name = ?,
                    index_number = ?,
                    academic_level = ?,
                    program_name = ?,
                    department_id = NULLIF(?, 0),
                    show_on_public_portal = ?,
                    allow_super_admin_access = ?,
                    recovery_email = NULLIF(?, ''),
                    momo_number = ?,
                    account_name = ?,
                    momo_network = ?,
                    payment_method = ?,
                    paystack_enabled = ?,
                    paystack_public_key = ?,
                    paystack_secret_key = ?,
                    profile_photo_path = ?
                WHERE admin_id = ?");
            $stmt->bind_param(
                'sssssiiisssssisssi',
                $full_name,
                $class_name,
                $index_number,
                $academic_level,
                $program_name,
                $department_id,
                $show_on_public_portal,
                $allow_super_admin_access,
                $recovery_email,
                $momo_number,
                $account_name,
                $momo_network,
                $payment_method,
                $paystack_enabled,
                $paystack_public_key,
                $paystack_secret_key,
                $new_profile_photo_path,
                $admin_id
            );
            if ($stmt->execute()) {
                $old_profile_photo_path = trim(strval($admin['profile_photo_path'] ?? ''));
                if ($new_profile_photo_path !== '' && $new_profile_photo_path !== $old_profile_photo_path && function_exists('book_system_delete_profile_photo')) {
                    book_system_delete_profile_photo($old_profile_photo_path);
                }
                if (function_exists('cache_clear')) {
                    cache_clear('public_portal_reps_mobile_v1');
                    cache_clear('public_portal_index_map_v1');
                }
                if (function_exists('book_system_bump_portal_lookup_cache_version')) {
                    book_system_bump_portal_lookup_cache_version($conn);
                }
                $_SESSION['admin_full_name'] = $full_name;
                if (!$viewing_workspace && !$is_assistant_workspace_user) {
                    $_SESSION['admin_class_name'] = $class_name;
                }
                $success_msg = 'Profile updated successfully!';
                if ($is_assistant_workspace_user && $setup_mode) {
                    header('Location: rep_dashboard.php?msg=assistant_profile_ready');
                    exit;
                }
            } else {
                $error_msg = 'Failed to update profile.';
            }
            $stmt->close();
        }
    }

    $stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $current_payment_method = function_exists('book_system_normalize_payment_method')
        ? book_system_normalize_payment_method($admin['payment_method'] ?? 'manual_momo')
        : 'manual_momo';
    $workspace_reset_summary = (!$is_assistant_workspace_user && function_exists('book_system_get_rep_semester_workspace_summary'))
        ? book_system_get_rep_semester_workspace_summary($conn, $admin_id, $active_semester_id)
        : $workspace_reset_summary;
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
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; color: #555; margin-bottom: 8px; font-size: 14px; }
        .form-input { width: 100%; padding: 14px 16px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 15px; transition: border-color 0.3s; }
        .form-input:focus { outline: none; border-color: #667eea; }
        .form-input:disabled { background: #f5f5f5; color: #888; }
        .profile-photo-wrap { display: flex; align-items: center; gap: 18px; padding: 18px; border-radius: 18px; border: 1px solid #e2e8f0; background: #f8fbff; margin-bottom: 22px; flex-wrap: wrap; }
        .profile-photo-avatar { width: 110px; height: 110px; border-radius: 50%; overflow: hidden; background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%); border: 4px solid rgba(102, 126, 234, 0.14); display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 800; color: #667eea; background-size: cover; background-position: center; flex-shrink: 0; }
        .profile-photo-avatar.has-image { color: transparent; }
        .profile-photo-copy { flex: 1; min-width: 220px; }
        .profile-photo-copy strong { display: block; color: #333; font-size: 15px; margin-bottom: 6px; }
        .profile-photo-copy span { display: block; color: #667085; font-size: 13px; line-height: 1.6; }
        .file-input { width: 100%; padding: 12px 14px; border: 1px dashed #cbd5e1; border-radius: 14px; background: white; margin-top: 14px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        .section-title { font-weight: 700; color: #667eea; font-size: 15px; margin: 25px 0 15px; padding-top: 15px; border-top: 1px solid #eee; }
        .section-title:first-of-type { margin-top: 0; padding-top: 0; border-top: none; }
        .btn { padding: 14px 30px; border-radius: 10px; font-weight: 600; font-size: 15px; text-decoration: none; border: none; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-danger { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white; }
        .btn-secondary { background: #eef2ff; color: #4338ca; }
        .info-box { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; color: #666; }
        .info-box strong { color: #333; }
        .muted-note { font-size: 13px; color: #6b7280; margin-top: 8px; line-height: 1.5; }
        .danger-card {
            border: 1px solid rgba(220, 38, 38, 0.18);
            background: linear-gradient(180deg, #fff 0%, #fff8f8 100%);
        }
        .danger-title {
            color: #991b1b;
            border-bottom-color: rgba(220, 38, 38, 0.14);
        }
        .danger-shell {
            display: grid;
            gap: 18px;
        }
        .danger-copy {
            color: #475569;
            line-height: 1.7;
            font-size: 14px;
        }
        .danger-summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .danger-metric {
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255,255,255,0.92);
            border: 1px solid #ffe4e6;
        }
        .danger-metric span {
            display: block;
            color: #64748b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 6px;
        }
        .danger-metric strong {
            color: #111827;
            font-size: 18px;
        }
        .danger-list {
            margin: 0;
            padding-left: 18px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
        }
        .danger-actions {
            display: flex;
            justify-content: flex-start;
        }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 1000;
        }
        .modal-backdrop.is-open { display: flex; }
        .modal-card {
            width: min(100%, 520px);
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.25);
        }
        .modal-card h3 {
            font-size: 22px;
            color: #111827;
            margin-bottom: 12px;
        }
        .modal-card p {
            color: #475569;
            line-height: 1.7;
            margin-bottom: 14px;
        }
        .modal-warning-list {
            margin: 0 0 16px;
            padding-left: 18px;
            color: #475569;
            line-height: 1.7;
        }
        .confirm-box {
            padding: 14px 16px;
            border-radius: 14px;
            background: #fff8f8;
            border: 1px solid #fecaca;
            margin-bottom: 16px;
        }
        .confirm-box label {
            display: block;
            font-weight: 600;
            color: #991b1b;
            margin-bottom: 8px;
        }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        @media (max-width: 600px) {
            .danger-summary { grid-template-columns: 1fr; }
            .modal-card { padding: 20px; }
            .modal-actions { justify-content: stretch; }
            .modal-actions .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <div>
            <h1>&#128100; My Profile</h1>
            <p class="subtitle"><?php echo $is_assistant_workspace_user ? ($setup_mode ? 'Complete your assistant identity before entering the workspace' : 'Update your assistant identity inside the rep workspace') : 'Update your account and payment details'; ?></p>
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
            <strong>Role:</strong> <?php echo htmlspecialchars($display_role_label); ?>
            <?php if ($is_assistant_workspace_user): ?>
                &nbsp;|&nbsp; <strong>Workspace:</strong> <?php echo htmlspecialchars(strval($access_context['effective_full_name'] ?? 'Main Rep')); ?>
            <?php endif; ?>
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
                    <span><?php echo $is_assistant_workspace_user ? 'This picture and name will appear while you work inside the main rep workspace.' : 'Your class members will see this circular photo when they find you on the common request page.'; ?></span>
                    <input type="file" name="profile_photo" id="profilePhotoInput" class="file-input" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" class="form-input" required value="<?php echo htmlspecialchars($admin['full_name'] ?? ''); ?>">
                </div>
                <?php if (!$is_assistant_workspace_user): ?>
                    <div class="form-group">
                        <label>Class Name</label>
                        <input type="text" name="class_name" class="form-input" value="<?php echo htmlspecialchars($admin['class_name'] ?? ''); ?>" placeholder="e.g. Level 200 CS">
                    </div>
                    <div class="form-group">
                        <label>Index Number</label>
                        <input type="text" name="index_number" class="form-input" value="<?php echo htmlspecialchars($admin['index_number'] ?? ''); ?>" placeholder="e.g. 5230100552">
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$is_assistant_workspace_user): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Academic Level</label>
                        <input type="text" name="academic_level" class="form-input" value="<?php echo htmlspecialchars($admin['academic_level'] ?? ''); ?>" placeholder="e.g. 200" maxlength="3">
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" class="form-input">
                            <option value="0">Select Department</option>
                            <?php foreach ($departments as $department): ?>
                                <?php
                                    $department_option_id = intval($department['department_id'] ?? 0);
                                    $department_option_label = trim(strval($department['department_name'] ?? ''));
                                    if ($department_option_id <= 0 || $department_option_label === '') {
                                        continue;
                                    }
                                    if (intval($department['is_active'] ?? 0) !== 1 && intval($admin['department_id'] ?? 0) !== $department_option_id) {
                                        continue;
                                    }
                                ?>
                                <option value="<?php echo $department_option_id; ?>" <?php echo intval($admin['department_id'] ?? 0) === $department_option_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department_option_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" class="form-input" value="<?php echo htmlspecialchars($admin['program_name'] ?? ''); ?>" placeholder="e.g. Computer Science">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Recovery Email</label>
                <input type="email" name="recovery_email" class="form-input" value="<?php echo htmlspecialchars($admin['recovery_email'] ?? ''); ?>" placeholder="e.g. you@example.com" autocomplete="email">
                <div class="muted-note">This email will be used for password recovery on your <?php echo $is_assistant_workspace_user ? 'assistant' : 'admin'; ?> account.</div>
            </div>

            <?php if (!$is_assistant_workspace_user && $current_admin_role === 'rep'): ?>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:10px;">
                        <input type="checkbox" name="show_on_public_portal" value="1" style="width:auto;" <?php echo !isset($admin['show_on_public_portal']) || intval($admin['show_on_public_portal']) === 1 ? 'checked' : ''; ?>>
                        Show my class on the common request portal
                    </label>
                </div>
                <div class="form-group">
                    <label style="display:flex; align-items:flex-start; gap:10px;">
                        <input type="checkbox" name="allow_super_admin_access" value="1" style="width:auto; margin-top:3px;" <?php echo !empty($admin['allow_super_admin_access']) ? 'checked' : ''; ?>>
                        <span>
                            Allow the super admin to access my rep workspace and operational data.
                            <br><small style="color:#6b7280;">If this is off, the super admin can still activate, deactivate, and manage your account, but cannot open your rep pages.</small>
                        </span>
                    </label>
                </div>
            <?php endif; ?>

            <?php if (!$is_assistant_workspace_user): ?>
                <p class="section-title">&#128179; Payment Details (For Students)</p>
                <p class="muted-note">Students will see these details when making payments for their books.</p>

                <div class="form-row">
                    <div class="form-group">
                        <label>Payment Method</label>
                        <?php if ($can_manage_paystack_settings): ?>
                            <select name="payment_method" class="form-input">
                                <option value="manual_momo" <?php echo $current_payment_method === 'manual_momo' ? 'selected' : ''; ?>>Manual MoMo</option>
                                <option value="paystack" <?php echo $current_payment_method === 'paystack' ? 'selected' : ''; ?>>Paystack</option>
                            </select>
                        <?php else: ?>
                            <input type="text" class="form-input" value="<?php echo htmlspecialchars($current_payment_method === 'paystack' ? 'Paystack' : 'Manual MoMo'); ?>" disabled>
                            <div class="muted-note">Only the super admin or a trusted admin path can enable Paystack for this rep.</div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>MoMo Network</label>
                        <input type="text" name="momo_network" class="form-input" value="<?php echo htmlspecialchars($admin['momo_network'] ?? ''); ?>" placeholder="e.g. MTN, Telecel, AirtelTigo">
                    </div>
                    <div class="form-group">
                        <label>MoMo Number</label>
                        <input type="text" name="momo_number" class="form-input" value="<?php echo htmlspecialchars($admin['momo_number'] ?? ''); ?>" placeholder="e.g. 0244123456">
                    </div>
                    <div class="form-group">
                        <label>MoMo Account Name</label>
                        <input type="text" name="account_name" class="form-input" value="<?php echo htmlspecialchars($admin['account_name'] ?? ''); ?>" placeholder="e.g. Roland Kitsi">
                    </div>
                </div>

                <?php if ($can_manage_paystack_settings): ?>
                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:10px;">
                        <input type="checkbox" name="paystack_enabled" value="1" style="width:auto;" <?php echo !empty($admin['paystack_enabled']) ? 'checked' : ''; ?>>
                        Enable Paystack for this rep
                    </label>
                    <div class="muted-note">The public key can be shown in admin forms. The secret key is kept server-side and is never exposed on the frontend.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Paystack Public Key</label>
                        <input type="text" name="paystack_public_key" class="form-input" value="<?php echo htmlspecialchars($admin['paystack_public_key'] ?? ''); ?>" placeholder="pk_test_...">
                    </div>
                    <div class="form-group">
                        <label>Paystack Secret Key</label>
                        <input type="password" name="paystack_secret_key" class="form-input" value="" placeholder="Leave blank to keep the existing secret key">
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">&#128190; Save Changes</button>
        </form>
    </div>

    <?php if (!$is_assistant_workspace_user): ?>
    <div class="card danger-card">
        <h3 class="card-title danger-title">&#9888;&#65039; Danger Zone</h3>
        <div class="danger-shell">
            <div class="danger-copy">
                <strong>Reset Current Semester Workspace</strong><br>
                This clears only your active semester workspace. Previous semester records remain saved.
            </div>

            <div class="info-box">
                <strong>Active Semester:</strong>
                <?php echo htmlspecialchars($active_semester_label !== '' ? $active_semester_label : 'No active semester'); ?>
            </div>

            <div class="danger-summary">
                <div class="danger-metric">
                    <span>Class List</span>
                    <strong><?php echo intval($workspace_reset_summary['class_students'] ?? 0); ?></strong>
                </div>
                <div class="danger-metric">
                    <span>Books</span>
                    <strong><?php echo intval($workspace_reset_summary['books'] ?? 0); ?></strong>
                </div>
                <div class="danger-metric">
                    <span>Requests</span>
                    <strong><?php echo intval($workspace_reset_summary['requests'] ?? 0); ?></strong>
                </div>
                <div class="danger-metric">
                    <span>Request Items</span>
                    <strong><?php echo intval($workspace_reset_summary['request_items'] ?? 0); ?></strong>
                </div>
                <div class="danger-metric">
                    <span>Payments Affected</span>
                    <strong><?php echo intval($workspace_reset_summary['payment_records'] ?? 0); ?></strong>
                </div>
                <div class="danger-metric">
                    <span>Lecturer Records</span>
                    <strong><?php echo intval($workspace_reset_summary['books_received'] ?? 0) + intval($workspace_reset_summary['lecturer_payments'] ?? 0); ?></strong>
                </div>
            </div>

            <ul class="danger-list">
                <li>Uploaded class list for the active semester</li>
                <li>Books added for the active semester</li>
                <li>Requests, request items, and payment history for the active semester</li>
                <li>Books received and lecturer payment records for the active semester</li>
            </ul>

            <div class="danger-actions">
                <button type="button" class="btn btn-danger" id="openWorkspaceResetModal">Reset Workspace</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal-backdrop" id="workspaceResetModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="workspaceResetTitle">
        <h3 id="workspaceResetTitle">Reset Workspace?</h3>
        <p>
            This will permanently clear your current semester workspace.
        </p>
        <ul class="modal-warning-list">
            <li>uploaded class list</li>
            <li>requests</li>
            <li>books</li>
            <li>payments</li>
            <li>lecturer records</li>
        </ul>
        <p>Previous semester records will remain safe.</p>

        <form method="POST" id="workspaceResetForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="reset_current_semester_workspace">

            <div class="confirm-box">
                <label for="confirmResetKeyword">Type RESET to continue</label>
                <input type="text" id="confirmResetKeyword" name="confirm_keyword" class="form-input" autocomplete="off" placeholder="RESET">
                <label style="display:flex; align-items:flex-start; gap:10px; margin-top:12px; font-weight:500; color:#475569;">
                    <input type="checkbox" name="confirm_reset" value="1" style="width:auto; margin-top:3px;">
                    <span>I understand this will clear only my active semester workspace and cannot be undone.</span>
                </label>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelWorkspaceReset">Cancel</button>
                <button type="submit" class="btn btn-danger">Yes, Reset Workspace</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
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

    var resetModal = document.getElementById('workspaceResetModal');
    var openResetModalButton = document.getElementById('openWorkspaceResetModal');
    var cancelResetButton = document.getElementById('cancelWorkspaceReset');
    var confirmResetKeyword = document.getElementById('confirmResetKeyword');

    function closeResetModal() {
        if (!resetModal) return;
        resetModal.classList.remove('is-open');
        resetModal.setAttribute('aria-hidden', 'true');
    }

    if (openResetModalButton && resetModal) {
        openResetModalButton.addEventListener('click', function () {
            resetModal.classList.add('is-open');
            resetModal.setAttribute('aria-hidden', 'false');
            if (confirmResetKeyword) {
                window.setTimeout(function () {
                    confirmResetKeyword.focus();
                }, 60);
            }
        });
    }

    if (cancelResetButton) {
        cancelResetButton.addEventListener('click', closeResetModal);
    }

    if (resetModal) {
        resetModal.addEventListener('click', function (event) {
            if (event.target === resetModal) {
                closeResetModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && resetModal && resetModal.classList.contains('is-open')) {
            closeResetModal();
        }
    });
});
</script>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
<?php include 'footer.php'; ?>
</body>
</html>
