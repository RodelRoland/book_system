<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'temp_admin_permissions', 'TEXT NULL AFTER profile_photo_path');
        book_system_setup_ensure_column($conn, 'admins', 'temp_admin_expires_at', 'DATETIME NULL AFTER temp_admin_permissions');
        book_system_setup_ensure_column($conn, 'admins', 'delegated_by_admin_id', 'INT NULL AFTER temp_admin_expires_at');
        book_system_setup_ensure_column($conn, 'admins', 'recovery_email', 'VARCHAR(120) NULL AFTER account_number');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
    }
    if (function_exists('book_system_setup_ensure_admin_role_support')) {
        book_system_setup_ensure_admin_role_support($conn);
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? '');
$allow_assistant_management = false;
if ($access_context) {
    if ($session_role === 'rep') {
        $allow_assistant_management = true;
    } elseif (
        $session_role === 'super_admin' &&
        (!empty($access_context['is_workspace_mode']) || !empty($access_context['is_own_rep_mode']))
    ) {
        $allow_assistant_management = true;
    }
}
if (!$allow_assistant_management) {
    header('Location: rep_dashboard.php');
    exit;
}

$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_name = strval($access_context['effective_full_name'] ?? 'Rep');
$current_admin_class = strval($access_context['effective_class_name'] ?? 'Class');
$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();
$rep_bottom_nav_active = 'profile';
$rep_bottom_nav_profile_href = 'manage_assistants.php';

function assistant_parse_datetime(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone('Africa/Accra'));
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function assistant_default_expiry(): string {
    $dt = new DateTime('now', new DateTimeZone('Africa/Accra'));
    $dt->modify('+365 days');
    return $dt->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $action = strval($_POST['action'] ?? '');

        if ($action === 'create_assistant') {
            $username = trim(strval($_POST['username'] ?? ''));
            $password = strval($_POST['password'] ?? '');
            $full_name = trim(strval($_POST['full_name'] ?? ''));
            $recovery_email = function_exists('book_system_normalize_recovery_email')
                ? book_system_normalize_recovery_email($_POST['recovery_email'] ?? '')
                : trim(strval($_POST['recovery_email'] ?? ''));
            $expires_at = assistant_parse_datetime(strval($_POST['temp_admin_expires_at'] ?? '')) ?: assistant_default_expiry();
            $permissions = function_exists('book_system_encode_temp_admin_permissions')
                ? book_system_encode_temp_admin_permissions(['rep_workspace_access'])
                : json_encode(['rep_workspace_access']);

            if ($username === '' || $password === '') {
                $error_msg = 'Username and password are required.';
            } elseif (strlen($password) < 6) {
                $error_msg = 'Password must be at least 6 characters.';
            } elseif (trim(strval($_POST['recovery_email'] ?? '')) !== '' && $recovery_email === '') {
                $error_msg = 'Enter a valid recovery email address.';
            } else {
                $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? LIMIT 1");
                if ($check) {
                    $check->bind_param('s', $username);
                    $check->execute();
                    $exists = $check->get_result();
                    if ($exists && $exists->num_rows > 0) {
                        $error_msg = 'That username already exists.';
                    }
                    $check->close();
                }
            }

            if ($error_msg === '') {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO admins (
                        username,
                        password_hash,
                        full_name,
                        recovery_email,
                        role,
                        is_active,
                        approved_at,
                        temp_admin_permissions,
                        temp_admin_expires_at,
                        delegated_by_admin_id
                    ) VALUES (?, ?, ?, NULLIF(?, ''), 'temporary_admin', 1, NOW(), ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('ssssssi', $username, $password_hash, $full_name, $recovery_email, $permissions, $expires_at, $current_admin_id);
                    if ($stmt->execute()) {
                        $assistant_id = intval($stmt->insert_id);
                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'create_assistant_account', 'admin', $assistant_id, [
                                'assistant_username' => $username,
                                'assistant_full_name' => $full_name,
                                'expires_at' => $expires_at,
                                'source' => 'manage_assistants',
                            ], $current_admin_id);
                        }
                        $success_msg = 'Assistant account created successfully.';
                    } else {
                        $error_msg = 'Could not create the assistant account right now.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'update_assistant') {
            $assistant_id = intval($_POST['assistant_id'] ?? 0);
            $full_name = trim(strval($_POST['full_name'] ?? ''));
            $recovery_email = function_exists('book_system_normalize_recovery_email')
                ? book_system_normalize_recovery_email($_POST['recovery_email'] ?? '')
                : trim(strval($_POST['recovery_email'] ?? ''));
            $expires_at = assistant_parse_datetime(strval($_POST['temp_admin_expires_at'] ?? ''));
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($assistant_id <= 0) {
                $error_msg = 'Select a valid assistant.';
            } elseif (trim(strval($_POST['recovery_email'] ?? '')) !== '' && $recovery_email === '') {
                $error_msg = 'Enter a valid recovery email address.';
            } elseif ($expires_at === null) {
                $error_msg = 'Choose a valid expiry date and time.';
            } else {
                $stmt = $conn->prepare("UPDATE admins
                    SET full_name = ?, recovery_email = NULLIF(?, ''), temp_admin_expires_at = ?, is_active = ?
                    WHERE admin_id = ? AND role = 'temporary_admin' AND delegated_by_admin_id = ?");
                if ($stmt) {
                    $stmt->bind_param('sssiii', $full_name, $recovery_email, $expires_at, $is_active, $assistant_id, $current_admin_id);
                    if ($stmt->execute()) {
                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'update_assistant_account', 'admin', $assistant_id, [
                                'assistant_full_name' => $full_name,
                                'expires_at' => $expires_at,
                                'is_active' => $is_active,
                                'source' => 'manage_assistants',
                            ], $current_admin_id);
                        }
                        $success_msg = 'Assistant account updated.';
                    } else {
                        $error_msg = 'Could not update the assistant account.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'toggle_status') {
            $assistant_id = intval($_POST['assistant_id'] ?? 0);
            if ($assistant_id > 0) {
                $stmt = $conn->prepare("UPDATE admins
                    SET is_active = NOT is_active
                    WHERE admin_id = ? AND role = 'temporary_admin' AND delegated_by_admin_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $assistant_id, $current_admin_id);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'toggle_assistant_status', 'admin', $assistant_id, [
                                'source' => 'manage_assistants',
                            ], $current_admin_id);
                        }
                        $success_msg = 'Assistant status updated.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'reset_password') {
            $assistant_id = intval($_POST['assistant_id'] ?? 0);
            $new_password = strval($_POST['new_password'] ?? '');
            if ($assistant_id <= 0 || strlen($new_password) < 6) {
                $error_msg = 'The new password must be at least 6 characters.';
            } else {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admins
                    SET password_hash = ?
                    WHERE admin_id = ? AND role = 'temporary_admin' AND delegated_by_admin_id = ?");
                if ($stmt) {
                    $stmt->bind_param('sii', $password_hash, $assistant_id, $current_admin_id);
                    if ($stmt->execute()) {
                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'reset_assistant_password', 'admin', $assistant_id, [
                                'source' => 'manage_assistants',
                            ], $current_admin_id);
                        }
                        $success_msg = 'Assistant password reset successfully.';
                    } else {
                        $error_msg = 'Could not reset the assistant password.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

$assistants = [];
$assistant_stmt = $conn->prepare("SELECT admin_id, username, full_name, recovery_email, profile_photo_path, is_active, temp_admin_expires_at, created_at
    FROM admins
    WHERE role = 'temporary_admin' AND delegated_by_admin_id = ?
    ORDER BY created_at DESC");
if ($assistant_stmt) {
    $assistant_stmt->bind_param('i', $current_admin_id);
    $assistant_stmt->execute();
    $assistant_res = $assistant_stmt->get_result();
    while ($assistant_res && ($row = $assistant_res->fetch_assoc())) {
        $assistants[] = $row;
    }
    $assistant_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistant Access</title>
    <style>
        :root {
            --bg: #f6f8fc;
            --surface: #ffffff;
            --line: #e5edf8;
            --text: #172033;
            --muted: #667085;
            --primary: #2563eb;
            --primary-soft: #eff6ff;
            --green: #16a34a;
            --red: #dc2626;
            --shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            --radius-lg: 20px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
            color: var(--text);
            padding: 14px 12px 120px;
        }
        a { color: inherit; text-decoration: none; }
        .page { width: min(100%, 430px); margin: 0 auto; display: grid; gap: 16px; }
        .hero, .card { background: var(--surface); border: 1px solid rgba(255,255,255,0.92); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
        .hero { padding: 18px 18px 16px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--primary); margin-bottom: 12px; }
        .hero h1 { margin: 0 0 6px; font-size: 22px; line-height: 1.2; }
        .hero p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
        .hero-meta { margin-top: 12px; display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; background: var(--primary-soft); color: var(--primary); font-size: 12px; font-weight: 700; }
        .card { padding: 16px; }
        .card h2 { margin: 0 0 6px; font-size: 16px; }
        .card p.lead { margin: 0 0 14px; color: var(--muted); font-size: 13px; line-height: 1.6; }
        .alert { padding: 12px 14px; border-radius: 14px; font-size: 13px; line-height: 1.55; }
        .alert.success { background: #ecfdf3; color: #166534; }
        .alert.error { background: #fef2f2; color: #991b1b; }
        .form-grid { display: grid; gap: 12px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group label { display: block; margin-bottom: 7px; font-size: 12px; font-weight: 800; color: #475467; text-transform: uppercase; letter-spacing: 0.04em; }
        .form-input { width: 100%; border: 1px solid var(--line); border-radius: 14px; padding: 13px 14px; font: inherit; background: #fff; }
        .form-input:focus { outline: none; border-color: #93c5fd; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); }
        .note { margin-top: 6px; color: var(--muted); font-size: 12px; line-height: 1.55; }
        .btn { border: none; border-radius: 14px; padding: 13px 16px; font: inherit; font-weight: 800; cursor: pointer; }
        .btn-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; }
        .btn-secondary { background: #eef2ff; color: #3730a3; }
        .assistant-list { display: grid; gap: 12px; }
        .assistant-item { border: 1px solid var(--line); border-radius: 18px; padding: 14px; background: linear-gradient(180deg, #fff 0%, #fbfdff 100%); }
        .assistant-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .assistant-name { font-size: 15px; font-weight: 800; }
        .assistant-user { margin-top: 3px; color: var(--muted); font-size: 12px; }
        .status-pill { display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .status-pill.active { background: #dcfce7; color: #166534; }
        .status-pill.inactive { background: #fef2f2; color: #b91c1c; }
        .assistant-meta { display: grid; gap: 8px; margin-bottom: 12px; color: #344054; font-size: 12px; }
        .assistant-meta strong { color: #111827; }
        .assistant-actions { display: grid; gap: 10px; }
        .action-row { display: grid; grid-template-columns: 1fr auto; gap: 10px; }
        .inline-form { display: grid; gap: 10px; }
        .inline-form .form-row { grid-template-columns: 1fr; }
        .small-btn { border: none; border-radius: 12px; padding: 11px 12px; font: inherit; font-weight: 800; cursor: pointer; }
        .small-btn.toggle { background: #f8fafc; color: #1f2937; border: 1px solid var(--line); }
        .small-btn.reset { background: #eff6ff; color: #1d4ed8; }
        .small-btn.save { background: #eef2ff; color: #3730a3; }
        .section-link { display: inline-flex; align-items: center; gap: 8px; margin-top: 12px; color: var(--primary); font-size: 13px; font-weight: 700; }
        @media (max-width: 380px) {
            .form-row,
            .action-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <a href="rep_dashboard.php" class="back-link">&larr; Back to Dashboard</a>
        <h1>Assistant Access</h1>
        <p>Create a delegated login for someone helping you handle your class activity without giving them super admin access.</p>
        <div class="hero-meta"><?php echo htmlspecialchars($current_admin_name); ?> • <?php echo htmlspecialchars($current_admin_class); ?></div>
    </section>

    <?php if ($success_msg !== ''): ?><div class="alert success"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg !== ''): ?><div class="alert error"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <section class="card">
        <h2>Create Assistant Account</h2>
        <p class="lead">The assistant will log in with these details, then complete their own name and photo setup before entering your workspace.</p>
        <form method="POST" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="create_assistant">
            <div class="form-row">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Temporary Password</label>
                    <input type="password" name="password" class="form-input" minlength="6" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Assistant Name</label>
                    <input type="text" name="full_name" class="form-input" placeholder="Optional for now">
                </div>
                <div class="form-group">
                    <label>Recovery Email</label>
                    <input type="email" name="recovery_email" class="form-input" placeholder="Optional">
                </div>
            </div>
            <div class="form-group">
                <label>Access Expiry</label>
                <input type="datetime-local" name="temp_admin_expires_at" class="form-input" value="<?php echo htmlspecialchars((new DateTime('now', new DateTimeZone('Africa/Accra')))->modify('+365 days')->format('Y-m-d\TH:i')); ?>">
                <div class="note">Use this to decide when the assistant’s access should end automatically.</div>
            </div>
            <button type="submit" class="btn btn-primary">Create Assistant</button>
        </form>
    </section>

    <section class="card">
        <h2>Current Assistants</h2>
        <p class="lead">Manage access, recovery details, and passwords for the assistants working under your class workspace.</p>
        <div class="assistant-list">
            <?php if (empty($assistants)): ?>
                <div class="assistant-item">
                    <div class="assistant-name">No assistant accounts yet</div>
                    <div class="assistant-user">Create one above when you are ready to delegate work.</div>
                </div>
            <?php else: ?>
                <?php foreach ($assistants as $assistant): ?>
                    <?php
                    $assistant_id = intval($assistant['admin_id'] ?? 0);
                    $assistant_name = trim(strval($assistant['full_name'] ?? ''));
                    $assistant_username = trim(strval($assistant['username'] ?? ''));
                    $assistant_status_class = !empty($assistant['is_active']) ? 'active' : 'inactive';
                    $assistant_status_text = !empty($assistant['is_active']) ? 'Active' : 'Inactive';
                    $expires_value = '';
                    if (!empty($assistant['temp_admin_expires_at'])) {
                        $expires_value = date('Y-m-d\TH:i', strtotime(strval($assistant['temp_admin_expires_at'])));
                    }
                    ?>
                    <div class="assistant-item">
                        <div class="assistant-head">
                            <div>
                                <div class="assistant-name"><?php echo htmlspecialchars($assistant_name !== '' ? $assistant_name : 'Profile not completed yet'); ?></div>
                                <div class="assistant-user">@<?php echo htmlspecialchars($assistant_username); ?></div>
                            </div>
                            <span class="status-pill <?php echo htmlspecialchars($assistant_status_class); ?>"><?php echo htmlspecialchars($assistant_status_text); ?></span>
                        </div>
                        <div class="assistant-meta">
                            <div><strong>Recovery Email:</strong> <?php echo htmlspecialchars(trim(strval($assistant['recovery_email'] ?? '')) !== '' ? strval($assistant['recovery_email']) : 'Not set'); ?></div>
                            <div><strong>Access Ends:</strong> <?php echo !empty($assistant['temp_admin_expires_at']) ? htmlspecialchars(date('M d, Y g:i A', strtotime(strval($assistant['temp_admin_expires_at'])))) : 'Not set'; ?></div>
                            <div><strong>Photo:</strong> <?php echo trim(strval($assistant['profile_photo_path'] ?? '')) !== '' ? 'Added' : 'Pending from assistant'; ?></div>
                        </div>
                        <div class="assistant-actions">
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="update_assistant">
                                <input type="hidden" name="assistant_id" value="<?php echo $assistant_id; ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Assistant Name</label>
                                        <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($assistant_name); ?>" placeholder="Let assistant complete this if blank">
                                    </div>
                                    <div class="form-group">
                                        <label>Recovery Email</label>
                                        <input type="email" name="recovery_email" class="form-input" value="<?php echo htmlspecialchars(strval($assistant['recovery_email'] ?? '')); ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Access Expiry</label>
                                        <input type="datetime-local" name="temp_admin_expires_at" class="form-input" value="<?php echo htmlspecialchars($expires_value); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label style="display:flex;align-items:center;gap:10px;margin-top:28px;">
                                            <input type="checkbox" name="is_active" value="1" style="width:auto;" <?php echo !empty($assistant['is_active']) ? 'checked' : ''; ?>>
                                            Keep this assistant active
                                        </label>
                                    </div>
                                </div>
                                <button type="submit" class="small-btn save">Save Changes</button>
                            </form>
                            <div class="action-row">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="assistant_id" value="<?php echo $assistant_id; ?>">
                                    <button type="submit" class="small-btn toggle"><?php echo !empty($assistant['is_active']) ? 'Deactivate Access' : 'Reactivate Access'; ?></button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="assistant_id" value="<?php echo $assistant_id; ?>">
                                    <input type="password" name="new_password" class="form-input" minlength="6" placeholder="New password" required style="min-width: 150px;">
                                    <button type="submit" class="small-btn reset">Reset Password</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <a href="rep_activity_log.php" class="section-link">View team activity log &rarr;</a>
    </section>
</div>
<?php require __DIR__ . '/rep_bottom_nav.php'; ?>
</body>
</html>
