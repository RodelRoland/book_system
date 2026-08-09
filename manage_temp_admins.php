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
    }
}

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();
$permission_catalog = function_exists('book_system_temp_admin_permissions_catalog')
    ? book_system_temp_admin_permissions_catalog()
    : [];

function temp_admin_parse_datetime(string $value): ?string {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $action = strval($_POST['action'] ?? '');

        if ($action === 'create_temp_admin') {
            $username = trim(strval($_POST['username'] ?? ''));
            $full_name = trim(strval($_POST['full_name'] ?? ''));
            $password = strval($_POST['password'] ?? '');
            $expires_input = strval($_POST['temp_admin_expires_at'] ?? '');
            $expires_at = temp_admin_parse_datetime($expires_input);
            $permissions = function_exists('book_system_decode_temp_admin_permissions')
                ? book_system_decode_temp_admin_permissions(json_encode($_POST['permissions'] ?? []))
                : [];

            if ($username === '' || $full_name === '' || $password === '') {
                $error_msg = 'Username, full name, and password are required.';
            } elseif (strlen($password) < 6) {
                $error_msg = 'Password must be at least 6 characters.';
            } elseif ($expires_at === null) {
                $error_msg = 'Choose a valid expiry date and time.';
            } elseif (empty($permissions)) {
                $error_msg = 'Select at least one permission.';
            } elseif (function_exists('book_system_temp_admin_expired') && book_system_temp_admin_expired($expires_at)) {
                $error_msg = 'Expiry must be in the future.';
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

                if ($error_msg === '') {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $encoded_permissions = function_exists('book_system_encode_temp_admin_permissions')
                        ? book_system_encode_temp_admin_permissions($permissions)
                        : json_encode($permissions);
                    $delegated_by = intval($_SESSION['admin_id'] ?? 0);
                    $stmt = $conn->prepare("INSERT INTO admins (username, password_hash, full_name, role, is_active, approved_at, temp_admin_permissions, temp_admin_expires_at, delegated_by_admin_id)
                        VALUES (?, ?, ?, 'temporary_admin', 1, NOW(), ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssssi', $username, $password_hash, $full_name, $encoded_permissions, $expires_at, $delegated_by);
                        if ($stmt->execute()) {
                            $success_msg = 'Temporary admin created successfully.';
                            if (function_exists('book_system_audit_log')) {
                                book_system_audit_log($conn, 'create_temporary_admin', 'admin', intval($stmt->insert_id), [
                                    'username' => $username,
                                    'expires_at' => $expires_at,
                                    'permissions' => $permissions,
                                ]);
                            }
                        } else {
                            $error_msg = 'Failed to create the temporary admin.';
                        }
                        $stmt->close();
                    }
                }
            }
        } elseif ($action === 'update_temp_admin') {
            $admin_id = intval($_POST['admin_id'] ?? 0);
            $full_name = trim(strval($_POST['full_name'] ?? ''));
            $expires_input = strval($_POST['temp_admin_expires_at'] ?? '');
            $expires_at = temp_admin_parse_datetime($expires_input);
            $permissions = function_exists('book_system_decode_temp_admin_permissions')
                ? book_system_decode_temp_admin_permissions(json_encode($_POST['permissions'] ?? []))
                : [];
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($admin_id <= 0 || $full_name === '') {
                $error_msg = 'Select a valid temporary admin and enter a full name.';
            } elseif ($expires_at === null) {
                $error_msg = 'Choose a valid expiry date and time.';
            } elseif (empty($permissions)) {
                $error_msg = 'Select at least one permission.';
            } else {
                $encoded_permissions = function_exists('book_system_encode_temp_admin_permissions')
                    ? book_system_encode_temp_admin_permissions($permissions)
                    : json_encode($permissions);
                $stmt = $conn->prepare("UPDATE admins
                    SET full_name = ?, is_active = ?, temp_admin_permissions = ?, temp_admin_expires_at = ?
                    WHERE admin_id = ? AND role = 'temporary_admin'");
                if ($stmt) {
                    $stmt->bind_param('sissi', $full_name, $is_active, $encoded_permissions, $expires_at, $admin_id);
                    if ($stmt->execute()) {
                        $success_msg = 'Temporary admin updated.';
                    } else {
                        $error_msg = 'Could not update the temporary admin.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'toggle_status') {
            $admin_id = intval($_POST['admin_id'] ?? 0);
            if ($admin_id > 0) {
                $stmt = $conn->prepare("UPDATE admins SET is_active = NOT is_active WHERE admin_id = ? AND role = 'temporary_admin'");
                if ($stmt) {
                    $stmt->bind_param('i', $admin_id);
                    $stmt->execute();
                    $stmt->close();
                    $success_msg = 'Temporary admin status updated.';
                }
            }
        } elseif ($action === 'reset_password') {
            $admin_id = intval($_POST['admin_id'] ?? 0);
            $new_password = strval($_POST['new_password'] ?? '');
            if ($admin_id <= 0 || strlen($new_password) < 6) {
                $error_msg = 'The new password must be at least 6 characters.';
            } else {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admins SET password_hash = ? WHERE admin_id = ? AND role = 'temporary_admin'");
                if ($stmt) {
                    $stmt->bind_param('si', $password_hash, $admin_id);
                    $stmt->execute();
                    $stmt->close();
                    $success_msg = 'Temporary admin password reset successfully.';
                }
            }
        }
    }
}

$temp_admins = [];
$temp_res = $conn->query("SELECT admin_id, username, full_name, is_active, temp_admin_permissions, temp_admin_expires_at, created_at
    FROM admins
    WHERE role = 'temporary_admin'
    ORDER BY created_at DESC");
if ($temp_res) {
    while ($row = $temp_res->fetch_assoc()) {
        $row['decoded_permissions'] = function_exists('book_system_decode_temp_admin_permissions')
            ? book_system_decode_temp_admin_permissions(strval($row['temp_admin_permissions'] ?? ''))
            : [];
        $temp_admins[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Temporary Admins</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%); min-height: 100vh; padding: 30px 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 26px 30px; border-radius: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 16px; box-shadow: 0 10px 30px rgba(102,126,234,0.3); }
        .header h1 { font-size: 24px; font-weight: 700; }
        .header .subtitle { opacity: .92; margin-top: 6px; font-size: 14px; }
        .back-btn { background: rgba(255,255,255,0.18); color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; border: 1px solid rgba(255,255,255,0.3); }
        .grid { display: grid; grid-template-columns: 1fr 1.15fr; gap: 20px; }
        .card { background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .section-title { font-size: 18px; color: #333; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 700; color: #475569; font-size: 14px; }
        .form-input { width: 100%; padding: 13px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
        .form-input:focus { outline: none; border-color: #667eea; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .permission-grid { display: grid; gap: 10px; margin-top: 10px; }
        .permission-item { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; }
        .permission-item input { width: auto; }
        .btn { border: none; border-radius: 10px; padding: 12px 18px; font-weight: 700; cursor: pointer; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-secondary { background: #eef2ff; color: #4338ca; }
        .btn-danger { background: #fee2e2; color: #b91c1c; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 12px; border-bottom: 1px solid #edf2f7; text-align: left; vertical-align: top; font-size: 14px; }
        th { color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
        .badge { display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; }
        .badge.active { background: #dcfce7; color: #166534; }
        .badge.inactive { background: #fee2e2; color: #991b1b; }
        .permission-list { display: flex; flex-wrap: wrap; gap: 6px; }
        .permission-pill { background: #eef2ff; color: #3730a3; border-radius: 999px; padding: 6px 10px; font-size: 11px; font-weight: 800; }
        .action-stack { display: flex; gap: 8px; flex-wrap: wrap; }
        .mini-btn { border: none; border-radius: 8px; padding: 9px 12px; cursor: pointer; font-weight: 700; font-size: 12px; }
        .mini-btn.edit { background: #e0f2fe; color: #075985; }
        .mini-btn.reset { background: #ede9fe; color: #5b21b6; }
        .mini-btn.toggle { background: #fef3c7; color: #92400e; }
        @media (max-width: 900px) { .grid, .form-row { grid-template-columns: 1fr; } .header { flex-direction: column; align-items: flex-start; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Temporary Admins</h1>
            <p class="subtitle">Grant limited admin access for a controlled period of time.</p>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back</a>
    </div>

    <?php if ($success_msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div class="grid">
        <div class="card">
            <div class="section-title">Create Temporary Admin</div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="create_temp_admin">
                <div class="form-row">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="form-input" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-input" minlength="6" required>
                    </div>
                    <div class="form-group">
                        <label>Access Expires</label>
                        <input type="datetime-local" name="temp_admin_expires_at" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Permissions</label>
                    <div class="permission-grid">
                        <?php foreach ($permission_catalog as $permission_key => $permission_label): ?>
                            <label class="permission-item">
                                <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($permission_key); ?>">
                                <span><?php echo htmlspecialchars($permission_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Create Temporary Admin</button>
            </form>
        </div>

        <div class="card">
            <div class="section-title">Update Selected Temporary Admin</div>
            <form method="POST" id="editTempAdminForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="update_temp_admin">
                <input type="hidden" name="admin_id" id="edit_admin_id" value="">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label>Access Expires</label>
                        <input type="datetime-local" name="temp_admin_expires_at" id="edit_expires_at" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="is_active" id="edit_is_active" value="1" checked style="width:auto; margin-right:8px;"> Keep account active</label>
                </div>
                <div class="form-group">
                    <label>Permissions</label>
                    <div class="permission-grid">
                        <?php foreach ($permission_catalog as $permission_key => $permission_label): ?>
                            <label class="permission-item">
                                <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($permission_key); ?>" data-edit-permission>
                                <span><?php echo htmlspecialchars($permission_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
            <div style="margin-top:18px; color:#64748b; font-size:13px; line-height:1.6;">Tip: click <strong>Edit</strong> on a row below to load that temporary admin into this form.</div>
        </div>
    </div>

    <div class="card" style="margin-top: 20px;">
        <div class="section-title">Current Temporary Admins</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th>Permissions</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($temp_admins)): ?>
                    <tr><td colspan="6">No temporary admins created yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($temp_admins as $temp_admin): ?>
                        <?php
                        $decoded_permissions = $temp_admin['decoded_permissions'] ?? [];
                        $status_class = (!empty($temp_admin['is_active']) && !(function_exists('book_system_temp_admin_expired') ? book_system_temp_admin_expired(strval($temp_admin['temp_admin_expires_at'] ?? '')) : false)) ? 'active' : 'inactive';
                        $expires_value = '';
                        if (!empty($temp_admin['temp_admin_expires_at'])) {
                            $expires_value = date('Y-m-d\TH:i', strtotime(strval($temp_admin['temp_admin_expires_at'])));
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars(strval($temp_admin['username'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(strval($temp_admin['full_name'] ?? '')); ?></td>
                            <td><span class="badge <?php echo $status_class; ?>"><?php echo $status_class === 'active' ? 'Active' : 'Inactive / Expired'; ?></span></td>
                            <td><?php echo !empty($temp_admin['temp_admin_expires_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime(strval($temp_admin['temp_admin_expires_at'])))) : '-'; ?></td>
                            <td>
                                <div class="permission-list">
                                    <?php foreach ($decoded_permissions as $permission_key): ?>
                                        <span class="permission-pill"><?php echo htmlspecialchars($permission_catalog[$permission_key] ?? $permission_key); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <div class="action-stack">
                                    <button
                                        type="button"
                                        class="mini-btn edit"
                                        onclick='loadTempAdmin(<?php echo intval($temp_admin["admin_id"] ?? 0); ?>, <?php echo json_encode(strval($temp_admin["full_name"] ?? "")); ?>, <?php echo json_encode($expires_value); ?>, <?php echo !empty($temp_admin["is_active"]) ? "true" : "false"; ?>, <?php echo json_encode(array_values($decoded_permissions)); ?>)'
                                    >Edit</button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="admin_id" value="<?php echo intval($temp_admin['admin_id'] ?? 0); ?>">
                                        <button type="submit" class="mini-btn toggle"><?php echo !empty($temp_admin['is_active']) ? 'Deactivate' : 'Activate'; ?></button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="admin_id" value="<?php echo intval($temp_admin['admin_id'] ?? 0); ?>">
                                        <input type="password" name="new_password" placeholder="New password" minlength="6" required class="form-input" style="min-width:140px; display:inline-block;">
                                        <button type="submit" class="mini-btn reset">Reset</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function loadTempAdmin(adminId, fullName, expiresAt, isActive, permissions) {
    document.getElementById('edit_admin_id').value = adminId;
    document.getElementById('edit_full_name').value = fullName || '';
    document.getElementById('edit_expires_at').value = expiresAt || '';
    document.getElementById('edit_is_active').checked = !!isActive;
    document.querySelectorAll('[data-edit-permission]').forEach(function (checkbox) {
        checkbox.checked = Array.isArray(permissions) && permissions.indexOf(checkbox.value) !== -1;
    });
    document.getElementById('editTempAdminForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
