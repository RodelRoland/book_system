<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$success_msg = '';
$error_msg = '';
$generated_code = null;
$generated_username = null;

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $class_name = substr(strval($req['class_name'] ?? ''), 0, 30);

                $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? LIMIT 1");
                $check->bind_param('s', $username);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error_msg = 'Username already exists in admins. Cannot approve.';
                } else {
                    $tmp_pass = bin2hex(random_bytes(16));
                    $tmp_hash = password_hash($tmp_pass, PASSWORD_DEFAULT);

                    $code = strval(random_int(1000, 9999));
                    $expires_at = date('Y-m-d H:i:s', time() + (24 * 3600));

                    $ins = $conn->prepare("INSERT INTO admins (username, password_hash, full_name, class_name, role, is_active, first_time_code, first_time_code_expires, requires_password_reset, approved_at) VALUES (?, ?, ?, ?, 'rep', 1, ?, ?, 1, NOW())");
                    $ins->bind_param('ssssss', $username, $tmp_hash, $full_name, $class_name, $code, $expires_at);

                    if ($ins->execute()) {
                        $new_admin_id = intval($conn->insert_id);

                        $upd = $conn->prepare("UPDATE rep_signup_requests SET status = 'approved', approved_at = NOW(), approved_by_admin_id = ?, created_admin_id = ? WHERE signup_id = ?");
                        $upd->bind_param('iii', $current_admin_id, $new_admin_id, $signup_id);
                        $upd->execute();

                        $generated_code = $code;
                        $generated_username = $username;
                        $success_msg = "Payment confirmed. Rep account created. Share the 4-digit first-time code with the rep.";
                    } else {
                        $error_msg = 'Failed to create rep account.';
                    }
                }
            } elseif ($action === 'reject') {
                $upd = $conn->prepare("UPDATE rep_signup_requests SET status = 'rejected', approved_at = NOW(), approved_by_admin_id = ? WHERE signup_id = ?");
                $upd->bind_param('ii', $current_admin_id, $signup_id);
                if ($upd->execute()) {
                    $success_msg = 'Request rejected.';
                } else {
                    $error_msg = 'Failed to reject request.';
                }
            }
        }
    }
}

$pending = $conn->query("SELECT * FROM rep_signup_requests WHERE status = 'pending' ORDER BY created_at DESC");
$recent = $conn->query("SELECT * FROM rep_signup_requests WHERE status <> 'pending' ORDER BY approved_at DESC, created_at DESC LIMIT 20");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rep Signup Requests</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        .header {
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
        .header h1 { font-size: 22px; font-weight: 800; }
        .header .subtitle { opacity: 0.9; margin-top: 5px; font-size: 13px; }
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
        .export-btn {
            background: rgba(34,197,94,0.20);
            color: white;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 900;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .export-btn:hover { background: rgba(34,197,94,0.30); }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
            border-left: 4px solid;
        }
        .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left-color: #f44336; }
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 18px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
        th { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .actions { display: flex; gap: 8px; }
        .btn { border: none; border-radius: 8px; padding: 9px 12px; font-weight: 800; cursor: pointer; font-size: 13px; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .code-box {
            margin-top: 10px;
            background: #f8f9fa;
            border: 1px solid #eee;
            border-radius: 12px;
            padding: 14px;
            font-size: 14px;
        }
        .code-box strong { font-size: 18px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Rep Signup Requests</h1>
            <div class="subtitle">Confirm payment then approve to generate 4-digit first-time code</div>
        </div>
        <div style="display:flex; gap: 10px; align-items:center;">
            <a href="rep_onboarding_export.php" class="export-btn">⬇ Download Onboarding Excel</a>
            <a href="admin.php" class="back-btn">← Back</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if ($generated_code && $generated_username): ?>
        <div class="card">
            <h3 style="margin-bottom: 10px;">First-Time Code Generated</h3>
            <div class="code-box">
                <div><strong>Username:</strong> <?php echo htmlspecialchars($generated_username); ?></div>
                <div><strong>Code:</strong> <strong><?php echo htmlspecialchars($generated_code); ?></strong></div>
                <div style="margin-top: 8px; color:#666;">Rep should visit: <strong>First-Time Code Reset</strong> (login page link) and set password.</div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3 style="margin-bottom: 12px;">Pending Requests</h3>
        <div style="overflow:auto;">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
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
                            <td><?php echo htmlspecialchars($r['class_name'] ?: '—'); ?></td>
                            <td><span class="badge badge-pending">pending</span></td>
                            <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                            <td>
                                <div class="actions">
                                    <form method="post" onsubmit="return confirm('Approve this request and generate first-time code?');">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="signup_id" value="<?php echo intval($r['signup_id']); ?>">
                                        <button type="submit" class="btn btn-approve">Approve</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Reject this request?');">
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
                        <td colspan="6" style="color:#777;">No pending requests.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-bottom: 12px;">Recent Decisions</h3>
        <div style="overflow:auto;">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
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
                            <td><?php echo htmlspecialchars($r['class_name'] ?: '—'); ?></td>
                            <td>
                                <?php if ($r['status'] === 'approved'): ?>
                                    <span class="badge badge-approved">approved</span>
                                <?php else: ?>
                                    <span class="badge badge-rejected">rejected</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($r['approved_at'] ?: '—'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="color:#777;">No decisions yet.</td>
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
