<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'trial_started_at', 'DATETIME NULL AFTER approved_at');
        book_system_setup_ensure_column($conn, 'admins', 'trial_expires_at', 'DATETIME NULL AFTER trial_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_active', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER trial_expires_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_started_at', 'DATETIME NULL AFTER subscription_active');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_expires_at', 'DATETIME NULL AFTER subscription_started_at');
    }
}

if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'rep') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: rep_subscription.php?msg=csrf_invalid');
        exit;
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

$csrf_token = csrf_get_token();
$admin_id = intval($_SESSION['admin_id'] ?? 0);
$rep_name = strval($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Rep');
$rep_class = strval($_SESSION['admin_class_name'] ?? '');
$rep_access_status = function_exists('book_system_get_rep_access_status')
    ? book_system_get_rep_access_status($conn, $admin_id)
    : [];

if (!empty($rep_access_status['can_access'])) {
    header('Location: rep_dashboard.php');
    exit;
}

$super_admin = null;
$super = $conn->query("SELECT admin_id, full_name, momo_number, account_name FROM admins WHERE role = 'super_admin' AND is_active = 1 ORDER BY admin_id ASC LIMIT 1");
if ($super && $super->num_rows === 1) {
    $super_admin = $super->fetch_assoc();
}

$fallback_momo_number = '0549090433';
$fallback_account_name = 'Roland Kitsi';
$pay_to_account_name = $super_admin ? (strval($super_admin['account_name'] ?? '') !== '' ? strval($super_admin['account_name']) : $fallback_account_name) : $fallback_account_name;
$pay_to_momo_number = $super_admin ? (strval($super_admin['momo_number'] ?? '') !== '' ? strval($super_admin['momo_number']) : $fallback_momo_number) : $fallback_momo_number;
$trial_expiry_label = '';
if (!empty($rep_access_status['trial_expires_at'])) {
    $trial_expiry_label = date('M d, Y', strtotime(strval($rep_access_status['trial_expires_at'])));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Required</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --surface: #ffffff;
            --border: rgba(148, 163, 184, 0.18);
            --text: #0f172a;
            --muted: #64748b;
            --primary-a: #5b6ee1;
            --primary-b: #7c4dbe;
            --warning-a: #fff7e6;
            --warning-b: #ffe8b5;
            --warning-text: #8a5a08;
            --shadow: 0 24px 50px rgba(15, 23, 42, 0.08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(900px 540px at 10% 0%, rgba(91,110,225,0.12), transparent 52%),
                radial-gradient(760px 500px at 100% 12%, rgba(124,77,190,0.10), transparent 48%),
                linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
            padding: 28px 18px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .hero {
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            color: white;
            border-radius: 28px;
            padding: 28px 30px;
            box-shadow: 0 24px 50px rgba(91, 110, 225, 0.24);
            margin-bottom: 22px;
        }
        .hero h1 {
            font-size: 30px;
            line-height: 1.08;
            letter-spacing: -0.03em;
            font-weight: 800;
        }
        .hero p {
            margin-top: 10px;
            font-size: 14px;
            line-height: 1.7;
            max-width: 620px;
            opacity: 0.96;
        }
        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 20px;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 24px;
        }
        .card h2 {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 10px;
        }
        .copy {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
        }
        .warning {
            background: linear-gradient(135deg, var(--warning-a) 0%, var(--warning-b) 100%);
            color: var(--warning-text);
            border: 1px solid rgba(217, 119, 6, 0.20);
            border-radius: 18px;
            padding: 16px 18px;
            margin: 16px 0 18px;
        }
        .warning strong {
            display: block;
            font-size: 15px;
            margin-bottom: 4px;
        }
        .detail-list {
            display: grid;
            gap: 10px;
            margin-top: 18px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .detail-row .label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .detail-row .value {
            color: var(--text);
            font-weight: 800;
            font-size: 14px;
            text-align: right;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 22px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 14px;
            border: none;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            color: white;
        }
        .btn-muted {
            background: #eef2ff;
            color: #334155;
        }
        .note {
            margin-top: 14px;
            color: var(--muted);
            font-size: 12.5px;
            line-height: 1.7;
        }
        @media (max-width: 820px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="hero">
        <h1>Subscription required to continue.</h1>
        <p>Your 7-day free trial has ended, so access to the rep workspace is currently paused. Once your subscription is activated, your dashboard and rep tools will open again immediately.</p>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Account status</h2>
            <div class="copy">We’ve kept your account and records safe. You only need subscription activation to continue using the rep side of the system.</div>

            <div class="warning">
                <strong>Trial expired</strong>
                <?php echo htmlspecialchars(strval($rep_access_status['reminder_message'] ?? 'Your free trial has expired.')); ?>
            </div>

            <div class="detail-list">
                <div class="detail-row">
                    <div class="label">Rep Name</div>
                    <div class="value"><?php echo htmlspecialchars($rep_name); ?></div>
                </div>
                <div class="detail-row">
                    <div class="label">Class</div>
                    <div class="value"><?php echo htmlspecialchars($rep_class !== '' ? $rep_class : '—'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="label">Trial Expired On</div>
                    <div class="value"><?php echo htmlspecialchars($trial_expiry_label !== '' ? $trial_expiry_label : '—'); ?></div>
                </div>
            </div>

            <div class="actions">
                <a href="rep_signup.php" class="btn btn-primary">View Onboarding Page</a>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="logout" value="1" class="btn btn-muted">Log Out</button>
                </form>
            </div>
            <div class="note">After payment, the super admin can activate your subscription from the rep management page.</div>
        </div>

        <div class="card">
            <h2>Subscription payment</h2>
            <div class="copy">Use these details when you are asked to renew or activate your rep subscription.</div>

            <div class="detail-list">
                <div class="detail-row">
                    <div class="label">Account Name</div>
                    <div class="value"><?php echo htmlspecialchars($pay_to_account_name); ?></div>
                </div>
                <div class="detail-row">
                    <div class="label">MoMo Number</div>
                    <div class="value"><?php echo htmlspecialchars($pay_to_momo_number); ?></div>
                </div>
                <div class="detail-row">
                    <div class="label">Reference</div>
                    <div class="value">Use your username</div>
                </div>
            </div>

            <div class="note">Once the subscription is confirmed and activated, sign in again and your access will continue normally.</div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
