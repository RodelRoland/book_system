<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
}

book_system_require_admin_feature($conn, 'manage_reps');

$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();

$department_rows = [];
$department_result = $conn->query("SELECT d.*,
    (SELECT COUNT(*) FROM admins a WHERE a.role = 'rep' AND a.department_id = d.department_id) AS rep_count
    FROM departments d
    ORDER BY d.is_active DESC, d.department_name ASC");
if ($department_result) {
    while ($department_row = $department_result->fetch_assoc()) {
        $department_rows[] = $department_row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $action = strval($_POST['action'] ?? '');

        if ($action === 'create_department') {
            $department_name = substr(trim(strval($_POST['department_name'] ?? '')), 0, 120);
            $department_code = strtoupper(substr(trim(strval($_POST['department_code'] ?? '')), 0, 20));

            if ($department_name === '') {
                $error_msg = 'Department name is required.';
            } else {
                $stmt = $conn->prepare("INSERT INTO departments (department_name, department_code, is_active) VALUES (?, NULLIF(?, ''), 1)");
                if ($stmt) {
                    $stmt->bind_param('ss', $department_name, $department_code);
                    if ($stmt->execute()) {
                        $success_msg = 'Department added successfully.';
                    } else {
                        $error_msg = 'Could not add department. Please check for duplicate name or code.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'update_department') {
            $department_id = intval($_POST['department_id'] ?? 0);
            $department_name = substr(trim(strval($_POST['department_name'] ?? '')), 0, 120);
            $department_code = strtoupper(substr(trim(strval($_POST['department_code'] ?? '')), 0, 20));

            if ($department_id <= 0 || $department_name === '') {
                $error_msg = 'Department name is required.';
            } else {
                $stmt = $conn->prepare("UPDATE departments SET department_name = ?, department_code = NULLIF(?, '') WHERE department_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ssi', $department_name, $department_code, $department_id);
                    if ($stmt->execute()) {
                        $success_msg = 'Department updated successfully.';
                    } else {
                        $error_msg = 'Could not update department. Please check for duplicate name or code.';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'toggle_department') {
            $department_id = intval($_POST['department_id'] ?? 0);
            if ($department_id > 0) {
                $stmt = $conn->prepare("UPDATE departments SET is_active = NOT is_active WHERE department_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $department_id);
                    $stmt->execute();
                    $stmt->close();
                    $success_msg = 'Department status updated.';
                }
            }
        } elseif ($action === 'merge_department') {
            $source_department_id = intval($_POST['source_department_id'] ?? 0);
            $target_department_id = intval($_POST['target_department_id'] ?? 0);

            if ($source_department_id <= 0 || $target_department_id <= 0) {
                $error_msg = 'Choose both the duplicate department and the department to keep.';
            } elseif ($source_department_id === $target_department_id) {
                $error_msg = 'The source and target departments must be different.';
            } else {
                $lookup = $conn->prepare("SELECT department_id, department_name, department_code
                    FROM departments
                    WHERE department_id IN (?, ?)
                    ORDER BY department_id ASC");
                $found_departments = [];
                if ($lookup) {
                    $lookup->bind_param('ii', $source_department_id, $target_department_id);
                    $lookup->execute();
                    $lookup_result = $lookup->get_result();
                    if ($lookup_result) {
                        while ($lookup_row = $lookup_result->fetch_assoc()) {
                            $found_departments[intval($lookup_row['department_id'] ?? 0)] = $lookup_row;
                        }
                    }
                    $lookup->close();
                }

                if (!isset($found_departments[$source_department_id], $found_departments[$target_department_id])) {
                    $error_msg = 'One of the selected departments could not be found.';
                } else {
                    $target_department_name = trim(strval($found_departments[$target_department_id]['department_name'] ?? ''));
                    $conn->begin_transaction();
                    try {
                        $move_admins = $conn->prepare("UPDATE admins
                            SET department_id = ?, program_name = CASE
                                WHEN role = 'rep' THEN ?
                                ELSE program_name
                            END
                            WHERE department_id = ?");
                        if ($move_admins) {
                            $move_admins->bind_param('isi', $target_department_id, $target_department_name, $source_department_id);
                            $move_admins->execute();
                            $move_admins->close();
                        }

                        $move_signups = $conn->prepare("UPDATE rep_signup_requests
                            SET department_id = ?
                            WHERE department_id = ?");
                        if ($move_signups) {
                            $move_signups->bind_param('ii', $target_department_id, $source_department_id);
                            $move_signups->execute();
                            $move_signups->close();
                        }

                        $delete_source = $conn->prepare("DELETE FROM departments WHERE department_id = ? LIMIT 1");
                        if (!$delete_source) {
                            throw new RuntimeException('Could not prepare the department merge cleanup.');
                        }
                        $delete_source->bind_param('i', $source_department_id);
                        $delete_source->execute();
                        $delete_source->close();

                        $conn->commit();
                        $success_msg = 'Department merged successfully. All linked reps and signup requests now point to the kept department.';
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error_msg = 'Could not merge the selected departments. Please try again.';
                    }
                }
            }
        }
    }
}

$department_rows = [];
$department_result = $conn->query("SELECT d.*,
    (SELECT COUNT(*) FROM admins a WHERE a.role = 'rep' AND a.department_id = d.department_id) AS rep_count
    FROM departments d
    ORDER BY d.is_active DESC, d.department_name ASC");
if ($department_result) {
    while ($department_row = $department_result->fetch_assoc()) {
        $department_rows[] = $department_row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Departments</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e9eef7 100%);
            min-height: 100vh;
            padding: 28px 18px 36px;
            color: #172235;
        }
        .page { max-width: 1120px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #5f6ee5 0%, #7b4aa6 100%);
            color: #fff;
            border-radius: 22px;
            padding: 24px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            box-shadow: 0 20px 44px rgba(95, 110, 229, 0.24);
            margin-bottom: 22px;
        }
        .header h1 { font-size: 28px; margin-bottom: 4px; }
        .header p { font-size: 14px; opacity: 0.9; }
        .back-btn {
            text-decoration: none;
            color: #fff;
            background: rgba(255,255,255,0.16);
            border: 1px solid rgba(255,255,255,0.26);
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 600;
        }
        .alerts { display: grid; gap: 12px; margin-bottom: 18px; }
        .alert {
            border-radius: 16px;
            padding: 14px 16px;
            font-weight: 600;
        }
        .alert-success { background: #eafaf1; color: #166534; border: 1px solid #c7f0d7; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .grid { display: grid; grid-template-columns: 340px 1fr; gap: 20px; }
        .card {
            background: rgba(255,255,255,0.97);
            border: 1px solid #dce4f2;
            border-radius: 20px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
            padding: 22px;
        }
        .card h2 { font-size: 20px; margin-bottom: 8px; }
        .card p { color: #64748b; font-size: 14px; margin-bottom: 16px; }
        .form-grid { display: grid; gap: 14px; }
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
        }
        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d9e2ef;
            border-radius: 14px;
            font-size: 15px;
            background: #fcfdff;
        }
        select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d9e2ef;
            border-radius: 14px;
            font-size: 15px;
            background: #fcfdff;
            color: #172235;
        }
        input:focus {
            outline: none;
            border-color: #7c8ef2;
            box-shadow: 0 0 0 4px rgba(124, 142, 242, 0.12);
        }
        select:focus {
            outline: none;
            border-color: #7c8ef2;
            box-shadow: 0 0 0 4px rgba(124, 142, 242, 0.12);
        }
        .btn {
            border: none;
            border-radius: 14px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-primary { background: linear-gradient(135deg, #5f6ee5 0%, #7b4aa6 100%); color: #fff; }
        .btn-warning { background: #fff7ed; color: #9a3412; }
        .btn-toggle { background: #eef2ff; color: #1d4ed8; }
        .btn-danger { background: #fee2e2; color: #991b1b; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 780px; }
        th, td { padding: 14px 12px; border-bottom: 1px solid #edf1f7; text-align: left; vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
        td form { display: inline-block; margin-right: 8px; }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .inline-form {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(130px, 180px) auto;
            gap: 10px;
            align-items: end;
        }
        .inline-field {
            display: grid;
            gap: 6px;
            min-width: 0;
        }
        .inline-field label {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .inline-field input {
            min-width: 0;
            padding: 11px 12px;
            font-size: 14px;
        }
        .muted { color: #64748b; font-size: 13px; }
        .merge-panel {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid #e5ebf5;
        }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
            .inline-form { grid-template-columns: 1fr; }
            table { min-width: 0; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <h1>Manage Departments</h1>
            <p>Create the department list reps will use during signup and class setup.</p>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back</a>
    </div>

    <div class="alerts">
        <?php if ($success_msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
        <?php if ($error_msg): ?><div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Add Department</h2>
            <p>Every rep will choose from this list during onboarding and when their account is edited.</p>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="create_department">
                <div>
                    <label>Department Name *</label>
                    <input type="text" name="department_name" placeholder="e.g. Information Technology Education" required>
                </div>
                <div>
                    <label>Department Code</label>
                    <input type="text" name="department_code" placeholder="e.g. ITE">
                </div>
                <button type="submit" class="btn btn-primary">Add Department</button>
            </form>

            <div class="merge-panel">
                <h2>Merge Duplicate Departments</h2>
                <p>Use this when two department entries represent the same department. Reps and pending signup requests will be moved to the department you keep.</p>
                <form method="post" class="form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="merge_department">
                    <div>
                        <label>Duplicate Department *</label>
                        <select name="source_department_id" required>
                            <option value="">Choose duplicate department</option>
                            <?php foreach ($department_rows as $department): ?>
                                <?php
                                $department_label = trim(strval($department['department_name'] ?? ''));
                                $department_code = trim(strval($department['department_code'] ?? ''));
                                if ($department_code !== '') {
                                    $department_label .= ' (' . $department_code . ')';
                                }
                                ?>
                                <option value="<?php echo intval($department['department_id']); ?>"><?php echo htmlspecialchars($department_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Department To Keep *</label>
                        <select name="target_department_id" required>
                            <option value="">Choose department to keep</option>
                            <?php foreach ($department_rows as $department): ?>
                                <?php
                                $department_label = trim(strval($department['department_name'] ?? ''));
                                $department_code = trim(strval($department['department_code'] ?? ''));
                                if ($department_code !== '') {
                                    $department_label .= ' (' . $department_code . ')';
                                }
                                ?>
                                <option value="<?php echo intval($department['department_id']); ?>"><?php echo htmlspecialchars($department_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger">Merge Departments</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>Department Directory</h2>
            <p>Use this list to keep the signup dropdown current. Inactive departments stay hidden from new signup requests.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Department</th>
                        <th>Code</th>
                        <th>Reps</th>
                        <th>Status</th>
                        <th>Update</th>
                        <th>Visibility</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($department_rows)): ?>
                        <?php foreach ($department_rows as $department): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($department['department_name']); ?></strong>
                                    <div class="muted">Created <?php echo htmlspecialchars(date('M d, Y', strtotime($department['created_at']))); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($department['department_code'] ?: '-'); ?></td>
                                <td><?php echo intval($department['rep_count'] ?? 0); ?></td>
                                <td>
                                    <span class="badge <?php echo !empty($department['is_active']) ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo !empty($department['is_active']) ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="update_department">
                                        <input type="hidden" name="department_id" value="<?php echo intval($department['department_id']); ?>">
                                        <div class="inline-field">
                                            <label>Department Name</label>
                                            <input type="text" name="department_name" value="<?php echo htmlspecialchars($department['department_name']); ?>" placeholder="Department name" required>
                                        </div>
                                        <div class="inline-field">
                                            <label>Code</label>
                                            <input type="text" name="department_code" value="<?php echo htmlspecialchars($department['department_code'] ?: ''); ?>" placeholder="Code">
                                        </div>
                                        <button type="submit" class="btn btn-warning">Save</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="toggle_department">
                                        <input type="hidden" name="department_id" value="<?php echo intval($department['department_id']); ?>">
                                        <button type="submit" class="btn btn-toggle">
                                            <?php echo !empty($department['is_active']) ? 'Hide from Signup' : 'Make Active'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="muted">No departments added yet.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
