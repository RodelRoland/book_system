<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'signup_password_hash', 'VARCHAR(255) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'recovery_email', 'VARCHAR(120) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'department_id', 'INT NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_ensure_column($conn, 'admins', 'recovery_email', 'VARCHAR(120) NULL AFTER account_number');
        book_system_setup_ensure_column($conn, 'admins', 'department_id', 'INT NULL AFTER program_name');
    }
}

book_system_require_admin_feature($conn, 'manage_rep_signups');

$success_msg = '';
$error_msg = '';
$access_mode_config = function_exists('book_system_get_access_mode_config')
    ? book_system_get_access_mode_config($conn)
    : ['effective_mode' => 'premium_active'];
$is_premium_signup_mode = strval($access_mode_config['effective_mode'] ?? 'premium_active') === 'premium_active';

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);

$csrf_token = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $action = $_POST['action'] ?? '';
    $signup_id = intval($_POST['signup_id'] ?? 0);

    if ($signup_id <= 0) {
        $error_msg = 'Invalid request.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM rep_signup_requests WHERE signup_id = ? LIMIT 1");
        $stmt->bind_param('i', $signup_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $req = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;

        if (!$req) {
            $error_msg = 'Signup request not found.';
        } elseif ($req['status'] !== 'pending') {
            $error_msg = 'This request is not pending.';
        } else {
            if ($action === 'approve') {
                $username = substr(strval($req['username'] ?? ''), 0, 50);
                $full_name = substr(strval($req['full_name'] ?? ''), 0, 30);
                $public_display_name = substr(strval($req['public_display_name'] ?? ''), 0, 50);
                $profile_photo_path = trim(strval($req['profile_photo_path'] ?? ''));
                $class_name = substr(strval($req['class_name'] ?? ''), 0, 30);
                $recovery_email = function_exists('book_system_normalize_recovery_email')
                    ? book_system_normalize_recovery_email(strval($req['recovery_email'] ?? ''))
                    : trim(strval($req['recovery_email'] ?? ''));
                $department_id = intval($req['department_id'] ?? 0);
                $department_name = '';
                if ($department_id > 0) {
                    $department_stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ? LIMIT 1");
                    if ($department_stmt) {
                        $department_stmt->bind_param('i', $department_id);
                        $department_stmt->execute();
                        $department_row = $department_stmt->get_result()->fetch_assoc();
                        $department_name = trim(strval($department_row['department_name'] ?? ''));
                        $department_stmt->close();
                    }
                }

                $request_password_hash = trim(strval($req['signup_password_hash'] ?? ''));

                $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? LIMIT 1");
                $check->bind_param('s', $username);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error_msg = 'Username already exists in admins. Cannot approve.';
                } elseif ($request_password_hash === '') {
                    $error_msg = 'This signup request was created under the old onboarding flow. Ask the rep to sign up again using the current signup page.';
                } elseif ($recovery_email === '') {
                    $error_msg = 'This signup request does not have a valid recovery email. Ask the rep to sign up again.';
                } else {
                    $trial_started_at = $is_premium_signup_mode ? date('Y-m-d H:i:s') : null;
                    $trial_expires_at = $is_premium_signup_mode ? book_system_trial_expiry_from_start($trial_started_at) : null;
                    $ins = $conn->prepare("INSERT INTO admins (username, password_hash, full_name, public_display_name, profile_photo_path, class_name, recovery_email, department_id, program_name, role, is_active, approved_at, trial_started_at, trial_expires_at, subscription_active) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, 'rep', 1, NOW(), ?, ?, 0)");
                    $ins->bind_param('sssssssisss', $username, $request_password_hash, $full_name, $public_display_name, $profile_photo_path, $class_name, $recovery_email, $department_id, $department_name, $trial_started_at, $trial_expires_at);

                    if ($ins->execute()) {
                        $new_admin_id = intval($conn->insert_id);

                        $upd = $conn->prepare("UPDATE rep_signup_requests SET status = 'approved', approved_at = NOW(), approved_by_admin_id = ?, created_admin_id = ? WHERE signup_id = ?");
                        $upd->bind_param('iii', $current_admin_id, $new_admin_id, $signup_id);
                        $upd->execute();

                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'approve_rep_signup', 'rep_signup', $signup_id, [
                                'username' => $username,
                                'full_name' => $full_name,
                                'public_display_name' => $public_display_name,
                                'profile_photo_path' => $profile_photo_path,
                                'class_name' => $class_name,
                                'recovery_email' => $recovery_email,
                                'department_id' => $department_id,
                                'department_name' => $department_name,
                                'created_admin_id' => $new_admin_id,
                                'activation_mode' => 'direct_password_activation',
                            ], $new_admin_id);
                        }

                        $success_msg = $is_premium_signup_mode
                            ? "Payment verified. Rep account activated. The rep can now sign in with the password chosen during signup."
                            : "Rep account approved. The rep can now sign in with the password chosen during signup.";
                    } else {
                        $error_msg = 'Failed to create rep account.';
                    }
                }
            } elseif ($action === 'reject') {
                $upd = $conn->prepare("UPDATE rep_signup_requests SET status = 'rejected', approved_at = NOW(), approved_by_admin_id = ? WHERE signup_id = ?");
                $upd->bind_param('ii', $current_admin_id, $signup_id);
                if ($upd->execute()) {
                    if (function_exists('book_system_audit_log')) {
                        book_system_audit_log($conn, 'reject_rep_signup', 'rep_signup', $signup_id, [
                            'username' => strval($req['username'] ?? ''),
                            'full_name' => strval($req['full_name'] ?? ''),
                            'public_display_name' => strval($req['public_display_name'] ?? ''),
                            'profile_photo_path' => strval($req['profile_photo_path'] ?? ''),
                            'class_name' => strval($req['class_name'] ?? ''),
                            'recovery_email' => strval($req['recovery_email'] ?? ''),
                            'department_id' => intval($req['department_id'] ?? 0),
                        ]);
                    }
                    $success_msg = 'Request rejected.';
                } else {
                    $error_msg = 'Failed to reject request.';
                }
            }
        }
    }
    }
}

$pending = $conn->query("SELECT rsr.*, d.department_name
    FROM rep_signup_requests rsr
    LEFT JOIN departments d ON d.department_id = rsr.department_id
    WHERE rsr.status = 'pending'
    ORDER BY rsr.created_at DESC");
$recent = $conn->query("SELECT rsr.*, d.department_name
    FROM rep_signup_requests rsr
    LEFT JOIN departments d ON d.department_id = rsr.department_id
    WHERE rsr.status <> 'pending'
    ORDER BY rsr.approved_at DESC, rsr.created_at DESC
    LIMIT 20");
$pending_count = $pending ? intval($pending->num_rows) : 0;
$recent_count = $recent ? intval($recent->num_rows) : 0;
$decision_stats = ['approved' => 0, 'rejected' => 0];
$decision_stats_result = $conn->query("SELECT status, COUNT(*) AS total FROM rep_signup_requests WHERE status IN ('approved', 'rejected') GROUP BY status");
if ($decision_stats_result) {
    while ($decision_row = $decision_stats_result->fetch_assoc()) {
        $status_key = strval($decision_row['status'] ?? '');
        if (isset($decision_stats[$status_key])) {
            $decision_stats[$status_key] = intval($decision_row['total'] ?? 0);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rep Signup Requests</title>
    <style>
        :root {
            --bg: #f3f6fb;
            --surface: rgba(255,255,255,0.95);
            --surface-strong: #ffffff;
            --border: rgba(148, 163, 184, 0.18);
            --border-soft: rgba(226, 232, 240, 0.9);
            --text: #0f172a;
            --muted: #64748b;
            --primary-a: #5b6ee1;
            --primary-b: #7c4dbe;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
            --shadow-lg: 0 24px 50px rgba(15, 23, 42, 0.08);
            --shadow-md: 0 14px 30px rgba(15, 23, 42, 0.06);
            --radius-xl: 26px;
            --radius-lg: 20px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(900px 560px at 10% 0%, rgba(91,110,225,0.12), transparent 52%),
                radial-gradient(760px 500px at 100% 10%, rgba(124,77,190,0.10), transparent 48%),
                linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
            min-height: 100vh;
            padding: 28px 18px 36px;
        }
        .container { max-width: 1180px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            color: white;
            padding: 26px 28px;
            border-radius: var(--radius-xl);
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 22px 50px rgba(91, 110, 225, 0.24);
            gap: 16px;
            flex-wrap: wrap;
            position: relative;
            overflow: hidden;
        }
        .header::before {
            content: '';
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255,255,255,0.10);
            top: -160px;
            right: -90px;
        }
        .header::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 32px;
            background: rgba(255,255,255,0.08);
            bottom: -150px;
            left: -70px;
            transform: rotate(24deg);
        }
        .header-copy,
        .header-actions { position: relative; z-index: 1; }
        .header-copy {
            max-width: 720px;
        }
        .header h1 {
            font-size: 31px;
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        .header .subtitle {
            opacity: 0.95;
            margin-top: 10px;
            font-size: 14px;
            line-height: 1.7;
            max-width: 560px;
        }
        .header-actions { display:flex; gap: 10px; align-items:center; flex-wrap: wrap; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 11px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3);
            white-space: nowrap;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        .alert {
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-size: 14px;
            border-left: 4px solid;
        }
        .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left-color: #f44336; }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            padding: 20px 20px 18px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        .summary-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-a) 0%, var(--primary-b) 100%);
        }
        .summary-card:nth-child(2)::before {
            background: linear-gradient(90deg, #0891b2 0%, #2563eb 100%);
        }
        .summary-card:nth-child(3)::before {
            background: linear-gradient(90deg, #16a34a 0%, #0f9d76 100%);
        }
        .summary-card:nth-child(4)::before {
            background: linear-gradient(90deg, #f97316 0%, #dc2626 100%);
        }
        .summary-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 10px;
        }
        .summary-value {
            color: var(--text);
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 8px;
            letter-spacing: -0.03em;
        }
        .summary-note {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .card {
            background: var(--surface);
            border-radius: var(--radius-xl);
            padding: 24px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 18px;
            border: 1px solid var(--border);
        }
        .table-shell {
            overflow: auto;
            border: 1px solid var(--border-soft);
            border-radius: 18px;
            background: var(--surface-strong);
        }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th, td {
            padding: 14px 14px;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            font-size: 14px;
        }
        th {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 800;
            background: #f8fafc;
        }
        tbody tr:hover { background: #fbfdff; }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn {
            border: none;
            border-radius: 12px;
            padding: 10px 13px;
            font-weight: 800;
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
        }
        .btn-approve { background: linear-gradient(135deg, #16a34a 0%, #0f9d76 100%); color: white; }
        .btn-reject { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
        .section-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e5e7eb;
            letter-spacing: -0.02em;
        }
        .empty-cell {
            color: var(--muted);
            padding: 18px 14px;
        }
        .mono {
            font-variant-numeric: tabular-nums;
        }
        @media (max-width: 900px) {
            .summary-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .summary-grid { grid-template-columns: 1fr; }
            body { padding: 18px 12px 24px; }
            .header {
                padding: 22px 20px;
            }
            .header h1 { font-size: 26px; }
            .card { padding: 18px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-copy">
            <h1>Rep Signup Requests</h1>
            <div class="subtitle">Review onboarding requests, verify payment when required, and approve qualified reps so they can sign in directly with the password chosen during signup.</div>
        </div>
        <div class="header-actions">
            <a href="admin.php" class="back-btn">&larr; Back</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Pending Requests</div>
            <div class="summary-value"><?php echo $pending_count; ?></div>
            <div class="summary-note">Requests waiting for payment confirmation and approval.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Recent Decisions</div>
            <div class="summary-value"><?php echo $recent_count; ?></div>
            <div class="summary-note">Latest approved or rejected signup actions.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Approved</div>
            <div class="summary-value"><?php echo intval($decision_stats['approved']); ?></div>
            <div class="summary-note">Signup requests that successfully became rep accounts.</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Rejected</div>
            <div class="summary-value"><?php echo intval($decision_stats['rejected']); ?></div>
            <div class="summary-note">Requests that were reviewed but not accepted.</div>
        </div>
    </div>

    <div class="card">
        <h3 class="section-title">Pending Requests</h3>
        <div class="table-shell">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Public Name</th>
                    <th>Recovery Email</th>
                    <th>Department</th>
                    <th>Class</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pending && $pending->num_rows > 0): ?>
                    <?php while ($r = $pending->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['full_name']); ?></td>
                            <td><?php echo htmlspecialchars(strval($r['public_display_name'] ?? '') !== '' ? strval($r['public_display_name']) : '—'); ?></td>
                            <td><?php echo htmlspecialchars(strval($r['recovery_email'] ?? '') !== '' ? strval($r['recovery_email']) : '—'); ?></td>
                            <td><?php echo htmlspecialchars(strval($r['department_name'] ?? '') !== '' ? strval($r['department_name']) : '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['class_name'] ?: '—'); ?></td>
                            <td><span class="badge badge-pending">pending</span></td>
                            <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                            <td>
                                <div class="actions">
                                    <form method="post" onsubmit="return confirm('Approve this request and activate the rep account?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="signup_id" value="<?php echo intval($r['signup_id']); ?>">
                                        <button type="submit" class="btn btn-approve">Approve</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Reject this request?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="signup_id" value="<?php echo intval($r['signup_id']); ?>">
                                        <button type="submit" class="btn btn-reject">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty-cell">No pending requests.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h3 class="section-title">Recent Decisions</h3>
        <div class="table-shell">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Public Name</th>
                    <th>Recovery Email</th>
                    <th>Department</th>
                    <th>Class</th>
                    <th>Status</th>
                    <th>Approved/Rejected At</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recent && $recent->num_rows > 0): ?>
                    <?php while ($r = $recent->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['full_name']); ?></td>
                            <td><?php echo htmlspecialchars(strval($r['public_display_name'] ?? '') !== '' ? strval($r['public_display_name']) : '—'); ?></td>
                            <td><?php echo htmlspecialchars(strval($r['recovery_email'] ?? '') !== '' ? strval($r['recovery_email']) : '—'); ?></td>
                            <td><?php echo htmlspecialchars(strval($r['department_name'] ?? '') !== '' ? strval($r['department_name']) : '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['class_name'] ?: '—'); ?></td>
                            <td>
                                <?php if ($r['status'] === 'approved'): ?>
                                    <span class="badge badge-approved">approved</span>
                                <?php else: ?>
                                    <span class="badge badge-rejected">rejected</span>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?php echo htmlspecialchars($r['approved_at'] ?: '—'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty-cell">No decisions yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>

