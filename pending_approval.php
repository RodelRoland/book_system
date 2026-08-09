<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'rep') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strval($_POST['action'] ?? '') === 'logout') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $logout_error = 'Your session could not be verified. Please try again.';
    } else {
        if (function_exists('book_system_clear_pending_rep_session')) {
            book_system_clear_pending_rep_session();
        }
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

$status = function_exists('book_system_get_pending_rep_gate_status')
    ? book_system_get_pending_rep_gate_status($conn)
    : null;

if (!is_array($status) || strval($status['state'] ?? '') === 'approved') {
    $adminId = intval($_SESSION['admin_id'] ?? 0);
    if ($adminId > 0 && function_exists('book_system_rep_has_books') && !book_system_rep_has_books($conn, $adminId)) {
        header('Location: manage_books.php?msg=book_setup_required');
    } else {
        header('Location: rep_dashboard.php');
    }
    exit;
}

$state = strval($status['state'] ?? 'pending');
$fullName = trim(strval($status['full_name'] ?? $_SESSION['admin_full_name'] ?? ''));
$className = trim(strval($status['class_name'] ?? $_SESSION['admin_class_name'] ?? ''));
$csrf_token = csrf_get_token();

$stateMeta = [
    'pending' => [
        'title' => 'Awaiting Approval',
        'eyebrow' => 'ACCOUNT STATUS',
        'accent' => '#5b6cf0',
        'accent_soft' => 'rgba(91, 108, 240, 0.12)',
    ],
    'rejected' => [
        'title' => 'Request Not Approved',
        'eyebrow' => 'ACCOUNT STATUS',
        'accent' => '#d04c63',
        'accent_soft' => 'rgba(208, 76, 99, 0.12)',
    ],
    'inactive' => [
        'title' => 'Account Temporarily Unavailable',
        'eyebrow' => 'ACCOUNT STATUS',
        'accent' => '#cf8a00',
        'accent_soft' => 'rgba(207, 138, 0, 0.12)',
    ],
];
$meta = $stateMeta[$state] ?? $stateMeta['pending'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($meta['title']); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #edf3ff 0%, #f7f2ff 100%);
            color: #1e2432;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .shell {
            width: 100%;
            max-width: 720px;
        }
        .card {
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 24px 60px rgba(31, 41, 55, 0.14);
            overflow: hidden;
        }
        .hero {
            padding: 34px 34px 28px;
            background:
                radial-gradient(circle at top right, rgba(255,255,255,0.24) 0, rgba(255,255,255,0.24) 20%, transparent 21%) top right / 180px 180px no-repeat,
                linear-gradient(135deg, <?php echo htmlspecialchars($meta['accent']); ?> 0%, #7b57b6 100%);
            color: #fff;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
        }
        .hero h1 {
            margin-top: 18px;
            font-size: 32px;
            line-height: 1.15;
        }
        .hero p {
            margin-top: 14px;
            max-width: 560px;
            font-size: 16px;
            line-height: 1.7;
            color: rgba(255,255,255,0.92);
        }
        .content {
            padding: 28px 34px 34px;
        }
        .identity {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .meta-card {
            border-radius: 18px;
            background: #f8faff;
            border: 1px solid #e4ebfb;
            padding: 18px 20px;
        }
        .meta-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6d7994;
            margin-bottom: 10px;
        }
        .meta-value {
            font-size: 19px;
            font-weight: 700;
            color: #20283b;
        }
        .notice {
            background: <?php echo htmlspecialchars($meta['accent_soft']); ?>;
            border: 1px solid rgba(32, 40, 59, 0.06);
            border-left: 5px solid <?php echo htmlspecialchars($meta['accent']); ?>;
            border-radius: 20px;
            padding: 22px 22px 20px;
            line-height: 1.75;
            color: #33415c;
            margin-bottom: 24px;
        }
        .logout-error {
            margin-bottom: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #fff3f5;
            border: 1px solid #f7c6cf;
            color: #9b3348;
            font-size: 14px;
        }
        .actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }
        .button {
            appearance: none;
            border: none;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 172px;
            border-radius: 16px;
            padding: 15px 20px;
            font-size: 15px;
            font-weight: 700;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .button:hover {
            transform: translateY(-1px);
        }
        .button-primary {
            background: linear-gradient(135deg, <?php echo htmlspecialchars($meta['accent']); ?> 0%, #6a57d6 100%);
            color: #fff;
            box-shadow: 0 16px 30px rgba(91, 108, 240, 0.22);
        }
        .button-secondary {
            background: #eef2fa;
            color: #24304c;
            border: 1px solid #dbe4f3;
        }
        .support {
            margin-top: 18px;
            font-size: 14px;
            color: #68748e;
        }
        @media (max-width: 640px) {
            body { padding: 16px; }
            .hero, .content { padding-left: 22px; padding-right: 22px; }
            .hero h1 { font-size: 26px; }
            .hero p { font-size: 15px; }
            .identity { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
            .button { width: 100%; min-width: 0; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="card">
            <div class="hero">
                <div class="eyebrow"><?php echo htmlspecialchars($meta['eyebrow']); ?></div>
                <h1><?php echo htmlspecialchars($meta['title']); ?></h1>
                <p><?php echo htmlspecialchars(strval($status['message'] ?? '')); ?></p>
            </div>
            <div class="content">
                <?php if (!empty($logout_error ?? '')): ?>
                    <div class="logout-error"><?php echo htmlspecialchars($logout_error); ?></div>
                <?php endif; ?>

                <div class="identity">
                    <div class="meta-card">
                        <div class="meta-label">Rep Name</div>
                        <div class="meta-value"><?php echo htmlspecialchars($fullName !== '' ? $fullName : strval($_SESSION['admin_username'] ?? 'Rep Account')); ?></div>
                    </div>
                    <div class="meta-card">
                        <div class="meta-label">Class</div>
                        <div class="meta-value"><?php echo htmlspecialchars($className !== '' ? $className : 'Class not yet available'); ?></div>
                    </div>
                </div>

                <div class="notice">
                    We’ve saved your account and kept it safe. As soon as the super admin completes the review for your request, this page will update and you’ll be allowed into your rep workspace automatically.
                </div>

                <div class="actions">
                    <a class="button button-primary" href="pending_approval.php">Refresh Status</a>
                    <form method="post" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="button button-secondary">Logout</button>
                    </form>
                </div>

                <div class="support">
                    If approval is taking longer than expected, please contact the super admin for help.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
