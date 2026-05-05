<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_ensure_column($conn, 'admins', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
    }
}

$success_msg = '';
$error_msg = '';

$super_admin = null;
$super = $conn->query("SELECT admin_id, full_name, momo_number, bank_name, account_name, account_number FROM admins WHERE role = 'super_admin' AND is_active = 1 ORDER BY admin_id ASC LIMIT 1");
if ($super && $super->num_rows === 1) {
    $super_admin = $super->fetch_assoc();
}

$fallback_momo_number = '0549090433';
$fallback_account_name = 'Roland Kitsi';
$pay_to_full_name = $super_admin ? (strval($super_admin['full_name'] ?? '') !== '' ? $super_admin['full_name'] : $fallback_account_name) : $fallback_account_name;
$pay_to_momo_number = $super_admin ? (strval($super_admin['momo_number'] ?? '') !== '' ? $super_admin['momo_number'] : $fallback_momo_number) : $fallback_momo_number;
$pay_to_account_name = $super_admin ? (strval($super_admin['account_name'] ?? '') !== '' ? $super_admin['account_name'] : $fallback_account_name) : $fallback_account_name;

$csrf_token = csrf_get_token();
$form_username = trim($_POST['username'] ?? '');
$form_full_name = trim($_POST['full_name'] ?? '');
$form_public_display_name = trim($_POST['public_display_name'] ?? '');
$form_class_name = trim($_POST['class_name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $username = substr(trim($_POST['username'] ?? ''), 0, 50);
        $full_name = substr(trim($_POST['full_name'] ?? ''), 0, 30);
        $public_display_name = substr(trim($_POST['public_display_name'] ?? ''), 0, 50);
        $class_name = substr(trim($_POST['class_name'] ?? ''), 0, 30);

        if ($username === '' || $full_name === '') {
            $error_msg = 'Username and full name are required.';
        } elseif (!isset($_FILES['profile_photo']) || intval($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error_msg = 'A profile picture is required.';
        } else {
            $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? LIMIT 1");
            $check->bind_param('s', $username);
            $check->execute();
            $exists_admin = $check->get_result()->num_rows > 0;

            $check2 = $conn->prepare("SELECT signup_id FROM rep_signup_requests WHERE username = ? LIMIT 1");
            $check2->bind_param('s', $username);
            $check2->execute();
            $exists_signup = $check2->get_result()->num_rows > 0;

            if ($exists_admin) {
                $error_msg = "Username already exists. Please choose a different username.";
            } elseif ($exists_signup) {
                $error_msg = "A signup request with this username already exists. Please wait for approval or contact the super admin.";
            } else {
                $upload_result = function_exists('book_system_store_profile_photo_upload')
                    ? book_system_store_profile_photo_upload($_FILES['profile_photo'], 'rep_signup')
                    : ['success' => false, 'message' => 'Profile upload helper is unavailable.'];

                if (empty($upload_result['success'])) {
                    $error_msg = strval($upload_result['message'] ?? 'Could not upload the profile picture.');
                } else {
                    $profile_photo_path = strval($upload_result['path'] ?? '');
                    $stmt = $conn->prepare("INSERT INTO rep_signup_requests (username, full_name, public_display_name, profile_photo_path, class_name, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                    $stmt->bind_param('sssss', $username, $full_name, $public_display_name, $profile_photo_path, $class_name);
                    if ($stmt->execute()) {
                        $success_msg = "Signup request submitted successfully. Please make payment to the super admin and wait for approval.";
                    } else {
                        if ($profile_photo_path !== '' && function_exists('book_system_delete_profile_photo')) {
                            book_system_delete_profile_photo($profile_photo_path);
                        }
                        $error_msg = "Failed to submit signup request. Please try again.";
                    }
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
    <title>Rep Sign Up</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --surface: rgba(255,255,255,0.97);
            --surface-strong: #ffffff;
            --border: rgba(148, 163, 184, 0.18);
            --text: #0f172a;
            --muted: #64748b;
            --primary-a: #5166d8;
            --primary-b: #7351b6;
            --shadow: 0 22px 55px rgba(15, 23, 42, 0.08);
            --radius-xl: 26px;
            --radius-lg: 18px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(920px 520px at 10% 0%, rgba(81,102,216,0.10), transparent 50%),
                radial-gradient(760px 440px at 100% 10%, rgba(115,81,182,0.08), transparent 46%),
                linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
            min-height: 100vh;
            padding: 28px 18px 36px;
        }
        .container { max-width: 1120px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            color: white;
            padding: 24px 28px;
            border-radius: 26px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 22px 50px rgba(91, 110, 225, 0.24);
            position: relative;
            overflow: hidden;
            gap: 18px;
        }
        .header:before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255,255,255,0.12);
            top: -150px;
            right: -110px;
        }
        .header:after {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 34px;
            background: rgba(255,255,255,0.08);
            bottom: -155px;
            left: -90px;
            transform: rotate(22deg);
        }
        .header-copy { position: relative; z-index: 1; max-width: 620px; }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            margin-bottom: 12px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
            border: 1px solid rgba(255,255,255,0.24);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .header h1 { font-size: 31px; line-height: 1.08; font-weight: 800; letter-spacing: -0.03em; }
        .header .subtitle {
            opacity: 0.95;
            margin-top: 10px;
            font-size: 14px;
            line-height: 1.7;
            max-width: 560px;
        }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 11px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
            position: relative;
            z-index: 1;
            white-space: nowrap;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        .shell {
            display: grid;
            grid-template-columns: minmax(0, 1.06fr) minmax(320px, 0.94fr);
            gap: 20px;
            align-items: start;
        }
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }
        .main-panel {
            padding: 28px 28px 24px;
        }
        .side-panel {
            padding: 20px;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-size: 14px;
            border-left: 4px solid;
        }
        .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left-color: #f44336; }
        .panel-kicker {
            font-size: 12px;
            font-weight: 800;
            color: var(--primary-a);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .panel-title {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--text);
            margin-bottom: 10px;
        }
        .panel-copy {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 24px;
            max-width: 58ch;
        }
        .form-grid {
            display: grid;
            gap: 16px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-group { margin-bottom: 0; }
        label {
            display: block;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
            font-size: 13px;
            letter-spacing: 0.01em;
        }
        input {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            font-size: 15px;
            background: #fcfdff;
            color: var(--text);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        input:focus {
            outline: none;
            border-color: #7c8ef2;
            background: white;
            box-shadow: 0 0 0 4px rgba(91,110,225,0.10);
        }
        input::placeholder { color: #94a3b8; }
        .input-note {
            margin-top: 7px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .photo-section {
            display: grid;
            gap: 14px;
            padding: 18px;
            border: 1px solid #dbe3ef;
            border-radius: 18px;
            background: #f8fbff;
        }
        .photo-row {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }
        .photo-avatar {
            width: 104px;
            height: 104px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid rgba(81,102,216,0.14);
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5166d8;
            font-size: 34px;
            font-weight: 800;
            flex-shrink: 0;
            background-size: cover;
            background-position: center;
        }
        .photo-avatar.has-image {
            color: transparent;
        }
        .photo-meta {
            flex: 1;
            min-width: 220px;
        }
        .photo-meta strong {
            display: block;
            font-size: 15px;
            margin-bottom: 6px;
            color: var(--text);
        }
        .photo-meta span {
            display: block;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .photo-input {
            display: block;
            width: 100%;
            padding: 12px 14px;
            border: 1px dashed #b8c4d8;
            border-radius: 14px;
            background: white;
            color: var(--text);
        }
        .submit-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .btn {
            min-width: 220px;
            padding: 14px 18px;
            border: none;
            border-radius: 14px;
            font-weight: 800;
            cursor: pointer;
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            color: white;
            font-size: 15px;
            letter-spacing: 0.02em;
            box-shadow: 0 14px 28px rgba(91,110,225,0.20);
        }
        .btn:hover { opacity: 0.95; }
        .muted { color: #7a7a7a; font-size: 13px; line-height: 1.5; }
        .quick-links {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .trust-text {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .primary-link {
            color: var(--primary-a);
            text-decoration: none;
            font-weight: 800;
        }
        .primary-link:hover { text-decoration: underline; }
        .stack {
            display: grid;
            gap: 18px;
        }
        .side-card {
            background: var(--surface-strong);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 22px;
        }
        .side-card h3 {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 8px;
            color: var(--text);
        }
        .side-copy {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }
        .steps {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }
        .step {
            display: flex;
            gap: 14px;
            padding: 14px;
            border-radius: 16px;
            background: #f8faff;
            border: 1px solid rgba(99,102,241,0.10);
        }
        .step .num {
            width: 32px;
            height: 32px;
            border-radius: 11px;
            display: grid;
            place-items: center;
            font-weight: 900;
            color: var(--primary-a);
            background: rgba(91,110,225,0.14);
            flex-shrink: 0;
        }
        .step .title { font-weight: 900; color: #1e293b; font-size: 13px; margin-bottom: 4px; }
        .step .desc { color: #64748b; font-size: 12.5px; line-height: 1.5; }
        .payment-card {
            border-radius: 20px;
            padding: 18px;
            background: linear-gradient(180deg, rgba(255,255,255,0.92) 0%, rgba(240,249,255,0.92) 100%);
            border: 1px solid rgba(15,157,118,0.18);
            margin-top: 16px;
        }
        .pay-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.06);
            margin-bottom: 10px;
        }
        .pay-row .label { color: #64748b; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; }
        .pay-row .value { color: #0f172a; font-weight: 900; font-size: 14px; text-align: right; }
        .copy-btn {
            margin-top: 8px;
            width: 100%;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #0f172a;
            color: white;
            padding: 11px 12px;
            border-radius: 14px;
            font-weight: 900;
            cursor: pointer;
        }
        .copy-btn:hover { opacity: 0.95; }
        .micro-note {
            margin-top: 12px;
            color: var(--muted);
            font-size: 12.5px;
            line-height: 1.6;
        }
        @media (max-width: 940px) {
            .shell { grid-template-columns: 1fr; }
            .container { max-width: 760px; }
        }
        @media (max-width: 640px) {
            body { padding: 18px 12px 24px; }
            .header {
                flex-direction: column;
                align-items: flex-start;
                padding: 22px 20px;
            }
            .header h1 { font-size: 26px; }
            .main-panel, .side-panel { padding: 20px 18px; }
            .form-row { grid-template-columns: 1fr; }
            .panel-title { font-size: 24px; }
            .submit-wrap { align-items: stretch; }
            .btn { width: 100%; min-width: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-copy">
            <div class="eyebrow">Rep Enrollment</div>
            <h1>Join the platform as a class rep.</h1>
            <p class="subtitle">Send your signup request, complete the onboarding payment, and wait for approval so you can set your password and start using the system.</p>
        </div>
        <a href="login.php" class="back-btn">&larr; Back</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="shell">
        <div class="panel main-panel">
            <div class="panel-kicker">Sign Up Form</div>
            <h2 class="panel-title">Create your rep request</h2>
            <p class="panel-copy">Use a clear username and your full name exactly as you want it to appear on the system. Once approved, the same username will be used for your first login and password setup.</p>

            <div class="form-grid">
                <form method="post" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <label>Profile Picture *</label>
                        <div class="photo-section">
                            <div class="photo-row">
                                <div class="photo-avatar" id="signupPhotoPreview">RK</div>
                                <div class="photo-meta">
                                    <strong>Profile picture</strong>
                                </div>
                            </div>
                            <input type="file" name="profile_photo" id="profilePhotoInput" class="photo-input" accept="image/png,image/jpeg,image/webp" required>
                            <div class="input-note">Accepted formats: JPG, PNG, or WEBP. Maximum file size: 3 MB.</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required placeholder="e.g. Roland Kitsi" value="<?php echo htmlspecialchars($form_full_name); ?>">
                        <div class="input-note">Use your real full name for approval and payment confirmation.</div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" required placeholder="e.g. rep_roland" value="<?php echo htmlspecialchars($form_username); ?>">
                            <div class="input-note">This should be easy to remember and unique to you.</div>
                        </div>
                        <div class="form-group">
                            <label>Class Name</label>
                            <input type="text" name="class_name" placeholder="e.g. ITE 3A" value="<?php echo htmlspecialchars($form_class_name); ?>">
                            <div class="input-note">Enter the class you will be managing on the platform.</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Name for Common Request Page</label>
                        <input type="text" name="public_display_name" placeholder="e.g. Roland Kitsi" value="<?php echo htmlspecialchars($form_public_display_name); ?>">
                        <div class="input-note">Optional. This is the name students will see on the common request page. Leave it blank to use your full name.</div>
                    </div>
                    <div class="submit-wrap">
                        <button type="submit" class="btn">Submit Signup Request</button>
                        <div class="trust-text">After payment is confirmed, the super admin will approve your request and issue your 4-digit first-time code.</div>
                    </div>
                </form>
            </div>

            <div class="quick-links">
                <div class="trust-text">Already approved? Move straight to password setup using your first-time code.</div>
                <a class="primary-link" href="rep_first_time_reset.php">Go to First-Time Code Reset &rarr;</a>
            </div>
        </div>

        <div class="panel side-panel">
            <div class="stack">
                <div class="side-card">
                    <h3>Onboarding steps</h3>
                    <div class="side-copy">The process is short. Complete each step once and you will be ready to access your rep workspace.</div>
                    <div class="steps">
                        <div class="step">
                            <div class="num">1</div>
                            <div>
                                <div class="title">Submit your request</div>
                                <div class="desc">Send your username, full name, and class details from this page.</div>
                            </div>
                        </div>
                        <div class="step">
                            <div class="num">2</div>
                            <div>
                                <div class="title">Complete payment</div>
                                <div class="desc">Pay to the MoMo account shown here and use your username as the payment reference if needed.</div>
                            </div>
                        </div>
                        <div class="step">
                            <div class="num">3</div>
                            <div>
                                <div class="title">Wait for approval</div>
                                <div class="desc">Once payment is confirmed, you receive your 4-digit code and can set your password.</div>
                            </div>
                        </div>
                    </div>

                    <div class="payment-card">
                        <h3 style="margin-bottom: 8px;">Payment details</h3>
                        <div class="side-copy" style="margin-bottom: 14px;">Use these MoMo details for your onboarding payment. Keep your payment simple and consistent with your signup information.</div>
                        <div class="pay-row">
                            <div class="label">Account Name</div>
                            <div class="value"><?php echo htmlspecialchars($pay_to_account_name); ?></div>
                        </div>
                        <div class="pay-row">
                            <div class="label">MoMo Number</div>
                            <div class="value" id="momoNumber"><?php echo htmlspecialchars($pay_to_momo_number); ?></div>
                        </div>
                        <div class="pay-row" style="margin-bottom: 0;">
                            <div class="label">Reference</div>
                            <div class="value">Use your username</div>
                        </div>
                        <button type="button" class="copy-btn" onclick="copyMomo()">Copy MoMo Number</button>
                        <div class="micro-note">Once your payment is confirmed, your request can be approved and your first-time code can be issued.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyMomo() {
    var el = document.getElementById('momoNumber');
    var text = el ? (el.textContent || '').trim() : '';
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}

(function () {
    const input = document.getElementById('profilePhotoInput');
    const preview = document.getElementById('signupPhotoPreview');
    if (!input || !preview) return;

    input.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) {
            preview.style.backgroundImage = '';
            preview.classList.remove('has-image');
            preview.textContent = 'RK';
            return;
        }
        const reader = new FileReader();
        reader.onload = function (event) {
            const result = event.target && event.target.result ? String(event.target.result) : '';
            if (result !== '') {
                preview.style.backgroundImage = 'url(' + result.replace(/"/g, '&quot;') + ')';
                preview.classList.add('has-image');
                preview.textContent = '';
            }
        };
        reader.readAsDataURL(file);
    });
})();
</script>

<?php include 'footer.php'; ?>
</body>
</html>

