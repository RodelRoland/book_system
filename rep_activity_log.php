<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? '');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}

$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_name = strval($access_context['effective_full_name'] ?? 'Rep');
$rep_bottom_nav_active = 'reports';
$rep_bottom_nav_reports_href = 'rep_activity_log.php';
$rep_bottom_nav_profile_href = (!empty($access_context['is_assistant_mode']) ? 'assistant_profile.php' : 'my_profile.php');
$actor_filter = trim(strval($_GET['actor'] ?? 'all'));
$limit = 50;
$logs = [];
$audit_logs_available = false;

$check = $conn->query("SHOW TABLES LIKE 'audit_logs'");
$audit_logs_available = ($check && $check->num_rows === 1);

if ($audit_logs_available) {
    $sql = "SELECT
                al.log_id,
                al.actor_admin_id,
                al.actor_role,
                al.actor_username,
                al.action_type,
                al.entity_type,
                al.entity_id,
                al.details_json,
                al.created_at,
                a.full_name AS actor_full_name,
                a.delegated_by_admin_id
            FROM audit_logs al
            LEFT JOIN admins a ON a.admin_id = al.actor_admin_id
            WHERE (
                al.actor_admin_id = ?
                OR (
                    COALESCE(a.role, '') = 'temporary_admin'
                    AND COALESCE(a.delegated_by_admin_id, 0) = ?
                )
            )";

    if ($actor_filter === 'rep') {
        $sql .= " AND al.actor_admin_id = ?";
    } elseif ($actor_filter === 'assistant') {
        $sql .= " AND COALESCE(a.role, '') = 'temporary_admin' AND COALESCE(a.delegated_by_admin_id, 0) = ?";
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT " . intval($limit);

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($actor_filter === 'rep' || $actor_filter === 'assistant') {
            $stmt->bind_param('iii', $current_admin_id, $current_admin_id, $current_admin_id);
        } else {
            $stmt->bind_param('ii', $current_admin_id, $current_admin_id);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $logs[] = $row;
        }
        $stmt->close();
    }
}

$action_labels = [
    'record_payment' => 'Recorded payment',
    'toggle_payment' => 'Changed payment status',
    'toggle_collection' => 'Changed book collection status',
    'return_balance' => 'Returned balance',
    'delete_request' => 'Deleted request',
    'record_books_received' => 'Recorded books received',
    'update_books_received' => 'Updated books received record',
    'delete_books_received' => 'Deleted books received record',
    'record_lecturer_payment' => 'Recorded lecturer payment',
    'update_lecturer_payment' => 'Updated lecturer payment',
    'delete_lecturer_payment' => 'Deleted lecturer payment',
    'add_book' => 'Added book',
    'update_book' => 'Updated book',
    'create_assistant_account' => 'Created assistant account',
    'update_assistant_account' => 'Updated assistant account',
    'toggle_assistant_status' => 'Changed assistant access status',
    'reset_assistant_password' => 'Reset assistant password',
    'update_assistant_profile' => 'Updated assistant profile',
    'add_class_student' => 'Added class student',
    'export_received_students' => 'Exported received students list',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Activity</title>
    <style>
        :root {
            --bg: #f6f8fc;
            --surface: #ffffff;
            --line: #e5edf8;
            --text: #172033;
            --muted: #667085;
            --primary: #2563eb;
            --shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            --radius-lg: 22px;
            --radius-md: 16px;
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
        .hero, .card, .log-item { background: var(--surface); border: 1px solid rgba(255,255,255,0.92); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
        .hero { padding: 18px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--primary); margin-bottom: 12px; }
        .hero h1 { margin: 0 0 6px; font-size: 22px; line-height: 1.2; }
        .hero p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
        .hero-chip { display: inline-flex; align-items: center; gap: 8px; margin-top: 12px; padding: 8px 12px; border-radius: 999px; background: #eff6ff; color: var(--primary); font-size: 12px; font-weight: 800; }
        .card { padding: 16px; }
        .filter-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-chip { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 999px; border: 1px solid var(--line); background: #fff; color: #334155; font-size: 12px; font-weight: 800; }
        .filter-chip.active { background: #eff6ff; border-color: #bfdbfe; color: var(--primary); }
        .log-list { display: grid; gap: 12px; }
        .log-item { padding: 14px; }
        .log-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
        .log-title { font-size: 15px; font-weight: 800; line-height: 1.35; }
        .log-time { color: var(--muted); font-size: 12px; white-space: nowrap; }
        .log-meta { display: grid; gap: 6px; color: #344054; font-size: 12px; }
        .actor-pill { display: inline-flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 999px; background: #f8fafc; color: #334155; font-size: 12px; font-weight: 700; }
        .details-box { margin-top: 12px; padding: 12px; border-radius: 14px; background: #f8fafc; border: 1px solid var(--line); color: #334155; font-size: 12px; line-height: 1.6; white-space: pre-wrap; word-break: break-word; }
        .empty { padding: 16px; color: var(--muted); font-size: 13px; line-height: 1.7; text-align: center; }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <a href="rep_dashboard.php" class="back-link">&larr; Back to Dashboard</a>
        <h1>Team Activity</h1>
        <p>See what has been done inside your class workspace by you or any assistant working under your account.</p>
        <div class="hero-chip"><?php echo htmlspecialchars($current_admin_name); ?></div>
    </section>

    <section class="card">
        <div class="filter-row">
            <a href="rep_activity_log.php" class="filter-chip <?php echo $actor_filter === 'all' ? 'active' : ''; ?>">All Activity</a>
            <a href="rep_activity_log.php?actor=rep" class="filter-chip <?php echo $actor_filter === 'rep' ? 'active' : ''; ?>">Main Rep</a>
            <a href="rep_activity_log.php?actor=assistant" class="filter-chip <?php echo $actor_filter === 'assistant' ? 'active' : ''; ?>">Assistants</a>
        </div>
    </section>

    <section class="log-list">
        <?php if (!$audit_logs_available): ?>
            <div class="log-item empty">Activity logging is not available in this deployment yet.</div>
        <?php elseif (empty($logs)): ?>
            <div class="log-item empty">No activity has been recorded for this workspace yet.</div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <?php
                $action_type = strval($log['action_type'] ?? '');
                $log_title = $action_labels[$action_type] ?? ucwords(str_replace('_', ' ', $action_type));
                $actor_role = strval($log['actor_role'] ?? '');
                $actor_name = trim(strval($log['actor_full_name'] ?? ''));
                if ($actor_name === '') {
                    $actor_name = trim(strval($log['actor_username'] ?? 'Unknown'));
                }
                $role_label = $actor_role === 'temporary_admin' ? 'Assistant Rep' : ($actor_role === 'rep' ? 'Main Rep' : ucfirst($actor_role));
                $details = trim(strval($log['details_json'] ?? ''));
                ?>
                <article class="log-item">
                    <div class="log-top">
                        <div class="log-title"><?php echo htmlspecialchars($log_title); ?></div>
                        <div class="log-time"><?php echo htmlspecialchars(date('M d, g:i A', strtotime(strval($log['created_at'] ?? 'now')))); ?></div>
                    </div>
                    <div class="log-meta">
                        <span class="actor-pill"><?php echo htmlspecialchars($actor_name); ?> • <?php echo htmlspecialchars($role_label); ?></span>
                        <span><strong>Entity:</strong> <?php echo htmlspecialchars(strval($log['entity_type'] ?? 'record')); ?> #<?php echo intval($log['entity_id'] ?? 0); ?></span>
                    </div>
                    <?php if ($details !== ''): ?>
                        <div class="details-box"><?php echo htmlspecialchars($details); ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/rep_bottom_nav.php'; ?>
</body>
</html>
