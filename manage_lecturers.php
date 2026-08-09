<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_level', 'VARCHAR(10) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'lecturers', 'phone_number', 'VARCHAR(20) NULL AFTER full_name');
    }
}
$conn->query("CREATE TABLE IF NOT EXISTS lecturer_materials (
    material_id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NOT NULL,
    material_title VARCHAR(100) NOT NULL,
    course_code VARCHAR(20) NOT NULL,
    course_code_key VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lecturer_material_code (lecturer_id, course_code_key)
)");

book_system_require_admin_feature($conn, 'manage_lecturers');

$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();
$teaching_level_options = function_exists('book_system_get_teaching_level_options')
    ? book_system_get_teaching_level_options()
    : ['100', '200', '300', '400'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_lecturer') {
            $username = substr(trim(strval($_POST['username'] ?? '')), 0, 50);
            $full_name = substr(trim(strval($_POST['full_name'] ?? '')), 0, 100);
            $phone_number = trim(strval($_POST['phone_number'] ?? ''));
            $phone_e164 = function_exists('book_system_normalize_ghana_phone')
                ? book_system_normalize_ghana_phone($phone_number)
                : '';
            $teaching_level = function_exists('book_system_normalize_teaching_level')
                ? book_system_normalize_teaching_level($_POST['teaching_level'] ?? '')
                : preg_replace('/[^0-9]/', '', strval($_POST['teaching_level'] ?? ''));
            $password = strval($_POST['password'] ?? '');

            if ($username === '' || $full_name === '' || $password === '' || $teaching_level === '' || $phone_number === '') {
                $error_msg = 'Username, full name, phone number, teaching level, and password are required.';
            } elseif ($phone_e164 === '') {
                $error_msg = 'Enter a valid Ghana phone number.';
            } elseif (strlen($password) < 6) {
                $error_msg = 'Password must be at least 6 characters.';
            } else {
                $check = $conn->prepare("SELECT lecturer_id FROM lecturers WHERE username = ? LIMIT 1");
                if ($check) {
                    $check->bind_param('s', $username);
                    $check->execute();
                    $res = $check->get_result();
                    if ($res && $res->num_rows > 0) {
                        $error_msg = 'Username already exists.';
                    }
                }

                if ($error_msg === '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO lecturers (username, password_hash, full_name, phone_number, teaching_level, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                    if ($stmt) {
                        $stmt->bind_param('sssss', $username, $hash, $full_name, $phone_number, $teaching_level);
                        if ($stmt->execute()) {
                            $success_msg = 'Lecturer created successfully.';
                        } else {
                            $error_msg = 'Failed to create lecturer.';
                        }
                    } else {
                        $error_msg = 'Database error.';
                    }
                }
            }
        }

        if ($action === 'update_profile') {
            $lecturer_id = intval($_POST['lecturer_id'] ?? 0);
            $phone_number = trim(strval($_POST['phone_number'] ?? ''));
            $phone_e164 = function_exists('book_system_normalize_ghana_phone')
                ? book_system_normalize_ghana_phone($phone_number)
                : '';
            $teaching_level = function_exists('book_system_normalize_teaching_level')
                ? book_system_normalize_teaching_level($_POST['teaching_level'] ?? '')
                : preg_replace('/[^0-9]/', '', strval($_POST['teaching_level'] ?? ''));

            if ($lecturer_id <= 0 || $teaching_level === '' || $phone_number === '') {
                $error_msg = 'Provide a valid teaching level and phone number.';
            } elseif ($phone_e164 === '') {
                $error_msg = 'Enter a valid Ghana phone number.';
            } else {
                $stmt = $conn->prepare("UPDATE lecturers SET teaching_level = ?, phone_number = ? WHERE lecturer_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssi', $teaching_level, $phone_number, $lecturer_id);
                    if ($stmt->execute()) {
                        $success_msg = 'Lecturer details updated successfully.';
                    } else {
                        $error_msg = 'Failed to update lecturer details.';
                    }
                } else {
                    $error_msg = 'Database error.';
                }
            }
        }

        if ($action === 'toggle_active') {
            $lecturer_id = intval($_POST['lecturer_id'] ?? 0);
            if ($lecturer_id > 0) {
                $stmt = $conn->prepare("UPDATE lecturers SET is_active = 1 - is_active WHERE lecturer_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $lecturer_id);
                    $stmt->execute();
                    $success_msg = 'Lecturer status updated.';
                }
            }
        }

        if ($action === 'reset_password') {
            $lecturer_id = intval($_POST['lecturer_id'] ?? 0);
            $new_password = strval($_POST['new_password'] ?? '');
            if ($lecturer_id <= 0) {
                $error_msg = 'Invalid lecturer.';
            } elseif (strlen($new_password) < 6) {
                $error_msg = 'Password must be at least 6 characters.';
            } else {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE lecturers SET password_hash = ? WHERE lecturer_id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $hash, $lecturer_id);
                    if ($stmt->execute()) {
                        $success_msg = 'Password reset successfully.';
                    } else {
                        $error_msg = 'Failed to reset password.';
                    }
                } else {
                    $error_msg = 'Database error.';
                }
            }
        }
    }
}

// Data for page
$lecturers = $conn->query("SELECT lecturer_id, username, full_name, phone_number, teaching_level, is_active, created_at FROM lecturers ORDER BY created_at DESC");
$lecturer_summary = [
    'total_lecturers' => 0,
    'active_lecturers' => 0,
    'levels_covered' => 0,
    'materials_linked' => 0,
];
$lecturer_summary_result = $conn->query("SELECT
    (SELECT COUNT(*) FROM lecturers) AS total_lecturers,
    (SELECT COUNT(*) FROM lecturers WHERE is_active = 1) AS active_lecturers,
    (SELECT COUNT(DISTINCT teaching_level) FROM lecturers WHERE COALESCE(teaching_level, '') <> '') AS levels_covered,
    (SELECT COUNT(*) FROM lecturer_materials) AS materials_linked");
if ($lecturer_summary_result) {
    $lecturer_summary = array_merge($lecturer_summary, $lecturer_summary_result->fetch_assoc() ?: []);
}

$selected_lecturer_id = intval($_GET['lecturer_id'] ?? 0);
$selected_lecturer = null;
$assigned_books = null;

if ($selected_lecturer_id > 0) {
    $stmt = $conn->prepare("SELECT lecturer_id, username, full_name, phone_number, teaching_level, is_active FROM lecturers WHERE lecturer_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $selected_lecturer_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $selected_lecturer = $res->fetch_assoc();
        }
    }

    $stmt = $conn->prepare("SELECT material_id, material_title, course_code FROM lecturer_materials WHERE lecturer_id = ? ORDER BY material_title ASC, course_code ASC");
    if ($stmt) {
        $stmt->bind_param('i', $selected_lecturer_id);
        $stmt->execute();
        $assigned_books = $stmt->get_result();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Lecturers</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .page-container { max-width: 1200px; margin: 0 auto; }
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
            flex-wrap: wrap;
            gap: 10px;
        }
        .page-header h1 { font-size: 22px; font-weight: 700; }
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

        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #eef2f7;
        }
        .summary-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 8px;
        }
        .summary-value {
            color: #111827;
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 6px;
        }
        .summary-note {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 16px;
            color: #333;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }

        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 700; color: #555; margin-bottom: 8px; font-size: 13px; }
        input, select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
        }

        .btn {
            padding: 12px 16px;
            border-radius: 10px;
            font-weight: 800;
            border: none;
            cursor: pointer;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-sm { padding: 8px 12px; font-size: 12px; }

        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            font-weight: 800;
            border-bottom: 2px solid #e9ecef;
        }
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .status-pill {
            display: inline-flex;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .status-pill.active { background: #dcfce7; color: #166534; }
        .status-pill.inactive { background: #fee2e2; color: #991b1b; }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>&#127891; Manage Lecturers</h1>
            <div style="opacity:0.9; font-size: 13px; margin-top: 6px;">Create lecturers and view their selected course materials</div>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Total Lecturers</div>
            <div class="summary-value"><?php echo intval($lecturer_summary['total_lecturers'] ?? 0); ?></div>
            <div class="summary-note">All lecturer accounts currently created in the system.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Active Lecturers</div>
            <div class="summary-value"><?php echo intval($lecturer_summary['active_lecturers'] ?? 0); ?></div>
            <div class="summary-note">Lecturer accounts that can currently log in and work.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Levels Covered</div>
            <div class="summary-value"><?php echo intval($lecturer_summary['levels_covered'] ?? 0); ?></div>
            <div class="summary-note">Distinct teaching levels currently represented.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Linked Materials</div>
            <div class="summary-value"><?php echo intval($lecturer_summary['materials_linked'] ?? 0); ?></div>
            <div class="summary-note">Course materials registered by lecturers for matching.</div>
        </div>
    </div>

    <div class="grid">
        <div>
            <div class="card">
                <h2>Create Lecturer</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="create_lecturer">

                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="text" name="phone_number" required placeholder="e.g. 0244123456">
                    </div>
                    <div class="form-group">
                        <label>Teaching Level *</label>
                        <select name="teaching_level" required>
                            <option value="">Select level</option>
                            <?php foreach ($teaching_level_options as $level_option): ?>
                                <option value="<?php echo htmlspecialchars($level_option); ?>"><?php echo htmlspecialchars($level_option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-primary">Create Lecturer</button>
                </form>
            </div>

            <div class="card">
                <h2>All Lecturers</h2>
                <div style="overflow:auto;">
                <table>
                    <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Phone</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                    </thead>
                    <tbody>
                        <?php if ($lecturers && $lecturers->num_rows > 0): ?>
                            <?php while ($l = $lecturers->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($l['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($l['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($l['phone_number'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars((strval($l['teaching_level'] ?? '') !== '' ? strval($l['teaching_level']) : '-')); ?></td>
                                    <td>
                                        <span class="status-pill <?php echo intval($l['is_active']) === 1 ? 'active' : 'inactive'; ?>">
                                            <?php echo intval($l['is_active']) === 1 ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a class="btn btn-warning btn-sm" href="?lecturer_id=<?php echo intval($l['lecturer_id']); ?>">Manage</a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Toggle lecturer active status?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="lecturer_id" value="<?php echo intval($l['lecturer_id']); ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Toggle</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="color:#666;">No lecturers yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <h2>Selected Lecturer</h2>

                <?php if ($selected_lecturer): ?>
                    <div style="margin-bottom: 14px; color:#333;">
                        <strong><?php echo htmlspecialchars($selected_lecturer['full_name']); ?></strong>
                        <div style="color:#666; font-size: 13px; margin-top: 4px;">@<?php echo htmlspecialchars($selected_lecturer['username']); ?></div>
                        <div style="color:#666; font-size: 13px; margin-top: 4px;"><?php echo htmlspecialchars($selected_lecturer['phone_number'] ?: '-'); ?></div>
                        <div style="color:#666; font-size: 13px; margin-top: 4px;">Level <?php echo htmlspecialchars((strval($selected_lecturer['teaching_level'] ?? '') !== '' ? strval($selected_lecturer['teaching_level']) : '-')); ?></div>
                    </div>

                    <h2 style="border-bottom:none; padding-bottom:0; margin-bottom: 10px;">Lecturer Details</h2>
                    <form method="POST" style="margin-bottom: 18px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="lecturer_id" value="<?php echo intval($selected_lecturer['lecturer_id']); ?>">

                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="text" name="phone_number" value="<?php echo htmlspecialchars($selected_lecturer['phone_number'] ?? ''); ?>" required placeholder="e.g. 0244123456">
                        </div>
                        <div class="form-group">
                            <label>Assigned Level *</label>
                            <select name="teaching_level" required>
                                <option value="">Select level</option>
                                <?php foreach ($teaching_level_options as $level_option): ?>
                                    <option value="<?php echo htmlspecialchars($level_option); ?>" <?php echo (strval($selected_lecturer['teaching_level'] ?? '') === $level_option) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($level_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Details</button>
                    </form>

                    <h2 style="border-bottom:none; padding-bottom:0; margin-bottom: 10px;">Reset Password</h2>
                    <form method="POST" style="margin-bottom: 18px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="lecturer_id" value="<?php echo intval($selected_lecturer['lecturer_id']); ?>">

                        <div class="form-group">
                            <label>New Password *</label>
                            <input type="password" name="new_password" required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-primary">Reset</button>
                    </form>

                    <h2>Selected Course Materials</h2>
                    <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Course Code</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($assigned_books && $assigned_books->num_rows > 0): ?>
                                <?php while ($ab = $assigned_books->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($ab['material_title']); ?></td>
                                        <td><?php echo htmlspecialchars($ab['course_code']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="2" style="color:#666;">No materials selected yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                <?php else: ?>
                    <div style="color:#666;">Select a lecturer from the list to manage assignments.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>

