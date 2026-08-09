<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
error_reporting(0);
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_level', 'VARCHAR(10) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_levels', 'TEXT NULL AFTER teaching_level');
        book_system_setup_ensure_column($conn, 'lecturers', 'course_codes', 'TEXT NULL AFTER teaching_levels');
        book_system_setup_ensure_column($conn, 'lecturers', 'phone_number', 'VARCHAR(20) NULL AFTER full_name');
    }
}

if (isset($_SESSION['lecturer_logged_in']) && intval($_SESSION['lecturer_logged_in']) === 1) {
    header('Location: lecturer_dashboard.php');
    exit;
}

$error = '';
$success = '';
$csrf_token = csrf_get_token();
$teaching_level_options = function_exists('book_system_get_teaching_level_options')
    ? book_system_get_teaching_level_options()
    : ['100', '200', '300', '400'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $username = substr(trim(strval($_POST['username'] ?? '')), 0, 50);
        $full_name = substr(trim(strval($_POST['full_name'] ?? '')), 0, 100);
        $phone_number = trim(strval($_POST['phone_number'] ?? ''));
        $phone_e164 = '';
        if ($phone_number !== '') {
            $phone_e164 = function_exists('book_system_normalize_ghana_phone')
                ? book_system_normalize_ghana_phone($phone_number)
                : '';
        }
        $selected_level_inputs = $_POST['teaching_levels'] ?? [];
        if (!is_array($selected_level_inputs)) {
            $selected_level_inputs = [];
        }
        $selected_levels = [];
        foreach ($selected_level_inputs as $level_input) {
            $normalized_level = function_exists('book_system_normalize_teaching_level')
                ? book_system_normalize_teaching_level(strval($level_input))
                : preg_replace('/[^0-9]/', '', strval($level_input));
            if ($normalized_level !== '' && in_array($normalized_level, $teaching_level_options, true) && !in_array($normalized_level, $selected_levels, true)) {
                $selected_levels[] = $normalized_level;
            }
        }
        $teaching_level = $selected_levels[0] ?? '';
        $course_codes_input = trim(strval($_POST['course_codes'] ?? ''));
        $course_codes = [];
        $course_code_lines = preg_split('/[\r\n,]+/', $course_codes_input) ?: [];
        foreach ($course_code_lines as $course_code_line) {
            $course_code_line = trim(strval($course_code_line));
            if ($course_code_line === '') {
                continue;
            }
            $course_code_key = function_exists('book_system_normalize_course_code')
                ? book_system_normalize_course_code($course_code_line)
                : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $course_code_line));
            if ($course_code_key === '') {
                continue;
            }
            $course_code_label = strtoupper(preg_replace('/\s+/', ' ', $course_code_line));
            if (!isset($course_codes[$course_code_key])) {
                $course_codes[$course_code_key] = $course_code_label;
            }
        }
        $password = strval($_POST['password'] ?? '');
        $confirm_password = strval($_POST['confirm_password'] ?? '');

        if ($username === '' || $full_name === '' || $password === '' || $teaching_level === '') {
            $error = 'All fields are required.';
        } elseif (empty($selected_levels)) {
            $error = 'Select at least one teaching level.';
        } elseif (empty($course_codes)) {
            $error = 'Enter at least one course code.';
        } elseif ($phone_number !== '' && $phone_e164 === '') {
            $error = 'Enter a valid Ghana phone number.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $error = 'Username must be 3-50 characters and contain only letters, numbers, dot, underscore, or dash.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            // Prevent collisions with admin usernames too
            $exists = false;

            $stmt = $conn->prepare("SELECT 1 FROM lecturers WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = ($res && $res->num_rows === 1);
            }

            if (!$exists) {
                $stmt = $conn->prepare("SELECT 1 FROM admins WHERE username = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $exists = ($res && $res->num_rows === 1);
                }
            }

            if ($exists) {
                $error = 'Username already exists. Please choose another.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $teaching_levels_json = json_encode(array_values($selected_levels), JSON_UNESCAPED_UNICODE);
                $course_codes_json = json_encode(array_values($course_codes), JSON_UNESCAPED_UNICODE);

                // Require activation by default
                $is_active = 0;
                $phone_to_store = $phone_e164 !== '' ? $phone_e164 : null;
                $stmt = $conn->prepare("INSERT INTO lecturers (username, password_hash, full_name, phone_number, teaching_level, teaching_levels, course_codes, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('sssssssi', $username, $hash, $full_name, $phone_to_store, $teaching_level, $teaching_levels_json, $course_codes_json, $is_active);
                    if ($stmt->execute()) {
                        $lecturer_id = intval($stmt->insert_id ?? 0);
                        $stmt->close();

                        if ($lecturer_id > 0) {
                            $material_stmt = $conn->prepare("INSERT IGNORE INTO lecturer_materials (lecturer_id, material_title, course_code, course_code_key, academic_level) VALUES (?, ?, ?, ?, ?)");
                            if ($material_stmt) {
                                foreach ($selected_levels as $level_option) {
                                    foreach ($course_codes as $course_code_key => $course_code_label) {
                                        $material_title = $course_code_label;
                                        $course_code = $course_code_label;
                                        $academic_level = $level_option;
                                        $material_stmt->bind_param('issss', $lecturer_id, $material_title, $course_code, $course_code_key, $academic_level);
                                        $material_stmt->execute();
                                    }
                                }
                                $material_stmt->close();
                            }
                        }
                        header('Location: lecturer_login.php?signed_up=1');
                        exit;
                    } else {
                        $error = 'Failed to create account. Please try again.';
                    }
                } else {
                    $error = 'Database error. Please try again.';
                }
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
    <title>Lecturer Sign Up</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body, html { height: 100%; }

        .wrapper {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .card {
            background: white;
            padding: 42px 40px;
            width: 100%;
            max-width: 480px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
        }

        .header { text-align: center; margin-bottom: 22px; }
        .header .icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 30px;
            color: white;
        }
        .header h2 { font-size: 24px; color: #111827; font-weight: 800; }
        .header p { color: #6b7280; font-size: 13px; margin-top: 8px; }

        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; text-align: center; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 700; color: #374151; margin-bottom: 8px; font-size: 13px; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            background: #f9fafb;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #111827; background: white; }
        .level-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .level-option {
            border: 1px solid #dbe4f0;
            background: #f9fbff;
            border-radius: 14px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
        }
        .level-option input {
            width: 18px;
            height: 18px;
            accent-color: #111827;
            flex-shrink: 0;
        }
        .field-hint {
            margin-top: 8px;
            font-size: 12px;
            color: #6b7280;
            line-height: 1.45;
        }
        @media (max-width: 420px) {
            .level-grid {
                grid-template-columns: 1fr;
            }
        }

        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            color: white;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            margin-top: 8px;
        }
        .btn:hover { opacity: 0.95; }

        .footer { text-align: center; margin-top: 18px; color: #9ca3af; font-size: 13px; }
        .footer a { color: #111827; text-decoration: none; font-weight: 700; }
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .hint {
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            color: #374151;
            padding: 10px 12px;
            border-radius: 10px;
            margin-top: 12px;
            font-size: 12px;
            line-height: 1.4;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="icon">&#127891;</div>
            <h2>Lecturer Sign Up</h2>
            <p>Create your lecturer account</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" required value="<?php echo htmlspecialchars(strval($_POST['full_name'] ?? '')); ?>">
            </div>

            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" required value="<?php echo htmlspecialchars(strval($_POST['username'] ?? '')); ?>">
            </div>

            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone_number" placeholder="Optional, e.g. 0244123456" value="<?php echo htmlspecialchars(strval($_POST['phone_number'] ?? '')); ?>">
            </div>

            <div class="form-group">
                <label>Teaching Levels *</label>
                <div class="level-grid">
                    <?php foreach ($teaching_level_options as $level_option): ?>
                        <?php $checked = in_array($level_option, array_map('strval', $_POST['teaching_levels'] ?? []), true); ?>
                        <label class="level-option">
                            <input type="checkbox" name="teaching_levels[]" value="<?php echo htmlspecialchars($level_option); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                            <span>Level <?php echo htmlspecialchars($level_option); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="field-hint">Choose all the levels you currently teach.</div>
            </div>

            <div class="form-group">
                <label>Course Codes *</label>
                <textarea name="course_codes" rows="4" class="form-input" placeholder="Enter one course code per line, e.g.&#10;ICT 354&#10;ITE 312&#10;DBMS 301"><?php echo htmlspecialchars(strval($_POST['course_codes'] ?? '')); ?></textarea>
                <div class="field-hint">These course codes help the system connect your dashboard to rep records that use the same course codes.</div>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required minlength="6">
            </div>

            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" required minlength="6">
            </div>

            <button type="submit" class="btn">Create Account</button>

            <div class="hint">
                After sign-up, your account will be pending activation. Please contact the administrator to activate your account.
            </div>
        </form>

        <div class="footer">
            Already have an account? <a href="lecturer_login.php">Sign In</a>
            <div class="footer-links">
                <a href="common_request_portal.php">Portal</a>
                <a href="login.php">Admin Login</a>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>

