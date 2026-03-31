<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');
$role_filter = trim(strval($_GET['role'] ?? ''));
$role_filter = in_array($role_filter, ['super_admin', 'rep'], true) ? $role_filter : '';
$back_link = $is_super_admin ? 'admin.php' : 'rep_dashboard.php';

$activity_rows = function_exists('book_system_fetch_recent_activity')
    ? book_system_fetch_recent_activity($conn, 100, $current_admin_id, $is_super_admin, $role_filter !== '' ? $role_filter : null)
    : [];

$summary = [
    'all' => count($activity_rows),
    'requests' => 0,
    'payments' => 0,
    'approvals' => 0,
];

foreach ($activity_rows as $activity) {
    $action = strval($activity['action_type'] ?? '');
    if (strpos($action, 'request') !== false || strpos($action, 'collection') !== false || strpos($action, 'payment') !== false) {
        $summary['requests']++;
    }
    if (strpos($action, 'lecturer_payment') !== false || strpos($action, 'books_received') !== false || strpos($action, 'payment') !== false) {
        $summary['payments']++;
    }
    if (strpos($action, 'approve') !== false || strpos($action, 'reject') !== false || strpos($action, 'semester') !== false) {
        $summary['approvals']++;
    }
}

function book_system_activity_label(array $activity): string {
    $action = strval($activity['action_type'] ?? '');
    $map = [
        'set_active_semester' => 'Changed the active semester',
        'create_semester' => 'Created a semester',
        'toggle_payment' => 'Toggled a request payment status',
        'toggle_collection' => 'Updated a book collection status',
        'mark_paid' => 'Marked a request as paid',
        'delete_request' => 'Deleted a request',
        'return_balance' => 'Marked a student balance as returned',
        'approve_rep_signup' => 'Approved a rep signup',
        'reject_rep_signup' => 'Rejected a rep signup',
        'add_book' => 'Added a book',
        'update_book' => 'Updated a book',
        'record_books_received' => 'Recorded books received',
        'update_books_received' => 'Updated a books received entry',
        'delete_books_received' => 'Deleted a books received entry',
        'record_lecturer_payment' => 'Recorded a lecturer payment',
        'update_lecturer_payment' => 'Updated a lecturer payment',
        'delete_lecturer_payment' => 'Deleted a lecturer payment',
        'upload_class_csv' => 'Imported a class list',
        'add_class_student' => 'Added a student to the class list',
        'delete_class_student' => 'Removed a student from the class list',
        'clear_class_students' => 'Cleared the class list',
    ];

    return $map[$action] ?? str_replace('_', ' ', ucwords($action, '_'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log</title>
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
            padding: 26px 30px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .page-header h1 { font-size: 24px; font-weight: 700; }
        .page-header .subtitle { opacity: 0.92; margin-top: 6px; font-size: 14px; }
        .title-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .title-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.28);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18);
            flex-shrink: 0;
        }
        .back-btn {
            background: rgba(255,255,255,0.18);
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.28); }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        .stat-card, .filter-card, .log-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .stat-card {
            padding: 22px;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #667eea;
        }
        .stat-card .label {
            font-size: 12px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 700;
        }
        .stat-card .value {
            font-size: 26px;
            font-weight: 800;
            color: #1f2937;
        }
        .filter-card, .log-card { padding: 24px; }
        .filter-card { margin-bottom: 20px; }
        .section-title {
            font-size: 18px;
            color: #333;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        .filter-form {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #475569;
        }
        .filter-form select, .filter-form button {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
        }
        .filter-form select {
            min-width: 180px;
            border: 2px solid #e2e8f0;
        }
        .filter-form button {
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            cursor: pointer;
            font-weight: 700;
        }
        .activity-list {
            display: grid;
            gap: 14px;
        }
        .activity-item {
            padding: 18px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .activity-title {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px;
        }
        .activity-meta {
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }
        .activity-details {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            background: white;
            color: #475569;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .empty-note {
            padding: 16px;
            border-radius: 12px;
            background: #f8fafc;
            color: #64748b;
        }
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 560px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <div>
            <div class="title-row">
                <span class="title-icon">&#128221;</span>
                <div>
                    <h1>Activity Log</h1>
                    <div class="subtitle">Track request, payment, pricing, and approval changes across the system.</div>
                </div>
            </div>
        </div>
        <a href="<?php echo htmlspecialchars($back_link); ?>" class="back-btn">&larr; Back to Dashboard</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Visible Entries</div>
            <div class="value"><?php echo intval($summary['all']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Request Actions</div>
            <div class="value"><?php echo intval($summary['requests']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Payment Actions</div>
            <div class="value"><?php echo intval($summary['payments']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Approvals / Setup</div>
            <div class="value"><?php echo intval($summary['approvals']); ?></div>
        </div>
    </div>

    <?php if ($is_super_admin): ?>
        <div class="filter-card">
            <div class="section-title">Filter Activity</div>
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="">All roles</option>
                        <option value="super_admin" <?php echo $role_filter === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                        <option value="rep" <?php echo $role_filter === 'rep' ? 'selected' : ''; ?>>Rep</option>
                    </select>
                </div>
                <button type="submit">Apply Filter</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="log-card">
        <div class="section-title">Recent Activity</div>
        <?php if (!empty($activity_rows)): ?>
            <div class="activity-list">
                <?php foreach ($activity_rows as $activity): ?>
                    <?php
                        $actor_name = strval($activity['actor_full_name'] ?? '');
                        if ($actor_name === '') {
                            $actor_name = strval($activity['actor_username'] ?? 'System');
                        }
                        $details = json_decode(strval($activity['details_json'] ?? '{}'), true);
                    ?>
                    <div class="activity-item">
                        <div class="activity-title"><?php echo htmlspecialchars(book_system_activity_label($activity)); ?></div>
                        <div class="activity-meta">
                            <?php echo htmlspecialchars($actor_name); ?>
                            &bull;
                            <?php echo htmlspecialchars(strtoupper(strval($activity['actor_role'] ?? 'system'))); ?>
                            &bull;
                            <?php echo htmlspecialchars(strval($activity['entity_type'] ?? 'record')); ?>
                            #<?php echo intval($activity['entity_id'] ?? 0); ?>
                            &bull;
                            <?php echo htmlspecialchars(date('M d, Y H:i', strtotime(strval($activity['created_at'] ?? 'now')))); ?>
                        </div>
                        <?php if (!empty($details) && is_array($details)): ?>
                            <div class="activity-details"><?php echo htmlspecialchars(book_system_json_encode($details)); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-note">No activity has been logged yet for this view.</div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
